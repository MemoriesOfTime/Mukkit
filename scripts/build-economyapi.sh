#!/usr/bin/env bash
#
# 取一份第三方 EconomyAPI（PocketMine API 5 维护版）并打成 phar，供无头 boot 验收用。
#
# 为什么钉 commit：这是外部依赖，上游随时会变。验收要能重复，就得钉住一个已知能跑的版本。
# 换版本就改 ECON_API_COMMIT，并重新跑 scripts/run-m2.sh 看断言。
#
# 产物：run/m2/plugins/EconomyAPI.phar
set -euo pipefail

cd "$(dirname "$0")/.."

ECON_API_REPO="${ECON_API_REPO:-https://github.com/imjustnozell/EconomyAPI-Dev}"
ECON_API_COMMIT="${ECON_API_COMMIT:-7ae5845}"
SRC="${ECON_API_SRC:-run/thirdparty/economyapi}"
OUT=run/m2/plugins
PHAR="$OUT/EconomyAPI.phar"

mkdir -p "$OUT"

if [ ! -d "$SRC/.git" ]; then
    mkdir -p "$(dirname "$SRC")"
    git clone -q "$ECON_API_REPO" "$SRC"
fi

git -C "$SRC" fetch -q origin "$ECON_API_COMMIT" 2>/dev/null || git -C "$SRC" fetch -q origin
git -C "$SRC" checkout -q "$ECON_API_COMMIT"

rm -f "$PHAR"
php -d phar.readonly=0 -r '
$src = $argv[1];
$out = $argv[2];
$phar = new Phar($out);
$phar->startBuffering();
foreach(["plugin.yml"] as $f){
    $phar->addFile($src . "/" . $f, $f);
}
foreach(["src", "resources"] as $dir){
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . "/" . $dir, FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        $path = $file->getPathname();
        $phar->addFile($path, substr($path, strlen($src) + 1));
    }
}
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->setStub("<?php __HALT_COMPILER();\n");
$phar->stopBuffering();
' "$SRC" "$PHAR"

printf 'built %s (%s bytes) from %s@%s\n' "$PHAR" "$(wc -c < "$PHAR" | tr -d ' ')" "$ECON_API_REPO" "$ECON_API_COMMIT"
