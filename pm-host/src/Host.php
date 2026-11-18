<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * The host loop: handshake, load plugins, then one frame in and one frame out per tick.
 *
 * Faults are injected here rather than inside a profile so the hang / crash / throw paths can
 * be tested against the same code that runs in production.
 */
final class Host
{
    public function __construct(
        private readonly Transport $transport,
        private readonly Profile $profile,
        private readonly ?string $fault = null,
    ) {
    }

    public function run(): int
    {
        $this->transport->accept();

        $this->transport->write(Protocol::envelope(Protocol::HELLO, 1, [
            'proto' => Protocol::VERSION,
            'codec' => 'json',
            'php' => PHP_VERSION,
            'profile' => $this->profile->name(),
            'caps' => Capability::summary(),
        ]));

        $welcome = $this->expect(Protocol::WELCOME);
        $peer = (int) ($welcome['p']['proto'] ?? 0);
        if ($peer !== Protocol::VERSION) {
            throw new \RuntimeException(
                'protocol mismatch: host speaks ' . Protocol::VERSION . ", server speaks {$peer}"
            );
        }

        $request = $this->expect(Protocol::PLUGINS);
        // The welcome carries what the adapter knew before this process existed (the default
        // level name), so a profile can build the world before plugins enable.
        $result = $this->profile->load(
            $request['p']['list'] ?? [],
            is_array($welcome['p'] ?? null) ? $welcome['p'] : []
        );
        $this->transport->write(Protocol::envelope(Protocol::READY, 2, [
            'loaded' => $result['loaded'] ?? [],
            'rejected' => $result['rejected'] ?? [],
            //Who delivers chat and the join/quit lines. The `pm` profile runs PocketMine's own
            //PlayerChatEvent and broadcasts the formatted result, so the adapter has to stop
            //Nukkit from sending a second copy. `stub` and `plugin` only observe: they cannot
            //deliver anything, so the adapter must keep its own broadcast or chat goes silent.
            //See Mukkit\Host\Delivery for the single place that decides this.
            'broadcasts' => Delivery::ownerFor($this->profile->name()),
            //Whether the host answers block reads. Nukkit only sends chunk snapshots and block
            //updates to a host that does.
            'mirror' => Delivery::mirrorsWorld($this->profile->name()),
            //Whether this host runs the commands players type. True means the adapter forwards
            //every name Nukkit does not know instead of answering "unknown command" itself.
            'commands' => $this->profile->ownsCommands(),
            //Cancellable actions the adapter should hold back for a PocketMine verdict. Empty
            //means nobody is listening, and intercepting would be pure latency.
            'events' => $this->profile->eventDemand(),
        ]));

        return $this->loop();
    }

    private function loop(): int
    {
        $ticks = 0;
        while (true) {
            $message = $this->transport->read();
            if ($message === null) {
                return 0;
            }

            switch ((string) ($message['t'] ?? '')) {
                case Protocol::TICK:
                    $ticks++;
                    $this->transport->write($this->handleTick($message, $ticks));
                    break;

                case Protocol::SHUTDOWN:
                    $this->profile->shutdown();
                    return 0;

                default:
                    $this->transport->write(Protocol::envelope(Protocol::LOG, 0, [
                        'level' => 'warn',
                        'msg' => 'unknown frame: ' . ($message['t'] ?? '?'),
                    ]));
            }
        }
    }

    private function handleTick(array $message, int $ticks): array
    {
        $payload = $message['p'] ?? [];
        $started = microtime(true);
        $error = null;
        $ops = [];

        try {
            $this->injectFault($ticks);
            $ops = $this->profile->tick($payload['in'] ?? []);
        } catch (\Throwable $e) {
            // A throwing plugin must not take the host down. Report it on the tick frame and
            // keep going: the server logs it, the tick still lands, the game keeps running.
            $error = $e->getMessage();
            $ops[] = Protocol::logOp('error', "tick {$ticks} failed: {$error}");
        }

        return Protocol::envelope(Protocol::TICK_DONE, (int) ($message['id'] ?? 0), [
            'seq' => (int) ($payload['seq'] ?? 0),
            'ops' => $ops,
            'error' => $error,
            'stats' => [
                'ms' => round((microtime(true) - $started) * 1000, 3),
                'in' => count($payload['in'] ?? []),
                'ticks' => $ticks,
            ],
        ]);
    }

    private function injectFault(int $tick): void
    {
        if ($this->fault === null) {
            return;
        }
        $parts = explode(':', $this->fault, 2);
        $kind = $parts[0];
        $at = (int) ($parts[1] ?? 0);
        if ($at !== $tick) {
            return;
        }
        switch ($kind) {
            case 'hang':
                sleep(10);
                break;
            case 'crash':
                exit(3);
            case 'error':
                throw new \RuntimeException("injected plugin failure at tick {$tick}");
        }
    }

    private function expect(string $type): array
    {
        $message = $this->transport->read();
        if ($message === null) {
            throw new \RuntimeException("connection closed while waiting for '{$type}'");
        }
        if ((string) ($message['t'] ?? '') !== $type) {
            throw new \RuntimeException("expected '{$type}', got '" . ($message['t'] ?? '?') . "'");
        }
        return $message;
    }
}
