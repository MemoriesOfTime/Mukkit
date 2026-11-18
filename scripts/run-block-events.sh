#!/usr/bin/env bash
#
# 可取消事件判据：PocketMine 插件能否否决一个 Nukkit 动作。
#
#   1. 宿主侧（真 PM + 真 phar 插件）：`BlockBreakEvent` 里的 `cancel()` 变成
#      `event.result{allowed:false}`；没取消的是 `true`；构建不出来的帧按拒绝回答。
#   2. 只有真的有插件监听时才拦截（`eventDemand()`），插件停用后同 tick 撤回。
#   3. JVM 侧（离线）：挂起动作的 id 与超时规则 —— 超时没答就丢弃，绝不复用 id。
#
# 为什么会有"挂起"：Nukkit 同步决定方块动作，桥一 tick 才往返一次。要让 PM 否决，只能先拦下、
# 等判决、再由适配器补执行。代价写在 docs/design/events.md。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/block-events/host.log
JVM_LOG=run/block-events/jvm.log
mkdir -p run/block-events

printf '== 可取消事件（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：真插件 cancel() -> event.result\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-block-event-test.php 2>&1 | tee "$LOG"; then
    printf '\n可取消事件未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：挂起动作的 id 与超时\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n可取消事件未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.BlockEventCheck >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^block-events-jvm' "$JVM_LOG" || true
    printf '\n可取消事件未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$JVM_LOG" | tail -5

fail=0
require_line() {
    if grep -qF -- "$1" "$2"; then
        printf 'ok    %s\n' "$3"
    else
        printf 'FAIL  %s\n' "$3"
        fail=1
    fi
}

printf '\n-- 汇总\n'
require_line 'a listener makes the host ask for block breaks' "$LOG" '有监听才拦截'
require_line 'a break inside the protected box is denied'     "$LOG" '保护区内的破坏被否决'
require_line 'the plugin saw the real block from the mirror'  "$LOG" '事件里是真方块（来自镜像）'
require_line 'and the real item, rebuilt from the namespaced id' "$LOG" '事件里是真物品（命名空间 id）'
require_line 'a break outside the box is allowed'             "$LOG" '保护区外放行'
require_line 'denied, not left hanging'                        "$LOG" '判决必达，不留悬挂'
require_line 'a frame without coordinates is denied'          "$LOG" '构建不出来的帧按拒绝'
require_line 'withdraws the demand in the same tick'          "$LOG" '停用插件同 tick 撤回拦截'
require_line 'block events PASS'                              "$LOG" '宿主侧全部断言通过'
require_line 'block-events-jvm PASS'                          "$JVM_LOG" 'JVM 侧 id 与超时规则通过'

if [ "$fail" -ne 0 ]; then
    printf '\n可取消事件未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\n可取消事件 PASS\n'
