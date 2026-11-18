#!/usr/bin/env bash
#
# 把 examples/stub-chat 打成 examples/stub-chat/build/StubChat.phar。
# 只装 plugin.yml 与 main.php，且放在 phar 根目录（不额外套一层目录）。
set -euo pipefail

cd "$(dirname "$0")/.."

src="examples/stub-chat"
out="$src/build"
phar="$out/StubChat.phar"

for file in plugin.yml main.php; do
    if [ ! -f "$src/$file" ]; then
        echo "missing $src/$file" >&2
        exit 1
    fi
done

rm -f "$phar"
mkdir -p "$out"

php -d phar.readonly=0 -r '
[$src, $out] = [$argv[1], $argv[2]];
$phar = new Phar($out);
$phar->startBuffering();
foreach (["plugin.yml", "main.php"] as $file) {
    $phar->addFile($src . "/" . $file, $file);
}
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->setStub("<?php __HALT_COMPILER();\n");
$phar->stopBuffering();
echo "built " . $out . PHP_EOL;
' -- "$src" "$phar"
