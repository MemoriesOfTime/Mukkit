# Nukkit 侧读结果

两种读法，证明力不同，通常组合使用。

## A. 离线 Check（读 fixture / 读 op）

### 位置与命名

- `mukkit-nukkit/src/test/java/cn/mukkit/nukkit/<Capability>Check.java`
- 与 `<Capability>Mirror` / `<Capability>Codec` 同包，能直接调包内静态方法（`diff`、`gameModeOf`、`pack` 等）。
- 不启动服务端，`main()` 跑断言，失败 `System.exit(1)`。

### 骨架

```java
public final class XxxCheck {
    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        check("a known value maps both ways", XxxMirror.map("a") == 1 && "a".equals(XxxMirror.name(1)));
        check("an unknown value is refused rather than defaulted", XxxMirror.map("nope") == -1);
        // ... 读 fixture：Files.readString(Path.of(args[0]))
        System.out.printf("xxx-jvm %d checks, %d failures%n", checks, failures);
        if (failures > 0) {
            System.exit(1);
        }
        System.out.println("xxx-jvm PASS");
    }

    private static void check(String label, boolean ok) {
        checks++;
        if (ok) {
            System.out.println("ok    " + label);
            return;
        }
        failures++;
        System.out.println("FAIL  " + label);
    }
}
```

失败要带「实际值是什么」，否则线上排查只能靠猜：

```java
check("health is the new value", number(state.get("health")) == 7.0, "actual=" + state.get("health"));
```

### 该放什么、不该放什么

放：字段映射、整数/枚举转换、打包顺序、浮点 epsilon、未知值容错、空值不 NPE。

不放：需要真 `Player`、真 `World`、真 `Inventory` 才能观察的行为——那些要么在 A 里改成纯函数入参，要么归 B。

### fixture 交换

- JVM 生成给 PHP：`java ... ChunkSnapshotCheck run/m5/chunk.json`，PHP 侧读同一份字节做解码比对。
- PHP 生成给 JVM：`file_put_contents($fixture, json_encode($ops, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))`，`main(args)` 里读它。
- fixture 放 `run/<capability>/`，每次重建；不要提交进 git。

### 运行

```bash
mvn -o -q -pl mukkit-nukkit -am test-compile

NK_JAR="$(ls "$HOME/.m2/repository/cn/nukkit/Nukkit/MOT-SNAPSHOT/Nukkit-MOT-SNAPSHOT.jar" 2>/dev/null | head -1)"
CP="$NK_JAR:mukkit-nukkit/target/classes:mukkit-nukkit/target/test-classes:mukkit-bridge/target/classes"
java -cp "$CP" cn.mukkit.nukkit.XxxCheck
```

`NK_JAR` 找不到时提示先跑 `scripts/install-nukkit-mot.sh`，不要静默跳过——跳过等于假绿。

## B. 真 Nukkit 服务端（读日志 / 查询）

用在「必须证明 Nukkit 真对象被改了」的判据。参考 `scripts/run-product.sh`、`scripts/run-m5.sh`。

### 结构

1. 每次新数据目录：`run/<capability>/<timestamp>-$$/`，`server.properties` 里 `server-port=0`（随机端口）、`level-type=flat`、`xbox-auth=off`。
2. 拷 `mukkit-nukkit/target/Mukkit.jar` 和探针 phar 进 `plugins/`。
3. 后台起服务端，stdout/stderr 进 `server.log`，`tee` 一份给断言。
4. 等「宿主已连接」「插件 enabled」这类锚点行出现，再发命令；不要用固定 `sleep` 赌启动时间。
5. 断言 Nukkit 侧证据：方块查询回读、`mukkit status` 输出、包投递日志、TPS 行。
6. 收尾 `stop`，等进程退出，检查退出码。

### 日志断言

```bash
fail=0
require_line() {
    if grep -qF -- "$1" "$2"; then
        printf 'ok    %s\n' "$3"
    else
        printf 'FAIL  %s\n' "$3"
        fail=1
    fi
}
require_line 'Loading Mukkit v' "$LOG" 'jar 被服务端加载'
require_line '宿主已连接' "$LOG" 'PM 宿主起来'
```

- `grep -qF`：日志里有 `[`、`(` 等字符，别用正则。
- 断言整句锚点，不 grep 单词（`enabled` 会命中一堆无关行）。
- `[packet] ...` 逐条日志是 **debug 级**，默认不输出。要开就把 `nukkit-mot.yml` 的 `debug-settings.debug-level` 设成 2，或用 `mukkit status` 的计数。
- PM 的控制台输出带 `Command output |` 前缀，匹配 payload。

### 真服务端的边界

- 没有客户端时 `recipients=0` 是正常的：只能证明包交给了 Nukkit，不能证明客户端收到/画对。
- 广播可能被宿主与 Nukkit 各投一次；判据要先确认「投递权」归属，否则会数出两遍。
- 真机项（进服看标题/表单/侧边栏、换客户端版本）归 `docs/operations/smoke.md`，不在脚本里假装。

## 判定通过的最低配置

| 能力类型 | 最低判据 |
|---|---|
| 状态类（血量/模式/坐标/经验） | 宿主出站字段 + 不回声 + 入站读回 + 离线映射 |
| 世界写类（方块/时间） | 宿主 op 字段 + 离线打包 + 真服务端回读 |
| 发包类（标题/表单/音效） | 宿主 op 字段 + Nukkit `encode()` + 真机条目登记为未验 |
| 容器类 | 宿主 op + 客户端事务判决 + 真服务端窗口绑定 |
