<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * A fake plugin runtime with no PocketMine in it.
 *
 * It exists so the transport, tick pacing and supervision can be proven before the PM fork
 * is worth building, and it doubles as the reference for what a real profile must emit.
 */
final class StubProfile implements Profile
{
    /** Plugins that need PocketMine to simulate the world cannot be served by a mirror. */
    private const UNSUPPORTED = [
        'LegacySkyBlock' => 'requires PM world simulation (capability: world.simulate)',
    ];

    /** @var array<string, string> */
    private array $players = [];

    private int $ticks = 0;

    public function name(): string
    {
        return 'stub';
    }

    public function load(array $plugins, array $context = []): array
    {
        $loaded = [];
        $rejected = [];
        foreach ($plugins as $plugin) {
            $name = (string) ($plugin['name'] ?? 'unknown');
            if (isset(self::UNSUPPORTED[$name])) {
                $rejected[] = ['name' => $name, 'reason' => self::UNSUPPORTED[$name]];
                continue;
            }
            $loaded[] = [
                'name' => $name,
                'version' => '1.0.0',
                'api' => '5.0.0',
                'status' => 'enabled',
            ];
        }
        return ['loaded' => $loaded, 'rejected' => $rejected];
    }

    public function tick(array $inbound): array
    {
        $this->ticks++;
        $ops = [];

        foreach ($inbound as $item) {
            $kind = (string) ($item['k'] ?? '');
            $uuid = (string) ($item['player'] ?? '');

            if ($kind === 'event' && ($item['name'] ?? '') === 'player.join') {
                $name = (string) ($item['data']['name'] ?? 'player');
                $this->players[$uuid] = $name;
                $ops[] = Protocol::packetOp($uuid, 'TextPacket', [
                    'type' => 'chat',
                    'message' => "§a欢迎 {$name} 来到 Mukkit",
                ]);
            }

            if ($kind === 'event' && ($item['name'] ?? '') === 'player.chat') {
                $ops[] = Protocol::packetOp($uuid, 'TextPacket', [
                    'type' => 'chat',
                    'message' => '§7<§f' . ($this->players[$uuid] ?? '?') . '§7> §f' . ($item['data']['message'] ?? ''),
                ]);
            }

            if ($kind === 'command') {
                $ops[] = Protocol::packetOp((string) ($item['sender'] ?? ''), 'TextPacket', [
                    'type' => 'chat',
                    'message' => '§e余额: §a$1024.00 §7(占位，来自 stub profile)',
                ]);
            }
        }

        if ($this->ticks % 100 === 0) {
            $ops[] = Protocol::logOp('info', sprintf(
                'stub profile alive: %d ticks, %d players known',
                $this->ticks,
                count($this->players)
            ));
        }

        return $ops;
    }

    public function shutdown(): void
    {
    }

    /** No `CommandMap` here either: the stub observes, it never runs a command. */
    public function ownsCommands(): bool
    {
        return false;
    }

    public function eventDemand(): array
    {
        return [];
    }
}
