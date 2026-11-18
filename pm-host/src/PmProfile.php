<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * The production PocketMine profile: phar plugins are loaded by PM itself, not by a stand-in
 * contract.
 *
 * The test-only `PluginProfile` remains for the HostedPlugin contract. The mechanics here live
 * in PmRuntime (the only file allowed to boot the fork); this class only adapts them to the
 * bridge's Profile interface.
 *
 * Paths come from the environment so the same jar can point at an unpacked host:
 *   MUKKIT_PM_ROOT     fork checkout (default: ../PocketMine-MP next to the repo)
 *   MUKKIT_PM_DATA     PM data dir (default: <repo>/run/pm/data)
 *   MUKKIT_PM_PLUGINS  phar directory (default: <repo>/run/pm/plugins)
 *   MUKKIT_PM_DATA_LAYOUT  plugin-folder (default) | isolated — see PmRuntime
 *
 * When the bridge supplies phar paths, their directory wins: the JVM already decided which
 * `plugins/` folder is live, and PM must scan the same one.
 */
final class PmProfile implements Profile
{
    private ?PmRuntime $runtime = null;

    public function __construct(
        private readonly ?string $pmRoot = null,
        private readonly ?string $dataPath = null,
        private readonly ?string $pluginPath = null,
    ) {
    }

    public function name(): string
    {
        return 'pm';
    }

    public function load(array $plugins, array $context = []): array
    {
        $pluginPath = $this->pluginPath ?? self::env('MUKKIT_PM_PLUGINS') ?? self::repoPath('run/pm/plugins');

        foreach ($plugins as $entry) {
            $path = is_array($entry) ? (string) ($entry['path'] ?? '') : '';
            if ($path !== '' && is_file($path)) {
                $pluginPath = dirname($path);
                break;
            }
        }

        $runtime = new PmRuntime(
            $this->pmRoot ?? self::env('MUKKIT_PM_ROOT') ?? self::defaultForkPath(),
            $this->dataPath ?? self::env('MUKKIT_PM_DATA') ?? self::repoPath('run/pm/data'),
            $pluginPath,
            isset($context['defaultWorld']) && is_string($context['defaultWorld']) && $context['defaultWorld'] !== ''
                ? $context['defaultWorld']
                : null,
            self::env('MUKKIT_PM_DATA_LAYOUT') ?? 'plugin-folder',
        );

        $result = $runtime->boot();
        $this->runtime = $runtime;

        return $result;
    }

    public function tick(array $inbound): array
    {
        if ($this->runtime === null) {
            throw new \RuntimeException('pm profile: load() was never called');
        }
        return $this->runtime->tick($inbound);
    }

    public function shutdown(): void
    {
        $this->runtime?->shutdown();
        $this->runtime = null;
    }

    /**
     * Commands are only claimed while a PocketMine server is actually up.
     *
     * `ready` is sent after `load()`, so in the normal path this is true. It is false when the
     * boot failed - and then the adapter must keep answering commands itself instead of
     * forwarding them into a runtime that is not there.
     */
    public function ownsCommands(): bool
    {
        return $this->runtime !== null;
    }

    public function eventDemand(): array
    {
        return $this->runtime?->eventDemand() ?? [];
    }

    private static function env(string $key): ?string
    {
        $value = getenv($key);
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function repoPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    private static function defaultForkPath(): string
    {
        return dirname(__DIR__, 3) . '/PocketMine-MP';
    }
}
