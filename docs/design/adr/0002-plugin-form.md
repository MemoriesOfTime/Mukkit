# ADR 0002：产品形态是一个装进 `plugins/` 的 jar

- 状态：已接受
- 日期：2026-11-16
- 影响范围：`mukkit-nukkit`

## 背景

目标很具体：**让一个已经在跑的 Nukkit-MOT 服务端能加载 PocketMine 插件**。不是做一个新服务端，
也不是让用户换启动方式。

这件事有一个容易被忽略的约束：用户的服务端是别人的。面板、systemd 单元、Docker 镜像、
启动参数、`plugins/` 目录的备份策略，全都是围绕"`java -jar Nukkit-MOT.jar`"这一个事实建立的。
任何要求改启动方式的方案，都等于要求用户把服务器交给我们托管。

## 决策

**交付物是一个 jar：丢进 `plugins/`，重启，生效。**

1. 入口是 `MukkitPlugin extends PluginBase`，走 Nukkit 原生的 jar 插件加载路径。
2. PHP 宿主（`pm-host/**/*.php`）作为资源打在 jar 里，`onEnable` 时解包到
   `plugins/Mukkit/pm-host/`，在 `onDisable` 后依然保留（便于排查）。
3. 不修改 Nukkit、不继承 `Server`、不开反射、不要求改 JVM 参数。
4. tick 通过 `ServerScheduler` 的 `scheduleRepeatingTask(this, tick, 1)` 挂进服务端主线程，
   这是插件能合法拿到"每 tick 一次"的唯一入口。
5. `.phar` 的加载发生在 `onEnable` 里：`registerInterface(PhpPluginLoader.class)` 之后
   调 `pluginManager.loadPlugins(plugins/, List.of(PhpPluginLoader.class.getName()))`。

## 理由

- **安装动作只有一个。** 用户不需要知道桥的另一半是 PHP，也不需要对齐 Java 侧和 PHP 侧的版本
  ——它们本来就来自同一个 jar。
- **服务端还是他的。** 升级 Nukkit 时换自己的 jar 即可，Mukkit 只要跟着 API 走。
- **崩溃域不变。** PHP 仍然是子进程；它挂了不影响 Nukkit 的 tick，只影响 phar 插件。
- **插件顺序是确定的。** Nukkit 在 `Server` 构造器里扫描 jar 插件，那时还没有任何插件被启用，
  所以第二个加载器只能在插件内部注册。结论：**jar 插件先加载并启用，phar 插件随后加载**。
  这条要写进文档，因为它决定了 phar 插件不能依赖 jar 插件的 `onLoad` 顺序。

## 后果

正面：

- 装法、备份、面板、容器镜像全部沿用现有习惯
- 一个 jar 里 Java 与 PHP 版本天然对齐，不会出现"桥的一半比另一半新"
- 与 Nukkit 的耦合收窄到 `PluginBase` + `ServerScheduler` + `PluginManager` 三个公开面

负面：

- phar 插件在 jar 插件之后启用，`depend/softdepend` 不跨格式生效；能力探测尚未实现
- 解包 PHP 宿主会让 `plugins/Mukkit/` 多出几十个文件，需要用户知道那是资源不是配置
- 若 Nukkit 改掉 scheduler 的"每 tick 一次"语义（比如合并 tick），这里要跟着改

## 被否决的方案

| 方案 | 否决原因 |
|---|---|
| 自带启动器（`MukkitMain` + `MukkitServer` 接管 tick） | 改启动方式；用户必须放弃自己的服务端 jar |
| 打补丁的 Nukkit fork | 每个版本都要维护一份，且与"jar 插件"目标矛盾 |
| 独立网关进程 + 转发协议 | 等于自己实现 Bedrock 服务端，与"复用 Nukkit"直接冲突 |
| 让用户手工解包 PHP 宿主到固定路径 | 安装动作变成两步，且 Java/PHP 版本会漂移 |

## 检测漂移

`scripts/check-architecture.sh` 现在机检三条：

1. 仓库里不允许出现任何 `package cn.nukkit`（没有 shim，也没有例外）
2. 打出来的 `Mukkit.jar` 里不允许有 `cn/nukkit/` 的类
3. `Mukkit.jar` 必须自带 `plugin.yml`、`config.yml` 与 `pm-host/**` 的 PHP 宿主
