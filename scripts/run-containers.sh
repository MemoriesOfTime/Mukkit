#!/usr/bin/env bash
#
# 容器窗口判据：PocketMine 插件开箱子，客户端改动回到 PM，判决能允许也能否决。
#
# 这里的"客户端"是帧，不是真客户端：`pm-container-test.php` 喂的是适配器会发出的
# `window.open` / `window.content` / `window.slot` / `window.close` 与 `inventory.transaction`
# 形状，验的是**两端各自的形状和判决落点**。真客户端能不能画出来、点下去，仍归
# `docs/operations/smoke.md` 的 L4。
#
# 判据：
#   1. setCurrentWindow() 产生 window.open（窗口 id/类型/坐标）与完整内容；
#   2. 客户端移动物品 -> inventory.transaction -> 插件看到事件 -> 允许时内容与背包都变；
#   3. 插件 cancel() -> allowed=false，窗口和背包都不动；
#   4. 过期窗口的帧被拒绝，不留悬挂；
#   5. 插件在窗口打开时 setItem() -> window.slot；
#   6. removeCurrentWindow() -> window.close；
#   7. player.death 作为通知送达（PM 5 的死亡事件不可取消）。
#
# 用法：bash scripts/run-containers.sh
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

printf '== 容器窗口（宿主侧） ==\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-container-test.php; then
    printf '\n容器判据未通过。\n' >&2
    exit 1
fi

printf '\n== 事件面（含 player.death） ==\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-lifecycle-event-test.php; then
    printf '\n事件面判据未通过。\n' >&2
    exit 1
fi

printf '\n== JVM 侧编译与窗口库存 ==\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\nJVM 侧编译失败。\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"
if ! java -cp "$CP" cn.mukkit.nukkit.ContainerInventoryCheck; then
    printf '\n窗口库存判据未通过。\n' >&2
    exit 1
fi

printf '\ncontainers PASS\n'
