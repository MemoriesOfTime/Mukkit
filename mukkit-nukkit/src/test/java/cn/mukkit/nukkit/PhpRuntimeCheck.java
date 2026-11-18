package cn.mukkit.nukkit;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.Comparator;
import java.util.Optional;
import java.util.stream.Stream;

/**
 * Offline checks for {@link PhpRuntime}'s layout knowledge.
 *
 * <p>The failure this pins is silent by construction: if {@code binaryIn} does not recognise a
 * platform's archive layout, the bundled runtime is extracted and then reported as missing, and
 * the bridge quietly falls back to whatever {@code php} is on PATH - on a Windows box that is
 * usually nothing at all. The server then starts without a host and the only symptom is that no
 * plugin ever loads.</p>
 *
 * <p>pmmp ships two shapes: {@code bin/php7/bin/php} for macOS and Linux, {@code bin/php/php.exe}
 * for Windows with no {@code php7} level. Both are asserted here against real directory trees,
 * not string constants.</p>
 */
public final class PhpRuntimeCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) throws IOException {
        Path root = Files.createTempDirectory("mukkit-php-layout");
        try {
            check("unix layout: bin/php7/bin/php",
                    finds(root, "unix", "bin/php7/bin/php", "bin/php7/bin/php"));
            check("windows layout: bin/php/php.exe",
                    finds(root, "win", "bin/php/php.exe", "bin/php/php.exe"));
            check("a flat bin/php still counts",
                    finds(root, "flat", "bin/php", "bin/php"));
            check("an empty runtime has no binary", noBinary(root, "empty"));

            // platformId is what chooses the runtime directory and the jar prefix. The mapping for
            // the machine running the tests is the only one that can be checked without lying
            // about os.name, but an unknown OS must not invent an id.
            String here = PhpRuntime.platformId();
            String os = System.getProperty("os.name", "").toLowerCase();
            check("this machine maps to a known platform id",
                    here.matches("macos-(arm64|x86_64)|linux-x86_64|linux-arm64|windows-x64"));
            check("macOS never reports a linux id", !(os.contains("mac") && here.startsWith("linux")));
            check("Windows never reports a mac id", !(os.contains("win") && here.startsWith("macos")));

            // The order matters: bin/php is a directory in the Windows archive, and treating it as
            // a candidate file would hide bin/php/php.exe behind a directory read.
            Path mixed = root.resolve("mixed");
            Files.createDirectories(mixed.resolve("bin/php"));
            Files.writeString(mixed.resolve("bin/php/php.exe"), "");
            check("a bin/php directory does not shadow bin/php/php.exe",
                    PhpRuntime.binaryIn(mixed).map(p -> p.endsWith("php.exe")).orElse(false));

            // The bundled ini carries an absolute zend_extension from the build machine. If the
            // extraction only matches the literal "opcache.so", every user gets a PHP warning on
            // startup and runs without opcache.
            Path iniRoot = root.resolve("ini");
            Path ini = iniRoot.resolve("bin/php7/bin/php.ini");
            Path so = iniRoot.resolve(
                    "bin/php7/lib/php/extensions/no-debug-zts-20240924/opcache.so");
            Files.createDirectories(ini.getParent());
            Files.createDirectories(so.getParent());
            Files.writeString(so, "");
            Files.writeString(ini,
                    "zend_extension=/build/php-builder/opcache.so\nopcache.enable=1\n");
            RuntimeAssets.fixOpcachePath(iniRoot);
            String rewritten = Files.readString(ini);
            check("the bundled ini is re-pointed at the extracted opcache",
                    rewritten.contains("zend_extension=" + so)
                            && !rewritten.contains("/build/php-builder"));
            check("other ini lines survive the rewrite", rewritten.contains("opcache.enable=1"));

            // End to end against the real jar: extract the Windows runtime and look for php.exe.
            // That is the exact shape of the failure this class exists for - the archive unpacks
            // fine, the binary is then reported missing, and the bridge silently uses system PHP.
            Path jar = Path.of("mukkit-nukkit", "build", "libs", "Mukkit.jar");
            if (Files.isRegularFile(jar)) {
                Path data = root.resolve("install-win");
                Optional<String> installed = RuntimeAssets.install(data, jar, "windows-x64");
                check("the jar's Windows runtime extracts and is recognised",
                        installed.map(p -> p.endsWith("php.exe")).orElse(false));
                check("the extraction writes its version marker",
                        Files.isRegularFile(data.resolve("php/windows-x64/.version")));
            } else {
                System.out.println("skip  Mukkit.jar not built; jar extraction not checked");
            }
        } finally {
            deleteRecursively(root);
        }

        System.out.println("php-runtime-jvm " + (failures == 0 ? "PASS" : "FAILED")
                + " (" + checks + " checks)");
        if (failures > 0) {
            System.exit(1);
        }
    }

    /** Creates {@code relative} under a fresh directory and asserts binaryIn points at it. */
    private static boolean finds(Path root, String name, String relative, String expectedTail)
            throws IOException {
        Path dir = root.resolve(name);
        Path file = dir.resolve(relative);
        Files.createDirectories(file.getParent());
        Files.writeString(file, "");
        Optional<String> found = PhpRuntime.binaryIn(dir);
        return found.isPresent() && found.get().endsWith(expectedTail.replace('/', java.io.File.separatorChar));
    }

    private static boolean noBinary(Path root, String name) throws IOException {
        Path dir = root.resolve(name);
        Files.createDirectories(dir.resolve("bin"));
        return PhpRuntime.binaryIn(dir).isEmpty();
    }

    private static void deleteRecursively(Path path) throws IOException {
        if (!Files.exists(path)) {
            return;
        }
        try (Stream<Path> walk = Files.walk(path)) {
            for (Path p : walk.sorted(Comparator.reverseOrder()).toList()) {
                Files.deleteIfExists(p);
            }
        }
    }

    private static void check(String label, boolean ok) {
        checks++;
        if (ok) {
            System.out.println("ok    " + label);
        } else {
            failures++;
            System.out.println("FAIL  " + label);
        }
    }

    private PhpRuntimeCheck() {
    }
}
