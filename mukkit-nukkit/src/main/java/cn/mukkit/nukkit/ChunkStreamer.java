package cn.mukkit.nukkit;

import cn.nukkit.Player;
import cn.nukkit.Server;
import cn.nukkit.level.Level;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Queue;
import java.util.Set;

/**
 * Keeps the host's chunk mirror subscribed to the chunks players are actually near.
 *
 * <p>The mirror is subscription-based, not a one-shot 5x5 square at join. A one-shot square
 * would be correct exactly where the player joined and wrong everywhere else: walk 200 blocks
 * and {@code getBlock()} starts answering air, teleport to another level and the whole world is
 * air. This class recomputes the union of the squares around every online player, sends the
 * chunks that are new, and tells the host to drop the ones nobody is near any more.</p>
 *
 * <p>Two consequences worth stating:</p>
 * <ul>
 *   <li>Chunks are shared, not per player: the union is what the host mirrors. A chunk stays as
 *       long as <em>any</em> player is within the radius, which is also why a player walking past
 *       an arena does not unload it from under somebody standing in it.</li>
 *   <li>Leaving the radius means reading air again, not reading stale blocks. Forgetting a chunk
 *       is visible and reportable; a chunk frozen at the moment the player walked away is a lie
 *       that only shows up much later.</li>
 * </ul>
 *
 * <p>The poll is cheap (a few hundred set lookups per tick at radius 2) but not free, so it runs
 * on its own interval and is forced when something makes the answer wrong immediately: a join, a
 * quit, a teleport, or a host that has just (re)connected with an empty mirror.</p>
 */
final class ChunkStreamer {

    /** Mirror radius; 0 mirrors the player's own chunk only, negative disables the mirror. */
    private static final int RADIUS = Integer.getInteger("mukkit.mirrorRadius", 2);

    /** One second by default: a walking player crosses a chunk every few seconds at most. */
    private static final int INTERVAL = Math.max(1, Integer.getInteger("mukkit.mirrorInterval", 20));

    private final Server server;
    private final Queue<Map<String, Object>> inbound;
    private final java.util.function.BooleanSupplier enabled;

    /** Chunks the host has been told about, per world. */
    private final Map<String, Set<Long>> sent = new LinkedHashMap<>();

    /**
     * Chunks sent since the last drain, as `{world, level, chunkKey}`.
     *
     * <p>A chunk frame carries its block entities, so a mirror that polls tiles afterwards would
     * otherwise send the same tiles a second time. Handing out the freshly sent chunks lets the
     * tile mirror seed its shadow without emitting.</p>
     */
    private final List<Object[]> newlySent = new ArrayList<>();

    private boolean forced = true;
    private int tick;

    ChunkStreamer(Server server, Queue<Map<String, Object>> inbound, java.util.function.BooleanSupplier enabled) {
        this.server = server;
        this.inbound = inbound;
        this.enabled = enabled;
    }

    /** Recompute on the next tick: a player moved to another place we cannot predict. */
    void invalidate() {
        forced = true;
    }

    /**
     * The host has no mirror at all (first connect, or a restart). Whatever it was told before
     * died with it, so the next poll must be a full subscription.
     */
    void forgetAll() {
        sent.clear();
        newlySent.clear();
        forced = true;
    }

    /**
     * Chunks sent since the last call, `{world, level, chunkKey}`.
     *
     * <p>Drained by {@link TileMirror}; keeping the list here rather than in that class means
     * the tile mirror does not need a second subscription to the same chunk set.</p>
     */
    List<Object[]> drainNewlySent() {
        if (newlySent.isEmpty()) {
            return List.of();
        }
        List<Object[]> out = new ArrayList<>(newlySent);
        newlySent.clear();
        return out;
    }

    void poll() {
        if (!enabled.getAsBoolean() || RADIUS < 0) {
            return;
        }
        if (!forced) {
            if (++tick % INTERVAL != 0) {
                return;
            }
        }
        forced = false;
        tick = 0;

        Map<String, Level> levels = new LinkedHashMap<>();
        Map<String, Set<Long>> desired = new LinkedHashMap<>();
        for (Player player : server.getOnlinePlayers().values()) {
            Level level = player.getLevel();
            if (level == null) {
                continue;
            }
            levels.putIfAbsent(level.getName(), level);
            collect(desired.computeIfAbsent(level.getName(), name -> new HashSet<>()),
                    player.getFloorX() >> 4, player.getFloorZ() >> 4);
        }

        for (Map.Entry<String, Set<Long>> entry : desired.entrySet()) {
            String world = entry.getKey();
            Set<Long> known = sent.getOrDefault(world, Set.of());
            Level level = levels.get(world);
            for (long key : entry.getValue()) {
                if (!known.contains(key)) {
                    inbound.add(ChunkSnapshot.frame(level, xOf(key), zOf(key)));
                    newlySent.add(new Object[]{world, level, key});
                }
            }
        }
        for (Map.Entry<String, Set<Long>> entry : sent.entrySet()) {
            String world = entry.getKey();
            Set<Long> wanted = desired.getOrDefault(world, Set.of());
            for (long key : entry.getValue()) {
                if (!wanted.contains(key)) {
                    inbound.add(unloadFrame(world, xOf(key), zOf(key)));
                }
            }
        }

        sent.clear();
        sent.putAll(desired);
    }

    // ---------------------------------------------------------------- pure helpers

    /** One long per chunk; the high half is x, the low half is z, both signed. */
    static long chunkKey(int chunkX, int chunkZ) {
        return ((long) chunkX << 32) | (chunkZ & 0xffffffffL);
    }

    static int xOf(long key) {
        return (int) (key >> 32);
    }

    static int zOf(long key) {
        return (int) key;
    }

    /** The square of chunks around a centre, including it. */
    static Set<Long> around(int centreX, int centreZ, int radius) {
        Set<Long> chunks = new HashSet<>();
        collect(chunks, centreX, centreZ, radius);
        return chunks;
    }

    static Map<String, Object> unloadFrame(String world, int chunkX, int chunkZ) {
        Map<String, Object> frame = new LinkedHashMap<>(5);
        frame.put("k", "chunk.unload");
        frame.put("world", world);
        frame.put("x", chunkX);
        frame.put("z", chunkZ);
        return frame;
    }

    private static void collect(Set<Long> into, int centreX, int centreZ) {
        collect(into, centreX, centreZ, RADIUS);
    }

    private static void collect(Set<Long> into, int centreX, int centreZ, int radius) {
        for (int dx = -radius; dx <= radius; dx++) {
            for (int dz = -radius; dz <= radius; dz++) {
                into.add(chunkKey(centreX + dx, centreZ + dz));
            }
        }
    }

    /** For the status line: how many chunks the host is currently subscribed to. */
    List<String> describe() {
        List<String> lines = new ArrayList<>();
        for (Map.Entry<String, Set<Long>> entry : sent.entrySet()) {
            lines.add(entry.getKey() + "=" + entry.getValue().size());
        }
        return lines;
    }

    /**
     * Whether a level has any mirrored chunks at all.
     *
     * <p>Used to keep block updates from levels nobody is in off the wire: the host would have
     * to invent a world for them, and a chunk it never received is air anyway, so the update
     * could only ever be applied to nothing.</p>
     */
    boolean mirrors(String world) {
        return sent.containsKey(world);
    }

    /**
     * Whether one chunk of a level has been sent to the host.
     *
     * <p>Level-wide is too coarse for block events: a level can be mirrored around one player and
     * not around another, and a block event from a chunk the host never received would be applied
     * to air. Asking about the chunk itself is the only honest answer.</p>
     */
    boolean mirrors(String world, int chunkX, int chunkZ) {
        Set<Long> known = sent.get(world);
        return known != null && known.contains(chunkKey(chunkX, chunkZ));
    }

    /** The worlds with at least one mirrored chunk; the entity mirror walks the same set. */
    Set<String> mirroredWorlds() {
        return Set.copyOf(sent.keySet());
    }

    /** The chunks the host has been told about in one world. */
    Set<Long> chunksOf(String world) {
        return Set.copyOf(sent.getOrDefault(world, Set.of()));
    }
}
