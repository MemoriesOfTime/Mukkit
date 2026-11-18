# 嫁接映射表

一句话：**每一行 PM 能力都必须来自 PM 本体的那次调用，桥只负责把调用结果搬过进程边界。**
这张表是开发时的对照单——想加一个功能，先在这里找到它属于哪一行。

## 白送：直接跑 fork 里的实现

| 能力 | 来源 | 桥要做的事 | 绝不能做的事 |
|---|---|---|---|
| 插件加载与生命周期 | `PluginManager` / `plugin.yml` | 把 `.phar` 路径和元数据交给宿主 | 自己写 `.phar` 解析器 |
| 事件分发 | `PluginManager::callEvent()` | 构造 PM 事件对象，转发 cancel 决定 | 自己遍历监听器 |
| 可取消事件 | `BlockBreakEvent` + `Level::useBreakOn()`；`PlayerDropItemEvent` + `PlayerInventory::removeItem()` / `Player::dropItem()`；切手持、状态切换、方块生命周期、容器事务各自的原语 | 先扣住动作、等判决、放行后按事件名补执行（14 条决定型，清单见 `docs/design/events.md`） | 自己判断该不该碎、该不该丢 |
| 事件优先级与 `@priority` | 反射注册表 | 无 | 自己实现优先级排序 |
| 命令框架 | `CommandMap` + `plugin.yml` | Nukkit 不认识的名字转成一次 PM dispatch，带玩家身份（`command` op） | 自己解析命令树 |
| 权限 | `PermissionManager` + 权限树 | 权限判定结果回传 | 自己维护权限表 |
| 调度器 | `Scheduler` / `ClosureTask` | 每 tick 驱动一次 | 自己写定时器 |
| 配置与 YAML | `Config` | 无 | 自己写 YAML 解析（锚点、类型会丢） |
| 物品 / NBT / 数学 | `Item` / `CompoundTag` / `Vector3` | 字段级序列化 | 自己造物品模型 |
| 颜色码与多语言 | `TextFormat` / `KnownTranslationFactory` | 无 | 自己写 `§` 解析 |
| Composer / Virion | 宿主 autoload | 无 | 自己做依赖解析 |
| `AsyncTask` | 宿主线程池 | 无（进程内，不跨桥） | 搬到 JVM 侧执行 |

以上全部"零实现"，代码里出现第二份就是红线。

## 要嫁接：fork 里调用，桥上搬运

| 能力 | 调用点 | 搬运方式 |
|---|---|---|
| 发消息 / 标题 / 计分板 / 音效 | `NetworkSession::sendDataPacket()` | 包对象 → `packet` op → Nukkit 包（白名单 9 种） |
| 表单 | `Player::sendForm()` / `Player::onFormSubmit()` | `ModalFormRequestPacket` 出去，`ModalFormResponsePacket` 被拦下来变成 `form.response` 入站项（id 由桥发，见 `docs/design/protocol.md`） |
| 玩家状态读写 | `Player::teleport()` / `setHealth()` / `setGamemode()` / `getXpManager()` / `getHungerManager()` / `getEffects()` / `getInventory()->getHeldItemIndex()` | `player.state` op，双向、按字段 last-writer-wins（10 Hz 轮询；效果持续时间按整秒比较；手持槽只是索引，不进背包 op） |
| 玩家背包 | `PlayerInventory` / `ArmorInventory` / `PlayerOffHandInventory` | `player.inventory` op，双向；槽位 0–40，扁平命名空间 id + 数量 + 耐久 meta + NBT 字节 |
| 容器窗口 | `setCurrentWindow()` + `InventoryManager` 的 `ContainerOpen` / `InventoryContent` / `InventorySlot` | `window.open` / `window.content` / `window.slot` / `window.close` / `window.data`；客户端事务以 `inventory.transaction` 回到 PM |
| 方块实体 | `BlockEntityChest` / `BlockEntitySign` / `BlockEntityFurnace` 等 | `chunk.tiles` + `tile` 帧，双向；`getTile()` 返回真 PM Tile，插件写回真实方块实体；开在真实方块实体上的窗口直接绑定它的库存 |
| 世界读取 | `World::getBlock()` | 命中本地镜像，不跨进程（随玩家移动补发/撤销区块） |
| 世界枚举 | `WorldManager::getWorlds()` / `getWorldByName()` / `getDefaultWorld()` | 注册进 manager 但不 tick；默认世界来自 `welcome.defaultWorld` |
| 方块写入 | `World::setBlock()` | `block` op，tick 末批量提交（legacy id + meta，有损） |
| 世界时间 | `World::getTime()` / `setTime()` / `stopTime()` | `world.state` op，双向、每秒一次；天气没有 API，不镜像 |
| 实体查询 | `World::getEntities()` | 已镜像的区块里，PM 工厂认识的类型建成真实体；其余报 `entity.unsupported` |
| 实体移动/血量 | `Entity::teleport()` / `Living::setHealth()` | `entity.state` op 回写；Nukkit 的移动由 `entity.sync` 覆盖 |
| 实体名牌/效果 | `Entity::setNameTag()` / `Living::getEffects()` | `entity.sync` 的 extras 带 `name` / `effects`，双向 |
| 玩家命令 | `PlayerCommandPreprocessEvent` → `command` op → `Server::dispatchCommand($player, …)` | 名字两边都有时 Nukkit 赢；补全与 `/help` 不含 PM 命令 |
| 权限/OP | `Server::addOp()` / `removeOp()` / `hasPermission(ROOT_OPERATOR)` | `player.join` 的 `op` 与 `player.state` 的 `op`，双向。只过 op 一位：`default: op` 节点跟着它解析，单节点授权不过桥 |
| 踢人 | `Player::kick()` → `DisconnectPacket` | 宿主转成 `packet` op `DisconnectPacket`（`message`），适配器用 `Player.close()` 关闭而不是投包，且不回发 `player.quit`。反向走原有 `player.quit` |
| 日志 | `PluginLogger` | `log` op |

## 不支持：加载期就拒绝

| 类别 | 原因码 |
|---|---|
| 依赖 PM 推进世界（生成、红石、物理、载具） | `world.simulate` |
| 自定义方块 / 物品 / 实体注册 | `registry.custom` |
| 自定义网络包、资源包 | `network.custom` |
| 直读世界存储（LevelDB） | `storage.direct` |

拒绝要给原因码，不能静默失败。`StubProfile::UNSUPPORTED` 是参考实现。

## PM 缺钩子时怎么改

顺序固定，不许跳步：

1. **先看能不能不改**：有没有现成的公开方法能达到同样效果
2. **放宽可见性**：`private` → `protected`，这是最小改动
3. **加钩子**：确实没有时，在 fork 里加一个明确命名的扩展点，不写业务逻辑
4. **记录**：把改动补进 `docs/design/pm-fork.md` 的切口表，标明行号和原因

反面做法：在桥接层反射穿透私有成员。改 fork 就断，而且没有编译期保护。

## 验收

每嫁接一个能力，配一条可执行的验证，不接受"看起来能用"：

| 能力 | 验证 |
|---|---|
| 事件 + cancel | 插件取消一次方块破坏，看客户端是否真的没破坏 |
| 命令 | `/money` 走完一次完整往返，回包到客户端（桥内已验 `run-player-commands.sh`，真机待验） |
| 调度器 | 延迟任务在预期 tick 触发，重复任务不漂移 |
| 配置 | 改 `config.yml` 后重载生效 |
| 表单 | 真客户端点按钮，回调落到插件 |
