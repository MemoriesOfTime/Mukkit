#!/usr/bin/env bash
#
# plugin profile 端到端：一个**原版 Nukkit-MOT 服务端**，把 Mukkit.jar 丢进 plugins/，再加一个 .phar 插件。
#
# 这个脚本刻意不用任何自带的启动器：产品形态就是"jar 装进 plugins/"，所以验证也必须走这条路。
# 控制台命令通过 stdin 喂进去（Nukkit 控制台命令不带前导斜杠）。
#
# 判据：jar 插件被加载 → PHP 宿主起来 → phar 插件 onEnable → 事件 → 发包 → onDisable。
#
# 用法: scripts/run-m1.sh
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DATA="${MUKKIT_DATA:-$ROOT/run/m1}"
if ! NUKKIT_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
LOG="$DATA/m1.log"
PLUGIN_JAR="$ROOT/mukkit-nukkit/build/libs/Mukkit.jar"

if [ "${MUKKIT_SKIP_BUILD:-0}" != "1" ]; then
    echo "== 1/3 build (plugin jar + example phar)"
    ./gradlew -q :mukkit-nukkit:jar
else
    echo "== 1/3 reuse the existing plugin jar"
fi
bash scripts/build-example-plugin.sh

echo "== 2/3 prepare $DATA"
mkdir -p "$DATA/plugins"
# 只清可变状态，保留 bin/（首次启动下载的 3MB 方块调色板）
rm -rf "$DATA/worlds" "$DATA/players" "$DATA/logs" "$DATA/server.properties" "$DATA/nukkit-mot.yml" "$LOG"
rm -f "$DATA/plugins"/*.phar "$DATA/plugins"/*.jar
cp "$PLUGIN_JAR" "$DATA/plugins/"
cp examples/stub-chat/build/StubChat.phar "$DATA/plugins/"

# 这套判据验收的是 HostedPlugin 契约（仅测试用），必须显式钉住 profile=plugin。
# 不写这一行，jar 里的 PocketMine 会让 auto 选 pm，StubChat 会被 PM 按 PluginBase 拒绝——
# 那是正确的行为，只是这套判据测的不是它。
mkdir -p "$DATA/plugins/Mukkit"
cat > "$DATA/plugins/Mukkit/config.yml" <<'YAML'
profile: plugin
YAML

cat > "$DATA/server.properties" <<'EOF'
server-port=0
enable-query=off
level-type=flat
spawn-protection=0
white-list=off
xbox-auth=off
EOF

cat > "$DATA/nukkit-mot.yml" <<'EOF'
performance-settings:
  thread-watchdog: false
network-settings:
  nethernet:
    enabled: false
EOF

echo "== 3/3 boot the real server (${NUKKIT_JAR##*/})"
set +e
(
    # 等 "Done (" 出现再喂命令，否则命令会在服务器启动过程中被丢掉
    for _ in $(seq 1 600); do
        if [ -f "$LOG" ] && grep -q "Done (" "$LOG"; then
            break
        fi
        sleep 0.25
    done
    sleep 1
    echo "mukkit demo"
    sleep 2
    echo "mukkit status"
    sleep 1
    echo "stop"
) | (cd "$DATA" && java -jar "$NUKKIT_JAR") > "$LOG" 2>&1
CODE=$?
set -e

if [ "$CODE" -ne 0 ]; then
    echo
    echo "FAIL: server exited with $CODE"
    tail -30 "$LOG"
    exit "$CODE"
fi

echo
echo "== assertions"
fail=0
check() {
    if grep -qF -- "$2" "$LOG"; then
        echo "  ok    $1"
    else
        echo "  FAIL  $1  (missing: $2)"
        fail=1
    fi
}
check "jar plugin loaded by Nukkit"   "Loading Mukkit v"
check "plugin enabled"                "Mukkit: 已启用"
check "php host connected"            "宿主已连接"
# The plugin profile only observes events, so the host must NOT claim chat delivery - if it did,
# Nukkit's own broadcast would be suppressed and chat would vanish.
check "plugin profile leaves delivery to Nukkit" "broadcasts=nukkit"
# Same rule for commands: the plugin profile has no CommandMap, so claiming them would swallow
# every command it cannot run.
check "plugin profile leaves commands to Nukkit" "commands=nukkit"
check "phar plugin loaded"            "loaded StubChat"
check "phar plugin enabled"           "StubChat enabled"
check "console command reached PHP"   "已注入合成事件"
check "join event produced a packet"  "欢迎 Steve"
check "chat event produced broadcast" "hello from Mukkit"
check "status command answered"       "host       pid"
check "plugin disabled on shutdown"   "StubChat disabled"

if [ "$fail" -ne 0 ]; then
    echo
    echo "FAIL: see $LOG"
    exit 1
fi

echo
echo "PASS: plugin profile met on an unmodified Nukkit server (Mukkit.jar in plugins/, phar next to it)"
