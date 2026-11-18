package cn.mukkit.nukkit;

import cn.nukkit.network.protocol.DataPacket;
import cn.nukkit.network.protocol.ClientboundCloseFormPacket;
import cn.nukkit.network.protocol.ModalFormRequestPacket;
import cn.nukkit.network.protocol.PlaySoundPacket;
import cn.nukkit.network.protocol.ProtocolInfo;
import cn.nukkit.network.protocol.RemoveObjectivePacket;
import cn.nukkit.network.protocol.SetDisplayObjectivePacket;
import cn.nukkit.network.protocol.SetScorePacket;
import cn.nukkit.network.protocol.SetTitlePacket;
import cn.nukkit.network.protocol.TextPacket;
import cn.nukkit.network.protocol.ToastRequestPacket;
import cn.nukkit.network.protocol.types.DisplaySlot;
import cn.nukkit.network.protocol.types.ScorerType;
import cn.nukkit.network.protocol.types.SortOrder;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/**
 * Turns a bridge {@code packet} operation into a real Nukkit packet.
 *
 * <p>The host never sends bytes: it names a packet class and a field table, and this is where
 * that becomes a Nukkit object whose encoding (and therefore protocol version) belongs to the
 * server, not to the frozen PocketMine build. Field names are the PocketMine ones because
 * PocketMine is the producer; the translation to Nukkit's spelling happens here and nowhere
 * else.</p>
 *
 * <p>Some translations are lossy on purpose and are documented per method. The rule is: never
 * invent data. If Nukkit cannot express a field, drop it and say so in the comment, rather than
 * synthesising a value that will look plausible on a client and be wrong.</p>
 */
final class PacketMapper {

    private PacketMapper() {
    }

    /**
     * @return the packet, or {@code null} when the class is not in the whitelist
     * @throws IllegalArgumentException when the fields are unusable
     */
    static DataPacket map(String className, Map<String, Object> fields) {
        return switch (className) {
            case "TextPacket" -> text(fields);
            case "SetTitlePacket" -> title(fields);
            case "ModalFormRequestPacket" -> modalForm(fields);
            case "ClientboundCloseFormPacket" -> closeForm();
            case "ToastRequestPacket" -> toast(fields);
            case "PlaySoundPacket" -> sound(fields);
            case "SetDisplayObjectivePacket" -> objective(fields);
            case "SetScorePacket" -> score(fields);
            case "RemoveObjectivePacket" -> removeObjective(fields);
            default -> null;
        };
    }

    /**
     * The oldest client protocol that understands this packet, or 0 for "all of them".
     *
     * <p>Most of the whitelist has been on the wire for a decade. The exceptions are packets added
     * by a specific Bedrock release, and an id an older client does not know is not merely ignored
     * — it can get the client disconnected. Nukkit guards its own {@code closeFormWindows()} the
     * same way, so this mirrors the server rather than inventing a policy.</p>
     */
    static int minimumProtocol(DataPacket packet) {
        if (packet instanceof ClientboundCloseFormPacket) {
            return ProtocolInfo.v1_21_2;
        }
        return 0;
    }

    // ---------------------------------------------------------------- text

    private static TextPacket text(Map<String, Object> fields) {
        TextPacket packet = new TextPacket();
        packet.type = textType(string(fields, "type", "chat"));
        packet.source = string(fields, "source", "");
        packet.message = string(fields, "message", "");
        return packet;
    }

    private static byte textType(String name) {
        return switch (name) {
            case "raw" -> TextPacket.TYPE_RAW;
            case "chat" -> TextPacket.TYPE_CHAT;
            case "system" -> TextPacket.TYPE_SYSTEM;
            case "tip" -> TextPacket.TYPE_TIP;
            case "popup" -> TextPacket.TYPE_POPUP;
            case "announcement" -> TextPacket.TYPE_ANNOUNCEMENT;
            case "whisper" -> TextPacket.TYPE_WHISPER;
            default -> throw new IllegalArgumentException("unknown TextPacket type: " + name);
        };
    }

    // ---------------------------------------------------------------- title

    /**
     * Both sides carry the same eight variants, but the constant numbers are an implementation
     * detail of each project, so the bridge passes names and each side resolves its own.
     */
    private static SetTitlePacket title(Map<String, Object> fields) {
        SetTitlePacket packet = new SetTitlePacket();
        packet.type = titleType(string(fields, "type", "title"));
        packet.text = string(fields, "text", "");
        packet.fadeInTime = integer(fields, "fadeInTime", 0);
        packet.stayTime = integer(fields, "stayTime", 0);
        packet.fadeOutTime = integer(fields, "fadeOutTime", 0);
        // xuid / platformOnlineId / filteredTitleText are left empty: PocketMine's packet
        // carries them for server-authoritative chat filtering, which this bridge does not do.
        return packet;
    }

    private static int titleType(String name) {
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
            default -> throw new IllegalArgumentException("unknown SetTitlePacket type: " + name);
        };
    }

    // ---------------------------------------------------------------- forms

    private static ModalFormRequestPacket modalForm(Map<String, Object> fields) {
        ModalFormRequestPacket packet = new ModalFormRequestPacket();
        packet.formId = integer(fields, "formId", 0);
        packet.data = string(fields, "formData", "{}");
        return packet;
    }

    /**
     * Carries no payload on either side: it exists only to make the client close whatever form is
     * on screen, which is what {@code Player::closeAllForms()} needs after a menu-driven flow ends.
     */
    private static ClientboundCloseFormPacket closeForm() {
        return new ClientboundCloseFormPacket();
    }

    // ---------------------------------------------------------------- toast

    private static ToastRequestPacket toast(Map<String, Object> fields) {
        ToastRequestPacket packet = new ToastRequestPacket();
        packet.title = string(fields, "title", "");
        packet.content = string(fields, "body", "");
        return packet;
    }

    // ---------------------------------------------------------------- sound

    /**
     * Nukkit's PlaySoundPacket stores <em>block</em> coordinates as ints and shifts them left by
     * three when encoding; its own {@code Level::addSound()} fills them with {@code getFloorX()}.
     * PocketMine's packet carries floats. Flooring here reproduces what a Nukkit-native sound
     * call would have produced; inventing a fractional encoding would desynchronise us from the
     * server's own sounds.
     */
    private static PlaySoundPacket sound(Map<String, Object> fields) {
        PlaySoundPacket packet = new PlaySoundPacket();
        packet.name = string(fields, "soundName", "");
        packet.x = floor(fields, "x");
        packet.y = floor(fields, "y");
        packet.z = floor(fields, "z");
        packet.volume = floating(fields, "volume", 1.0f);
        packet.pitch = floating(fields, "pitch", 1.0f);
        return packet;
    }

    // ---------------------------------------------------------------- scoreboard

    private static SetDisplayObjectivePacket objective(Map<String, Object> fields) {
        SetDisplayObjectivePacket packet = new SetDisplayObjectivePacket();
        packet.displaySlot = displaySlot(string(fields, "displaySlot", "sidebar"));
        packet.objectiveId = string(fields, "objectiveName", "");
        packet.displayName = string(fields, "displayName", "");
        packet.criteria = string(fields, "criteriaName", "dummy");
        packet.sortOrder = sortOrder(fields.get("sortOrder"));
        return packet;
    }

    private static RemoveObjectivePacket removeObjective(Map<String, Object> fields) {
        RemoveObjectivePacket packet = new RemoveObjectivePacket();
        packet.objectiveId = string(fields, "objectiveName", "");
        return packet;
    }

    private static SetScorePacket score(Map<String, Object> fields) {
        SetScorePacket packet = new SetScorePacket();
        packet.action = "remove".equals(string(fields, "type", "change"))
                ? SetScorePacket.Action.REMOVE
                : SetScorePacket.Action.SET;

        List<SetScorePacket.ScoreInfo> infos = new ArrayList<>();
        Object raw = fields.get("entries");
        if (raw instanceof List<?> entries) {
            for (Object entry : entries) {
                if (!(entry instanceof Map<?, ?> map)) {
                    throw new IllegalArgumentException("SetScorePacket entry is not an object");
                }
                @SuppressWarnings("unchecked")
                Map<String, Object> row = (Map<String, Object>) map;
                infos.add(scoreInfo(row));
            }
        }
        packet.infos = infos;
        return packet;
    }

    private static SetScorePacket.ScoreInfo scoreInfo(Map<String, Object> row) {
        // The three-argument constructor leaves type/name/entityId at their defaults, which is
        // exactly what we want: fill them explicitly below instead of guessing which overload
        // implies which ScorerType.
        SetScorePacket.ScoreInfo info = new SetScorePacket.ScoreInfo(
                number(row.get("scoreboardId")).longValue(),
                string(row, "objectiveName", ""),
                integer(row, "score", 0)
        );
        info.type = scorerType(string(row, "type", "fake_player"));
        info.entityId = number(row.get("actorUniqueId")).longValue();
        info.name = string(row, "customName", "");
        return info;
    }

    private static DisplaySlot displaySlot(String name) {
        return switch (name) {
            case "sidebar" -> DisplaySlot.SIDEBAR;
            case "list" -> DisplaySlot.LIST;
            case "belowname" -> DisplaySlot.BELOW_NAME;
            default -> throw new IllegalArgumentException("unknown display slot: " + name);
        };
    }

    private static SortOrder sortOrder(Object value) {
        if (value instanceof String name) {
            return "descending".equals(name) ? SortOrder.DESCENDING : SortOrder.ASCENDING;
        }
        return number(value).intValue() == 1 ? SortOrder.DESCENDING : SortOrder.ASCENDING;
    }

    private static ScorerType scorerType(String name) {
        return switch (name) {
            case "player" -> ScorerType.PLAYER;
            case "entity" -> ScorerType.ENTITY;
            case "fake_player" -> ScorerType.FAKE;
            default -> throw new IllegalArgumentException("unknown scorer type: " + name);
        };
    }

    // ---------------------------------------------------------------- field helpers

    private static String string(Map<String, Object> fields, String key, String fallback) {
        Object value = fields.get(key);
        return value instanceof String text ? text : fallback;
    }

    private static int integer(Map<String, Object> fields, String key, int fallback) {
        Object value = fields.get(key);
        return value instanceof Number number ? number.intValue() : fallback;
    }

    private static float floating(Map<String, Object> fields, String key, float fallback) {
        Object value = fields.get(key);
        return value instanceof Number number ? number.floatValue() : fallback;
    }

    private static int floor(Map<String, Object> fields, String key) {
        Object value = fields.get(key);
        return value instanceof Number number ? (int) Math.floor(number.doubleValue()) : 0;
    }

    private static Number number(Object value) {
        return value instanceof Number number ? number : 0L;
    }
}
