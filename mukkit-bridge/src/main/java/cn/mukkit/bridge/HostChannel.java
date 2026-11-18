package cn.mukkit.bridge;

import java.io.Closeable;
import java.io.EOFException;
import java.io.IOException;
import java.net.InetSocketAddress;
import java.net.UnixDomainSocketAddress;
import java.nio.ByteBuffer;
import java.nio.channels.SelectionKey;
import java.nio.channels.Selector;
import java.nio.channels.SocketChannel;
import java.nio.charset.StandardCharsets;
import java.nio.file.Path;
import java.util.Map;

/**
 * A length-prefixed JSON channel to the plugin host.
 *
 * <p>Local sockets are the reason this design can stay dependency-free: a Unix domain socket
 * needs nothing but the JDK, and it keeps the PHP process from ever exposing a port. The TCP
 * variant exists only for platforms without Unix sockets.</p>
 */
public final class HostChannel implements Closeable {

    private static final int MAX_FRAME_BYTES = 16 * 1024 * 1024;

    private final SocketChannel channel;
    private final Selector selector;

    private ByteBuffer header = ByteBuffer.allocate(4);
    private ByteBuffer body;
    private boolean headerComplete;
    private boolean closed;

    public static HostChannel connectUnix(Path socket, long timeoutMillis) throws IOException {
        long deadline = System.nanoTime() + timeoutMillis * 1_000_000L;
        IOException last = null;
        while (System.nanoTime() < deadline) {
            try {
                return new HostChannel(SocketChannel.open(UnixDomainSocketAddress.of(socket)));
            } catch (IOException e) {
                last = e;
                sleep(20L);
            }
        }
        throw new IOException("could not connect to plugin host at " + socket, last);
    }

    public static HostChannel connectTcp(String host, int port, long timeoutMillis) throws IOException {
        SocketChannel channel = SocketChannel.open();
        channel.socket().connect(new InetSocketAddress(host, port), (int) timeoutMillis);
        return new HostChannel(channel);
    }

    private HostChannel(SocketChannel channel) throws IOException {
        channel.configureBlocking(false);
        this.selector = Selector.open();
        channel.register(selector, SelectionKey.OP_READ);
        this.channel = channel;
    }

    /**
     * Reads the next frame, blocking at most {@code timeoutMillis}.
     *
     * @return the decoded message, or {@code null} when the deadline expired first
     */
    public Map<String, Object> receive(long timeoutMillis) throws IOException {
        long deadline = System.nanoTime() + Math.max(0L, timeoutMillis) * 1_000_000L;
        while (true) {
            if (pump()) {
                return finishFrame();
            }
            long remainingNanos = deadline - System.nanoTime();
            if (remainingNanos <= 0L) {
                return null;
            }
            selector.select(Math.max(1L, remainingNanos / 1_000_000L));
            selector.selectedKeys().clear();
        }
    }

    public void send(Map<String, Object> message) throws IOException {
        byte[] payload = Json.write(message).getBytes(StandardCharsets.UTF_8);
        if (payload.length == 0 || payload.length > MAX_FRAME_BYTES) {
            throw new IOException("frame size out of range: " + payload.length);
        }
        ByteBuffer buffer = ByteBuffer.allocate(4 + payload.length);
        buffer.putInt(payload.length).put(payload).flip();
        while (buffer.hasRemaining()) {
            if (channel.write(buffer) == 0) {
                selector.select(1L);
                selector.selectedKeys().clear();
            }
        }
    }

    /** Advances the read state machine without blocking. Returns true when a full frame is buffered. */
    private boolean pump() throws IOException {
        if (!headerComplete) {
            int read = channel.read(header);
            if (read < 0) {
                throw new EOFException("plugin host closed the connection");
            }
            if (header.hasRemaining()) {
                return false;
            }
            header.flip();
            int length = header.getInt();
            if (length <= 0 || length > MAX_FRAME_BYTES) {
                throw new IOException("invalid frame length: " + length);
            }
            body = ByteBuffer.allocate(length);
            headerComplete = true;
        }
        while (body.hasRemaining()) {
            int read = channel.read(body);
            if (read < 0) {
                throw new EOFException("plugin host closed the connection");
            }
            if (read == 0) {
                return false;
            }
        }
        return true;
    }

    @SuppressWarnings("unchecked")
    private Map<String, Object> finishFrame() {
        body.flip();
        String text = StandardCharsets.UTF_8.decode(body).toString();
        header = ByteBuffer.allocate(4);
        body = null;
        headerComplete = false;

        Object parsed = Json.parse(text);
        if (!(parsed instanceof Map<?, ?>)) {
            throw new IllegalArgumentException("frame is not a JSON object: " + text);
        }
        return (Map<String, Object>) parsed;
    }

    public boolean isOpen() {
        return !closed && channel.isOpen();
    }

    @Override
    public void close() throws IOException {
        if (closed) {
            return;
        }
        closed = true;
        try {
            selector.close();
        } finally {
            channel.close();
        }
    }

    private static void sleep(long millis) {
        try {
            Thread.sleep(millis);
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
        }
    }
}
