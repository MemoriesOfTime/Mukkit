#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Mukkit\Host\Host;
use Mukkit\Host\PluginProfile;
use Mukkit\Host\PmProfile;
use Mukkit\Host\Profile;
use Mukkit\Host\StubProfile;
use Mukkit\Host\Transport;

$options = getopt('', ['socket:', 'profile::', 'fault::']);

$socket = $options['socket'] ?? null;
if (!is_string($socket) || $socket === '') {
    fwrite(STDERR, "mukkit-host: --socket=PATH or --socket=tcp://HOST:PORT is required\n");
    exit(64);
}

$profileName = $options['profile'] ?? 'stub';
if (!is_string($profileName)) {
    $profileName = 'stub';
}
$fault = $options['fault'] ?? null;
if (!is_string($fault) || $fault === '') {
    $fault = null;
}

$profile = match ($profileName) {
    'stub' => new StubProfile(),
    'plugin' => new PluginProfile(),
    'pm' => new PmProfile(),
    default => null,
};
if (!$profile instanceof Profile) {
    fwrite(STDERR, "mukkit-host: unknown profile '{$profileName}'\n");
    exit(64);
}

try {
    $transport = Transport::listen($socket);
} catch (Throwable $e) {
    fwrite(STDERR, 'mukkit-host: ' . $e->getMessage() . "\n");
    exit(1);
}

$code = 0;
try {
    $code = (new Host($transport, $profile, $fault))->run();
} catch (Throwable $e) {
    fwrite(STDERR, 'mukkit-host: ' . $e->getMessage() . "\n");
    $code = 1;
}

$transport->close();
exit($code);
