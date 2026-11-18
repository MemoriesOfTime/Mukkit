#!/usr/bin/env bash
#
# 模板：一层验收脚本。把 xxx / Xxx 替换成能力名，放到 scripts/run-xxx.sh 并 chmod +x。
#
# 顺序固定：fork 可用 -> 宿主判据（真 PM + 真 phar）-> JVM 离线判据 -> 汇总。
# 任一步失败立即非 0 退出；不要用 set -e，错误码要自己收。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ] || [ ! -f "$PM_ROOT/vendor/autoload.php" ]; then
    printf 'FAIL 找不到可用的 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

LOG=run/xxx/host.log
JVM_LOG=run/xxx/jvm.log
FIXTURE=run/xxx/ops.json
mkdir -p run/xxx

printf '== xxx 能力（fork=%s）==\n' "$PM_ROOT"

printf '\n-- 宿主侧：真插件调 API -> op，反向应用 -> 插件读回\n'
if ! MUKKIT_PM_ROOT="$PM_ROOT" php -d memory_limit=512M pm-host/tests/pm-xxx-test.php 2>&1 | tee "$LOG"; then
    printf '\nxxx 未通过（宿主侧），日志：%s\n' "$LOG"
    exit 1
fi

printf '\n-- JVM 侧：映射与打包（离线）\n'
if ! mvn -o -q -pl mukkit-nukkit -am test-compile; then
    printf '\nxxx 未通过：JVM 侧编译失败\n' >&2
    exit 1
fi

NK_JAR="$(ls "$HOME/.m2/repository/cn/nukkit/Nukkit/MOT-SNAPSHOT/Nukkit-MOT-SNAPSHOT.jar" 2>/dev/null | head -1)"
if [ -z "$NK_JAR" ]; then
    printf 'FAIL 找不到 Nukkit jar，先跑 scripts/install-nukkit-mot.sh\n' >&2
    exit 1
fi
CP="$NK_JAR:mukkit-nukkit/target/classes:mukkit-nukkit/target/test-classes:mukkit-bridge/target/classes"

if ! java -cp "$CP" cn.mukkit.nukkit.XxxCheck "$FIXTURE" >"$JVM_LOG" 2>&1; then
    grep -E '^FAIL|^xxx-jvm' "$JVM_LOG" || true
    printf '\nxxx 未通过（JVM 侧），日志：%s\n' "$JVM_LOG"
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
require_line 'xxx probe phar loaded'        "$LOG" '探针 phar 被 PM 自己的 PluginManager 加载'
require_line 'the API call reaches the bridge' "$LOG" 'PM 调用产生 op'
require_line 'is what the plugin reads back'   "$LOG" 'Nukkit 改的状态插件读得到'
require_line 'is refused rather than applied'  "$LOG" '越界值只报不应用'
require_line 'xxx PASS'                     "$LOG" '宿主侧全部断言通过'
require_line 'xxx-jvm PASS'                 "$JVM_LOG" 'JVM 侧映射与打包通过'

if [ "$fail" -ne 0 ]; then
    printf '\nxxx 未通过，日志：%s %s\n' "$LOG" "$JVM_LOG"
    exit 1
fi
printf '\nxxx PASS\n'
