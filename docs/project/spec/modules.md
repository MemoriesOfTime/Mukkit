# 模块边界

## 职责

| 模块 | 语言 | 职责 | 明确不做 |
|---|---|---|---|
| `mukkit-bridge` | Java | 帧编解码、socket 通道、tick 驱动、超时与监督 | 不认识 Nukkit，不认识 PM |
| `mukkit-nukkit` | Java | `PluginLoader` / `PluginBase` 适配、事件构造、op 应用、无头启动 | 不认识 PM 的任何类 |
| `mukkit-spike` | Java | 死亡判据验证，stub 驱动 | 不参与生产部署 |
| `pm-host` | PHP | 宿主循环、profile 抽象、能力探测 | 不实现 PM 的 API |
| `pm-fork` | PHP | 真正的 PocketMine，改了五处切口 | 不进本仓库主线（见下） |

### `mukkit-nukkit` 里有什么

产物是一个 jar：`mukkit-nukkit/build/libs/Mukkit.jar`，装进服务端 `plugins/` 即生效。
里面有 Java 代码，也有 PHP 宿主（作为资源，运行时解包）。

| 类 | 职责 |
|---|---|
| `MukkitPlugin` | 插件入口：解包宿主、启动、注册命令、挂 tick 任务、关停 |
| `BridgeService` | 把宿主接进运行中的服务器：扫 phar、握手、加载启用、重启、状态 |
| `BridgeSettings` | `config.yml` 的四个数字与路径 |
| `PluginAssets` | 从 jar 解包 `pm-host/**` 到数据目录（内容不同才覆盖） |
| `PhpPluginLoader` | `registerInterface` 的实现，phar 过滤器，enable/disable 转发 |
| `PhpPlugin` | 一个 PHP 插件在 Nukkit 里的名字牌，所有钩子转发给宿主 |
| `NukkitGateway` | 事件入队、op 应用、日志与统计 |
| `PacketMapper` | `packet` op 的类名 + 字段表 → Nukkit 包对象 |
| `PharMeta` | 调内置的 `pm-inspect.php` 读 `plugin.yml`（Java 不解析 phar） |
| `HostProcess` | 宿主进程的 spawn / 握手 / 重启 |

**没有任何类放在 `cn.nukkit` 包里。** 产品形态是插件，不是改过的服务端：`Server`、
`ServerScheduler`、`PluginManager` 三个公开面就是全部接缝。`scripts/check-architecture.sh`
会拦 `package cn.nukkit` 的声明，也会拦打出来的 jar 里混进 `cn/nukkit/` 的类。

## 依赖矩阵

| ↓ 依赖 → | bridge | nukkit | spike | Nukkit | PM |
|---|---|---|---|---|---|
| `mukkit-bridge` | — | ✗ | ✗ | ✗ | ✗ |
| `mukkit-nukkit` | ✓ | — | ✗ | ✓ | ✗ |
| `mukkit-spike` | ✓ | ✗ | — | ✗ | ✗ |
| `pm-host` | ✗ | ✗ | ✗ | ✗ | ✓（fork） |

`mukkit-bridge` 无第三方运行时依赖。要加依赖必须先写 ADR，说明为什么不自己写、
体积和许可证影响是什么。

## 模块间只通过协议通信

- Java ↔ PHP：只有 `docs/design/protocol.md` 定义的那些帧
- `bridge` ↔ `nukkit`：只有 `Gateway` 接口和 `Map<String, Object>` 消息
- 不传 Nukkit 对象进 `bridge`，不传 PM 对象出 `pm-host`

消息里一律用 Map/List/基本类型，不用自定义 DTO 跨模块。理由：协议要能被日志完整打印，
DTO 会诱使人在两侧共享类，最后共享成耦合。

## 命名

- Java 包：`cn.mukkit.<模块>`，如 `cn.mukkit.bridge`、`cn.mukkit.nukkit`
- Java 类：职责名，不用 `Manager` / `Helper` / `Util` 这种无信息词（`TickDriver`、
  `HostChannel`、`Gateway` 是正例）
- PHP 命名空间：`Mukkit\Host\`，一个类一个文件，文件名 = 类名
- op 类型、帧类型：小写无下划线（`tickdone`、`packet`、`player.state` 除外——
  带点表示层级）
- 配置项：`MUKKIT_` 前缀的环境变量，或 `mukkit.yml` 里的 `kebab-case`

## 新增模块的判据

三条全过才可以加：

1. **能被独立测试。** 不启动其他模块也能验证它的核心行为。
2. **有单一职责。** 一句话说不清职责的模块，说明它是两三个模块粘在一起。
3. **不制造反向依赖。** 加完必须更新上面的依赖矩阵，且不出现新的反向箭头。

加模块要同时改这个文件。没改的 PR 视为没写完。

## 未来可能出现的模块

记录下来避免临时起意：

- `mukkit-pmapi` —— **永远不会有**。这个名字本身就是红线清单第 1 条。
- `mukkit-form` / `mukkit-scoreboard` —— 不需要。表单和计分板走包映射，
  单独做模块就是重写 PM 的功能。
- `mukkit-protocol-v2` —— 只有协议大改才拆，正常情况下在 `bridge` 里演进。
