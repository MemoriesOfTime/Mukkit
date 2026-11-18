package cn.mukkit.nukkit;

import cn.nukkit.command.Command;
import cn.nukkit.command.CommandSender;
import cn.nukkit.command.PluginCommand;
import cn.nukkit.plugin.PluginBase;

import java.io.IOException;
import java.nio.file.Path;

/**
 * The product: a Nukkit plugin that makes the server able to load PocketMine plugins.
 *
 * <p>Installing it is dropping this jar into {@code plugins/}. There is no launcher, no patched
 * server, no other file to place: the PHP host ships inside the jar and is unpacked into the
 * plugin's data folder on first enable. From the server's point of view Mukkit is an ordinary
 * jar plugin that happens to own a child process.</p>
 *
 * <p>What it deliberately is not: a fork of Nukkit. {@code Server}, the tick loop and every
 * game class stay untouched; the only thing we add is a second {@code PluginLoader} for
 * {@code .phar} files and one tick callback.</p>
 */
public final class MukkitPlugin extends PluginBase {

    private static final String HOST_RESOURCE = "pm-host";
    private static final String FORK_RESOURCE = "pocketmine";

    private BridgeService bridge;

    @Override
    public void onEnable() {
        saveDefaultConfig();
        Path dataFolder = getDataFolder().toPath();
        BridgeSettings settings;
        try {
            settings = BridgeSettings.from(getConfig(), dataFolder, getFile().toPath());
        } catch (IllegalArgumentException | IllegalStateException e) {
            getLogger().error("Mukkit: " + e.getMessage());
            disableSelf();
            return;
        }

        getLogger().info("Mukkit: platform=" + PhpRuntime.platformId() + " php=" + settings.phpBinary());

        if (!phpAvailable(settings.phpBinary())) {
            getLogger().error("Mukkit: 找不到 PHP 解释器 '" + settings.phpBinary() + "'。"
                    + "装一个 PHP 8.1+ 并在 config.yml 的 php-binary 里写绝对路径，然后重启服务器");
            disableSelf();
            return;
        }

        String stamp = PluginAssets.stampFor(getFile().toPath());
        Path pmHome;
        Path pmRoot;
        try {
            // Two trees: the bridge's own host scripts, and the PocketMine fork they boot. Both are
            // source in the jar, so a user can inspect and replace the LGPL part without a build.
            pmHome = PluginAssets.extract(getFile().toPath(), dataFolder.resolve(HOST_RESOURCE), stamp, HOST_RESOURCE);
            pmRoot = PluginAssets.extract(getFile().toPath(), dataFolder.resolve(FORK_RESOURCE), stamp, FORK_RESOURCE);
        } catch (IOException e) {
            getLogger().error("Mukkit: 无法解包内置的 PHP 宿主: " + e.getMessage());
            disableSelf();
            return;
        }

        try {
            bridge = BridgeService.start(this, settings, pmHome, pmRoot);
        } catch (Exception e) {
            getLogger().error("Mukkit: 启动 PHP 宿主失败: " + e);
            disableSelf();
            return;
        }

        // One scheduler task per server tick, on the main thread: the bridge's whole model is
        // "one tick in flight", and the scheduler is the only place inside Server.tick() that a
        // plugin can legally run.
        // Registered in code rather than through plugin.yml: the yaml path is registered during
        // Server's constructor, and a command that silently fails to appear is worse than one
        // that cannot exist at all.
        PluginCommand<MukkitPlugin> command = new PluginCommand<>("mukkit", this);
        command.setDescription("Mukkit 桥接状态");
        command.setUsage("/mukkit <status|plugins|demo>");
        command.setExecutor(this);
        getServer().getCommandMap().register("mukkit", command);

        getServer().getScheduler().scheduleRepeatingTask(this, bridge::tick, 1);
        getLogger().info("Mukkit: 已启用，phar 插件 " + bridge.pharPlugins().size() + " 个");
    }

    @Override
    public void onDisable() {
        if (bridge != null) {
            bridge.close();
            bridge = null;
        }
    }

    @Override
    public boolean onCommand(CommandSender sender, Command command, String label, String[] args) {
        if (bridge == null) {
            sender.sendMessage("§cMukkit 未启动：见服务器日志");
            return true;
        }
        String sub = args.length == 0 ? "status" : args[0].toLowerCase();
        switch (sub) {
            case "status" -> {
                for (String line : bridge.status().split("\n")) {
                    sender.sendMessage(line);
                }
            }
            case "plugins" -> sender.sendMessage(bridge.pharPlugins().isEmpty()
                    ? "§7没有加载任何 .phar"
                    : "§7phar: §f" + String.join(", ", bridge.pharPlugins()));
            case "demo" -> {
                bridge.demo();
                sender.sendMessage("§7已注入合成事件，看下一 tick 的日志");
            }
            default -> sender.sendMessage("§e用法: /" + label + " <status|plugins|demo>");
        }
        return true;
    }

    private void disableSelf() {
        getServer().getPluginManager().disablePlugin(this);
    }

    private static boolean phpAvailable(String phpBinary) {
        try {
            Process process = new ProcessBuilder(phpBinary, "-r", "echo PHP_VERSION;")
                    .redirectErrorStream(true)
                    .start();
            if (!process.waitFor(10, java.util.concurrent.TimeUnit.SECONDS)) {
                process.destroyForcibly();
                return false;
            }
            return process.exitValue() == 0;
        } catch (IOException e) {
            return false;
        } catch (InterruptedException e) {
            Thread.currentThread().interrupt();
            return false;
        }
    }
}
