#!/usr/bin/env bash
#
# 从钉住的基线重建 PocketMine 的 mukkit fork。
#
# 为什么需要它：`mukkit-nukkit/build.gradle.kts` 从 `../PocketMine-MP` 取 fork 源码打进 jar，
# 而那棵树不在本仓库里 —— 干净的 clone 因此构建不出 jar。补丁文件
# （`vendor/pocketmine-fork/`）是那份源码的唯一出处，这个脚本把它物化出来。
#
# 幂等：每次都是"checkout 基线 + 重放补丁"，跑两遍结果一样。会重写目标目录的分支，
# 所以目标目录有未提交改动时直接拒绝，不 stash、不猜。
#
# 用法：
#   scripts/pm-fork.sh                      # 重建 ../PocketMine-MP
#   PM_FORK_DIR=/tmp/pm scripts/pm-fork.sh  # 放到别处（Gradle 仍只认 ../PocketMine-MP）
#   PM_FORK_UPSTREAM=/path/to/pmmp scripts/pm-fork.sh   # 离线：用本地仓库当上游
#
# 升级基线：改下面的 BASE（或设 PM_FORK_BASE），重打补丁，跑 L3。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

BASE="${PM_FORK_BASE:-6a7cc02e9dff59b69241aa0bcffdb9903ce86beb}"
UPSTREAM="${PM_FORK_UPSTREAM:-https://github.com/pmmp/PocketMine-MP.git}"
TARGET="${PM_FORK_DIR:-$ROOT/../PocketMine-MP}"
PATCH_DIR="$ROOT/vendor/pocketmine-fork"
BRANCH="mukkit-fork"

die() { printf 'FAIL  %s\n' "$*" >&2; exit 1; }
say() { printf '%s\n' "$*"; }

[ -d "$PATCH_DIR" ] || die "补丁目录不存在：$PATCH_DIR"

shopt -s nullglob
patches=("$PATCH_DIR"/*.patch)
[ "${#patches[@]}" -gt 0 ] || die "$PATCH_DIR 里没有 .patch"

if [ -e "$TARGET" ] && [ ! -d "$TARGET/.git" ]; then
    die "$TARGET 存在但不是 git 仓库"
fi

if [ ! -d "$TARGET/.git" ]; then
    say "== clone $UPSTREAM -> $TARGET"
    git clone --quiet --branch stable --single-branch "$UPSTREAM" "$TARGET" \
        || die "克隆失败：$UPSTREAM"
else
    if [ -n "$(git -C "$TARGET" status --porcelain)" ]; then
        die "$TARGET 有未提交改动。先提交或 stash —— 这个脚本会重写 $BRANCH 分支"
    fi
    say "== 复用已有仓库，更新引用"
    git -C "$TARGET" fetch --quiet origin || say "   (fetch 失败，用本地已有的引用继续)"
fi

if ! git -C "$TARGET" cat-file -e "$BASE^{commit}" 2>/dev/null; then
    say "== 本地没有基线 $BASE，去上游取"
    git -C "$TARGET" fetch --quiet origin stable || true
fi
git -C "$TARGET" cat-file -e "$BASE^{commit}" 2>/dev/null \
    || die "找不到基线 $BASE（是不是把 stable 分支换成别的了？）"

say "== 重置 $BRANCH 到基线 ${BASE:0:9}"
git -C "$TARGET" checkout --quiet -B "$BRANCH" "$BASE"

say "== 重放 ${#patches[@]} 个补丁"
if ! git -C "$TARGET" -c user.name="Mukkit fork" -c user.email="mukkit@localhost" \
        am --quiet --keep-cr "${patches[@]}"; then
    printf '\n补丁没有干净应用。看 git -C %s am --show-current-patch=diff，\n' "$TARGET" >&2
    printf '修好后 git -C %s am --abort 再重跑。\n' "$TARGET" >&2
    exit 1
fi

# 源码不够：jar 里还要 `vendor/`（composer 依赖），否则 PM 在用户机器上起不来。
# 缺了它 Gradle 只会默默打出一个 2 MB 的 jar —— 构建成功，运行即坏。
composer_path() {
    if [ -n "${COMPOSER_PHAR:-}" ]; then
        [ -f "$COMPOSER_PHAR" ] || return 1
        printf '%s\n' "$COMPOSER_PHAR"
        return 0
    fi
    if command -v composer >/dev/null 2>&1; then
        printf '%s\n' composer
        return 0
    fi
    if [ -f "$ROOT/.tools/composer.phar" ]; then
        printf '%s\n' "$ROOT/.tools/composer.phar"
        return 0
    fi
    if [ -f /tmp/composer.phar ]; then
        printf '%s\n' /tmp/composer.phar
        return 0
    fi
    return 1
}

if [ -f "$TARGET/vendor/autoload.php" ]; then
    say "== composer 依赖已就绪"
elif [ "${PM_FORK_SKIP_DEPS:-0}" = "1" ]; then
    say "== 跳过 composer 依赖（PM_FORK_SKIP_DEPS=1）：这样打出的 jar 起不了 PM"
else
    say "== 安装 composer 依赖（--no-dev --ignore-platform-reqs）"
    COMPOSER_BIN="$(composer_path)" || die \
        "找不到 composer。跑 scripts/fetch-composer.sh，或设 COMPOSER_PHAR=/path/to/composer.phar"
    if [ "$COMPOSER_BIN" = "composer" ]; then
        ( cd "$TARGET" && composer install --ignore-platform-reqs --no-dev \
              --no-interaction --no-progress ) || die "composer install 失败"
    else
        ( cd "$TARGET" && php "$COMPOSER_BIN" install --ignore-platform-reqs --no-dev \
              --no-interaction --no-progress ) || die "composer install 失败"
    fi
fi

say "== 完成：$(git -C "$TARGET" log --oneline -1)"
say "   目标：${TARGET}（分支 ${BRANCH}，基线 ${BASE:0:9} + ${#patches[@]} 个补丁）"
