#!/usr/bin/env bash
#
# 下载一组真实 PocketMine 插件到 run/server/plugins（Poggit 归档，版本钉死）。
# 这份清单是"真实插件"的唯一出处：Docker 真机环境（PLUGINS=real）只装这里的插件，
# examples/ 下的探针一个都不装。EconomyAPI 不在归档里，由 scripts/build-economyapi.sh 构建。
#
# 为什么是 Poggit 归档：poggit.pmmp.io 已经只做 301 跳转，发布物归档在
# github.com/poggit/release-archive，路径是 <作者>/<插件>/<版本>/<插件>_<版本>.phar。
# 钉版本而不是"取最新"：服务端是钉住的 PM 5.44.4，插件哪天更新到更高 API 就装不上了。
#
# 选这些插件的理由（都是 API 5.x，能落在桥已有的能力面上）：
#   MultiWorld      世界管理 + 传送 + 命令       → 世界接口、玩家命令
#   Texter          悬浮文字（实体/名牌）         → 实体镜像
#   Wilderness      野外保护/命令                → 权限、命令、方块事件
#   BedcoreProtect  方块日志（libasynql 线程）    → 需要 scripts/fetch-php.sh（真线程）
#   ChestShop       牌子商店（libasynql 线程）    → 需要 scripts/fetch-php.sh（真线程）
#   ScoreHud        计分板（18 万下载）           → SetDisplayObjective/SetScore
#
# 试过但装不上的（记在 docs/operations/plugins.md，别重复踩）：
#   ShortSneak 1.1.1    plugin.yml 里 mcpe-protocol 只有 594，服务端协议是 2225 → 拒绝
#   PiggyShopUI 3.0.3 / SimpleHome 1.1.0 / PiggyCustomEnchants 3.0.9   只支持 API 4.x
#   FlyPE 3.8.3 / AutoXP 1.1.0 / LockIt 1.3.5   只支持 API 3.x
#
# 用法：
#   bash scripts/fetch-plugins.sh            # 下到 run/server/plugins
#   bash scripts/fetch-plugins.sh /tmp/plugins
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${1:-$ROOT/run/server/plugins}"
BASE="https://raw.githubusercontent.com/poggit/release-archive/master"

# 作者/插件/版本 —— 版本必须与 docs/operations/plugins.md 的清单一致。
PLUGINS=(
    "CzechPMDevs/MultiWorld/2.1.2"
    "fuyutsuki/Texter/4.3.3"
    "Muqsit/Wilderness/2.1.3"
    "matcracker/BedcoreProtect/1.2.4"
    "Muqsit/ChestShop/5.3.9"
    "Ifera/ScoreHud/8.0.0"
)

mkdir -p "$DEST"

if ! command -v curl >/dev/null 2>&1; then
    printf '需要 curl 才能下载插件；容器里请先在宿主机跑一遍 fetch-plugins.sh，或用带 curl 的工具链镜像。\n' >&2
    exit 1
fi

for entry in "${PLUGINS[@]}"; do
    name="$(basename "$(dirname "$entry")")"
    version="$(basename "$entry")"
    out="$DEST/$name.phar"
    if [ -s "$out" ]; then
        printf 'skip  %-16s %s（已存在）\n' "$name" "$version"
        continue
    fi
    url="$BASE/$entry/${name}_${version}.phar"
    code="$(curl -fsSL --max-time 180 -o "$out.part" -w '%{http_code}' "$url" || true)"
    if [ "$code" != "200" ] || [ ! -s "$out.part" ]; then
        # raw.githubusercontent 在代理下会握手成功但拉一半断掉（HTTP/2 framing / empty reply）。
        # 同一个文件走 GitHub contents API 的 raw 模式，通常能整份拿到；只在失败时兜底。
        api="https://api.github.com/repos/poggit/release-archive/contents/$entry/${name}_${version}.phar"
        printf 'retry %-16s %s（raw=%s，改走 API）\n' "$name" "$version" "${code:-?}" >&2
        code="$(curl -fsSL --max-time 180 -H 'Accept: application/vnd.github.raw' \
            -o "$out.part" -w '%{http_code}' "$api" || true)"
    fi
    if [ "$code" != "200" ] || [ ! -s "$out.part" ]; then
        printf 'FAIL  %-16s %s（HTTP %s）\n' "$name" "$version" "${code:-?}" >&2
        rm -f "$out.part"
        exit 1
    fi
    # 一个 phar 至少得是 PHP 文件；归档偶尔会给 404 页面，别把 HTML 当插件装进去。
    if ! head -c 5 "$out.part" | grep -q '<?php'; then
        printf 'FAIL  %-16s 下载到的不是 phar\n' "$name" >&2
        rm -f "$out.part"
        exit 1
    fi
    mv "$out.part" "$out"
    printf 'ok    %-16s %s (%s)\n' "$name" "$version" "$(du -h "$out" | cut -f1)"
done
