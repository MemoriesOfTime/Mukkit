#!/usr/bin/env bash
#
# 实体判据：Nukkit 的实体在 PM 里是真实体，插件改的位置/血量能回到 Nukkit。
#
#   1. 宿主侧（真 PM + 真 phar 插件）：entity.sync 之后 /e-report 能看到真 Zombie；
#      /e-hurt、/e-move 产生 entity.state；Nukkit 推来的移动不回推；未知类型只报不造。
#   2. JVM 侧（离线）：快照指纹 —— 什么算"变了"，决定实体是否会被报告/每秒钟重发。
#
# 没有真客户端，也没有真 Nukkit 实体：这里证明的是"宿主把快照变成真实体、插件的改动变成 op"，
# Nukkit 侧的快照采集与 entity.state 应用只有真服务端才跑得到。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/entities/host.log
JVM_LOG=run/entities/jvm.log
mkdir -p run/entities

printf '== 实体镜像（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：entity.sync -> 真实体 -> 插件读写\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-entity-test.php 2>&1 | tee "$LOG"; then
    printf '\n实体未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：快照指纹（离线）\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n实体未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.EntityMirrorCheck >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^entity-mirror' "$JVM_LOG" || true
    printf '\n实体未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$JVM_LOG" | tail -2

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
require_line 'the plugin sees a real zombie with position and health' "$LOG" '插件看到的是真 Zombie'
require_line 'the bridged player is not counted'                       "$LOG" '玩家不被当成镜像实体'
require_line 'setHealth() reaches the bridge'                          "$LOG" '插件改血量过桥'
require_line 'teleport() reaches the bridge'                           "$LOG" '插件传送实体过桥'
require_line 'a position pushed by Nukkit is what the plugin reads back' "$LOG" 'Nukkit 的移动插件读得到'
require_line 'a mirrored move is not echoed back'                      "$LOG" '镜像移动不回推'
require_line 'an empty snapshot removes what was there'                "$LOG" '实体消失后从世界移除'
require_line 'a name tag pushed by Nukkit'                            "$LOG" '实体名牌 Nukkit -> 插件'
require_line 'setNameTag() reaches the bridge'                         "$LOG" '插件改名牌过桥'
require_line 'an effect pushed by Nukkit'                              "$LOG" '实体效果 Nukkit -> 插件'
require_line 'adding an effect reaches the bridge'                     "$LOG" '插件加效果过桥'
require_line 'an effect id PocketMine does not know'                   "$LOG" '未知实体效果 id 只报不应用'
require_line 'a dropped stack counts as an entity'                     "$LOG" '掉落物是真实体'
require_line 'the stack keeps its item, count and NBT'                 "$LOG" '掉落物保留物品、数量与 NBT'
require_line 'an item name this build does not know is reported'        "$LOG" '未知物品名只报不造'
require_line 'is reported, not approximated'                           "$LOG" '未知类型只报不造'
require_line 'entity PASS'                                             "$LOG" '宿主侧全部断言通过'
require_line 'entity-mirror PASS'                                      "$JVM_LOG" 'JVM 侧指纹通过'

if [ "$fail" -ne 0 ]; then
    printf '\n实体未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\n实体 PASS\n'
