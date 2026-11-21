package cn.mukkit.nukkit;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.List;
import java.util.Locale;

/**
 * Pins the failure cleanup in {@link HostProcess#boot()}.
 *
 * <p>A host that starts but never answers the handshake is the ordinary way a cold start fails:
 * the {@code pm} profile spends its budget on PocketMine's own startup, and a slow disk or a large
 * phar set can push that past the 15s the bridge waits for the ready frame. The exception is thrown
 * either way, so nothing looks wrong from the outside - what used to be wrong is that the child
 * process was never touched. The HostProcess instance becomes unreachable at that point and the
 * socket file is already gone (the next boot deletes it), so the PHP host keeps running with no
 * handle left to stop it: one orphaned process per failed boot, each holding its own copy of the
 * plugin data.</p>
 *
 * <p>The fake host here is a Java source file rather than PHP: this check must run on a machine
 * without a PHP interpreter, and the failure being pinned lives entirely on the JVM side.</p>
 */
public final class HostProcessCheck {

    private static final String FAKE_HOST = """
            import java.nio.file.Files;
            import java.nio.file.Path;

            /** Stands in for a PHP host that starts but never speaks the protocol. */
            public final class FakeHost {
                public static void main(String[] args) throws Exception {
                    String socket = "";
                    for (String arg : args) {
                        if (arg.startsWith("--socket=")) {
                            socket = arg.substring("--socket=".length());
                        }
                    }
                    if (!socket.isEmpty()) {
                        Files.writeString(Path.of(socket + ".pid"), String.valueOf(ProcessHandle.current().pid()));
                    }
                    Thread.sleep(120_000L);
                }
            }
            """;

    private static int checks;
    private static int failures;

    public static void main(String[] args) throws IOException, InterruptedException {
        Path root = Files.createTempDirectory("mukkit-boot-failure");
        try {
            check("a host that never answers the handshake leaves no child behind",
                    startLeavesNoChild(root));
        } finally {
            deleteRecursively(root);
        }
        System.out.println();
        System.out.println(checks + " checks, " + failures + " failure(s)");
        System.exit(failures == 0 ? 0 : 1);
    }

    private static boolean startLeavesNoChild(Path root) throws IOException, InterruptedException {
        Path socket = root.resolve("host.sock");
        Path pidFile = Path.of(socket + ".pid");
        Path source = root.resolve("FakeHost.java");
        Files.writeString(source, FAKE_HOST);

        HostProcess.Config config = new HostProcess.Config(
                javaBinary(), source.toString(), socket, root.resolve("host.log"),
                20, 15L, "stub", root, root, root, "", "plugin-folder", false);

        boolean refused = false;
        try {
            HostProcess.start(config, List.of());
        } catch (IOException expected) {
            // The bridge is supposed to refuse a host that never says hello; what matters is
            // what is still running afterwards.
            refused = true;
        }
        if (!refused) {
            System.out.println("      (start() accepted a host that never answered)");
            return false;
        }

        long pid = awaitPid(pidFile, 5_000L);
        if (pid < 0L) {
            System.out.println("      (the fake host never wrote its pid, so cleanup cannot be judged)");
            return false;
        }
        if (awaitExit(pid, 10_000L)) {
            return true;
        }
        ProcessHandle.of(pid).ifPresent(ProcessHandle::destroyForcibly);
        return false;
    }

    /** The child writes the file before it sleeps, so a miss here means it never started. */
    private static long awaitPid(Path pidFile, long timeoutMillis) throws InterruptedException {
        long deadline = System.nanoTime() + timeoutMillis * 1_000_000L;
        while (System.nanoTime() < deadline) {
            if (Files.isRegularFile(pidFile)) {
                try {
                    String text = Files.readString(pidFile).trim();
                    if (!text.isEmpty()) {
                        return Long.parseLong(text);
                    }
                } catch (IOException | NumberFormatException ignored) {
                    // still being written
                }
            }
            Thread.sleep(50L);
        }
        return -1L;
    }

    private static boolean awaitExit(long pid, long timeoutMillis) throws InterruptedException {
        long deadline = System.nanoTime() + timeoutMillis * 1_000_000L;
        while (System.nanoTime() < deadline) {
            if (ProcessHandle.of(pid).map(handle -> !handle.isAlive()).orElse(true)) {
                return true;
            }
            Thread.sleep(50L);
        }
        return false;
    }

    private static String javaBinary() {
        boolean windows = System.getProperty("os.name", "").toLowerCase(Locale.ROOT).contains("win");
        return Path.of(System.getProperty("java.home"), "bin", windows ? "java.exe" : "java").toString();
    }

    private static void deleteRecursively(Path path) throws IOException {
        if (!Files.exists(path)) {
            return;
        }
        try (var walk = Files.walk(path)) {
            for (Path p : walk.sorted(java.util.Comparator.reverseOrder()).toList()) {
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

    private HostProcessCheck() {
    }
}
