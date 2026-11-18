package cn.mukkit.bridge;

import java.util.List;
import java.util.Map;

/**
 * Everything the tick driver needs from the game server, and everything the game server
 * needs to hear back from the plugin host.
 *
 * <p>Deliberately narrow: the driver owns timing, framing and supervision, while the
 * implementation owns the translation between Nukkit objects and bridge operations. That
 * split is what lets the transport be tested with a synthetic gateway long before a real
 * server is wired in.</p>
 */
public interface Gateway {

    /** Inbound work (events, commands) accumulated since the previous tick. Called once per tick. */
    default List<Map<String, Object>> drainInbound() {
        return List.of();
    }

    /** Apply one outbound operation produced by the plugin host. Called on the tick thread. */
    default void applyOp(Map<String, Object> op) {
    }

    /** The host finished its handshake and reported which plugins it accepted. */
    default void onReady(Map<String, Object> payload) {
    }

    /** A log line produced by the host outside of the operation list. */
    default void onLog(String level, String message) {
    }

    /** The host stopped responding for longer than the hard deadline. */
    default void onHostLost(String reason) {
    }
}
