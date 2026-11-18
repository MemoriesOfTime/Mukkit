# 原则与红线

## 第一原则：嫁接，不重写

PocketMine 的插件 API 面是数千个类。我们的全部优势来自"直接复用它的实现"。
一旦开始手写 `pocketmine\*` 的同名类，就不再是嫁接，而是分叉一个注定落后的 PM。

判断一段代码是"桥"还是"垫片"，问三个问题：

1. **PM 里已经有这个实现了吗？** 有 —— 那你就不能写第二份。
2. **如果 PM 明天改了那个类，这段代码会坏吗？** 会 —— 那它是垫片，不是桥。
3. **删掉这行，插件还能跑吗？** 能 —— 那这行是多余的。

## 禁止清单

| # | 禁止 | 为什么 | 正确做法 |
|---|---|---|---|
| 1 | 在 Java 或 `pm-host` 里声明 `pocketmine\*` 同名类 | 分叉出第二套 API，语义漂移且测不出来 | 改 fork：放宽可见性、加钩子 |
| 2 | 在 `pm-host/src/stubs/` 放"只为编译通过"的 PM 类 | 同上，且更隐蔽 | 同上 |
| 3 | JNI / FFI / 内嵌 PHP 运行时 | 崩溃域合并、许可边界污染、跨语言调试 | Unix domain socket |
| 4 | 在 tick 内同步回调 JVM 取返回值 | 重入死锁 | 出站动作入队，tick 末批量回传 |
| 5 | 让 PM 侧 tick 世界 | 两个模拟器永远对不齐 | 世界归 Nukkit，PM 只读镜像 |
| 6 | 改 Nukkit 的包结构去迁就 PM | 反向耦合，Nukkit 升级就断 | 在映射层适配 |
| 7 | 反射穿透 PM 私有成员（`@Internal` 之外） | 改 fork 就断，且没有编译期保护 | 在 fork 里改成 `protected` |
| 8 | 引入 pmmp 自研 C 扩展 | 用户装不上，等于要求用户自己编译 | 纯 PHP 替代或直接去掉 |
| 9 | 让未知 op / 未知帧中断 tick | 版本一错就整服停摆 | 记 warn 跳过 |

第 1、2 条是本项目的立身之本。它们不是"不推荐"，是"不接受 PR"。

## 依赖方向

```
pm-fork (PHP, 外部)  ──┐
                       ├─►  socket  ─►  mukkit-bridge  ◄──  mukkit-nukkit  ◄──  Nukkit
pm-host (PHP, 本仓)  ──┘                     ▲
                                             └──  mukkit-spike
```

- `mukkit-bridge` **不认识** Nukkit，也不认识 PM
- `mukkit-nukkit` **不认识** PM，只知道桥的协议
- `pm-host` **不依赖** 任何 Java 代码，只依赖协议

任何一条反向箭头都是设计事故。

## 漂移检测

`scripts/check-architecture.sh` 把可机检的部分固定下来：

- Java 侧出现 `package pocketmine` 或 `cn.mukkit.pmapi` → 失败
- `pm-host` 出现 `namespace pocketmine` → 失败
- `mukkit-bridge` 的 `build.gradle.kts` 出现 `cn.nukkit` → 失败
- `pm-host` 里 `require` PM 的 `vendor/autoload.php` → 失败
- 出现 `System.loadLibrary` / JNA / JFFI → 失败

机检覆盖不到的（比如"这算桥还是算垫片"），靠评审时那三个问题。

## 什么时候可以破例

只能通过 ADR。写清楚：破了哪条、为什么、代价是什么、什么时候还债。
没有 ADR 的破例一律回退。
