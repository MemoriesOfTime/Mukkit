# 代码规范

## Java

### 版本与依赖

- 源码与字节码 `--release 17`（Nukkit 侧要求 JRE 17+，不要提高）
- `mukkit-bridge` 零第三方运行时依赖。要加先写 ADR
- `mukkit-spike` 可以有构建期依赖，但不进生产部署

### 线程模型

只有两个线程，别加第三个：

| 线程 | 允许做什么 | 禁止做什么 |
|---|---|---|
| tick 线程（调用 `TickDriver.run` 的那个） | 应用 op、构造事件、写 `Gateway` 状态 | 阻塞 IO、等锁、调 PM |
| 监督线程（未来） | 启停子进程、重建连接 | 碰 `Gateway` 状态 |

`TickDriver` 是单线程驱动，不做并发。要并行就在 PM 侧用 PM 自己的机制，
不要把并发引到桥上。

### 消息

跨模块一律 `Map<String, Object>` / `List<Object>` / 基本类型。键名常量集中在 `Protocol`，
不要散落在调用点。

读 payload 一律走容错写法：

```java
private static String stringOr(Map<String, Object> map, String key, String fallback) {
    Object value = map.get(key);
    return value instanceof String text ? text : fallback;
}
```

不信任对面发来的任何字段。未知字段忽略，未知类型降级，绝不 NPE 冒到 tick 线程。

### 异常

- 插件抛异常 ≠ 宿主死亡 ≠ 桥出错，三者分开处理，不要合并成一类
- 自定义异常带足够定位的信息（`HostHungException` 带 seq 和等待时长）
- 异常消息不带版本号前缀，也不用中英双语——这一点沿用 Nukkit-MOT 的既有风格

### 注释

只解释"代码看不出来的东西"：为什么这里必须同步、为什么这个超时是 15ms、
为什么不能用另一种写法。不写：

- 版本迁移叙事（"v2 起改成…"）
- 复述代码的废话
- 中英对照

## PHP

### 版本与语法

- 下限 PHP 8.1，上限跟随 PM fork 支持的版本
- 每个文件 `declare(strict_types=1);`
- 只用命名空间 + 自动加载，不手写 `require`（`bootstrap.php` 除外）
- 类型声明写全：参数、返回值、属性都用原生类型，不用 docblock 代替

### 允许的扩展

无头宿主的扩展白名单（`Capability::HEADLESS_REQUIRED`）：
`json`、`pcre`、`spl`、`reflection`、`mbstring`、`phar`、`sockets`、`ctype`。

要用白名单之外的扩展，必须同时满足：能装、能降级、写进 `Capability` 报告。
pmmp 自研的 C 扩展一律不允许（红线第 8 条）。

### 禁止

```php
// 禁止：把 PM 的运行时拉进宿主
require __DIR__ . '/vendor/pocketmine/pocketmine-mp/vendor/autoload.php';

// 禁止：为了让代码编译通过而补 PM 的类
namespace pocketmine\player;
final class Player {}
```

`pm-host` 只认识自己的 `Mukkit\Host\*`。要跑 PM，就走 `PmProfile`，
由 fork 自己完成加载。

### 错误处理

- 插件异常在 `handleTick` 内捕获，写进 tick 帧的 `error`，tick 照常落地
- 结构性错误（缺扩展、协议不匹配、socket 起不来）在启动期直接失败，不要拖到运行中
- 日志一行一条，不要多行堆栈直接写进 op

## 两侧共同的约定

- **每个 op 和每行日志都带 tick 号。** 跨进程排查没有 tick 号等于猜。
- **错误信息说人话。** 面向用户的消息（`rejected.reason`）要能让人知道下一步做什么，
  比如 `requires PM world simulation (capability: world.simulate)`。
- **不做静默降级。** 降级必须写日志、上报、或在加载期拒绝。
