<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * A minimal plugin.yml reader covering only the subset the `plugin` profile needs.
 *
 * Supported: `key: value`, `key: "quoted"`, `key: [a, b]`, an indented `- item` list, `#`
 * comments and blank lines. Anything else (anchors, multi-line scalars, nested maps) is
 * silently ignored rather than misread.
 *
 * The `pm` profile uses the YAML extension bundled in the PocketMine fork; this reader exists
 * so the `plugin` profile can run with zero composer dependencies.
 */
final class PluginYml
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(string $yaml): array
    {
        $data = [];
        $listKey = null;

        foreach (preg_split('/\r\n|\r|\n/', $yaml) ?: [] as $rawLine) {
            $trimmed = trim(self::stripComment($rawLine));
            if ($trimmed === '' || $trimmed === '---' || $trimmed === '...') {
                continue;
            }

            if (str_starts_with($trimmed, '- ') || $trimmed === '-') {
                if ($listKey === null) {
                    continue;
                }
                if (!is_array($data[$listKey] ?? null)) {
                    $data[$listKey] = [];
                }
                $data[$listKey][] = self::scalar(substr($trimmed, 1));
                continue;
            }

            $colon = strpos($trimmed, ':');
            if ($colon === false) {
                continue;
            }
            $key = trim(substr($trimmed, 0, $colon));
            if (preg_match('/^[A-Za-z0-9_.\-]+$/', $key) !== 1) {
                continue;
            }

            $value = trim(substr($trimmed, $colon + 1));
            if ($value === '') {
                // Might be a nested list on the following indented lines, or just empty.
                $data[$key] = null;
                $listKey = $key;
                continue;
            }

            $listKey = null;
            $data[$key] = self::value($value);
        }

        return $data;
    }

    /**
     * Read `<phar>/plugin.yml`. Returns an empty array when the phar or the descriptor is
     * missing or unreadable, so callers can report a single "invalid" reason.
     *
     * @return array<string, mixed>
     */
    public static function fromPhar(string $pharPath): array
    {
        $real = realpath($pharPath);
        if ($real === false || !is_file($real)) {
            return [];
        }

        try {
            $descriptor = 'phar://' . $real . '/plugin.yml';
            if (!@is_file($descriptor)) {
                return [];
            }
            $contents = @file_get_contents($descriptor);
        } catch (\Throwable) {
            // A corrupt or non-phar file makes phar:// throw; treat it as "no descriptor".
            return [];
        }

        if (!is_string($contents)) {
            return [];
        }

        return self::parse($contents);
    }

    private static function stripComment(string $line): string
    {
        $quote = null;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '#' && ($i === 0 || ctype_space($line[$i - 1]))) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    /** @return array<int, string> */
    private static function splitInline(string $inner): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;
        $length = strlen($inner);

        for ($i = 0; $i < $length; $i++) {
            $char = $inner[$i];
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;
                continue;
            }
            if ($char === ',') {
                $parts[] = $buffer;
                $buffer = '';
                continue;
            }
            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = $buffer;
        }

        return $parts;
    }

    private static function value(string $value): mixed
    {
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));
            if ($inner === '') {
                return [];
            }
            return array_map(
                static fn (string $item): string => self::scalar($item),
                self::splitInline($inner)
            );
        }

        return self::scalar($value);
    }

    private static function scalar(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
