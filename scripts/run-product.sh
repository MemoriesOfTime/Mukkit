#!/usr/bin/env bash
#
# 产品级验收：**一个原版 Nukkit-MOT 服务端 + 一个 Mukkit.jar + 一个真 PM 插件**。
#
# 与 run-m1.sh 的区别：run-m1 钉的是 HostedPlugin 契约（仅测试用），这一个走默认路径——
# profile: auto，也就是"jar 里带了 PocketMine 就用它"。断言的是：
#   - 宿主以 profile=pm 起来（不是回退到 plugin）
#   - PocketMine 自己加载并启用了 EconomyAPI（ready 帧里 runtime=pocketmine）
#   - EconomyAPI 的 onEnable 真的跑过（它写出了自己的 config.yml 和 PlayerLang.dat）
#   - 聊天投递权交给了宿主（broadcasts=host），世界镜像打开（mirror=true）
#   - 全程没有出现 main.class.missing
#
# 这才是"把 Mukkit.jar 丢进 plugins/ 就支持 PM 插件"这句话的判据。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# 默认每次跑一个全新的数据目录：产品验收要验的是"首次解包 + 首次开 PM"，
# 复用旧目录就得先删掉一整套解包树，而递归删除在某些受限环境里会被拦下来。
# 想复用就显式给 MUKKIT_DATA（那时才会清理上一轮的状态）。
if [ -n "${MUKKIT_DATA:-}" ]; then
    DATA="$MUKKIT_DATA"
    REUSE=1
else
    DATA="$ROOT/run/product/$(date +%Y%m%d-%H%M%S)-$$"
    REUSE=0
fi
if ! NUKKIT_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
LOG="$DATA/product.log"
PLUGIN_JAR="$ROOT/mukkit-nukkit/build/libs/Mukkit.jar"
ECON_PHAR="$ROOT/run/m2/plugins/EconomyAPI.phar"

echo "== 1/5 build"
if [ "${MUKKIT_SKIP_BUILD:-0}" != "1" ]; then
    ./gradlew -q :mukkit-nukkit:jar :mukkit-nukkit:testClasses
fi
if [ ! -f "$ECON_PHAR" ]; then
    bash scripts/build-economyapi.sh >/dev/null
fi

echo "== 2/5 prepare $DATA"
mkdir -p "$DATA/plugins"
# plugins/Mukkit 整个删掉：这样每次跑的都是"首次解包"路径，而不是被上一版 stamp 跳过的路径
# 不删整棵树（这里的 shell 会把大目录递归删除当成个人文件操作拦下来），只删掉"必须重来"的东西：
#   - 解包戳：删掉即强制重新从 jar 解包，正是要验的路径
#   - EconomyAPI 的插件数据：不删的话"onEnable 真的跑过"就成了上一轮留下的证据
#   - 上一轮的 jar / phar / 日志
rm -f "$DATA/plugins"/*.phar "$DATA/plugins"/*.jar "$LOG"
rm -f "$DATA/plugins/Mukkit/pm-host"/.unpacked-* "$DATA/plugins/Mukkit/pocketmine"/.unpacked-*
rm -rf "$DATA/plugins/EconomyAPI" "$DATA/plugins/Mukkit/pm-data/plugin_data/EconomyAPI"
cp "$PLUGIN_JAR" "$DATA/plugins/"
cp "$ECON_PHAR" "$DATA/plugins/"

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

# 默认配置：profile 留空 → auto
rm -f "$DATA/plugins/Mukkit/config.yml"

echo "== 3/5 boot the real server (${NUKKIT_JAR##*/})"
set +e
(
    for _ in $(seq 1 1200); do
        if [ -f "$LOG" ] && grep -q "Done (" "$LOG"; then
            break
        fi
        sleep 0.25
    done
    sleep 1
    echo "mukkit status"
    sleep 2
    echo "stop"
) | (cd "$DATA" && java -jar "$NUKKIT_JAR") > "$LOG" 2>&1
CODE=$?
set -e

echo "== 4/5 extract summary"
grep -E "Mukkit: (宿主|已启用)|\[mukkit\] (plugin host up|host ready)|profile=|EconomyAPI" "$LOG" | head -20

echo "== 5/5 assertions"
fail=0
check() {
    if grep -qF -- "$2" "$LOG"; then
        echo "  ok    $1"
    else
        echo "  FAIL  $1  (missing: $2)"
        fail=1
    fi
}
absent() {
    if grep -qF -- "$2" "$LOG"; then
        echo "  FAIL  $1  (unexpected: $2)"
        fail=1
    else
        echo "  ok    $1"
    fi
}

check "jar loaded by Nukkit"        "Loading Mukkit v"
check "host booted the fork"        "profile=pm"
check "host ran on the bundled PHP" "plugins/Mukkit/php/"
check "PocketMine loaded the phar"  "runtime=pocketmine"
check "plugin reported enabled"     "status=enabled"
check "chat delivery owned by host" "broadcasts=host"
check "player commands owned by host" "commands=host"
check "world mirror enabled"        "mirror=true"
check "default world known at boot" "world: default=world"
check "item vocabulary resolves"    "item vocabulary: 4/4"
check "bridge sees one PM plugin"   "1 个 PM 插件"
absent "no HostedPlugin rejection"  "main.class.missing"
absent "did not fall back"          "回退到 plugin"

if [ ! -f "$DATA/plugins/EconomyAPI/config.yml" ]; then
    echo "  FAIL  EconomyAPI onEnable ran  (missing plugins/EconomyAPI/config.yml)"
    fail=1
else
    echo "  ok    EconomyAPI onEnable ran"
fi
if [ ! -f "$DATA/plugins/EconomyAPI/PlayerLang.dat" ]; then
    echo "  FAIL  EconomyAPI wrote its state  (missing plugins/EconomyAPI/PlayerLang.dat)"
    fail=1
else
    echo "  ok    EconomyAPI wrote its state"
fi
if [ -d "$DATA/plugins/Mukkit/pm-data/plugin_data/EconomyAPI" ]; then
    echo "  FAIL  the isolated layout was populated as well"
    fail=1
else
    echo "  ok    data went to plugins/EconomyAPI/ only"
fi
if [ ! -f "$DATA/plugins/Mukkit/pocketmine/src/mukkit/polyfill.php" ]; then
    echo "  FAIL  fork source extracted from the jar"
    fail=1
else
    echo "  ok    fork source extracted from the jar"
fi

# 运行时布局的离线判据：Windows 包是 bin/php/php.exe、Unix 是 bin/php7/bin/php。
# 只看"服务器起来了"不够 —— 认不出布局时会静默回退系统 PHP，而那台机器上可能根本没有。
NK_JAR="$(bash scripts/nukkit-jar.sh 2>/dev/null || true)"
if [ -n "$NK_JAR" ] && [ -d mukkit-nukkit/build/classes/java/test ]; then
    if java -cp "$NK_JAR:mukkit-nukkit/build/classes/java/main:mukkit-nukkit/build/classes/java/test:mukkit-bridge/build/classes/java/main" \
        cn.mukkit.nukkit.PhpRuntimeCheck; then
        echo "  ok    PHP runtime layouts are recognised"
    else
        echo "  FAIL  PHP runtime layouts are not recognised"
        fail=1
    fi
fi

if [ "$CODE" -ne 0 ]; then
    echo "  FAIL  server exited with $CODE"
    fail=1
fi

if [ "$fail" -ne 0 ]; then
    echo
    echo "FAIL: see $LOG"
    exit 1
fi

echo
echo "PRODUCT PASS"
