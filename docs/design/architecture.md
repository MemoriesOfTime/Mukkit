# 架构

## 一条规矩

世界上只有一个权威。Nukkit 拥有方块、实体、玩家和 tick。PocketMine 是插件宿主，
它的世界是只读镜像，写入必须提交回 Nukkit。

一切设计取舍都从这条规矩推出来：

- PM 侧的 `World::tick()` 不跑，所以 PM 不会和 Nukkit 抢物理、AI、红石
- PM 侧玩家对象只承载"插件视角的状态"，位置和背包由 Nukkit 推送
- 插件读到的是快照，可能落后一个 tick；这是可接受且必须写进文档的语义
- 只要插件依赖"PM 自己推进世界"，它就不可能正确 —— 加载时就该拒绝

## 进程边界

```
┌──────────────────────────────┐         ┌──────────────────────────────┐
│ JVM: Nukkit-MOT              │         │ PHP: PM fork                 │
│                              │         │                              │
│  Level / Player / Entity     │  tick   │  PluginManager  (原生)        │
│         │                    │ ──────► │  Scheduler      (原生)        │
│         ▼                    │         │  CommandMap     (原生)        │
│  BridgeHost                  │         │  Config / NBT   (原生)        │
│   ├ 事件构造                  │ ◄────── │         │                    │
│   └ op 应用                  │   ops   │  NetworkSession 子类          │
│                              │         │   └ sendDataPacket 拦截       │
│  PhpPluginLoader             │         │  World / Player 镜像代理      │
└──────────────────────────────┘         └──────────────────────────────┘
```

两条边方向相反、粒度不同，这是刻意的不对称：

- **入站（JVM → PM）**：事件级。数量少、结构稳定，翻译成 PM 事件对象即可。
- **出站（PM → JVM）**：包级。PM 自己把 `Player::sendMessage()` 变成 `TextPacket`，
  我们在对象层截下来，映射成 Nukkit 的包对象，交给 Nukkit 按客户端协议版本编码。

出站走包级而不是 API 级，是因为 API 面太大而包面很小：十几个包就覆盖了消息、标题、
表单、计分板、音效、粒子。而且包对象级映射天然免疫版本漂移 —— PM 冻结在某个
Bedrock 版本，编码交给一直更新的 Nukkit。当前白名单 9 种，字段表在 `docs/design/protocol.md`。

投递方只有一个：宿主连上后，Nukkit 侧不再自己广播聊天/加入/退出消息（MONITOR 取消
`PlayerChatEvent`、join/quit 消息置空），改成由 PM 的格式化结果唯一投递；宿主失联时
Nukkit 的广播恢复。没有这条，同一句话会在真机上出现两遍。

## 一轮 tick 的生命周期

```
JVM                                    PHP
 │  t=0  ── tick{seq, in:[event...]} ──►  │
 │                                        │  dispatch 事件 → 插件回调
 │                                        │  回调里产生的出站动作全部入队
 │  t=Δ  ◄────── tickdone{seq, ops} ───── │
 │  应用 ops（发包/改方块/写日志）           │
 │  t=50ms ── 下一帧 ──────────────────►  │
```

三条硬规则：

1. **同一时刻只有一个 tick 在飞。** 不允许流水线，否则插件看到的顺序会乱。
2. **PM 在 tick 内不许回调 JVM。** 所有出站动作入队，tick 结束一次性带回。
   这一条消灭了重入，也就消灭了"主线程等子进程、子进程等主线程"的死锁。
3. **读走镜像，写走批量。** 需要返回值的东西（`getBlock`）必须命中本地镜像，
   不许在 tick 内做同步 RPC。这条是性能红线。

## 启动顺序

产品是一个 jar 插件，所以顺序不由我们决定，而是由 Nukkit 的加载流程决定：

```
1  Server 构造器             扫 plugins/*.jar → 只有 JavaPluginLoader 认识 Mukkit.jar
2  enablePlugins(POSTWORLD)  MukkitPlugin.onEnable
   ├ 解包 pm-host/           资源从 jar 出来，落到 plugins/Mukkit/pm-host/
   ├ 扫 plugins/*.phar       用 pm-inspect.php 读 plugin.yml
   ├ 起 PHP 宿主             握手 hello/welcome/plugins → ready（宿主只 load，不 enable）
   ├ registerInterface       PhpPluginLoader 就位
   ├ loadPlugins(plugins/)   显式指定加载器，只这一次扫描 .phar
   └ enablePlugin(each)      PhpPlugin.onEnable → 入站项 plugin.enable
3  scheduleRepeatingTask     每 tick 一次 driver.step()（在主线程）
4  server 关停               MukkitPlugin.onDisable → 排队 disable → 最后一帧 → 杀宿主
```

两条必须守住的顺序约束：

- **`registerInterface` 必须在 `loadPlugins` 之前。** 反过来 `PluginManager` 的
  `fileAssociations` 里没有我们的加载器，`.phar` 会被静默跳过。
- **宿主 `load` 与 `enable` 必须分开。** 宿主若在握手时就 enable，插件会以为自己活了，
  而 Nukkit 那边还没承认它；`plugin.enable` 入站项就是为了让 JVM 保持"谁什么时候活"的权威。

phar 插件因此总是晚于 jar 插件启用，`depend/softdepend` 不跨格式生效——这是形态带来的，
不是实现缺陷；能力探测尚未实现。

## 进程边界与安装形态

```
Nukkit-MOT 进程                                        PHP 进程
├─ plugins/Mukkit.jar          ← 唯一安装物            ├─ mukkit-host.php --profile=pm
├─ plugins/Mukkit/pm-host/**    ← 从 jar 解包（桥）     ├─ 插件由 PM 自己的 PluginManager 加载
├─ plugins/Mukkit/pocketmine/** ← 从 jar 解包（fork）   ├─ PM 自身数据在 plugins/Mukkit/pm-data/
├─ plugins/Mukkit/pm-data/**    ← worlds / config       └─ 与 JVM 只通过 unix socket 说话
├─ plugins/EconomyAPI.phar      ← 用户放的 PM 插件
├─ plugins/EconomyAPI/**        ← 该插件的数据（Nukkit 习惯）
└─ plugins/其他 jar 插件         ← 完全不受影响
```

**jar 里装的是 fork 的源码**（`pocketmine/src` + `vendor` + `generated` + `resources`，约 3.7 MB
压缩后）。第一个理由是可替换性：PM 是 LGPL-3.0，随 jar 发源码，用户随时能查看、修改、重建
这部分，不需要我们额外交付什么。第二个理由是安装故事：用户只需要丢一个 jar。

解包按 jar 的 **内容哈希**打戳（`.unpacked-<sha256>`）：同一份 jar 只解一次，改了 jar 就重解，
所以开发时不会跑到上一次构建的 PHP。解包 + PM 开机实测约 1 秒，`Done (2.87s)` 里含这一段。

`profile` 在 `config.yml` 里，`auto`（默认）表示"jar 里有 fork 就用 pm"。选 `plugin` 只应出现在
测试或排障时——那是有意为之的降级，真正的 PM 插件会被拒绝。

两个 profile 的分工：`plugin` 是不 boot PocketMine 的测试契约（只认 `HostedPlugin`，没有
`pocketmine\*` API）；`pm` 才 boot 真 PocketMine，插件由 PM 自己的 `PluginManager` 加载。
`pm` 的入站 `command` 走 PM 的 `CommandMap`，出站把控制台输出翻成 `log` op；`player.join` /
`player.chat` / `player.quit` 走 PM 自己的玩家生命周期，插件拿到的是真 `Player`。没有 PM 等价物的
事件名仍然被显式拒绝（`event.unsupported`），不会静默吞掉。

世界是镜像，不是模拟：Nukkit 把玩家周围的区块推给宿主，宿主把它们建成真 `Chunk` 放进
真 `World`，所以 `$world->getBlock()` 走的是 PM 自己的方块工厂与状态注册表。推送不是一次性的：
适配器每 `mukkit.mirrorInterval` tick 按在线玩家所在区块重算 5×5 并集，补发新区域，走远的区块
用 `chunk.unload` 撤销（读回空气，而不是读到旧快照）。`world` 是 Nukkit 的 level 名，宿主按名字
一个建一个 `World`，玩家 join 时拿到的是他实际所在的那个。插件写方块时宿主 diff 出差异，以
legacy id + meta 发回 Nukkit（按世界分组）；Nukkit 自己的改动以 `block.update` 推来。一个 tick
内的顺序固定为"先入站、再 PM tick、最后收集本地写"，镜像因此最多落后一 tick，但永远不会领先
于服务端。

这些世界注册进 `WorldManager`，否则插件只能通过 `$player->getWorld()` 拿到它们，
`getWorldByName()` / `getWorlds()` / `getDefaultWorld()` 全是空的。注册的世界**不参与
`WorldManager::tick()`**：一旦参与，PocketMine 会开始跑第二份模拟（方块 tick、实体物理、
自动前进的时钟），镜像立刻和 Nukkit 打架。默认世界从 `welcome` 里拿到名字，在插件启用前建好。

世界时钟走一条独立的短回路（`world.state`）：宿主不跑 `World::tick()`，PM 的时钟本来是冻结的，
所以适配器每秒把有玩家的世界的 `time` / `stopped` 推下来，插件改了时间再推回去。天气不在其中 ——
PM5 的 `World` 没有天气 API，没有地方存放那个值。

实体没有"描述"这一层，插件拿到的是对象、要按类分支。所以宿主不复刻实体模型，而是用 PM 自己的
`EntityFactory::createFromData()` 把 Nukkit 的快照建成真实体（`instanceof Zombie` 必须成立），
再按同一套影子 diff 收集插件对位置/血量的改动。方向仍然是不对称的：Nukkit 拥有移动，同步用
`applyMirroredTransform()`（不发 `EntityTeleportEvent`）；插件 `teleport()` 才是动作，才发事件。

可取消事件多一层：Nukkit 同步决定方块与丢弃动作，桥一 tick 才往返，所以适配器先
`setCancelled(true)` 把动作扣住，把判决权交给 PocketMine，拿到"允许"再按事件补执行
（`Level::useBreakOn()` / `removeItem()` + `dropItem()`）。宿主只在**有插件监听**时才要求拦截
（`ready.events`），否则拦截就只是延迟。挂起表按事件名分派：14 条决定型共用一条通道
（`HeldAction(event, replay, deny)`），清单与判定标准见 `docs/design/events.md`。

玩家状态用同一套办法（`player.state`）。这里没有用"映射 PM 的包"，因为 PM5 的血量根本不发
包、游戏模式与背包同步又依赖只有登录流程才填的私有字段：插件改状态走的是 API，不是
`sendDataPacket()`。所以两端各自对比自己那份公共 API 的影子副本，把变化的字段发给对面，
应用后再用结果重设影子，同一个变化就只走一趟。位置按 `mukkit.stateInterval` 轮询（默认 10 Hz），
这是"够插件做判定"的频率，不是实时同步。

背包（`player.inventory`）复用同一套机制，只换了方言：槽位 0–40（背包 / 护甲 / 副手），物品用
扁平命名空间 id + 数量 + 耐久 + NBT 字节。首次 diff 是整份快照 —— join 帧没有地方携带背包，
这一点与状态相反；宿主重启后 `onReady` 清空影子，让新宿主也能拿到全量。

PM 的插件数据默认按 Nukkit 的习惯放：`plugins/<插件名>/`，和 jar 插件的数据目录是同一种排法，
一个插件的东西（phar 与它的 config/data）在同一个地方，备份或删插件都不用猜。

`config.yml` 里的 `pm-data-layout: isolated` 会改成 `plugins/Mukkit/pm-data/plugin_data/<插件名>/`：
适合"整个 PM 侧只占 `plugins/Mukkit/` 一个目录，删除不牵连 `plugins/`"的服务器。两种布局都通过
`plugins.legacy-data-dir` 交给 PM 自己解析，桥不拼路径。

布局只决定新数据写在哪，不做搬迁：已经是 `plugins/<插件名>/` 的数据不会因为改成 `isolated` 而
自己移动，反过来也一样。换布局等于换一个空的数据目录，旧的那个留着，删不删由管理员决定。

宿主挂了只影响 phar 插件；服务器继续 tick，jar 插件照常工作。

## 超时与监督

| 阈值 | 默认 | 含义 | 动作 |
|---|---|---|---|
| 软超时 | 15ms | 本 tick 超预算 | 计数告警，继续等 |
| 硬超时 | 500ms | 宿主失联 | 中止驱动，抛 `HostHungException`，交给监督者重启子进程 |
| 插件抛异常 | — | 插件代码崩了 | 捕获，写进 tick 帧的 `error`，tick 照常落地 |

插件异常和宿主失联必须分开处理：前者是插件的问题，不该让整个 PHP 进程陪葬；
后者是进程的问题，只能重启。`--fault=error|hang|crash` 三个开关分别验证这三条路径。

## 内存与状态

- PM 侧只缓存被访问过的 chunk（LRU），不整体加载世界
- PHP 数组开销大，给宿主单独设 `memory_limit`，超限视为崩溃而非降级
- 每个 op 都带 tick 号，两侧日志按 tick 对齐，否则跨进程排查会失控
