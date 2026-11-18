#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Guards the polyfill/extension boundary for chunkutils2.
 *
 * `src/mukkit/polyfill/chunkutils2.php` only runs when the extension is *missing*, i.e. on a dev
 * box. The product ships the pmmp PHP, which has it. So a member invented in the polyfill is a
 * member that exists while developing and does not exist in production - and that is exactly how
 * the bridge used to wedge: the mirror called `PalettedBlockArray::SIZE` and `fromPackedData()`,
 * which only the polyfill ever had, so every dev run passed and the first block a player broke
 * died on `Undefined constant ... ::SIZE` on every tick, forever.
 *
 * Two things are checked, both of which would have caught it:
 *
 *   1. Static: no file under `src/mukkit/` may reference a `PalettedBlockArray::`/`LightArray::`
 *      member that ext-chunkutils2 does not have (comments are ignored).
 *   2. Reflective: whatever class is loaded - the polyfill here, or the real extension when this
 *      runs inside the bundled PHP - must expose only public members the extension has.
 *
 * Usage: php pm-host/tests/pm-chunkutils2-parity-test.php
 */

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';
$polyfill = $pmRoot . '/src/mukkit/polyfill/chunkutils2.php';

if (!is_file($polyfill)) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill/chunkutils2.php)\n");
    exit(1);
}

/**
 * Public surface of ext-chunkutils2 **0.3.5**, the version bundled in the pmmp PHP this product
 * ships (`phpversion("chunkutils2")`). Taken from ReflectionClass on that binary - note it is one
 * method smaller than the current master stub, which also has `setPalette()`.
 *
 * @var array<string, list<string>> class => public method names
 */
const EXTENSION_METHODS = [
    'pocketmine\world\format\PalettedBlockArray' => [
        '__construct', 'fromData', 'getWordArray', 'getPalette', 'getMaxPaletteSize',
        'getBitsPerBlock', 'get', 'set', 'replaceAll', 'collectGarbage', 'getExpectedWordArraySize',
    ],
    'pocketmine\world\format\LightArray' => [
        '__construct', 'fill', 'get', 'set', 'getData', 'collectGarbage', 'isUniform',
    ],
];

/**
 * What `src/mukkit/` actually calls - the mirror builds and diffs sections, `SubChunk` fills
 * light arrays lazily. Anything beyond this is optional on a dev box.
 *
 * @var array<string, list<string>>
 */
const BRIDGE_NEEDS = [
    'pocketmine\world\format\PalettedBlockArray' => ['__construct', 'get', 'set', 'getBitsPerBlock'],
    'pocketmine\world\format\LightArray' => ['__construct', 'fill', 'get', 'set', 'getData'],
];

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "ok    {$label}\n";
        return;
    }
    echo "FAIL  {$label}" . ($detail === '' ? '' : " ({$detail})") . "\n";
    $failures++;
};

$real = extension_loaded('chunkutils2');
echo 'using ' . ($real ? 'the real extension ' . phpversion('chunkutils2') : 'the userland polyfill') . "\n";

// ---------------------------------------------------------------- 1. static scan
/**
 * Class-name => member references in real code, comments and docblocks dropped.
 *
 * @return list<array{string, string, string, int}> [file, class, member, line]
 */
$references = static function (string $file): array {
    $found = [];
    $tokens = token_get_all((string) file_get_contents($file));
    $interesting = ['PalettedBlockArray', 'LightArray'];
    foreach ($tokens as $i => $token) {
        if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], $interesting, true)) {
            continue;
        }
        $colon = $tokens[$i + 1] ?? null;
        $member = $tokens[$i + 2] ?? null;
        if (!is_array($colon) || $colon[0] !== T_DOUBLE_COLON || !is_array($member) || $member[0] !== T_STRING) {
            continue;
        }
        if ($member[1] === 'class') {
            continue; // ClassName::class is a compile-time string, not a member
        }
        $found[] = [$file, $token[1], $member[1], $token[2]];
    }
    return $found;
};

$offenders = [];
foreach (glob($pmRoot . '/src/mukkit/*.php') ?: [] as $file) {
    foreach ($references($file) as [$path, $class, $member, $line]) {
        $allowed = EXTENSION_METHODS['pocketmine\world\format\\' . $class] ?? [];
        if (!in_array($member, $allowed, true)) {
            $offenders[] = sprintf('%s:%d %s::%s', basename($path), $line, $class, $member);
        }
    }
}
$check(
    'src/mukkit/ only names members ext-chunkutils2 also has',
    $offenders === [],
    implode(', ', $offenders)
);

// ---------------------------------------------------------------- 2. reflective
if (!$real) {
    require $polyfill;
}

foreach (EXTENSION_METHODS as $class => $allowed) {
    $reflection = new ReflectionClass($class);

    $publicMethods = [];
    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $publicMethods[] = $method->getName();
    }
    $extras = array_values(array_diff($publicMethods, $allowed));

    $check(
        $reflection->getShortName() . ' exposes no method the extension lacks',
        $extras === [],
        implode(', ', $extras)
    );

    $constants = [];
    foreach ($reflection->getReflectionConstants() as $constant) {
        if ($constant->isPublic()) {
            $constants[] = $constant->getName();
        }
    }
    $check(
        $reflection->getShortName() . ' exposes no constant (the extension has none)',
        $constants === [],
        implode(', ', $constants)
    );

    if ($real) {
        // An upstream removal or rename would break a bridge call only in production - say so here.
        $missing = array_values(array_diff($allowed, $publicMethods));
        $check($reflection->getShortName() . ' still has every recorded 0.3.5 member', $missing === [], implode(', ', $missing));
    } else {
        // The other direction is allowed - the polyfill may lack disk/network members - but the
        // calls the bridge actually makes must be there, or a dev box stops resembling production.
        $needed = BRIDGE_NEEDS[$class] ?? [];
        $absent = array_values(array_diff($needed, $publicMethods));
        $check($reflection->getShortName() . ' has what the bridge builds chunks with', $absent === [], implode(', ', $absent));
    }
}

// ---------------------------------------------------------------- 3. behaviour both sides must agree on
$air = 20481500; // Block::EMPTY_STATE_ID, PocketMine 5 numbering
$section = new pocketmine\world\format\PalettedBlockArray($air);
$check('a fresh section reports the uniform shape', $section->getBitsPerBlock() === 0, (string) $section->getBitsPerBlock());
$check('a fresh section reads air everywhere', $section->get(3, 4, 5) === $air, (string) $section->get(3, 4, 5));
$check('a fresh section has an empty word array', $section->getWordArray() === '', (string) strlen($section->getWordArray()));

$section->set(1, 2, 3, 5);
$check('a write is read back', $section->get(1, 2, 3) === 5, (string) $section->get(1, 2, 3));
$check('a write leaves the neighbours alone', $section->get(0, 2, 3) === $air, (string) $section->get(0, 2, 3));
$check('a write makes the section non-uniform', $section->getBitsPerBlock() !== 0, (string) $section->getBitsPerBlock());
$check('a write lands in the palette', in_array(5, $section->getPalette(), true), (string) json_encode($section->getPalette()));

$lights = pocketmine\world\format\LightArray::fill(15);
$check('a filled light array reads back', $lights->get(15, 15, 15) === 15, (string) $lights->get(15, 15, 15));
$check('light data is 2048 bytes', strlen($lights->getData()) === 2048, (string) strlen($lights->getData()));

if ($failures > 0) {
    echo "\n{$failures} failure(s)\n";
    exit(1);
}
echo "\nchunkutils2-parity PASS\n";
