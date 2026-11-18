# 从干净 clone 开始

一句话：别的机器拉下仓库后，只需要 `./gradlew bootstrap`。它把仓库外的源码、依赖和运行时补齐，
然后由 Gradle 构建 `Mukkit.jar`。

## 前置

| 依赖 | 要求 | 用途 |
|---|---|---|
| JDK | 21+ | Nukkit-MOT 的 MR-JAR 编译到 release 21；Mukkit 自身按 release 17 编译 |
| Maven | 3.6+ | 只用于构建外部的 Nukkit-MOT |
| PHP | 8.1+（带 `phar`） | 跑 PHP 宿主、Composer 与能力探测 |
| git | 任意近版本 | 拉 Nukkit-MOT 与 PocketMine fork |
| curl | 任意近版本 | 下载 Composer 与 pmmp PHP |

Gradle 不需要预装。仓库带 `gradlew`，首次执行会按 `gradle/wrapper/gradle-wrapper.properties`
下载 Gradle 9.9.0。

## 一条命令

```bash
./gradlew bootstrap
```

Gradle 负责 Java 构建与入口；Nukkit-MOT、PM fork、Composer、PHP 运行时是外部工具，
由 `scripts/bootstrap.sh` 这一层准备脚本调起；其余 `run-*.sh` 是混合 PHP/JVM 的场景判据，
不是构建管理。

它会按顺序做：

1. 把 Nukkit-MOT clone 到仓库旁边的 `../Nukkit-MOT`，checkout 到钉住的 commit 并构建；
2. 用 `vendor/pocketmine-fork/0001-*.patch` 重建 `../PocketMine-MP`，再跑
   `composer install --ignore-platform-reqs --no-dev`；
3. 把当前平台的 pmmp PHP 下到 `vendor/php/<platform>/`；
4. 构建 `mukkit-nukkit/build/libs/Mukkit.jar`，并跑 `scripts/check-architecture.sh`。

准备过一次之后，日常只需要：

```bash
./gradlew build
./gradlew checkArchitecture
```

## 目录约定

`mukkit-nukkit/build.gradle.kts` 默认从两个兄弟目录取输入；可用下表的环境变量改到别处：

| 路径 | 内容 | 谁产生 |
|---|---|---|
| `../Nukkit-MOT/target/Nukkit-MOT-SNAPSHOT.jar` | 服务端 jar | `./gradlew bootstrap` |
| `../PocketMine-MP/` | PM fork 源码与 `vendor/` | `scripts/pm-fork.sh`（bootstrap 调用） |

这两个目录都在仓库外，不会被提交。`vendor/php/`、`build/`、`.gradle/`、`.tools/`、`run/`、`dist/`
同样在 `.gitignore` 里。

## 可调的环境变量

| 变量 | 作用 |
|---|---|
| `MUKKIT_NUKKIT_JAR` | 直接指定 Nukkit-MOT jar，跳过 clone/build |
| `NUKKIT_MOT_DIR` | Nukkit-MOT 目录，默认 `../Nukkit-MOT` |
| `NUKKIT_MOT_REPO` | Nukkit-MOT 上游，默认 MemoriesOfTime 仓库 |
| `NUKKIT_MOT_COMMIT` | 钉住的 Nukkit-MOT commit |
| `PM_FORK_DIR` | PocketMine fork 目录，默认 `../PocketMine-MP` |
| `PM_FORK_UPSTREAM` | `pm-fork.sh` 的上游；离线时可指向本地 PocketMine-MP 仓库 |
| `COMPOSER_PHAR` | 现成的 `composer.phar`；不设时依次找 PATH、`.tools/composer.phar`、`/tmp/composer.phar` |
| `MUKKIT_PROXY` | 下载 GitHub release 时用的代理，见 `scripts/fetch-php.sh` |

离线或内网镜像：把 `NUKKIT_MOT_REPO`、`PM_FORK_UPSTREAM` 指向本地仓库，并用 `MUKKIT_NUKKIT_JAR`
跳过 Nukkit 构建；Gradle 本身还需要能拿到 wrapper 分发包（或预装 Gradle 9.9.0 后直接跑 `gradle`）。

## 失败时看哪里

| 现象 | 原因 | 处理 |
|---|---|---|
| `Nukkit-MOT 服务端 jar 不存在` | 没跑准备，或 `../Nukkit-MOT` 没构建 | `./gradlew bootstrap` |
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
