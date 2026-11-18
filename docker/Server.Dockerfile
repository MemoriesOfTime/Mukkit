# 真实插件测试服的最小镜像：只要 JDK 21。
#
# 为什么不用 docker/Dockerfile 那个工具链镜像：
#   - 起服只需要 java；Gradle/Maven 是构建用的，PHP 由 Mukkit.jar 自带（linux-x86_64）。
#   - 镜像从 929 MB 降到约 460 MB，拉取和启动都更快。
#
# 为什么钉 linux/amd64：
#   pmmp 的 PHP-Binaries 只发 Linux-x86_64，没有 Linux-arm64。Apple Silicon 上如果按
#   默认的 arm64 起容器，宿主会看到 platform=linux-arm64，找不到自带运行时，退回系统
#   PHP（没有 pmmpthread），BedcoreProtect / ChestShop 一 enable 就把宿主带崩。
#   走 amd64 模拟（Docker Desktop 的 Rosetta/QEMU）才能用 jar 里那份真 PHP。
#
# Docker Hub 拉不动时，可以在构建脚本里换镜像站：
#   SERVER_BASE_IMAGE=docker.m.daocloud.io/library/eclipse-temurin:21-jre bash scripts/docker-serve.sh
ARG BASE_IMAGE=eclipse-temurin:21-jre
FROM --platform=linux/amd64 ${BASE_IMAGE}

WORKDIR /work

# 构建期自检：Java 能跑，且架构确实是 x86_64
RUN java -version && test "$(uname -m)" = "x86_64"
