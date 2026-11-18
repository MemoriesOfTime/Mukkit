# 发行形态与平台

一句话：**一个 jar，内含四个平台的 PHP 运行时**。安装动作仍然只有"丢一个 jar 进 `plugins/`"，
用户不需要选平台，也不需要系统 PHP。

## 平台矩阵

| 平台 id | pmmp 产物 | 压缩 | 解压后 | 场景 |
|---|---|---|---|---|
| `macos-arm64` | `PHP-8.4-MacOS-arm64-PM5.tar.gz` | 10.3 MB | 34 MB | Apple Silicon |
| `macos-x86_64` | `PHP-8.4-MacOS-x86_64-PM5.tar.gz` | 10.9 MB | 34 MB | Intel Mac |
| `linux-x86_64` | `PHP-8.4-Linux-x86_64-PM5.tar.gz` | 13.2 MB | 35 MB | 绝大多数服务器 |
| `windows-x64` | `PHP-8.4-Windows-x64-PM5.zip` | 12.6 MB | 36 MB | Windows 面板服 |
| ~~`android-arm64`~~ | — | — | — | 不发：那是 Android 上的 PocketMine，与 Nukkit 插件无关 |

四份解压后约 **150 MB**。jar 从 3.9 MB 变成 **68 MB**——对比 Nukkit 自己的 fat jar 约 100 MB。
代价是所有人都下载四份，收益是**没有任何平台相关的安装步骤**。

> 68 MB 比原始 tar.gz 之和（约 47 MB）大，是因为 zip 对每个文件单独压缩，拿不到 gzip 整包的
> 压缩率优势。可以改成"jar 里存四个 tar.gz、安装时解压"来省 20 MB，但那要让 Java 自己解析
> tar.gz；现在这个体积仍在 Nukkit 本体之下，先不动。

### 下载这四个运行时

`scripts/fetch-php.sh` 自动探测一个能连 GitHub 的代理（Clash 之类常见的本地端口优先），也可以
显式指定：

```bash
bash scripts/fetch-php.sh --platform all                       # 自动探测代理
MUKKIT_PROXY=http://127.0.0.1:7890 bash scripts/fetch-php.sh   # 指定代理
```

**不要盲信 `http_proxy` / `https_proxy`**：桌面工具注入的往往是它自己的内部通道，握手能过、
拉大文件会截断，最后得到一个 `gzip: invalid stored block lengths` 的坏包。脚本因此把本机常见
代理端口排在环境变量之前，并用 512 KB 的实测下载而不是 1 字节来判定"这个代理能用"。
万一还是拿到坏包，`archive_ok` 会在解压前发现并整份重下。

## 为什么是方案 B（而不是每个平台一个包）

| 方式 | 体积 | 离线 | 代价 |
|---|---|---|---|
| **B. 一个 jar 内含四份**（当前） | 68 MB | ✅ | 为用不到的平台付流量；构建变慢 |
| A. 每平台一个 zip | 每个 ~19 MB | ✅ | 四个产物、用户要选对平台、发错包就是"找不到 PHP" |
| C. 首次运行下载 | 3.9 MB | ❌ | 离线服不可用；GitHub release 国内常年 100–200 KB/s |

选 B 的核心理由是**产品形态**：Mukkit 的卖点是"把 phar 丢进 `plugins/` 就能用"。多一个"请先确认
你的系统架构"的步骤，就把这个卖点打掉了。B 的 68 MB 是一次性成本，Nukkit 本体已经 100 MB。

## 运行机制

1. 插件启用时读 `runtime/<platform>/.version`（**直接读 jar 条目**，不走 classloader——Nukkit 给插件
   的类加载器不保证暴露资源）。
2. 目标目录 `plugins/Mukkit/php/<platform>/` 里已有同版本，就跳过；否则清空重解当前平台那一份。
3. 解包后：给 `php` 等二进制补执行位（jar 不保留文件模式），把 `php.ini` 里的 `zend_extension`
   改成本地 `opcache.so` 路径（原值指向 pmmp 构建机的绝对路径，在用户机器上必然加载失败）。
4. 之后 `PhpRuntime` 的解析顺序：

```
MUKKIT_PHP_BINARY  →  plugins/Mukkit/php/<platform>/  →  jar 内 runtime/<platform>/  →  config.yml  →  PATH
```

**两个包布局不一样，这是一个静默故障点**：macOS / Linux 是 `bin/php7/bin/php`，Windows 是
`bin/php/php.exe`（没有 `php7` 这一层）。只认前一种时，Windows 上运行时会正常解出来、然后被判为
"找不到 PHP"，静默回退系统 `php`——而面板服通常没有。`PhpRuntime.binaryIn` 两种都认，
`PhpRuntimeCheck` 用真实目录树钉住；下载脚本的 `php_binary_in` 是同一份清单。

版本戳是**运行时自己的版本**（`PHP-8.4-MacOS-arm64-PM5.tar.gz`），不是 jar 哈希：重打 jar 不该让
客户端重解 34 MB。

## 构建与出包

```bash
bash scripts/fetch-php.sh --platform all     # 四个平台下到 vendor/php/<platform>（约 47 MB 下载 / 150 MB 落地）
bash scripts/package-release.sh              # 出 dist/mukkit-<ver>.zip（jar 已含四份运行时）
```

`vendor/php` 不存在时 `./gradlew build` 仍然成功，只是打出**瘦 jar**（3.9 MB）——那就退回系统 PHP，
`docs/design/pm-fork.md` 里"两档 AsyncPool"的后一档。`package-release.sh` 会检查 jar 里到底带了哪几个
平台，缺了会警告。

## 已验证

- 瘦 jar（无运行时）：`PRODUCT PASS`，宿主跑系统 PHP 8.5。
- 带 `macos-arm64` 的 jar：`PRODUCT PASS`，插件从 jar 里解出运行时并**改用它**，日志：

```
Mukkit: platform=macos-arm64 php=.../plugins/Mukkit/php/macos-arm64/bin/php7/bin/php
```

- 解包目录结构、`.version` 戳、opcache 路径改写都按上面第 2–3 步落地。

## 许可（发布前必须确认）

- PHP 本体是 PHP License 3.01，允许二进制再分发，但**必须随包附许可文本**；pmmp 的压缩包里没有，
  出包时自己补一份进 `META-INF/` 或 `INSTALL.txt` 旁边。
- `pmmp/PHP-Binaries` 仓库**没有声明 license**。把它的构建打进另一个产品的发行包属于再分发，
  **需要向 pmmp 确认**。在这之前 `dist/` 只能内部用——这是发布阻断项，不是脚注。
- PocketMine-MP 是 LGPL-3.0：fork 的单个 patch 已在 `vendor/pocketmine-fork/` 开源，满足要求。
