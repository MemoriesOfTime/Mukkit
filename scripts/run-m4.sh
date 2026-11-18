#!/usr/bin/env bash
#
# M4 判据：出站包从 PocketMine 的包对象走到 Nukkit 的包对象，中间不出现任何线缆字节。
#
#   1. 插件用 sendDataPacket() 发 8 种包，宿主把它们变成带语义字段的 packet op
#   2. 同一批 op 交给 JVM 侧映射，每种都能被**这个 Nukkit 构建**编码
#   3. 同一个包在 jar 里最老（协议 281）和最新（协议 898）的 GameVersion 下都能编码 ——
#      桥里只要藏过一字节协议数据，这一步就会露馅
#   4. 白名单之外的包按类名报一次，不静默丢
#
# 没有客户端参与，这是刻意的：这里能证明的是"交给 Nukkit 的包是对的、能编码"，
# 客户端画得对不对只有客户端能回答。真机验收另开，判据写在 docs/operations/smoke.md。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

FIXTURE=run/m4/ops.json
LOG=run/m4/host.log
JVM_LOG=run/m4/jvm.log
mkdir -p run/m4

printf '== M4 出站包映射（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：真 PM 包 -> packet op\n'
if ! php -d memory_limit=512M pm-host/tests/pm-packet-test.php --fixture="$FIXTURE" 2>&1 | tee "$LOG"; then
    printf '\nM4 未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：packet op -> Nukkit 包 -> 编码\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\nM4 未通过：JVM 侧编译失败\n'
    exit 1
fi

# The adapter compiles against exactly one Nukkit build; the harness has to use that same jar,
# otherwise "it encodes" would be a statement about a different server.
if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.PacketMapperCheck "$FIXTURE" >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^m4-jvm' "$JVM_LOG" || true
    printf '\nM4 未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$' "$JVM_LOG" | tail -8

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
require_line 'all four title variants'          "$LOG"     '标题的四种变体各自成 op'
require_line 'form keeps its id and its JSON'   "$LOG"     '表单 id 与 JSON 原样过桥'
require_line 'sound keeps its name, position'   "$LOG"     '音效名、坐标、音量音高都在'
require_line 'sidebar objective keeps slot'     "$LOG"     '计分板槽位/标题/排序方式都在'
require_line 'score packet keeps both entries'  "$LOG"     '计分条目与计分者类型都在'
require_line 'objective removal names'          "$LOG"     '移除目标带名字'
require_line 'reported once, by class name'     "$LOG"     '白名单外的包被报一次'
require_line 'the pm profile claims delivery'   "$LOG"     'pm profile 声明自己投递聊天'
require_line 'observing profiles leave delivery' "$LOG"    'plugin/stub 不声明投递，Nukkit 保留广播'
require_line 'm4-jvm PASS'                      "$JVM_LOG" 'JVM 侧逐包编码通过'

if [ "$fail" -ne 0 ]; then
    printf '\nM4 未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\nM4 PASS\n'
