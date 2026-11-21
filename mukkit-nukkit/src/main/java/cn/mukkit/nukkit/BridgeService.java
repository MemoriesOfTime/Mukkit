package cn.mukkit.nukkit;

import cn.nukkit.Server;
import cn.nukkit.plugin.PluginManager;
import cn.mukkit.bridge.HostHungException;
import cn.mukkit.bridge.TickDriver;

import java.io.IOException;
import java.nio.file.Files;
import java.nio.file.Path;
import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.stream.Stream;

/**
 * Wires the PHP host into a running Nukkit server.
 *
 * <p>This is the whole product in one class: find the phar plugins, start the host, teach the
 * plugin manager about them, and hand the tick over. It is created in {@code onEnable} and torn
 * down in {@code onDisable} — there is no launcher, no custom main, no patched server. A jar
 * dropped into {@code plugins/} is the entire installation.</p>
 *
 * <p>Phar plugins therefore load <em>after</em> jar plugins: Nukkit scans its plugin directory
 * during {@code Server}'s constructor, before any plugin is enabled, so the only correct moment
 * to register a second loader is from inside a plugin that is already running.</p>
 */
final class BridgeService implements AutoCloseable {

    private final MukkitPlugin plugin;
    private final BridgeSettings settings;
    private final NukkitGateway gateway;
    private final List<HostProcess.PluginEntry> entries;
    private final List<PhpPlugin> pharPlugins = new ArrayList<>();

    private HostProcess host;
    private TickDriver driver;
    private int restarts;
    private boolean failed;

    private BridgeService(MukkitPlugin plugin, BridgeSettings settings, NukkitGateway gateway,
                          List<HostProcess.PluginEntry> entries, HostProcess host, TickDriver driver) {
        this.plugin = plugin;
        this.settings = settings;
        this.gateway = gateway;
        this.entries = entries;
        this.host = host;
        this.driver = driver;
    }

    static BridgeService start(MukkitPlugin plugin, BridgeSettings settings, Path pmHome, Path pmRoot) throws IOException {
        Server server = plugin.getServer();
        Path pluginDir = Path.of(server.getPluginPath());
        String profile = resolveProfile(plugin, settings.profile(), pmRoot);

        PharMeta meta = new PharMeta(settings.phpBinary(), pmHome.resolve("bin").resolve("pm-inspect.php"));
        List<HostProcess.PluginEntry> entries = scan(pluginDir, meta, server, plugin);
        if (entries.isEmpty()) {
            plugin.getLogger().info("Mukkit: " + pluginDir + " 里没有 .phar, 只启动空的 PHP 宿主");
        }

        Path dataFolder = plugin.getDataFolder().toPath();
        HostProcess host = HostProcess.start(new HostProcess.Config(
                settings.phpBinary(),
                pmHome.resolve("bin").resolve("mukkit-host.php").toString(),
                settings.socket(),
                dataFolder.resolve("logs").resolve("pm-host.log"),
                20,
                settings.softDeadlineMillis(),
                profile,
                pmRoot,
                dataFolder.resolve("pm-data"),
                pluginDir,
                server.getDefaultLevel() == null ? "" : server.getDefaultLevel().getName(),
                settings.pmDataLayout(),
                settings.tcp()), entries);

        NukkitGateway gateway = new NukkitGateway(server);
        // The handshake consumed the ready frame before TickDriver existed, so nothing else would
        // ever hand it to the gateway. It carries the host's `broadcasts` promise, which decides
        // whether Nukkit keeps its own chat/join announcements.
        gateway.onReady(host.ready());
        PhpPluginLoader.install(gateway, meta);

        PluginManager pluginManager = server.getPluginManager();
        pluginManager.registerEvents(gateway, plugin);
        if (!pluginManager.registerInterface(PhpPluginLoader.class)) {
            host.close();
            throw new IOException("could not register the phar plugin loader");
        }

        BridgeService service = new BridgeService(plugin, settings, gateway, entries, host,
                new TickDriver(host.channel(), gateway,
                        new TickDriver.Config(20, settings.softDeadlineMillis(), settings.hardDeadlineMillis())));

        pluginManager.loadPlugins(pluginDir.toFile(), List.of(PhpPluginLoader.class.getName()));
        for (var entry : pluginManager.getPlugins().values()) {
            if (entry instanceof PhpPlugin phpPlugin) {
                service.pharPlugins.add(phpPlugin);
                pluginManager.enablePlugin(phpPlugin);
            }
        }

        plugin.getLogger().info("Mukkit: 宿主已连接（profile=" + profile + "）, " + entries.size() + " 个 PM 插件"
                + (entries.isEmpty() ? "" : " (" + names(entries) + ")"));
        return service;
    }

    /** Called from the scheduler once per server tick. */
    long tick() {
        if (failed) {
            return -1;
        }
        try {
            return driver.step();
        } catch (HostHungException e) {
            return recover("宿主在 tick " + e.sequence() + " 超过硬超时");
        } catch (IOException e) {
            return recover("宿主连接断开: " + e.getMessage());
        }
    }

    private long recover(String reason) {
        plugin.getLogger().warning("Mukkit: " + reason);
        if (restarts >= settings.maxRestarts()) {
            failed = true;
            // Nobody is going to deliver chat through PocketMine any more, so hand it back to
            // Nukkit instead of leaving a bridge that silently eats every message.
            gateway.onHostLost(reason);
            plugin.getLogger().error("Mukkit: 不再重启宿主。phar 插件已失效，jar 插件不受影响；"
                    + "修复后请 /mukkit reload 或重启服务器");
            return -1;
        }
        restarts++;
        try {
            host.restart();
            driver.rebind(host.channel());
            // A restarted host re-negotiates, so its delivery promise has to be read again; the
            // old one may have been made by a different profile.
            gateway.onReady(host.ready());
            for (PhpPlugin phpPlugin : pharPlugins) {
                gateway.queuePluginLifecycle(phpPlugin.hostName(), "enable");
            }
            plugin.getLogger().warning("Mukkit: 宿主已重启（第 " + restarts + " 次），重放 "
                    + pharPlugins.size() + " 个插件");
            return 0;
        } catch (IOException e) {
            failed = true;
            plugin.getLogger().error("Mukkit: 重启宿主失败: " + e.getMessage());
            return -1;
        }
    }

    /**
     * Feeds a synthetic scenario into the same queue real events use. Exists so the bridge can
     * be exercised without a client; real player traffic takes the same path once connected.
     */
    void demo() {
        String uuid = "11111111-1111-1111-1111-111111111111";
        Map<String, Object> join = new LinkedHashMap<>(2);
        join.put("name", "Steve");
        gateway.queueSyntheticEvent("player.join", uuid, join);

        Map<String, Object> chat = new LinkedHashMap<>(2);
        chat.put("name", "Steve");
        chat.put("message", "hello from Mukkit");
        gateway.queueSyntheticEvent("player.chat", uuid, chat);

        Map<String, Object> quit = new LinkedHashMap<>(2);
        quit.put("name", "Steve");
        quit.put("reason", "demo over");
        gateway.queueSyntheticEvent("player.quit", uuid, quit);

        plugin.getLogger().info("Mukkit: 已注入合成事件 join/chat/quit（不是真玩家）");
    }

    List<String> pharPlugins() {
        List<String> names = new ArrayList<>(pharPlugins.size());
        for (PhpPlugin phpPlugin : pharPlugins) {
            names.add(phpPlugin.hostName() + (phpPlugin.isEnabled() ? "" : " (disabled)"));
        }
        return names;
    }

    String status() {
        StringBuilder out = new StringBuilder();
        out.append("Mukkit ").append(plugin.getDescription().getVersion()).append('\n');
        out.append("  php        ").append(settings.phpBinary()).append('\n');
        out.append("  host       ").append(host.describe()).append('\n');
        out.append("  phar       ").append(pharPlugins().isEmpty() ? "none" : String.join(", ", pharPlugins())).append('\n');
        out.append("  restarts   ").append(restarts).append(failed ? " (已放弃)" : "").append('\n');
        out.append(driver.stats().report());
        out.append(gateway.summary());
        return out.toString();
    }

    @Override
    public void close() {
        if (driver != null) {
            for (PhpPlugin phpPlugin : pharPlugins) {
                gateway.queuePluginLifecycle(phpPlugin.hostName(), "disable");
            }
            try {
                driver.step();
            } catch (Exception e) {
                plugin.getLogger().info("Mukkit: 关停时最后一帧未送达: " + e.getMessage());
            }
            driver.finish();
        }
        if (host != null) {
            host.close();
        }
        plugin.getLogger().info("Mukkit: 宿主已关闭");
    }

    /**
     * Picks the PHP profile the host should boot.
     *
     * {@code auto} means "use the fork if the jar actually shipped one". That check is a file
     * existence test rather than a build flag on purpose: a jar built without the fork must still
     * start, just in the degraded plugin mode, and say so loudly. The two profiles are not
     * interchangeable — {@code plugin} rejects every {@code PluginBase} plugin — so silently
     * guessing would be worse than the extra file check.
     */
    private static String resolveProfile(MukkitPlugin plugin, String configured, Path pmRoot) {
        if (!"auto".equals(configured)) {
            return configured;
        }
        boolean hasFork = Files.isRegularFile(pmRoot.resolve("src").resolve("mukkit").resolve("polyfill.php"));
        if (hasFork) {
            return "pm";
        }
        plugin.getLogger().warning("Mukkit: jar 里没有找到 PocketMine（" + pmRoot + "），"
                + "回退到 plugin 的 HostedPlugin 契约；真正的 PM 插件会被拒绝。"
                + "如果你是从源码构建的，先跑 git submodule/composer 再打包");
        return "plugin";
    }

    private static List<HostProcess.PluginEntry> scan(Path pluginDir, PharMeta meta, Server server,
                                                       MukkitPlugin plugin) throws IOException {
        List<HostProcess.PluginEntry> entries = new ArrayList<>();
        if (!Files.isDirectory(pluginDir)) {
            return entries;
        }
        try (Stream<Path> files = Files.list(pluginDir)) {
            for (Path phar : files.filter(path -> path.getFileName().toString().endsWith(".phar")).sorted().toList()) {
                Path absolute = phar.toAbsolutePath().normalize();
                PharMeta.Meta described = meta.describe(absolute);
                if (described == null) {
                    continue;
                }
                // Both managers derive the plugin's data folder from its name: Nukkit uses
                // plugins/<name> for the wrapper, and (in the default layout) PocketMine will
                // write there too. If a jar plugin already owns that folder, loading this phar
                // would drop a second config.yml into someone else's data.
                if (server.getPluginManager().getPlugin(described.name()) != null) {
                    plugin.getLogger().warning("Mukkit: 跳过 " + phar.getFileName() + "：插件名 '"
                            + described.name() + "' 已被一个 Nukkit 插件占用，数据目录会撞车");
                    continue;
                }
                entries.add(new HostProcess.PluginEntry(described.name(), absolute.toString()));
            }
        }
        return entries;
    }

    private static String names(List<HostProcess.PluginEntry> entries) {
        return String.join(", ", entries.stream().map(HostProcess.PluginEntry::name).toList());
    }
}
