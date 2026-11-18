#!/usr/bin/env bash
# 在 Docker 里跑 M1 测试入口（scripts/run-m1.sh）。
# 用法：
#   bash scripts/docker-test.sh [run-m1.sh 的参数...]
#   MUKKIT_NO_BUILD=1 bash scripts/docker-test.sh   # 跳过镜像构建，复用已有镜像
set -euo pipefail

# 用脚本自身位置推导仓库根，不依赖调用者的 cwd
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

IMAGE=mukkit-test-env
GRADLE_VOLUME=mukkit-gradle

if ! docker info >/dev/null 2>&1; then
    echo "错误：Docker 不可用。请先启动 Docker Desktop，并确认 \`docker info\` 能正常返回。" >&2
    echo "（本脚本不会尝试 sudo 提权，需要你在宿主侧自行处理好 Docker 守护进程。）" >&2
    exit 1
fi

if [ "${MUKKIT_NO_BUILD:-0}" = "1" ]; then
    echo "==> 跳过镜像构建（MUKKIT_NO_BUILD=1），复用镜像 $IMAGE"
else
    echo "==> 构建镜像 ${IMAGE}（context = 仓库根 ${REPO_ROOT}）"
    docker build -f docker/Dockerfile -t "$IMAGE" .
fi

# 仓库根挂到 /work；Nukkit-MOT 由 Gradle 从 repo.lanink.cn 解析到 /root/.gradle 卷，
# 首次运行需要容器能访问该仓库（之后复用卷里的缓存）。
echo "==> 运行容器：$REPO_ROOT -> /work，卷 $GRADLE_VOLUME -> /root/.gradle"

set +e
docker run --rm \
    -v "$REPO_ROOT":/work \
    -v "$GRADLE_VOLUME":/root/.gradle \
    -w /work \
    "$IMAGE" \
    bash scripts/run-m1.sh "$@"
RC=$?
set -e

if [ "$RC" -eq 0 ]; then
    echo "==> 结果：成功（exit 0）"
else
    echo "==> 结果：失败（exit ${RC}）"
fi

exit "$RC"
