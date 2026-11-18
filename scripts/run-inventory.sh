#!/usr/bin/env bash
#
# 背包判据：插件放进槽位的东西能到 Nukkit，Nukkit 玩家身上的东西能到插件，两边都不来回弹。
#
#   1. 宿主侧（真 PM + 真 phar 插件）：/inv-set /inv-armor /inv-offhand 产生 player.inventory；
#      入站的 player.inventory 被插件读回；镜像应用之后不再回推；坏数据只报一次。
#   2. JVM 侧（离线）：槽位映射（0-35 / 36-39 / 40）与物品指纹 —— 这些是"静默出错"的部分。
#
# 没有客户端参与，这是刻意的：这里能证明的是"该发的 op 发了、该收的物品收到了"，
# 客户端背包里画得对不对只有客户端能回答。真机验收另开，判据写在 docs/operations/smoke.md。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/inventory/host.log
JVM_LOG=run/inventory/jvm.log
mkdir -p run/inventory

printf '== 背包镜像（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：真插件写槽位 -> player.inventory，反向应用 -> 插件读回\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-inventory-test.php 2>&1 | tee "$LOG"; then
    printf '\n背包未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：槽位映射与指纹（离线）\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n背包未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.InventoryMirrorCheck >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^inventory-jvm' "$JVM_LOG" || true
    printf '\n背包未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
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
require_line 'setItem() reaches the bridge as slot 0'   "$LOG" '背包槽位写入过桥'
require_line 'getArmorInventory() maps to slot 36'      "$LOG" '护甲槽位映射到 36'
require_line 'getOffHandInventory() maps to slot 40'    "$LOG" '副手槽位映射到 40'
require_line 'a custom name travels as a fifth NBT element' "$LOG" 'NBT 跟物品一起过桥'
require_line 'a slot pushed by Nukkit is what the plugin reads back' "$LOG" 'Nukkit 放的物品插件读得到'
require_line 'mirrored slots are not echoed back'       "$LOG" '镜像应用后不回推'
require_line 'is reported, not guessed'                 "$LOG" '未知物品名只报不猜'
require_line 'inventory PASS'                           "$LOG" '宿主侧全部断言通过'
require_line 'inventory-jvm PASS'                       "$JVM_LOG" 'JVM 侧槽位与指纹通过'

if [ "$fail" -ne 0 ]; then
    printf '\n背包未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\n背包 PASS\n'
