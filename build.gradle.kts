import org.gradle.api.plugins.JavaPluginExtension
import org.gradle.api.tasks.Exec
import org.gradle.api.tasks.compile.JavaCompile
import org.gradle.api.tasks.testing.Test
import org.gradle.jvm.toolchain.JavaLanguageVersion

plugins {
    java
}

allprojects {
    group = "cn.mukkit"
    version = "0.1.0-SNAPSHOT"
}

subprojects {
    apply(plugin = "java")

    extensions.configure<JavaPluginExtension> {
        toolchain {
            languageVersion.set(JavaLanguageVersion.of(21))
        }
    }

    tasks.withType<JavaCompile>().configureEach {
        options.release.set(17)
        options.encoding = "UTF-8"
    }

    // src/test/java 里是带 main() 的验收程序，不是 JUnit 测试；由 scripts/run-*.sh 调起。
    // Gradle 只负责编译 testClasses，不让 test task 因为"没有发现测试"而失败。
    tasks.withType<Test>().configureEach {
        failOnNoDiscoveredTests = false
    }
}

// 外部源码与运行时不是 Gradle 能自己解析的依赖：Nukkit-MOT 是另一个 Maven 项目，
// PocketMine fork 是 git patch + Composer，PHP 是平台二进制。这个 task 只做这些准备，
// 真正的 Java 构建仍由 Gradle 完成。
val prepareExternal = tasks.register<Exec>("prepareExternal") {
    group = "mukkit"
    description = "准备 Nukkit-MOT、PocketMine fork、Composer 依赖与 PHP 运行时"
    workingDir = rootDir
    commandLine("bash", "scripts/bootstrap.sh")
}

tasks.register("bootstrap") {
    group = "mukkit"
    description = "一键准备外部依赖并构建 Mukkit.jar"
    dependsOn(prepareExternal)
    dependsOn("build")
    dependsOn("checkArchitecture")
}

tasks.register<Exec>("checkArchitecture") {
    group = "verification"
    description = "运行架构红线机检"
    dependsOn(":mukkit-nukkit:jar")
    commandLine("bash", "scripts/check-architecture.sh")
}

tasks.register<Exec>("runSpike") {
    group = "mukkit"
    description = "构建并运行桥内 spike（200 tick）"
    dependsOn(":mukkit-spike:jar")
    environment("MUKKIT_SKIP_BUILD", "1")
    commandLine("bash", "scripts/run-spike.sh")
}

// run*/serve 的脚本通过 scripts/nukkit-jar.sh 找服务端 jar；这里把 Gradle 解析到的路径直接
// 传进去。脚本里如果再起一个 ./gradlew，会和当前构建争同一把项目锁。
val nukkitJarPathFile = project(":mukkit-nukkit").layout.buildDirectory.file("nukkit-jar-path.txt")

fun Exec.useResolvedNukkitJar() {
    val task = this
    dependsOn(":mukkit-nukkit:writeNukkitJarPath")
    doFirst {
        task.environment("MUKKIT_NUKKIT_RESOLVED_JAR", nukkitJarPathFile.get().asFile.readText().trim())
    }
}

tasks.register<Exec>("runM1") {
    group = "mukkit"
    description = "跑 Nukkit + PHP + phar 端到端判据"
    dependsOn(":mukkit-nukkit:jar")
    useResolvedNukkitJar()
    environment("MUKKIT_SKIP_BUILD", "1")
    commandLine("bash", "scripts/run-m1.sh")
}

tasks.register<Exec>("runProduct") {
    group = "mukkit"
    description = "跑真 Nukkit + jar + EconomyAPI 的产品判据"
    dependsOn(":mukkit-nukkit:jar")
    dependsOn(":mukkit-nukkit:testClasses")
    useResolvedNukkitJar()
    environment("MUKKIT_SKIP_BUILD", "1")
    commandLine("bash", "scripts/run-product.sh")
}

tasks.register<Exec>("serve") {
    group = "mukkit"
    description = "用已构建的 jar 起一个可连服务端"
    dependsOn(":mukkit-nukkit:jar")
    useResolvedNukkitJar()
    commandLine("bash", "scripts/serve.sh", "--no-build")
}
