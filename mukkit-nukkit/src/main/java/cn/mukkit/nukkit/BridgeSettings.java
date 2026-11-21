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
                      int maxRestarts, Path socket, String profile, String pmDataLayout,
                      boolean tcp) {

    /** The profiles the PHP host knows how to run. */
    static final Set<String> PROFILES = Set.of("auto", "pm", "plugin");

    /**
     * How the bridge reaches the PHP host.
     *
     * <p>{@code auto} picks {@code tcp} on Windows only, for one reason: the Windows PHP build
     * has no {@code unix} stream transport, so asking it to listen on a Unix socket fails before
     * the bridge ever gets to connect. Everywhere else the Unix socket stays the default — it
     * needs no port and no other process can squat on it.</p>
     */
    static final Set<String> TRANSPORTS = Set.of("auto", "unix", "tcp");

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

        String transport = config.getString("transport", "auto").trim().toLowerCase(Locale.ROOT);
        if (!TRANSPORTS.contains(transport)) {
            throw new IllegalArgumentException("config.yml: transport must be one of " + TRANSPORTS
                    + ", got '" + transport + "'");
        }
        boolean tcp = switch (transport) {
            case "tcp" -> true;
            case "unix" -> false;
            // The Windows PHP build has no unix stream transport, so a Unix listener there is a
            // host that never starts. Picking per platform is the only default that works on both.
            default -> PhpRuntime.platformId().startsWith("windows");
        };

        return new BridgeSettings(php, soft, hard, restarts, socketPath, profile, layout, tcp);
    }
}
