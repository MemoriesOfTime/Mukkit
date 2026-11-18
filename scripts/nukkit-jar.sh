#!/usr/bin/env bash
#
# 输出 Nukkit-MOT 服务端 jar 的绝对路径。所有验收脚本都从这里取，不再各自猜路径。
#
# 顺序：
#   1. MUKKIT_NUKKIT_RESOLVED_JAR（Gradle 的 run*/serve 传入的解析结果）
#   2. Gradle 解析结果（repo.lanink.cn 的 cn.nukkit:Nukkit）
#
# 只往 stdout 写路径，诊断信息走 stderr。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -n "${MUKKIT_NUKKIT_RESOLVED_JAR:-}" ]; then
    if [ ! -f "$MUKKIT_NUKKIT_RESOLVED_JAR" ]; then
        printf 'FAIL  MUKKIT_NUKKIT_RESOLVED_JAR 指向的文件不存在：%s\n' "$MUKKIT_NUKKIT_RESOLVED_JAR" >&2
        exit 1
    fi
    printf '%s\n' "$MUKKIT_NUKKIT_RESOLVED_JAR"
    exit 0
fi

# 和 mukkit-nukkit/build.gradle.kts 用同一个来源：Gradle 解析到的 jar。
# 只给直接在 shell 里跑的脚本用；Gradle 的 run*/serve 会在 doFirst 里设 MUKKIT_NUKKIT_RESOLVED_JAR，
# 不会走到这里（同一项目目录里嵌套 ./gradlew 会互相等锁）。
RESOLVED="$( cd "$ROOT" && ./gradlew -q :mukkit-nukkit:printNukkitJar 2>/dev/null \
    | sed -n '/\.jar$/p' | tail -1 || true )"
if [ -n "$RESOLVED" ] && [ -f "$RESOLVED" ]; then
    printf '%s\n' "$RESOLVED"
    exit 0
fi

cat >&2 <<EOF
FAIL  找不到 Nukkit-MOT 服务端 jar

Nukkit-MOT 只从 repo.lanink.cn 解析（cn.nukkit:Nukkit），先确认网络/代理能访问该仓库。
在仓库根手动检查：
  ./gradlew :mukkit-nukkit:printNukkitJar
EOF
exit 1
