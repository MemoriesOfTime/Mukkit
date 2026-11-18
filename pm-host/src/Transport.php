<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * Length-prefixed JSON frames over a Unix domain socket.
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

    private function __construct(private readonly string $path)
    {
    }

    public static function listen(string $path): self
    {
        if (file_exists($path)) {
            @unlink($path);
        }
        $transport = new self($path);
        $server = @stream_socket_server('unix://' . $path, $errno, $errstr);
        if ($server === false) {
            throw new \RuntimeException("cannot listen on {$path}: {$errstr} ({$errno})");
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
        if (file_exists($this->path)) {
            @unlink($this->path);
        }
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
