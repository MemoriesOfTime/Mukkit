#!/usr/bin/env bash
#
# 表单验收：PM 插件发出的表单能不能收到回答。
#
#   1. 宿主侧（真 PM + 真 phar 插件 + 插件自带的 Form 实现）：
#      `sendForm()` -> `ModalFormRequestPacket`；回答帧 -> `Form::handleResponse()`；
#      关闭 -> `handleResponse(null)`；乱七八糟的回答按"关闭"释放，不悬挂、不崩。
#   2. JVM 侧（离线）：表单 id 的归属账本，以及 Nukkit 真正解码出来的响应包长什么样 ——
#      "玩家关掉了表单"在线上协议里没有标志位，只有"没有 data"。
#
# 为什么这套东西需要单独一套判据：PocketMine 5 已经不带 SimpleForm/CustomForm 了，插件要么
# 自带表单库要么自己实现 `pocketmine\form\Form`。桥只认识 `sendForm()` 和 `handleResponse()`，
# 所以这里的探针插件故意自己实现，验的是桥的契约而不是某个库的行为。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/forms/host.log
JVM_LOG=run/forms/jvm.log
mkdir -p run/forms

printf '== 表单（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：真插件 sendForm -> handleResponse\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-form-test.php 2>&1 | tee "$LOG"; then
    printf '\n表单未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：id 归属与响应包形状\n'
if ! ./gradlew -q :mukkit-nukkit:testClasses; then
    printf '\n表单未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

if ! NK_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main"

if ! java -cp "$CP" cn.mukkit.nukkit.FormResponseCheck >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^forms-jvm' "$JVM_LOG" || true
    printf '\n表单未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
    exit 1
fi
grep -vE '^WARNING|WARN Advanced|^$|^[0-9]{4}-' "$JVM_LOG" | tail -5

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
require_line "forms need no event demand: a menu plugin has no listeners" "$LOG" '表单不需要事件通道'
require_line "the form id comes from the bridge range"                 "$LOG" '表单 id 与 Nukkit 自己的计数器不重叠'
require_line "the payload is the form's own JSON, untouched"          "$LOG" '表单 JSON 原样过桥'
require_line "the button index reaches the plugin callback"           "$LOG" '按钮下标落到插件回调'
require_line "every element value arrives with its type intact"       "$LOG" '自定义表单每种元素类型不变'
require_line "a dismissal arrives as a null response"                 "$LOG" '关闭按 null 送达'
require_line "a second answer to the same form runs nothing"          "$LOG" '同一个表单只判一次'
require_line "a well-formed answer of the wrong type reaches the plugin's validation" "$LOG" '形状不对交给插件校验'
require_line "undecodable JSON is reported, not guessed at"           "$LOG" '坏 JSON 报错不猜'
require_line "an oversized answer is refused before it is decoded"    "$LOG" '超限回答先拒后解'
require_line "the host is still serving forms after every refusal"    "$LOG" '各种拒绝之后宿主仍可用'
require_line "closeAllForms() becomes a close-form packet"            "$LOG" 'closeAllForms 有对应包'
require_line "forms PASS"                                             "$LOG" '宿主侧全部断言通过'
require_line "forms-jvm PASS"                                         "$JVM_LOG" 'JVM 侧 id 归属与解码形状通过'

if [ "$fail" -ne 0 ]; then
    printf '\n表单未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\n表单 PASS\n'
