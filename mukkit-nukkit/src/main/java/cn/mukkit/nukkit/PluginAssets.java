package cn.mukkit.nukkit;

import java.io.IOException;
import java.io.InputStream;
import java.nio.file.Files;
import java.nio.file.Path;
import java.security.MessageDigest;
import java.security.NoSuchAlgorithmException;
import java.util.Comparator;
import java.util.Enumeration;
import java.util.jar.JarEntry;
import java.util.jar.JarFile;

/**
 * Unpacks the PHP trees that ship inside the plugin jar.
 *
 * <p>The host scripts and the PocketMine fork are resources, not loose files, because the install
 * story is "drop one jar into plugins/". A user should never have to know that there is a PHP
 * tree next to the jar, or keep it in sync with the jar's version — that is exactly how a bridge
 * ends up running Java and PHP from two different releases. Shipping the fork as source (rather
 * than a build artefact) also keeps the LGPL side trivially replaceable.</p>
 *
 * <p>Extraction is stamped with a hash of the jar's contents: the first enable of a build unpacks,
 * every later start skips it. Re-walking ~3100 entries on every boot would add a second to server
 * startup for no benefit. Hashing rather than using the version string matters during development
 * — a rebuilt jar at the same version must not keep running last build's PHP.</p>
 */
final class PluginAssets {

    private PluginAssets() {
    }

    /**
     * Stamp name for this jar. Content-based on purpose: version numbers are bumped rarely in
     * development, and a stale PHP tree is a bug that looks like "my change did nothing".
     */
    static String stampFor(Path jar) {
        if (!Files.isRegularFile(jar)) {
            return ".unpacked-dev";
        }
        try {
            MessageDigest digest = MessageDigest.getInstance("SHA-256");
            try (InputStream in = Files.newInputStream(jar)) {
                byte[] buffer = new byte[1 << 16];
                int n;
                while ((n = in.read(buffer)) > 0) {
                    digest.update(buffer, 0, n);
                }
            }
            StringBuilder hex = new StringBuilder(64);
            for (byte b : digest.digest()) {
                hex.append(String.format("%02x", b));
            }
            return ".unpacked-" + hex;
        } catch (IOException | NoSuchAlgorithmException e) {
            // Hashing is an optimisation guard, not a requirement: fall back to the version-free
            // stamp and let extraction run.
            return ".unpacked-unknown";
        }
    }

    /**
     * @param jar    the plugin jar (as reported by {@code PluginBase.getFile()})
     * @param target directory to unpack into; wiped first if the stamp does not match
     * @param stamp  file name inside {@code target} marking the version already unpacked
     * @param prefix resource directory inside the jar, e.g. {@code pm-host}
     * @return the directory the caller should run PHP from
     */
    static Path extract(Path jar, Path target, String stamp, String prefix) throws IOException {
        Path marker = target.resolve(stamp);
        if (Files.isRegularFile(marker)) {
            return target;
        }
        if (!Files.isRegularFile(jar)) {
            // Running from build/classes during development: the resources are already on disk.
            return target;
        }

        if (Files.isDirectory(target)) {
            deleteRecursively(target);
        }
        Files.createDirectories(target);

        String root = prefix.endsWith("/") ? prefix : prefix + "/";
        try (JarFile jarFile = new JarFile(jar.toFile())) {
            Enumeration<JarEntry> entries = jarFile.entries();
            while (entries.hasMoreElements()) {
                JarEntry entry = entries.nextElement();
                String name = entry.getName();
                if (entry.isDirectory() || !name.startsWith(root)) {
                    continue;
                }
                Path out = target.resolve(name.substring(root.length()));
                Files.createDirectories(out.getParent());
                try (InputStream in = jarFile.getInputStream(entry)) {
                    Files.write(out, in.readAllBytes());
                }
            }
        }

        Files.writeString(marker, "unpacked from " + jar.getFileName() + "\n");
        return target;
    }

    private static void deleteRecursively(Path root) throws IOException {
        try (var paths = Files.walk(root)) {
            for (Path path : paths.sorted(Comparator.reverseOrder()).toList()) {
                Files.deleteIfExists(path);
            }
        }
    }
}
