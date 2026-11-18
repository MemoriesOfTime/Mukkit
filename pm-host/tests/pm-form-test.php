#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Form acceptance: a form shown by a PocketMine plugin can be answered.
 *
 * The frames below are what the adapter sends when a client answers a form. The plugin
 * (examples/pm-form-probe) owns its form classes - PocketMine 5 ships only the `Form` interface -
 * so what is being asserted is the bridge's whole contract, not a particular library's:
 *
 *   `Player::sendForm()`  -> a `ModalFormRequestPacket` op with an id from the bridge's range
 *   a response frame      -> `Form::handleResponse()` with the decoded answer
 *   a dismissal           -> `handleResponse(null)`
 *
 * The negative cases matter as much as the happy one: an answer to a form that already resolved,
 * a response that is not JSON, one that is too large. None of them may take the host down, and
 * none of them may leave a form pending forever.
 *
 * Usage: php pm-host/tests/pm-form-test.php
 */

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\PmProfile;

$root = dirname(__DIR__, 2);
$pmRoot = getenv('MUKKIT_PM_ROOT') ?: dirname($root) . '/PocketMine-MP';

if (!is_file($pmRoot . '/src/mukkit/polyfill.php') || !is_file($pmRoot . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL  fork not usable at {$pmRoot} (need src/mukkit/polyfill.php + vendor/autoload.php)\n");
    exit(1);
}

$base = $root . '/run/pm-form';
$plugins = $base . '/plugins';
$data = $base . '/data';
$phar = $plugins . '/MukkitFormProbe.phar';

$rmrf = static function (string $path) use (&$rmrf): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
};
$rmrf($base);
mkdir($plugins, 0777, true);
mkdir($data, 0777, true);

passthru(
    'bash ' . escapeshellarg($root . '/scripts/build-plugin-phar.sh')
    . ' ' . escapeshellarg($root . '/examples/pm-form-probe')
    . ' ' . escapeshellarg($phar),
    $buildCode
);
if (($buildCode ?? 1) !== 0) {
    fwrite(STDERR, "FAIL  cannot build MukkitFormProbe.phar\n");
    exit(1);
}

$failures = 0;
$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    if ($ok) {
        echo "ok    {$label}\n";
        return;
    }
    echo "FAIL  {$label}" . ($detail === '' ? '' : " ({$detail})") . "\n";
    $failures++;
};

/** Everything a human would read in the log: plugin log lines and text packets alike. */
$messages = static function (array $ops): string {
    $lines = [];
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'log') {
            $lines[] = (string) ($op['msg'] ?? '');
        } elseif (($op['o'] ?? '') === 'packet' && ($op['cls'] ?? '') === 'TextPacket') {
            $lines[] = (string) ($op['f']['message'] ?? '');
        }
    }
    return implode("\n", $lines);
};

/** The form request the host sent this tick, or null. */
$formOp = static function (array $ops): ?array {
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'packet' && ($op['cls'] ?? '') === 'ModalFormRequestPacket') {
            return $op;
        }
    }
    return null;
};

$formCount = static function (array $ops): int {
    $count = 0;
    foreach ($ops as $op) {
        if (($op['o'] ?? '') === 'packet' && ($op['cls'] ?? '') === 'ModalFormRequestPacket') {
            $count++;
        }
    }
    return $count;
};

$uuid = '11111111-2222-3333-4444-555555555555';
$profile = new PmProfile($pmRoot, $data, $plugins);
$result = $profile->load([['name' => 'MukkitFormProbe', 'path' => $phar]]);

$names = array_map(static fn (array $p): string => (string) ($p['name'] ?? ''), $result['loaded']);
$check('form probe phar loaded by PM\'s own PluginManager', in_array('MukkitFormProbe', $names, true), implode(',', $names));
$check(
    'forms need no event demand: a menu plugin has no listeners',
    $profile->eventDemand() === [],
    (string) json_encode($profile->eventDemand())
);

$profile->tick([[
    'k' => 'event',
    'name' => 'player.join',
    'player' => $uuid,
    'data' => ['name' => 'Steve', 'x' => 0.5, 'y' => 64.0, 'z' => 0.5, 'yaw' => 0.0, 'pitch' => 0.0, 'world' => 'world'],
]]);

$command = static function (string $line) use ($profile): array {
    return $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => $line]]);
};

$respond = static function (int $formId, string $payload, bool $closed = false) use ($profile, $uuid): array {
    return $profile->tick([[
        'k' => 'form.response',
        'player' => $uuid,
        'formId' => $formId,
        'closed' => $closed,
        'data' => $payload,
    ]]);
};

// ---------------------------------------------------------------- showing a form
$ops = $command('form-open Steve');
$text = $messages($ops);
$check('the command reaches the player', str_contains($text, 'form-open done'), $text);

$op = $formOp($ops);
$check('one form request goes out', $formCount($ops) === 1, (string) $formCount($ops));
$check('the request addresses the player Nukkit knows', ($op['to'] ?? '') === $uuid, (string) ($op['to'] ?? ''));

$formId = (int) ($op['f']['formId'] ?? -1);
$check(
    'the form id comes from the bridge range, clear of Nukkit\'s own counter',
    $formId >= 1_000_000,
    (string) $formId
);

$body = json_decode((string) ($op['f']['formData'] ?? ''), true);
$check('the payload is the form\'s own JSON, untouched', ($body['type'] ?? '') === 'form', (string) ($op['f']['formData'] ?? ''));
$check('with its title and both buttons', ($body['title'] ?? '') === 'Mukkit simple probe'
    && count($body['buttons'] ?? []) === 2, (string) ($op['f']['formData'] ?? ''));

// ---------------------------------------------------------------- answering it
$ops = $respond($formId, '1');
$text = $messages($ops);
$check('the button index reaches the plugin callback', str_contains($text, 'simple: index=1'), $text);
$check('and the plugin decided what the index means', !str_contains($text, 'simple: closed'), $text);

$ops = $respond($formId, '1');
$text = $messages($ops);
$check('a second answer to the same form runs nothing', !str_contains($text, 'simple:'), $text);
$check(
    'and is reported as unexpected rather than silently dropped',
    str_contains($text, 'nothing pending for form ' . $formId),
    $text
);

// ---------------------------------------------------------------- a form with typed elements
$ops = $command('form-custom Steve');
$op = $formOp($ops);
$customId = (int) ($op['f']['formId'] ?? -1);
$check('the second form gets a fresh id', $customId > $formId, "{$formId} -> {$customId}");

$customBody = json_decode((string) ($op['f']['formData'] ?? ''), true);
$check(
    'a custom form travels as its element list',
    // Bedrock's wire value is `custom_form`; the old `custom` was rejected by a real client
    // and the probe now writes the protocol value.
    ($customBody['type'] ?? '') === 'custom_form' && count($customBody['content'] ?? []) === 4,
    (string) ($op['f']['formData'] ?? '')
);

// A dropdown answers with the selected index, an input with its text, a toggle with a bool and a
// slider with a number - four different types in one array, which is the whole reason this form
// exists as a test case.
$answer = [1, 'Bobby', true, 4];
$ops = $respond($customId, (string) json_encode($answer));
$text = $messages($ops);
$check(
    'every element value arrives with its type intact',
    str_contains($text, 'custom: [1,"Bobby",true,4]'),
    $text
);

// ---------------------------------------------------------------- dismissing
$ops = $command('form-open Steve');
$dismissId = (int) ($formOp($ops)['f']['formId'] ?? -1);
$ops = $respond($dismissId, 'null', true);
$text = $messages($ops);
$check('a dismissal arrives as a null response', str_contains($text, 'simple: closed'), $text);

// ---------------------------------------------------------------- refusals
// Valid JSON, wrong shape: the plugin's own validation must run, and the host must survive it.
$ops = $command('form-open Steve');
$badShapeId = (int) ($formOp($ops)['f']['formId'] ?? -1);
$ops = $respond($badShapeId, '"not-an-index"');
$text = $messages($ops);
$check('a well-formed answer of the wrong type reaches the plugin\'s validation', !str_contains($text, 'simple: index='), $text);
$check(
    'and the failure is attributed to the form, not to the bridge',
    str_contains($text, 'Failed to validate form'),
    $text
);

$ops = $command('form-open Steve');
$brokenId = (int) ($formOp($ops)['f']['formId'] ?? -1);
$ops = $respond($brokenId, '{"x":');
$text = $messages($ops);
$check('undecodable JSON is reported, not guessed at', str_contains($text, 'not decodable JSON'), $text);
$check('and the form is released as closed instead of hanging', str_contains($text, 'simple: closed'), $text);

$ops = $command('form-open Steve');
$hugeId = (int) ($formOp($ops)['f']['formId'] ?? -1);
$ops = $respond($hugeId, (string) json_encode(['x' => str_repeat('a', 12 * 1024)]));
$text = $messages($ops);
$check('an oversized answer is refused before it is decoded', str_contains($text, 'over the 10240-byte limit'), $text);
$check('and that form is released too', str_contains($text, 'simple: closed'), $text);

$ops = $profile->tick([[
    'k' => 'form.response',
    'player' => '99999999-0000-0000-0000-000000000000',
    'formId' => 1000000,
    'closed' => false,
    'data' => '0',
]]);
$check(
    'an answer for an unknown player is reported once',
    str_contains($messages($ops), 'no bridged player for 99999999'),
    $messages($ops)
);

// After all of that the host must still be able to show and resolve a form: the failure modes
// above are recoverable, not fatal.
$ops = $command('form-open Steve');
$lastId = (int) ($formOp($ops)['f']['formId'] ?? -1);
$ops = $respond($lastId, '0');
$check('the host is still serving forms after every refusal', str_contains($messages($ops), 'simple: index=0'), $messages($ops));

// ---------------------------------------------------------------- closing
$ops = $command('form-close Steve');
$closed = false;
foreach ($ops as $op) {
    if (($op['o'] ?? '') === 'packet' && ($op['cls'] ?? '') === 'ClientboundCloseFormPacket') {
        $closed = true;
    }
}
$check('closeAllForms() becomes a close-form packet', $closed, (string) json_encode($ops));

$profile->shutdown();

echo $failures === 0 ? "forms PASS\n" : "forms FAILED ({$failures})\n";
exit($failures === 0 ? 0 : 1);
