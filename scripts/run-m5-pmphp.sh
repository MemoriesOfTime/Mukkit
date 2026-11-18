#!/usr/bin/env bash
#
# M5 的第二个运行时：同一批宿主判据，在**自带的 pmmp PHP**（带 ext-chunkutils2）上再跑一遍。
#
# 为什么要有这一条：本机 PHP 没有 chunkutils2，桥于是走 `src/mukkit/polyfill/`；产品跑的却是
# 扩展。成员表由 `pm-host/tests/pm-chunkutils2-parity-test.php` 守着，但"成员一样"不等于
# "行为一样"，所以唯一诚实的验证是把同一份 `pm-world-test.php` 放到真 PHP 上再跑。
#
# 这条判据守的是"垫片与扩展行为一致"：只比对成员表不够，还要在两份 PHP 上各跑一次。
# 细节见 docs/design/pm-fork.md 的"垫片层"。
#
# 用法：bash scripts/run-m5-pmphp.sh
#   前置 1：跑过一次真实服（`scripts/docker-serve.sh`），jar 会把自带运行时解到
#           run/docker-real/plugins/Mukkit/php/，这条判据就用它 —— 产品用的哪份 PHP，测的就是哪份
#   前置 2：跑过 `scripts/run-m5.sh`，生成 run/m5/chunk.json
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DATA="${MUKKIT_SERVER_DIR:-$ROOT/run/docker-real}"
PHP_DIR="$DATA/plugins/Mukkit/php/linux-x86_64/bin/php7/bin"
FIXTURE="${MUKKIT_M5_CHUNK:-run/m5/chunk.json}"
IMAGE="${SERVER_IMAGE:-mukkit-server}"
PLATFORM=linux/amd64

if ! docker info >/dev/null 2>&1; then
    printf '错误：Docker 不可用。先启动 Docker Desktop，确认 `docker info` 能返回。\n' >&2
    exit 1
fi

if [ ! -x "$PHP_DIR/php" ]; then
    printf 'FAIL 找不到自带运行时：%s\n' "$PHP_DIR" >&2
    printf '      跑一次 `bash scripts/docker-serve.sh` 让 jar 解出 PHP，再来。\n' >&2
    exit 1
fi

if [ ! -f "$FIXTURE" ]; then
    printf 'FAIL 找不到区块 fixture：%s\n' "$FIXTURE" >&2
    printf '      先跑 `bash scripts/run-m5.sh`，它会用 JVM 侧编码器生成一份。\n' >&2
    exit 1
fi

if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
    printf 'FAIL 镜像 %s 不存在，先跑一次 `bash scripts/docker-serve.sh`。\n' "$IMAGE" >&2
    exit 1
fi

WORKSPACE_ROOT="$(cd "$ROOT/.." && pwd)"
DATA_REL="${DATA#"$WORKSPACE_ROOT"/}"
PHP_IN_CONTAINER="/work/$DATA_REL/plugins/Mukkit/php/linux-x86_64/bin/php7/bin"

printf '== M5（自带 pmmp PHP）：%s ==\n' "$PHP_IN_CONTAINER"

# 这个断言不是走形式：镜像里退回系统 PHP 的话，这条判据会静默地又测一遍垫片 —— 正是它要防的。
preflight='if(!extension_loaded("chunkutils2")){fwrite(STDERR, "FAIL 这个 PHP 没有 chunkutils2，等于又测了一遍垫片\n"); exit(1);} printf("php %s / chunkutils2 %s\n", PHP_VERSION, phpversion("chunkutils2"));'

docker run --rm \
    --platform "$PLATFORM" \
    -v "$WORKSPACE_ROOT":/work \
    -w /work/Mukkit \
    -e PATH="$PHP_IN_CONTAINER:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" \
    -e MUKKIT_M5_CHUNK="$FIXTURE" \
    "$IMAGE" \
    bash -c "php -r '$preflight' && php -d memory_limit=512M pm-host/tests/pm-chunkutils2-parity-test.php && php -d memory_limit=512M pm-host/tests/pm-world-test.php"
rc=$?

if [ "$rc" -ne 0 ]; then
    printf '\nM5（自带 pmmp PHP）未通过，exit %s\n' "$rc"
    exit "$rc"
fi

printf '\nM5（自带 pmmp PHP）PASS\n'
