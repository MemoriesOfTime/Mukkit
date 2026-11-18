# Mukkit 测试工具链镜像
# 这个镜像只提供工具链（JDK21 + PHP + Gradle wrapper 的依赖），不包含任何项目源码：
# 源码由宿主机的仓库父目录整层挂载到 /work，Nukkit 的 fat jar 也由挂载提供
# （/work/Nukkit-MOT/target/Nukkit-MOT-SNAPSHOT.jar），因此镜像里刻意不执行项目构建。
#
# php-mbstring 不是可选项：PocketMine 的启动路径（方块注册表、崩溃转储）都调 mb_*；
# 少了它宿主会在 Server::__construct() 里崩，而 JVM 只看得到 "plugin host closed the
# connection"。php-cli 不会替你带上它。
#
# curl 是给 scripts/fetch-plugins.sh 兜底用的：真实插件通常在宿主机上下载好（容器里
# 走代理常常拉一半断掉），但容器里直接重跑 serve.sh 时不该因为缺 curl 而失败。
FROM eclipse-temurin:21-jdk

RUN apt-get update \
 && apt-get install -y --no-install-recommends php-cli php-mbstring curl ca-certificates unzip \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /work

# 构建期自检：PHP 可用、带 phar 与 mbstring，Java 可用，unzip 给 Gradle wrapper 解包
RUN php -v && php -m | grep -i -q phar && php -m | grep -i -q mbstring && java -version && unzip -v >/dev/null
