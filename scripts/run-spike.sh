#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "$0")/.."

if [ "${MUKKIT_SKIP_BUILD:-0}" != "1" ]; then
    echo "== building =="
    ./gradlew -q :mukkit-spike:jar
fi

JAR="$(ls mukkit-spike/build/libs/mukkit-spike-*.jar 2>/dev/null | head -1)"
[ -n "$JAR" ] || { echo "找不到 mukkit-spike jar，先跑 ./gradlew :mukkit-spike:jar" >&2; exit 1; }

echo
echo "== capability probe =="
php pm-host/bin/pm-probe.php || true

echo
echo "== spike: 200 ticks at 20 TPS =="
java -jar "$JAR" --ticks=200 --rate=20
