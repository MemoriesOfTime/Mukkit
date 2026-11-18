#!/usr/bin/env bash
#
# 玩家状态判据：插件改的状态能到客户端，客户端侧改的状态能到插件，两边都不来回弹。
#
#   1. 宿主侧（真 PM + 真 phar 插件）：/st-move /st-health /st-gm 产生 player.state；
#      入站的 player.state 被插件读回；镜像应用之后不再回推。
#   2. JVM 侧（离线）：diff 的分组、epsilon、游戏模式映射 —— 这些是"静默出错"的部分。
#
# 没有客户端参与，这是刻意的：这里能证明的是"该发的 op 发了、该收的状态收到了"，
# 客户端画得对不对只有客户端能回答。真机验收另开，判据写在 docs/operations/smoke.md。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/player-state/host.log
JVM_LOG=run/player-state/jvm.log
mkdir -p run/player-state

printf '== 玩家状态镜像（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：真插件改状态 -> player.state，反向应用 -> 插件读回\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-player-state-test.php 2>&1 | tee "$LOG"; then
    printf '\n玩家状态未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：diff 与映射（离线）\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n玩家状态未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.PlayerStateCheck >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^player-state-jvm' "$JVM_LOG" || true
    printf '\n玩家状态未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$JVM_LOG" | tail -6

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
require_line 'teleport() reaches the bridge'        "$LOG" '传送变成 player.state'
require_line 'carries yaw and pitch'                "$LOG" '传送带上朝向'
require_line 'setHealth() reaches the bridge'       "$LOG" '血量变化过桥'
require_line 'setGamemode() reaches the bridge'     "$LOG" '游戏模式变化过桥'
require_line 'experience reaches the bridge'          "$LOG" '经验变化过桥'
require_line 'hunger reaches the bridge'              "$LOG" '饥饿变化过桥'
require_line 'setHeldItemIndex() reaches the bridge' "$LOG" '手持槽变化过桥'
require_line 'the held slot is reported once'        "$LOG" '手持槽只报一次'
require_line 'the join frame lands the held slot'    "$LOG" '进服即带手持槽'
require_line 'a held slot pushed by Nukkit is what the plugin reads back' "$LOG" 'Nukkit 改的手持槽插件读得到'
require_line 'a held slot outside 0-8 is reported'   "$LOG" '越界手持槽只报不应用'
require_line 'a slot selected inside PlayerJoinEvent' "$LOG" 'join 期间的选槽也回放'
require_line 'a potion effect reaches the bridge'     "$LOG" '药水效果过桥'
require_line 'an empty effect list clears'            "$LOG" '清空药水效果'
require_line 'pushed by Nukkit is what the plugin reads back' "$LOG" 'Nukkit 改的状态插件读得到'
require_line 'is not echoed back'                   "$LOG" '镜像应用后不回推'
require_line 'unknown gamemode is reported'         "$LOG" '未知游戏模式被拒绝而非猜一个'
require_line 'an effect id PocketMine does not know is reported' "$LOG" '未知效果 id 只报不应用'
require_line 'player state PASS'                    "$LOG" '宿主侧全部断言通过'
require_line 'player-state-jvm PASS'                "$JVM_LOG" 'JVM 侧 diff 与映射通过'

if [ "$fail" -ne 0 ]; then
    printf '\n玩家状态未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\n玩家状态 PASS\n'
