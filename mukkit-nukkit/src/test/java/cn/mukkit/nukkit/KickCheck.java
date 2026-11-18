package cn.mukkit.nukkit;

import java.util.Map;

/**
 * Offline checks for {@link DisconnectRouting}.
 *
 * <p>The interesting failure here is silent in the worst way: Nukkit's {@code Player.close()}
 * only sends the disconnect packet when the screen text is non-empty, so an empty message from
 * the host produces no packet at all. The player is removed and the quit event fires - the server
 * believes the kick worked - while the client sits there until its own timeout. Nothing logs it,
 * nothing throws; the player just hangs on a screen that never comes.</p>
 *
 * <p>The second check pins the other decision: a {@code DisconnectPacket} op must not be sent as
 * a packet. Delivering it would tell the client to leave while leaving the Nukkit player alive,
 * which is a ghost with a closed connection - see the class comment on {@link DisconnectRouting}.</p>
 */
public final class KickCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        check("a reason is shown as given", "cheating".equals(
                DisconnectRouting.screenMessage(Map.of("message", "cheating"))));
        check("the text is trimmed", "cheating".equals(
                DisconnectRouting.screenMessage(Map.of("message", "  cheating \n"))));
        check("an empty reason gets a real one",
                !DisconnectRouting.screenMessage(Map.of("message", "")).isEmpty());
        check("so does a blank one", !DisconnectRouting.screenMessage(Map.of("message", "   ")).isEmpty());
        check("and a missing field", !DisconnectRouting.screenMessage(Map.of()).isEmpty());
        check("a non-string field does not become the literal 'null'",
                DisconnectRouting.FALLBACK.equals(DisconnectRouting.screenMessage(Map.of("message", 42))));

        // Not a packet: this op closes the player instead of sending bytes. Adding it to the
        // mapper would reintroduce the ghost the class exists to avoid.
        check("DisconnectPacket is not mapped to a Nukkit packet",
                PacketMapper.map("DisconnectPacket", Map.of("message", "cheating")) == null);

        System.out.println("kick-jvm " + (failures == 0 ? "PASS" : "FAILED")
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

    private KickCheck() {
    }
}
