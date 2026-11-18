package cn.mukkit.bridge;

/**
 * Thrown when the plugin host misses the hard deadline. Recovery is the supervisor's job:
 * the driver stops driving ticks and hands control back so the child process can be killed
 * and respawned instead of stalling the whole server.
 */
public class HostHungException extends RuntimeException {

    private final long sequence;

    public HostHungException(long sequence, long waitedMillis) {
        super("plugin host did not answer tick " + sequence + " within " + waitedMillis + "ms");
        this.sequence = sequence;
    }

    public long sequence() {
        return sequence;
    }
}
