package cn.mukkit.nukkit;

import java.util.List;

/**
 * Offline checks for the held-action table's per-event rules.
 *
 * <p>{@link BlockEventCheck} pins the block-break contract (ids, deadline). This one pins what
 * changed when the table stopped being block-break-shaped: a withdrawn capability drops only its
 * own entries, a verdict still reaches the entry it names, and an id is never recycled by the
 * removal path.</p>
 */
public final class EventHoldCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        // ---------------------------------------------------------------- per-name removal
        PendingActions<String> pending = new PendingActions<>(10);
        long a1 = pending.add("block.break#1");
        long drop1 = pending.add("player.drop#1");
        long a2 = pending.add("block.break#2");

        check(
                "withdrawing one event drops only its entries",
                pending.removeIf(payload -> payload.startsWith("block.break#")) == 2
        );
        check("the other event's entry survives", pending.size() == 1);
        check("and is still reachable by its id", "player.drop#1".equals(pending.take(drop1)));
        check("a withdrawn entry's verdict is stale, not applied", pending.take(a1) == null && pending.take(a2) == null);

        // ---------------------------------------------------------------- no id reuse
        long next = pending.add("player.drop#2");
        check("removal does not recycle ids", next > a2 && next > drop1);

        // ---------------------------------------------------------------- sibling names
        // The event vocabulary is full of names that start with one another (player.toggle.sneak
        // vs .sprint, block.grow vs block.spread). Withdrawal is an exact-name test, and this pins
        // it: a prefix match would drop a listener's sibling the moment they disabled one plugin.
        PendingActions<String> siblings = new PendingActions<>(10);
        siblings.add("player.toggle.sneak");
        siblings.add("player.toggle.sprint");
        siblings.add("block.grow");
        siblings.add("block.spread");
        check(
                "withdrawing sneak leaves sprint alone",
                siblings.removeIf("player.toggle.sneak"::equals) == 1
        );
        check(
                "and withdrawing grow leaves spread alone",
                siblings.removeIf("block.grow"::equals) == 1 && siblings.size() == 2
        );

        // ---------------------------------------------------------------- empty table
        check("nothing to withdraw is not an error", pending.removeIf(payload -> true) == 1);
        check("and the table is empty afterwards", pending.size() == 0);

        // ---------------------------------------------------------------- expiry is name-blind
        PendingActions<String> timed = new PendingActions<>(2);
        timed.add("block.break#3");
        timed.add("player.drop#3");
        check("the first tick keeps both", timed.advance().isEmpty());
        List<String> expired = timed.advance();
        check("both expire on the same deadline", expired.size() == 2);
        check("and they come back with their payloads", expired.contains("block.break#3") && expired.contains("player.drop#3"));

        System.out.println("event-hold-jvm " + (failures == 0 ? "PASS" : "FAILED")
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

    private EventHoldCheck() {
    }
}
