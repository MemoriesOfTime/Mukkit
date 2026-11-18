package cn.mukkit.nukkit;

import java.util.List;

/**
 * Offline checks for the bookkeeping behind held actions.
 *
 * <p>The interesting part is not "does a map store things" but the two rules that keep a held
 * action from becoming a stuck player: an action must survive exactly its deadline and no longer,
 * and an id must never be handed out twice.</p>
 */
public final class BlockEventCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        // ---------------------------------------------------------------- identity
        PendingActions<String> pending = new PendingActions<>(5);
        long first = pending.add("a");
        long second = pending.add("b");
        check("ids increase", second > first);
        check("ids are not zero", first > 0);
        check("two actions are tracked", pending.size() == 2);

        check("take returns the payload", "a".equals(pending.take(first)));
        check("take removes it", pending.size() == 1);
        check("taking twice returns nothing", pending.take(first) == null);
        check("an unknown id returns nothing", pending.take(9999) == null);

        // ---------------------------------------------------------------- deadline
        PendingActions<String> timed = new PendingActions<>(3);
        timed.add("held");
        // tick 1, 2: still inside the window.
        check("an action survives the first tick", timed.advance().isEmpty());
        check("an action survives the second tick", timed.advance().isEmpty());
        List<String> expired = timed.advance();
        check("an action expires on its deadline", expired.equals(List.of("held")));
        check("expired actions are forgotten", timed.size() == 0);
        check("a late take finds nothing", timed.take(1) == null);

        // The caller must not replay something that timed out, and a verdict that arrives after the
        // expiry has to be counted as stale rather than applied to the next action with that id.
        long reused = timed.add("next");
        check("ids are not reused after an expiry", reused != 1);

        // ---------------------------------------------------------------- clearing
        PendingActions<String> cleared = new PendingActions<>(10);
        cleared.add("x");
        cleared.add("y");
        check("clear reports what it dropped", cleared.clear().size() == 2);
        check("clear empties the map", cleared.size() == 0 && cleared.clear().isEmpty());

        System.out.println("block-events-jvm " + (failures == 0 ? "PASS" : "FAILED")
                + " (" + checks + " checks)");
        if (failures > 0) {
            System.exit(1);
        }
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

    private BlockEventCheck() {
    }
}
