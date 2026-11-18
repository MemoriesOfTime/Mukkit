#!/usr/bin/env bash
#
# 起一个"能真进服"的服务端，用来做 L4 真机验收（docs/operations/smoke.md）。
#
# 与 run-product.sh 的区别：那个是自测——port=0、往 stdin 喂命令、自己 stop。
# 这个是给人或客户端连的：固定端口、离线认证、前台运行、Ctrl-C 退，
# 世界与插件数据留在目录里，下次起来还是同一个服。
#
# 用法：
#   scripts/serve.sh                       # 默认 19132，真实插件 + 全部探针
#   MUKKIT_PLUGINS=real scripts/serve.sh   # 只装真实 PM 插件（Docker 默认）
#   MUKKIT_PLUGINS=probe scripts/serve.sh  # 只装探针 + EconomyAPI（L4 冒烟用）
#   PORT=25565 scripts/serve.sh
#   MUKKIT_SERVER_DIR=/tmp/srv scripts/serve.sh   # 复用目录（保留世界与玩家数据）
#   scripts/serve.sh --no-build            # 跳过 Gradle，直接用现有 jar
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${PORT:-19132}"
DATA="${MUKKIT_SERVER_DIR:-$ROOT/run/server}"
if ! NUKKIT_JAR="$(bash scripts/nukkit-jar.sh)"; then
    exit 1
fi
JAR="$ROOT/mukkit-nukkit/build/libs/Mukkit.jar"

# 带 pmmpthread 的 PHP（官方 pmmp 构建）放在 vendor/php/<平台>，scripts/fetch-php.sh 负责下载。
# 有它，宿主的 AsyncPool 就是真的 worker 线程；没有就退回 PATH 里的 stock PHP + 同步池。
platform_id() {
    case "$(uname -s)-$(uname -m)" in
        Darwin-arm64) echo macos-arm64 ;;
        Darwin-x86_64) echo macos-x86_64 ;;
        Linux-x86_64) echo linux-x86_64 ;;
        MINGW*|MSYS*|CYGWIN*) echo windows-x64 ;;
        *) echo "" ;;
    esac
}
PHP_BIN="${MUKKIT_PHP_BINARY:-}"
PLATFORM="$(platform_id)"
# 容器里默认用 jar 自带的那份：host 的 vendor/php 里有宿主平台写死的 php.ini
# （zend_extension 指向 macOS 的 opcache.so），在 Linux 容器里会加载失败。
if [ -z "$PHP_BIN" ] && [ "${MUKKIT_BUNDLED_PHP:-0}" != "1" ] && [ -n "$PLATFORM" ]; then
    for candidate in "$ROOT/vendor/php/$PLATFORM/bin/php7/bin/php" "$ROOT/vendor/php/$PLATFORM/bin/php"; do
        if [ -x "$candidate" ]; then
            PHP_BIN="$candidate"
            break
        fi
    done
fi
if [ -n "$PHP_BIN" ]; then
    export MUKKIT_PHP_BINARY="$PHP_BIN"
    THREADS="$("$PHP_BIN" -r 'echo extension_loaded("pmmpthread") ? "pmmpthread" : "no-threads";' 2>/dev/null || echo unknown)"
    echo "== php: $PHP_BIN ($THREADS)"
else
    echo "== php: 用 Mukkit.jar 自带的运行时（找不到时退回 PATH 里的 php）"
fi

PLUGINS="${MUKKIT_PLUGINS:-all}"
case "$PLUGINS" in
    all|real|probe) ;;
    *)
        printf 'MUKKIT_PLUGINS 只能是 all / real / probe（当前：%s）\n' "$PLUGINS" >&2
        exit 2
        ;;
esac

# real 模式会清掉探针；宿主机上默认换一个数据目录，避免把 run/server 的全量测试服改残。
if [ "$PLUGINS" = "real" ] && [ -z "${MUKKIT_SERVER_DIR:-}" ] && [ "$DATA" = "$ROOT/run/server" ]; then
    DATA="$ROOT/run/real-server"
fi

BUILD=1
for arg in "$@"; do
    case "$arg" in
        --no-build) BUILD=0 ;;
        *) printf 'unknown option: %s\n' "$arg" >&2; exit 2 ;;
    esac
done

if [ "$BUILD" -eq 1 ]; then
    echo "== build Mukkit.jar"
    ./gradlew -q :mukkit-nukkit:jar
fi
if [ ! -f "$JAR" ]; then
    printf '找不到 %s（去掉 --no-build 重跑一次）\n' "$JAR" >&2
    exit 1
fi

echo "== prepare $DATA"
mkdir -p "$DATA/plugins"
cp "$JAR" "$DATA/plugins/"

# 真实插件清单（Poggit 归档，版本钉死在 scripts/fetch-plugins.sh）。EconomyAPI 走源码构建，
# 不在这份清单里。Docker 真机环境默认只装这一组，探针一个都不装。
REAL_PLUGINS=(EconomyAPI MultiWorld Texter Wilderness BedcoreProtect ChestShop ScoreHud)
PROBE_PLUGINS=(MukkitStateProbe MukkitFormProbe MukkitInventoryProbe MukkitEntityProbe
    MukkitBlockProbe MukkitDropProbe MukkitEventProbe MukkitWorldProbe MukkitPacketProbe
    MukkitContainerProbe MukkitTileProbe)

install_economyapi() {
    local src=""
    for candidate in "$ROOT/run/m2/plugins/EconomyAPI.phar" "$ROOT/run/server/plugins/EconomyAPI.phar" "$DATA/plugins/EconomyAPI.phar"; do
        if [ -s "$candidate" ]; then
            src="$candidate"
            break
        fi
    done
    if [ -z "$src" ]; then
        echo "== build EconomyAPI（本地没有现成 phar）"
        bash "$ROOT/scripts/build-economyapi.sh"
        src="$ROOT/run/m2/plugins/EconomyAPI.phar"
    fi
    if [ "$src" != "$DATA/plugins/EconomyAPI.phar" ]; then
        cp "$src" "$DATA/plugins/EconomyAPI.phar"
    fi
}

install_real_plugins() {
    # 先在本地找现成的：run/server 是常驻测试服，run/m2 是经济插件构建目录。找到就复制，
    # 找不到才走网络。这样 Docker 首次起服不用把 6 个 phar 再下一遍。
    local name src
    for name in "${REAL_PLUGINS[@]}"; do
        [ "$name" = "EconomyAPI" ] && continue
        [ -s "$DATA/plugins/$name.phar" ] && continue
        for src in "$ROOT/run/server/plugins/$name.phar" "$ROOT/run/m2/plugins/$name.phar"; do
            if [ -s "$src" ]; then
                cp "$src" "$DATA/plugins/$name.phar"
                break
            fi
        done
    done
    # fetch 是幂等的：已经在盘子上的会 skip，不会重复下载。
    bash "$ROOT/scripts/fetch-plugins.sh" "$DATA/plugins"
    install_economyapi
    local missing=0
    for name in "${REAL_PLUGINS[@]}"; do
        if [ ! -s "$DATA/plugins/$name.phar" ]; then
            printf 'FAIL  真实插件缺失：%s.phar\n' "$name" >&2
            missing=1
        fi
    done
    [ "$missing" -eq 0 ] || exit 1
}

remove_probes() {
    local name
    for name in "${PROBE_PLUGINS[@]}"; do
        rm -f "$DATA/plugins/$name.phar"
    done
}

# probe 模式只留 EconomyAPI（冒烟第 3 条要用 /money），其余真实插件清掉，避免互相干扰。
remove_real_plugins() {
    local name
    for name in "${REAL_PLUGINS[@]}"; do
        [ "$name" = "EconomyAPI" ] && continue
        rm -f "$DATA/plugins/$name.phar"
    done
}

if [ "$PLUGINS" = "real" ]; then
    install_real_plugins
    remove_probes
else
    install_economyapi
    if [ "$PLUGINS" = "probe" ]; then
        remove_real_plugins
    fi
fi

# 探针按需现打：源码比 phar 新就重打，省得手改忘了重建。real 模式不碰探针。
if [ "$PLUGINS" != "real" ]; then
    for pair in \
        pm-state-probe:MukkitStateProbe \
        pm-form-probe:MukkitFormProbe \
        pm-inventory-probe:MukkitInventoryProbe \
        pm-entity-probe:MukkitEntityProbe \
        pm-block-probe:MukkitBlockProbe \
        pm-drop-probe:MukkitDropProbe \
        pm-event-probe:MukkitEventProbe \
        pm-world-probe:MukkitWorldProbe \
        pm-packet-probe:MukkitPacketProbe \
        pm-container-probe:MukkitContainerProbe \
        pm-tile-probe:MukkitTileProbe
    do
        src="examples/${pair%%:*}"
        out="$DATA/plugins/${pair##*:}.phar"
        [ -f "$src/plugin.yml" ] || continue
        if [ ! -f "$out" ] || [ -n "$(find "$src" -type f -newer "$out" | head -1)" ]; then
            bash scripts/build-plugin-phar.sh "$src" "$out" >/dev/null
        fi
    done
fi

if [ ! -f "$DATA/server.properties" ]; then
    cat > "$DATA/server.properties" <<EOF
server-port=$PORT
server-udp-ports=$PORT
xbox-auth=off
level-type=flat
spawn-protection=0
white-list=off
enable-query=off
EOF
else
    # 端口与离线认证是进服的前提，每次覆盖；其余设置留给用户。
    sed -i '' -e "s/^server-port=.*/server-port=$PORT/" "$DATA/server.properties" 2>/dev/null || \
        sed -i -e "s/^server-port=.*/server-port=$PORT/" "$DATA/server.properties"
    # WebRTC 媒体端口必须跟着 server-port 走。默认值 19132 是"跟随 server-port"的哨兵，
    # 但一旦端口换成 19133，媒体仍会留在 19132 —— 客户端 signaling 通了却找不到媒体路径，
    # 报 "no network path to this server"。所以两个端口一起写。
    for kv in "xbox-auth=off" "white-list=off" "server-udp-ports=$PORT"; do
        key=${kv%%=*}
        if grep -q "^$key=" "$DATA/server.properties"; then
            sed -i '' -e "s/^$key=.*/$kv/" "$DATA/server.properties" 2>/dev/null || \
                sed -i -e "s/^$key=.*/$kv/" "$DATA/server.properties"
        else
            echo "$kv" >> "$DATA/server.properties"
        fi
    done
fi

if [ ! -f "$DATA/nukkit-mot.yml" ]; then
    cat > "$DATA/nukkit-mot.yml" <<'EOF'
performance-settings:
  thread-watchdog: false
network-settings:
  nethernet:
    # 1.26.60+ 的客户端已经没有 RakNet，只能走 NetherNet（signaling 走 TCP，媒体走
    # server.properties 的 server-udp-ports）。关掉它，真机连不上。
    enabled: true
netease-settings:
  # 网易开发客户端走 RakNet 8 + raw-deflate 批包头。Nukkit 默认按标准 zlib 解，
  # 握手能过、第一个批包就报 malformed。L4 冒烟用的就是网易客户端，所以默认开。
  client-support: true
  only-allow-netease-client: false
EOF
else
    # 老数据目录里这个值可能是 false（Nukkit 默认）。只改这一行，不动管理员的其他配置。
    if grep -q '^  client-support: false' "$DATA/nukkit-mot.yml"; then
        sed -i '' 's/^  client-support: false/  client-support: true/' "$DATA/nukkit-mot.yml" 2>/dev/null || \
            sed -i 's/^  client-support: false/  client-support: true/' "$DATA/nukkit-mot.yml"
    elif ! grep -q '^netease-settings:' "$DATA/nukkit-mot.yml"; then
        printf '\nnetease-settings:\n  client-support: true\n  only-allow-netease-client: false\n' >> "$DATA/nukkit-mot.yml"
    fi
fi

# Mukkit 的配置留在默认值（profile: auto → jar 里带了 fork 就用 pm）。
LAN_IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo 127.0.0.1)"

printf '\n== 插件模式：%s\n' "$PLUGINS"
for phar in "$DATA/plugins"/*.phar; do
    [ -e "$phar" ] || continue
    printf '   %s\n' "$(basename "$phar")"
done

cat <<EOF

== 服务端准备就绪
   本机连：  127.0.0.1:$PORT
   局域网连：$LAN_IP:$PORT
   数据目录：$DATA
   进服后（控制台不带斜杠）：mukkit status | mukkit plugins | mukkit demo
   客户端：用任意 Bedrock 客户端连 127.0.0.1:$PORT（离线认证已开）

EOF

cd "$DATA"
exec java -jar "$NUKKIT_JAR"
