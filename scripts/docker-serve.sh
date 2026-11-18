#!/usr/bin/env bash
#
# 起一个"能真进服"的 docker 服务端：容器里跑 scripts/serve.sh，宿主机只负责端口。
#
# 与 docker-test.sh 的区别：那个跑 run-m1.sh 自测，退出即结束；这个常驻，给客户端连。
# 与直接跑 serve.sh 的区别：JDK 在镜像里，PHP 由 Mukkit.jar 自带，宿主机只需要 Docker。
#
# 镜像固定 linux/amd64：pmmp 没有 Linux-arm64 的 PHP 构建。Apple Silicon 上走
# Rosetta/QEMU 模拟，能用 jar 里那份 linux-x86_64 的真 PHP（带 pmmpthread），
# BedcoreProtect / ChestShop 才能正常起 SQLite worker。
#
# 默认装的是**真实 PocketMine 插件**（EconomyAPI / MultiWorld / Texter / Wilderness /
# BedcoreProtect / ChestShop / ScoreHud），不是 examples/ 里的探针。要跑 L4 冒烟，用
# PLUGINS=probe；同时装真实插件和探针的组合留在宿主机（`MUKKIT_PLUGINS=all scripts/serve.sh`）。
#
# 用法：
#   bash scripts/docker-serve.sh                 # 宿主机 19132 -> 容器 19132（UDP + TCP），真实插件
#   PORT=19133 bash scripts/docker-serve.sh      # 19132 被占用时换一个
#   PLUGINS=probe bash scripts/docker-serve.sh   # 只装探针 + EconomyAPI（L4 冒烟）
#   MUKKIT_NO_BUILD=1 bash scripts/docker-serve.sh   # 跳过镜像构建
#
# NetherNet 的 signaling 走 TCP、媒体走 UDP，所以两个协议都要发布；只映射 UDP 会让
# 1.26.60+ 的客户端连不上（它们已经没有 RakNet 了）。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${PORT:-19132}"
PLUGINS="${PLUGINS:-real}"
case "$PLUGINS" in
    real|probe) ;;
    *)
        printf 'PLUGINS 只能是 real / probe（当前：%s）。要同时装真实插件和探针，在宿主机跑 serve.sh。\n' "$PLUGINS" >&2
        exit 2
        ;;
esac
if [ "$PLUGINS" = "real" ]; then
    NAME="${NAME:-mukkit-real}"
    DATA="${MUKKIT_SERVER_DIR:-$ROOT/run/docker-real}"
    IMAGE="${SERVER_IMAGE:-mukkit-server}"
    SERVER_PLATFORM=linux/amd64
else
    NAME="${NAME:-mukkit-probe}"
    DATA="${MUKKIT_SERVER_DIR:-$ROOT/run/docker-probe}"
    IMAGE="${SERVER_IMAGE:-mukkit-test-env}"
    SERVER_PLATFORM=""
fi
WORKSPACE_ROOT="$(cd "$ROOT/.." && pwd)"

if ! docker info >/dev/null 2>&1; then
    printf '错误：Docker 不可用。先启动 Docker Desktop，确认 `docker info` 能返回。\n' >&2
    exit 1
fi

if [ "${MUKKIT_NO_BUILD:-0}" = "1" ]; then
    printf '== 跳过镜像构建，复用 %s\n' "$IMAGE"
    if ! docker image inspect "$IMAGE" >/dev/null 2>&1; then
        printf '镜像 %s 不存在，去掉 MUKKIT_NO_BUILD=1 先构建一次\n' "$IMAGE" >&2
        exit 1
    fi
elif [ "$PLUGINS" = "real" ]; then
    printf '== 构建镜像 %s（%s）\n' "$IMAGE" "$SERVER_PLATFORM"
    build_server_image() {
        docker build --platform "$SERVER_PLATFORM" \
            --build-arg BASE_IMAGE="$1" \
            -f docker/Server.Dockerfile -t "$IMAGE" .
    }
    if ! build_server_image "${SERVER_BASE_IMAGE:-eclipse-temurin:21-jre}"; then
        printf '== Docker Hub 拉取失败，改用镜像站重试\n'
        build_server_image "docker.m.daocloud.io/library/eclipse-temurin:21-jre"
    fi
else
    printf '== 构建镜像 %s（工具链，含 PHP/Gradle）\n' "$IMAGE"
    docker build -f docker/Dockerfile -t "$IMAGE" .
fi

# 数据目录在仓库里（由 /work 挂进容器），容器用同一份路径看世界与插件。
case "$DATA" in
    "$WORKSPACE_ROOT"/*) DATA_IN_CONTAINER="/work/${DATA#"$WORKSPACE_ROOT"/}" ;;
    *)
        printf 'MUKKIT_SERVER_DIR 必须在 %s 之下（当前：%s）\n' "$WORKSPACE_ROOT" "$DATA" >&2
        exit 2
        ;;
esac

mkdir -p "$DATA/plugins"

# 首次起服时 Nukkit 会从 GitHub 拉方块 palette（约 3 MB，走代理常常失败）。
# 仓库里已经有一份，直接借过来，容器里就不用再下载。
if [ ! -d "$DATA/bin" ] && [ -d "$ROOT/run/server/bin" ]; then
    cp -R "$ROOT/run/server/bin" "$DATA/bin"
fi

if [ "$PLUGINS" = "real" ]; then
    printf '== 准备真实插件（Poggit 归档，版本钉死）\n'
    # 常驻测试服里已经有一份，先复制过来，避免每次换数据目录都重下。
    for name in MultiWorld Texter Wilderness BedcoreProtect ChestShop ScoreHud; do
        if [ ! -s "$DATA/plugins/$name.phar" ] && [ -s "$ROOT/run/server/plugins/$name.phar" ]; then
            cp "$ROOT/run/server/plugins/$name.phar" "$DATA/plugins/$name.phar"
        fi
    done
    bash "$ROOT/scripts/fetch-plugins.sh" "$DATA/plugins"
    if [ ! -s "$ROOT/run/m2/plugins/EconomyAPI.phar" ] \
        && [ ! -s "$ROOT/run/server/plugins/EconomyAPI.phar" ] \
        && [ ! -s "$DATA/plugins/EconomyAPI.phar" ]; then
        printf '== 本地没有 EconomyAPI.phar，先构建\n'
        bash "$ROOT/scripts/build-economyapi.sh"
    fi
fi

docker rm -f "$NAME" >/dev/null 2>&1 || true

printf '== 启动 %s（宿主机 %s -> 容器 19132，UDP+TCP，插件 %s%s）\n' \
    "$NAME" "$PORT" "$PLUGINS" "${SERVER_PLATFORM:+，$SERVER_PLATFORM}"
# -i 保留 stdin、-t 给 Nukkit 一个真终端，否则 `docker attach` 只能看日志、不能敲命令。
run_args=(-dit --rm --name "$NAME")
[ -n "$SERVER_PLATFORM" ] && run_args+=(--platform "$SERVER_PLATFORM")
env_args=(-e MUKKIT_SERVER_DIR="$DATA_IN_CONTAINER" -e MUKKIT_PLUGINS="$PLUGINS")
[ "$PLUGINS" = "real" ] && env_args+=(-e MUKKIT_BUNDLED_PHP=1)
# The probe server is driven by scripts/smoke-client.sh, which sends commands over RCON
# (the chat field needs a real IME, so it cannot be scripted). Keep RCON on loopback only;
# the real-plugin server does not publish it.
rcon_args=()
if [ "$PLUGINS" = "probe" ]; then
    rcon_args+=(-p 25575:25575/tcp)
fi
if ! docker run "${run_args[@]}" \
    -v "$WORKSPACE_ROOT":/work \
    -w /work/Mukkit \
    "${env_args[@]}" \
    -p "$PORT":19132/udp \
    -p "$PORT":19132/tcp \
    "${rcon_args[@]}" \
    "$IMAGE" \
    bash scripts/serve.sh --no-build; then
    printf '\n启动失败。若提示端口被占用，换一个：PORT=19133 bash scripts/docker-serve.sh\n' >&2
    exit 1
fi

printf '== 等待服务端就绪%s\n' "${SERVER_PLATFORM:+（amd64 模拟下首次启动会慢一些）}"
ready=0
for _ in $(seq 1 180); do
    # 不要用 `grep -q`：它找到就退出，docker logs 会收到 SIGPIPE，pipefail 下整条
    # 管道返回 141，循环永远判不成功。grep 读完整个日志再决定。
    if docker logs "$NAME" 2>&1 | grep -F 'Done (' >/dev/null; then
        ready=1
        break
    fi
    sleep 1
done

docker logs "$NAME" 2>&1 | grep -E 'Opening server|NetherNet|插件模式|宿主已连接|Done \(|ERROR' | tail -8

if [ "$ready" -ne 1 ]; then
    printf '\n服务端没有在 180s 内就绪，完整日志：docker logs %s\n' "$NAME" >&2
    exit 1
fi

# 真实插件模式要能回答两个问题：装进去了没有、有没有混进探针。
if [ "$PLUGINS" = "real" ]; then
    host_line="$(docker logs "$NAME" 2>&1 | grep '宿主已连接' | tail -1)"
    if [ -z "$host_line" ]; then
        printf '\n宿主没有连上，看不到插件清单。完整日志：docker logs %s\n' "$NAME" >&2
        exit 1
    fi
    case "$host_line" in
        *Probe*)
            printf '\n真实插件模式里混进了探针：\n%s\n' "$host_line" >&2
            exit 1
            ;;
    esac
    missing=0
    for name in EconomyAPI MultiWorld Texter Wilderness BedcoreProtect ChestShop ScoreHud; do
        case "$host_line" in
            *"$name"*) ;;
            *)
                printf 'FAIL  插件没有启用：%s\n' "$name" >&2
                missing=1
                ;;
        esac
    done
    if [ "$missing" -ne 0 ]; then
        printf '\n宿主只加载了：\n%s\n完整日志：docker logs %s\n' "$host_line" "$NAME" >&2
        exit 1
    fi
    printf '\n== 真实插件已全部启用（无探针）\n'
    printf '   %s\n' "$host_line"
fi

LAN_IP="$(ipconfig getifaddr en0 2>/dev/null || ipconfig getifaddr en1 2>/dev/null || echo '')"
printf '\n== 可以进了\n'
printf '   本机：  127.0.0.1:%s\n' "$PORT"
[ -n "$LAN_IP" ] && printf '   局域网：%s:%s\n' "$LAN_IP" "$PORT"
printf '   插件：  %s（%s）\n' "$PLUGINS" "$DATA/plugins"
printf '   离线认证已开（xbox-auth=off），名字随便填；服务端版本见 MOTD。\n'
printf '   日志：docker logs -f %s    停止：docker stop %s\n' "$NAME" "$NAME"
printf '   控制台：docker attach %s（Ctrl-P Ctrl-Q 退出，不会停服）\n' "$NAME"
