package cn.mukkit.bridge;

import java.util.Arrays;
import java.util.Locale;

/** Latency and health counters for a driven run. */
public final class TickStats {

    /**
     * A server runs for hours, so the samples are a ring: the percentiles describe the recent
     * window rather than the first few seconds after a cold JIT.
     */
    private static final int WINDOW = 4096;

    private final long[] samples = new long[WINDOW];
    private int sampleCount;
    private int nextIndex;

    private long ticks;
    private long overruns;
    private long hangs;
    private long operations;
    private long startedNanos;
    private long finishedNanos;

    void begin() {
        startedNanos = System.nanoTime();
    }

    void end() {
        finishedNanos = System.nanoTime();
    }

    void record(long roundTripNanos, int ops) {
        ticks++;
        operations += ops;
        samples[nextIndex] = roundTripNanos;
        nextIndex = (nextIndex + 1) % WINDOW;
        if (sampleCount < WINDOW) {
            sampleCount++;
        }
    }

    void recordOverrun() {
        overruns++;
    }

    void recordHang() {
        hangs++;
    }

    public long ticks() {
        return ticks;
    }

    public long overruns() {
        return overruns;
    }

    public long hangs() {
        return hangs;
    }

    public long operations() {
        return operations;
    }

    public String report() {
        long end = finishedNanos != 0L ? finishedNanos : System.nanoTime();
        double elapsedSeconds = (end - startedNanos) / 1_000_000_000.0;
        double achievedRate = elapsedSeconds <= 0 ? 0 : ticks / elapsedSeconds;
        long[] sorted = Arrays.copyOf(samples, sampleCount);
        Arrays.sort(sorted);

        StringBuilder out = new StringBuilder();
        out.append(String.format(Locale.ROOT, "ticks            %d (%.1f TPS achieved)%n", ticks, achievedRate));
        out.append(String.format(Locale.ROOT, "operations       %d%n", operations));
        out.append(String.format(Locale.ROOT, "overruns         %d (soft deadline missed)%n", overruns));
        out.append(String.format(Locale.ROOT, "hangs            %d%n", hangs));
        if (sampleCount > 0) {
            out.append(String.format(Locale.ROOT, "round trip       min %.3f / p50 %.3f / p95 %.3f / p99 %.3f / max %.3f ms%n",
                    millis(sorted[0]),
                    millis(percentile(sorted, 50)),
                    millis(percentile(sorted, 95)),
                    millis(percentile(sorted, 99)),
                    millis(sorted[sorted.length - 1])));
        }
        return out.toString();
    }

    private static double millis(long nanos) {
        return nanos / 1_000_000.0;
    }

    private static long percentile(long[] sorted, int percentile) {
        int index = (int) Math.ceil(percentile / 100.0 * sorted.length) - 1;
        return sorted[Math.max(0, Math.min(sorted.length - 1, index))];
    }
}
