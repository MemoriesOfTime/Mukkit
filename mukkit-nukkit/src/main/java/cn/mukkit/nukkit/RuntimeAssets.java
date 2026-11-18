package cn.mukkit.nukkit;

import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.nio.file.attribute.PosixFilePermission;
import java.util.Enumeration;
import java.util.EnumSet;
import java.util.List;
import java.util.Optional;
import java.util.jar.JarEntry;
import java.util.jar.JarFile;
import java.util.stream.Stream;

/**
 * The PHP runtime that ships inside the jar.
 *
 * <p>Plan B: one artifact that works on every supported platform. The jar carries a runtime per
 * OS+architecture under {@code runtime/<platform>/}; on enable the plugin installs the one that
 * matches into {@code plugins/Mukkit/php/<platform>/} and uses it. Nothing is downloaded, and a
 * server with no system PHP works out of the box.</p>
 *
 * <p>The installed copy is stamped with the runtime's own version, not the jar hash: rebuilding
 * the jar must not re-extract 34 MB on every restart. {@code scripts/fetch-php.sh} writes that
 * version file next to the runtime.</p>
 */
final class RuntimeAssets {

    private static final String RESOURCE_ROOT = "runtime/";

    private RuntimeAssets() {
    }

    /**
     * Installs the runtime bundled in the jar, if this jar has one for the platform.
     *
     * <p>The version marker is read from the jar entry, not through the classloader: Nukkit gives
     * plugins a classloader that does not have to expose resources, and a missing resource would
     * silently look like "this build has no runtime". The jar file is the source of truth for both
     * the marker and the extraction anyway.</p>
     *
     * @return empty when the jar has no runtime for this platform (thin build) or we are running
     *         from {@code build/classes}
     */
    static Optional<String> install(Path dataFolder, Path jar, String platform) throws IOException {
        if (!Files.isRegularFile(jar)) {
            return Optional.empty();
        }
        Path target = dataFolder.resolve("php").resolve(platform);
        String prefix = RESOURCE_ROOT + platform + "/";

        try (JarFile jarFile = new JarFile(jar.toFile())) {
            JarEntry versionEntry = jarFile.getJarEntry(prefix + ".version");
            if (versionEntry == null) {
                return Optional.empty();
            }
            String version;
            try (InputStream in = jarFile.getInputStream(versionEntry)) {
                version = new String(in.readAllBytes(), java.nio.charset.StandardCharsets.UTF_8).trim();
            }
            Path marker = target.resolve(".version");
            Optional<String> existing = PhpRuntime.binaryIn(target);
            if (existing.isPresent() && Files.isRegularFile(marker)
                    && Files.readString(marker).trim().equals(version)) {
                // Older jars shipped an absolute builder path in php.ini. Repair it in place:
                // re-extracting 34 MB on every upgrade just to fix one line is not worth it.
                fixOpcachePath(target);
                return existing;
            }

            deleteRecursively(target);
            Files.createDirectories(target);
            java.util.Enumeration<JarEntry> entries = jarFile.entries();
            while (entries.hasMoreElements()) {
                JarEntry entry = entries.nextElement();
                String name = entry.getName();
                if (entry.isDirectory() || !name.startsWith(prefix)) {
                    continue;
                }
                Path out = target.resolve(name.substring(prefix.length()));
                if (out.getParent() != null) {
                    Files.createDirectories(out.getParent());
                }
                try (InputStream in = jarFile.getInputStream(entry)) {
                    Files.write(out, in.readAllBytes());
                }
            }
            makeExecutables(target);
            fixOpcachePath(target);
            Files.writeString(marker, version);
            return PhpRuntime.binaryIn(target);
        }
    }

    private static void makeExecutables(Path target) {
        List<String> names = List.of("php", "php.exe", "phpize", "php-config", "php-cgi", "phar", "phar.phar");
        try (Stream<Path> walk = Files.walk(target)) {
            walk.filter(Files::isRegularFile)
                    .filter(p -> names.contains(p.getFileName().toString()))
                    .forEach(p -> {
                        try {
                            Files.setPosixFilePermissions(p, EnumSet.of(
                                    PosixFilePermission.OWNER_READ, PosixFilePermission.OWNER_WRITE,
                                    PosixFilePermission.OWNER_EXECUTE,
                                    PosixFilePermission.GROUP_READ, PosixFilePermission.GROUP_EXECUTE,
                                    PosixFilePermission.OTHERS_READ, PosixFilePermission.OTHERS_EXECUTE));
                        } catch (UnsupportedOperationException | IOException ignored) {
                            // Windows has no executable bit; the .exe extension is enough.
                        }
                    });
        } catch (IOException ignored) {
            // Best effort: if the walk fails, the binary check later reports it.
        }
    }

    /**
     * The bundled ini points opcache at the builder's absolute path, which exists on no user
     * machine. Point it at the copy we just extracted, or comment it out when the platform ships
     * no opcache.so.
     *
     * <p>The builder path is absolute ({@code zend_extension=/build/php/opcache.so}), not the
     * bare {@code opcache.so} the upstream archives used to carry. Anchoring the replacement on
     * the literal {@code opcache.so} alone therefore missed every real bundle and left PHP
     * printing {@code Failed loading .../opcache.so} on each start.</p>
     */
    static void fixOpcachePath(Path target) {
        // Windows ships bin/php/php.ini without the php7 level; the Unix builds use bin/php7/bin.
        Path ini = null;
        for (String candidate : new String[]{"bin/php7/bin/php.ini", "bin/php/php.ini"}) {
            Path p = target.resolve(candidate);
            if (Files.isRegularFile(p)) {
                ini = p;
                break;
            }
        }
        if (ini == null) {
            return;
        }
        String so = null;
        for (String candidate : new String[]{"bin/php7/lib/php/extensions", "bin/php/ext"}) {
            Path ext = target.resolve(candidate);
            if (!Files.isDirectory(ext)) {
                continue;
            }
            try (Stream<Path> stream = Files.walk(ext, 2)) {
                so = stream.filter(p -> p.getFileName().toString().equals("opcache.so"))
                        .findFirst().map(Path::toString).orElse(null);
            } catch (IOException ignored) {
                // Fall through to commenting the line out.
            }
            if (so != null) {
                break;
            }
        }
        try {
            String text = Files.readString(ini);
            String replacement = so == null
                    ? ";zend_extension=opcache.so (not shipped by this runtime)"
                    : "zend_extension=" + so;
            text = text.replaceAll("(?m)^\\s*zend_extension\\s*=\\s*.*?opcache\\.so\\s*$",
                    java.util.regex.Matcher.quoteReplacement(replacement));
            Files.writeString(ini, text);
        } catch (IOException ignored) {
            // A writable ini is nice to have; the warning it prints is not fatal.
        }
    }

    private static void deleteRecursively(Path path) throws IOException {
        if (!Files.exists(path)) {
            return;
        }
        try (Stream<Path> walk = Files.walk(path)) {
            for (Path p : walk.sorted(java.util.Comparator.reverseOrder()).toList()) {
                Files.deleteIfExists(p);
            }
        }
    }
}
