package cn.mukkit.nukkit;

import cn.nukkit.Player;
import cn.nukkit.Server;
import cn.nukkit.entity.Entity;
import cn.nukkit.entity.EntityLiving;
import cn.nukkit.entity.item.EntityItem;
import cn.nukkit.item.Item;
import cn.nukkit.level.Level;
import cn.nukkit.level.Location;
import cn.nukkit.nbt.NBTIO;
import cn.nukkit.nbt.tag.CompoundTag;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Queue;
import java.util.Set;

/**
 * Tells the host which entities exist around the players, and applies the ones a plugin changed.
 *
 * <p>Entities are harder than blocks because a plugin does not receive a description, it receives
 * an object with a class: `instanceof Zombie` has to be true. So the host builds real entities
 * through PocketMine's own factory, and this side keeps sending the list they are built from.</p>
 *
 * <p>The snapshot is <b>full and idempotent</b>, not a stream of adds and removes: the set is
 * small (only the chunks already mirrored), and a full list heals itself when the host refused a
 * type last time. Identical consecutive snapshots are not sent at all - a field of stationary
 * mobs costs nothing after the first one.</p>
 *
 * <p>Players are excluded: they are already represented by the player mirrors, and a second
 * `Player` object per player would be a different player as far as plugins are concerned.</p>
 */
final class EntityMirror {

    /** One second, like the clock: entity positions are snapshots, not a physics feed. */
    private static final int INTERVAL = Math.max(1, Integer.getInteger("mukkit.entityInterval", 20));

    private final Server server;
    private final Queue<Map<String, Object>> inbound;
    private final java.util.function.BooleanSupplier enabled;
    private final ChunkStreamer chunks;

    /** Fingerprints of the last snapshot sent per world, to skip identical ones. */
    private final Map<String, Set<String>> sent = new LinkedHashMap<>();

    private final Set<String> reported = new HashSet<>();

    private int tick;

    EntityMirror(Server server, Queue<Map<String, Object>> inbound,
                 java.util.function.BooleanSupplier enabled, ChunkStreamer chunks) {
        this.server = server;
        this.inbound = inbound;
        this.enabled = enabled;
        this.chunks = chunks;
    }

    void forgetAll() {
        sent.clear();
        reported.clear();
    }

    void poll() {
        if (!enabled.getAsBoolean()) {
            return;
        }
        if (++tick % INTERVAL != 0) {
            return;
        }

        Set<String> worlds = chunks.mirroredWorlds();
        for (String world : worlds) {
            Level level = server.getLevelByName(world);
            if (level == null) {
                continue;
            }

            List<List<Object>> entities = new ArrayList<>();
            Set<String> fingerprints = new HashSet<>();
            for (long key : chunks.chunksOf(world)) {
                for (Entity entity : level.getChunkEntities(ChunkStreamer.xOf(key), ChunkStreamer.zOf(key)).values()) {
                    if (entity instanceof Player || entity.isClosed()) {
                        continue;
                    }
                    List<Object> tuple = tuple(entity);
                    entities.add(tuple);
                    fingerprints.add(fingerprint(tuple));
                }
            }

            if (fingerprints.equals(sent.get(world))) {
                continue;
            }
            sent.put(world, fingerprints);

            Map<String, Object> frame = new LinkedHashMap<>(4);
            frame.put("k", "entity.sync");
            frame.put("world", world);
            frame.put("e", entities);
            inbound.add(frame);
        }
        sent.keySet().retainAll(worlds);
    }

    /** Applies entity states a plugin produced, then lets the next snapshot read Nukkit back. */
    @SuppressWarnings("unchecked")
    void apply(Map<String, Object> op) {
        String world = String.valueOf(op.get("world"));
        Level level = server.getLevelByName(world);
        if (level == null) {
            report("no level named " + world);
            return;
        }
        if (!(op.get("e") instanceof List<?> rows)) {
            return;
        }

        for (Object entry : rows) {
            if (!(entry instanceof List<?> row) || row.size() < 6) {
                continue;
            }
            long id = ((Number) row.get(0)).longValue();
            Entity entity = level.getEntity(id);
            if (entity == null || entity.isClosed()) {
                // Died or despawned between the plugin's change and this tick. Nothing to apply.
                continue;
            }
            double x = number(row.get(1));
            double y = number(row.get(2));
            double z = number(row.get(3));
            double yaw = number(row.get(4));
            double pitch = number(row.get(5));

            // `teleport`, not `setPositionAndRotation`: this *is* a plugin action, so other
            // plugins may veto it and the client has to be told where the entity went.
            entity.teleport(new Location(x, y, z, yaw, pitch, level));

            if (row.size() > 6 && row.get(6) instanceof Map<?, ?> extra) {
                if (extra.get("hp") instanceof Number health && entity instanceof EntityLiving living) {
                    living.setHealth(health.floatValue());
                }
                if (extra.get("name") instanceof String name) {
                    entity.setNameTag(name);
                }
                if (extra.get("effects") instanceof List<?> effects && entity instanceof EntityLiving living) {
                    for (String gap : EffectState.apply(living, effects)) {
                        System.out.println("[mukkit] entity.state: " + gap);
                    }
                }
            }
        }
    }

    // ---------------------------------------------------------------- pure helpers

    /** A stable string for "this entity, here, like this" - the unit of change detection. */
    static String fingerprint(List<Object> tuple) {
        StringBuilder out = new StringBuilder(48);
        for (Object value : tuple) {
            out.append(value).append('|');
        }
        return out.toString();
    }

    private static List<Object> tuple(Entity entity) {
        Location location = entity.getLocation();
        List<Object> tuple = new ArrayList<>(8);
        tuple.add(entity.getId());
        tuple.add(entity.getSaveId());
        tuple.add(round(location.getX()));
        tuple.add(round(location.getY()));
        tuple.add(round(location.getZ()));
        tuple.add(round(location.getYaw()));
        tuple.add(round(location.getPitch()));

        // What the entity carries depends on what it is; a map keeps the two cases apart instead
        // of guessing from the JSON type of a bare value. `name` and `effects` are optional for
        // the same reason: most entities have neither.
        Map<String, Object> extra = new LinkedHashMap<>(4);
        if (!entity.getNameTag().isEmpty()) {
            extra.put("name", entity.getNameTag());
        }
        if (entity instanceof EntityItem itemEntity) {
            Item dropped = droppedItem(itemEntity);
            if (dropped != null && !dropped.isNull()) {
                extra.put("item", InventoryMirror.itemTuple(dropped));
            }
        } else if (entity instanceof EntityLiving living) {
            extra.put("hp", round(living.getHealth()));
            List<List<Object>> effects = EffectState.snapshot(living);
            if (!effects.isEmpty()) {
                extra.put("effects", effects);
            }
        }
        if (!extra.isEmpty()) {
            tuple.add(extra);
        }
        return tuple;
    }

    /**
     * The stack a dropped-item entity holds.
     *
     * <p>Nukkit keeps it in a protected field with no getter, but the entity's NBT is public and
     * `initEntity()` read the stack from exactly this tag, so the tag is the honest source - not
     * a guess and not reflection.</p>
     */
    private static Item droppedItem(EntityItem entity) {
        try {
            if (entity.namedTag == null) {
                return null;
            }
            CompoundTag tag = entity.namedTag.getCompound("Item");
            if (tag.isEmpty()) {
                return null;
            }
            return NBTIO.getItemHelper(tag);
        } catch (RuntimeException e) {
            // A stack whose NBT this build cannot read is reported as "no item", not as an empty
            // one: the host will refuse to build the entity rather than drop an air stack.
            return null;
        }
    }

    /**
     * Millimetre/degree rounding, so float noise between two coordinate pipelines does not read
     * as movement and wake the whole snapshot up every second.
     */
    private static double round(double value) {
        return Math.round(value * 1000.0) / 1000.0;
    }

    private static double number(Object value) {
        return value instanceof Number n ? n.doubleValue() : 0.0;
    }

    private void report(String message) {
        if (reported.add(message)) {
            System.out.println("[mukkit] entity.state: " + message + " (further occurrences suppressed)");
        }
    }
}
