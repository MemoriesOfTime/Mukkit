#!/usr/bin/env bash
#
# 干净 clone 的外部依赖准备：PocketMine fork、Composer 依赖、PHP 运行时。
# Nukkit-MOT 不在这里准备：Gradle 直接按坐标从 repo.lanink.cn 解析。
# 这个脚本由 Gradle 的 bootstrap task 调用；Java 构建本身由 Gradle 继续完成。
#
# 它做的三件事：
#   1. 检查 JDK 21 / PHP 8.1+ / git / curl
#   2. 用 vendor/pocketmine-fork/*.patch 重建 ../PocketMine-MP，并 composer install
#   3. 下载当前平台的 pmmp PHP 到 vendor/php/<platform>（可 --no-php 跳过）
#
# 幂等：已经就绪的步骤会跳过。不会 git reset 你的仓库；目标目录有未提交改动时直接拒绝。
#
# 用法：
#   ./gradlew bootstrap                  # 推荐入口：准备外部依赖 + Gradle 构建 + 架构机检
#   scripts/bootstrap.sh                 # 只准备外部依赖
#   scripts/bootstrap.sh --no-php        # 不下载自带 PHP（构建瘦 jar，用系统 PHP）
#   scripts/bootstrap.sh --skip-pm       # 已有可用的 ../PocketMine-MP + vendor
#   scripts/bootstrap.sh --force         # 强制重建 PocketMine fork 与当前平台 PHP
#
# 环境变量：
#   PM_FORK_DIR          PocketMine fork 目录，默认 ../PocketMine-MP
#   PM_FORK_UPSTREAM     传给 scripts/pm-fork.sh 的上游（离线时可指向本地仓库）
#   COMPOSER_PHAR        现成的 composer.phar；不设时优先 PATH 里的 composer，再下载到 .tools/
#   MUKKIT_PROXY         下载 GitHub release 时用的代理（见 scripts/fetch-php.sh）
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PM_DIR="${PM_FORK_DIR:-$ROOT/../PocketMine-MP}"

WITH_PHP=1
SKIP_PM=0
FORCE=0

die() { printf 'FAIL  %s\n' "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --no-php) WITH_PHP=0 ;;
        --skip-pm) SKIP_PM=1 ;;
        --force) FORCE=1 ;;
        -h|--help)
            sed -n '2,/^set -euo pipefail$/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'
            exit 0
            ;;
        *) die "unknown option: $1" ;;
    esac
    shift
done

need() { command -v "$1" >/dev/null 2>&1 || die "缺少 $1；先安装它再重跑"; }
need git
need java
need php
need curl

java_major="$(java -version 2>&1 | sed -n 's/.*version "\([0-9][0-9]*\).*/\1/p' | head -1)"
[ -n "$java_major" ] || die "读不出 Java 版本；需要 JDK 21+"
[ "$java_major" -ge 21 ] || die "需要 JDK 21+（Nukkit-MOT 含 release 21 的 MR-JAR），当前是 $java_major"

php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' \
    || die "需要 PHP 8.1+（当前：$(php -v 2>/dev/null | head -1)）"

platform_id() {
    case "$(uname -s)-$(uname -m)" in
        Darwin-arm64) echo macos-arm64 ;;
        Darwin-x86_64) echo macos-x86_64 ;;
        Linux-x86_64) echo linux-x86_64 ;;
        MINGW*|MSYS*|CYGWIN*) echo windows-x64 ;;
        *) echo "" ;;
    esac
}

# ---------------------------------------------------------------------------
# 1. Nukkit-MOT：不在这里准备
# ---------------------------------------------------------------------------
say "== Nukkit-MOT 由 Gradle 从 repo.lanink.cn 解析（不本地构建）"

# ---------------------------------------------------------------------------
# 2. PocketMine fork：patch + composer 依赖
# ---------------------------------------------------------------------------
if [ "$SKIP_PM" -eq 1 ]; then
    [ -f "$PM_DIR/vendor/autoload.php" ] \
        || die "--skip-pm 但 $PM_DIR/vendor/autoload.php 不存在"
    say "== 跳过 PocketMine fork 重建"
else
    if [ "$FORCE" -eq 0 ] && [ -f "$PM_DIR/vendor/autoload.php" ] \
        && [ -f "$PM_DIR/src/mukkit/polyfill.php" ]; then
        say "== PocketMine fork 已就绪"
    else
        if ! command -v composer >/dev/null 2>&1 && [ -z "${COMPOSER_PHAR:-}" ] \
            && [ ! -f "$ROOT/.tools/composer.phar" ]; then
            say "== 没有 composer，下载到 .tools/"
            bash scripts/fetch-composer.sh >/dev/null \
                || die "Composer 准备失败；也可以自己装 composer 后重跑"
        fi
        say "== 重建 PocketMine fork 并安装依赖"
        bash scripts/pm-fork.sh || die "PocketMine fork 准备失败"
    fi
fi

# ---------------------------------------------------------------------------
# 3. 当前平台的 pmmp PHP（可选，但产品判据需要）
# ---------------------------------------------------------------------------
if [ "$WITH_PHP" -eq 1 ]; then
    platform="$(platform_id)"
    if [ -z "$platform" ]; then
        say "WARN 无法识别平台，跳过自带 PHP；需要时手动跑 scripts/fetch-php.sh --platform <id>"
    elif [ "$FORCE" -eq 0 ] && [ -f "$ROOT/vendor/php/$platform/.version" ]; then
        say "== 自带 PHP 已就绪（${platform}）"
    else
        say "== 下载 pmmp PHP（${platform}）"
        if ! bash scripts/fetch-php.sh; then
            say "WARN 自带 PHP 下载失败：会构建瘦 jar，退回系统 PHP"
            say "     需要产品判据（run-product.sh）时重跑 scripts/fetch-php.sh"
        fi
    fi
else
    say "== 跳过自带 PHP（--no-php）：构建瘦 jar，使用系统 PHP"
fi

say ""
say "== 外部依赖准备完成（Java 构建由 ./gradlew bootstrap 的 build task 继续）"
