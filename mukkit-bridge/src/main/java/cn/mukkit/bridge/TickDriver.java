package cn.mukkit.bridge;

import java.io.Closeable;
import java.io.IOException;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Drives the plugin host in lockstep with the server tick.
 *
 * <p>Only one tick is ever outstanding. If the host answers late the tick is still applied,
 * because dropping it would leave plugin state behind the world; if it never answers the run
 * is aborted so the caller can respawn the process instead of freezing the server.</p>
 *
 * <p>{@link #step()} is the primitive: a game server that owns its own loop calls it once per
 * tick. {@link #run(long)} is the same loop for harnesses that have no server to drive.</p>
 */
public final class TickDriver implements Closeable {

    /**
     * @param rate              ticks per second; {@code 0} runs unpaced
     * @param softDeadlineMillis budget after which a tick counts as an overrun
     * @param hardDeadlineMillis budget after which the host is considered dead
     */
    public record Config(int rate, long softDeadlineMillis, long hardDeadlineMillis) {

        public static Config defaults() {
            return new Config(20, 15, 500);
        }
    }

    private final Gateway gateway;
    private final Config config;
    private final TickStats stats = new TickStats();

    private HostChannel channel;
    private long sequence;
    private boolean running;

    public TickDriver(HostChannel channel, Gateway gateway, Config config) {
        this.channel = channel;
        this.gateway = gateway;
        this.config = config;
    }

    /**
     * Swaps in a fresh connection after the previous host process died or was restarted.
     *
     * <p>The frame sequence keeps counting: a stale {@code tickdone} from a dead process can
     * never be mistaken for the answer to a tick sent on the new connection.</p>
     */
    public void rebind(HostChannel replacement) {
        this.channel = replacement;
    }

    public TickStats stats() {
        return stats;
    }

    /**
     * Runs exactly one tick: send the accumulated inbound work, wait for the host, apply the
     * outbound operations on the calling thread.
     *
     * @return the number of operations applied
     * @throws HostHungException when the host misses the hard deadline; the caller should
     *                           restart the host process rather than keep waiting
     */
    public int step() throws IOException {
        if (!running) {
            running = true;
            stats.begin();
        }

        long seq = ++sequence;
        List<Map<String, Object>> inbound = gateway.drainInbound();

        Map<String, Object> payload = new LinkedHashMap<>(4);
        payload.put("seq", seq);
        payload.put("in", inbound);

        long started = System.nanoTime();
        channel.send(Protocol.envelope(Protocol.TICK, seq, payload));

        Map<String, Object> done = awaitTickDone(seq, started + config.softDeadlineMillis() * 1_000_000L);
        if (done == null) {
            stats.recordOverrun();
            done = awaitTickDone(seq, started + config.hardDeadlineMillis() * 1_000_000L);
            if (done == null) {
                stats.recordHang();
                gateway.onHostLost("tick " + seq + " exceeded the hard deadline");
                throw new HostHungException(seq, config.hardDeadlineMillis());
            }
        }

        List<Map<String, Object>> ops = Protocol.ops(done);
        for (Map<String, Object> op : ops) {
            gateway.applyOp(op);
        }
        stats.record(System.nanoTime() - started, ops.size());
        return ops.size();
    }

    /**
     * Paces {@link #step()} at the configured rate. Harnesses use this; a game server calls
     * {@link #step()} from its own loop instead.
     */
    public TickStats run(long maxTicks) throws IOException {
        long intervalNanos = config.rate() > 0 ? 1_000_000_000L / config.rate() : 0L;
        long nextTick = System.nanoTime();
        try {
            for (long i = 0; i < maxTicks; i++) {
                step();
                if (intervalNanos > 0L) {
                    nextTick += intervalNanos;
                    long now = System.nanoTime();
                    if (nextTick < now - intervalNanos) {
                        nextTick = now;
                    }
                    sleepUntil(nextTick);
                }
            }
        } finally {
            stats.end();
            running = false;
        }
        return stats;
    }

    /**
     * Closes the measurement window for callers that drive {@link #step()} from their own loop.
     * Without it the report cannot compute a rate, because the window never ended.
     */
    public void finish() {
        stats.end();
        running = false;
    }

    private Map<String, Object> awaitTickDone(long seq, long deadlineNanos) throws IOException {
        while (true) {
            long remainingMillis = (deadlineNanos - System.nanoTime()) / 1_000_000L;
            if (remainingMillis < 0L) {
                return null;
            }
            Map<String, Object> message = channel.receive(remainingMillis);
            if (message == null) {
                return null;
            }
            if (Protocol.TICK_DONE.equals(Protocol.type(message)) && Protocol.id(message) == seq) {
                return message;
            }
            dispatch(message);
        }
    }

    private void dispatch(Map<String, Object> message) {
        Map<String, Object> payload = Protocol.payload(message);
        switch (Protocol.type(message)) {
            case Protocol.LOG -> gateway.onLog(stringOr(payload, "level", "info"), stringOr(payload, "msg", ""));
            case Protocol.READY -> gateway.onReady(payload);
            default -> gateway.onLog("warn", "unexpected frame: " + Protocol.type(message));
        }
    }

    private static String stringOr(Map<String, Object> map, String key, String fallback) {
        Object value = map.get(key);
        return value instanceof String text ? text : fallback;
    }

    private static void sleepUntil(long targetNanos) {
        long delta = targetNanos - System.nanoTime();
        if (delta <= 0L) {
            return;
        }
        try {
            Thread.sleep(delta / 1_000_000L, (int) (delta % 1_000_000L));
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }

    @Override
    public void close() throws IOException {
        channel.close();
    }
}
