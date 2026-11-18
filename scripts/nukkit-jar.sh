#!/usr/bin/env bash
#
# 输出 Nukkit-MOT 服务端 jar 的绝对路径。所有验收脚本都从这里取，不再各自猜路径。
#
# 顺序：
#   1. MUKKIT_NUKKIT_JAR（显式指定，路径不存在直接失败，不静默回退）
#   2. ../Nukkit-MOT/target/Nukkit-MOT-SNAPSHOT.jar（./gradlew bootstrap 构建的本地仓库）
#
# 只往 stdout 写路径，诊断信息走 stderr。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [ -n "${MUKKIT_NUKKIT_JAR:-}" ]; then
    if [ ! -f "$MUKKIT_NUKKIT_JAR" ]; then
        printf 'FAIL  MUKKIT_NUKKIT_JAR 指向的文件不存在：%s\n' "$MUKKIT_NUKKIT_JAR" >&2
        exit 1
    fi
    printf '%s\n' "$MUKKIT_NUKKIT_JAR"
    exit 0
fi

NUKKIT_DIR="${NUKKIT_MOT_DIR:-$ROOT/../Nukkit-MOT}"
JAR="$NUKKIT_DIR/target/Nukkit-MOT-SNAPSHOT.jar"
if [ -f "$JAR" ]; then
    printf '%s\n' "$JAR"
    exit 0
fi

cat >&2 <<EOF
FAIL  找不到 Nukkit-MOT 服务端 jar：$JAR

先跑：
  ./gradlew bootstrap

或者显式指定：
  MUKKIT_NUKKIT_JAR=/path/to/Nukkit-MOT-SNAPSHOT.jar <command>
EOF
exit 1
