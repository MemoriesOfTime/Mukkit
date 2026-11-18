<?php

declare(strict_types=1);

namespace MukkitExample;

use Mukkit\Host\HostedPlugin;
use Mukkit\Host\PluginContext;

/**
 * The smallest plugin that proves the profile end to end: no world access, no storage, just
 * chat, broadcast and log calls through PluginContext.
 */
final class StubChat implements HostedPlugin
{
    public function onEnable(PluginContext $ctx): void
    {
        $ctx->log('info', 'StubChat enabled');
    }

    public function onEvent(array $event, PluginContext $ctx): void
    {
        $player = (string) ($event['player'] ?? '');
        $name = (string) ($event['data']['name'] ?? 'player');

        switch ((string) ($event['name'] ?? '')) {
            case 'player.join':
                $ctx->chat($player, '§a欢迎 ' . $name . ' 来到 Mukkit');
                break;

            case 'player.chat':
                $message = (string) ($event['data']['message'] ?? '');
                $ctx->broadcast('§7<§f' . $name . '§7> §f' . $message);
                break;

            case 'player.quit':
                $ctx->broadcast('§e' . $name . ' 离开了');
                break;
        }
    }

    public function onCommand(array $command, PluginContext $ctx): void
    {
        if (trim((string) ($command['line'] ?? '')) !== '/mukkit') {
            return;
        }

        $sender = (string) ($command['sender'] ?? 'unknown');
        $ctx->log('info', 'mukkit status requested by ' . $sender);
        $ctx->broadcast('§bMukkit §7桥接运行中');
    }

    public function onDisable(PluginContext $ctx): void
    {
        $ctx->log('info', 'StubChat disabled');
    }
}
