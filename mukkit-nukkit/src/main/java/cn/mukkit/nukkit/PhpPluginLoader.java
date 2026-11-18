package cn.mukkit.nukkit;

import cn.nukkit.Server;
import cn.nukkit.event.plugin.PluginDisableEvent;
import cn.nukkit.event.plugin.PluginEnableEvent;
import cn.nukkit.plugin.Plugin;
import cn.nukkit.plugin.PluginDescription;
import cn.nukkit.plugin.PluginLoader;

import java.io.File;
import java.util.regex.Pattern;

/**
 * Teaches Nukkit's plugin manager about {@code .phar} files.
 *
 * <p>{@code PluginManager.registerInterface} only asks for a class with a {@code (Server)}
 * constructor, so the adapter's context has to arrive out of band. That is the one static in
 * this module; it is set once, before the server loads any plugin, and never mutated after.</p>
 */
public final class PhpPluginLoader implements PluginLoader {

    private static final Pattern PHAR = Pattern.compile("^.+\\.phar$");

    private static volatile NukkitGateway gateway;
    private static volatile PharMeta meta;

    private final Server server;

    public PhpPluginLoader(Server server) {
        this.server = server;
    }

    /** Called by {@link BridgeService} before {@code registerInterface}. */
    static void install(NukkitGateway gateway, PharMeta meta) {
        PhpPluginLoader.gateway = gateway;
        PhpPluginLoader.meta = meta;
    }

    @Override
    public Pattern[] getPluginFilters() {
        return new Pattern[]{PHAR};
    }

    @Override
    public PluginDescription getPluginDescription(String filename) {
        return getPluginDescription(new File(filename));
    }

    @Override
    public PluginDescription getPluginDescription(File file) {
        PharMeta.Meta described = meta().describe(file.toPath());
        if (described == null) {
            server.getLogger().warning("Mukkit: " + file.getName() + " is not a readable plugin.phar, skipped");
            return null;
        }
        return new PluginDescription(described.toDescriptionMap());
    }

    @Override
    public Plugin loadPlugin(String filename) throws Exception {
        return loadPlugin(new File(filename));
    }

    @Override
    public Plugin loadPlugin(File file) throws Exception {
        PharMeta.Meta described = meta().describe(file.toPath());
        if (described == null) {
            return null;
        }
        PluginDescription description = new PluginDescription(described.toDescriptionMap());
        PhpPlugin plugin = new PhpPlugin(described.name(), gateway());
        plugin.init(this, server, description, new File(server.getPluginPath(), described.name()), file);
        plugin.onLoad();
        server.getLogger().info("Mukkit: loaded " + described.name() + " " + described.version()
                + " (" + file.getName() + ")");
        return plugin;
    }

    /**
     * Mirrors {@code JavaPluginLoader}: {@code setEnabled(true)} fires {@code onEnable}, then the
     * manager-facing event goes out. Plugin-permission registration stays in
     * {@code PluginManager.enablePlugin}, which calls this method.
     */
    @Override
    public void enablePlugin(Plugin plugin) {
        if (!(plugin instanceof PhpPlugin phpPlugin) || plugin.isEnabled()) {
            return;
        }
        server.getLogger().info("Mukkit: enabling " + phpPlugin.getName());
        phpPlugin.setEnabled(true);
        server.getPluginManager().callEvent(new PluginEnableEvent(plugin));
    }

    @Override
    public void disablePlugin(Plugin plugin) {
        if (!(plugin instanceof PhpPlugin phpPlugin) || !plugin.isEnabled()) {
            return;
        }
        server.getLogger().info("Mukkit: disabling " + phpPlugin.getName());
        server.getPluginManager().callEvent(new PluginDisableEvent(plugin));
        phpPlugin.setEnabled(false);
    }

    /**
     * Upstream's default implementation of this method calls {@code enablePlugin}, which is
     * wrong for an unload path. Disabling is the closest correct behaviour available here.
     */
    @Override
    public void unloadPlugin(Plugin plugin) {
        disablePlugin(plugin);
    }

    private static NukkitGateway gateway() {
        NukkitGateway value = gateway;
        if (value == null) {
            throw new IllegalStateException("PhpPluginLoader.install() was never called");
        }
        return value;
    }

    private static PharMeta meta() {
        PharMeta value = meta;
        if (value == null) {
            throw new IllegalStateException("PhpPluginLoader.install() was never called");
        }
        return value;
    }
}
