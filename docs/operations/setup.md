# 从干净 clone 开始

一句话：别的机器拉下仓库后，只需要 `./gradlew bootstrap`。它把仓库外的源码、依赖和运行时补齐，
然后由 Gradle 构建 `Mukkit.jar`。

## 前置

| 依赖 | 要求 | 用途 |
|---|---|---|
| JDK | 21+ | Nukkit-MOT 的 MR-JAR 编译到 release 21；Mukkit 自身按 release 17 编译 |
| PHP | 8.1+（带 `phar`） | 跑 PHP 宿主、Composer 与能力探测 |
| git | 任意近版本 | 拉 PocketMine fork |
| curl | 任意近版本 | 下载 Composer 与 pmmp PHP |

Gradle 不需要预装。仓库带 `gradlew`，首次执行会按 `gradle/wrapper/gradle-wrapper.properties`
下载 Gradle 9.9.0。

## 一条命令

```bash
./gradlew bootstrap
```

Gradle 负责 Java 构建与入口；PM fork、Composer、PHP 运行时是外部工具，
由 `scripts/bootstrap.sh` 这一层准备脚本调起；其余 `run-*.sh` 是混合 PHP/JVM 的场景判据，
不是构建管理。

它会按顺序做：

1. `mukkit-nukkit` 按 `cn.nukkit:Nukkit:<nukkitVersion>` 从 `repo.lanink.cn` 解析
   Nukkit-MOT，Gradle 自己管理模块缓存；
2. 用 `vendor/pocketmine-fork/0001-*.patch` 重建 `../PocketMine-MP`，再跑
   `composer install --ignore-platform-reqs --no-dev`；
3. 把当前平台的 pmmp PHP 下到 `vendor/php/<platform>/`；
4. 构建 `mukkit-nukkit/build/libs/Mukkit.jar`，并跑 `scripts/check-architecture.sh`。

准备过一次之后，日常只需要：

```bash
./gradlew build
./gradlew checkArchitecture
```

## Nukkit-MOT 来源

唯一来源是 Maven 坐标：`https://repo.lanink.cn/` 的 `cn.nukkit:Nukkit`，版本由
`gradle.properties` 的 `nukkitVersion` 决定（默认 `MOT-SNAPSHOT`）。`mukkit-nukkit` 的
`compileOnly` / `testImplementation` / `testRuntimeOnly` 都指向同一个坐标；不在本地 clone
或构建 Nukkit-MOT。Gradle 把它缓存到自己的模块缓存里，验收脚本经 `scripts/nukkit-jar.sh`
拿到同一个 jar。

## 目录约定

`../PocketMine-MP/` 是仓库外唯一需要 Gradle 指定的本地目录（PM fork 源码与 `vendor/`），
由 `scripts/pm-fork.sh` 产生；`PM_FORK_DIR` 可以改位置。Nukkit-MOT 只存在于 Gradle 的模块缓存。

`../PocketMine-MP` 在仓库外，不会被提交。`vendor/php/`、`build/`、`.gradle/`、`.tools/`、
`run/`、`dist/` 同样在 `.gitignore` 里。

## 可调的环境变量

| 变量 | 作用 |
|---|---|
| `PM_FORK_DIR` | PocketMine fork 目录，默认 `../PocketMine-MP` |
| `PM_FORK_UPSTREAM` | `pm-fork.sh` 的上游；离线时可指向本地 PocketMine-MP 仓库 |
| `COMPOSER_PHAR` | 现成的 `composer.phar`；不设时依次找 PATH、`.tools/composer.phar`、`/tmp/composer.phar` |
| `MUKKIT_PROXY` | 下载 GitHub release 时用的代理，见 `scripts/fetch-php.sh` |

Nukkit-MOT 的版本用 Gradle 属性 `nukkitVersion` 指定，改 `gradle.properties` 或在命令行加
`-PnukkitVersion=...`。

离线或内网镜像：把 `PM_FORK_UPSTREAM` 指向本地仓库；Nukkit-MOT 需要能访问
`repo.lanink.cn`（或把一台有网机器的 Gradle 模块缓存带到构建机上）；Gradle 本身还需要能
拿到 wrapper 分发包（或预装 Gradle 9.9.0 后直接跑 `gradle`）。

## 失败时看哪里

| 现象 | 原因 | 处理 |
|---|---|---|
| `Could not resolve cn.nukkit:Nukkit` | 连不上 `repo.lanink.cn` | 检查网络/代理，或改 `nukkitVersion`；也可在仓库根跑 `./gradlew :mukkit-nukkit:printNukkitJar` 看解析结果 |
| `PocketMine fork 还没有物化` | 补丁没重放，或 `vendor/` 缺失 | `./gradlew bootstrap` |
| `找不到 composer` | 没装 Composer，`.tools/` 也没有 | `scripts/fetch-composer.sh`，或设 `COMPOSER_PHAR` |
| `需要 JDK 21+` | 当前 `java` 是 17/11 | 切到 JDK 21，`JAVA_HOME` 指向它 |
| jar 只有 2–4 MB | `vendor/php` 或 PM `vendor/` 缺失，打成了瘦 jar | 重跑 `./gradlew bootstrap`，或 `scripts/fetch-php.sh` |
| `不兼容的 Gradle 版本` | wrapper 下载被网络拦截 | 设代理，或预装 Gradle 9.9.0 后直接用 `gradle` |

## 准备之后

```bash
./gradlew runSpike        # 桥内冒烟，不需要 Nukkit
./gradlew runM1           # Nukkit + PHP + phar 端到端
./gradlew runProduct      # 真 Nukkit + jar + EconomyAPI 产品路径
./gradlew serve           # 起一个可连的服务端
```

完整判据与验证分层见 [`docs/project/spec/testing.md`](../project/spec/testing.md)。
