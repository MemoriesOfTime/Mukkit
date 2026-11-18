package cn.mukkit.bridge;

import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Wire protocol constants and message helpers.
 *
 * <p>The whole protocol is tick-framed: the JVM sends exactly one {@code tick} frame per
 * server tick carrying every piece of inbound work accumulated since the previous tick, and
 * the host answers with exactly one {@code tickdone} frame carrying every outbound operation.
 * There is no re-entrant call from the host back into the JVM, which is what keeps a slow
 * plugin from deadlocking the main thread.</p>
 */
public final class Protocol {

    public static final int VERSION = 18;
    public static final String CODEC_JSON = "json";

    public static final String HELLO = "hello";
    public static final String WELCOME = "welcome";
    public static final String PLUGINS = "plugins";
    public static final String READY = "ready";
    public static final String TICK = "tick";
    public static final String TICK_DONE = "tickdone";
    public static final String LOG = "log";
    public static final String SHUTDOWN = "shutdown";

    private Protocol() {
    }

    public static Map<String, Object> envelope(String type, long id, Map<String, Object> payload) {
        Map<String, Object> message = new LinkedHashMap<>(4);
        message.put("t", type);
        message.put("id", id);
        message.put("p", payload == null ? new LinkedHashMap<String, Object>() : payload);
        return message;
    }

    public static String type(Map<String, Object> message) {
        Object value = message.get("t");
        return value instanceof String text ? text : "";
    }

    public static long id(Map<String, Object> message) {
        Object value = message.get("id");
        return value instanceof Number number ? number.longValue() : -1L;
    }

    @SuppressWarnings("unchecked")
    public static Map<String, Object> payload(Map<String, Object> message) {
        Object value = message.get("p");
        return value instanceof Map<?, ?> map ? (Map<String, Object>) map : Map.of();
    }

    @SuppressWarnings("unchecked")
    public static List<Map<String, Object>> ops(Map<String, Object> message) {
        Object value = payload(message).get("ops");
        if (value instanceof List<?> list) {
            return (List<Map<String, Object>>) list.stream()
                    .filter(Map.class::isInstance)
                    .map(item -> (Map<String, Object>) item)
                    .toList();
        }
        return List.of();
    }

    public static Map<String, Object> packetOp(String target, String packetClass, Map<String, Object> fields) {
        Map<String, Object> op = new LinkedHashMap<>(4);
        op.put("o", "packet");
        op.put("to", target);
        op.put("cls", packetClass);
        op.put("f", fields);
        return op;
    }

    public static Map<String, Object> logOp(String level, String message) {
        Map<String, Object> op = new LinkedHashMap<>(3);
        op.put("o", "log");
        op.put("level", level);
        op.put("msg", message);
        return op;
    }
}
