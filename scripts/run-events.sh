#!/usr/bin/env bash
#
# 可取消事件判据（全集）：PocketMine 插件能否否决一个 Nukkit 动作。
#
#   1. block.break：保护区内的破坏被 cancel()，方块真的不碎（扣住 → 判决 → useBreakOn 补执行）。
#   2. player.drop：钻石被 cancel() 时物品留在背包里；放行时适配器才 removeItem + dropItem。
#   3. 事件面：切手持、5 个状态切换、5 个方块生命周期、重生通知，逐个判决/送达。
#   4. 只有真的有插件监听时才拦截（eventDemand()），插件停用后同 tick 撤回，且只撤回自己那一类。
#   5. JVM 侧（离线）：挂起表的 id、超时，以及"按事件名撤回"的规则。
#
# 为什么会有"挂起"：Nukkit 同步决定方块/丢弃，桥一 tick 才往返一次。要让 PM 否决，只能先拦下、
# 等判决、再由适配器补执行。代价写在 docs/design/events.md。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

mkdir -p run/events
BLOCK_LOG=run/events/block-host.log
DROP_LOG=run/events/drop-host.log
LIFECYCLE_LOG=run/events/lifecycle-host.log
JVM_LOG=run/events/jvm.log

printf '== 可取消事件（fork=%s）==\n' "$PM_ROOT"

printf '\n-- block.break：真插件 cancel() -> event.result\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-block-event-test.php 2>&1 | tee "$BLOCK_LOG"; then
    printf '\n可取消事件未通过（block.break），日志：%s\n' "$BLOCK_LOG"
    exit 1
fi

printf '\n-- player.drop：真插件 cancel() -> event.result\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-drop-event-test.php 2>&1 | tee "$DROP_LOG"; then
    printf '\n可取消事件未通过（player.drop），日志：%s\n' "$DROP_LOG"
    exit 1
fi

printf '\n-- 事件面扩容：切手持 / 状态切换 / 方块生命周期 / 重生\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-lifecycle-event-test.php 2>&1 | tee "$LIFECYCLE_LOG"; then
    printf '\n可取消事件未通过（事件面扩容），日志：%s\n' "$LIFECYCLE_LOG"
    exit 1
fi

printf '\n-- JVM 侧：挂起表的 id、超时与按事件撤回\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n可取消事件未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

: > "$JVM_LOG"
if ! java -cp "$CP" cn.mukkit.nukkit.BlockEventCheck >>"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^block-events-jvm' "$JVM_LOG" || true
    printf '\n可取消事件未通过（JVM 侧 block.break），日志：%s\n' "$JVM_LOG"
    exit 1
fi
if ! java -cp "$CP" cn.mukkit.nukkit.EventHoldCheck >>"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^event-hold-jvm' "$JVM_LOG" || true
    printf '\n可取消事件未通过（JVM 侧挂起表），日志：%s\n' "$JVM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$JVM_LOG" | tail -4

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
require_line 'a listener makes the host ask for block breaks' "$BLOCK_LOG" 'block.break：有监听才拦截'
require_line 'a break inside the protected box is denied'     "$BLOCK_LOG" 'block.break：保护区内被否决'
require_line 'the plugin saw the real block from the mirror'  "$BLOCK_LOG" 'block.break：事件里是真方块（镜像）'
require_line 'a break outside the box is allowed'             "$BLOCK_LOG" 'block.break：保护区外放行'
require_line 'denied, not left hanging'                        "$BLOCK_LOG" 'block.break：构建失败按拒绝'
require_line 'withdraws the demand in the same tick'          "$BLOCK_LOG" 'block.break：停用同 tick 撤回'
require_line 'block events PASS'                              "$BLOCK_LOG" 'block.break：宿主侧通过'

require_line 'a listener makes the host ask for drops'        "$DROP_LOG" 'player.drop：有监听才拦截'
require_line 'dropping diamonds is denied'                     "$DROP_LOG" 'player.drop：钻石被否决'
require_line 'the plugin saw the real stack from the tuple'    "$DROP_LOG" 'player.drop：事件里是真物品（元组）'
require_line 'dropping dirt is allowed'                        "$DROP_LOG" 'player.drop：其它物品放行'
require_line 'a worn sword keeps its damage'                   "$DROP_LOG" 'player.drop：耐久跟着过桥'
require_line 'a renamed stack arrives renamed'                 "$DROP_LOG" 'player.drop：NBT 跟着过桥'
require_line 'an item this build does not have is denied'      "$DROP_LOG" 'player.drop：未知物品按拒绝'
require_line 'disabling the listener withdraws the demand'     "$DROP_LOG" 'player.drop：停用同 tick 撤回'
require_line 'drop events PASS'                                "$DROP_LOG" 'player.drop：宿主侧通过'

require_line 'one listener asks for all 11 decision events'    "$LIFECYCLE_LOG" '扩容：11 个决定事件全在需求表'
require_line 'a crop growing is allowed by default'            "$LIFECYCLE_LOG" '扩容：生长默认放行'
require_line 'the plugin saw the block and the state it becomes' "$LIFECYCLE_LOG" '扩容：生长带方块与新状态'
require_line 'a vetoed growth is denied'                       "$LIFECYCLE_LOG" '扩容：生长被否决'
require_line 'a spread carries its source block too'           "$LIFECYCLE_LOG" '扩容：扩散带源方块'
require_line 'forming (soul sand -> bubble column) is delivered' "$LIFECYCLE_LOG" '扩容：成型送达'
require_line 'burning is delivered'                            "$LIFECYCLE_LOG" '扩容：燃烧送达'
require_line 'leaves decaying is delivered'                    "$LIFECYCLE_LOG" '扩容：树叶凋零送达'
require_line 'a sneak toggle is delivered'                     "$LIFECYCLE_LOG" '扩容：潜行切换带状态'
require_line 'a vetoed sneak is denied'                        "$LIFECYCLE_LOG" '扩容：潜行切换被否决'
require_line 'with the slot and the real item'                 "$LIFECYCLE_LOG" '扩容：切手持带槽位与真物品'
require_line 'a vetoed hotbar switch is denied'                "$LIFECYCLE_LOG" '扩容：切手持被否决'
require_line 'toggle sprint is delivered'                      "$LIFECYCLE_LOG" '扩容：其余四个切换送达'
require_line 'a respawn is delivered as news'                  "$LIFECYCLE_LOG" '扩容：重生只通知'
require_line 'a block event for an unmirrored world is denied' "$LIFECYCLE_LOG" '扩容：未镜像世界拒绝'
require_line 'an unknown legacy block id is denied'            "$LIFECYCLE_LOG" '扩容：未知方块 id 拒绝'
require_line 'a world event needs no player uuid'              "$LIFECYCLE_LOG" '扩容：世界事件免 uuid'
require_line 'a toggle with a non-boolean state is denied'     "$LIFECYCLE_LOG" '扩容：非布尔状态拒绝'
require_line 'a toggle for an unknown player is denied'        "$LIFECYCLE_LOG" '扩容：未知玩家也不悬挂'
require_line 'disabling the listener withdraws every demand'   "$LIFECYCLE_LOG" '扩容：停用撤回全部'
require_line 'lifecycle events PASS'                            "$LIFECYCLE_LOG" '扩容：宿主侧通过'

require_line 'block-events-jvm PASS' "$JVM_LOG" 'JVM：block.break 的 id 与超时'
require_line 'event-hold-jvm PASS'   "$JVM_LOG" 'JVM：按事件名撤回，互不牵连'

if [ "$fail" -ne 0 ]; then
    printf '\n可取消事件未通过，日志：%s %s %s\n' "$BLOCK_LOG" "$DROP_LOG" "$JVM_LOG"
    exit 1
fi
printf '\n可取消事件 PASS\n'
