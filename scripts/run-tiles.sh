#!/usr/bin/env bash
#
# 方块实体判据：Nukkit 的箱子/告示牌/熔炉进到 PocketMine 的 getTile()，插件读写能回到 Nukkit。
#
# 这里的"Nukkit"是帧，不是真服务端：`pm-tile-test.php` 喂的是适配器会发出的 chunk（带
# `tiles`）与 `tile` 帧，验的是**两端各自的形状和落点**。真客户端能不能看到箱子、告示牌
# 上的字，仍归 `docs/operations/smoke.md` 的 L4。
#
# 判据：
#   1. chunk 帧里的 tiles 变成真 PocketMine Tile，getTile() 不再是 null；
#   2. 箱子内容、告示牌文本、熔炉进度可读；
#   3. 增量 tile 帧改内容，不改身份，也不回声；
#   4. 插件 setItem()/setText() -> tile op，带完整快照；
#   5. 容器方块实体的库存能当窗口打开（锚点/类型/槽位数）；
#   6. tile 帧的 removed 让 getTile() 回到 null；
#   7. 没改动的 tile 不产生 op。
#
# 用法：bash scripts/run-tiles.sh
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

printf '== 方块实体（宿主侧） ==\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-tile-test.php; then
    printf '\n方块实体判据未通过。\n' >&2
    exit 1
fi

printf '\n== JVM 侧编译与位置打包 ==\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\nJVM 侧编译失败。\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi

CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"
if ! java -cp "$CP" cn.mukkit.nukkit.TilePackingCheck; then
    printf '\n位置打包判据未通过。\n' >&2
    exit 1
fi

printf '\ntiles PASS\n'
