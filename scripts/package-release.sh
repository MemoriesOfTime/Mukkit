#!/usr/bin/env bash
#
# 打发行包（方案 B）：一个 jar 里带四个平台的 PHP 运行时，发行包只有一个 zip。
#
#   plugins/Mukkit.jar      ← 已经包含 runtime/<platform>/...
#   INSTALL.txt
#
# 用户解压到服务端根目录就完事：不需要系统 PHP，也不需要挑平台——首次启动时 jar 只解当前
# 平台那一份到 plugins/Mukkit/php/<platform>/（约 34 MB，之后按 .version 跳过）。
#
# 用法：
#   bash scripts/fetch-php.sh --platform all   # 先把四个平台下齐（约 47 MB）
#   bash scripts/package-release.sh            # 出 dist/mukkit-<ver>.zip
#   bash scripts/package-release.sh --no-build # 复用现有 jar
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

DIST="$ROOT/dist"
JAR="$ROOT/mukkit-nukkit/build/libs/Mukkit.jar"
ALL=(macos-arm64 macos-x86_64 linux-x86_64 windows-x64)

BUILD=1
for arg in "$@"; do
    case "$arg" in
        --no-build) BUILD=0 ;;
        *) printf 'unknown option: %s\n' "$arg" >&2; exit 2 ;;
    esac
done

if [ "$BUILD" -eq 1 ]; then
    echo "== build Mukkit.jar（含运行时，会比较慢）"
    ./gradlew -q :mukkit-nukkit:jar
fi
[ -f "$JAR" ] || { printf 'FAIL 没有 %s（去掉 --no-build）\n' "$JAR" >&2; exit 1; }

VERSION="$(sed -n 's/^version=//p' gradle.properties | head -1)"
VERSION="${VERSION:-0.0.0}"

included="$(unzip -l "$JAR" | sed -n 's|.*runtime/\([^/]*\)/\.version.*|\1|p' | sort -u | tr '\n' ' ')"
if [ -z "$included" ]; then
    printf 'FAIL jar 里没有任何运行时。先跑：bash scripts/fetch-php.sh --platform all\n' >&2
    printf '     （不想带运行时也可以发瘦 jar，但那就不是方案 B 了）\n' >&2
    exit 1
fi
for p in "${ALL[@]}"; do
    case " $included " in
        *" $p "*) ;;
        *) printf 'WARN jar 里缺少 %s（只带 %s）\n' "$p" "$included" >&2 ;;
    esac
done

mkdir -p "$DIST"

read -r -d '' INSTALL <<'TXT' || true
Mukkit —— 把 PocketMine 插件跑进 Nukkit
========================================

安装：
  1. 解压到 Nukkit 服务端的根目录（本包会放到 plugins/Mukkit.jar）。
  2. 启动服务端。首次启动会从 jar 里解出当前平台的 PHP 运行时（约 34 MB），耗时几秒。
  3. 日志里出现 `Mukkit: 宿主已连接（profile=pm）` 即成功。

不需要：
  - 系统 PHP
  - 手动选平台（jar 里有 macOS arm64/x86_64、Linux x86_64、Windows x64 四份）

检查运行时：
  plugins/Mukkit/php/<platform>/ 只会有当前平台那一份；换机器时删掉它，jar 会重新解对应平台。

注意：
  - 本包只有 Mukkit，不含 Nukkit 本体；Nukkit 需要 Java 21+。
  - 想用系统 PHP：设 MUKKIT_PHP_BINARY，或在 plugins/Mukkit/config.yml 里写 php-binary。
TXT

stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/plugins"
cp "$JAR" "$stage/plugins/Mukkit.jar"
printf '%s\n' "$INSTALL" > "$stage/INSTALL.txt"

out="$DIST/mukkit-$VERSION.zip"
rm -f "$out"
(cd "$stage" && zip -qr "$out" .)

printf 'ok    %s (%s)\n' "$(basename "$out")" "$(du -h "$out" | cut -f1)"
printf '      运行时：%s\n' "$included"
printf '\n== 产物在 %s\n' "$DIST"
