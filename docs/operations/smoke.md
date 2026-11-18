# 真机冒烟（L4）

一句话：这份清单只回答一件事——**桥送出去的包，真实客户端画得对、画完还能收回来**。

真机测试是唯一能回答"客户端收到之后画成什么"的环节。桥内判据钉的是两端的形状：插件发出的
对象对、Nukkit 能 `encode()`、字段不丢；它不覆盖真实渲染。这里出错的代价是返工一层
（包映射、id 空间、生命周期），不是补一条断言。所以先跑这 12 条，再谈扩面。

## 两条路

**A. 网易开发者客户端（macOS 本机，可脚本化）**

客户端自动化需要一个本仓库之外的驱动，按自己的环境准备。下面的命令用 `mcpy` 作为
占位命令名，换成你自己的驱动即可；判据不依赖具体工具。本文用到的驱动能力是：
会话管理、截图、客户端 Python 探针、UI 输入。

> **必须在用户自己的终端里跑。** 启动器要调 `sandbox-exec`，而 Agent 的进程树本身已在沙箱里，
> 嵌套会被系统拒绝（`sandbox_apply: Operation not permitted`，退出码 71）。这不是权限没给，
> 是 macOS 不允许套第二层沙箱——换任何参数都绕不过去。

```bash
# 终端 1：服务端（固定 19132、xbox-auth=off，前台运行，Ctrl-C 退）
scripts/serve.sh

# 终端 2：客户端
mkdir -p /tmp/mukkit-smoke
mcpy --local --project /tmp/mukkit-smoke --non-interactive connect 127.0.0.1 \
     --port 19132 --detach --json --player-name Smoke
```

返回里的 `session` 后面每条都要用：

```bash
mcpy --local --project /tmp/mukkit-smoke --non-interactive status --session <sid> --json
mcpy --local --project /tmp/mukkit-smoke --non-interactive screenshot --session <sid> \
     --background-only --output run/smoke/01-join.png --json
mcpy --local --project /tmp/mukkit-smoke --non-interactive runtime py --session <sid> \
     --side client --file run/smoke/probe.py --json
mcpy --local --project /tmp/mukkit-smoke --non-interactive stop --session <sid> --json
```

`connection_verified=true` 只表示"曾观察到 HUD"，不表示持续在线，更不证明玩法对 —— 判据是
下面的每一条。聊天、点击 UI 由驱动自己的 UI 输入接口完成，具体用法看驱动文档。

**两个待确认的事实**（第一次连上之前不要当成已知）：

- **网易版的协议版本**。引擎 `3.10.100.301421` 没有对外声明它对应哪个 Bedrock 协议；连不上时
  先看服务端日志有没有握手记录，再考虑 `nukkit-mot.yml` 的 `network-settings.nethernet`
  （Nukkit-MOT 对 1.26.60+ 只接受 NetherNet/WebRTC，默认关着）。
- **进服是否需要开发客户端特权**。文档只说"服务器须允许无认证开发客户端"，`xbox-auth=off`
  是必要条件，不一定是充分条件。

**B. 真实玩家（手机 / PC 国际版客户端）**

同一份清单，手工做，每步截屏。这条路的价值是**换一个客户端版本**（第 11 条），
以及验证"没有开发客户端特权"的普通连接。

## 12 条

服务端起来后，8 个探针插件都已装好（`scripts/serve.sh` 负责）。控制台命令不带斜杠。

| # | 做什么 | 期望 | 失败时先看 |
|---|---|---|---|
| 1 | 进服 | 玩家列表有 `Smoke`；控制台 `mukkit status` 显示宿主在线、`mirror=true`；`player.join` 只出现一次 | `unknown player`、`player.join` op |
| 2 | 聊天一句 | 聊天框**只有一行**（两行 = 投递方统一失效） | `broadcasts=host` |
| 3 | `/money` | EconomyAPI 回余额；控制台没有 `unknown command` | `commands=host`、`command` op |
| 4 | `/form-open` | 弹窗出现，标题与两个按钮文字正常，不糊 | `ModalFormRequestPacket` |
| 5 | 在第 4 条里点按钮 | 聊天框回报所选下标（回答链路通） | `form.response`、`FormLedger` |
| 6 | `/m4packets` | 标题、副标题、Toast、侧边栏、音效、表单六样都出现且不互相覆盖 | 白名单 9 种、`packet.unsupported` |
| 7 | `/inv-set` | 手上出现改名钻石剑，名字带颜色码；重进仍在 | `player.inventory` op、NBT |
| 8 | `/m5write`，再 `/bp-protect` 后挖方块 | 客户端看得到方块变化；保护区内挖不动、区外正常 | `block` op、`event.result` |
| 9 | `/e-item`、`/e-name` | 面前出现掉落物；改名后客户端名牌跟着变 | `entity.sync` |
| 10 | `/st-kick` | 自己被踢，断连屏有理由文案；重进正常 | `DisconnectPacket` op |
| 11 | 换一个客户端版本，重跑 1–7 | 全对（这是"没烤死协议字节"的唯一证据） | `GameVersion` 映射 |
| 12 | 跑图 10 分钟 | 不漂移；`mukkit status` 的 p99 稳定；RSS 不持续上涨 | `chunk` / `chunk.unload` |

## 事件面追加（需要真客户端）

上表第 8 条只覆盖了 `block.break`。决定型事件共 14 条，其中这些只有真客户端能判：

| # | 做什么 | 期望 | 失败时先看 |
|---|---|---|---|
| E1 | `/ev-cancel player.toggle.sneak on`，按潜行 | 客户端按下去又弹回站立；`/ev-cancel player.toggle.sneak off` 后恢复 | `held actions` 里的 `player.toggle.sneak` 计数 |
| E2 | `/ev-cancel player.item.held on`，按 1/2 切热栏 | 选中的格子回到原来那一格（服务端与客户端一致） | `MobEquipmentPacket`、`player.item.held` |
| E3 | 在耕地上种小麦，`/ev-cancel block.grow on` | 作物停在当前阶段；`off` 后继续长 | `block.grow` 的 held/replayed 计数 |
| E4 | 死一次再重生 | `/ev-report` 里 `ev-seen player.respawn=1`，且没有 `event.result` | `player.respawn` 只在通知层 |

E1/E2 的价值在"拒绝之后客户端回得去"——服务端状态对、客户端显示错，是这类事件最典型的错法。

## 容器追加（需要真客户端）

容器没有"包映射层"可退回：窗口是 Nukkit 里的一个真 `ContainerInventory`，客户端点击走的是
`InventoryTransactionEvent`。这三条只有真客户端能判。

| # | 做什么 | 期望 | 失败时先看 |
|---|---|---|---|
| C1 | `/ct-open` | 弹出 27 格箱子，0 号钻石 ×5、1 号苹果 ×3；关闭后重开还在 | 日志 `window 2 (nukkit ...) opened`、`window.open` op |
| C2 | 把钻石拖到自己背包 | 箱子 0 号空、背包多出钻石；`ct-report` 里箱子只剩苹果 | `inventory.transaction`、`event.result`、`window.slot` |
| C3 | `/ct-cancel on`，再把苹果拖走 | 苹果回到箱子（客户端回弹），`ct-report` 仍有两样 | `capabilities`、`ct-event ... cancelled` |
| C4 | `/ct-close` | 箱子界面关闭，没有残留窗口 | `window.close` op |
| C5 | 死一次（掉进虚空或 `/kill`） | 插件收到 `player.death`；死亡屏文案正常 | `player.death` op、`drops`/`xp` |

C3 的重点是**回弹**：服务端拒绝了，客户端不能自己留在"已经拿走"的画面上。

## 方块实体追加（需要真客户端）

`getTile()` 有没有东西、内容对不对，只有把插件放到真服务端上才能判。箱子/告示牌的位置
用插件命令里的坐标，`tl-*` 来自 `examples/pm-tile-probe`。

| # | 做什么 | 期望 | 失败时先看 |
|---|---|---|---|
| T1 | 放一个箱子，塞两样东西，`/tl-report <x> <y> <z>` | `type=Chest items=...`，与箱子里一致；走远再回来仍然一致 | `chunk` 帧带 `tiles`、`tile` 帧、`tile:` 开头的警告 |
| T2 | 让另一个玩家（或漏斗）改箱子，再 `/tl-report` | 几 tick 内读到新内容 | `TileMirror` 轮询（`mukkit.tileInterval`）、`tile` 帧 |
| T3 | `/tl-set <x> <y> <z> 0 diamond 3`，打开箱子看 | 箱子里真的多出 3 个钻石 | `tile` op、`tile ... has no matching block entity` |
| T4 | 立一块牌子写好字，`/tl-report`，再 `/tl-sign <x> <y> <z> hi` | 插件读到原字；改完牌子上立刻变成 `hi` | `tile` op 的 `front`、告示牌 `spawnToAll()` |
| T5 | `/tl-open <x> <y> <z>` 打开一个真实箱子，拖动物品 | 有开箱动画/音效；`tl-report` 与客户端一致；没有物品回弹或复制 | `window ... bound to the block entity`、`tile` 帧方向 |

T5 的重点是**绑定**：如果日志里出现的是 `opened (... slots)` 而不是 `bound to the block entity`，
说明窗口开在了替身上，箱子里的东西会和客户端显示的分叉。

## 判据

- **过 10 条**：达到通过门槛，扩面继续
- **6–9 条**：按失败项归类，先修真机这一层再谈新能力
- **不足 6 条**：包映射层有系统性错误，暂停扩面

## 结果记录

每次跑完填一行，附日志与截图路径。这是"兼容性分级"的第一手证据。

| 日期 | 客户端 | 版本 | 通过 | 失败项 | 日志 / 截图 |
|---|---|---|---|---|---|
| 2026-11-18 | 网易开发客户端 | 3.10.100.301421 / 898 | 8/12 | 虚拟容器窗口不可见；实体/长跑/换版本未跑 | `run/smoke/20261118-204154/`（聊天 UI 计数见 `20261118-212631/`） |

## 当前结果（Docker 探针服）

**环境**

- 客户端：网易开发客户端 `3.10.100.301421`，进服协议 `898 (1.21.130_NetEase, RakNet)`。
- 服务端：`PLUGINS=probe scripts/docker-serve.sh`；容器里是 Nukkit-MOT 1.26.60（标准协议 2225），
  必须开 `netease-settings.client-support: true`，否则网易客户端第一个批包会被当成 zlib 解，
  日志是 `Failed to decode batch packet ... Unable to inflate Zlib stream`。
- 首次跑之前把 `run/docker-probe/plugins/Mukkit/config.yml` 的 `hard-deadline-ms` 从 500 调到
  5000：第一次 join 要同时下发 5×5 区块并建 PM Player，500 ms 会让宿主重启、join 丢失。
  这是探针服的临时绕过，不是产品默认值；正式修法见
  [`roadmap.md`](../project/roadmap.md) 的背压与首连预算。
- 驱动：`scripts/smoke-client.sh`（RCON + 外部客户端驱动 + AppleScript 粘贴）。服务端要开 RCON；
  `docker-serve.sh` 的 probe 模式已经发布 25575。

**当前通过项**

进服与 `st-join`、聊天（UI 树里一条）、`/mymoney`、简单表单显示、自定义表单回答
（`custom: [0, "", false, 1]`）、标题/副标题/Toast/侧边栏、`/inv-set`、`/m5write`、`/st-kick`。
离线判据 `run-forms.sh` / `run-containers.sh` / `run-m4.sh` 同时保持全绿。

**当前阻塞与判断**

1. **虚拟容器窗口不可见（未修）**：`ct-open` 在宿主侧是 `window 2 opened`，客户端没有窗口。
   真实方块箱子还没判：`setblock` 放下的 chest 没有建出 tile，`tl-open` 报
   `not a container`，需要先修 Nukkit 侧的方块实体创建路径。
2. **首连 500 ms 硬超时（探针服已绕过）**：默认 `hard-deadline-ms: 500` 在第一次 join
   （5×5 区块 + 建 PM Player）会让宿主重启，join 直接丢失。探针服从 `plugins/Mukkit/config.yml`
   改成 5000 后稳定；正式修法见 roadmap。
3. **客户端 20 s 超时（harness 已绕过）**：网易客户端在没有输入约 20 s 后停止 RakNet
   keepalive，服务端报 `transport:timed_out`。`smoke-client.sh` 用热栏轮换保活，并等
   HUD 就绪后再开聊天。
4. **聊天"两行"是 UI 叠加，不是投递重复**：截图里两行 `<Smoke> hello from smoke` 是聊天界面
   本身 + 它背后 HUD 聊天栈的叠加。`runtime input observe --view ui` 的聊天文本树只有一条；
   Nukkit 侧确认 `PlayerChatEvent` 被取消（`chat suppressed`），PM 只发一个 `TextPacket`。
   第 2 条按 UI 树判。

**未跑**

第 8 条的保护区挖掘（`/bp-protect` + 客户端 dig）、第 9 条实体、第 11 条换客户端版本、
第 12 条 10 分钟跑图；容器 C1–C5、方块实体 T1–T5 仍未过。按 12 条判据只有 8 条可判通过，
低于 10 条门槛；先修虚拟容器窗口，再补剩余项。
