package cn.mukkit.nukkit;

import cn.nukkit.utils.Config;

import java.io.IOException;
import java.nio.file.Path;
import java.util.Locale;
import java.util.Set;

/**
 * Everything the adapter takes from {@code config.yml}, in one immutable bundle.
 *
 * <p>Read once at enable time. A reload is deliberately not supported yet: changing the PHP
 * binary or the socket path mid-run would mean tearing down the host while plugin state depends
 * on it, which is a different feature (and a different milestone) than "read three numbers".</p>
 */
record BridgeSettings(String phpBinary, long softDeadlineMillis, long hardDeadlineMillis,
                      int maxRestarts, Path socket, String profile, String pmDataLayout) {

    /** The profiles the PHP host knows how to run. */
    static final Set<String> PROFILES = Set.of("auto", "pm", "plugin");

    /**
     * Where a phar plugin keeps its data. Both names are PM's own vocabulary.
     *
     * <ul>
     *   <li>{@code plugin-folder} — {@code plugins/<PluginName>/}, the layout a Nukkit admin
     *       already expects: one folder per plugin, next to its jar or phar.</li>
     *   <li>{@code isolated} — {@code plugins/Mukkit/pm-data/plugin_data/<PluginName>/}, so the
     *       whole PocketMine side can be backed up or deleted in one place.</li>
     * </ul>
     */
    static final Set<String> PM_DATA_LAYOUTS = Set.of("plugin-folder", "isolated");

    static BridgeSettings from(Config config, Path dataFolder, Path jar) {
        String php;
        try {
            // Environment > installed runtime > runtime bundled in the jar > config.yml > PATH.
            php = PhpRuntime.resolve(dataFolder, jar, config.getString("php-binary", "php"));
        } catch (IOException e) {
            // An extraction failure is not something to paper over with system PHP: the user
            // installed the "everything included" artifact and it could not unpack itself.
            throw new IllegalStateException("cannot install the bundled PHP runtime: " + e.getMessage(), e);
        }
        long soft = config.getInt("soft-deadline-ms", 15);
        long hard = config.getInt("hard-deadline-ms", 500);
        int restarts = config.getInt("max-restarts", 1);
        String socket = config.getString("socket-path", "");
        Path socketPath = socket.isBlank()
                ? Path.of(System.getProperty("java.io.tmpdir"), "mukkit-" + ProcessHandle.current().pid() + ".sock")
                : Path.of(socket);

        String profile = config.getString("profile", "auto").trim().toLowerCase(Locale.ROOT);
        if (!PROFILES.contains(profile)) {
            // A typo must not silently pick a profile: one of them rejects every real PM plugin.
            throw new IllegalArgumentException("config.yml: profile must be one of " + PROFILES
                    + ", got '" + profile + "'");
        }

        String layout = config.getString("pm-data-layout", "plugin-folder").trim().toLowerCase(Locale.ROOT);
        if (!PM_DATA_LAYOUTS.contains(layout)) {
            // The layout decides where every plugin's config.yml lands. Guessing here would
            // silently split a server's data across two directories, which is worse than not
            // starting at all.
            throw new IllegalArgumentException("config.yml: pm-data-layout must be one of "
                    + PM_DATA_LAYOUTS + ", got '" + layout + "'");
        }

        return new BridgeSettings(php, soft, hard, restarts, socketPath, profile, layout);
    }
}
