<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * Runtime prerequisites for the two things that can be booted here.
 *
 * The headless set is what a bridge host needs once networking, world storage and real
 * threads have been removed from PocketMine; the upstream set is what stock PocketMine-MP
 * refuses to start without. Reporting both is what turns "can we run it?" into a decision
 * instead of an experiment.
 */
final class Capability
{
    public const HEADLESS_REQUIRED = [
        'json', 'pcre', 'spl', 'reflection', 'mbstring', 'phar', 'sockets', 'ctype',
    ];

    public const UPSTREAM_REQUIRED = [
        'pmmpthread', 'leveldb', 'chunkutils2', 'igbinary', 'yaml', 'crypto', 'encoding', 'morton',
        'openssl', 'curl', 'ctype', 'zlib', 'gmp', 'zip', 'simplexml', 'json', 'pcre',
    ];

    /**
     * @return array{
     *     php: string,
     *     pm_source: string|null,
     *     pm_version: string|null,
     *     missing_headless: list<string>,
     *     missing: list<string>,
     *     ready: bool
     * }
     */
    public static function audit(): array
    {
        $source = self::pmSource();
        $missingHeadless = self::missing(self::HEADLESS_REQUIRED);
        $missingUpstream = self::missing(self::UPSTREAM_REQUIRED);

        return [
            'php' => PHP_VERSION,
            'pm_source' => $source,
            'pm_version' => $source === null ? null : self::pmVersion($source),
            'missing_headless' => $missingHeadless,
            'missing' => $missingUpstream,
            'ready' => $source !== null && $missingUpstream === [],
        ];
    }

    /** @return array{pmSource: bool, pmReady: bool, headlessOk: bool} */
    public static function summary(): array
    {
        $audit = self::audit();
        return [
            'pmSource' => $audit['pm_source'] !== null,
            'pmReady' => $audit['ready'],
            'headlessOk' => $audit['missing_headless'] === [],
        ];
    }

    public static function pmSource(): ?string
    {
        $candidates = [];
        $fromEnv = getenv('MUKKIT_PM_ROOT');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $candidates[] = $fromEnv;
        }
        $candidates[] = __DIR__ . '/../../../PocketMine-MP';
        $candidates[] = __DIR__ . '/../../../vendor/pocketmine/pocketmine-mp';

        foreach ($candidates as $candidate) {
            if (is_file($candidate . '/src/Server.php')) {
                $resolved = realpath($candidate);
                return $resolved === false ? $candidate : $resolved;
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function missing(array $required): array
    {
        $missing = [];
        foreach ($required as $extension) {
            if (!extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }
        return $missing;
    }

    private static function pmVersion(string $source): ?string
    {
        $file = $source . '/src/VersionInfo.php';
        if (!is_file($file)) {
            return null;
        }
        $contents = (string) file_get_contents($file);
        if (preg_match('/BASE_VERSION\s*=\s*"([^"]+)"/', $contents, $matches) === 1) {
            return $matches[1];
        }
        return null;
    }
}
