<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * Plugin contract for the test-only `plugin` profile.
 *
 * This is deliberately *not* a re-implementation of PocketMine's PluginBase: it only carries
 * the four lifecycle entry points the bridge drives. The production `pm` profile uses the real
 * PluginBase from the PM fork; this interface keeps the host testable without the fork.
 */
interface HostedPlugin
{
    public function onEnable(PluginContext $ctx): void;

    /**
     * @param array<string, mixed> $event wire event, e.g. {"k":"event","name":"player.join",...}
     */
    public function onEvent(array $event, PluginContext $ctx): void;

    /**
     * @param array<string, mixed> $command wire command, e.g. {"k":"command","sender":"console","line":"/mukkit"}
     */
    public function onCommand(array $command, PluginContext $ctx): void;

    public function onDisable(PluginContext $ctx): void;
}
