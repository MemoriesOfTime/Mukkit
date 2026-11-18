<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * The test-only `plugin` profile: loads phar plugins that implement HostedPlugin and drives
 * them from tick frames.
 *
 * Lifecycle authority lives on the JVM side. Loading a phar here only makes the class
 * available; onEnable is fired by an inbound `plugin.enable` item so the server's ordering is
 * reproduced exactly. Everything is idempotent (double enable / disable) because the server may
 * replay its plugin list after the host crashes and restarts.
 *
 * Failure containment is per plugin and per inbound item: one broken phar must never stop the
 * others from loading, and one throwing plugin must never lose the tick. Host only catches
 * around the whole tick, which is too coarse for that.
 */
final class PluginProfile implements Profile
{
    /** @var array<string, HostedPlugin> */
    private array $plugins = [];

    /** @var array<string, true> */
    private array $enabled = [];

    public function name(): string
    {
        return 'plugin';
    }

    public function load(array $plugins, array $context = []): array
    {
        $loaded = [];
        $rejected = [];

        foreach ($plugins as $entry) {
            $entryName = is_array($entry) ? (string) ($entry['name'] ?? 'unknown') : 'unknown';
            $path = is_array($entry) ? (string) ($entry['path'] ?? '') : '';

            if ($path === '' || !is_file($path)) {
                $rejected[] = [
                    'name' => $entryName,
                    'reason' => 'phar.missing: ' . ($path === '' ? '<empty path>' : $path) . ' not found',
                ];
                continue;
            }

            try {
                $manifest = PluginYml::fromPhar($path);
                $main = trim((string) ($manifest['main'] ?? ''));
                if ($manifest === [] || $main === '') {
                    $rejected[] = [
                        'name' => $entryName,
                        'reason' => 'plugin.yml.invalid: no usable plugin.yml or main class in ' . $path,
                    ];
                    continue;
                }

                $name = trim((string) ($manifest['name'] ?? ''));
                if ($name === '') {
                    $name = $entryName;
                }

                $plugin = $this->instantiate($path, $main);
                if ($plugin === null) {
                    $rejected[] = [
                        'name' => $name,
                        'reason' => 'main.class.missing: ' . $main . ' is not a usable HostedPlugin',
                    ];
                    continue;
                }

                if (isset($this->plugins[$name])) {
                    // Reload after a crash: the class is already here, do not duplicate state.
                    continue;
                }

                $this->plugins[$name] = $plugin;
                $loaded[] = [
                    'name' => $name,
                    'version' => (string) ($manifest['version'] ?? ''),
                    'api' => self::apiSummary($manifest['api'] ?? null),
                    'status' => 'loaded',
                ];
            } catch (\Throwable $e) {
                // Any single plugin failure is local to that plugin.
                $rejected[] = [
                    'name' => $entryName,
                    'reason' => 'main.class.missing: ' . $e->getMessage(),
                ];
            }
        }

        return ['loaded' => $loaded, 'rejected' => $rejected];
    }

    public function tick(array $inbound): array
    {
        $ops = [];

        foreach ($inbound as $item) {
            if (!is_array($item)) {
                $ops[] = Protocol::logOp('error', 'inbound item is not an object, skipped');
                continue;
            }

            $kind = (string) ($item['k'] ?? '');
            try {
                switch ($kind) {
                    case 'event':
                        $this->fireEvent($item, $ops);
                        break;

                    case 'command':
                        $this->fireCommand($item, $ops);
                        break;

                    case 'plugin':
                        $this->toggle($item, $ops);
                        break;

                    default:
                        $ops[] = Protocol::logOp('warn', 'unknown inbound kind: ' . ($kind === '' ? '?' : $kind));
                }
            } catch (\Throwable $e) {
                // One bad item must not end the tick; the rest still gets processed.
                $ops[] = Protocol::logOp('error', sprintf(
                    'inbound %s failed: %s',
                    $kind === '' ? '?' : $kind,
                    $e->getMessage()
                ));
            }
        }

        return $ops;
    }

    public function shutdown(): void
    {
        foreach ($this->enabledPlugins() as $name => $plugin) {
            $ctx = new PluginContext($name);
            try {
                $plugin->onDisable($ctx);
            } catch (\Throwable) {
                // Shutdown must always finish; a plugin cannot veto it.
            }
        }

        $this->enabled = [];
        $this->plugins = [];
    }

    private function instantiate(string $path, string $main): ?HostedPlugin
    {
        $entry = 'phar://' . (realpath($path) ?: $path) . '/main.php';
        if (!is_file($entry)) {
            return null;
        }

        try {
            require_once $entry;
        } catch (\Throwable) {
            return null;
        }

        if (!class_exists($main)) {
            return null;
        }

        try {
            $instance = new $main();
        } catch (\Throwable) {
            return null;
        }

        return $instance instanceof HostedPlugin ? $instance : null;
    }

    /**
     * @param array<string, mixed> $item
     * @param list<array<string, mixed>> $ops
     */
    private function fireEvent(array $item, array &$ops): void
    {
        $name = (string) ($item['name'] ?? '');
        if ($name === '' || !is_string($item['player'] ?? null)) {
            throw new \RuntimeException('event requires a name and a player uuid');
        }

        foreach ($this->enabledPlugins() as $pluginName => $plugin) {
            $ctx = new PluginContext($pluginName);
            try {
                $plugin->onEvent($item, $ctx);
            } catch (\Throwable $e) {
                $ops[] = Protocol::logOp('error', sprintf(
                    'plugin %s onEvent(%s) failed: %s',
                    $pluginName,
                    $name,
                    $e->getMessage()
                ));
            }
            $this->merge($ops, $ctx);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param list<array<string, mixed>> $ops
     */
    private function fireCommand(array $item, array &$ops): void
    {
        $line = $item['line'] ?? null;
        if (!is_string($line) || trim($line) === '') {
            throw new \RuntimeException('command requires a non-empty line');
        }

        foreach ($this->enabledPlugins() as $pluginName => $plugin) {
            $ctx = new PluginContext($pluginName);
            try {
                $plugin->onCommand($item, $ctx);
            } catch (\Throwable $e) {
                $ops[] = Protocol::logOp('error', sprintf(
                    'plugin %s onCommand(%s) failed: %s',
                    $pluginName,
                    $line,
                    $e->getMessage()
                ));
            }
            $this->merge($ops, $ctx);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @param list<array<string, mixed>> $ops
     */
    private function toggle(array $item, array &$ops): void
    {
        $name = (string) ($item['plugin'] ?? '');
        $action = (string) ($item['name'] ?? '');

        if ($name === '' || ($action !== 'enable' && $action !== 'disable')) {
            throw new \RuntimeException('plugin item requires plugin and name=enable|disable');
        }

        $plugin = $this->plugins[$name] ?? null;
        if ($plugin === null) {
            $ops[] = Protocol::logOp('warn', "plugin.{$action} ignored: '{$name}' is not loaded");
            return;
        }

        $ctx = new PluginContext($name);
        if ($action === 'enable') {
            if (isset($this->enabled[$name])) {
                return;
            }
            try {
                $plugin->onEnable($ctx);
                $this->enabled[$name] = true;
            } catch (\Throwable $e) {
                $ops[] = Protocol::logOp('error', "plugin {$name} onEnable failed: " . $e->getMessage());
            }
            $this->merge($ops, $ctx);
            return;
        }

        if (!isset($this->enabled[$name])) {
            return;
        }
        unset($this->enabled[$name]);
        try {
            $plugin->onDisable($ctx);
        } catch (\Throwable $e) {
            $ops[] = Protocol::logOp('error', "plugin {$name} onDisable failed: " . $e->getMessage());
        }
        $this->merge($ops, $ctx);
    }

    /** @return array<string, HostedPlugin> */
    private function enabledPlugins(): array
    {
        $enabled = [];
        foreach ($this->enabled as $name => $_) {
            if (isset($this->plugins[$name])) {
                $enabled[$name] = $this->plugins[$name];
            }
        }
        return $enabled;
    }

    /**
     * @param list<array<string, mixed>> $ops
     */
    private function merge(array &$ops, PluginContext $ctx): void
    {
        foreach ($ctx->ops() as $op) {
            $ops[] = $op;
        }
    }

    private static function apiSummary(mixed $api): string
    {
        if (is_array($api)) {
            return implode(', ', array_map(static fn (mixed $v): string => (string) $v, $api));
        }
        return is_string($api) ? $api : '';
    }

    /** This profile plugs into a host-side contract, not into a `CommandMap`; Nukkit keeps its commands. */
    public function ownsCommands(): bool
    {
        return false;
    }

    public function eventDemand(): array
    {
        return [];
    }
}
