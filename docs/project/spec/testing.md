# 验证规范

## 分层

| 层 | 范围 | 怎么跑 | 判据 |
|---|---|---|---|
| L0 | 编解码、帧状态机 | 单元测试（无进程） | 往返一致、畸形帧不崩 |
| L1 | 两进程 tick 往返 | `mukkit-spike --profile=stub` | 0 超时，p99 < 5ms |
| L2 | 故障注入 | `--fault=error\|hang\|crash` | 三档行为与退出码符合下表 |
| L3 | 真 PM fork 存活 | `--profile=pm` + 1000 tick | 不崩、不泄漏、`Server::getInstance()` 全程可用 |
| L4 | 真客户端 | `scripts/serve.sh` + `docs/operations/smoke.md` 的 12 条 | 表单/计分板/聊天/背包/区块在客户端上正确，换客户端版本仍正确 |
| L5 | 长跑与背压 | 2 小时 + 20 人 + 跑图 | RSS 有上限、p99 tick < 5ms、宿主重启后状态自愈 |

L3 是 PM 宿主是否可用的停止判据：过不了就不做玩家代理与世界镜像。

L4 是所有"发包"类能力的停止判据：`encode()` 通过只说明交给 Nukkit 的包是对的，
不说明客户端画得对。**拿 L1–L3 的绿替代 L4，等于把未验说成已验。**

## 未验债

能力只能标"桥内通过"或"真机通过"，两者不是一回事。真机没做的，必须在
[`roadmap.md`](../roadmap.md) 或 [`smoke.md`](../../operations/smoke.md) 里点明"未验 + 缺什么条件"，
不许只在提交信息里提一句。

条目一旦登记，修完之前不能在同一份文档里被写成"可用"。`docs/operations/smoke.md` 的结果表是销账凭据。

## 故障注入矩阵

| 参数 | 注入点 | 期望宿主行为 | 期望桥行为 | 退出码 |
|---|---|---|---|---|
| `--fault=error:N` | 第 N tick 抛异常 | 捕获，写 `error` 字段，继续跑 | tick 照常应用，日志可见 | 0 |
| `--fault=hang:N` | 第 N tick 睡 10s | 无响应 | 软超时计数 → 硬超时放弃 → 抛 `HostHungException` | 2 |
| `--fault=crash:N` | 第 N tick `exit(3)` | 进程消失 | EOF 检出，判定崩溃，JVM 存活 | 3 |

新增故障类型必须同时更新这张表和 `Host::injectFault()`。

## 阈值

| 指标 | 阈值 | 现状 |
|---|---|---|
| 单 tick 往返 p99 | < 5ms | 0.47ms（1000 tick 无节流） |
| 软超时次数 | 0（20 TPS 下 200 tick） | 0 |
| 传输开销占 tick 预算 | < 1% | 0.16% |
| 1000 tick 后内存增长 | < 20% | 未测 |
| 20 人跑图时服务端 tick p99（L5） | < 5ms | 未测 |
| 宿主 RSS 上限（L5） | 与镜像区块数线性、可配上限 | 未测 |

阈值不是目标，是红线。超过就是回归，不是"偶发波动"。

## 命令

```bash
./gradlew bootstrap                           # 干净 clone 的外部依赖 + 构建
./gradlew build                               # 日常构建
./gradlew checkArchitecture                   # 红线机检
./gradlew runSpike                            # 构建 + 探测 + 200 tick
./gradlew runM1                               # Nukkit + PHP + phar 端到端
./gradlew runProduct                          # 真 Nukkit + jar + EconomyAPI 产品路径
./gradlew serve                               # 起可连服务端（L4/L5 用，前台，Ctrl-C 退）

scripts/serve.sh                              # 同上，直接调脚本
scripts/run-spike.sh                          # 构建 + 探测 + 200 tick
scripts/run-player-commands.sh                # 玩家命令：分流 + 以玩家身份执行（需要 fork）
scripts/run-ops.sh                            # 权限/OP：default: op 节点 + 双向 op（需要 fork）
scripts/run-kick.sh                           # 踢人：断连 op + QuitEvent 只有一次（需要 fork）
scripts/run-containers.sh                     # 容器窗口 + 客户端事务判决 + 死亡通知（需要 fork）
scripts/run-tiles.sh                          # 方块实体：getTile() 可读可写、窗口绑定真实库存（需要 fork）
scripts/check-architecture.sh                 # 红线机检
java -jar mukkit-spike/build/libs/mukkit-spike-*.jar --ticks=1000 --turbo
java -jar mukkit-spike/build/libs/mukkit-spike-*.jar --ticks=80 --fault=hang:20
```

## 回归要求

改了什么必须跑什么，不跑不算完成：

| 改动 | 必须跑 |
|---|---|
| `mukkit-bridge` 任意文件 | L0 + L1 + L2 |
| 协议字段/类型 | L1 双端 + L2，并更新 `docs/design/protocol.md` |
| `pm-host` 宿主循环 | L1 + L2 |
| `pm-fork` 切口 | L3 |

## 不允许的测试

- 不允许用 mock 替换掉 PHP 宿主进程来"加速" L1。桥的价值就在于跨进程，
  mock 掉就等于没测。
- 不允许把超时阈值调大来让测试通过。
- 不允许只跑十几秒、看到插件 enable 就宣称通过：安装形态的判据是 `onEnable → 事件 → 发包 → onDisable`
  四步都落地（`scripts/run-m1.sh` 的断言就是这四步）。
- 不允许用 `encode()` 通过、日志里出现包名、或客户端进程"启动成功"代替 L4。真机判据只有
  `docs/operations/smoke.md` 的 12 条 + 容器 5 条 + 方块实体 5 条，缺一条就是缺一条。
