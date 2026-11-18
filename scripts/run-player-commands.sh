#!/usr/bin/env bash
#
# 玩家命令入口判据：玩家在聊天框敲的 `/money`，能到 PocketMine 的插件手里。
#
#   1. 宿主侧（真 PM + 真 phar 插件）：带 player uuid 的 command op 以**玩家**身份执行，
#      回复作为 packet op 回给该玩家；未知/已退出玩家被丢弃；控制台路径不回归。
#   2. JVM 侧（离线）：路由决策 —— Nukkit 不认识的转给宿主，认识的留给 Nukkit（否则执行两遍）。
#
# 没有真客户端参与，这是刻意的：能证明的是"命令被正确分流、以正确身份执行、回复回到正确的人"。
# 补全列表、/help 里能不能看见 PM 命令不在这条判据里，边界见 docs/design/graft-map.md 的"玩家命令"一行。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/player-commands/host.log
JVM_LOG=run/player-commands/jvm.log
mkdir -p run/player-commands

printf '== 玩家命令入口（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：command op 以玩家身份执行\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-command-test.php 2>&1 | tee "$LOG"; then
    printf '\n玩家命令未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：命令分流（离线）\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n玩家命令未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.CommandRoutingCheck >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^command-routing-jvm' "$JVM_LOG" || true
    printf '\n玩家命令未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
    exit 1
fi
tail -3 "$JVM_LOG"

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
require_line 'a command sent with a player uuid runs as that player'    "$LOG" '命令以玩家身份执行'
require_line 'the reply went back to that player, not to console'      "$LOG" '回复回给该玩家'
require_line 'a leading slash is punctuation, not part of the name'    "$LOG" '前导斜杠只是标点'
require_line 'a command with no player uuid is still console'          "$LOG" '控制台路径不回归'
require_line 'a command from a uuid that was never proxied is dropped' "$LOG" '未知玩家的命令被丢弃'
require_line 'and is not quietly run as console'                       "$LOG" '且不降级成控制台'
require_line 'a command no plugin owns is reported, not silently accepted' "$LOG" '未知命令留 warn'
require_line 'an empty line is an error for that tick, not a crash'    "$LOG" '空命令不崩'
require_line 'a command that arrives after the quit is dropped, not run for a ghost' "$LOG" '退出后的命令被丢弃'
require_line 'pm player command PASS'                                  "$LOG" '宿主侧全部断言通过'
require_line 'command-routing-jvm PASS'                                "$JVM_LOG" 'JVM 侧分流通过'

if [ "$fail" -ne 0 ]; then
    printf '\n玩家命令未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\n玩家命令 PASS\n'
