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
 * Keeps the host's view of a world's clock aligned with Nukkit's.
 *
 * <p>PocketMine's {@code World::tick()} never runs under this bridge, so its clock is frozen at
 * whatever it was created with unless somebody sets it. A plugin asking {@code $world->getTime()}
 * would get 6000 forever, and {@code $world->setTime(0)} would change a number nobody sees. Both
 * are fixed by the same mirror.</p>
 *
 * <p>Only worlds with a bridged player are polled: the host would otherwise create a {@code World}
 * for every level on the server, including the ones no plugin can reach. Time advances every tick
 * on the Nukkit side, so this writes one small op per world per interval - that is the cost of a
 * clock that agrees, and it is why the interval is a second rather than a tick.</p>
 */
final class WorldStateMirror {

    /** A world clock is not a physics simulation; one second is plenty for day/night logic. */
    private static final int INTERVAL = Math.max(1, Integer.getInteger("mukkit.worldInterval", 20));

    private final Server server;
    private final Queue<Map<String, Object>> inbound;
    private final java.util.function.BooleanSupplier enabled;

    private final Map<String, Map<String, Object>> shadow = new LinkedHashMap<>();
    private final Set<String> reported = new HashSet<>();

    private int tick;

    WorldStateMirror(Server server, Queue<Map<String, Object>> inbound, java.util.function.BooleanSupplier enabled) {
        this.server = server;
        this.inbound = inbound;
        this.enabled = enabled;
    }

    /** A new host knows nothing about any clock; the next poll sends every world it can see. */
    void forgetAll() {
        shadow.clear();
        reported.clear();
    }

    void poll() {
        if (!enabled.getAsBoolean()) {
            return;
        }
        if (++tick % INTERVAL != 0) {
            return;
        }

        Map<String, Level> levels = new LinkedHashMap<>();
        for (Player player : server.getOnlinePlayers().values()) {
            Level level = player.getLevel();
            if (level != null) {
                levels.putIfAbsent(level.getName(), level);
            }
        }

        for (Map.Entry<String, Level> entry : levels.entrySet()) {
            Map<String, Object> after = snapshot(entry.getValue());
            Map<String, Object> before = shadow.put(entry.getKey(), after);
            // No shadow means the host has never been told this world's clock; an empty map makes
            // the diff report every field, which is exactly the first copy.
            Map<String, Object> changed = diff(before == null ? Map.of() : before, after);
            if (changed.isEmpty()) {
                continue;
            }
            Map<String, Object> frame = new LinkedHashMap<>(4);
            frame.put("k", "world.state");
            frame.put("world", entry.getKey());
            frame.put("s", changed);
            inbound.add(frame);
        }
        shadow.keySet().retainAll(levels.keySet());
    }

    /** Applies a clock the host pushed, then re-reads the level into the shadow. */
    @SuppressWarnings("unchecked")
    void apply(Map<String, Object> op) {
        String world = String.valueOf(op.get("world"));
        Level level = server.getLevelByName(world);
        if (level == null) {
            // The host only names worlds it was told about, so this is a host that is older than
            // the level list or a level that was unloaded under it. Say it once; do not invent.
            report("no level named " + world);
            return;
        }
        if (!(op.get("s") instanceof Map<?, ?> raw)) {
            return;
        }
        Map<String, Object> state = (Map<String, Object>) raw;

        if (state.containsKey("time")) {
            level.setTime((int) number(state.get("time")));
        }
        if (state.containsKey("stopped")) {
            if (Boolean.TRUE.equals(state.get("stopped"))) {
                level.stopTime();
            } else {
                level.startTime();
            }
        }

        shadow.put(world, snapshot(level));
    }

    // ---------------------------------------------------------------- pure helpers

    /**
     * What changed, field by field.
     *
     * <p>Package-private and static so the grouping can be checked without a server: which fields
     * travel together is the part that silently breaks, not the two getters.</p>
     */
    static Map<String, Object> diff(Map<String, Object> before, Map<String, Object> after) {
        Map<String, Object> changed = new LinkedHashMap<>(3);
        if (number(before.get("time")) != number(after.get("time"))) {
            changed.put("time", after.get("time"));
        }
        if (Boolean.TRUE.equals(before.get("stopped")) != Boolean.TRUE.equals(after.get("stopped"))) {
            changed.put("stopped", after.get("stopped"));
        }
        return changed;
    }

    private static Map<String, Object> snapshot(Level level) {
        Map<String, Object> state = new LinkedHashMap<>(3);
        state.put("time", level.getTime());
        state.put("stopped", level.stopTime);
        return state;
    }

    private static double number(Object value) {
        return value instanceof Number n ? n.doubleValue() : 0.0;
    }

    private void report(String message) {
        if (reported.add(message)) {
            System.out.println("[mukkit] world.state: " + message + " (further occurrences suppressed)");
        }
    }
}
