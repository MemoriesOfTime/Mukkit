package cn.mukkit.nukkit;

import cn.mukkit.bridge.HostChannel;
import cn.mukkit.bridge.Protocol;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/**
 * Owns the PHP process: spawn, handshake, and the restart that a hung host earns.
 *
 * <p>The process is a child of the server, so a crash takes the plugin host down and nothing
 * else. Restarting is deliberately dumb — kill, respawn, replay the plugin list — because the
 * host is stateless by contract: plugin state is rebuilt from {@code plugin.yml} and the data
 * folder, and world state is re-pushed from Nukkit.</p>
 */
final class HostProcess implements AutoCloseable {

    record PluginEntry(String name, String path) {
    }

    /**
     * @param phpBinary  interpreter to run
     * @param hostScript {@code mukkit-host.php}
     * @param socket     unix socket to listen on; must stay under the platform's path limit
     * @param logFile    where the host's stdout/stderr goes
     * @param profile    which PHP profile to boot: {@code pm} runs the bundled fork, {@code plugin}
     *                   runs the HostedPlugin contract
     * @param pmRoot     unpacked fork root (contains {@code src/} and {@code vendor/}); only used by
     *                   the {@code pm} profile
     * @param pmData     writable data dir for PocketMine (worlds, config, and — when the layout is
     *                   {@code isolated} — plugin data)
     * @param pmPlugins  directory PocketMine scans for phars — the server's own plugins dir, so the
     *                   two plugin managers agree on what is installed
     * @param defaultWorld Nukkit's default level name, or empty. The host builds this world before
     *                   PocketMine enables plugins so `getDefaultWorld()` is not null in onEnable.
     * @param pmDataLayout where each phar's own data folder goes; see {@link BridgeSettings}
     * @param tcp        listen on loopback TCP instead of a Unix socket. Windows needs this: its
     *                   PHP build has no {@code unix} stream transport, so a Unix-only listener
     *                   never starts there.
     */
    record Config(String phpBinary, String hostScript, Path socket, Path logFile,
                  int tickRate, long softDeadlineMillis, String profile,
                  Path pmRoot, Path pmData, Path pmPlugins, String defaultWorld,
                  String pmDataLayout, boolean tcp) {
    }

    private final Config config;
    private final List<PluginEntry> plugins;
    private Process process;
    private HostChannel channel;
    private Map<String, Object> ready = Map.of();
    private int port;

    private HostProcess(Config config, List<PluginEntry> plugins) {
        this.config = config;
        this.plugins = plugins;
    }

    static HostProcess start(Config config, List<PluginEntry> plugins) throws IOException {
        HostProcess host = new HostProcess(config, plugins);
        host.boot();
        return host;
    }

    HostChannel channel() {
        return channel;
    }

    Map<String, Object> ready() {
        return ready;
    }

    /** One line for {@code /mukkit status} — the supervisor's view of the child process. */
    String describe() {
        if (process == null || !process.isAlive()) {
            return "not running";
        }
        return "pid " + process.pid() + ", " + plugins.size() + " plugin(s), " + endpoint();
    }

    /** Where the host was asked to listen — the one detail that makes a failed boot diagnosable. */
    private String endpoint() {
        return config.tcp() ? "tcp 127.0.0.1:" + port : "socket " + config.socket();
    }

    /**
     * Picks a free loopback port for this boot.
     *
     * <p>Bind-then-close leaves a gap in which another process could take the port. The gap is
     * local and milliseconds wide, and losing the race shows up as "the host did not answer",
     * which is already a failed boot — so a retry loop here would buy nothing but complexity.</p>
     */
    private static int freeLoopbackPort() throws IOException {
        try (java.net.ServerSocket probe = new java.net.ServerSocket(
                0, 1, java.net.InetAddress.getLoopbackAddress())) {
            return probe.getLocalPort();
        }
    }

    /** Kills the current process and brings up a new one with the same plugin list. */
    void restart() throws IOException {
        shutdownProcess();
        boot();
        System.out.println("[mukkit] plugin host restarted, replays " + plugins.size() + " plugin(s)");
    }

    private void boot() throws IOException {
        Files.createDirectories(config.logFile().getParent());
        if (config.tcp()) {
            port = freeLoopbackPort();
        } else {
            Files.deleteIfExists(config.socket());
        }

        List<String> command = new ArrayList<>(4);
        command.add(config.phpBinary());
        command.add(config.hostScript());
        command.add("--socket=" + (config.tcp()
                ? "tcp://127.0.0.1:" + port
                : config.socket().toString()));
        command.add("--profile=" + config.profile());

        ProcessBuilder builder = new ProcessBuilder(command)
                .redirectErrorStream(true)
                .redirectOutput(ProcessBuilder.Redirect.appendTo(config.logFile().toFile()));
        // Passed through the environment rather than argv: the host re-reads these when it decides
        // where the fork lives, and argv entries here would be another thing to keep in sync with
        // PmProfile's own defaults.
        builder.environment().put("MUKKIT_PM_ROOT", config.pmRoot().toString());
        builder.environment().put("MUKKIT_PM_DATA", config.pmData().toString());
        builder.environment().put("MUKKIT_PM_PLUGINS", config.pmPlugins().toString());
        builder.environment().put("MUKKIT_PM_DATA_LAYOUT", config.pmDataLayout());
        process = builder.start();

        // Every failure past this line leaves a live child behind. Without the guard the
        // HostProcess instance becomes unreachable while the host keeps running, and the socket
        // file is already gone (the next boot deletes it), so nothing can ever shut that process
        // down again: a cold start that misses the handshake budget strands a PocketMine process
        // per attempt. The caller sees the IOException either way - what changes is that we no
        // longer walk away from the process we just started.
        try {
            channel = config.tcp()
                    ? HostChannel.connectTcp("127.0.0.1", port, 15_000L)
                    : HostChannel.connectUnix(config.socket(), 15_000L);
            Map<String, Object> hello = channel.receive(5_000L);
            if (hello == null || !Protocol.HELLO.equals(Protocol.type(hello))) {
                throw new IOException("plugin host did not send a hello frame");
            }
            Map<String, Object> greeting = Protocol.payload(hello);
            int peer = greeting.get("proto") instanceof Number n ? n.intValue() : -1;
            if (peer != Protocol.VERSION) {
                throw new IOException("protocol mismatch: host speaks " + peer + ", bridge speaks " + Protocol.VERSION);
            }
            System.out.println("[mukkit] plugin host up: pid=" + process.pid()
                    + " php=" + greeting.get("php") + " profile=" + greeting.get("profile"));

            Map<String, Object> welcome = new java.util.LinkedHashMap<>(5);
            welcome.put("proto", Protocol.VERSION);
            welcome.put("tickRate", config.tickRate());
            welcome.put("softDeadlineMs", config.softDeadlineMillis());
            welcome.put("codec", Protocol.CODEC_JSON);
            welcome.put("defaultWorld", config.defaultWorld());
            channel.send(Protocol.envelope(Protocol.WELCOME, 1L, welcome));
            System.out.println("[mukkit] world: default="
                    + (config.defaultWorld().isEmpty() ? "none" : config.defaultWorld()));

            Map<String, Object> list = new java.util.LinkedHashMap<>(1);
            List<Map<String, Object>> entries = new ArrayList<>(plugins.size());
            for (PluginEntry plugin : plugins) {
                Map<String, Object> entry = new java.util.LinkedHashMap<>(2);
                entry.put("name", plugin.name());
                entry.put("path", plugin.path());
                entries.add(entry);
            }
            list.put("list", entries);
            channel.send(Protocol.envelope(Protocol.PLUGINS, 2L, list));

            Map<String, Object> answer = channel.receive(15_000L);
            if (answer == null || !Protocol.READY.equals(Protocol.type(answer))) {
                throw new IOException("plugin host did not answer the plugin list");
            }
            ready = Protocol.payload(answer);
        } catch (IOException | RuntimeException e) {
            shutdownProcess();
            throw e;
        }
    }

    private void shutdownProcess() {
        if (channel != null) {
            try {
                channel.close();
            } catch (IOException ignored) {
                // the process is killed below regardless
            }
            channel = null;
        }
        if (process != null && process.isAlive()) {
            process.destroy();
            try {
                if (!process.waitFor(2, java.util.concurrent.TimeUnit.SECONDS)) {
                    process.destroyForcibly();
                }
            } catch (InterruptedException e) {
                Thread.currentThread().interrupt();
                process.destroyForcibly();
            }
            process = null;
        }
        if (!config.tcp()) {
            try {
                Files.deleteIfExists(config.socket());
            } catch (IOException ignored) {
                // a stale socket file is overwritten on the next boot anyway
            }
        }
    }

    @Override
    public void close() {
        if (channel != null) {
            try {
                channel.send(Protocol.envelope(Protocol.SHUTDOWN, 9_999L, Map.of()));
                channel.receive(2_000L);
            } catch (IOException ignored) {
                // the host is going away either way
            }
        }
        shutdownProcess();
    }
}
