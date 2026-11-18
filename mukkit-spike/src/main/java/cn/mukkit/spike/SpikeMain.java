package cn.mukkit.spike;

import cn.mukkit.bridge.Gateway;
import cn.mukkit.bridge.HostChannel;
import cn.mukkit.bridge.HostHungException;
import cn.mukkit.bridge.Protocol;
import cn.mukkit.bridge.TickDriver;
import cn.mukkit.bridge.TickStats;

import java.io.EOFException;
import java.io.IOException;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.TimeUnit;

/**
 * Death-criterion harness.
 *
 * <p>Everything here is scaffolding for one question: can the JVM drive a PHP plugin host in
 * lockstep for thousands of ticks without stalls, leaks or silent drift? A synthetic gateway
 * stands in for the game server so the answer does not depend on the Nukkit adapter existing.</p>
 */
public final class SpikeMain {

    public static void main(String[] args) {
        Options options;
        try {
            options = Options.parse(args);
        } catch (IllegalArgumentException e) {
            System.err.println(e.getMessage());
            System.err.println(Options.USAGE);
            System.exit(64);
            return;
        }
        System.exit(new SpikeMain(options).run());
    }

    private final Options options;

    private SpikeMain(Options options) {
        this.options = options;
    }

    private int run() {
        Path runDir = Path.of("run");
        Path logFile = runDir.resolve("pm-host.log");
        Path socket = Path.of(System.getProperty("java.io.tmpdir"), "mukkit-" + ProcessHandle.current().pid() + ".sock");

        Process host = null;
        HostChannel channel = null;
        try {
            Files.createDirectories(runDir);
            Files.deleteIfExists(socket);
            Files.deleteIfExists(logFile);

            host = spawn(socket, logFile);
            System.out.println("spawned plugin host: pid " + host.pid() + " (" + options.profile + ")");

            channel = HostChannel.connectUnix(socket, 10_000L);
            Map<String, Object> hello = channel.receive(5_000L);
            if (hello == null) {
                return fail(1, "plugin host did not send a handshake", logFile);
            }
            if (!Protocol.HELLO.equals(Protocol.type(hello))) {
                return fail(1, "unexpected handshake frame: " + Protocol.type(hello), logFile);
            }
            Map<String, Object> greeting = Protocol.payload(hello);
            System.out.println("host php " + greeting.get("php")
                    + " / profile " + greeting.get("profile")
                    + " / protocol " + greeting.get("proto"));

            handshake(channel);

            SyntheticGateway gateway = new SyntheticGateway();
            TickDriver driver = new TickDriver(channel, gateway,
                    new TickDriver.Config(options.rate, options.softDeadlineMs, options.hardDeadlineMs));

            System.out.printf("driving %d ticks at %s%n",
                    options.ticks, options.rate > 0 ? options.rate + " TPS" : "max speed");

            TickStats stats;
            try {
                stats = driver.run(options.ticks);
            } catch (HostHungException e) {
                return fail(2, "host hung at tick " + e.sequence()
                        + " - the supervisor would kill and respawn the process here", logFile);
            } catch (EOFException e) {
                return fail(3, "host process died mid-run (" + e.getMessage()
                        + ") - crash isolation held, the JVM survived", logFile);
            }

            System.out.println();
            System.out.print(stats.report());
            System.out.println();
            System.out.print(gateway.summary());

            shutdown(channel);
            return 0;
        } catch (Exception e) {
            e.printStackTrace();
            return fail(1, "spike failed: " + e, logFile);
        } finally {
            closeQuietly(channel);
            stop(host);
            try {
                Files.deleteIfExists(socket);
            } catch (IOException ignored) {
                // socket file is cleaned up on the next run anyway
            }
        }
    }

    private void handshake(HostChannel channel) throws IOException {
        Map<String, Object> welcome = new LinkedHashMap<>();
        welcome.put("proto", Protocol.VERSION);
        welcome.put("tickRate", options.rate);
        welcome.put("softDeadlineMs", options.softDeadlineMs);
        welcome.put("codec", Protocol.CODEC_JSON);
        channel.send(Protocol.envelope(Protocol.WELCOME, 1L, welcome));

        Map<String, Object> plugin = new LinkedHashMap<>();
        plugin.put("name", "EconomyAPI");
        plugin.put("path", "plugins/EconomyAPI.phar");
        Map<String, Object> legacy = new LinkedHashMap<>();
        legacy.put("name", "LegacySkyBlock");
        legacy.put("path", "plugins/LegacySkyBlock.phar");
        Map<String, Object> plugins = new LinkedHashMap<>();
        plugins.put("list", List.of(plugin, legacy));
        channel.send(Protocol.envelope(Protocol.PLUGINS, 2L, plugins));

        Map<String, Object> ready = channel.receive(5_000L);
        if (ready == null || !Protocol.READY.equals(Protocol.type(ready))) {
            throw new IOException("host did not answer the plugin list");
        }
        Map<String, Object> payload = Protocol.payload(ready);
        System.out.println("loaded  " + payload.get("loaded"));
        System.out.println("rejected " + payload.get("rejected"));
    }

    private void shutdown(HostChannel channel) {
        try {
            channel.send(Protocol.envelope(Protocol.SHUTDOWN, 9_999L, new LinkedHashMap<>()));
        } catch (IOException ignored) {
            // the process is killed in the finally block regardless
        }
    }

    private Process spawn(Path socket, Path logFile) throws IOException {
        List<String> command = new ArrayList<>();
        command.add("php");
        command.add(options.hostScript);
        command.add("--socket=" + socket);
        command.add("--profile=" + options.profile);
        if (options.fault != null) {
            command.add("--fault=" + options.fault);
        }
        return new ProcessBuilder(command)
                .redirectErrorStream(true)
                .redirectOutput(logFile.toFile())
                .start();
    }

    private int fail(int code, String message, Path logFile) {
        System.out.println();
        System.out.println("[FAIL] " + message);
        tail(logFile);
        return code;
    }

    private void tail(Path logFile) {
        try {
            if (!Files.exists(logFile)) {
                return;
            }
            List<String> lines = Files.readAllLines(logFile, StandardCharsets.UTF_8);
            int from = Math.max(0, lines.size() - 20);
            if (lines.isEmpty()) {
                return;
            }
            System.out.println("--- plugin host log (last " + (lines.size() - from) + " lines) ---");
            for (int i = from; i < lines.size(); i++) {
                System.out.println("  " + lines.get(i));
            }
        } catch (IOException ignored) {
            // diagnosing a broken run must not fail on unreadable logs
        }
    }

    private static void closeQuietly(HostChannel channel) {
        if (channel == null) {
            return;
        }
        try {
            channel.close();
        } catch (IOException ignored) {
            // closing a dead channel is not an error worth reporting
        }
    }

    private static void stop(Process process) {
        if (process == null || !process.isAlive()) {
            return;
        }
        process.destroy();
        try {
            if (!process.waitFor(2, TimeUnit.SECONDS)) {
                process.destroyForcibly();
            }
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            process.destroyForcibly();
        }
    }

    private static final class Options {
        static final String USAGE = """
                usage: mukkit-spike [options]
                  --ticks=N       ticks to drive (default 200)
                  --rate=N        ticks per second, 0 for unpaced (default 20)
                  --profile=NAME  host profile: stub | pm (default stub)
                  --fault=SPEC    inject a host fault: hang:N | crash:N | error:N
                  --host=PATH     path to mukkit-host.php (default pm-host/bin/mukkit-host.php)
                  --soft-ms=N     soft deadline per tick (default 15)
                  --hard-ms=N     hard deadline per tick (default 500)
                """;

        final long ticks;
        final int rate;
        final String profile;
        final String fault;
        final String hostScript;
        final long softDeadlineMs;
        final long hardDeadlineMs;

        private Options(long ticks, int rate, String profile, String fault,
                        String hostScript, long softDeadlineMs, long hardDeadlineMs) {
            this.ticks = ticks;
            this.rate = rate;
            this.profile = profile;
            this.fault = fault;
            this.hostScript = hostScript;
            this.softDeadlineMs = softDeadlineMs;
            this.hardDeadlineMs = hardDeadlineMs;
        }

        static Options parse(String[] args) {
            long ticks = 200;
            int rate = 20;
            String profile = "stub";
            String fault = null;
            String host = "pm-host/bin/mukkit-host.php";
            long soft = 15;
            long hard = 500;

            for (String arg : args) {
                if (arg.equals("--help") || arg.equals("-h")) {
                    throw new IllegalArgumentException("");
                } else if (arg.startsWith("--ticks=")) {
                    ticks = Long.parseLong(arg.substring(8));
                } else if (arg.startsWith("--rate=")) {
                    rate = Integer.parseInt(arg.substring(7));
                } else if (arg.equals("--turbo")) {
                    rate = 0;
                } else if (arg.startsWith("--profile=")) {
                    profile = arg.substring(10);
                } else if (arg.startsWith("--fault=")) {
                    fault = arg.substring(8);
                } else if (arg.startsWith("--host=")) {
                    host = arg.substring(7);
                } else if (arg.startsWith("--soft-ms=")) {
                    soft = Long.parseLong(arg.substring(10));
                } else if (arg.startsWith("--hard-ms=")) {
                    hard = Long.parseLong(arg.substring(10));
                } else {
                    throw new IllegalArgumentException("unknown option: " + arg);
                }
            }
            if (ticks <= 0) {
                throw new IllegalArgumentException("--ticks must be positive");
            }
            return new Options(ticks, rate, profile, fault, host, soft, hard);
        }
    }

    /**
     * Stands in for the game server: fabricates a plausible event stream and tallies whatever
     * the host asks the server to do.
     */
    private static final class SyntheticGateway implements Gateway {

        private long tick;
        private long events;
        private long operations;
        private final Map<String, Integer> opKinds = new LinkedHashMap<>();
        private final List<String> samples = new ArrayList<>();

        @Override
        public List<Map<String, Object>> drainInbound() {
            tick++;
            List<Map<String, Object>> inbound = new ArrayList<>(2);
            if (tick == 1L) {
                inbound.add(event("player.join", "6f1f0e4c-0000-0000-0000-000000000001",
                        Map.of("name", "Steve", "xuid", "2535400000000001")));
            } else if (tick % 25L == 0L) {
                inbound.add(inboundCommand("6f1f0e4c-0000-0000-0000-000000000001", "/money"));
            }
            if (tick % 5L == 0L) {
                inbound.add(event("player.chat", "6f1f0e4c-0000-0000-0000-000000000001",
                        Map.of("message", "tick " + tick)));
            }
            events += inbound.size();
            return inbound;
        }

        @Override
        public void applyOp(Map<String, Object> op) {
            operations++;
            String kind = String.valueOf(op.get("o"));
            opKinds.merge(kind, 1, Integer::sum);
            if ("log".equals(kind)) {
                System.out.println("  [plugin:" + op.get("level") + "] " + op.get("msg"));
                return;
            }
            if (samples.size() < 3) {
                samples.add(kind + " -> " + op.get("cls") + " " + op.get("f"));
            }
        }

        @Override
        public void onLog(String level, String message) {
            System.out.println("  [host:" + level + "] " + message);
        }

        String summary() {
            StringBuilder out = new StringBuilder();
            out.append("inbound events   ").append(events).append('\n');
            out.append("op kinds         ").append(opKinds).append('\n');
            for (String sample : samples) {
                out.append("  sample: ").append(sample).append('\n');
            }
            return out.toString();
        }

        private static Map<String, Object> event(String name, String player, Map<String, Object> data) {
            Map<String, Object> item = new LinkedHashMap<>();
            item.put("k", "event");
            item.put("name", name);
            item.put("player", player);
            item.put("data", data);
            return item;
        }

        private static Map<String, Object> inboundCommand(String sender, String line) {
            Map<String, Object> item = new LinkedHashMap<>();
            item.put("k", "command");
            item.put("sender", sender);
            item.put("line", line);
            return item;
        }
    }
}
