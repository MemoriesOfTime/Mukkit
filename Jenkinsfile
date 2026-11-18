// Mukkit CI：Nukkit-MOT 不在这里编译，Gradle 直接按坐标从 repo.lanink.cn 解析
// （cn.nukkit:Nukkit，版本见 gradle.properties 的 nukkitVersion）。
// 外部准备只剩下 PocketMine fork、Composer 依赖和 pmmp PHP，由 scripts/bootstrap.sh 负责。
// 产物是带四个平台 PHP 运行时的 jar，和发行包一致。
//
// agent 上需要：JDK 21（下面的 tools 名与 Nukkit-MOT 的 Jenkinsfile 保持一致）、
// PHP 8.1+、git、curl、unzip。
pipeline {
    agent any

    tools {
        jdk 'java21'
    }

    options {
        buildDiscarder(logRotator(artifactNumToKeepStr: '5'))
    }

    parameters {
        string(name: 'NUKKIT_VERSION', defaultValue: 'MOT-SNAPSHOT',
               description: 'repo.lanink.cn 上 cn.nukkit:Nukkit 的版本')
    }

    environment {
        // 缓存放 workspace 外：deleteDir() 不会清掉，第二次构建不用重新 clone PM fork。
        PM_FORK_DIR = "${env.HOME}/.cache/mukkit/pocketmine-${env.EXECUTOR_NUMBER}"
    }

    stages {
        stage('Toolchain') {
            steps {
                // 缺什么在这里直接报出来，不要等到 bootstrap 里才炸。
                sh '''
                    java -version
                    php -v | head -1
                    git --version
                    curl --version | head -1
                '''
            }
        }

        stage('PHP runtimes') {
            steps {
                // bootstrap 只下当前平台的运行时；发行 jar 要带四个平台，所以先下齐。
                // 已有 .version 的平台会跳过。
                sh 'bash scripts/fetch-php.sh --platform all'
            }
        }

        stage('Build') {
            steps {
                script {
                    def nukkitVersion = params.NUKKIT_VERSION?.trim() ?: 'MOT-SNAPSHOT'
                    sh "./gradlew --no-daemon -PnukkitVersion=${nukkitVersion} bootstrap"
                }
                // 防止网络问题让 fetch-php 静默失败、打出只有单个平台或瘦 jar 的产物。
                sh '''
                    jar=mukkit-nukkit/build/libs/Mukkit.jar
                    for p in macos-arm64 macos-x86_64 linux-x86_64 windows-x64; do
                        if ! unzip -l "$jar" | grep -qF "runtime/$p/.version"; then
                            echo "FAIL  Mukkit.jar 缺少 $p 的 PHP 运行时"
                            exit 1
                        fi
                    done
                    echo "ok    Mukkit.jar 含四个平台的 PHP 运行时"
                '''
            }
            post {
                success {
                    archiveArtifacts artifacts: 'mukkit-nukkit/build/libs/Mukkit.jar', fingerprint: true
                }
            }
        }
    }

    post {
        always {
            deleteDir()
        }
    }
}
