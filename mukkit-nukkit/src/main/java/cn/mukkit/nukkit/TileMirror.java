package cn.mukkit.nukkit;

import cn.nukkit.Server;
import cn.nukkit.blockentity.BlockEntity;
import cn.nukkit.blockentity.BlockEntityFurnace;
import cn.nukkit.blockentity.BlockEntityNameable;
import cn.nukkit.blockentity.BlockEntitySign;
import cn.nukkit.inventory.ContainerInventory;
import cn.nukkit.item.Item;
import cn.nukkit.level.Level;
import cn.nukkit.level.format.generic.BaseFullChunk;
import cn.nukkit.math.Vector3;
import cn.nukkit.nbt.tag.CompoundTag;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Queue;
import java.util.Set;
import java.util.function.BooleanSupplier;

/**
 * Keeps the host's block-entity view aligned with Nukkit's.
 *
 * <p>Polled like {@link InventoryMirror}, and for the same reason: a chest can change through a
 * player transaction, a hopper, a plugin calling {@code setItem()}, or a sign edit, and no single
 * event covers all of them. Comparing encoded payloads against a shadow catches every path.</p>
 *
 * <p>Only chunks the host already mirrors are walked. A tile outside that set is one the host
 * would drop on arrival, and walking whole levels every few ticks to find them would make the
 * mirror cost grow with the map instead of with the players.</p>
 *
 * <p>The first poll after a chunk was sent does not emit anything: the chunk frame already
 * carried its tiles, so emitting them again would be pure duplicate traffic. {@link ChunkStreamer}
 * hands out the chunks it sent since the last poll for exactly this.</p>
 */
final class TileMirror {

    /** Containers change on player actions, so this is the inventory interval, not the chunk one. */
    private static final int INTERVAL = Math.max(1, Integer.getInteger("mukkit.tileInterval", 4));

    private final Server server;
    private final Queue<Map<String, Object>> inbound;
    private final BooleanSupplier enabled;
    private final ChunkStreamer chunks;

    /** world -> chunk key -> packed position -> last encoded payload. */
    private final Map<String, Map<Long, Map<Long, String>>> shadow = new LinkedHashMap<>();

    private final Set<String> reported = new HashSet<>();

    private int tick;

    TileMirror(Server server, Queue<Map<String, Object>> inbound, BooleanSupplier enabled, ChunkStreamer chunks) {
        this.server = server;
        this.inbound = inbound;
        this.enabled = enabled;
        this.chunks = chunks;
    }

    /** The host restarted: everything it knew is gone with it. */
    void forgetAll() {
        shadow.clear();
        reported.clear();
    }

    /** Collects tiles Nukkit changed since the last poll. Runs on the tick thread. */
    void poll() {
        if (!enabled.getAsBoolean()) {
            return;
        }
        if (++tick % INTERVAL != 0) {
            return;
        }

        // Chunks sent since the last poll arrive with their tiles already attached. Record those
        // tiles in the shadow without emitting, so the first real diff is against the truth.
        for (Object[] fresh : chunks.drainNewlySent()) {
            seed((String) fresh[0], (Level) fresh[1], (Long) fresh[2]);
        }

        for (String world : chunks.mirroredWorlds()) {
            Level level = server.getLevelByName(world);
            if (level == null) {
                continue;
            }
            Map<Long, Map<Long, String>> worldShadow = shadow.computeIfAbsent(world, ignored -> new LinkedHashMap<>());
            Map<Long, Map<Long, String>> next = new LinkedHashMap<>();
            List<Map<String, Object>> changed = new ArrayList<>();
            List<List<Integer>> removed = new ArrayList<>();

            for (long chunkKey : chunks.chunksOf(world)) {
                BaseFullChunk chunk = level.getChunkIfLoaded(ChunkStreamer.xOf(chunkKey), ChunkStreamer.zOf(chunkKey));
                if (chunk == null) {
                    // Keep what we knew: the chunk is still subscribed, it is just not in memory
                    // right now, and inventing removals for it would wipe the host's tiles too.
                    Map<Long, String> previous = worldShadow.get(chunkKey);
                    if (previous != null) {
                        next.put(chunkKey, previous);
                    }
                    continue;
                }

                Map<Long, String> before = worldShadow.getOrDefault(chunkKey, Map.of());
                Map<Long, String> after = new LinkedHashMap<>();
                for (BlockEntity blockEntity : chunk.getBlockEntities().values()) {
                    Map<String, Object> payload = TileCodec.encode(blockEntity);
                    if (payload == null) {
                        continue;
                    }
                    long key = pack(blockEntity.getFloorX(), blockEntity.getFloorY(), blockEntity.getFloorZ());
                    String encoded = payload.toString();
                    after.put(key, encoded);
                    if (!encoded.equals(before.get(key))) {
                        changed.add(payload);
                    }
                }
                for (Long key : before.keySet()) {
                    if (!after.containsKey(key)) {
                        removed.add(unpack(key));
                    }
                }
                if (!after.isEmpty()) {
                    next.put(chunkKey, after);
                }
            }

            worldShadow.clear();
            worldShadow.putAll(next);
            if (changed.isEmpty() && removed.isEmpty()) {
                continue;
            }
            Map<String, Object> frame = new LinkedHashMap<>();
            frame.put("k", "tile");
            frame.put("world", world);
            frame.put("tiles", changed);
            frame.put("removed", removed);
            inbound.add(frame);
        }
    }

    /** Records a freshly sent chunk's tiles without treating them as changes. */
    private void seed(String world, Level level, long chunkKey) {
        if (level == null) {
            return;
        }
        BaseFullChunk chunk = level.getChunkIfLoaded(ChunkStreamer.xOf(chunkKey), ChunkStreamer.zOf(chunkKey));
        if (chunk == null) {
            return;
        }
        Map<Long, String> tiles = new LinkedHashMap<>();
        for (BlockEntity blockEntity : chunk.getBlockEntities().values()) {
            Map<String, Object> payload = TileCodec.encode(blockEntity);
            if (payload == null) {
                continue;
            }
            tiles.put(pack(blockEntity.getFloorX(), blockEntity.getFloorY(), blockEntity.getFloorZ()), payload.toString());
        }
        if (!tiles.isEmpty()) {
            shadow.computeIfAbsent(world, ignored -> new LinkedHashMap<>()).put(chunkKey, tiles);
        }
    }

    /**
     * Applies tile writes the host collected from its plugins.
     *
     * <p>A missing block entity is reported, not created. Nukkit only makes a chest tile when a
     * chest is placed through the placement path; a block the host wrote directly may not have
     * one, and inventing a tile would give the client a container the level does not know.</p>
     */
    void apply(Map<String, Object> op) {
        if (!enabled.getAsBoolean()) {
            return;
        }
        String world = String.valueOf(op.get("world"));
        Level level = server.getLevelByName(world);
        if (level == null) {
            return;
        }
        Object entries = op.get("tiles");
        if (!(entries instanceof List<?> list)) {
            return;
        }
        for (Object entry : list) {
            if (!(entry instanceof Map<?, ?> tile)) {
                continue;
            }
            int x = intOf(tile.get("x"), 0);
            int y = intOf(tile.get("y"), 0);
            int z = intOf(tile.get("z"), 0);
            String type = String.valueOf(tile.get("type"));
            BlockEntity blockEntity = level.getBlockEntityIfLoaded(new Vector3(x, y, z));
            if (blockEntity == null || !type.equals(blockEntity.getSaveId())) {
                report("tile " + type + " at " + x + "," + y + "," + z
                        + " has no matching block entity on this side");
                continue;
            }
            try {
                applyTo(blockEntity, tile);
            } catch (RuntimeException e) {
                report("tile " + type + " at " + x + "," + y + "," + z + " could not be applied: " + e.getMessage());
                continue;
            }
            remember(world, blockEntity);
        }
    }

    private static void applyTo(BlockEntity blockEntity, Map<?, ?> tile) {
        if (blockEntity instanceof BlockEntityNameable nameable) {
            // An absent name is a name the plugin removed; `setName("")` is how Nukkit clears
            // `CustomName`. Skipping it would leave the old name on the block forever.
            nameable.setName(tile.get("name") instanceof String name ? name : "");
        }

        ContainerInventory inventory = TileCodec.containerInventory(blockEntity);
        if (inventory != null) {
            Map<Integer, Item> contents = new HashMap<>();
            if (tile.get("items") instanceof List<?> items) {
                for (Object itemEntry : items) {
                    if (!(itemEntry instanceof List<?> tuple) || tuple.size() < 4) {
                        continue;
                    }
                    Item item = ContainerMirror.itemOf(tuple, 1);
                    if (item != null && !item.isNull()) {
                        contents.put(intOf(tuple.get(0), -1), item);
                    }
                }
            }
            contents.remove(-1);
            inventory.setContents(contents);
            if (blockEntity instanceof BlockEntityFurnace furnace) {
                furnace.setBurnTime(intOf(tile.get("burn"), 0));
                furnace.setCookTime(intOf(tile.get("cook"), 0));
                furnace.setMaxTime(intOf(tile.get("max"), 0));
            }
        } else if (blockEntity instanceof BlockEntitySign sign) {
            applySide(sign, BlockEntitySign.TAG_FRONT_TEXT, tile.get("front"),
                    tile.get("frontColor"), tile.get("frontGlow"));
            applySide(sign, BlockEntitySign.TAG_BACK_TEXT, tile.get("back"),
                    tile.get("backColor"), tile.get("backGlow"));
            sign.spawnToAll();
            sign.setDirty();
        }
    }

    private static void applySide(BlockEntitySign sign, String tagName, Object lines,
                                  Object color, Object glowing) {
        CompoundTag side = sign.namedTag.getCompound(tagName);
        if (lines instanceof List<?> list) {
            List<String> text = new ArrayList<>(4);
            for (int i = 0; i < 4 && i < list.size(); i++) {
                text.add(String.valueOf(list.get(i)));
            }
            side.putString(BlockEntitySign.TAG_TEXT_BLOB, String.join("\n", text));
        }
        if (color instanceof Number number) {
            side.putInt(BlockEntitySign.TAG_TEXT_COLOR, number.intValue());
        }
        if (glowing instanceof Boolean value) {
            side.putBoolean(BlockEntitySign.TAG_GLOWING_TEXT, value);
        }
        sign.namedTag.putCompound(tagName, side);
    }

    /** Advances the shadow after an applied write so the next poll does not echo it back. */
    private void remember(String world, BlockEntity blockEntity) {
        Map<String, Object> payload = TileCodec.encode(blockEntity);
        if (payload == null) {
            return;
        }
        long chunkKey = ChunkStreamer.chunkKey(blockEntity.getFloorX() >> 4, blockEntity.getFloorZ() >> 4);
        long key = pack(blockEntity.getFloorX(), blockEntity.getFloorY(), blockEntity.getFloorZ());
        shadow.computeIfAbsent(world, ignored -> new LinkedHashMap<>())
                .computeIfAbsent(chunkKey, ignored -> new LinkedHashMap<>())
                .put(key, payload.toString());
    }

    private void report(String message) {
        if (reported.add(message)) {
            System.out.println("[mukkit] tile: " + message + " (further occurrences suppressed)");
        }
    }

    /**
     * x, y and z in one long: 26 bits of x, 12 of y, 26 of z. The world's own limits are well
     * inside those, and the packing has no collisions.
     */
    static long pack(int x, int y, int z) {
        return ((long) (x & 0x3ffffff) << 38) | ((long) (y & 0xfff) << 26) | (z & 0x3ffffffL);
    }

    static List<Integer> unpack(long key) {
        int y = (int) ((key >> 26) & 0xfff);
        if (y >= 2048) {
            y -= 4096;
        }
        int z = (int) (key & 0x3ffffff);
        if (z >= 0x2000000) {
            z -= 0x4000000;
        }
        return List.of((int) (key >> 38), y, z);
    }

    private static int intOf(Object value, int fallback) {
        return value instanceof Number number ? number.intValue() : fallback;
    }
}
