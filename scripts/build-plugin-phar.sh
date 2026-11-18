#!/usr/bin/env bash
#
# 把一个 PM 插件源码目录打成 phar：插件目录 / plugin.yml + src/ 原样进包。
#
# 用法：scripts/build-plugin-phar.sh <src-dir> <out.phar>
# 用途：玩家代理验收插件（examples/pm-probe）与第三方插件（build-economyapi.sh 有自己的一份，
# 因为还要钉 commit 拉源码）。这里只做打包，不管来源。
set -euo pipefail

if [ "$#" -ne 2 ]; then
    printf 'usage: %s <src-dir> <out.phar>\n' "$0" >&2
    exit 2
fi

SRC=$1
OUT=$2

if [ ! -f "$SRC/plugin.yml" ]; then
    printf 'FAIL  %s has no plugin.yml\n' "$SRC" >&2
    exit 1
fi

mkdir -p "$(dirname "$OUT")"
rm -f "$OUT"

php -d phar.readonly=0 -r '
$src = $argv[1];
$out = $argv[2];
$phar = new Phar($out);
$phar->startBuffering();
$phar->addFile($src . "/plugin.yml", "plugin.yml");
if(is_dir($src . "/src")){
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src . "/src", FilesystemIterator::SKIP_DOTS));
    foreach($it as $file){
        $path = $file->getPathname();
        $phar->addFile($path, substr($path, strlen($src) + 1));
    }
}
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->setStub("<?php __HALT_COMPILER();\n");
$phar->stopBuffering();
' "$SRC" "$OUT"

printf 'built %s (%s bytes) from %s\n' "$OUT" "$(wc -c < "$OUT" | tr -d ' ')" "$SRC"
