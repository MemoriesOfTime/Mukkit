package cn.mukkit.nukkit;

import cn.mukkit.bridge.Json;

import java.io.IOException;
import java.io.InputStream;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.TimeUnit;
import java.util.regex.Pattern;

/**
 * Reads a {@code .phar}'s metadata by asking PHP to do it.
 *
 * <p>A phar is a PHP archive with a PHP-serialised manifest; parsing it in Java would mean
 * reimplementing a format we do not otherwise care about, and every parser bug would show up
 * as "this plugin silently does not load". {@code pm-inspect.php} already has the language
 * that reads it, so we pay one short-lived process per plugin per boot instead.</p>
 */
final class PharMeta {

    /**
     * PM's own rule for plugin names ({@code PluginDescription::__construct}). It has to hold here
     * too: the name is also the folder under {@code plugins/}, so a name with a separator in it
     * would move a plugin's data outside the plugin directory.
     */
    private static final Pattern SAFE_NAME = Pattern.compile("[A-Za-z0-9 _.-]+");

    /** What the bridge needs to know about a plugin before it can be loaded. */
    record Meta(String name, String main, String version, List<String> api, String description) {

        Map<String, Object> toDescriptionMap() {
            Map<String, Object> map = new LinkedHashMap<>(6);
            map.put("name", name);
            map.put("main", main);
            map.put("version", version);
            map.put("api", api);
            map.put("description", description);
            return map;
        }
    }

    private final String phpBinary;
    private final Path inspector;
    private final Map<String, Meta> cache = new LinkedHashMap<>();

    PharMeta(String phpBinary, Path inspector) {
        this.phpBinary = phpBinary;
        this.inspector = inspector;
    }

    /**
     * @return the plugin metadata, or {@code null} when the file cannot be read as a plugin
     */
    Meta describe(Path phar) {
        String key = phar.toAbsolutePath() + "@" + lastModified(phar);
        Meta cached = cache.get(key);
        if (cached != null) {
            return cached;
        }
        Meta meta = inspect(phar);
        if (meta != null) {
            cache.put(key, meta);
        }
        return meta;
    }

    private Meta inspect(Path phar) {
        if (!Files.isRegularFile(phar)) {
            return null;
        }
        try {
            Process process = new ProcessBuilder(phpBinary, inspector.toString(), phar.toAbsolutePath().toString())
                    .redirectErrorStream(false)
                    .start();
            String stdout = readAll(process.getInputStream());
            String stderr = readAll(process.getErrorStream());
            if (!process.waitFor(10, TimeUnit.SECONDS)) {
                process.destroyForcibly();
                System.out.println("[mukkit] pm-inspect timed out on " + phar.getFileName());
                return null;
            }
            if (process.exitValue() != 0) {
                System.out.println("[mukkit] pm-inspect rejected " + phar.getFileName() + ": " + stderr.strip());
                return null;
            }
            Object parsed = Json.parse(stdout.strip());
            if (!(parsed instanceof Map<?, ?> raw)) {
                System.out.println("[mukkit] pm-inspect returned no object for " + phar.getFileName());
                return null;
            }
            String name = string(raw.get("name"));
            String main = string(raw.get("main"));
            if (name.isEmpty() || main.isEmpty()) {
                System.out.println("[mukkit] " + phar.getFileName() + " has no plugin.yml name/main");
                return null;
            }
            if (!SAFE_NAME.matcher(name).matches() || name.equals(".") || name.equals("..")) {
                System.out.println("[mukkit] " + phar.getFileName() + " has an unusable plugin name '" + name + "'");
                return null;
            }
            return new Meta(name, main, string(raw.get("version")), stringList(raw.get("api")),
                    string(raw.get("description")));
        } catch (IOException e) {
            System.out.println("[mukkit] could not inspect " + phar.getFileName() + ": " + e.getMessage());
            return null;
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            return null;
        }
    }

    private static String readAll(InputStream stream) throws IOException {
        try (stream) {
            return new String(stream.readAllBytes(), StandardCharsets.UTF_8);
        }
    }

    private static long lastModified(Path path) {
        try {
            return Files.getLastModifiedTime(path).toMillis();
        } catch (IOException e) {
            return 0L;
        }
    }

    private static String string(Object value) {
        return value instanceof String text ? text.strip() : "";
    }

    @SuppressWarnings("unchecked")
    private static List<String> stringList(Object value) {
        if (value instanceof List<?> list) {
            List<String> out = new ArrayList<>(list.size());
            for (Object item : list) {
                if (item != null) {
                    out.add(String.valueOf(item));
                }
            }
            return out;
        }
        String single = string(value);
        return single.isEmpty() ? List.of() : List.of(single);
    }
}
