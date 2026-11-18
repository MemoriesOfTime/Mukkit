#!/usr/bin/env bash
#
# M3 判据：真 phar 插件拿到的是**真 Player**，位置 / 背包 / 消息转发都对。
#
#   1. PlayerJoinEvent 送达插件，$player->getName() 是 Nukkit 报上来的名字
#   2. getPosition() 与 Nukkit 报的坐标一致（join 帧带 x/y/z/yaw/pitch）
#   3. 背包可写可读（M3 的"背包"：本进程内存里的 PlayerInventory）
#   4. sendMessage() → TextPacket op，目标是这个玩家的 UUID
#   5. 聊天经 Player::chat() → PlayerChatEvent → 格式化后以 packet op 回到玩家
#   6. 玩家发起的命令以玩家为 sender 走 CommandMap，回复以 packet op 回给该玩家
#   7. PlayerQuitEvent 送达；退出后的聊天被拒绝而不是投给幽灵玩家
#   8. 没有 PM 等价物的事件仍然被明确拒绝，不是静默吞掉
#
# 需要 fork 先装依赖（同 M2）。插件在 examples/pm-probe，测试时现场打成 phar。
set -uo pipefail

cd "$(dirname "$0")/.."

PM_ROOT="${MUKKIT_PM_ROOT:-../PocketMine-MP}"
if [ ! -f "$PM_ROOT/src/mukkit/polyfill.php" ]; then
    printf 'FAIL 找不到 fork：%s\n' "$PM_ROOT" >&2
    exit 1
fi

mkdir -p run/m3
LOG=run/m3/last.log

printf '== M3 玩家代理（fork=%s）==\n' "$PM_ROOT"
if php -d memory_limit=512M pm-host/tests/pm-player-test.php 2>&1 | tee "$LOG"; then
    :
else
    printf '\nM3 未通过，日志：%s\n' "$LOG"
    exit 1
fi

fail=0
require_line() {
    if grep -qF -- "$1" "$LOG"; then
        printf 'ok    %s\n' "$2"
    else
        printf 'FAIL  %s\n' "$2"
        fail=1
    fi
}

require_line 'PlayerJoinEvent reached the plugin with a real Player' 'join 事件送达真 Player'
require_line 'getPosition() matches what Nukkit reported'            '位置与 Nukkit 一致'
require_line 'inventory round trip'                                  '背包可写可读'
require_line 'sendMessage() produced a TextPacket op'                'sendMessage 产出 packet op'
require_line 'PlayerChatEvent reached the plugin'                    '聊天事件送达'
require_line 'a player-issued command runs with the player as sender' '玩家命令以玩家身份执行'
require_line 'PlayerQuitEvent reached the plugin'                    'quit 事件送达'
require_line 'chat after quit is refused'                            '退出后聊天被拒绝'
require_line 'M3 player proxy PASS'                                  '全部断言通过'

if [ "$fail" -ne 0 ]; then
    printf '\nM3 未通过，日志：%s\n' "$LOG"
    exit 1
fi
printf '\nM3 PASS\n'
