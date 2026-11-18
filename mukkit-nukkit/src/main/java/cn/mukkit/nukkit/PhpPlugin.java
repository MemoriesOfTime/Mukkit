package cn.mukkit.nukkit;

import cn.nukkit.plugin.PluginBase;

/**
 * The Nukkit-side handle for one PHP plugin.
 *
 * <p>It exists so the plugin shows up in {@code /plugins}, in the plugin manager and in Nukkit's
 * own lifecycle order — but every hook is forwarded to the host process rather than executed
 * here. The object is a name badge, not an implementation.</p>
 */
public final class PhpPlugin extends PluginBase {

    private final String pluginName;
    private final NukkitGateway gateway;

    PhpPlugin(String pluginName, NukkitGateway gateway) {
        this.pluginName = pluginName;
        this.gateway = gateway;
    }

    /** The name the host knows this plugin by, which is the one from {@code plugin.yml}. */
    public String hostName() {
        return pluginName;
    }

    @Override
    public void onEnable() {
        gateway.queuePluginLifecycle(pluginName, "enable");
    }

    @Override
    public void onDisable() {
        gateway.queuePluginLifecycle(pluginName, "disable");
    }
}
