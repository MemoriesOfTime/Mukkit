package cn.mukkit.nukkit;

import java.util.Map;

/**
 * Offline checks for {@link WorldStateMirror}'s diff.
 *
 * <p>The two fields are independent: a clock that keeps running must not drag the "stopped" flag
 * along, and a plugin pausing the clock must not send a time value that then overwrites Nukkit's
 * (which is a tick ahead by the time it arrives). Getting the grouping wrong would be invisible
 * in normal play - the world would just occasionally jump - so it is pinned here.</p>
 */
public final class WorldStateCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        check("an unchanged clock reports nothing",
                WorldStateMirror.diff(clock(1000L, false), clock(1000L, false)).isEmpty());

        Map<String, Object> running = WorldStateMirror.diff(clock(1000L, false), clock(1020L, false));
        check("a running clock reports time alone", running.keySet().equals(java.util.Set.of("time")));
        check("and carries the new value", number(running.get("time")) == 1020.0);

        Map<String, Object> stopped = WorldStateMirror.diff(clock(1000L, false), clock(1000L, true));
        check("pausing reports the flag alone", stopped.keySet().equals(java.util.Set.of("stopped")));
        check("and the flag is true", Boolean.TRUE.equals(stopped.get("stopped")));

        Map<String, Object> resumed = WorldStateMirror.diff(clock(1000L, true), clock(1000L, false));
        check("resuming reports the flag alone", resumed.keySet().equals(java.util.Set.of("stopped")));
        check("and the flag is false", Boolean.FALSE.equals(resumed.get("stopped")));

        Map<String, Object> both = WorldStateMirror.diff(clock(5L, false), clock(6L, true));
        check("a jump and a pause travel together",
                both.keySet().equals(java.util.Set.of("time", "stopped")));

        // JSON gives Long for an integral value and Double for a fractional one; the compare must
        // not depend on which.
        check("integers and doubles compare as numbers",
                WorldStateMirror.diff(clock(1000L, false), clock(1000.0, false)).isEmpty());

        System.out.println("world-state " + (failures == 0 ? "PASS" : "FAILED")
                + " (" + checks + " checks)");
        if (failures > 0) {
            System.exit(1);
        }
    }

    private static Map<String, Object> clock(Number time, boolean stopped) {
        return Map.of("time", time, "stopped", stopped);
    }

    private static double number(Object value) {
        return value instanceof Number n ? n.doubleValue() : 0.0;
    }

    private static void check(String label, boolean ok) {
        checks++;
        if (ok) {
            System.out.println("ok    " + label);
        } else {
            failures++;
            System.out.println("FAIL  " + label);
        }
    }

    private WorldStateCheck() {
    }
}
