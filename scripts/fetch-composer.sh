#!/usr/bin/env bash
#
# 把 Composer 下到仓库内的 .tools/composer.phar（不装到系统 PATH，也不改全局配置）。
#
# 为什么需要它：PM fork 的 `vendor/` 是构建 jar 的硬依赖，而干净机器上通常没有 composer。
# 下载的 installer 先用官方签名核对，再执行；失败时给出可复现的手工步骤。
#
# 用法：
#   bash scripts/fetch-composer.sh                 # 输出 .tools/composer.phar 的路径
#   COMPOSER_PHAR=/path/to/composer.phar bash scripts/fetch-composer.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${COMPOSER_PHAR:-$ROOT/.tools/composer.phar}"

die() { printf 'FAIL  %s\n' "$*" >&2; exit 1; }

if [ -f "$DEST" ]; then
    php "$DEST" --version >/dev/null 2>&1 || die "已有的 Composer 不能运行：$DEST"
    printf '%s\n' "$DEST"
    exit 0
fi

command -v php >/dev/null 2>&1 || die "需要 PHP 8.1+ 才能运行 Composer"
command -v curl >/dev/null 2>&1 || die "需要 curl 才能下载 Composer"
php -r 'exit(extension_loaded("phar") ? 0 : 1);' \
    || die "当前 PHP 没有 phar 扩展，Composer 无法运行（Debian/Ubuntu 装 php-cli 会带）"

mkdir -p "$(dirname "$DEST")"
setup="$(mktemp "${TMPDIR:-/tmp}/composer-setup.XXXXXX")"
trap 'rm -f "$setup"' EXIT

printf '== 下载 Composer installer\n'
curl -fsSL --max-time 120 https://getcomposer.org/installer -o "$setup" \
    || die "下载 installer 失败；检查网络或 MUKKIT_PROXY"
expected="$(curl -fsSL --max-time 30 https://composer.github.io/installer.sig)" \
    || die "下载签名失败"
actual="$(php -r 'echo hash_file("sha384", $argv[1]);' "$setup")"
if [ "$expected" != "$actual" ]; then
    die "Composer installer 签名不匹配（expected=$expected actual=$actual）"
fi
printf '== 签名校验通过\n'

php "$setup" --install-dir="$(dirname "$DEST")" --filename="$(basename "$DEST")" --quiet \
    || die "Composer 安装失败"
php "$DEST" --version >/dev/null 2>&1 || die "装好的 Composer 不能运行：$DEST"

printf '%s\n' "$DEST"
