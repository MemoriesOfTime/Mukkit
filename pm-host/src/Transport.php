<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * Length-prefixed JSON frames over a local socket.
 *
 * Two address forms are accepted:
 *
 *   /tmp/mukkit-1234.sock    Unix domain socket, the default on macOS and Linux
 *   tcp://127.0.0.1:50001    loopback TCP, bound to that address only
 *
 * The TCP form exists because the Windows PHP build has no `unix` stream transport at all -
 * `stream_get_transports()` there lists tcp/udp/ssl/tls and nothing else - so a host that can
 * only listen on a Unix socket never starts on Windows. The JVM side can connect over AF_UNIX
 * there, but that does not help when the other end cannot create the listener.
 *
 * Reads are fully blocking: the host has nothing else to do between ticks, and the JVM
 * always sends the next frame within one tick interval.
 */
final class Transport
{
    /** @var resource|null */
    private $server = null;

    /** @var resource|null */
    private $connection = null;

    private function __construct(private readonly string $address)
    {
    }

    public static function listen(string $address): self
    {
        $transport = new self($address);
        if (self::isTcp($address)) {
            // Loopback only: the frame format carries no authentication, so the listener must
            // not be reachable from another machine.
            $listen = $address;
        } else {
            if (file_exists($address)) {
                @unlink($address);
            }
            $listen = 'unix://' . $address;
        }
        $server = @stream_socket_server($listen, $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("cannot listen on {$address}: {$errstr} ({$errno})");
        }
        $transport->server = $server;
        return $transport;
    }

    public function accept(int $timeoutSeconds = 10): void
    {
        $connection = @stream_socket_accept($this->server, $timeoutSeconds);
        if ($connection === false) {
            throw new \RuntimeException('no client connected within ' . $timeoutSeconds . 's');
        }
        $this->connection = $connection;
    }

    /** @return array<string, mixed>|null null on a clean EOF */
    public function read(): ?array
    {
        $header = $this->readExactly(4);
        if ($header === null) {
            return null;
        }
        $length = unpack('N', $header)[1];
        if ($length <= 0 || $length > Protocol::MAX_FRAME) {
            throw new \RuntimeException('invalid frame length: ' . $length);
        }
        $payload = $this->readExactly($length);
        if ($payload === null) {
            return null;
        }
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('frame is not a JSON object');
        }
        return $decoded;
    }

    public function write(array $message): void
    {
        $frame = Protocol::frame($message);
        $written = 0;
        $total = strlen($frame);
        while ($written < $total) {
            $chunk = @fwrite($this->connection, substr($frame, $written));
            if ($chunk === false || $chunk === 0) {
                throw new \RuntimeException('cannot write to the bridge socket');
            }
            $written += $chunk;
        }
        fflush($this->connection);
    }

    public function close(): void
    {
        if (is_resource($this->connection)) {
            fclose($this->connection);
        }
        if (is_resource($this->server)) {
            fclose($this->server);
        }
        // Only Unix sockets leave a file behind; a TCP listener releases its port on close.
        if (!self::isTcp($this->address) && file_exists($this->address)) {
            @unlink($this->address);
        }
    }

    private static function isTcp(string $address): bool
    {
        return str_starts_with($address, 'tcp://');
    }

    private function readExactly(int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = @fread($this->connection, $length - strlen($buffer));
            if ($chunk === false) {
                return null;
            }
            if ($chunk === '') {
                if (feof($this->connection)) {
                    return null;
                }
                usleep(200);
                continue;
            }
            $buffer .= $chunk;
        }
        return $buffer;
    }
}
