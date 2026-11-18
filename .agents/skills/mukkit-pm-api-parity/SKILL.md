---
name: mukkit-pm-api-parity
description: "在 Mukkit 仓库里验证「PM 侧调用 PocketMine 原生 API → 桥转换成 op → Nukkit 侧读到正确结果」这条链路。适用于新增或修改 PM API 转换判据、编写 examples/pm-*-probe 探针、pm-host/tests/pm-*-test.php 宿主判据、mukkit-nukkit 的 *Check.java、scripts/run-*.sh 验收脚本，或排查「PM 调了但 Nukkit 没反应」的转换问题。"
description_zh: "验证 PM 调原生 API、Nukkit 读结果确认转换正确"
description_en: "Verify PM native API calls convert correctly into Nukkit state"
version: 1.0.0
allowed-tools:
  - Read
  - Write
  - Edit
  - Bash
  - Grep
  - Glob
---

# Mukkit PM API 转换验证

## 这份 skill 解决什么

给一项「PM 插件用普通 PocketMine API 做了某事」的能力建立判据，并证明它在 Nukkit 侧**真的发生了**。

验证对象不是「函数被调到」，而是**转换本身**：PM 的一次调用 → 线上一个 op / packet → Nukkit 的一个对象或行为。三段里任何一段没接上，都不算通过。

不适用范围：纯 JVM 内部逻辑（用普通单元测试）、纯协议编解码（`mukkit-bridge` 的往返测试）、真客户端画面验收（`docs/operations/smoke.md`）。

## 先判断：新建还是复用

| 现状 | 做法 |
|---|---|
| 只是要跑已有判据 | 直接跑 `docs/project/spec/testing.md` 命令表里对应的 `scripts/run-*.sh`，不要新建 |
| 能力已有探针，只缺一条断言 | 在既有 `pm-<cap>-test.php` / `*Check.java` 里加，不新开一套 |
| 新能力，或原判据换了转换路径 | 按下面四段新建 |

现成范例（照抄结构最快）：`examples/pm-state-probe/` + `pm-host/tests/pm-player-state-test.php` + `PlayerStateCheck.java` + `scripts/run-player-state.sh`。

## 第一条规矩：先写三列对齐表

动手写代码前，把这张表贴进测试文件头注释或 PR 描述。缺一列就说明还没想清要验什么。

| PM 侧（普通 API） | 线上（op + 字段） | Nukkit 侧（怎么读 + 期望） |
|---|---|---|
| `$player->setHealth(7)` | `player.state` → `s.health = 7.0` | `NukkitGateway` 应用到 `Player::setHealth`；离线 `PlayerStateCheck` 断言 7.0 |
| `$world->setBlockAt()` | `block` → `b[] = {x,y,z,id,meta}` | `NukkitGateway::applyBlock`；真服务端日志/查询回读 |
| `$player->sendTitle()` | `packet` → `cls=SetTitle`，标题四变体字段 | `PacketMapper` 映射成 Nukkit 包；离线编码校验 + 真机另记 |

三条硬要求：

1. **PM 侧只用 `pocketmine\*`。** 探针里出现任何 bridge 专用类，这条判据就作废——它证明不了普通插件能用。
2. **线上列必须是 op 名 + 具体字段**，不许写「一些数据」。
3. **Nukkit 列必须写清读法**：是离线读 fixture，还是真服务端读日志/查询。两者证明力不同，见下节。

## 选验证模式：离线还是真服务端

| 模式 | 能证明 | 不能证明 | 用在哪 |
|---|---|---|---|
| **A. 离线 JVM 读 fixture**（`*Check.java`） | op 的字段被正确映射/打包/容错；diff、epsilon、枚举映射这类纯函数 | Nukkit 的真对象真被改了 | 大多数字段级判据；`PlayerStateCheck`、`TilePackingCheck`、`PacketMapperCheck` |
| **B. 真 Nukkit 起服务端读日志** | 真服务端里对象状态变了、包真投递了 | 客户端画得对 | 需要「Nukkit 实际执行」的判据；`scripts/run-product.sh` |
| **C. 真客户端** | 画面正确 | — | 只有 `docs/operations/smoke.md` 的条目算数 |

默认 A + B 组合：A 钉字段，B 钉落地。**不要用 A 冒充 B，更不要用 `encode()` 通过冒充 C。** 这是仓库里最贵的假绿。

## 实施四段

`assets/` 下的文件是模板：先把 `xxx` / `Xxx` 替换成能力名（如 `state` / `State`），再拷到对应路径。

### 第 1 段：PM 侧探针（`examples/pm-<capability>-probe/`）

结构固定：`plugin.yml` + `src/mukkit/<capability>probe/Main.php`。

- 每个被测行为注册一个命令，命令里调用**普通 API**，不做断言——探针只负责「做事」和「报告」。
- 必须有一个 `report` 类命令，把插件视角的状态打成一行 `key=value`。反向验证（Nukkit 改的 PM 读得到）全靠它。
- 命令权限：管理类用 `default: op`（真实插件就这么写）；不想配 OP 就用 `DefaultPermissions::ROOT_USER`，并在注释里说明原因。
- 所有输出带固定前缀（如 `st-report x=`），断言时按 payload 匹配，不按行首匹配——控制台会加 `Command output |` 前缀。

打包：`bash scripts/build-plugin-phar.sh examples/pm-<capability>-probe <out.phar>`。判据必须用打包后的 phar，不用源码目录。

模板：`assets/probe-plugin/`。

### 第 2 段：宿主侧判据（`pm-host/tests/pm-<capability>-test.php`）

这是「读结果」的第一种读法：在宿主进程里读 PM 产出的 op——也就是**即将交给 Nukkit 的东西**。用真 `PmProfile` + 真 fork + 真 phar，不 mock。

```php
$profile = new PmProfile($pmRoot, $data, $plugins);
$result  = $profile->load([['name' => 'MukkitXxxProbe', 'path' => $phar]]);

// 一次命令 = 一个 tick，返回该 tick 的 op 列表
$ops = $profile->tick([['k' => 'command', 'sender' => 'console', 'line' => 'xx-do 7']]);
```

断言必须覆盖四个方向，缺哪个就会出现「单向绿」：

1. **出站**：调用产生了预期 op 和字段，且**不夹带无关字段**（改血量不该带坐标）。
2. **不回声**：紧接着 `$profile->tick([])`，同一状态不得再报一次。
3. **入站**：`['k' => 'event', ...]` 或 `['k' => 'player.state', 'player' => $uuid, 's' => [...]]` 推进来后，`report` 命令能读到新值。
4. **异常输入**：越界值/未知枚举「只报不应用」，不是静默吞掉，也不是猜一个默认值。

最后一行固定输出 `<capability> PASS`，汇总脚本靠这行判绿。每条断言用 `ok    ` / `FAIL  ` 前缀，标签写英文短句，说明写中文。

模板：`assets/host-test.php`。

### 第 3 段：再让 Nukkit 读一遍

**A. 离线（默认先做）**

- 把第 2 段的 ops 落成 fixture（`json_encode`），或由 JVM 侧生成 fixture 给 PHP 读。
- `*Check.java` 放在 `mukkit-nukkit/src/test/java/cn/mukkit/nukkit/`，`main()` 里跑纯函数断言，失败 `System.exit(1)`，通过打印 `<capability>-jvm PASS`。
- 只有真能离线判的东西才放这里：字段映射、打包顺序、epsilon、未知值容错。需要真 `Player`/`World` 的别硬拗。

**B. 真服务端**

- 起 `run/` 下的数据目录 + `Mukkit.jar` + phar，从服务端日志/控制台命令读 Nukkit 侧结果。
- 断言写在 `scripts/run-*.sh` 的 `require_line` 里，日志先 `tee` 再 grep。
- 注意日志级别：`[packet]` 逐条输出是 debug 级，默认看不到，别把「日志没刷」当成「包没发」。

模板：`assets/Check.java.tmpl`、`assets/run-acceptance.sh`。

### 第 4 段：一条验收脚本（`scripts/run-<capability>.sh`）

顺序固定，任一步失败立即非 0 退出：

1. 检查 fork：`$PM_ROOT/src/mukkit/polyfill.php` 与 `vendor/autoload.php` 都在。
2. 每次全新数据目录（`run/<capability>/`），删掉旧 phar 与插件数据，避免测到上一版。
3. `php pm-host/tests/pm-<capability>-test.php | tee host.log`
4. `mvn -o -q -pl mukkit-nukkit -am test-compile`
5. `java -cp "$NK_JAR:mukkit-nukkit/target/classes:mukkit-nukkit/target/test-classes:mukkit-bridge/target/classes" cn.mukkit.nukkit.XxxCheck > jvm.log`
6. `require_line` 逐条汇总，最后打印 `<capability> PASS`。

`set -uo pipefail`，不要 `-e`（脚本里要自己收错误码再决定退出）。

## 常见假绿（按踩坑频率排序）

1. **探针用了 bridge 专用 API。** 绿了也只证明桥自己能用，不证明插件能用。
2. **只测出站。** 不测入站和「不回声」，镜像会来回弹，线上表现为位置抖动/血量跳变。
3. **用 mock 代替 PHP 宿主。** 桥的价值就在跨进程，mock 掉等于没测。
4. **`encode()` 通过当客户端验收。** 只说明交给 Nukkit 的包对，不说明客户端画得对。
5. **fixture 是上一轮的。** 没删 `run/<cap>/` 或插件 data，测的是历史产物。
6. **断言匹配行首。** 控制台有 `Command output |` 前缀和 PM 自己的格式化，用 `strpos` 找 payload。
7. **阈值调大让测试过。** p99 < 5ms 是红线，超了就是回归。
8. **`null` 当 `false`。** PHP 里 `($x['k'] ?? null) === false` 与缺字段不是一回事，断言写全等。
9. **静默降级当通过。** 未知枚举/越界值必须有一条「被拒绝且记录」的断言。
10. **新 op 只改一端。** 跨进程 op 必须 `cn.mukkit.bridge.Protocol` 与 `Mukkit\Host\Protocol` 同 PR 改，并更新 `docs/design/protocol.md`。

## 交付时说什么

- **层级**：L0 编解码 / L1 双进程 / L3 真 fork / L4 真客户端，明确写到哪一层。
- **命令和结果**：跑的是哪条 `scripts/run-*.sh`，几条断言，关键数字（p50/p99、字段值）。
- **未验**：没做的真机项按 `docs/project/spec/testing.md` 的「未验债」登记到 `docs/project/roadmap.md` 或对应设计文档，不要写成「可用」。

文档同步：新 op → `docs/design/protocol.md`；新能力 → `docs/project/roadmap.md`（计划）或对应设计文档；客户端项 → `docs/operations/smoke.md`；判据命令 → `docs/project/spec/testing.md` 的命令表。

## 参考

- `references/harness.md`：`PmProfile` 接口、inbound 帧形态、op 字段表、断言 helper。
- `references/nk-side.md`：离线 Check 与真服务端两条读法的细节、classpath、日志断言。
- 现成范例：`pm-host/tests/pm-player-state-test.php` + `PlayerStateCheck.java` + `scripts/run-player-state.sh` + `examples/pm-state-probe/`。
