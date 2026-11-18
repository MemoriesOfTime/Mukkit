package cn.mukkit.nukkit;

import cn.nukkit.network.protocol.ClientboundCloseFormPacket;
import cn.nukkit.network.protocol.ModalFormResponsePacket;
import cn.nukkit.network.protocol.ProtocolInfo;
import cn.nukkit.utils.BinaryStream;

import java.util.Map;
import java.util.UUID;

/**
 * Offline checks for the bookkeeping behind form responses.
 *
 * <p>The question this answers is not "does a map work" but "can a response be attributed to the
 * wrong form": a PocketMine form and a Nukkit plugin's form must never both be claimed by the
 * bridge, an answer must be delivered exactly once, and a player who leaves must not leave ids
 * behind that a recycled UUID could later be credited with.</p>
 *
 * <p>The second half decodes real response bytes, because "the player dismissed the form" has no
 * flag on the wire: the client simply omits the data field, and the adapter has to recognise the
 * dismissal from what Nukkit leaves behind.</p>
 */
public final class FormResponseCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        ledger();
        wire();
        report();
    }

    private static void ledger() {
        UUID steve = UUID.fromString("11111111-2222-3333-4444-555555555555");
        UUID alex = UUID.fromString("66666666-7777-8888-9999-000000000000");

        // ---------------------------------------------------------------- claiming
        FormLedger ledger = new FormLedger();
        ledger.remember(steve, 1_000_000);
        check("a sent form is remembered", ledger.size() == 1);
        check("its own answer is claimed", ledger.take(steve, 1_000_000));
        check("claiming removes it", ledger.size() == 0);
        check("a second answer to the same form is not claimed", !ledger.take(steve, 1_000_000));
        check("an id the bridge never sent is not claimed", !ledger.take(steve, 7));

        // ---------------------------------------------------------------- isolation
        ledger.remember(steve, 1_000_001);
        ledger.remember(alex, 1_000_002);
        check("one player's form is not another's", !ledger.take(steve, 1_000_002));
        check("the correct owner still claims it", ledger.take(alex, 1_000_002));
        check("an unknown player claims nothing", !ledger.take(
                UUID.fromString("00000000-0000-0000-0000-000000000001"), 1_000_001));
        check("the untouched id is still pending", ledger.pendingFor(steve).size() == 1);

        // ---------------------------------------------------------------- duplicates
        ledger.remember(steve, 1_000_001);
        check("re-remembering an id does not stack up entries", ledger.pendingFor(steve).size() == 1);

        // ---------------------------------------------------------------- leaving
        FormLedger quitter = new FormLedger();
        quitter.remember(steve, 10);
        quitter.remember(steve, 11);
        check("forget reports what it dropped", quitter.forget(steve) == 2);
        check("and the ids are gone", quitter.size() == 0 && !quitter.take(steve, 10));
        check("forgetting a player with nothing pending is harmless", quitter.forget(alex) == 0);

        // ---------------------------------------------------------------- host restart
        FormLedger restarted = new FormLedger();
        restarted.remember(steve, 20);
        restarted.remember(alex, 21);
        check("a new host clears every id", restarted.clear() == 2 && restarted.size() == 0);
        check("and an answer after the restart is not claimed", !restarted.take(steve, 20));

        // ---------------------------------------------------------------- bounded
        FormLedger bounded = new FormLedger();
        for (int i = 0; i < FormLedger.MAX_PENDING; i++) {
            bounded.remember(steve, 1_000_000 + i);
        }
        check("the ledger holds a full page of forms", bounded.size() == FormLedger.MAX_PENDING);
        bounded.remember(steve, 1_000_000 + FormLedger.MAX_PENDING);
        check("one more does not grow it", bounded.size() == FormLedger.MAX_PENDING);
        check(
            "the oldest form is the one dropped",
            bounded.pendingFor(steve).get(0) == 1_000_001
                && bounded.take(steve, 1_000_000 + FormLedger.MAX_PENDING)
        );
    }

    /**
     * What Nukkit hands the adapter for the two shapes a response can take.
     *
     * <p>Since 1.19.20 the packet is a pair of optionals: either form data, or a cancel reason.
     * {@code ModalFormResponsePacket.decode()} leaves {@code data} at its default whenever the
     * data field is absent, so "the player dismissed it" has to be read as "no data", not as a
     * separate flag - which is exactly the assumption this check pins down.</p>
     */
    private static void wire() {
        ModalFormResponsePacket submitted = decode(encode(1_000_007, "[\"beta\",3]"));
        check("a submitted response keeps its form id", submitted.formId == 1_000_007);
        check("and its JSON payload byte for byte", "[\"beta\",3]".equals(submitted.data));

        ModalFormResponsePacket dismissed = decode(encode(1_000_008, null));
        check("a dismissed response keeps its form id", dismissed.formId == 1_000_008);
        check(
            "and is recognisable only by the absent payload Nukkit leaves behind",
            "null".equals(dismissed.data)
        );
        check(
            "which the adapter maps to a closed form",
            "null".equals(dismissed.data) && !"null".equals(submitted.data)
        );

        // Closing a form is the one form packet with an age limit: it arrived in 1.21.2, and an id
        // an older client does not know can get it disconnected rather than merely ignored.
        check(
            "close-form is held back from clients that predate it",
            PacketMapper.minimumProtocol(new ClientboundCloseFormPacket()) == ProtocolInfo.v1_21_2
        );
        check(
            "and packets every client understands have no floor",
            PacketMapper.minimumProtocol(PacketMapper.map("TextPacket", Map.of())) == 0
        );
    }

    private static byte[] encode(int formId, String data) {
        BinaryStream stream = new BinaryStream().reset();
        stream.putUnsignedVarInt(formId);
        stream.putBoolean(data != null);
        if (data != null) {
            stream.putString(data);
        }
        // The cancel reason is a separate optional; a client that submits does not set it.
        stream.putBoolean(false);
        return stream.getBuffer();
    }

    private static ModalFormResponsePacket decode(byte[] buffer) {
        ModalFormResponsePacket packet = new ModalFormResponsePacket();
        // The modern layout only: before 1.19.20 the packet was a bare string.
        packet.protocol = ProtocolInfo.v1_21_2;
        packet.setBuffer(buffer);
        packet.setOffset(0);
        packet.decode();
        return packet;
    }

    private static void report() {
        System.out.println("forms-jvm " + (failures == 0 ? "PASS" : "FAILED")
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

    private FormResponseCheck() {
    }
}
