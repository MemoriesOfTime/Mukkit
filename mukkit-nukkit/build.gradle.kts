import org.gradle.api.tasks.SourceSetContainer

plugins {
    java
}

// Nukkit-MOT 没有公开的 Maven 坐标，也不能把 95 MB 的服务端 jar 放进仓库。
// 默认从兄弟目录取；显式指定用 MUKKIT_NUKKIT_JAR。
val nukkitJar = providers.environmentVariable("MUKKIT_NUKKIT_JAR")
    .map { file(it) }
    .orElse(
        providers.environmentVariable("NUKKIT_MOT_DIR")
            .map { file(it).resolve("target/Nukkit-MOT-SNAPSHOT.jar") }
    )
    .orElse(rootProject.file("../Nukkit-MOT/target/Nukkit-MOT-SNAPSHOT.jar"))
    .get()

val pocketMineRoot = providers.environmentVariable("PM_FORK_DIR")
    .map { rootProject.file(it) }
    .orElse(rootProject.file("../PocketMine-MP"))
    .get()

dependencies {
    implementation(project(":mukkit-bridge"))

    // Provided 语义：编译期能看见 Nukkit API，运行期用服务端自己的类，不把 Nukkit 打进插件 jar。
    compileOnly(files(nukkitJar))
    testImplementation(files(nukkitJar))
    testRuntimeOnly(files(nukkitJar))
}

val checkNukkitJar = tasks.register("checkNukkitJar") {
    group = "verification"
    description = "确认 Nukkit-MOT 服务端 jar 存在"
    mustRunAfter(rootProject.tasks.named("prepareExternal"))
    doLast {
        if (!nukkitJar.isFile) {
            throw GradleException(
                """
                Nukkit-MOT 服务端 jar 不存在：${nukkitJar.absolutePath}

                先跑：
                  ./gradlew bootstrap

                或者设置：
                  MUKKIT_NUKKIT_JAR=/path/to/Nukkit-MOT-SNAPSHOT.jar ./gradlew build
                """.trimIndent()
            )
        }
    }
}

val checkPocketMineFork = tasks.register("checkPocketMineFork") {
    group = "verification"
    description = "确认 PocketMine fork 与 Composer 依赖已经物化"
    mustRunAfter(rootProject.tasks.named("prepareExternal"))
    doLast {
        if (!pocketMineRoot.resolve("vendor/autoload.php").isFile) {
            throw GradleException(
                """
                PocketMine fork 还没有物化到：${pocketMineRoot.absolutePath}

                先跑：
                  ./gradlew bootstrap

                或者：
                  scripts/pm-fork.sh
                """.trimIndent()
            )
        }
    }
}

val prepareExternal = rootProject.tasks.named("prepareExternal")

tasks.named("compileJava") {
    dependsOn(checkNukkitJar)
    mustRunAfter(prepareExternal)
}
tasks.named("compileTestJava") {
    dependsOn(checkNukkitJar)
    mustRunAfter(prepareExternal)
}

tasks.processResources {
    dependsOn(checkPocketMineFork)
    mustRunAfter(prepareExternal)
    includeEmptyDirs = false
    exclude("**/.DS_Store")

    // PHP 宿主作为资源进 jar，运行时解包到 plugins/Mukkit/pm-host/。
    from(rootProject.file("pm-host")) {
        include("bin/**/*.php", "src/**/*.php")
        into("pm-host")
    }

    // 平台 PHP 运行时；目录不存在时 Gradle 会跳过，构建出瘦 jar。
    from(rootProject.file("vendor/php")) {
        exclude(".downloads/**")
        into("runtime")
    }

    // PocketMine 源码（含 vendor）随 jar 分发，满足 LGPL-3.0 的可替换要求。
    from(pocketMineRoot) {
        include("src/**", "vendor/**", "generated/**", "resources/**", "composer.json", "LICENSE")
        into("pocketmine")
    }
}

tasks.jar {
    archiveBaseName.set("Mukkit")
    archiveVersion.set("")
    archiveClassifier.set("")

    // 只有 mukkit-bridge 需要并进插件 jar；Nukkit 是 compileOnly，绝不能进。
    from(project(":mukkit-bridge").extensions.getByType<SourceSetContainer>()["main"].output)
}
