#!/usr/bin/env bash
#
# 红线机检。覆盖 docs/project/spec/principles.md 里能自动判定的部分。
# 人工判断（"这算桥还是算垫片"）不在范围内。
set -uo pipefail

cd "$(dirname "$0")/.."

fail=0

report() {
    local status="$1"
    local label="$2"
    local hits="${3:-}"
    if [ "$status" = "fail" ]; then
        printf 'FAIL  %s\n' "$label"
        printf '%s\n' "$hits" | sed 's/^/        /'
        fail=1
    else
        printf 'ok    %s\n' "$label"
    fi
}

check_tree() {
    local label="$1" pattern="$2" dir="$3" include="$4" hits
    hits=$(grep -rIn --include="$include" -E "$pattern" "$dir" \
        --exclude-dir=build --exclude-dir=.gradle --exclude-dir=run --exclude-dir=scripts 2>/dev/null || true)
    if [ -n "$hits" ]; then report fail "$label" "$hits"; else report ok "$label"; fi
}

check_file() {
    local label="$1" pattern="$2" file="$3" hits
    hits=$(grep -In -E "$pattern" "$file" 2>/dev/null || true)
    if [ -n "$hits" ]; then report fail "$label" "$hits"; else report ok "$label"; fi
}

check_tree "Java 侧没有 pocketmine / pmapi 包声明" \
    '^[[:space:]]*package[[:space:]]+(pocketmine|cn\.mukkit\.pmapi)(\.|;)' . '*.java'

check_tree "pm-host 没有 pocketmine 命名空间" \
    '^[[:space:]]*namespace[[:space:]]+pocketmine' pm-host '*.php'

# 生产路径（pm-host/src、pm-host/bin 的其余脚本）不许拉 PM 的 autoloader：宿主跑的是自己的
# profile，fork 只能通过 PmRuntime 显式接入。两个例外都是"接入点"本身：
#   pm-host/bin/pm-boot.php   —— 无头 boot 判据探针，只做 boot + tick
#   pm-host/src/PmRuntime.php —— pm profile 的运行时，负责把桥的帧翻成 PM 调用
# 要放宽这条，先改 docs/design/pm-fork.md。
autoload_hits=$(grep -rIn --include='*.php' -E 'vendor/autoload\.php' pm-host \
    --exclude-dir=build --exclude-dir=.gradle --exclude-dir=run --exclude-dir=tests \
    --exclude=pm-boot.php --exclude=PmRuntime.php 2>/dev/null || true)
if [ -n "$autoload_hits" ]; then
    report fail "pm-host 生产路径没有拉入 PM 的 autoloader" "$autoload_hits"
else
    report ok "pm-host 生产路径没有拉入 PM 的 autoloader"
fi

check_tree "没有 JNI / FFI 内嵌" \
    'System\.loadLibrary|com\.sun\.jna|org\.jffi' . '*.java'

check_file "bridge 不依赖 Nukkit" 'cn\.nukkit' mukkit-bridge/build.gradle.kts
check_file "spike 不依赖 Nukkit" 'cn\.nukkit' mukkit-spike/build.gradle.kts

check_tree "bridge 源码没有 Nukkit 引用" 'cn\.nukkit' mukkit-bridge '*.java'
check_tree "spike 源码没有 Nukkit 引用" 'cn\.nukkit' mukkit-spike '*.java'

# 产品是一个装进 plugins/ 的 jar，不是改过的服务端：任何 cn.nukkit 包声明都意味着有人开始
# 往 Nukkit 里贴代码，而不是适配它。
stray=$(grep -rIl --include='*.java' -E '^[[:space:]]*package[[:space:]]+cn\.nukkit(\.|;)' . \
    --exclude-dir=build --exclude-dir=.gradle --exclude-dir=run 2>/dev/null || true)
if [ -n "$stray" ]; then
    report fail "没有任何 cn.nukkit 包声明" "$stray"
else
    report ok "没有任何 cn.nukkit 包声明"
fi

# 打出来的插件 jar 里不能混进 Nukkit 的类：那等于把服务端装进插件
PLUGIN_JAR=mukkit-nukkit/build/libs/Mukkit.jar
if [ -f "$PLUGIN_JAR" ]; then
    # 一次性取到变量里再匹配。`unzip -l | grep -q` 在 pipefail 下会因 grep 提前退出、
    # unzip 收到 SIGPIPE 而返回 141，被判成"条目缺失"——jar 越大越容易发作。
    jar_listing="$(unzip -l "$PLUGIN_JAR" 2>/dev/null || true)"
    # 纯 shell 匹配，不走管道：`printf | grep -q` 在 pipefail 下同样会因为 SIGPIPE 失败。
    case "$jar_listing" in
        *" cn/nukkit/"*) report fail "插件 jar 不含 Nukkit 类" "found cn/nukkit entries" ;;
        *) report ok "插件 jar 不含 Nukkit 类" ;;
    esac

    has_entry() {
        case "$jar_listing" in
            *" $1"*) return 0 ;;
            *) return 1 ;;
        esac
    }

    misses=""
    for entry in plugin.yml config.yml pm-host/bin/mukkit-host.php pm-host/bin/pm-inspect.php pm-host/src/Host.php; do
        has_entry "$entry" || misses="$misses $entry"
    done
    if [ -n "$misses" ]; then report fail "插件 jar 自带描述符与 PHP 宿主" "missing:$misses"; else report ok "插件 jar 自带描述符与 PHP 宿主"; fi

    # fork 的源码必须真的在 jar 里，否则 pm profile 会在用户机器上找不到解释器
    fork_missing=""
    for entry in pocketmine/src/mukkit/polyfill.php pocketmine/vendor/autoload.php pocketmine/LICENSE; do
        has_entry "$entry" || fork_missing="$fork_missing $entry"
    done
    if [ -n "$fork_missing" ]; then report fail "插件 jar 自带 PocketMine 源码" "missing:$fork_missing"; else report ok "插件 jar 自带 PocketMine 源码"; fi
else
    report ok "插件 jar 未构建（跳过打包内容检查）"
fi

# pm-host 只能通过协议说话：出现 cn.nukkit 或 pocketmine 之外的 Java 依赖都算反向耦合。
check_tree "pm-host 不引用 Nukkit 类名" 'cn\.nukkit' pm-host '*.php'

echo
if [ "$fail" -ne 0 ]; then
    echo "架构检查未通过。规则见 docs/project/spec/principles.md"
    exit 1
fi
echo "架构检查通过。"
