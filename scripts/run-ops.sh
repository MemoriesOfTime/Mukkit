#!/usr/bin/env bash
#
# 权限/OP 判据：Nukkit 上的 OP 到了 PocketMine 还成立，反向也一样。
#
#   1. 宿主侧（真 PM + 真 phar 插件）：入站的 op 位让 `default: op` 权限节点生效；
#      `Server::addOp()` / `removeOp()` 产生 player.state；重进服务器时以 Nukkit 为准；
#      镜像应用之后不回推。
#   2. JVM 侧（离线）：op 单独成字段、布尔直读，不会把一次加 OP 变成一次传送。
#
# 断言不看内部标志，看插件真正会问的那句 `$player->hasPermission('...')`——
# 这条链断了的表现是"管理命令被静默拒绝"，不是崩，所以只能这样钉。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/ops/host.log
JVM_LOG=run/ops/jvm.log
mkdir -p run/ops

printf '== 权限/OP 镜像（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：default: op 节点、双向 op、重进服务器\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-ops-test.php 2>&1 | tee "$LOG"; then
    printf '\n权限/OP 未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：op 字段的 diff 与解析（离线）\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n权限/OP 未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.OpsCheck >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^ops-jvm' "$JVM_LOG" || true
    printf '\n权限/OP 未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$JVM_LOG" | tail -3

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
require_line 'an operator who joins is an operator in PocketMine'  "$LOG" '带 OP 进服就是 OP'
require_line 'PlayerJoinEvent already sees the operator bit'      "$LOG" 'join 事件里就已经是 OP'
require_line 'declared with default: op is granted'               "$LOG" 'default: op 节点生效'
require_line 'a de-op pushed by Nukkit'                          "$LOG" 'Nukkit 取消 OP 插件读得到'
require_line 'carrying op and game mode applies both'            "$LOG" '同一帧两个字段都生效'
require_line 'Server::removeOp() reaches Nukkit'                 "$LOG" '插件取消 OP 过桥'
require_line 'Server::addOp() reaches Nukkit'                    "$LOG" '插件加 OP 过桥'
require_line 'the operator change travels alone'                 "$LOG" 'OP 变化不拖带位置'
require_line 'changing op does not send a packet'                 "$LOG" 'OP 变化不额外发包'
require_line 'reported once, not every tick'                     "$LOG" 'OP 不重复上报'
require_line 'is not echoed back to Nukkit'                      "$LOG" '镜像应用后不回推'
require_line 'a stored operator entry is cleared'                "$LOG" '重进服务器以 Nukkit 为准'
require_line 'an operator change for an unknown player'          "$LOG" '未知玩家的 OP 只报不执行'
require_line 'ops PASS'                                          "$LOG" '宿主侧全部断言通过'
require_line 'ops-jvm PASS'                                      "$JVM_LOG" 'JVM 侧 diff 与解析通过'

if [ "$fail" -ne 0 ]; then
    printf '\n权限/OP 未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\n权限/OP PASS\n'
