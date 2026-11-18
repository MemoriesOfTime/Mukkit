# 事件面

一句话：桥能送到插件的事件分两层 —— **能忠实重放的动作**（决定型：扣住 → 等判决 → 补执行），
和**已经发生的事实**（通知型：PocketMine 的事件本来就不能取消）。判据不是"PM 有没有这个事件"，
而是"Nukkit 侧的动作在判决之后能不能被原样做出来"。

这张表是"哪些事件通了、哪些没通、为什么"的唯一出处。加事件之前先在这里加一行。

## 已经通的：通知型（5 条）

| `event.name` | PM 事件 | Nukkit 来源（适配器监听） | 说明 |
|---|---|---|---|
| `player.join` | `PlayerJoinEvent` | `PlayerJoinEvent`，MONITOR 未取消 | 玩家由 `BridgedPlayers::join()` 构造 |
| `player.quit` | `PlayerQuitEvent` | `PlayerQuitEvent`，MONITOR | 宿主主动踢人时不回声 |
| `player.chat` | `PlayerChatEvent` | `PlayerChatEvent`，MONITOR | 宿主投递时本就接管广播，取消 = 不广播 |
| `player.respawn` | `PlayerRespawnEvent` | `PlayerRespawnEvent`，MONITOR | PM 侧事件不可取消；改重生点无效（见"代价"） |
| `player.death` | `PlayerDeathEvent` | `PlayerDeathEvent`，MONITOR | 死亡已经发生；`drops`/`xp`/`message` 是新闻，`keepInventory` 不会回到 Nukkit |

## 已经通的：决定型（14 条）

| `event.name` | PM 事件 | Nukkit 来源 | 补执行（允许时） | 拒绝时 |
|---|---|---|---|---|
| `block.break` | `BlockBreakEvent` | `BlockBreakEvent` | `Level::useBreakOn(x,y,z,item,player)` | 方块留着 |
| `player.drop` | `PlayerDropItemEvent` | `PlayerDropItemEvent` | `inventory.removeItem()` + `Player::dropItem()` | 物品留在背包 |
| `inventory.transaction` | `InventoryTransactionEvent` | `InventoryTransactionEvent` | 宿主逐条 `SlotChangeAction::execute()`；适配器只回滚、不重放 | 窗口与背包都不动 |
| `player.item.held` | `PlayerItemHeldEvent` | `PlayerItemHeldEvent` | `inventory.setHeldItemIndex(slot)` | 重发旧槽位（客户端也跟着回退） |
| `player.toggle.sneak` | `PlayerToggleSneakEvent` | `PlayerToggleSneakEvent` | `Player::setSneaking(state)` | Nukkit 自己 `needSendData` 纠正 |
| `player.toggle.sprint` | `PlayerToggleSprintEvent` | `PlayerToggleSprintEvent` | `Player::setSprinting(state)` | 同上 |
| `player.toggle.fly` | `PlayerToggleFlightEvent` | `PlayerToggleFlightEvent` | `AdventureSettings::set(FLYING, state)->update()` | 同上 |
| `player.toggle.glide` | `PlayerToggleGlideEvent` | `PlayerToggleGlideEvent` | `Entity::setGliding(state)` | 同上 |
| `player.toggle.swim` | `PlayerToggleSwimEvent` | `PlayerToggleSwimEvent` | `Entity::setSwimming(state)` | 同上 |
| `block.grow` | `BlockGrowEvent` | `BlockGrowEvent` | `Level::setBlock(pos, newState, true, true)` | 方块停在旧状态 |
| `block.spread` | `BlockSpreadEvent` | `BlockSpreadEvent` | 同上 | 同上 |
| `block.form` | `BlockFormEvent` | `BlockFormEvent` | 同上 | 同上 |
| `block.burn` | `BlockBurnEvent` | `BlockBurnEvent` | `Level::setBlock(pos, air, true)` | 方块不烧掉 |
| `leaves.decay` | `LeavesDecayEvent` | `LeavesDecayEvent` | `Level::useBreakOn(pos)` | 树叶挂着 |

事件面共 **19 个 wire 名**（5 通知 + 14 决定）。

为什么这 14 条能补执行、而别的不能：它们的"结果"都是 Nukkit / PM 在事件里已经算好的**状态**
（新方块、目标槽位、布尔开关），允许之后照着写一遍就完事。反过来，`BlockPlaceEvent` 的事件里
没有点击向量，`Block::place()` 的朝向（楼梯倒挂、半砖上下）要靠它算 —— 补执行只能猜，所以不做。

**不是桥事件、但插件也会收到的**：`PlayerKickEvent`（只限 PM 主动踢）、`EntityDespawnEvent`
（镜像实体从快照消失）、`PlayerGameModeChangeEvent`（Nukkit 改模式时由 `setGamemode()` 触发，
可被插件否决）。

## 三条约定，19 条事件都适用

- **决定型只在有监听时拦截。** `BridgeEvents::demanded()` 用 `HandlerListManager` 数监听器，
  `ready` 帧的 `events` 与 `capabilities` op 跟着变。没人听就不扣，零延迟、零闪烁。
  通知型不受此限：它没有判决，也就没有"扣住"的成本。
- **构建不出事件按拒绝回答（fail closed）。** 缺字段、未知方块 id、未知世界、未知玩家 →
  `event.result{allowed:false}`。留着悬挂等 40 tick 超时，玩家面前就是一个敲不掉的方块 /
  站不稳的状态。
- **判决必达。** 宿主在 `finally` 里回 `event.result`，插件抛异常也要给拒绝。

## 判定一个事件能不能加：三个问题

1. **能重放吗？** 插件说"允许"之后，适配器必须能用**公共 API** 做出同一件事。做不到就不是漏做，
   是不该做（见下面"不做"）。
2. **拒绝的代价可见吗？** 方块留着、物品留在背包、槽位不动 —— 可见、可报告。物品消失、方块被
   复制，都是静默错。
3. **有对应的事件源吗？** Nukkit 侧得先有那个事件，且它得在动作**之前**触发（在之后触发的只能
   当通知）。方块事件还要落在**已镜像的区块**里，否则宿主会把事件建在一片空气上。

## 还缺的，按"需要补什么"分两档

### A. 缺"判决 + 补执行"

| 事件 | 补执行是什么 | 卡点 |
|---|---|---|
| `player.death`（`PlayerDeathEvent`） | 通知层已做；取消 Nukkit 的死亡事件后自己走完死亡尾部 | 通知层只送 `drops`/`xp`/`message`。要否决死亡就得接管掉落、清背包与经验（`Player.java:8009` 起），晚一 tick 的判决没有落点，等于重写死亡尾部 |
| `player.item.consume`（`PlayerItemConsumeEvent`） | 吃掉/喝掉的动作 | 饥饿走 `Food::eatenBy()`、药水走 `Potion::applyPotion()`，散在两个类里且没有公共入口；重放等于另写一套结算 |
| `sign.change`（`SignChangeEvent`） | 把文本写进方块实体 | 文本镜像已经通了（牌子的正反面、颜色、发光都能读，写入也会回到 Nukkit），但 Nukkit 的编辑流程是"客户端发 `updateCompoundTag` → 事件 → 不取消才 `setText()`"，要否决就得把这次编辑扣住再回滚客户端已经显示的字，和方块事件不是同一类；下一轮单独做 |
| `entity.damage`（`EntityDamageEvent` 家族） | 重算一套伤害数值 | PM 与 Nukkit 的 `DamageCause` 不是同一套枚举，最终伤害算法也不同 |
| `player.bed.enter`（`PlayerBedEnterEvent`） | 传送到床的重生点 | 床的占用状态、PM 侧的世界不同；收益小于成本 |

### B. 不做，以及为什么

| 事件 | 理由 |
|---|---|
| `block.place`（`BlockPlaceEvent`） | 事件里没有点击向量（`fx/fy/fz`）与朝向，楼梯/半砖的最终状态是 `place()` 里用玩家朝向算的。补执行只能猜 —— "看起来成功但朝向错了"比"插件没看到"更难查 |
| `player.interact`（`PlayerInteractEvent`） | 它和放置走同一个 `useItemOn()`；补执行会把整段放置逻辑再跑一遍。没有干净的"只重放开箱子"入口 |
| `player.move`（`PlayerMoveEvent`） | Nukkit 每个移动包触发一次，桥是 10 Hz 轮询；它还是可取消的（区域限制/反作弊全靠它），等价物要拦每个移动包 |
| `block.fade`（`BlockFadeEvent`） | PM 5 没有 `BlockFadeEvent`，只有 `BlockMeltEvent`（冰雪融化），语义只覆盖一半；珊瑚死亡/火焰熄灭会被误报成"融化" |
| 容器/合成（`InventoryTransactionEvent` 等） | 容器 GUI 没接，事件无处落地 |
| 登录（`PlayerLoginEvent`/`PlayerPreLoginEvent`） | 玩家由 `BridgedPlayers::join()` 直接构造，不走登录流程 |
| 数据包事件（`DataPacketReceive/SendEvent`） | 桥不搬原始包，只搬语义 op |
| 世界加载/卸载（`WorldLoadEvent`/`WorldUnloadEvent`） | 世界先于插件存在，注册不触发，也不该触发 |
| 实体生成（`EntitySpawnEvent`） | 世界不 tick，镜像实体由快照建立 → 不伪造 spawn（`EntityDespawnEvent` 会正常触发） |
| 效果增删（`EntityEffectAddEvent`/`RemoveEvent`） | 镜像就地改持续时间/等级，避免每 20 tick 一次 add/remove |
| 跳（`PlayerJumpEvent`） | 只可能是通知型，但每次起跳一条帧，价值与流量不成比例 |
| Nukkit 发起的踢人 | 只发 `player.quit`，不触发 PM 的 `PlayerKickEvent` —— 事情已经发生，没有否决的余地 |

## 加一个新事件要动的五处

1. fork：决定型加进 `BridgeEvents::CANCELLABLE`，通知型加进 `NOTIFY`；写一个 builder，
   构建不出来返回 `null`（= 拒绝）。方块事件再加进 `WORLD_EVENTS`（它没有 player）。
2. JVM：一个 `@EventHandler`，调 `hold(event, player, data, replay)`（决定型）或 `queueEvent()`
   （通知型）。`replay` 是一个返回 boolean 的 lambda —— 真的做成了才算 replayed。
3. 协议：`docs/design/protocol.md` 的 `event` 行与 `capabilities.events` 的取值。
4. 文档：本表加一行，并在 [`docs/project/roadmap.md`](../project/roadmap.md) 或对应设计文档里记一条。
5. 判据：`scripts/run-events.sh` + 一个会取消这一件事的探针插件（`examples/pm-*-probe`）。

`demanded()`、`capabilities` 撤回、40 tick 超时、按名字计数都是按事件名自动走的，不需要为新
事件再写一遍。

## 镜像应用时会触发哪些 PM 事件

镜像不是插件动作，但应用镜像时有的 PM 原语会自己发事件。这份清单要背下来，否则"为什么这个事件
来了、那个没来"每次都要读一遍 fork：

| 应用的动作 | PM 侧事件 | 理由 |
|---|---|---|
| `setGamemode()` | `PlayerGameModeChangeEvent`（可取消） | 模式真的变了，插件有权否决；被否决时桥报 gap，不回推 |
| `getInventory()->setHeldItemIndex()` | 不发 | PM 的 `PlayerItemHeldEvent` 只在 `Player::setHeldItemIndex()` 里发，桥走的是背包原语 |
| `setHealth(0)` | 不发 | 血量归零在 PM 侧只是一具 0 血尸体，死亡路径没走（见 A 档） |
| `setXpAndProgressNoEvent()` | 不发 | 刻意：镜像不是"插件发经验"，否则别的插件能取消同步 |
| 效果就地 `setDuration()` | 不发 add/remove | 整表替换会每 20 tick 触发一次 add/remove |
| 实体从快照消失 → `close()` | `EntityDespawnEvent` | 它确实不在了 |
| 世界注册进 `WorldManager` | 不发 `WorldLoadEvent` | 世界在宿主之前就有，插件没有否决权 |

## 代价（所有决定型事件共同）

- 动作**晚一个 tick** 生效（50 ms）。开关类动作尤其明显：潜行/疾跑的第一帧会被扣住，
  判决回来才补上。
- `block.break` 的补执行会**再触发一次**同一个事件，其他 Nukkit 插件每敲一个方块收到两次
  （一次被取消、一次通过）。`player.drop` 不会：`Player::dropItem()` 本身不发事件。
- 判决超时（40 tick ≈ 2 s）就丢弃动作：方块留着、物品留在背包里、状态不切换。留给玩家一个可见的
  失败，好过放过去一个没人批准的动作。
- `player.drop` 补执行前会检查背包里还有没有那一堆完整的物品；不在就不丢 —— 宁可少一次掉落，
  也不凭空造出一件物品。
- `player.item.held` 的**两个方向都要纠正客户端**：Nukkit 取消时只 `sendContents()`，不改选中格。
  允许时 `setHeldItemIndex(slot)`，拒绝时 `setHeldItemIndex(from)`，都会发 `MobEquipmentPacket`。
- 方块事件只送**已镜像区块**里的：宿主没收到过的区块，`getBlockAt()` 读到的是空气。
  未镜像世界/区块按拒绝回答，不猜。
- `block.grow/spread/form` 的 `newState` 是**只读**的：PM 的 `BaseBlockChangeEvent` 没有
  setter，插件只能取消，不能改写成别的方块。桥据实按 Nukkit 算出的状态补执行。
- `player.respawn` 是纯通知：PM 的 `setRespawnPosition()` 不会传回 Nukkit，重生点仍由 Nukkit 决定。
- `inventory.transaction` 的允许结果由**宿主**执行，适配器只取消 Nukkit 的事务并等判决。整笔事务
  因此会先回滚再落定，玩家可能看到一次闪烁；拒绝时两边都不动。
- `player.death` 是纯通知：死亡尾部（掉落、清背包、经验）已经由 Nukkit 跑完，插件改
  `keepInventory` / `setDrops()` 只影响它自己看到的值。
- **只桥了"玩家丢"这个方向。** 插件调 `$player->dropItem()` 仍然只在 PM 的镜像世界里生成一个
  实体，Nukkit 看不到它 —— 那需要"插件生成实体"的通道，不归事件面管。
