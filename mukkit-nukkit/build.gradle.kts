import org.gradle.api.tasks.SourceSetContainer

plugins {
    java
}

// Nukkit-MOT 的完整服务端 jar 发布在 repo.lanink.cn（cn.nukkit:Nukkit）。
// 唯一来源就是这个坐标：不在本地 clone，也不支持本地源码构建。
repositories {
    maven {
        name = "laninkSnapshots"
        url = uri("https://repo.lanink.cn/repository/maven-snapshots")
        mavenContent { snapshotsOnly() }
    }
    maven {
        name = "laninkReleases"
        url = uri("https://repo.lanink.cn/repository/maven-releases")
        mavenContent { releasesOnly() }
    }
}

val nukkitVersion = providers.gradleProperty("nukkitVersion").getOrElse("MOT-SNAPSHOT")
val nukkitCoordinate = "cn.nukkit:Nukkit:$nukkitVersion"

val pocketMineRoot = providers.environmentVariable("PM_FORK_DIR")
    .map { rootProject.file(it) }
    .orElse(rootProject.file("../PocketMine-MP"))
    .get()

dependencies {
    implementation(project(":mukkit-bridge"))

    // Provided 语义：编译期能看见 Nukkit API，运行期用服务端自己的类，不把 Nukkit 打进插件 jar。
    // 发布出来的 jar 自带依赖，所以关掉传递依赖。
    compileOnly(nukkitCoordinate) { isTransitive = false }
    testImplementation(nukkitCoordinate) { isTransitive = false }
    testRuntimeOnly(nukkitCoordinate) { isTransitive = false }
}

// 验收脚本需要的服务端 jar 路径。compileClasspath 里只有 bridge 的 output 和 Nukkit。
fun resolveNukkitJar(): File =
    configurations.getByName("compileClasspath").resolve().single { it.name.startsWith("Nukkit") }

// 给 scripts/nukkit-jar.sh 用：把 Gradle 实际解析到的 jar 路径吐到 stdout。
tasks.register("printNukkitJar") {
    group = "mukkit"
    description = "打印构建使用的 Nukkit-MOT jar 路径"
    doLast {
        println(resolveNukkitJar().absolutePath)
    }
}

// 给 Gradle 的 runM1 / runProduct / serve 用：把路径落成文件，Exec 在 doFirst 里读，
// 避免脚本再起一个 ./gradlew（同一项目目录里嵌套会互相等锁）。
val nukkitJarPathFile = layout.buildDirectory.file("nukkit-jar-path.txt")

tasks.register("writeNukkitJarPath") {
    group = "mukkit"
    description = "把解析到的 Nukkit-MOT jar 路径写到 build/nukkit-jar-path.txt"
    doLast {
        val target = nukkitJarPathFile.get().asFile
        target.parentFile.mkdirs()
        target.writeText(resolveNukkitJar().absolutePath + "\n")
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
