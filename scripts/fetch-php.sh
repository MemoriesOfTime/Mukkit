#!/usr/bin/env bash
#
# 下载官方 pmmp PHP（PHP 8.4，ZTS，自带 pmmpthread / chunkutils2 / encoding / morton / yaml）。
#
# 为什么要它：AsyncTask 的并行来自 pmmpthread 这个 C 扩展，而它要求 ZTS 的 PHP。系统/Homebrew
# 的 PHP 是 NTS，装不上；自己编一份 ZTS PHP 要半小时且和 PHP 版本绑死。pmmp 官方 PHP-Binaries
# 就是给 PocketMine 用的那一份成品。
#
# 平台是**运行时**的属性，不是 Mukkit 的属性：jar 一份，PHP 每个 OS+架构一份。
#   macos-arm64 / macos-x86_64 / linux-x86_64 / windows-x64
# （pmmp 还有 Android-arm64，那是给 Android 上的 PocketMine 用的，Nukkit 插件用不到。）
#
# 用法：
#   bash scripts/fetch-php.sh                  # 只下当前平台，放到 vendor/php/<platform>
#   bash scripts/fetch-php.sh --platform all   # 下全部四个（打包发行版时用）
#   bash scripts/fetch-php.sh --platform windows-x64
#   bash scripts/fetch-php.sh --force
#
# 代理：默认自动探测一个能连到 GitHub 的代理；要强制指定用 MUKKIT_PROXY。
#   MUKKIT_PROXY=http://127.0.0.1:7890 bash scripts/fetch-php.sh --platform all
#   注意 http_proxy/https_proxy 环境变量可能是别的工具的**内部**代理（连不上 GitHub），
#   所以脚本会实测连通性，不会盲目信环境变量。
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR="$ROOT/vendor/php"
TAG="pm5-php-8.4-latest"
BASE="https://github.com/pmmp/PHP-Binaries/releases/download/$TAG"

# 找一个真能下载 GitHub release 的代理。
#
# 顺序有意为之：先本机常见代理端口，最后才信 http_proxy/https_proxy。桌面工具注入的
# 环境变量代理往往是它自己的内部通道（CONNECT 能过、拉大文件却截断），1 字节探测骗得过，
# 半个包就废了 —— 所以探测本身要拉 512 KB 并核对字节数，不能只握手。
resolve_proxy() {
    if [ -n "${MUKKIT_PROXY:-}" ]; then
        printf '%s' "$MUKKIT_PROXY"
        return 0
    fi
    local probe="$BASE/PHP-8.4-Linux-x86_64-PM5.tar.gz"
    local candidates=(
        "http://127.0.0.1:7890"    # Clash / ClashX / Mihomo 默认
        "http://127.0.0.1:10809"   # v2rayN 默认 HTTP
        "http://127.0.0.1:1080"    # SOCKS 之外的通用 HTTP 口
    )
    [ -n "${https_proxy:-}" ] && candidates+=("$https_proxy")
    [ -n "${HTTPS_PROXY:-}" ] && candidates+=("$HTTPS_PROXY")

    local proxy got
    for proxy in "${candidates[@]}"; do
        got="$(curl -fsL -x "$proxy" -r 0-524287 -o /dev/null \
            --connect-timeout 5 --max-time 20 -w '%{size_download}' "$probe" 2>/dev/null || true)"
        # 少于 500 KB 说明代理只放行了握手/错误页，不是能扛 13 MB 的通道。
        if [ -n "$got" ] && [ "$got" -ge 500000 ]; then
            printf '%s' "$proxy"
            return 0
        fi
    done
    return 1
}

detect_platform() {
    case "$(uname -s)-$(uname -m)" in
        Darwin-arm64)  echo "macos-arm64" ;;
        Darwin-x86_64) echo "macos-x86_64" ;;
        Linux-x86_64)  echo "linux-x86_64" ;;
        MINGW*|MSYS*|CYGWIN*) echo "windows-x64" ;;
        *) echo "" ;;
    esac
}

asset_for() {
    case "$1" in
        macos-arm64)   echo "PHP-8.4-MacOS-arm64-PM5.tar.gz" ;;
        macos-x86_64)  echo "PHP-8.4-MacOS-x86_64-PM5.tar.gz" ;;
        linux-x86_64)  echo "PHP-8.4-Linux-x86_64-PM5.tar.gz" ;;
        windows-x64)   echo "PHP-8.4-Windows-x64-PM5.zip" ;;
        android-arm64) echo "PHP-8.4-Android-arm64-PM5.tar.gz" ;;
        *) return 1 ;;
    esac
}

php_binary_in() {
    local dir="$1"
    # macOS/Linux 的包是 bin/php7/bin/php；Windows 的包是 bin/php/php.exe。两种布局都得认。
    for candidate in "bin/php7/bin/php" "bin/php7/php" "bin/php" \
                     "bin/php/php.exe" "bin/php/php" \
                     "bin/php7/bin/php.exe" "bin/php7/php.exe" "bin/php.exe"; do
        if [ -f "$dir/$candidate" ]; then
            echo "$dir/$candidate"
            return 0
        fi
    done
    return 1
}

archive_ok() {
    case "$2" in
        *.zip) unzip -tqq "$1" >/dev/null 2>&1 ;;
        *)     tar -tzf "$1" >/dev/null 2>&1 ;;
    esac
}

install_one() {
    local platform="$1" force="$2"
    local asset; asset="$(asset_for "$platform")" || { printf '未知平台：%s\n' "$platform" >&2; return 2; }
    local dest="$VENDOR/$platform"

    # .version 只在"下载 + 解压 + 找到 php 二进制"全部成功后写入，所以它才是完整性标记。
    # 只看 php 二进制会把上次 tar 中途失败的残骸当成装好了（实测少 6 个文件、没有戳）。
    if php_binary_in "$dest" >/dev/null 2>&1 && [ -f "$dest/.version" ] && [ "$force" -eq 0 ]; then
        printf 'skip  %-14s 已存在\n' "$platform"
        return 0
    fi

    # 下载到固定路径而不是 mktemp：GitHub release 的链路会中途断，一个 13 MB 的包要跑十几分钟。
    # -C - 只有在路径稳定时才能续传。
    local dl="$VENDOR/.downloads"
    mkdir -p "$dl"
    local part="$dl/$asset.part"
    printf '== 下载 %-14s %s（已续 %s）\n' "$platform" "$asset" "$(du -h "$part" 2>/dev/null | cut -f1 || echo 0)"

    # 直连时必须 --noproxy：环境变量里那个代理是别的工具的内部通道，走它得到的是一份坏字节。
    do_curl() {
        if [ -n "${PROXY_URL:-}" ]; then
            curl -fL --http1.1 -x "$PROXY_URL" --retry 8 --retry-delay 3 --retry-all-errors \
                --speed-limit 1024 --speed-time 60 --connect-timeout 20 --max-time 1800 "$@"
        else
            curl -fL --http1.1 --noproxy '*' --retry 8 --retry-delay 3 --retry-all-errors \
                --speed-limit 1024 --speed-time 60 --connect-timeout 20 --max-time 1800 "$@"
        fi
    }

    download() {
        local rc=0
        do_curl -C - -o "$part" "$BASE/$asset" || rc=$?
        # 33 = 服务器拒绝续传（通常是 416：part 已到尾部）。整份重来，别在尾部再纠缠。
        if [ "$rc" -eq 33 ]; then
            printf '     续传被拒，从头下载\n'
            rm -f "$part"
            do_curl -o "$part" "$BASE/$asset" || rc=$?
        fi
        return "$rc"
    }

    unpack() {
        rm -rf "$dest"; mkdir -p "$dest"
        case "$asset" in
            *.zip) unzip -q "$part" -d "$dest" ;;
            *)     tar -xzf "$part" -C "$dest" ;;
        esac
    }

    # 上一次可能已下完但卡在解压（或进程被杀）；完整的包直接拿来用，不重复下 13 MB。
    if [ -s "$part" ] && archive_ok "$part" "$asset"; then
        printf '     已有完整包，跳过下载\n'
    else
        download
        # 续传的 part 可能是上一次经坏通道写坏的（gzip 会报 invalid stored block lengths）。
        # 校验失败就丢掉整份重下 —— 在半个坏包上再续只会得到另一个坏包。
        if ! archive_ok "$part" "$asset"; then
            printf '     续传内容损坏，丢弃 %s 并重下\n' "$(basename "$part")"
            rm -f "$part"
            download
        fi
    fi

    unpack
    rm -f "$part"

    local php; php="$(php_binary_in "$dest")" || { printf 'FAIL %s 解压后找不到 php 二进制\n' "$platform" >&2; return 1; }
    chmod +x "$php"
    # 版本戳：jar 里的 RuntimeAssets 只比对它，不比对 jar 的哈希 —— 重打 jar 不该重解 34 MB。
    printf '%s\n' "$asset" > "$dest/.version"

    # php.ini 里的 opcache 用相对名，而 extension_dir 是构建机的绝对路径；
    # 到这台机器上必然加载失败。换成解压出来的绝对路径，找不到就整行注释掉。
    local ini; ini="$(dirname "$php")/php.ini"
    if [ -f "$ini" ]; then
        local so; so="$(ls "$dest"/bin/php7/lib/php/extensions/*/opcache.so 2>/dev/null | head -1 || true)"
        # 用刚解出来的 PHP 改自己的 ini：不再要求机器上额外装 python3。
        "$php" -r '
$f = $argv[1];
$so = $argv[2] ?? "";
$s = file_get_contents($f);
$s = preg_replace_callback(
    "/^zend_extension=opcache\\.so.*$/m",
    static fn() => $so !== "" ? "zend_extension=" . $so : ";zend_extension=opcache.so (not shipped)",
    $s
);
file_put_contents($f, $s);
' "$ini" "${so:-}"
    fi
    printf 'ok    %-14s %s\n' "$platform" "$("$php" -v 2>/dev/null | head -1)"
}

force=0
platforms=()
while [ $# -gt 0 ]; do
    case "$1" in
        --force) force=1 ;;
        --platform)
            shift
            [ $# -gt 0 ] || { printf '--platform 需要值\n' >&2; exit 2; }
            if [ "$1" = "all" ]; then
                platforms=(macos-arm64 macos-x86_64 linux-x86_64 windows-x64)
            else
                IFS=',' read -r -a platforms <<< "$1"
            fi
            ;;
        *) printf 'unknown option: %s\n' "$1" >&2; exit 2 ;;
    esac
    shift
done

if [ ${#platforms[@]} -eq 0 ]; then
    current="$(detect_platform)"
    if [ -z "$current" ]; then
        printf '当前平台无法识别（%s-%s）。用 --platform 指定：macos-arm64 / macos-x86_64 / linux-x86_64 / windows-x64\n' "$(uname -s)" "$(uname -m)" >&2
        exit 1
    fi
    platforms=("$current")
fi

mkdir -p "$VENDOR"

# 一次性探测，避免每个平台都做一遍 range 请求。
if [ -n "${MUKKIT_PROXY:-}" ]; then
    PROXY_URL="$MUKKIT_PROXY"
elif PROXY_URL="$(resolve_proxy)"; then
    :
else
    PROXY_URL=""
fi
export PROXY_URL

for p in "${platforms[@]}"; do
    install_one "$p" "$force"
done

printf '\n== 完成。serve.sh 会自动使用 vendor/php/<当前平台>；手工指定用 MUKKIT_PHP_BINARY。\n'
