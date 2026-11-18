# Docker 测试环境

给 Mukkit 做端到端测试用的容器环境。分两种用途：

- **真实插件服**（默认）：`mukkit-server` 镜像，只装 Poggit 上的真实 PocketMine 插件，
  用来回答"真插件到底能不能跑"。镜像里只有 JDK 21，PHP 由 `Mukkit.jar` 自带。
- **回归工具链**：`mukkit-test-env` 镜像，JDK 21 + PHP + Gradle wrapper，跑 `docker-test.sh` 的自动判据。

## 起一个能进服的真实插件服

```bash
bash scripts/docker-serve.sh                 # 127.0.0.1:19132，真实插件
PORT=19133 bash scripts/docker-serve.sh      # 19132 被别的服务占用时
```

容器里跑的是 `scripts/serve.sh --no-build`，插件模式固定为 `real`：

| 插件 | 版本 | 覆盖的桥能力 |
|---|---|---|
| EconomyAPI | 5.7.2-Dev | 经济 API、命令、数据落在 `plugins/EconomyAPI/` |
| MultiWorld | 2.1.2 | 世界创建/加载/传送、`WorldManager`、命令 |
| Texter | 4.3.3 | 悬浮文字：实体/名牌、世界定位 |
| Wilderness | 2.1.3 | 区域保护、方块事件、玩家位置 |
| BedcoreProtect | 1.2.4 | 方块日志/回滚，libasynql SQLite worker |
| ChestShop | 5.3.9 | 牌子商店、经济、容器，libasynql worker |
| ScoreHud | 8.0.0 | 计分板（18 万下载），走 `SetDisplayObjective` / `SetScore` |

**一个探针都不装。** 数据留在 `run/docker-real`（世界、玩家、插件数据下次还在），
宿主启动后会核对这 7 个插件都在 `enabled` 状态、且日志里没有 `Probe`；任何一项不满足
脚本直接非零退出。

`PLUGINS=probe` 是另一条路：只装 `examples/` 的 11 个探针 + EconomyAPI，数据在
`run/docker-probe`，给 `docs/operations/smoke.md` 的 L4 清单用。两种模式不会互相污染。

## 为什么是 linux/amd64

pmmp 的 PHP-Binaries 只发 `Linux-x86_64`，没有 `Linux-arm64`。Apple Silicon 上如果按
默认 arm64 起容器，宿主会看到 `platform=linux-arm64`，匹配不到 jar 里的运行时，退回
系统 PHP（没有 `pmmpthread`）——BedcoreProtect / ChestShop 一 enable 就把宿主带崩，
日志只有 `plugin host closed the connection`。

所以 `docker-serve.sh` 固定 `--platform linux/amd64`，走 Docker Desktop 的 Rosetta/QEMU。
代价是启动慢一点（本机约 10 s），收益是跑的是 jar 里那份**真的 pmmp PHP 8.4**：

```
[Mukkit] Mukkit: platform=linux-x86_64 php=.../plugins/Mukkit/php/linux-x86_64/bin/php7/bin/php
```

`mukkit-server` 镜像只含 JDK；不要用 `MUKKIT_BUNDLED_PHP=0` 或系统 `php` 覆盖它。

## 控制台

```bash
docker attach mukkit-real
```

接上后直接敲命令（**不带前导斜杠**），例如 `help`、`plugins`、`mukkit status`。
Nukkit 控制台没有提示符，敲完回车看日志即可。

退出 attach 用 **Ctrl-P 然后 Ctrl-Q**（先按 Ctrl-P，松开，再按 Ctrl-Q）：只断开终端，
服务端继续跑。**不要按 Ctrl-C**，那会发 SIGINT 把服务端停掉。

> 容器必须用 `-dit` 启动（`docker-serve.sh` 已经这么做）。如果看到
> `OpenStdin=false`，说明是旧容器，`docker stop mukkit-real` 后重新跑一次脚本。

只看日志不输入命令：`docker logs -f mukkit-real`。

## 网络

**UDP 和 TCP 都要发布**：NetherNet 的 signaling 走 TCP、WebRTC 媒体走 UDP；只映射 UDP
会让 1.26.60+ 的客户端连不上（它们已经没有 RakNet 了）。`serve.sh` 首次生成的
`nukkit-mot.yml` 里 `nethernet.enabled: true`，不要手动关掉。

## 怎么跑自动判据

```bash
bash scripts/docker-test.sh                     # 构建工具链镜像并跑 scripts/run-m1.sh
MUKKIT_NO_BUILD=1 bash scripts/docker-test.sh   # 复用已有镜像，跳过构建
bash scripts/run-m5-pmphp.sh                    # 同一份宿主判据，跑在 jar 自带的 pmmp PHP 上
```

工具链镜像（`mukkit-test-env`）包含 JDK 21、`php-cli`、`php-mbstring` 和 `unzip`：
PocketMine 的启动路径（方块注册表、崩溃转储）会调 `mb_*`，少了它宿主在
`Server::__construct()` 就崩；`unzip` 给 Gradle wrapper 解分发包。
**镜像不含项目源码**，也不执行项目构建——源码和 Nukkit jar 都靠挂载。

`run-m5-pmphp.sh` 用的是 `mukkit-server` 镜像加 `run/docker-real` 里那份自带运行时，
也就是**产品实际跑的 PHP**（带 `ext-chunkutils2`）。本机 `php-cli` 没有这个扩展，桥走的是
`src/mukkit/polyfill/`，两边的类只保证"成员表一致"；方块的读写语义必须两边各跑一次，
否则"本机全绿、真机每 tick 报错"是可以同时成立的（2026-11-17 就是这么炸的）。

## 为什么挂工作区父目录

Nukkit 的 fat jar 在**仓库外**的兄弟目录 `Nukkit-MOT/target/Nukkit-MOT-SNAPSHOT.jar`，
只挂 `Mukkit` 就看不到它。所以把仓库的父目录整层挂到 `/work`：`-w /work/Mukkit`
是工作目录，`/work/Nukkit-MOT/target/*.jar` 同时可见。**容器内路径必须与宿主目录结构一致**，
因为脚本里 Nukkit jar 的路径是按兄弟目录相对位置写死的，换挂载点就会断。

## 依赖缓存

命名卷 `mukkit-gradle` 挂在 `/root/.gradle`（Gradle 分发包与构建缓存），避免每次重下。
清空：`docker volume rm mukkit-gradle`（容器还在跑会报占用，先 `docker ps` 停掉）。
