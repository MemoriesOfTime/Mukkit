#!/usr/bin/env bash
#
# M5 判据：`$world->getBlock()` 与 Nukkit 实测一致，且插件写回能到达 Nukkit。
#
#   1. JVM 侧把一段区块按线上格式编码（4096 个 (id, meta)，XZY 序，zlib+base64）
#   2. PHP 侧解码同一份字节，建成真 Chunk，插件的 `$world->getBlockAt()` 逐块核对
#   3. 插件 `setBlockAt()` 写一个方块，宿主 diff 出差异并产出 block op（legacy id + meta）
#   4. Nukkit 侧的改动以 block.update 推来，镜像跟上，且**不回声**
#   5. 镜像外的坐标读空气，镜像外的更新只警告一次
#   6. 第二个世界（Nukkit 的 level 名）有自己的 World 与镜像，block op 带世界名
#   7. chunk.unload 之后该区块读空气，写进去的东西不再回传
#   8. 世界存储的垫片只暴露扩展也有的成员（这条是补的：垫片多出来的成员在真 PHP 上不存在，
#      而本机所有判据都跑垫片，于是"全绿"和"线上必崩"可以同时成立）
#
# 判据里"连续 10 分钟无漂移"这一条跑不了：需要真 Nukkit + 真玩家在线 10 分钟。
# 这里能证明的是单 tick 的一致性与双向往返，长期漂移归 L5，判据见 docs/project/spec/testing.md。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/m5/host.log
JVM_LOG=run/m5/jvm.log
STREAM_LOG=run/m5/stream.log
STATE_LOG=run/m5/world-state.log
PARITY_LOG=run/m5/parity.log
FIXTURE=run/m5/chunk.json
mkdir -p run/m5

printf '== M5 世界镜像（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 编译\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\nM5 未通过：编译失败\n'
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

printf '\n-- JVM 侧：一段区块 -> 线上字节 + fixture\n'
if ! java -cp "$CP" cn.mukkit.nukkit.ChunkSnapshotCheck "$FIXTURE" >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^m5-jvm' "$JVM_LOG" || true
    printf '\nM5 未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$JVM_LOG" | tail -8

printf '\n-- JVM 侧：区块键与订阅方阵（离线）\n'
if ! java -cp "$CP" cn.mukkit.nukkit.ChunkStreamerCheck >"$STREAM_LOG" 2>&1; then
    grep -E '^FAIL|^chunk-streamer' "$STREAM_LOG" || true
    printf '\nM5 未通过（区块订阅），日志：%s\n' "$STREAM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$STREAM_LOG" | tail -3

printf '\n-- JVM 侧：世界时钟 diff（离线）\n'
if ! java -cp "$CP" cn.mukkit.nukkit.WorldStateCheck >"$STATE_LOG" 2>&1; then
    grep -E '^FAIL|^world-state' "$STATE_LOG" || true
    printf '\nM5 未通过（世界时钟），日志：%s\n' "$STATE_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$STATE_LOG" | tail -2

printf '\n-- 宿主侧：垫片与扩展的成员表（对照 ext-chunkutils2 0.3.5）\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-chunkutils2-parity-test.php 2>&1 | tee "$PARITY_LOG"; then
    printf '\nM5 未通过（垫片成员表），日志：%s\n' "$PARITY_LOG"
    exit 1
fi

printf '\n-- 宿主侧：同一份字节 -> 真 Chunk -> 插件读写\n'
if ! MUKKIT_M5_CHUNK="$FIXTURE" php -d memory_limit=512M pm-host/tests/pm-world-test.php 2>&1 | tee "$LOG"; then
    printf '\nM5 未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

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
require_line 'ids payload is 8192 bytes'                     "$JVM_LOG" '段的 id 载荷是 4096 个 16 位'
require_line 'planks decode at 0,0,1 with meta 2'            "$JVM_LOG" 'meta 半字节没被吃掉'
require_line 'chunk fixture came from the JVM encoder'        "$LOG"     'fixture 来自 JVM 编码器'
require_line 'agree on XZY order'                             "$LOG"     '两种语言对 XZY 序的理解一致'
require_line 'returns the stone Nukkit reported'              "$LOG"     'getBlockAt() 返回 Nukkit 报的方块'
require_line 'returns the dirt next to it'                    "$LOG"     '相邻方块没有转置'
require_line 'was never mirrored reads as air'                "$LOG"     '镜像外读空气，不凭空造地形'
require_line 'a plugin write produces a block op'             "$LOG"     '插件写方块产出 block op'
require_line 'Nukkit block change is not echoed back'         "$LOG"     'Nukkit 的改动不回声'
require_line 'the next read sees the block Nukkit changed'    "$LOG"     'Nukkit 的改动对插件可见'
require_line 'reported once, not every tick'                  "$LOG"     '镜像外的更新只警告一次'
require_line 'a chunk for a second world is accepted'         "$LOG"     '第二个世界的区块被接受'
require_line 'the player is in the world the join frame named' "$LOG"    '玩家跟着 join 帧进入对应世界'
require_line 'the first world is not visible from the second' "$LOG"     '世界之间不串数据'
require_line 'a write in the arena names the arena'           "$LOG"     'block op 带世界名'
require_line 'the chunk the player stands in is not unloaded'  "$LOG"     '玩家所在区块不会被卸载'
require_line 'an unloaded chunk reads as air again'           "$LOG"     '卸载后的区块读空气'
require_line 'a write into an unloaded chunk is not sent anywhere' "$LOG" '卸载后的写入不回传'
require_line 'chunk-streamer PASS'                            "$STREAM_LOG" '区块键与订阅方阵正确'
require_line 'chunkutils2-parity PASS'                        "$PARITY_LOG" '垫片不多出扩展没有的成员'
require_line 'a clock pushed by Nukkit is what the plugin reads back' "$LOG" 'Nukkit 的时间插件读得到'
require_line 'setTime() reaches the bridge for the world the player is in' "$LOG" '插件改时间过桥且带世界名'
require_line 'stopTime() reaches the bridge'                  "$LOG"     '暂停时间过桥'
require_line 'a mirrored clock is not echoed back'            "$LOG"     '时钟镜像不回推'
require_line 'a field this side has no home for is reported'  "$LOG"     '没有对应字段的天气被如实拒绝'
require_line 'world-state PASS'                               "$STATE_LOG" '时钟 diff 分组正确'
require_line 'the default world exists before any player joins' "$LOG"    '默认世界在开服时已存在'
require_line 'a world nobody joined is not invented'           "$LOG"    '没人进过的世界不会被凭空造出'
require_line 'a joined world shows up in the manager by name'  "$LOG"    '世界按名字可查'
require_line 'the default world did not change when another one appeared' "$LOG" '默认世界不因新世界而改变'
require_line 'M5 PASS'                                        "$LOG"     '宿主侧全部断言通过'

if [ "$fail" -ne 0 ]; then
    printf '\nM5 未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\nM5 PASS\n'
