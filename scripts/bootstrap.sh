#!/usr/bin/env bash
#
# 干净 clone 的外部依赖准备：Nukkit-MOT、PocketMine fork、Composer 依赖、PHP 运行时。
# 这个脚本由 Gradle 的 bootstrap task 调用；Java 构建本身由 Gradle 继续完成。
#
# 它做的四件事：
#   1. 检查 JDK 21 / Maven / PHP 8.1+ / git / curl
#   2. 把 Nukkit-MOT clone 到 ../Nukkit-MOT，checkout 到钉住的 commit 并构建
#   3. 用 vendor/pocketmine-fork/*.patch 重建 ../PocketMine-MP，并 composer install
#   4. 下载当前平台的 pmmp PHP 到 vendor/php/<platform>（可 --no-php 跳过）
#
# 幂等：已经就绪的步骤会跳过。不会 git reset 你的仓库；目标目录有未提交改动时直接拒绝。
#
# 用法：
#   ./gradlew bootstrap                  # 推荐入口：准备外部依赖 + Gradle 构建 + 架构机检
#   scripts/bootstrap.sh                 # 只准备外部依赖
#   scripts/bootstrap.sh --no-php        # 不下载自带 PHP（构建瘦 jar，用系统 PHP）
#   scripts/bootstrap.sh --skip-nukkit   # 已有可用的 ../Nukkit-MOT/target/*.jar
#   scripts/bootstrap.sh --skip-pm       # 已有可用的 ../PocketMine-MP + vendor
#   scripts/bootstrap.sh --force         # 强制重建 Nukkit-MOT 与当前平台 PHP
#
# 环境变量：
#   MUKKIT_NUKKIT_JAR    现成的 Nukkit-MOT jar（跳过 clone/build）
#   NUKKIT_MOT_DIR       Nukkit-MOT 目录，默认 ../Nukkit-MOT
#   NUKKIT_MOT_REPO      默认 https://github.com/MemoriesOfTime/Nukkit-MOT.git
#   NUKKIT_MOT_COMMIT    默认 de21c74a4d5d7c58c652d7b7b4e352eff5223cd8
#   PM_FORK_DIR          PocketMine fork 目录，默认 ../PocketMine-MP
#   PM_FORK_UPSTREAM     传给 scripts/pm-fork.sh 的上游（离线时可指向本地仓库）
#   COMPOSER_PHAR        现成的 composer.phar；不设时优先 PATH 里的 composer，再下载到 .tools/
#   MUKKIT_PROXY         下载 GitHub release 时用的代理（见 scripts/fetch-php.sh）
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

NUKKIT_DIR="${NUKKIT_MOT_DIR:-$ROOT/../Nukkit-MOT}"
NUKKIT_REPO="${NUKKIT_MOT_REPO:-https://github.com/MemoriesOfTime/Nukkit-MOT.git}"
NUKKIT_COMMIT="${NUKKIT_MOT_COMMIT:-de21c74a4d5d7c58c652d7b7b4e352eff5223cd8}"
PM_DIR="${PM_FORK_DIR:-$ROOT/../PocketMine-MP}"

WITH_PHP=1
SKIP_NUKKIT=0
SKIP_PM=0
FORCE=0

die() { printf 'FAIL  %s\n' "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }

while [ $# -gt 0 ]; do
    case "$1" in
        --no-php) WITH_PHP=0 ;;
        --skip-nukkit) SKIP_NUKKIT=1 ;;
        --skip-pm) SKIP_PM=1 ;;
        --force) FORCE=1 ;;
        -h|--help)
            sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
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
# 1. Nukkit-MOT：源码 + 构建
# ---------------------------------------------------------------------------
if [ -n "${MUKKIT_NUKKIT_JAR:-}" ]; then
    [ -f "$MUKKIT_NUKKIT_JAR" ] || die "MUKKIT_NUKKIT_JAR 不存在：$MUKKIT_NUKKIT_JAR"
    NUKKIT_JAR="$MUKKIT_NUKKIT_JAR"
    say "== 使用指定的 Nukkit jar：$NUKKIT_JAR"
elif [ "$SKIP_NUKKIT" -eq 1 ]; then
    NUKKIT_JAR="$(bash scripts/nukkit-jar.sh)" || die "没有可用的 Nukkit jar"
    say "== 跳过 Nukkit-MOT 构建，使用 $NUKKIT_JAR"
else
    if [ ! -e "$NUKKIT_DIR" ]; then
        say "== clone Nukkit-MOT -> $NUKKIT_DIR"
        git clone --quiet "$NUKKIT_REPO" "$NUKKIT_DIR" \
            || die "克隆失败：$NUKKIT_REPO"
    elif [ ! -d "$NUKKIT_DIR/.git" ]; then
        die "$NUKKIT_DIR 存在但不是 git 仓库"
    fi

    if [ -n "$(git -C "$NUKKIT_DIR" status --porcelain)" ]; then
        die "$NUKKIT_DIR 有未提交改动。先提交/stash，或设置 MUKKIT_NUKKIT_JAR 指向现成 jar"
    fi

    if ! git -C "$NUKKIT_DIR" cat-file -e "$NUKKIT_COMMIT^{commit}" 2>/dev/null; then
        say "== 本地没有基线 $NUKKIT_COMMIT，去上游取"
        git -C "$NUKKIT_DIR" fetch --quiet origin \
            || git -C "$NUKKIT_DIR" fetch --quiet "$NUKKIT_REPO" \
            || true
    fi
    git -C "$NUKKIT_DIR" cat-file -e "$NUKKIT_COMMIT^{commit}" 2>/dev/null \
        || die "找不到 Nukkit-MOT commit $NUKKIT_COMMIT"

    NUKKIT_JAR="$NUKKIT_DIR/target/Nukkit-MOT-SNAPSHOT.jar"
    current="$(git -C "$NUKKIT_DIR" rev-parse HEAD)"
    if [ "$FORCE" -eq 1 ] || [ "$current" != "$NUKKIT_COMMIT" ] || [ ! -f "$NUKKIT_JAR" ]; then
        command -v mvn >/dev/null 2>&1 \
            || die "缺少 mvn：构建 Nukkit-MOT 需要 Maven 3.6+；或设 MUKKIT_NUKKIT_JAR 指向现成 jar"
        say "== checkout Nukkit-MOT ${NUKKIT_COMMIT:0:9}"
        git -C "$NUKKIT_DIR" checkout --quiet --detach "$NUKKIT_COMMIT" \
            || die "checkout 失败"
        say "== build Nukkit-MOT（首次会下载 Maven 依赖）"
        ( cd "$NUKKIT_DIR" && mvn -B -DskipTests package ) \
            || die "Nukkit-MOT 构建失败"
    else
        say "== Nukkit-MOT 已构建（${NUKKIT_COMMIT:0:9}）"
    fi
fi

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
