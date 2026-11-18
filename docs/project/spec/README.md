# 项目规范

这套文档约束"怎么写"，不解释"为什么这么设计"。设计说明在
[`docs/design/`](../../design/)，路线与规范在 [`docs/project/`](../)。

## 三条铁律

1. **PM 只能被宿主，不能被重写。** 任何 `pocketmine\*` 的实现都必须来自 fork 本身。
   桥接层写第二份 PM API，就是把项目变成一个注定漂移的分叉。
2. **世界只有一个权威。** Nukkit 独占方块、实体、玩家、tick。PM 侧世界永远是镜像。
3. **边界只在协议里。** 两个进程之间只有帧通信。不共享内存、不 JNI、不互相 import。

## 规范索引

| 文档 | 内容 |
|---|---|
| [`principles.md`](principles.md) | 红线清单、禁止事项、漂移检测 |
| [`modules.md`](modules.md) | 模块边界、依赖方向、命名、新增模块的判据 |
| [`coding.md`](coding.md) | Java 与 PHP 的代码规范 |
| [`testing.md`](testing.md) | 验证分层、验收阈值、故障注入矩阵 |
| [`process.md`](process.md) | 分支、提交、版本、协议变更流程、评审清单 |

设计、操作与项目文档的完整索引见 [`docs/README.md`](../../README.md)。

## 违反了怎么办

先改代码，再补 ADR。不允许"先合进去再说"——破例的成本比绕路高。
