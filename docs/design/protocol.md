# 桥接协议

## 传输

- 默认 Unix domain socket（`/tmp` 下，路径短于 104 字节）。TCP 变体只在 `HostChannel` 里有实现，`HostProcess` 仍只连 unix，所以 Windows 目前走的是 JDK 的 AF_UNIX 支持（待办）
- 帧格式：`uint32 大端长度` + UTF-8 JSON 载荷，单帧上限 16 MiB
- 同一时刻只有一个连接，不做复用
- 当前版本：`proto = 18`（`Protocol.VERSION`，两侧必须相同）

用 JSON 不是因为它快，而是因为出问题时可以拿 `cat` 看。热路径（每 tick 的方块增量）
将来会换独立的二进制帧，控制面保持 JSON。

## 信封

```json
{"t": "tick", "id": 1001, "p": { }}
```

`t` 类型，`id` 单调递增（双向各自计数），`p` 载荷。应答必须回填相同的 `id`。

## 握手

```
PHP  ── hello{proto, codec, php, profile, caps} ──►  JVM
PHP  ◄──────────── welcome{proto, tickRate, softDeadlineMs, codec, defaultWorld}
PHP  ◄──────────── plugins{list:[{name, path}]}
PHP  ── ready{loaded, rejected, broadcasts, commands, mirror, events[]} ──►  JVM
```

握手期任意一侧版本不符立即断开，不做降级兼容 —— 桥的两半必须同版本发布。

`welcome.defaultWorld` 是 Nukkit 默认 level 的名字（没有则是空串）。它必须在 `ready` **之前**
到达，因为宿主在 `ready` 里报告插件加载结果，而插件在加载时就会 `onEnable` —— 那个时点
`getDefaultWorld()` 已经要有值。宿主拿它建好世界并注册进 `WorldManager`，才启用插件。

`broadcasts` 说明**谁负责把聊天/加入/退出消息送到客户端**。`pm` profile 报 `host`：
它跑 PocketMine 自己的 `PlayerChatEvent` 并广播格式化结果，适配器必须停掉 Nukkit 自己那份，
否则每行出现两遍。`stub` / `plugin` 只观察事件、不投递任何东西，报 `jvm`：适配器保留自己的
广播，不然聊天会静默消失。缺省视作 `jvm` —— 宁可重复，也不要哑掉。

`commands` 说明**谁执行玩家敲的命令**。`pm` 报 `true`：它有插件的 `CommandMap`，适配器把 Nukkit
不认识的命令名转给宿主（见"玩家命令"）。`stub` / `plugin` 没有 `CommandMap`，报 `false`，命令原样
留给 Nukkit。缺省视作 `false` —— 声明不了就不要接管。宿主掉线时适配器把标记清掉，命令退回 Nukkit。

`ready` 里的 `rejected` 是能力探测的结果，`reason` 必须是人能看懂的原因码。

`profile` 是宿主侧的运行时选择，走命令行不进协议：
`stub`（不加载任何东西）、`pm`（真 PM）、`plugin`（只加载 `plugin.yml` + `main.php` 的测试宿主）。
三者共用同一条传输与同一套帧。

## tick 帧

```json
{"t":"tick","id":1001,"p":{
  "seq": 1001,
  "in": [
    {"k":"event","name":"player.join","player":"<uuid>","data":{"name":"Steve","op":true}},
    {"k":"command","sender":"console","line":"/money"},
    {"k":"plugin","plugin":"StubChat","name":"enable"}
  ]
}}
```

| `k` | 字段 | 语义 |
|---|---|---|
| `event` | `name`、`player?`、`data`、`id?` | 世界侧事件；`name` 用小写点分（完整清单见 `docs/design/events.md`）。带 `id` 的是**决定型**，必须用 `event.result` 回答；不带的是**通知型**。方块事件（`block.*` / `leaves.*`）没有 `player`，用 `data.world` 定位世界 |
| `command` | `sender`、`player?`、`line` | 命令；带 `player` 时以该玩家身份执行，不带时是控制台 |
| `plugin` | `plugin`、`name`=`enable`/`disable` | 生命周期。JVM 才是"什么时候启用"的权威，宿主只执行，重复项必须幂等 |
| `form.response` | `player`、`formId`、`closed`、`data` | 玩家回答了一个表单；`data` 是客户端给的 JSON 原文，`closed` 表示没有任何数据（见"表单"一节） |

```json
{"t":"tickdone","id":1001,"p":{
  "seq": 1001,
  "ops": [
    {"o":"packet","to":"<uuid>","cls":"TextPacket","f":{"type":"chat","message":"§ahi"}},
    {"o":"log","level":"info","msg":"..."}
  ],
  "error": null,
  "stats": {"ms": 0.43, "in": 2, "ticks": 1001}
}}
```

`packet` 的 `to` 取 `<uuid>` 或 `"*"`（广播）。`"*"` 不是给插件用的糖：
`PluginContext::broadcast()` 产生的就是它，JVM 侧翻译成"遍历在线玩家"。

约束：

- `in` 只放自上一 tick 以来累积的入站工作，可以是空数组
- `ops` 按产生顺序排列，JVM 按序应用
- `error` 非空表示插件抛了异常，但该 tick 仍然有效（`ops` 里通常有一条 error 日志）
- 宿主不得在同一个 tick 内发送第二个 `tickdone`

## op 类型

| `o` | 字段 | 状态 |
|---|---|---|
| `packet` | `to` 玩家 uuid 或 `"*"`，`cls` 包类名，`f` 字段表 | 已实现，9 种包（见下） |
| `log` | `level`，`msg`（`debug` 只在 Nukkit 的日志级别提到 DEBUG 时才打印） | 已实现 |
| `block` | `world`，`b`：`[[x, y, z, id, meta], ...]` | 已实现（宿主 → 适配器） |
| `player.state` | `to` 玩家 uuid，`s` 字段表 | 已实现（双向往返） |
| `player.inventory` | `to` 玩家 uuid，`slots`：`[[slot, id, count, meta, nbt?], ...]` | 已实现（双向往返） |
| `window.open` | `to`，`window`，`type`，`x/y/z`，`actor` | 已实现（宿主 → 适配器） |
| `window.content` | `to`，`window`，`size`，`slots` | 已实现（宿主 → 适配器，整份快照） |
| `window.slot` | `to`，`window`，`slot`：`[slot, id, count, meta, nbt?]` | 已实现（宿主 → 适配器） |
| `window.close` | `to`，`window` | 已实现（宿主 → 适配器） |
| `window.data` | `to`，`window`，`property`，`value` | 已实现（宿主 → 适配器，熔炉/酿造进度） |
| `world.state` | `world`，`s`：`{time?, stopped?}` | 已实现（双向往返） |
| `tile` | `world`，`tiles[]` | 已实现（宿主 → 适配器，插件写过的方块实体） |
| `entity.sync` | `world`，`e`：`[[id, type, x, y, z, yaw, pitch, extra?], ...]` | 已实现（适配器 → 宿主，整份快照） |
| `entity.state` | `world`，`e`：`[[id, x, y, z, yaw, pitch, extra?], ...]` | 已实现（宿主 → 适配器，只带变化的） |
| `event.result` | `id`（适配器分配），`allowed` | 已实现（宿主 → 适配器） |
| `capabilities` | `events[]`（决定型的 wire 名） | 已实现（宿主 → 适配器，插件启停时；撤回按事件名） |
| `chunk` | `world`，`x`，`z`，`sections[]`，`tiles[]` | 已实现（适配器 → 宿主） |
| `chunk.unload` | `world`，`x`，`z` | 已实现（适配器 → 宿主，玩家走远时撤销） |
| `block.update` | `world`，`x/y/z`，`id`，`meta` | 已实现（适配器 → 宿主） |
| `tile` | `world`，`tiles[]`，`removed[]` | 已实现（适配器 → 宿主，变化的整份快照） |

`packet` 用"类名 + 字段表"而不是原始字节：JVM 侧按 Nukkit 的包类重建对象，
编码交给 Nukkit 的多版本序列化器。这样 PM 冻结的协议版本不会传染给客户端。

字段名用 PocketMine 的，值用**语义名**：枚举传字符串，常量不传数字。理由是数字和序号
是各自的实现细节 —— PM 的 `TYPE_SET_TITLE` 是 2、Nukkit 的 `TYPE_TITLE` 也是 2，但
`DisplaySlot` 在 Nukkit 是枚举而 PM 传的是 `"sidebar"` 这样的字符串，靠数字对不上。

### packet 白名单

| `cls` | `f` 字段 | 备注 |
|---|---|---|
| `TextPacket` | `type`（`raw`/`chat`/`system`/`tip`/`popup`/`announcement`/`whisper`）、`source`、`message` | |
| `SetTitlePacket` | `type`（`clear`/`reset`/`title`/`subtitle`/`actionbar`/`times`/`title_json`/`subtitle_json`/`actionbar_json`）、`text`、`fadeInTime`、`stayTime`、`fadeOutTime` | `xuid`/`platformOnlineId` 不传：桥不做服务端聊天过滤 |
| `ModalFormRequestPacket` | `formId`、`formData` | `formData` 是 JSON 原文，桥不解析 |
| `ClientboundCloseFormPacket` | 无 | 让客户端关掉当前表单；两边都是空载荷，`Player::closeAllForms()` 用它 |
| `ToastRequestPacket` | `title`、`body` | Nukkit 侧字段叫 `content` |
| `PlaySoundPacket` | `soundName`、`x`、`y`、`z`、`volume`、`pitch` | 坐标是**浮点**；Nukkit 的字段是整块坐标（编码时 `<<3`），映射时向下取整，与 Nukkit 自己的 `Level::addSound()` 一致 |
| `SetDisplayObjectivePacket` | `displaySlot`（`list`/`sidebar`/`belowname`）、`objectiveName`、`displayName`、`criteriaName`、`sortOrder`（`0`=升序） | Nukkit 侧是 `DisplaySlot` / `SortOrder` 枚举 |
| `SetScorePacket` | `type`（`change`/`remove`）、`entries[]`：`scoreboardId`、`objectiveName`、`score`、`type`（`player`/`entity`/`fake_player`）、`actorUniqueId`、`customName` | 计分板只有 `SetDisplayObjective` 是画不出内容的，所以这一条与下一条一起进白名单 |
| `RemoveObjectivePacket` | `objectiveName` | |
| `DisconnectPacket` | `message` | **唯一不按包投递的一条**：适配器把它当成"关闭这个玩家"，见"踢人与断开" |

白名单之外的包：宿主按类名计数并**每个类名只报一次** `packet.unsupported` 日志，
绝不猜一个"差不多的包"顶上。判据是"宁可什么都不显示，也不要显示错的东西"。

### 表单（显示 + 回答）

出站那一半就是上面两个包；入站是这条：

```json
{"k":"form.response","player":"<uuid>","formId":1000003,"closed":false,
 "data":"[\"beta\",\"Bobby\",true,4]"}
```

`data` 原样过桥，宿主 `json_decode` 之后交给 `Player::onFormSubmit()`。桥不解释表单的语义：
回答是按钮下标、布尔、还是元素值数组，取决于插件用的是哪种 `Form` 实现 —— PocketMine 5 已经
不带 `SimpleForm`/`CustomForm` 了，插件自带实现才是常态。

**id 是桥发的，不是 Nukkit 发的。** Nukkit 的 `Player::formWindows` 只认 `showFormWindow()`
登记过的 id，桥直接发的包不在里面，响应会被它按 debug 日志丢掉。所以：

- `BridgedPlayer::sendForm()` 覆写上游的分配，从 `1_000_000` 起发号；Nukkit 自己的计数器
  从 0 起，两个区间构造上不重叠（重叠就意味着一个响应会被交给错误的一侧）
- 适配器把发出去的 id 记在按玩家的账本里，`DataPacketReceiveEvent` 在 MONITOR 拦
  `ModalFormResponsePacket`：**认领得到才拦**（`setCancelled(true)`），认领后立即从账本移除，
  所以同一个表单只判一次
- 账本对每个玩家有上限（32 个），超出丢最旧的；玩家退出时清空；宿主重启时全清 —— 新宿主没有
  任何未决表单，留着这些 id 只会把回答转给一个只会报 "unexpected" 的进程

**"关闭"没有标志位。** 协议里客户端要么给 `data`，要么给 `cancelReason`；Nukkit 解码后
`data` 停在默认值 `"null"`，适配器据此报 `closed: true`，宿主按 `onFormSubmit($id, null)` 送达。
坏 JSON、超 10 KiB 的回答同样按关闭释放：上游对这两种情况是抛异常（真机上等于踢人），踢人比
少一次表单更糟，而 `null` 是每个表单都必须处理的分支。

### 事件：决定型与通知型

事件分两层。**通知型**只是"这件事发生了"：`player.join` / `player.quit` / `player.chat` /
`player.respawn` / `player.death`，没有 `id`，也没有回答。死亡帧带 `drops`、`xp` 与
`message`；PM 5 的 `PlayerDeathEvent` 本身不可取消，所以它只是新闻，`keepInventory` 之类
的改写不会回到 Nukkit。

**决定型**是一个判决：插件取消之后，Nukkit 的动作就不该发生。但桥的往返是一 tick 一次，而
Nukkit 在同一个 tick 里就把方块敲掉、把物品扔出去了 —— 所以中间必须有个"先扣住"的动作：

```
JVM:  动作事件(MONITOR, 未被取消, 且宿主声明要这个事件)
        → setCancelled(true)                    ← 先别做
        → 发 event{name, id, data}
PHP:  → 构造真 PM 事件（方块来自镜像、物品由命名空间 id 重建）
        → call()，看 isCancelled()
        → 发 event.result{id, allowed}
JVM:  → 查挂起表：允许就补执行；拒绝就什么都不做（槽位类再补一次纠正包）
```

挂起表按**事件名**分派。判决只有一个布尔，但"允许"的补执行各不相同，撤回（`capabilities`）
与超时也是按名字各算各的：

| `name` | 补执行 | 拒绝时 |
|---|---|---|
| `block.break` | `Level::useBreakOn(x, y, z, item, player, true)` | 方块留着 |
| `player.drop` | `inventory.removeItem(item)` + `Player::dropItem(item)`（创造模式不扣物品） | 物品留在背包里 |
| `player.item.held` | `inventory.setHeldItemIndex(slot)`（发 `MobEquipmentPacket`） | `setHeldItemIndex(from)`，客户端选回旧槽 |
| `player.toggle.sneak/sprint/glide/swim` | `Player::setSneaking/setSprinting/setGliding/setSwimming(state)` | Nukkit 的取消路径已 `needSendData` 纠正 |
| `player.toggle.fly` | `AdventureSettings::set(FLYING, state)->update()` | 同上 |
| `block.grow/spread/form` | `Level::setBlock(pos, newState, true, true)` | 方块停在旧状态 |
| `block.burn` | `Level::setBlock(pos, air, true)` | 方块不烧掉 |
| `leaves.decay` | `Level::useBreakOn(pos)` | 树叶挂着 |

`event`（入站）沿用既有形状，多了一个 `id`：

```json
{"k":"event","name":"block.break","player":"<uuid>","id":7,
 "data":{"x":5,"y":64,"z":5,"world":"world","item":"minecraft:diamond_pickaxe",
         "itemCount":1,"instaBreak":false}}
{"k":"event","name":"player.drop","player":"<uuid>","id":8,
 "data":{"item":["minecraft:diamond_sword",1,7]}}
{"k":"event","name":"player.item.held","player":"<uuid>","id":9,
 "data":{"slot":3,"from":0,"item":["minecraft:diamond_sword",1,7]}}
{"k":"event","name":"player.toggle.sneak","player":"<uuid>","id":10,
 "data":{"state":true}}
{"k":"event","name":"block.grow","id":11,
 "data":{"world":"world","x":5,"y":64,"z":5,"id":1,"meta":0,"newId":59,"newMeta":1}}
{"k":"event","name":"block.spread","id":12,
 "data":{"world":"world","x":5,"y":64,"z":5,"id":3,"meta":0,"newId":2,"newMeta":0,
         "source":{"x":5,"y":64,"z":4,"id":3,"meta":0}}}
```

方块事件**不带 `player`**（世界自己长出来的东西没有玩家），坐标、legacy id/meta 与新状态都在
`data` 里；`block.spread` 多一个 `source` 小表。只有**已镜像区块**里的方块事件会被发送：
没送过的区块在宿主那边是空气，建出来的事件是错的，所以适配器直接不发。

物品只以**命名空间 id** 过桥，从不传数字：两边的数字 id 都是构建相关的，同一个数字在对面是
另一个物品。`block.break` 用扁平的 `item`/`itemCount`（既有形状）；其余用背包那条
元组 `[id, count, damage, nbt?]` —— 改名与附魔要跟着过去。方块用镜像里的 legacy id/meta，
与 `block.update` 同一套方言。

**只有真的有人监听才拦截。** 宿主的 `ready` 帧带 `events` 列表，内容由
`HandlerListManager::global()->getHandlersFor()` 决定 —— 有插件监听 `BlockBreakEvent` 才有
`block.break`，有插件监听 `PlayerToggleSneakEvent` 才有 `player.toggle.sneak`，以此类推。
没有监听者时拦截就是纯粹的延迟和客户端闪烁。插件在运行时被启用/停用会让这个列表变，宿主同 tick
用 `capabilities` op 通知；撤回是按事件名算的，停掉一个监听方块破坏的插件不会顺带放掉别人正在等
的掉落判决。通知型不受此限：它没有判决，也就没有扣住的成本。

**代价（必须记住）**：

- 被拦截的动作**晚一个 tick** 才生效。50 ms 的延迟，玩家可能看到一次"方块碎了又回来"的修正；
  潜行/疾跑的第一帧也会慢半拍。
- `block.break` 的补执行会**再次触发**同一个事件，所以其他 Nukkit 插件每敲一个方块会收到两次
  （一次被取消、一次通过）。桥自己用 `replayingActions` 计数避免二次挂起。`leaves.decay` 的补
  执行也走 `useBreakOn()`，同样会再触发一次；`block.grow/spread/form/burn` 只是 `setBlock`，
  不发事件。
- 判决超时（40 tick ≈ 2 s）就丢弃动作，**方块留着、物品留在背包里、状态不切换**。留给玩家一个
  可见的失败，好过放过去一个没人批准的动作。
- `player.drop` 的补执行前会先确认背包里还有那一堆完整的物品，不在就不丢 —— 宁可少一次掉落，
  也不凭空造出一件物品（创造模式除外，那里本来就不扣）。
- `player.item.held` 的两个方向都要纠正客户端：Nukkit 取消时只 `sendContents()`，不改选中格。
- `player.respawn` 是纯通知：PM 侧 `setRespawnPosition()` 不会传回 Nukkit。
- `inventory.transaction` 的"允许"由宿主执行（`SlotChangeAction::execute()`），适配器只取消
  Nukkit 的事务并等结果；所以拒绝时两边都不动，允许时结果通过窗口内容与 `player.inventory`
  回去。整笔事务因此比普通方块事件多一次"回滚再落定"的可见闪烁。
- `player.death` 是纯通知：死亡已经发生，`keepInventory` / `setDrops()` 只改 PM 侧看到的值。
- **哪些事件能桥、哪些明确不桥**见 `docs/design/events.md`。那里是这张清单的唯一出处，本节只讲机制。

### 玩家命令

```json
{"k":"command","sender":"Steve","player":"<uuid>","line":"money Steve 100"}
{"k":"command","sender":"console","line":"say hi"}
```

`player` 在、且是一个在线玩家时，宿主用**那个 `Player`** 当 `CommandSender` 执行，而不是控制台：
权限、`$sender instanceof Player`、`getPosition()` 都依赖这个身份，插件回复 `$sender` 也才会
回到对的人。不带 `player` 就是控制台 —— 既有形状，行为不变。

命令输出不需要额外的 op：发给发送者的消息本来就会变成 `packet`（玩家）或 `log`（控制台）。

**分流在适配器，不在宿主。** 玩家敲的命令经过 `PlayerCommandPreprocessEvent`，控制台/RCON 的
命令经过 `ServerCommandEvent`，两条路走同一个判断：

| 情况 | 谁执行 | 为什么 |
|---|---|---|
| Nukkit 有这个名字（原版、Nukkit 插件、或两边都有） | Nukkit | 世界归 Nukkit，两边都跑就是执行两遍 |
| Nukkit 没有 | 宿主 | 否则只会看到 "unknown command" |

两边都有的名字一律归 Nukkit：PM 插件想覆盖 `/tp` 这类原版命令做不到，这是明写的边界。

控制台上"两边都跑"是看得见的事故：`list` 先出 PM 的 `0/20`，再出 Nukkit 的 `0/50`；`ver`
同理，还会多一句 `command not handled by any plugin`。所以控制台的分流规则跟玩家完全一样 ——
认识就留给 Nukkit，不认识的取消事件（免得它再答一句 "Unknown command"）后转给宿主。

适配器只在宿主 `ready` 里报 `commands: true` 时接管；掉线时标记跟着清掉，命令退回 Nukkit 的答案 ——
宁可报"未知命令"，也不要把命令行吞进一个不在的进程。

**空行**（去掉空白后为空）算错误：宿主报 `pm command failed`，该 tick 其余工作照常。
**未知或已退出的玩家**只报一条 warn 并丢弃，绝不降级成控制台 —— 否则一个已经离开的玩家能悄悄
执行服务器命令。

**不做的**：补全与 `/help` 的可见性。客户端的补全表来自 Nukkit 的 `AvailableCommandsPacket`，
只有 Nukkit 的命令；要把 PM 的命令塞进去得把 PM 的 `CommandMap` 同步成 Nukkit 的命令注册表，
会牵到别名冲突、权限节点和动态注册/注销，不是镜像而是另一套东西。两个列表因此会不一致：
玩家打错一个 PM 命令时，PM 的"Unknown command"提示会建议 `/help`，而 `/help` 里只有 Nukkit 的命令。

### 玩家状态（双向）

```json
{"o":"player.state","to":"<uuid>","s":{"x":12.5,"y":70.0,"z":-3.25,"yaw":90.0,"pitch":15.0}}
{"o":"player.state","to":"<uuid>","s":{"health":7.0}}
{"o":"player.state","to":"<uuid>","s":{"gamemode":"creative"}}
{"o":"player.state","to":"<uuid>","s":{"heldSlot":3}}
{"o":"player.state","to":"<uuid>","s":{"maxHealth":40}}
{"o":"player.state","to":"<uuid>","s":{"op":true}}
```

`to` 是单个玩家，没有 `"*"`：状态是每个玩家各自的，广播一个位置只会把所有人叠在一起。

字段只用变化的那些，唯一的分组是**位置与朝向**：任何一个变了就五个一起发，因为只改位置不改朝向
在客户端看来像是"闪了一下"，而且 `MovePlayerPacket` 本来就要全套。

为什么用语义 op 而不是直接映射 PM 的包：PM5 的血量**没有任何包**（它随属性包走），游戏模式同步
依赖一个只有登录流程才会填的私有字段，背包同步挂在一个同样私有的 `InventoryManager` 上。
换句话说，只有 `sendDataPacket()` 直接发的包能从 PM 侧流出来，而插件改状态走的是 API。
宿主改为对比公共 API 的返回值，这也顺带覆盖了"不产生包的状态变化"。

**谁说了算**：两边都是权威，按字段做 last-writer-wins。Nukkit 每 `mukkit.stateInterval` 个
tick（默认 2，即 10 Hz）对比一次在线玩家，Nukkit 这边的变化以 `player.state` 上报；宿主在 tick
末尾对比 PM 那边的玩家，PM 这边的变化以同一个 op 下发。**应用之后立刻用结果重设影子副本**，
所以同一个变化不会来回弹。

**代价**：位置是轮询的，最坏晚一个轮询周期（默认 100 ms）。这不是"实时位置"，插件拿它做
区域判定可以，做弹道预测不行。想更快就调 `-Dmukkit.stateInterval=1`。

**经验与饥饿**也用同一个 op，字段独立发送：

| 字段 | 类型 | 含义 |
|---|---|---|
| `xpLevel` | int | 经验等级 |
| `xpProgress` | float 0..1 | 当前等级内的进度。Nukkit 存的是"等级内点数"，适配器用 Nukkit 自己的 `calculateRequireExperience()` 换算，桥不复制这张表 |
| `food` | float 0..20 | 饥饿值。Nukkit 的 `PlayerFood` 是整数，向 PM 应用时四舍五入；PM 侧的 18.5 会在下一秒被 Nukkit 收敛成 19 |
| `saturation` | float | 饱和度，上下游都会把它夹到 `food` 以内 |

**手持槽**是同一个 op 里的一个小整数 `heldSlot`（0–8）：

```json
{"o":"player.state","to":"<uuid>","s":{"heldSlot":3}}
```

PM 侧的 `PlayerInventory::setHeldItemIndex()` 只改内存里的索引，不产生包（热栏同步挂在只有登录
流程才建的 `InventoryManager` 上），所以走和血量同一条路：diff 出来再发。Nukkit 侧应用时调
`setHeldItemIndex()`，它自己会发 `MobEquipmentPacket`，客户端选中的格子跟着动。

**它不进 `player.inventory`**：这不是槽位内容，而是"玩家选的是哪一格"。一次按键只该过桥一个
小整数，不该把 41 个物品堆重新传一遍。反过来，背包内容照旧走 `player.inventory`，两者互不替代。

越界值（负数、9 以上、非整数）只报一次并保持原样，**不夹到最近的槽位**：把客户端的选择挪到插件
没要求的地方，比留在原地更难查。

`player.join` 的 `data.heldSlot` 会在 `PlayerJoinEvent` **之前**应用，理由和 `op` 一样：插件在
事件里读 `getItemInHand()` 时，玩家手里拿的应该是客户端真正选中的那一格，而不是补一 tick 之后的
正确答案。影子副本按 join 帧里的值播种，不按事件处理完的玩家播种——所以插件在 `PlayerJoinEvent`
里改手持槽，会作为本 tick 的一次变化发回 Nukkit，而不是被影子吞掉。

**饥饿消耗值 `exhaustion` 不镜像**：Nukkit 的 `PlayerFood::foodExpLevel` 是私有字段，没有
getter/setter，公开的 `updateFoodExpLevel()` 是"增加"而不是"设为"。桥不反射私有成员，所以
这一项留在 Nukkit 自己那边。

**药水效果**是一个整表，`[id, duration, amplifier, visible, ambient]` 的数组：

```json
{"o":"player.state","to":"<uuid>","s":{"effects":[
  [1, 600, 1, true, false],
  [11, 200, 0, true, true]
]}}
```

- `id` 是 Bedrock 的数字 id：PocketMine 用 `EffectIdMap` 在类型对象和 id 之间转换，Nukkit
  的 `Effect` 本来就用这个 id。名字两边不一样，只有数字能过桥。
- `duration` 是 tick。PM 的"无限"效果在线上发 `2147483647`（Nukkit 的 `int` 上限），回到 PM
  时按无限重建。
- 整表替换而不是增量：只有整表才能同时表达"这个效果过期了"和"同一秒里换成了一个同名效果"。
- 适配器每 `mukkit.stateInterval` 轮询时，持续时间的比较按**整秒**分桶（`duration / 20`）；
  否则效果每 tick 掉一点，玩家身上只要有一个药水就会 10 Hz 发一条状态。代价是插件读到的
  `getDuration()` 最多晚一秒。
- PM 没有的效果 id（自定义效果）会报一次 `player state: effect: unknown effect id N` 并跳过；
  Nukkit 不认识的 id 同样只报不猜。

**还没有的**：饥饿消耗值 `exhaustion`（见上）与 PM 的 lifetime total XP。其余玩家状态字段
（位置、血量、最大血量、游戏模式、手持槽、经验、饥饿、效果、操作员）都已双向。

**操作员**是同一个 op 里的一个布尔，但因为它的语义和"改世界状态"不同，单独在下一节讲。

### 权限与 OP（双向）

```json
{"o":"player.state","to":"<uuid>","s":{"op":true}}
```

只有一个布尔过桥，不是一张权限表。

为什么这一个布尔就够：PM5 没有 `Player::isOp()`，"操作员"是一个**根权限**
`pocketmine.group.operator`。插件加载时，`plugin.yml` 里 `default: op` 的节点被挂到这个根下面
（`PluginManager` 做的），于是"是不是 OP"直接决定了 `/setmoney` 这类命令的
`hasPermission()` 返回值。镜像这一个布尔，等于把整棵默认权限树一起搬了过去。

| 时机 | 谁发 | 说明 |
|---|---|---|
| 加入 | 适配器，`player.join` 的 `data.op` | 必须在**建玩家之前**应用：PM5 的 `Player::__construct()` 只在那一刻读一次 ops 列表，`PlayerJoinEvent` 紧接着就触发 |
| 运行时变化 | 任一侧，`player.state` 的 `op` | Nukkit 的 `/op`、插件调 `Server::addOp()` / `removeOp()` 都算 |
| 重进服务器 | 以 Nukkit 为准 | PM 的 `ops.txt` 会持久化，旧条目在重进时被清掉，不会凭空继承 |

PM 侧的读写都用公共 API：`Server::addOp()` / `Server::removeOp()` 写，
`hasPermission(DefaultPermissions::ROOT_OPERATOR)` 读。写的时候顺带持久化 PM 自己的 `ops.txt`，
所以两边不会越跑越不一致。

**不做的**（不是漏做）：

- **单个权限节点**。Nukkit 权限插件给某个玩家单独加的节点不过桥。PM 侧要授一个节点只有两条
  路：`setBasePermission()` 对未注册的名字直接抛 `LogicException`，而 `addAttachment()` 必须绑在
  一个 `Plugin` 实例上——桥没有插件身份。结果是：PocketMine 插件自己 `plugin.yml` 里声明的节点
  会按镜像来的 op 正确解析，其余节点留在 Nukkit 那边。
- **离线玩家的 op 变化**。插件对一个不在线的名字调 `addOp()` 只写 PM 的 `ops.txt`，没有玩家对象
  可以 diff，Nukkit 不会知道；那个人下次进服时按 Nukkit 的答案覆盖。
- **`Player::setOp()` 不存在**。PM5 删掉了它，插件只能用 `Server::addOp()` / `removeOp()`，
  这也正是这里用的路径。

### 踢人与断开（宿主 → 适配器）

```json
{"o":"packet","to":"<uuid>","cls":"DisconnectPacket","f":{"message":"cheating"}}
```

插件调 `Player::kick()`，PM 立刻走完自己的整条退出路径（`PlayerKickEvent` → `PlayerQuitEvent` →
离开广播 → 卸载区块），然后把 `DisconnectPacket` 塞进会话的 outbox。宿主观测到它就做两件事：
记一条 `packet` op，**并且在同一个 tick 里停止跟踪这个玩家**（`BridgedPlayers::quit()` 此时只
摘掉映射，不重复执行生命周期，因为 PM 已经执行过了）。

不做"等 Nukkit 确认再摘"：适配器不在、客户端已经走了、或者包在半路丢了，都会留下一个再也不会
消失的幽灵玩家；而 PM 那边的玩家早就没了，继续 diff 它只是白做功。

**为什么不是"把包发过去"**：给客户端发一个 `DisconnectPacket` 只是让它断开，服务器侧的
`Player` 要等 socket 关闭被发现才消失——期间 Nukkit 插件还能读到这个玩家，`PlayerQuitEvent`
也还没触发。所以适配器收到这条 op 走的是 `Player.close()`，和 Nukkit 自己处理远端断连用的是
同一条路：同一次调用里发断连屏、移出玩家列表、触发 `PlayerQuitEvent`。

**不是 `kick()`**：Nukkit 的 `kick()` 会被别的 Nukkit 插件取消。等这条 op 到达时，PM 那边
已经触发过退出事件、玩家列表也更新了，再"复活"一个所有人都看着离开的玩家是不可能的，所以
这一侧用不可取消的 `close()`，让已经发生的断开算数。

**没有回声**：适配器知道这次 quit 是宿主要求的（关闭前记下 uuid），`onQuit` 照常做本地清理，
但**不再**发 `player.quit` 回去——宿主已经执行过自己的退出路径了，再来一次就是"同一个玩家走了
两次"。

**两个方向的差别**：Nukkit 侧踢人（`/kick`、Nukkit 插件、反作弊）走的是原有的 `player.quit`
事件，宿主按常规路径 `BridgedPlayers::quit()` 处理，PM 插件收到 `PlayerQuitEvent`——不触发
`PlayerKickEvent`，因为事情已经发生了，没有否决的余地。

### 背包（双向）

```json
{"o":"player.inventory","to":"<uuid>","slots":[
  [0, "minecraft:diamond_sword", 1, 0],
  [36, "minecraft:iron_helmet", 1, 7],
  [40, "minecraft:shield", 1, 0]
]}
```

槽位是一个连续的编号空间，两边各自映射到自己那套背包对象：

| slot | 含义 | Nukkit | PocketMine |
|---|---|---|---|
| `0`–`35` | 背包（含快捷栏 0–8） | `PlayerInventory` | `PlayerInventory` |
| `36`–`39` | 护甲：头、胸、腿、靴 | `PlayerInventory` 的 36–39（`getSize()` 之外的那四个） | `ArmorInventory` 0–3 |
| `40` | 副手 | `PlayerOffhandInventory` | `PlayerOffHandInventory` |

物品是四元组加一个可选字段：

| 下标 | 字段 | 说明 |
|---|---|---|
| `0` | `slot` | 上面的编号 |
| `1` | `id` | **扁平命名空间 id**，如 `minecraft:red_wool`；空槽是 `minecraft:air` |
| `2` | `count` | 0 表示空槽 |
| `3` | `meta` | 现在只剩耐久损伤（`Durable` 的 `Damage`）；变体已经进 `id` |
| `4` | `nbt` | 可选。Bedrock **network little-endian NBT** 的 base64；没有 NBT 就不带这一项 |

**为什么 `id` 能对齐**：两边运行时调色板都是扁平化的（Nukkit 的 `Item.getNamespaceId()`
从 `runtime_item_states` 里取，PM 的 `StringToItemParser::lookupAliases()` 反查同一个
扁平名），所以 `minecraft:red_wool` 在两边指同一件东西。数字 id 不行 —— Nukkit 的是
legacy id，PM 的是自己构建出来的运行时 id，同一个数字在对面是另一件物品。

**为什么 NBT 能直接过桥**：两边都用 Bedrock 的 network little-endian 编码，同一把改名
钻石剑在 Nukkit `NBTIO.write(tag, LITTLE_ENDIAN, true)` 与 PM
`NetworkNbtSerializer->write(new TreeRoot($tag))` 下产出**逐字节相同**的 23 字节
（`Probe` 与 `pm-inventory-test` 各钉一次）。所以桥只搬字节，不解释里面的 `display`/`ench`。

规则与 `player.state` 一致：

- **只发变化的槽位。** join 之后与宿主重启之后的第一次是整份快照（最多 41 项），之后一个 tick
  通常 0–3 项。
- **应用之后用结果重设影子副本。** Nukkit 的 `EntityInventoryChangeEvent` 可能被别的插件取消，
  PM 会把堆叠数夹到上限，只有读回才不会把没发生的变化再报一次。
- **谁改谁负责。** 插件 `setItem()` 是动作，要送到 Nukkit；Nukkit 的拾取/消耗/合成也是动作，
  要送到 PM。两边都对比公共 API，没有一方是"主"。
- **对不上就不写。** PM 侧没有别名的物品（例如某些自定义状态）只报一次
  `inventory: no name for state id N` 并跳过该槽；Nukkit 侧 `Item.fromString()` 解不出的
  名字只报一次 `item.unsupported` 并保留原物品。宁可两边在这个槽上分歧且可见，也不要
  猜一件"差不多"的物品写进去。

**不镜像的**：光标上的物品（`PlayerCursorInventory`）、合成格、末影箱。它们要么是瞬时状态，
要么是另一个容器，混进同一个槽位空间只会让 41 这个数字失去意义。

### 容器窗口（双向）

PocketMine 插件用一个 `BlockInventory`（最常见的是 `ChestInventory`）调
`$player->setCurrentWindow($chest)` 时，PM 自己的 `InventoryManager` 会产出
`ContainerOpen` / `InventoryContent` / `InventorySlot` / `ContainerClose`。适配器不搬这些包，
只搬它们的语义：

```json
{"o":"window.open","to":"<uuid>","window":2,"type":0,"x":0,"y":64,"z":0,"actor":-1}
{"o":"window.content","to":"<uuid>","window":2,"size":27,"slots":[
  [0, "minecraft:diamond", 5, 0],
  [1, "minecraft:apple", 3, 0]
]}
{"o":"window.slot","to":"<uuid>","window":2,"slot":[0, "minecraft:air", 0, 0]}
{"o":"window.close","to":"<uuid>","window":2}
{"o":"window.data","to":"<uuid>","window":2,"property":0,"value":120}
```

- `window` 是 **PocketMine 自己的窗口 id**（1–99，`ContainerIds::FIRST..LAST`），两边用同一个
  编号。`type` 是 Bedrock 的窗口类型；`x/y/z` 是方块窗口的锚点，虚拟窗口是 0；`actor` 对应
  实体容器，普通方块窗口是 -1。
- `window.content` 是**整份快照**，不是 diff：容器没有 `player.inventory` 那样的每 tick 影子，
  而且"插件删掉了一个槽"只有快照能表达。`size` 让适配器在写内容前把 Java 库存扩到 54 槽
  （双箱子），否则第二个箱子的 27 个槽会被静默丢掉。
- `window.slot` 是单个槽的变化，物品方言与 `player.inventory` 完全一致（四元组 + 可选 NBT）。
- `window.data` 把熔炉/酿造台的原始进度值原样带过去；宿主编不出语义名，也不假装有。

**客户端移动物品**走既有的可取消事件通道，wire 名是 `inventory.transaction`：

```json
{"k":"event","name":"inventory.transaction","player":"<uuid>","id":7,"data":{
  "window":2,
  "actions":[
    {"inv":"window","slot":0,"from":["minecraft:diamond",5,0],"to":["minecraft:air",0,0]},
    {"inv":"player","slot":0,"from":["minecraft:air",0,0],"to":["minecraft:diamond",5,0]}
  ]
}}
```

- `inv` 是 `window`（当前窗口的槽位）或 `player`（上面的 0–40 编号空间）；`from`/`to` 是
  **不带槽位的物品三元组**（id/count/meta，可选 NBT）。
- 适配器在 Nukkit 的 `InventoryTransactionEvent` 里**先取消**再问宿主：客户端会收到一次
  Nukkit 自己的回滚，然后宿主的判决结果以 `window.content`/`window.slot` 和
  `player.inventory` 到达。允许时由宿主执行（可能被插件改写过的）动作；拒绝时两边都不动。
- **只有碰到桥接窗口的事务才过桥。** 玩家只在自己的背包里挪东西仍然走 `player.inventory`；
  同一笔事务里如果还有光标/合成格动作，它们不会出现在 `actions` 里（没有可重放的槽位），
  插件看到的动作数因此可能少于包里的原始动作数。
- **没有监听者时不过桥。** `capabilities` 不带 `inventory.transaction` 时，Nukkit 直接执行，
  适配器不发帧；此时窗口是"看得见但改动只活在 Nukkit 侧"的。菜单/商店要接收移动，必须监听
  `InventoryTransactionEvent`。

**不做的**：非 `BlockInventory` 的 `SimpleInventory` 窗口（PM 自己也会在
`createContainerOpen()` 里拒绝）；箱子以外的复杂槽位映射（铁砧/附魔台/织布机的网络槽位与核心
槽位不是一一对应，当前只按 1:1 处理，炉子类只做到内容与进度，没有配方结算）。方块实体本身
见上一节。

### 世界状态（时钟）

```json
{"o":"world.state","world":"arena","s":{"time":6000,"stopped":false}}
```

字段只有两个，都是可选的：

| 字段 | 类型 | 含义 |
|---|---|---|
| `time` | int | 世界自创建以来的 tick 数；`time % 24000` 就是一天中的时刻 |
| `stopped` | bool | 时间是否暂停（Nukkit 的 `Level::stopTime`） |

**为什么时钟要镜像**：宿主不跑 `World::tick()`，PM 的时钟除了被 `setTime()` 改之外永远不动。
不镜像的话，插件 `getTime()` 永远拿到初始值，`setTime(0)` 也只是一个没人看得见的数字。

**方向**：两边都是权威，按字段 last-writer-wins。适配器每 `mukkit.worldInterval` tick（默认 20，
即 1 s）对**有玩家的世界**轮询一次，把变化的字段发下来；宿主在 tick 末尾对每个有玩家的世界
diff 一次，插件改了时间就发上去。时间每秒都在走，所以这条 op 正常运行时每秒每个世界一条，
这是"时钟对得上"的代价，也是间隔按秒而不是按 tick 的原因。

**应用不回声**：PM 的 `setTime()` / `stopTime()` 会给世界里每个玩家发 `SetTimePacket`，宿主在
应用入站时先记下 outbox 长度、应用后截断，包不会以 `packet.unsupported` 的形式再冒出来。

**天气没有对应字段**：PocketMine 5 的 `World` 根本没有 `isRaining()` / `setRaining()`，整块
天气 API 被上游删掉了。所以 `world.state` 不带天气；宿主收到 `rain` 这类字段会报一次
`unknown field rain` 并丢弃，不假装应用过。真要做天气，得先决定它存在哪里。

### 实体

```json
{"k":"entity.sync","world":"arena","e":[
  [700, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0, {"hp": 20.0}],
  [704, "minecraft:item",    3.0, 64.0,  3.0,  0.0, 0.0,
       {"item": ["minecraft:diamond_sword", 1, 0, "<base64 NBT>"]}]
]}
```

第七项是**可选的小表**，因为实体携带什么取决于它是什么：

| 键 | 类型 | 给谁 | 含义 |
|---|---|---|---|
| `hp` | float | `Living` | 血量 |
| `item` | 四/五元组 | `minecraft:item` | 掉落物，复用背包的物品方言 |
| `name` | string | 任意实体 | 自定义名牌。缺省即"没有名字"，不是"不要改"——快照是整份的 |
| `effects` | 数组 | `Living` | 药水效果，形状与 `player.state.effects` 完全相同（`[id, duration, amplifier, visible, ambient]`） |

箭和 TNT 什么都没有。`name` 和 `effects` 都是可选的：没有就不带这一项，带了就代表 Nukkit
那边的完整值。`effects` 为空数组表示"这个实体现在没有任何效果"，宿主会据此清掉插件加上的
那些（这也是药水过期能被看到的方式）。

**掉落物复用背包的物品方言**：`item` 就是 `player.inventory` 里那个四/五元组
（扁平命名空间 id、数量、耐久 meta、可选 network NBT），两侧同一个函数翻译。宿主这边把它
包成 `Item` 的存档 NBT（`Item::nbtSerialize()`）放进 `ItemEntity` 的 `Item` 标签，再交给
`EntityFactory` 建实体 —— 也就是 `minecraft:item` 从存档加载时走的同一条路。所以一把改名的
钻石剑掉在地上，捡起来仍然是同一把。

`entity.sync` 是**整份快照**，不是 add/update/remove 增量：范围只有已经镜像的那些区块，列表
本来就不长；整份列表是自愈的 —— 上一次因类型不认识被拒绝的实体，下一秒会自动重试，而不是
等到它碰巧动一下。快照与上一份完全相同就不发，所以站着不动的怪在一秒之后是零流量。

宿主用 **PocketMine 自己的 `EntityFactory::createFromData()`** 建实体 —— 也就是世界从存档
加载实体时走的那条路。这一步是整个方案的关键：插件拿到的必须是 `Zombie`，`instanceof` 才
成立；数组或通用替身会让所有按类型分支的插件失效。

**类型上限是 PocketMine 5 自己**：核心只有 Zombie / Squid / Villager 和 projectile、掉落物、
TNT 这些，没有 Cow / Pig / Skeleton。工厂返回 null 或抛异常的类型报一次
`entity.unsupported: minecraft:cow` 并跳过，绝不造一个"差不多"的实体。

**谁拥有移动**：Nukkit。同步下来的位置/朝向/血量直接覆盖，且用
`Entity::applyMirroredTransform()` 而不是 `teleport()` —— 后者会发 `EntityTeleportEvent`，
一只走动的怪每秒给每个监听者发一次"传送"是纯粹的谎报。反方向，插件调用 `teleport()` /
`setHealth()` 是动作，宿主 diff 出变化后发：

```json
{"o":"entity.state","world":"arena","e":[[700, 12.0, 64.0, 10.0, 90.0, 0.0]]}
```

适配器收到后走 `Entity::teleport()`（该发事件、该通知客户端）并 `setHealth()`。位置与朝向永远
成组；血量只在变化时出现，所以一只移动中的怪不会每秒重申一次血量。

**实体 id** 是 Nukkit 的运行时 id，只在两侧之间做映射；插件看到的 `Entity::getId()` 是 PM 自己
分配的，跟 Nukkit 无关。

**不镜像的**：实体背包/装备、AI、伤害事件、实体生成。掉落物的物品堆只在生成/重建实体时读取，
插件对 `ItemEntity::setItem()` 的改动不会回到 Nukkit（位置和血量会）。插件 `World::addEntity()`
生成的实体只存在于 PocketMine，Nukkit 不会知道；这条没有做，因为"把任意 PM 实体翻译成 Nukkit
实体"需要另一张类型表和一套生成语义，不是镜像。

**装备为什么是空的**：这不是漏做，是两边没有共同的公开面。PM 的 `Living` 有 `ArmorInventory`，
但 Nukkit 只有玩家（`EntityHuman`）有装备库存；`EntityVillager` 的 `getInventory()` 是交易表，
不是装备，`EntityArmorStand` 的护甲库是私有字段，而 PM 5 根本没有 `ArmorStand` 类。要在 mob
上镜像装备，得先改 Nukkit 的实体 API 或反射私有成员 —— 前者违反"桥适配 Nukkit，不改 Nukkit"，
后者是明令禁止的。所以这一项停在"PM 能看见自己的装备，Nukkit 看不见"，并在这里说清楚。

### 世界镜像

方块在两个方向上都用 **legacy id + meta**（Nukkit 的原生方言），不用 Bedrock runtime id：
runtime id 是版本相关的，PM 固定在一个 Bedrock 版本、客户端可能在另一个，传它等于把镜像
钉死在构建它的那个版本上。id + meta 是两边十年来唯一都认的编号；各side 用自己的表转换，
PM 侧走 `BlockIdMetaUpgrader`，这是它升级 PM3 存档的官方路径，因此**天然有损**
（多个状态塌到同一个 meta），转换失败按空气处理并计数。

`chunk` 是唯一带"二进制"的 op，因为 4096 个数字的 JSON 数组比方块本身大一个数量级：

```
{"o":"chunk","world":"world","x":0,"z":0,
 "sections":[{"y":4,
              "ids":"<base64(zlib(4096 * uint16 LE))>",   // 方块 id
              "metas":"<base64(zlib(4096 * uint8))>"}]}   // 4-bit damage
```

- 索引序是 **XZY**（`x | z<<4 | y<<8`），与 PM 的 `PalettedBlockArray` 一致；写反了世界会静默转置。
- 空段不发；缺席的段在接收侧一律是空气，因为快照是整块替换，不是增量。
- `block` 是批量：一次填充可能几千个方块，一个方块一个 op 就是一 tick 几千行 JSON。

**订阅，不是一次性推送。** 适配器每 `mukkit.mirrorInterval` tick（默认 20，即 1 s）把在线玩家
所在区块的方阵（`mukkit.mirrorRadius`，默认 2，即 5×5）求并集，比对上次的并集：新增的区块发
`chunk`，没人再需要的发 `chunk.unload`。join、quit、传送会立刻重算，不等下一个周期。

```
{"k":"chunk.unload","world":"world","x":12,"z":-3}
```

- 并集是**按世界的**，也是**跨玩家的**：一个区块只要还有任何一个玩家在半径内就不卸载，
  所以两个人在同一片区域走动不会互相把对方脚下的镜像撤掉。
- 卸载之后读空气，不是读到最后一次快照的旧值。旧值会让插件在一个玩家已经走开的地方
  读到早已过时的方块，而且永远不会自愈。
- 宿主拒绝卸载（`ChunkUnloadEvent` 被取消、有插件 pin 住这个区块）时，镜像保留这个区块并
  报一次 —— 保留旧值与"区块还在那里"一致，比删掉影子副本更诚实。

**多世界就是多个 `world`。** `world` 是 Nukkit 的 level 名，宿主按这个名字一个建一个真
`World`；玩家 join 时 `Player::getWorld()` 就是他实际所在的那个，`block` / `block.update` /
`chunk` 都带世界名，不会串到默认世界。名字缺失的帧一律拒绝（报一次），不猜默认值。

### 方块实体

方块实体（箱子、告示牌、熔炉）不跟着方块数据走：同样一块空气，上次是箱子、这次是空，
`chunk` 的 id/meta 分不出来。它们单独一组帧，跟着区块一起到：

```json
{"k":"chunk","world":"world","x":0,"z":0,
 "sections":[...],
 "tiles":[
   {"x":10,"y":64,"z":10,"type":"Chest","items":[[0,"minecraft:diamond",5,0],[1,"minecraft:apple",3,0]]},
   {"x":12,"y":64,"z":10,"type":"Sign",
    "front":["hello","world","",""],"frontColor":-16777216,"frontGlow":false,
    "back":["","","",""],"backColor":-16777216,"backGlow":false},
   {"x":14,"y":64,"z":10,"type":"Furnace","items":[[0,"minecraft:iron_ore",2,0]],
    "burn":120,"cook":40,"max":200}
 ]}
```

变化单独一帧，`removed` 是被拆掉的坐标：

```json
{"k":"tile","world":"world",
 "tiles":[{"x":10,"y":64,"z":10,"type":"Chest","items":[[0,"minecraft:diamond",7,0]]}],
 "removed":[[12,64,10]]}
```

- `type` 是两边都认的 save id（`Chest` / `Barrel` / `ShulkerBox` / `Hopper` / `Furnace` /
  `BlastFurnace` / `Smoker` / `BrewingStand` / `Sign`），不是任何一侧的类名。
- `items` 与 `player.inventory` 同一套物品方言，前面多一个槽位：`[slot, id, count, meta, nbt?]`。
  这是刻意的：方块实体里也有物品，如果为它另造一套格式，就等于让桥再养一个物品解析器。
- **快照，不是 diff。** 一个箱子只有 27 格，整份快照比"第 4 格空了"这种第二套词汇便宜，
  而且它是唯一能同时表达"少了一个物品"和"改名了"的形状。
- **Nukkit 是权威。** 玩家打开真实箱子挪东西走的是 Nukkit 自己的 `InventoryTransactionEvent`，
  PM 侧不需要参与；适配器每 `mukkit.tileInterval` tick（默认 4）对已镜像区块里的方块实体求
  一次指纹，变了就发 `tile`。PM 插件改箱子/告示牌之后，宿主在 tick 末尾 diff 出同样的形状
  发回来，适配器写到真实方块实体上。
- **PM 侧不 tick 方块实体。** 它们用 `Chunk::addTile()` 挂上去，不调 `World::addTile()`：后者会
  排一个 block update，熔炉就会在 PM 里自己烧起来。模拟只有一份，在 Nukkit。
- **方块实体没有脏标记。** 插件可以通过背包、`setText()`、甚至 `getInventory()->setItem()` 改，
  没有一条统一的通知路径；和方块一样，用"跟影子副本比"来发现，而不是拦截每个入口。
- **燃烧中的熔炉每次轮询都变。** `burn`/`cook` 每 tick 都在动，所以它有火焰时每
  `mukkit.tileInterval`（默认 4 tick）发一条小帧，一个世界里的熔炉数量就是这条流量的上限；
  这是"插件读到的是真实进度"的代价。不烧的时候它不动，也就不发。

**容器窗口与方块实体**：PM 插件在真实箱子的位置开窗口时（`$tile->getInventory()`），适配器把
这个窗口**绑到真实方块实体**的库存上，而不是再建一个替身。客户端看到的是那只箱子（开箱动画、
音效、双箱子 54 格都是 Nukkit 自己的），玩家挪动物品直接落在真实库存里；即使没有任何插件监听
`inventory.transaction`，tile 帧也会把结果带给 PM。此时宿主的 `window.content` / `window.slot`
**不再应用**：方块实体才是权威，PM 那份只是晚一 tick 的视图，把它盖回去会把玩家刚拿走的东西
变回来。虚拟窗口（菜单、商店，锚点不在真实方块实体上）仍然是替身窗口，内容由宿主决定。

**不做的**：PM 没有对应类的方块实体（发射器/投掷器/活塞臂等）不发，`getTile()` 保持 null；
刷怪笼的实体类型是网络 id 与标识符两套编号，没有可靠映射前不桥。PM 插件 `setBlock()` 放下的
箱子/熔炉会在 Nukkit 侧建出对应方块实体（`BlockEntityHolder` 路径），但绕过方块直接 `addTile()`
不会 —— 方块实体的存在由方块决定。箱子的**配对**由 Nukkit 决定：PM 侧 `pairWith()` 不会回到
Nukkit，但 Nukkit 的配对会随快照到达，`getInventory()` 因此能给出双箱子。

一次 tick 内的顺序是固定的：**先应用入站（Nukkit 的），再跑 PM 的 tick，最后收集本地写入。**
镜像因此最多落后一 tick，但永远不会读到服务端还没产生的值。

## 超时语义

| 情况 | 桥的动作 |
|---|---|
| 软超时（15ms） | 计数，继续等；该 tick 仍会被应用 |
| 硬超时（500ms） | 放弃该 tick，抛 `HostHungException`，由监督者杀进程重启 |
| 连接 EOF | 判定宿主崩溃；重启后重放 `plugins` 清单 |
| 插件异常 | 不杀进程，写 `error` 字段，tick 继续 |

重启后不重放历史 op：插件状态由 `plugin.yml` + data folder 重建，世界状态从 Nukkit 重新推。

## 演进

- `proto` 变更必须同时改 `cn.mukkit.bridge.Protocol` 与 `Mukkit\Host\Protocol`
- 新增 op 类型是兼容变更：JVM 侧遇到不认识的 `o` 记 warn 并跳过，不得中断 tick
- 新增帧类型同理，未知帧记 warn 丢弃
