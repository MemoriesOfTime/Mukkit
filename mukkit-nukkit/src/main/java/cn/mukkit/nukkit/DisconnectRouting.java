package cn.mukkit.nukkit;

import java.util.Map;

/**
 * What a PocketMine {@code DisconnectPacket} op means on the Nukkit side.
 *
 * <p>It is deliberately not a packet mapping. Sending a {@code DisconnectPacket} to a client only
 * tells that client to go away: the server-side {@code Player} keeps existing until the socket
 * close is noticed, Nukkit's plugins never see a quit, and anything still running for that player
 * keeps running. PocketMine, meanwhile, has already removed the player and broadcast the leave
 * line. The answer is {@code Player.close()}, the same uninterruptible path Nukkit uses for
 * remote disconnects: it sends the screen, removes the player and fires {@code PlayerQuitEvent}
 * in the same call.</p>
 *
 * <p>Not {@code kick()}: that one is cancellable by other Nukkit plugins, and by the time this op
 * exists the other side has committed - its quit event has fired and its player list is already
 * updated. Honouring a veto would mean resurrecting a player everybody else has seen leave, so
 * the commit wins and the disconnect is final.</p>
 */
final class DisconnectRouting {

    /**
     * Nukkit skips the disconnect packet entirely when the text is empty ({@code close()} checks
     * {@code !reason.isEmpty()}), which leaves the client connected until its own timeout instead
     * of showing a screen. A blank message therefore has to become a real one.
     */
    static final String FALLBACK = "Kicked";

    /**
     * The text to put on the client's disconnect screen.
     *
     * <p>PocketMine has already resolved its {@code Translatable} against the server language
     * before the message reaches the wire, so this is a plain string in the player's language
     * and needs no second translation here.</p>
     */
    static String screenMessage(Map<String, Object> fields) {
        Object raw = fields.get("message");
        String message = raw instanceof String s ? s.trim() : "";
        return message.isEmpty() ? FALLBACK : message;
    }

    private DisconnectRouting() {
    }
}
