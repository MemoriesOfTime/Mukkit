<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * Everything a plugin may do in the `plugin` profile: send chat, broadcast, write a log line.
 *
 * The surface is intentionally tiny. World and inventory access belong to the production
 * `pm` profile, so they are not here at all.
 *
 * Ops are buffered per plugin and drained by PluginProfile once per inbound item, which keeps
 * the outbound order identical to the order in which plugins produced them.
 */
final class PluginContext
{
    /** @var list<array<string, mixed>> */
    private array $ops = [];

    public function __construct(private readonly string $pluginName)
    {
    }

    public function chat(string $to, string $message): void
    {
        $this->ops[] = Protocol::packetOp($to, 'TextPacket', [
            'type' => 'chat',
            'message' => $message,
        ]);
    }

    public function broadcast(string $message): void
    {
        $this->chat('*', $message);
    }

    public function log(string $level, string $message): void
    {
        $this->ops[] = Protocol::logOp($level, $message);
    }

    /**
     * Drain the buffered ops in production order.
     *
     * @return list<array<string, mixed>>
     */
    public function ops(): array
    {
        $ops = $this->ops;
        $this->ops = [];
        return $ops;
    }

    public function pluginName(): string
    {
        return $this->pluginName;
    }
}
