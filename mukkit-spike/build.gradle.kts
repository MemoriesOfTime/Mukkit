import org.gradle.api.tasks.SourceSetContainer

plugins {
    java
}

dependencies {
    implementation(project(":mukkit-bridge"))
}

tasks.jar {
    archiveBaseName.set("mukkit-spike")

    manifest {
        attributes("Main-Class" to "cn.mukkit.spike.SpikeMain")
    }

    from(project(":mukkit-bridge").extensions.getByType<SourceSetContainer>()["main"].output)
}

tasks.register<JavaExec>("run") {
    group = "application"
    description = "运行 spike（默认参数见 scripts/run-spike.sh）"
    classpath = sourceSets["main"].runtimeClasspath
    mainClass.set("cn.mukkit.spike.SpikeMain")
    workingDir = rootProject.rootDir
}
