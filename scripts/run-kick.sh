#!/usr/bin/env bash
#
# 踢人/断开判据：插件调 `Player::kick()`，客户端被断开，而且只断一次。
#
#   1. 宿主侧（真 PM + 真 phar 插件）：kick() 产生 DisconnectPacket op，理由跟着走；
#      PlayerQuitEvent 只触发一次；被踢的玩家不再被 diff；Nukkit 迟到的 quit 不会再触发一次。
#   2. JVM 侧（离线）：断连文案的空值兜底（空文案 Nukkit 根本不发断连包，客户端会一直等），
#      以及 DisconnectPacket 不得被当成普通包投递。
#
# 没有客户端参与：这里能证明的是"该关的关了、该发的发了、事件只来一次"，屏幕长什么样只有
# 真机才能回答。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/kick/host.log
JVM_LOG=run/kick/jvm.log
mkdir -p run/kick

printf '== 踢人/断开（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：kick() -> 断连 op，事件只来一次\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-kick-test.php 2>&1 | tee "$LOG"; then
    printf '\n踢人未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：断连文案与"不是包"的判定（离线）\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n踢人未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.KickCheck >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^kick-jvm' "$JVM_LOG" || true
    printf '\n踢人未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
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
require_line 'a kick produces exactly one packet op'          "$LOG" '踢人只产生一个断连 op'
require_line 'and it is the disconnection screen'             "$LOG" '断连包类型正确'
require_line 'addressed to the player who was kicked'         "$LOG" '断连包发给被踢的人'
require_line 'carrying the reason the plugin gave'            "$LOG" '理由跟着断连包走'
require_line 'PlayerQuitEvent fires once, from the kick'      "$LOG" 'QuitEvent 只触发一次'
require_line 'the quit event carries the same reason'         "$LOG" 'QuitEvent 带同一个理由'
require_line 'a kicked player is not diffed on later ticks'   "$LOG" '被踢的人不再被镜像'
require_line 'a state frame for a kicked player'              "$LOG" '迟到的状态帧只报不应用'
require_line 'a quit Nukkit sends afterwards'                 "$LOG" 'Nukkit 迟到的 quit 不重复触发'
require_line 'still has something to show on the screen'      "$LOG" '没给理由也有屏幕文案'
require_line 'kicking nobody is a no-op'                      "$LOG" '没人在线时踢人是空操作'
require_line 'kick PASS'                                      "$LOG" '宿主侧全部断言通过'
require_line 'kick-jvm PASS'                                  "$JVM_LOG" 'JVM 侧文案与判定通过'

if [ "$fail" -ne 0 ]; then
    printf '\n踢人未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\n踢人 PASS\n'
