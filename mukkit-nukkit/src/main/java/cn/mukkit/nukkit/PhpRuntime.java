package cn.mukkit.nukkit;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Locale;
import java.util.Optional;

/**
 * Which PHP interpreter the host runs on.
 *
 * <p>Resolution order, most specific first:</p>
 * <ol>
 *   <li>{@code MUKKIT_PHP_BINARY} - tests and scripts/serve.sh point this at a runtime they chose;</li>
 *   <li>an already installed runtime under {@code plugins/Mukkit/php/<platform>/};</li>
 *   <li>the runtime bundled in the jar, extracted on first use (plan B: one artifact, every OS);</li>
 *   <li>{@code config.yml php-binary}, then {@code PATH}.</li>
 * </ol>
 */
final class PhpRuntime {

    private PhpRuntime() {
    }

    /** OS + architecture as the bundled runtimes and pmmp's assets name it. */
    static String platformId() {
        String os = System.getProperty("os.name", "").toLowerCase(Locale.ROOT);
        String arch = System.getProperty("os.arch", "").toLowerCase(Locale.ROOT);
        String system = os.contains("mac") ? "macos"
                : os.contains("win") ? "windows"
                : os.contains("linux") ? "linux" : "";
        if (system.isEmpty()) {
            return "";
        }
        if (system.equals("windows")) {
            // pmmp ships one Windows asset, x64 only.
            return "windows-x64";
        }
        String cpu = (arch.equals("aarch64") || arch.equals("arm64")) ? "arm64"
                : (arch.equals("x86_64") || arch.equals("amd64")) ? "x86_64" : arch;
        return system + "-" + cpu;
    }

    /**
     * The php binary inside a runtime directory, wherever the archive happens to put it.
     *
     * <p>The Unix packages lay out {@code bin/php7/bin/php}; the Windows one uses
     * {@code bin/php/php.exe} with no {@code php7} level. Both are pmmp's, so both are checked -
     * listing only one shape is how a platform silently loses its runtime.</p>
     */
    static Optional<String> binaryIn(Path dir) {
        for (String candidate : new String[]{
                "bin/php7/bin/php", "bin/php7/php", "bin/php",
                "bin/php/php.exe", "bin/php/php",
                "bin/php7/bin/php.exe", "bin/php7/php.exe", "bin/php.exe"
        }) {
            Path binary = dir.resolve(candidate);
            if (Files.isRegularFile(binary)) {
                return Optional.of(binary.toAbsolutePath().toString());
            }
        }
        return Optional.empty();
    }

    static String resolve(Path dataFolder, Path jar, String configured) throws IOException {
        String env = System.getenv("MUKKIT_PHP_BINARY");
        if (env != null && !env.isBlank()) {
            return env.trim();
        }
        String platform = platformId();
        if (platform.isEmpty()) {
            return configured;
        }
        // Always let install() validate the version marker. Short-circuiting on "a binary exists"
        // would keep an installation extracted by an older jar forever - including the broken
        // absolute opcache path that fixOpcachePath() is there to repair. install() returns the
        // existing tree without touching it when the marker matches.
        Optional<String> bundled = RuntimeAssets.install(dataFolder, jar, platform);
        if (bundled.isPresent()) {
            return bundled.get();
        }
        Optional<String> installed = binaryIn(dataFolder.resolve("php").resolve(platform));
        return installed.orElse(configured);
    }
}
