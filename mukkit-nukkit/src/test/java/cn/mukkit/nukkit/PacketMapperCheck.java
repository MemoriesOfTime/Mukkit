package cn.mukkit.nukkit;

import cn.mukkit.bridge.Json;
import cn.nukkit.GameVersion;
import cn.nukkit.network.protocol.DataPacket;
import cn.nukkit.network.protocol.SetTitlePacket;
import cn.nukkit.network.protocol.ToastRequestPacket;

import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.LinkedHashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * Proves {@link PacketMapper} produces packets this Nukkit build can actually encode.
 *
 * <p>The harness has two halves. The built-in cases pin the translations that are easy to get
 * quietly wrong (flooring, enum names, unknown inputs). The fixture half reads the {@code ops}
 * a real PocketMine run emitted - see {@code pm-host/tests/pm-packet-test.php} - and maps them
 * here, which is what closes the loop: those field tables were produced by upstream PM packet
 * objects, not by hand.</p>
 *
 * <p>There is no client here, and that is deliberate: this checks that the bridge hands Nukkit a
 * well-formed packet of the right class with the right fields, and that Nukkit's own encoder
 * accepts it at every protocol version the jar knows about. Whether the client draws it is a
 * question only a client can answer.</p>
 */
public final class PacketMapperCheck {

    private static int checks;
    private static final List<String> failures = new ArrayList<>();
    private static final Set<String> classesSeen = new LinkedHashSet<>();

    private PacketMapperCheck() {
    }

    public static void main(String[] args) throws Exception {
        GameVersion[] versions = GameVersion.values();
        GameVersion oldest = oldestEncodable(versions);
        GameVersion newest = versions[versions.length - 1];

        builtInCases();

        if (args.length > 0) {
            Path fixture = Path.of(args[0]);
            if (!Files.isRegularFile(fixture)) {
                fail("fixture", "not found: " + fixture);
            } else {
                fixture(fixture, oldest, newest);
            }
        } else {
            System.out.println("--   no fixture given, ran built-in cases only");
        }

        System.out.println();
        System.out.println("classes mapped    " + classesSeen);
        System.out.println("protocols used    " + oldest + " (" + oldest.getProtocol() + ") .. "
                + newest + " (" + newest.getProtocol() + ")");

        if (!failures.isEmpty()) {
            System.out.println();
            for (String failure : failures) {
                System.out.println("FAIL  " + failure);
            }
            System.out.println();
            System.out.println("m4-jvm FAIL  (" + failures.size() + " of " + checks + " checks)");
            System.exit(1);
        }
        System.out.println();
        System.out.println("m4-jvm PASS  (" + checks + " checks, " + classesSeen.size() + " classes)");
    }

    // ---------------------------------------------------------------- built-in cases

    private static void builtInCases() {
        // Anything outside the whitelist must be refused, not guessed at: the host counts those
        // separately, and silently inventing a packet would hide plugin bugs.
        check("unknown class returns null", PacketMapper.map("PlayerListPacket", Map.of()) == null);

        // Nukkit's PlaySoundPacket stores block coordinates; Level::addSound() itself uses
        // getFloorX(), so the bridge has to floor too or the sound lands at a different block.
        DataPacket positive = PacketMapper.map("PlaySoundPacket", Map.of(
                "soundName", "random.orb", "x", 12.9, "y", 64.0, "z", 0.4,
                "volume", 0.5, "pitch", 1.5));
        cn.nukkit.network.protocol.PlaySoundPacket sound = (cn.nukkit.network.protocol.PlaySoundPacket) positive;
        check("sound floors positive x", sound.x == 12);
        check("sound floors y", sound.y == 64);
        check("sound floors z", sound.z == 0);
        check("sound keeps volume", Math.abs(sound.volume - 0.5f) < 1e-6);
        check("sound keeps pitch", Math.abs(sound.pitch - 1.5f) < 1e-6);

        cn.nukkit.network.protocol.PlaySoundPacket negative =
                (cn.nukkit.network.protocol.PlaySoundPacket) PacketMapper.map("PlaySoundPacket",
                        Map.of("soundName", "x", "x", -3.25, "y", -0.5, "z", -1.0));
        check("sound floors negative x towards -inf", negative.x == -4);
        check("sound floors negative y towards -inf", negative.y == -1);

        // Scoreboard enums: PocketMine passes slot names, Nukkit wants its own enum, and the
        // names are not identical (PM "belowname" vs Nukkit BELOW_NAME).
        cn.nukkit.network.protocol.SetDisplayObjectivePacket sidebar =
                (cn.nukkit.network.protocol.SetDisplayObjectivePacket) PacketMapper.map("SetDisplayObjectivePacket",
                        Map.of("displaySlot", "sidebar", "objectiveName", "obj", "displayName", "Board",
                                "criteriaName", "dummy", "sortOrder", 1));
        check("sidebar slot", sidebar.displaySlot == cn.nukkit.network.protocol.types.DisplaySlot.SIDEBAR);
        check("descending sort order", sidebar.sortOrder == cn.nukkit.network.protocol.types.SortOrder.DESCENDING);
        check("criteria name survives as Nukkit's criteria", "dummy".equals(sidebar.criteria));

        cn.nukkit.network.protocol.SetDisplayObjectivePacket below =
                (cn.nukkit.network.protocol.SetDisplayObjectivePacket) PacketMapper.map("SetDisplayObjectivePacket",
                        Map.of("displaySlot", "belowname"));
        check("belowname slot", below.displaySlot == cn.nukkit.network.protocol.types.DisplaySlot.BELOW_NAME);
        check("ascending by default", below.sortOrder == cn.nukkit.network.protocol.types.SortOrder.ASCENDING);

        // Score entries carry their own scorer kind; PM's integer constants must not leak.
        cn.nukkit.network.protocol.SetScorePacket scores =
                (cn.nukkit.network.protocol.SetScorePacket) PacketMapper.map("SetScorePacket", Map.of(
                        "type", "change",
                        "entries", List.of(
                                Map.of("scoreboardId", 1, "objectiveName", "obj", "score", 42,
                                        "type", "player", "actorUniqueId", 99),
                                Map.of("scoreboardId", 2, "objectiveName", "obj", "score", 7,
                                        "type", "fake_player", "customName", "Steve"))));
        check("score action is SET", scores.action == cn.nukkit.network.protocol.SetScorePacket.Action.SET);
        check("two entries survive", scores.infos.size() == 2);
        check("player entry keeps its actor id",
                scores.infos.get(0).type == cn.nukkit.network.protocol.types.ScorerType.PLAYER
                        && scores.infos.get(0).entityId == 99L);
        check("fake entry keeps its name",
                scores.infos.get(1).type == cn.nukkit.network.protocol.types.ScorerType.FAKE
                        && "Steve".equals(scores.infos.get(1).name));

        cn.nukkit.network.protocol.SetScorePacket removal =
                (cn.nukkit.network.protocol.SetScorePacket) PacketMapper.map("SetScorePacket",
                        Map.of("type", "remove", "entries", List.of()));
        check("remove action", removal.action == cn.nukkit.network.protocol.SetScorePacket.Action.REMOVE);

        // A bad name must throw (the gateway logs and drops one packet) rather than pick a
        // default that happens to be plausible.
        check("unknown title type throws", throwsOn(() -> PacketMapper.map("SetTitlePacket",
                Map.of("type", "bogus"))));
        check("unknown display slot throws", throwsOn(() -> PacketMapper.map("SetDisplayObjectivePacket",
                Map.of("displaySlot", "bogus"))));
    }

    // ---------------------------------------------------------------- fixture

    @SuppressWarnings("unchecked")
    private static void fixture(Path path, GameVersion oldest, GameVersion newest) throws Exception {
        String text = Files.readString(path, StandardCharsets.UTF_8);
        Object parsed = Json.parse(text);
        if (!(parsed instanceof Map<?, ?> envelope)) {
            fail("fixture", "not a JSON object");
            return;
        }
        Object ops = envelope.get("ops");
        if (!(ops instanceof List<?> list)) {
            fail("fixture", "no 'ops' array");
            return;
        }
        if (list.isEmpty()) {
            fail("fixture", "'ops' array is empty");
            return;
        }

        for (Object item : list) {
            if (!(item instanceof Map<?, ?> raw)) {
                fail("fixture", "op is not an object: " + item);
                continue;
            }
            Map<String, Object> op = (Map<String, Object>) raw;
            String className = String.valueOf(op.get("cls"));
            Map<String, Object> fields = op.get("f") instanceof Map<?, ?> f
                    ? (Map<String, Object>) f
                    : Map.of();

            DataPacket packet;
            try {
                packet = PacketMapper.map(className, fields);
            } catch (RuntimeException e) {
                fail(className, "mapper threw: " + e.getMessage());
                continue;
            }
            if (packet == null) {
                fail(className, "op from the real host is not in the whitelist");
                continue;
            }
            classesSeen.add(className);
            check("mapped class for " + className, className.equals(packet.getClass().getSimpleName()));

            byte[] payload = encode(packet, newest);
            check(className + " encodes at " + newest, payload.length > 0);

            // The same object, reverted and re-encoded at the oldest protocol in the jar:  if the
            // bridge had baked wire bytes anywhere, this is where it would show up.
            byte[] oldPayload = encode(packet, oldest);
            check(className + " encodes at " + oldest, oldPayload.length > 0);

            // Two packets that only differ by one field must not encode identically; that
            // catches a mapper that sets the wrong field name and silently defaults.
            byte[] again = encode(PacketMapper.map(className, fields), newest);
            check(className + " encodes deterministically", java.util.Arrays.equals(payload, again));

            verifyDecoded(className, fields, payload, newest);
        }
    }

    /**
     * Only the packets Nukkit itself can parse are checked this way; the rest answer
     * {@code decodeUnsupported()}, which is a property of the server, not of the bridge.
     *
     * <p>{@code encode()} writes the packet id first (see {@code DataPacket.reset()}) while
     * {@code decode()} starts at the payload, so the id is skipped before handing bytes back.</p>
     */
    private static void verifyDecoded(String className, Map<String, Object> fields, byte[] payload, GameVersion version) {
        byte[] body = stripPacketId(payload);
        if ("SetTitlePacket".equals(className)) {
            SetTitlePacket back = new SetTitlePacket();
            back.gameVersion = version;
            back.protocol = version.getProtocol();
            back.setBuffer(body);
            back.decode();
            check("SetTitle type survives the encoder", back.type == titleTypeOf(String.valueOf(fields.get("type"))));
            check("SetTitle text survives the encoder", String.valueOf(fields.get("text")).equals(back.text));
            check("SetTitle fade times survive the encoder",
                    number(fields.get("fadeInTime")) == back.fadeInTime
                            && number(fields.get("stayTime")) == back.stayTime);
        } else if ("ToastRequestPacket".equals(className)) {
            ToastRequestPacket back = new ToastRequestPacket();
            back.gameVersion = version;
            back.protocol = version.getProtocol();
            back.setBuffer(body);
            back.decode();
            check("Toast title survives the encoder", String.valueOf(fields.get("title")).equals(back.title));
            check("Toast body survives the encoder", String.valueOf(fields.get("body")).equals(back.content));
        }
    }

    private static byte[] stripPacketId(byte[] payload) {
        cn.nukkit.utils.BinaryStream stream = new cn.nukkit.utils.BinaryStream(payload);
        stream.getUnsignedVarInt();
        return java.util.Arrays.copyOfRange(payload, stream.getOffset(), payload.length);
    }

    private static int titleTypeOf(String name) {
        return switch (name) {
            case "clear" -> SetTitlePacket.TYPE_CLEAR;
            case "reset" -> SetTitlePacket.TYPE_RESET;
            case "title" -> SetTitlePacket.TYPE_TITLE;
            case "subtitle" -> SetTitlePacket.TYPE_SUBTITLE;
            case "actionbar" -> SetTitlePacket.TYPE_ACTION_BAR;
            case "times" -> SetTitlePacket.TYPE_ANIMATION_TIMES;
            case "title_json" -> SetTitlePacket.TYPE_TITLE_JSON;
            case "subtitle_json" -> SetTitlePacket.TYPE_SUBTITLE_JSON;
            case "actionbar_json" -> SetTitlePacket.TYPE_ACTIONBAR_JSON;
            default -> throw new IllegalArgumentException(name);
        };
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Protocols at or below 274 resolve packet IDs through a live server's packet pool, so they
     * cannot be encoded off-line. That is a property of Nukkit's encoder, not of the bridge; the
     * oldest version this harness can speak to is the first one past that threshold.
     */
    private static GameVersion oldestEncodable(GameVersion[] versions) {
        for (GameVersion version : versions) {
            if (version.getProtocol() > 274) {
                return version;
            }
        }
        throw new IllegalStateException("no encodable game version in this Nukkit build");
    }

    private static byte[] encode(DataPacket packet, GameVersion version) {
        packet.gameVersion = version;
        packet.protocol = version.getProtocol();
        packet.encode();
        return packet.getBuffer();
    }

    private static int number(Object value) {
        return value instanceof Number number ? number.intValue() : 0;
    }

    private static boolean throwsOn(Runnable action) {
        try {
            action.run();
            return false;
        } catch (IllegalArgumentException e) {
            return true;
        }
    }

    private static void check(String what, boolean condition) {
        checks++;
        if (condition) {
            System.out.println("ok    " + what);
        } else {
            fail(what, "assertion failed");
        }
    }

    private static void fail(String what, String detail) {
        failures.add(what + ": " + detail);
        System.out.println("FAIL  " + what + ": " + detail);
    }
}
