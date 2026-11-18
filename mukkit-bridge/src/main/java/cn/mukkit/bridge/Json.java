package cn.mukkit.bridge;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Minimal JSON codec.
 *
 * <p>The bridge control plane only carries small maps, and the module is deliberately
 * dependency-free so the server jar stays free of third-party runtime libraries. Hot-path
 * payloads are meant to move to a binary codec later; this one exists so the protocol is
 * inspectable with nothing but a log file.</p>
 */
public final class Json {

    private Json() {
    }

    public static Object parse(String text) {
        Parser parser = new Parser(text);
        Object value = parser.readValue();
        parser.skipWhitespace();
        if (!parser.atEnd()) {
            throw new IllegalArgumentException("trailing content at offset " + parser.position());
        }
        return value;
    }

    public static String write(Object value) {
        StringBuilder out = new StringBuilder(128);
        writeValue(out, value);
        return out.toString();
    }

    private static void writeValue(StringBuilder out, Object value) {
        if (value == null) {
            out.append("null");
        } else if (value instanceof String text) {
            writeString(out, text);
        } else if (value instanceof Boolean flag) {
            out.append(flag ? "true" : "false");
        } else if (value instanceof Number number) {
            out.append(formatNumber(number));
        } else if (value instanceof Map<?, ?> map) {
            out.append('{');
            boolean first = true;
            for (Map.Entry<?, ?> entry : map.entrySet()) {
                if (!first) {
                    out.append(',');
                }
                first = false;
                writeString(out, String.valueOf(entry.getKey()));
                out.append(':');
                writeValue(out, entry.getValue());
            }
            out.append('}');
        } else if (value instanceof Iterable<?> items) {
            out.append('[');
            boolean first = true;
            for (Object item : items) {
                if (!first) {
                    out.append(',');
                }
                first = false;
                writeValue(out, item);
            }
            out.append(']');
        } else if (value instanceof Object[] items) {
            out.append('[');
            for (int i = 0; i < items.length; i++) {
                if (i > 0) {
                    out.append(',');
                }
                writeValue(out, items[i]);
            }
            out.append(']');
        } else {
            writeString(out, String.valueOf(value));
        }
    }

    private static String formatNumber(Number number) {
        if (number instanceof Double || number instanceof Float) {
            double value = number.doubleValue();
            if (Double.isNaN(value) || Double.isInfinite(value)) {
                return "null";
            }
            return Double.toString(value);
        }
        return number.toString();
    }

    private static void writeString(StringBuilder out, String text) {
        out.append('"');
        for (int i = 0; i < text.length(); i++) {
            char c = text.charAt(i);
            switch (c) {
                case '"' -> out.append("\\\"");
                case '\\' -> out.append("\\\\");
                case '\n' -> out.append("\\n");
                case '\r' -> out.append("\\r");
                case '\t' -> out.append("\\t");
                case '\b' -> out.append("\\b");
                case '\f' -> out.append("\\f");
                default -> {
                    if (c < 0x20) {
                        out.append(String.format("\\u%04x", (int) c));
                    } else {
                        out.append(c);
                    }
                }
            }
        }
        out.append('"');
    }

    private static final class Parser {
        private final String text;
        private int index;

        Parser(String text) {
            this.text = text;
        }

        boolean atEnd() {
            return index >= text.length();
        }

        int position() {
            return index;
        }

        void skipWhitespace() {
            while (index < text.length()) {
                char c = text.charAt(index);
                if (c == ' ' || c == '\t' || c == '\r' || c == '\n') {
                    index++;
                } else {
                    break;
                }
            }
        }

        Object readValue() {
            skipWhitespace();
            if (atEnd()) {
                throw new IllegalArgumentException("unexpected end of input");
            }
            char c = text.charAt(index);
            return switch (c) {
                case '{' -> readObject();
                case '[' -> readArray();
                case '"' -> readString();
                case 't' -> readLiteral("true", Boolean.TRUE);
                case 'f' -> readLiteral("false", Boolean.FALSE);
                case 'n' -> readLiteral("null", null);
                default -> readNumber();
            };
        }

        private Object readLiteral(String literal, Object value) {
            if (!text.startsWith(literal, index)) {
                throw new IllegalArgumentException("invalid literal at offset " + index);
            }
            index += literal.length();
            return value;
        }

        private Map<String, Object> readObject() {
            Map<String, Object> map = new LinkedHashMap<>();
            index++;
            skipWhitespace();
            if (peek() == '}') {
                index++;
                return map;
            }
            while (true) {
                skipWhitespace();
                if (peek() != '"') {
                    throw new IllegalArgumentException("expected object key at offset " + index);
                }
                String key = readString();
                skipWhitespace();
                expect(':');
                map.put(key, readValue());
                skipWhitespace();
                char c = peek();
                index++;
                if (c == '}') {
                    return map;
                }
                if (c != ',') {
                    throw new IllegalArgumentException("expected ',' or '}' at offset " + (index - 1));
                }
            }
        }

        private List<Object> readArray() {
            List<Object> list = new ArrayList<>();
            index++;
            skipWhitespace();
            if (peek() == ']') {
                index++;
                return list;
            }
            while (true) {
                list.add(readValue());
                skipWhitespace();
                char c = peek();
                index++;
                if (c == ']') {
                    return list;
                }
                if (c != ',') {
                    throw new IllegalArgumentException("expected ',' or ']' at offset " + (index - 1));
                }
            }
        }

        private String readString() {
            expect('"');
            StringBuilder out = new StringBuilder();
            while (true) {
                if (atEnd()) {
                    throw new IllegalArgumentException("unterminated string");
                }
                char c = text.charAt(index++);
                if (c == '"') {
                    return out.toString();
                }
                if (c != '\\') {
                    out.append(c);
                    continue;
                }
                if (atEnd()) {
                    throw new IllegalArgumentException("unterminated escape");
                }
                char escape = text.charAt(index++);
                switch (escape) {
                    case '"' -> out.append('"');
                    case '\\' -> out.append('\\');
                    case '/' -> out.append('/');
                    case 'b' -> out.append('\b');
                    case 'f' -> out.append('\f');
                    case 'n' -> out.append('\n');
                    case 'r' -> out.append('\r');
                    case 't' -> out.append('\t');
                    case 'u' -> {
                        if (index + 4 > text.length()) {
                            throw new IllegalArgumentException("truncated unicode escape");
                        }
                        out.append((char) Integer.parseInt(text.substring(index, index + 4), 16));
                        index += 4;
                    }
                    default -> throw new IllegalArgumentException("unknown escape \\" + escape);
                }
            }
        }

        private Number readNumber() {
            int start = index;
            while (index < text.length()) {
                char c = text.charAt(index);
                if ((c >= '0' && c <= '9') || c == '-' || c == '+' || c == '.' || c == 'e' || c == 'E') {
                    index++;
                } else {
                    break;
                }
            }
            if (start == index) {
                throw new IllegalArgumentException("unexpected character at offset " + start);
            }
            String token = text.substring(start, index);
            if (token.indexOf('.') < 0 && token.indexOf('e') < 0 && token.indexOf('E') < 0) {
                return Long.parseLong(token);
            }
            return Double.parseDouble(token);
        }

        private char peek() {
            if (atEnd()) {
                throw new IllegalArgumentException("unexpected end of input");
            }
            return text.charAt(index);
        }

        private void expect(char expected) {
            if (atEnd() || text.charAt(index) != expected) {
                throw new IllegalArgumentException("expected '" + expected + "' at offset " + index);
            }
            index++;
        }
    }
}
