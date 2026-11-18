# PocketMine fork：切口、垫片与约束

基线：`PocketMine-MP` 5.44.4（`stable`，2026-07 归档后不再更新），commit `6a7cc02e9`。
行号以这个基线为准。

上游冻结是这件事能做的前提：fork 一次，不用再追。代价是没有上游修复。

fork 只做无头宿主必须的三件事：让 `Server` 构造返回、让 tick 由外部驱动、让 stock PHP 在没有
pmmp C 扩展时也能跑。PM 的插件 API、`PluginManager`、`Scheduler`、`CommandMap`、`Config`
原样保留——插件看到的仍然是 PocketMine 本体。

## 为什么必须 fork

"无头"不是配置项。`Server::__construct()` 是单体启动，最后直接进入阻塞主循环，
没有任何跳过网络或世界的开关。必须改源码。

## 七个切口

| # | 位置 | 改动 | 为什么 |
|---|---|---|---|
| 1 | `src/Server.php:1770` `enterMainLoop()` | 构造函数尾部改调用它；默认实现仍是 `tickProcessor(); forceShutdown();` | 构造函数能返回，才谈得上被外部驱动 |
| 2 | `src/Server.php:1903` `tick()` | `private` → `public`；半 tick 保护搬进 `tickProcessor()` | 外部快速驱动 1000 tick 时不能被墙钟节流 |
| 3 | `src/Server.php` `tickProcessor()` | 守卫改为 `if(($now - nextTick) >= -0.025)` 才 tick | 语义不变，只是守卫换了个位置 |
| 4 | `src/Server.php:1277` `startupPrepareNetworkInterfaces()` | `private` → `protected`，`HeadlessServer` 覆写为不绑端口 | 无头宿主不监听 UDP |
| 5 | `src/Server.php:1130` `startupPrepareWorlds()` | `private` → `protected`，覆写为不加载默认世界 | 世界归 Nukkit，PM 侧只镜像 |
| 6 | `src/scheduler/AsyncTask.php:121` `markFinishedForSyncPool()` | 新增一个方法，只置 `finished` 标志 | `run()` 会通知 worker 的 sleeper 并断言线程局部状态，同步池不能调它 |
| 7 | `src/Server.php:1779` `createAsyncPool()` + `src/mukkit/SyncAsyncPool.php` | 池改为工厂创建，无头档返回同步实现 | 摘掉 pmmpthread 的最后一处运行时依赖 |

**没有**改 `Network`、`WorldManager`、`PluginManager`、命令框架、事件总线：它们原样跑。
`$this->network` 在构造函数更早的位置就已经建好，所以跳过 `startupPrepareNetworkInterfaces()`
之后 `tick()` 里的 `network->tick()` 仍然安全（零接口即空转），不需要到处加 `if headless`。

## 三处可做假会话的源码依据

| 依据 | 位置 | 含义 |
|---|---|---|
| `NetworkSession` 不是 `final` | `src/network/mcpe/NetworkSession.php` | 可以子类化并拦截发包 |
| `NetworkSession::sendDataPacket()` 是 `public` | 同文件 `:625` | 对象级出站入口，参数是 `ClientboundPacket` |
| `Player` 不是 `final`，构造不发包 | `src/player/Player.php:321` | 构造只存 `NetworkSession` 引用并调 `parent::__construct()`，不会向客户端发任何包 |

两条实现约束：

- `PacketSender` 是 interface，但它是**字节级**的：
  `send(string $payload, bool $immediate, ?int $receiptId)`（`src/network/mcpe/PacketSender.php:26`）。
  所以拦截点放在 `NetworkSession::sendDataPacket()`，不要放在 `PacketSender`。
- `NetworkSessionManager` 是普通 class（`src/network/NetworkSessionManager.php:31`），
  不是 interface，需要自己造一个可用的空实现，而不是 `implements`。

`Player` 构造签名（`src/player/Player.php:321`）：

```php
public function __construct(Server $server, NetworkSession $session, PlayerInfo $playerInfo,
                            bool $authenticated, Location $spawnLocation, ?CompoundTag $namedtag)
```

`PlayerInfo` 用 uuid + username + locale 即可构造，`Location` 自己拼。构造函数里唯一
需要真东西的是 `Server` 单例——也就是已经 boot 起来的半个 `Server`。

## 运行时依赖

真 PM 拒绝在缺扩展的 PHP 上启动。这台机器（Homebrew PHP 8.5）的实测缺项：

```
upstream deps   missing: pmmpthread, leveldb, chunkutils2, igbinary, yaml, crypto, encoding, morton
```

`pmmpthread`、`encoding`、`morton`、`crypto`、`leveldb`、`chunkutils2` 都是 pmmp 自研 C 扩展，
`pecl install` 装不到，得自己编。无头宿主的选择是：**桥不依赖用户安装这些扩展**，同时保留
“自带官方 pmmp PHP”这一档作为完整能力路径。

| 扩展 | 用途 | 无头档的处理 |
|---|---|---|
| `pmmpthread` | `AsyncPool`、`AsyncTask` 线程池 | 同步池：任务在当 tick 内联执行；有官方 pmmp PHP 时用真线程 |
| `leveldb` | 世界存储 | 不需要；镜像走内存 |
| `chunkutils2` | `PalettedBlockArray`、`LightArray`、区块存储 | 用户态实现；它不是协议路径，是方块存储本身 |
| `encoding` / `morton` | 协议二进制编解码、hash | 垫片覆盖；boot 路径和 chunk/block hash 都要用 |
| `crypto` | 登录链校验 | 不需要；无头档不做认证 |
| `yaml` | `plugin.yml`、`Config` | 用户态 YAML 解析器，有真扩展时自动让位 |

刻意**不**垫 `leveldb` 和 `crypto`：它们只服务世界落盘与登录链，伪造出来只会得到
"静默的错误数据"；无头档不会走到那里，缺了就是响亮的失败。

## 垫片层（`src/mukkit/polyfill/`）

| 文件 | 覆盖什么 | 说明 |
|---|---|---|
| `thread.php` | `pmmp\thread\{ThreadSafe,ThreadSafeArray,Runnable,Thread,Worker,Pool}` | 单线程实现，`synchronized()` 直接内联执行。`ThreadSafeArray` 把元素存成动态属性，因为上游有 `(array)$arr` 的用法 |
| `encoding.php` | `pmmp\encoding\{Byte,BE,LE,VarInt,ByteBufferReader,ByteBufferWriter}` | **在 boot 路径上**：`Block::computeStateIdXorMask()` 用 LE/BE，`CraftingManager::hashOutput()` 用 ByteBufferWriter。静态方法同时接受 `string&` 和 buffer 对象 |
| `morton.php` | `morton2d/3d_encode/decode` | `Item::getStateId()` 和 chunk/block hash 都靠它；2D 解码按 32 位符号扩展，负坐标能往返 |
| `yaml.php` + `../Yaml.php` | `yaml_parse()` / `yaml_emit()` 全局函数 | 条件定义，有真扩展时自动让位；用户态解析器支持块映射/序列、行内序列、引号、布尔/null/数字，**不支持锚点、标签、`\|` 与 `>` 块标量** |
| `ext-stubs.php` | `igbinary_serialize/unserialize` | 落到 `serialize()`：`NonThreadSafeValue` 两边都在同一进程，语义一致 |
| `chunkutils2.php` | `PalettedBlockArray` / `LightArray` | 直接存 32 位 state ID，每个段 16 KB；语义等价，内存比调色板索引高 |

**垫片只允许出现扩展也有的成员。** 多出来的成员会让本机判据全绿、产品自带的 pmmp PHP
上却每 tick 报错。扩展的公开面是：

- `PalettedBlockArray`：`__construct`、`get`、`set`、`getBitsPerBlock`、`getWordArray`、
  `getPalette`、`collectGarbage`。
- `LightArray`：`__construct`、`fill`、`get`、`set`、`getData`、`collectGarbage`、`isUniform`。

桥实际只用到 `PalettedBlockArray::__construct/get/set/getBitsPerBlock` 与
`LightArray::fill/get/set/getData`；`getBitsPerBlock()` 在 uniform 时按扩展的语义返回 0。

成员表由 `pm-host/tests/pm-chunkutils2-parity-test.php` 静态扫描 + 反射守住；同一份宿主判据
还要在自带 pmmp PHP 上跑一遍（`scripts/run-m5-pmphp.sh`）。成员一样不等于行为一样，
"本机全绿"不是证据。

两条容易踩的语义：

- **`readByteArray()` 是"读正好 N 字节，无前缀"**，不是 `readString()` 的"先读 varint 长度"。
  转发给 `readString()` 会让 `BlockIdMetaUpgrader::loadFromString()` 把 payload 首字节当长度，
  报 `String is too long (109, max 23)`。同时需要 `getData()` / `getUnreadLength()`。
- **PM5 的 state ID 是 32 位**：`BlockTypeIds::AIR << 11 | stateData`，空气是 `20481500`。
  按 uint16 打包会让空气变成 0，`World::setChunk()` 直接拒绝（"unknown state ID 0"）。

`Server::crashDump()` 会用"最短运行时间"节流自动重启；无头宿主是子进程，节流等于让桥误判
宿主挂了。`src/mukkit/polyfill.php` 定义 `MUKKIT_NO_RESTART_THROTTLE` 绕过。

## 两档 AsyncPool

池的选择在 `HeadlessServer::createAsyncPool()`，只看 `extension_loaded("pmmpthread")`。

| 解释器 | 池 | 行为 |
|---|---|---|
| stock PHP（NTS，无扩展） | `SyncAsyncPool` | `submitTask()` 当场 `onRun()`，`onCompletion()` 推到下一 tick。重活**直接吃掉 tick 时间**；`publishProgress()` 是死代码；timings 不记录 |
| 官方 pmmp PHP（ZTS + pmmpthread） | 上游 `AsyncPool` | 真的 worker 线程；与上游 PM 完全一致 |

pmmpthread 是 pthreads 的后代，`config.m4` 直接断言 `PHP_THREAD_SAFETY`，NTS 的 PHP 装不上。
pmmp 官方 `PHP-Binaries` 提供 macOS arm64/x86_64、Linux x86_64、Windows x64 的成品
（PHP 8.4，带 pmmpthread / chunkutils2 / encoding / morton / yaml）。
`scripts/fetch-php.sh` 下载到 `vendor/php/<platform>`，`serve.sh` 自动把 `MUKKIT_PHP_BINARY` 指过去；
不装就退回同步池，功能全在、只是重活会卡 tick。

用真线程时有三条硬约束：

1. **ThreadSafe 对象里不能存闭包。** pmmpthread 的规则是"bound to non-thread-safe `$this` 的
   闭包不能进线程安全对象"；`HeadlessLogger` 因此把日志 sink 存成静态属性，否则一开真线程就抛
   `Closures with non-thread-safe $this cannot be made thread-safe`。
2. **AsyncTask 里不能捕获非线程安全对象。** 任务对象会被复制到 worker，闭包/插件实例一旦被
   捕获，宿主在 `submitTask()` 当场报错。插件侧遵守上游 PM 的同一套约束；BedcoreProtect /
   ChestShop 在同步池下启不来、在真线程下正常，原因就在这里。
3. **同步池的任务语义不是并发。** 重活会直接吃 tick；这是降级，不是等价实现。

## pm profile：真插件接入

`pm-host/src/PmProfile.php` + `PmRuntime.php` 把 fork 接进产品路径，`--profile=pm` 生效：

- 插件加载：`PharPluginLoader` + `PluginManager::loadPlugins()`，**unmodified phar**，不认自定义契约。
  `plugin.yml` 的 `main` 指向真 `PluginBase` 子类。
- 入站 `command`：PM 的 `CommandMap::dispatch()`，console sender 的输出用 `ob_start()` 截住，
  逐行翻成 `log` op（`Terminal::writeLine` 是 `echo`，不截就会漏到宿主 stdout）。
- 入站 `plugin`：`PluginManager::enablePlugin()/disablePlugin()`。
- 入站 `event`：`player.join` / `player.chat` / `player.quit` 走真玩家生命周期；没有 PM 等价物的
  事件名显式拒绝并给一次 `event.unsupported` 警告——没有等价物的假事件比没有更坏。
- 出站：`HeadlessLogger::setSink()` 把插件日志转成 `log` op，不写 STDOUT；error 级以上同时写
  STDERR，否则 boot 期崩溃只会在进程死后留下一堆没人送出去的 op。

`pm-host/tests/pm-profile-test.php` 钉住这几件事：phar 由 PM 自己的 `PluginManager` 加载、
插件 enabled、数据目录与 YAML round trip、命令到真 `CommandMap`、余额跨 tick、事件拒绝。

插件来源：`scripts/build-economyapi.sh` 钉一个 commit（`imjustnozell/EconomyAPI-Dev@7ae5845`，
`api: 5.0.0` 的维护分支）打成 phar。上游官方 EconomyAPI 只到 API 4，所以用社区维护版；
换版本只改 `ECON_API_COMMIT`。

## 玩家代理：真 Player，假会话

插件的签名写的是具体类 `pocketmine\player\Player`，没有"实现一个接口"的余地——只能给真
`Player`，而真 `Player` 的构造函数硬性要求 `World` + `NetworkSession`：

| 新增（`src/mukkit/`） | 作用 | 为什么不能更省 |
|---|---|---|
| `BridgeWorldData` / `BridgeWorldProvider` | 内存世界：没有区块，有元数据 | `Player::__construct()` 要 `World`；PM 自带的 provider 只有 LevelDB 一种，要 `leveldb` 扩展 |
| `BridgedSession extends NetworkSession` | `sendDataPacket()` 只入队不编码；`onChatMessage()` 用服务端语言翻译 | `Player::sendMessage()` 就是 `getNetworkSession()->onChatMessage()` |
| `BridgedPlayer extends Player` | 只加 `getBridgeId()`（Nukkit 的 UUID） | 需要同一个 UUID，`packet` op 才对得上客户端 |
| `BridgedPlayers` | 建世界、join/quit、按 UUID/名字查找 | join 与 quit 都走上游自己的生命周期，不自己造 |

join 的链路：`Server::addOnlinePlayer()`（发 `PlayerLoginEvent`、进 playerList）→
`Player::doFirstSpawn()`（发 `PlayerJoinEvent`、广播加入消息、标记 spawned）。
quit 的链路：`NetworkSession::disconnect()`（翻状态、发包进 outbox）→
`Player::onPostDisconnect()`（发 `PlayerQuitEvent`、存档、移出在线列表）。
第二个调用本应由 `NetworkSession::disconnect()` 自己完成，但它通过一个私有 `$player` 字段做，
而"没人登录过"的会话那个字段永远是 null——所以宿主补这一下。

`kick()` 走同一条 `disconnect()`，PM 侧的退出事件在包入队之前就已经触发过；因此
`BridgedPlayers::quit()` 对已经断开的玩家只摘映射、不再跑生命周期，否则同一场离开会触发两次。

聊天：入站 `player.chat` 直接调 `Player::chat()`，玩家发的 `/命令` 也自然以玩家为
`CommandSender` 走 `CommandMap`（语义与上游一致）。真实玩家的输入另有一条入口：
Nukkit 的 `PlayerCommandPreprocessEvent` → `command` op（带玩家 uuid）→ 同一次
`Server::dispatchCommand($player, …)`。

当前边界：

- 世界名来自 join 帧；PM 侧一个 level 名一个 `World`，多世界可用。
- 区块由 Nukkit 推送并建成真 `Chunk`；`BridgeWorldProvider` 本身不生成区块。
- 玩家 `viewDistance` 保持 -1，避免 PM 自己去 order/生成区块；区块只走镜像。
- 出站包映射在宿主与适配器，fork 的 `sendDataPacket()` 只入队不编码；
  白名单 9 种，见 [`protocol.md`](protocol.md)。

`BridgedSession::truncateOutbox()` 在应用入站状态时会丢掉这次应用**新产生**的包，但保留同一
tick 里更早排队的包。不丢的话，`setGamemode()` 触发的 `SetPlayerGameTypePacket` 会立刻回到
刚告诉我们这件事的那一侧，两边开始对讲。

## 世界镜像与方块存储

| 新增（`src/mukkit/`） | 作用 |
|---|---|
| `polyfill/chunkutils2.php` | `pocketmine\world\format\PalettedBlockArray` / `LightArray` 的用户态实现 |
| `BridgeBlocks` | legacy id:meta ↔ 内部 state ID，两侧各用自己的表，带缓存 |
| `BridgeWorldMirror` | 快照建区块、影子副本、diff 出本地写入、应用远端更新 |

`PalettedBlockArray` 的扩展版本存 16 位调色板索引，配套 palette 映射到 state ID；垫片直接存
32 位 state ID（每段 16 KB）。语义等价、实现简单，也不存在"索引越界"这类静默错误。代价是
内存：一个 384 高、有地形的区块约 200–400 KB，5×5 镜像是几 MB 级；真实扩展更省，但这里省
的不是瓶颈。

这一层全用上游公开 API：`World::setChunk()` 换块、`Chunk::setBlockStateId()` 写块、
`Chunk::clearTerrainDirtyFlags()` 抑制回声。唯一"新"的写操作是
`Chunk::setLightPopulated(true)`：`isChunkTickable()` 要求邻域光照就绪，而镜像世界永远不会跑
光照任务，不标就永远不 tick（连玩家聊天计数器都不刷新）。

方块实体额外靠 `Chunk::addTile()` 直接挂，不走 `World::addTile()`：后者会排 block update，
熔炉会在 PM 里自己烧起来。Nukkit 是唯一模拟器。

## 出站包：fork 一行没改

出站包映射全在宿主侧（`PmRuntime::mapPacket`）和适配器侧（`PacketMapper`），fork 的
`BridgedSession::sendDataPacket()` 只做"入队不编码"。加包不需要碰 PM 源码，也不需要碰它的
协议版本。宿主 tick 必须先排空 outbox 再取日志，否则映射包产生的丢包警告会被推到下一 tick。

JVM 侧两处翻译值得记住：

- **`PlaySoundPacket` 的坐标是整块**。Nukkit 的字段是 `int`，编码时 `<< 3`；它自己的
  `Level::addSound()` 填的是 `getFloorX()`。所以 PM 的浮点坐标必须向下取整（负数朝 -∞，
  不是朝 0），否则音效落点差一格。
- **枚举不能传序号**。`DisplaySlot` 在 Nukkit 是枚举、在 PM 是字符串，`SortOrder` 也是枚举；
  桥上传名字，两侧各自解析。

## 玩家状态：用公共 API 做影子副本

`BridgePlayerState` 是世界镜像的同构做法，理由更尖锐：**PM5 里插件的状态改动压根不产生包**。
`setHealth()` 只改字段（血量随属性包走），`setGamemode()` 的同步读一个只有登录流程才填的私有
`$player`，背包同步挂在同样私有的 `InventoryManager` 上。想让这些包"自然流出来"，就得复刻
登录流程——那比对比公共 API 危险得多。

宿主在 tick 末尾对比 `getLocation()` / `getHealth()` / `getMaxHealth()` / `getGamemode()`，
只把变化的字段发出去；Nukkit 侧对称地按 `mukkit.stateInterval`（默认 2 tick）轮询自己的玩家。
两边应用之后都用**结果**重设影子副本——上游会把血量夹到上限、插件可能取消
`PlayerGameModeChangeEvent`，只有读回来才不会把没发生的变化再报一次。

没有新切口：`setPosition()` / `setRotation()` / `resetLastMovements()` 都是 protected 的上游原语，
`BridgedPlayer` 是子类，这正是子类的用途。位置写入刻意不走 `teleport()`：那会触发
`PlayerTeleportEvent` 并入队 `MovePlayerPacket`，而镜像同步不是插件动作。

## 可取消事件

`BridgeEvents` 把帧变成真的 PM 事件，把 `isCancelled()` 的结论还给适配器。两个设计点：

1. **`demanded()` 用 `HandlerListManager::global()->getHandlersFor()`。** 没有监听者就不拦截，
   否则每个动作都要晚一 tick 生效、还要付一次客户端闪烁。插件的启用/停用会改变这个集合，
   宿主同 tick 用 `capabilities` op 通知适配器。
2. **事件里的方块来自镜像，物品来自命名空间 id。** 物品绝不过数字 id：PM 的内部 id 与 Nukkit
   的 legacy id 都是构建相关的。方块有共同的 legacy 方言，物品没有，所以物品只能用名字。

没有新切口：`HandlerListManager` 和事件类都是公开 API。

## 分发：fork 源码随 jar

`mukkit-nukkit/build.gradle.kts` 把 `../PocketMine-MP/{src,vendor,generated,resources}` 打成本 jar 的
`pocketmine/` 资源；启用时按 jar 的 **SHA-256** 打戳解包到 `plugins/Mukkit/pocketmine/`。

不发 phar/二进制的原因：

- PM 是 LGPL-3.0。源码在同一份产物里，"可替换"就是字面意义上的可替换——用户能改、能重建。
- 离线服不联网也能装：一个 jar 就是全部。
- 代价是 jar 多出约 3.7 MB，解包约 1 秒。

打戳用内容哈希而不是版本号：开发时版本号常年不动，用版本号会让重建的 jar 继续跑上一次的
PHP——"改了没生效"是最难查的一类 bug。

## fork 怎么进仓库：单个 patch

源码本身不在本仓库；`vendor/pocketmine-fork/0001-Mukkit-headless-PocketMine-fork.patch` 是
`git format-patch` 的产物，一个 commit 包含全部 fork 改动，基线 commit 钉死，diff 就是评审对象。不选 submodule（clone 容易漏 `--recursive`，PR 里看不见
diff），也不 vendor 整份源码（上游一升级就是几万行噪声）。

- `scripts/pm-fork.sh`：按 `PM_FORK_BASE` 克隆上游到 `../PocketMine-MP`（已存在则只 fetch），
  再 `git am` 这个 patch；幂等，可重跑
- 升级基线 = 换 base + 重打补丁 + 跑 L3
- 基线：`6a7cc02e9dff59b69241aa0bcffdb9903ce86beb`（pmmp `stable`，`git describe` =
  `5.44.3-2-g6a7cc02e9`，2026-07-09）。当前单个 patch 重建出的 tree hash 是
  `f161737e2bddb408d1fcf7582829a33043b10448`。

**`vendor/` 是这一步的硬依赖。** jar 里除了 `src` 还要 `vendor`（composer 依赖，13 MB），少了
它 `./gradlew build` 一样成功——只是打出一个 2 MB 的 jar，到用户机器上 PM 起不来。所以
`pm-fork.sh` 在补丁之后会跑 `composer install --ignore-platform-reqs --no-dev`，并在缺少
composer 时直接失败，而不是留一个能构建、不能运行的树。

## 当前边界

- 扩展探测降级为警告（`PocketMine.php` 的 `checkExtensions()`）尚未接上：探针/pm profile 直接
  构造 `Server`，绕开了那条路径；等 jar 内真正接管 boot 时必须改。
- 世界按 Nukkit 的 level 名注册、可枚举、可查找，但不 tick；`loadWorld()` 仍只从磁盘加载，
  插件不该卸载镜像世界。
- 方块实体支持 PM 有 Tile 类的类型（箱子、告示牌、熔炉等）；发射器/投掷器等 PM 没有对应
  Tile 类的类型不发，`getTile()` 保持 null。
- 表单响应已接：`BridgedPlayer::sendForm()` 覆写上游的 id 分配（从 `1_000_000` 起，与 Nukkit
  自己的 0 起计数器错开），适配器认领 `ModalFormResponsePacket` 并转成 `form.response`，
  宿主交给 `Player::onFormSubmit()`。没有新切口：`$forms` 是 protected，`onFormSubmit()` 是 public。
- 插件 `AsyncTask` 重活的降级探测尚未实现（同步池下只会掉 TPS，不会告警）。
- 实体装备、AI、插件生成实体不镜像；`BlockPlaceEvent`、`EntityDamageEvent`、
  `PlayerInteractEvent` 不桥，理由见 [`events.md`](events.md) 与 [`graft-map.md`](graft-map.md)。
