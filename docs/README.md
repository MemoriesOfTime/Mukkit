# 文档索引

Mukkit 的文档按用途分三组，一组一个目录：

| 目录 | 回答的问题 | 读者 |
|---|---|---|
| [`design/`](design/) | 它是什么、为什么这样设计、两端怎么约定 | 要改桥或 fork 的人 |
| [`operations/`](operations/) | 怎么构建、怎么起服、怎么验收、装哪些插件 | 要运行或发布的人 |
| [`project/`](project/) | 现在做到哪、下一步做什么、代码怎么写 | 参与项目的人 |

## 建议阅读顺序

1. 根目录 [`README.md`](../README.md)：定位、能力与状态、安装与使用。
2. [`design/architecture.md`](design/architecture.md)：进程边界、tick 模型、安装形态。
3. [`design/graft-map.md`](design/graft-map.md)：每个 PM 能力从哪来、桥搬什么、什么时候拒绝。
4. [`design/protocol.md`](design/protocol.md)：线格式、帧、op、字段表。
5. [`project/spec/`](project/spec/)：动手前先看红线与评审清单。

## design/：设计与约定

| 文档 | 内容 |
|---|---|
| [`architecture.md`](design/architecture.md) | 唯一权威原则、进程边界、一轮 tick 的生命周期、启动顺序、超时与监督 |
| [`graft-map.md`](design/graft-map.md) | 嫁接映射：哪些能力白送、哪些要搬、哪些加载期拒绝 |
| [`events.md`](design/events.md) | 事件面：19 个 wire 名、决定型与通知型、加事件的判据与代价 |
| [`protocol.md`](design/protocol.md) | 桥接协议：传输、握手、tick 帧、全部 op 与字段 |
| [`pm-fork.md`](design/pm-fork.md) | PocketMine fork：切口、垫片、两档 AsyncPool、patch 基线 |
| [`adr/`](design/adr/) | 架构决策记录，一决策一文件，只增不改 |

## operations/：运行与验收

| 文档 | 内容 |
|---|---|
| [`setup.md`](operations/setup.md) | 干净 clone 的一次性准备：外部源码、Gradle、Composer、PHP 运行时 |
| [`distribution.md`](operations/distribution.md) | 发行形态：四平台运行时、解包顺序、打包、许可 |
| [`plugins.md`](operations/plugins.md) | 真实插件清单：装了什么、哪些装不上、为什么 |
| [`docker.md`](operations/docker.md) | Docker 测试服：真实插件服与探针服、控制台、网络、挂载 |
| [`smoke.md`](operations/smoke.md) | L4 真机测试：12 条 + 事件/容器/方块实体追加项、当前结果 |

## project/：项目治理

| 文档 | 内容 |
|---|---|
| [`roadmap.md`](project/roadmap.md) | 当前目标、下一步、不做清单 |
| [`spec/`](project/spec/) | 项目规范：红线、模块边界、代码规范、验证、流程 |

## 维护规则

- **一个事实只有一个出处。** 能力清单在 `design/graft-map.md`，事件在 `design/events.md`，
  协议字段在 `design/protocol.md`，真机结果在 `operations/smoke.md`。其他地方只链接，不复制表格。
- **`project/roadmap.md` 只写当前与下一步。** 不记录已完成阶段、修复历史或版本迁移。
- **新增文档要登记在本文件**，并说明它回答什么问题。
- **正文里的路径引用写仓库根相对路径**（如 `docs/design/protocol.md`），避免文件移动后出现
  错误的相对路径；可点击的 Markdown 链接则按所在文件写相对路径。
