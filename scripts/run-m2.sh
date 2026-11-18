#!/usr/bin/env bash
#
# M2 判据：stock PHP（无任何 pmmp 扩展）上 fork 能 boot() 返回，并被外部驱动 1000 tick。
#
#   1. php -m 里没有 pmmpthread / leveldb / chunkutils2 / yaml / encoding / morton / crypto / igbinary
#   2. 构造函数返回（不进入 tickProcessor）
#   3. 1000 次外部 tick() 不崩，Server::getInstance() 全程可用
#   4. 同步 AsyncPool 真的执行任务体，且在下一个 tick 边界交付 onCompletion
#   5. 正常关停，退出码 0
#
# 需要 fork 先装依赖：
#   php composer.phar install --ignore-platform-reqs --no-dev   # 在 PocketMine-MP 里执行
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    printf '     设置 MUKKIT_PM_ROOT，或把 PocketMine-MP checkout 放在仓库旁边\n' >&2
    exit 1
fi

TICKS="${MUKKIT_M2_TICKS:-1000}"
mkdir -p run/m2
LOG=run/m2/last.log

printf '== M2 探针 (fork=%s, ticks=%s) ==\n' "$PM_ROOT" "$TICKS"
php -d memory_limit=512M pm-host/bin/pm-boot.php --ticks="$TICKS" 2>&1 | tee "$LOG"
status=${PIPESTATUS[0]}

fail=0
require_line() {
    if grep -qF -- "$1" "$LOG"; then
        printf 'ok    %s\n' "$2"
    else
        printf 'FAIL  %s\n' "$2"
        fail=1
    fi
}

[ "$status" -eq 0 ] || { printf 'FAIL  探针退出码 %s\n' "$status"; fail=1; }
require_line '[m2] PASS'                  '探针全部断言通过'
require_line 'boot() returned'            'boot() 返回'
require_line 'sync async pool'            '同步 AsyncPool 语义'
require_line 'shutdown() completed'       '正常关停'

# 真插件验收：pm profile 用 PM 自己的 PluginManager 加载 EconomyAPI，命令走真 CommandMap。
printf '\n== pm profile 端到端（EconomyAPI 真实加载）==\n'
if php -d memory_limit=512M pm-host/tests/pm-profile-test.php 2>&1 | tee run/m2/pm-profile.log; then
    :
else
    printf 'FAIL  pm profile 端到端未通过\n'
    fail=1
fi

# 同一条判据、另一套数据布局：默认是 Nukkit 习惯（plugins/<名字>/），isolated 是老的 pm-data。
# 布局错了不会崩，只会让插件"忘记"自己的配置 —— 所以两种都得跑。
printf '\n== pm profile 端到端（isolated 布局）==\n'
if MUKKIT_PM_DATA_LAYOUT=isolated php -d memory_limit=512M pm-host/tests/pm-profile-test.php 2>&1 | tee run/m2/pm-profile-isolated.log; then
    :
else
    printf 'FAIL  pm profile isolated 布局未通过\n'
    fail=1
fi

if [ "$fail" -ne 0 ]; then
    printf '\nM2 未通过，日志：%s\n' "$LOG"
    exit 1
fi
printf '\nM2 PASS\n'
