package cn.mukkit.nukkit;

import cn.nukkit.Player;
import cn.nukkit.Server;
import cn.nukkit.level.Location;

import java.util.ArrayList;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;
import java.util.Objects;
import java.util.Queue;
import java.util.UUID;

/**
 * Keeps Nukkit's players and PocketMine's view of the same players aligned.
 *
 * <p>Polled once every {@code mukkit.stateInterval} ticks rather than driven by events. Nukkit's
 * events fire at awkward moments — {@code EntityDamageEvent} runs <em>before</em> the health is
 * actually reduced, so a MONITOR listener would report the pre-hit value and then miss the change
 * entirely. A poll has no ordering problem: by the time it runs, everything that happened this
 * tick has been applied. Throttling then comes for free.</p>
 *
 * <p>Echo suppression works the same way as the block mirror: applying a host change re-reads the
 * player into the shadow, so the next poll finds nothing to report. Without that, a teleport
 * would bounce between the two processes forever.</p>
 */
final class PlayerStateMirror {

    private static final double POSITION_EPSILON = 0.001;
    private static final double ROTATION_EPSILON = 0.05;
    private static final double HEALTH_EPSILON = 0.001;
    private static final double PROGRESS_EPSILON = 0.001;
    private static final double HUNGER_EPSILON = 0.001;

    /** Effect durations tick every tick; a second is the granularity a plugin can act on. */
    private static final int EFFECT_SECOND_TICKS = 20;

    /** 10 Hz by default: fast enough for plugins that track movement, cheap enough to ignore. */
    private static final int INTERVAL = Math.max(1, Integer.getInteger("mukkit.stateInterval", 2));

    private final Server server;
    private final Queue<Map<String, Object>> inbound;
    private final java.util.function.BooleanSupplier enabled;

    /** Last observed state per online player. */
    private final Map<UUID, Map<String, Object>> shadow = new LinkedHashMap<>();

    private int tick;

    PlayerStateMirror(Server server, Queue<Map<String, Object>> inbound, java.util.function.BooleanSupplier enabled) {
        this.server = server;
        this.inbound = inbound;
        this.enabled = enabled;
    }

    void remember(Player player) {
        UUID id = player.getUniqueId();
        if (id != null) {
            shadow.put(id, snapshot(player));
        }
    }

    void forget(UUID id) {
        if (id != null) {
            shadow.remove(id);
        }
    }

    /** Collects what Nukkit changed since the last poll. Runs on the tick thread. */
    void poll() {
        // Nothing to mirror when the host does not keep a mirror: sending state to a profile that
        // cannot apply it would be a warning per tick per player, which is worse than silence.
        // The shadow deliberately goes stale while disabled, so the first poll after the host
        // comes back reports everything that changed in the meantime.
        if (!enabled.getAsBoolean()) {
            return;
        }
        if (++tick % INTERVAL != 0) {
            return;
        }
        Map<UUID, Player> online = server.getOnlinePlayers();
        for (Player player : new ArrayList<>(online.values())) {
            UUID id = player.getUniqueId();
            if (id == null) {
                continue;
            }
            Map<String, Object> after = snapshot(player);
            Map<String, Object> before = shadow.put(id, after);
            if (before == null) {
                // First sighting: the join frame already told the host where this player is.
                continue;
            }
            Map<String, Object> changed = diff(before, after);
            if (changed.isEmpty()) {
                continue;
            }
            Map<String, Object> item = new LinkedHashMap<>(4);
            item.put("k", "player.state");
            item.put("player", id.toString());
            item.put("s", changed);
            inbound.add(item);
        }
        shadow.keySet().retainAll(online.keySet());
    }

    /** Applies a state the plugin host pushed. */
    @SuppressWarnings("unchecked")
    void apply(Map<String, Object> op) {
        UUID id = parseUuid(String.valueOf(op.get("to")));
        if (id == null) {
            return;
        }
        Player player = server.getOnlinePlayers().get(id);
        if (player == null || !(op.get("s") instanceof Map<?, ?> raw)) {
            return;
        }
        Map<String, Object> state = (Map<String, Object>) raw;

        if (state.containsKey("op")) {
            boolean operator = truth(state.get("op"));
            // First, because the operator bit changes what every permission check in this frame
            // answers. `setOp()` is the same call `/op` makes: it writes ops.txt, recalculates
            // permissions and refreshes the adventure settings the client sees.
            if (player.isOp() != operator) {
                player.setOp(operator);
            }
        }

        if (state.containsKey("x") && state.containsKey("y") && state.containsKey("z")) {
            double x = number(state.get("x"));
            double y = number(state.get("y"));
            double z = number(state.get("z"));
            double yaw = state.containsKey("yaw") ? number(state.get("yaw")) : player.getYaw();
            double pitch = state.containsKey("pitch") ? number(state.get("pitch")) : player.getPitch();
            // A location and not a vector: the client also needs the facing, otherwise a teleport
            // to the same spot looks like nothing happened.
            player.teleport(new Location(x, y, z, yaw, pitch, player.getLevel()));
        }

        if (state.containsKey("effects") && state.get("effects") instanceof List<?> rows) {
            // Before health, because health boost/absorption change the maximum this frame's
            // health value is meant to be measured against.
            for (String gap : EffectState.apply(player, rows)) {
                System.out.println("[mukkit] player.state: " + gap);
            }
        }

        if (state.containsKey("health")) {
            player.setHealth((float) Math.max(0.0, number(state.get("health"))));
        }

        if (state.containsKey("gamemode")) {
            int mode = gameModeOf(String.valueOf(state.get("gamemode")));
            if (mode < 0) {
                System.out.println("[mukkit] player.state: unknown gamemode '" + state.get("gamemode") + "'");
            } else if (!player.setGamemode(mode)) {
                // Another Nukkit plugin cancelled PlayerGameModeChangeEvent. PocketMine will keep
                // disagreeing until one side changes again; say so instead of retrying in silence.
                System.out.println("[mukkit] player.state: gamemode change for " + player.getName()
                        + " was cancelled by a Nukkit plugin");
            }
        }

        if (state.containsKey("heldSlot")) {
            Object slotValue = state.get("heldSlot");
            double slotNumber = slotValue instanceof Number n ? n.doubleValue() : Double.NaN;
            int hotbar = player.getInventory().getHotbarSize();
            // A non-numeric or fractional slot is a broken frame. Casting it would quietly mean
            // slot 0, which moves the client somewhere nobody asked for.
            if (Double.isNaN(slotNumber) || slotNumber != Math.rint(slotNumber)) {
                System.out.println("[mukkit] player.state: held slot '" + slotValue + "' is not an integer");
            } else if (slotNumber < 0 || slotNumber >= hotbar) {
                // Not clamped to the nearest slot, for the same reason.
                System.out.println("[mukkit] player.state: held slot " + (int) slotNumber
                        + " is outside 0-" + (hotbar - 1));
            } else if (player.getInventory().getHeldItemIndex() != (int) slotNumber) {
                // The same call a hotbar key makes: it changes the server's answer and sends
                // MobEquipmentPacket, so the client's selected slot moves with it. The
                // `send=false` variant would leave the client showing the old slot.
                player.getInventory().setHeldItemIndex((int) slotNumber);
            }
        }

        if (state.containsKey("xpLevel") || state.containsKey("xpProgress")) {
            int level = state.containsKey("xpLevel")
                    ? Math.max(0, (int) number(state.get("xpLevel")))
                    : player.getExperienceLevel();
            double progress = state.containsKey("xpProgress")
                    ? clamp(number(state.get("xpProgress")), 0.0, 1.0)
                    : progressOf(player.getExperience(), player.getExperienceLevel());
            // Nukkit stores XP as points inside the current level, PocketMine as a fraction of
            // the bar. The conversion uses Nukkit's own table, not a second copy of the formula.
            int exp = (int) Math.floor(progress * Player.calculateRequireExperience(level));
            player.setExperience(exp, level);
        }

        if (state.containsKey("food") || state.containsKey("saturation")) {
            if (state.containsKey("food")) {
                player.getFoodData().setLevel((int) Math.round(number(state.get("food"))));
            }
            if (state.containsKey("saturation")) {
                // Saturation is clamped to the food level, so food must be written first.
                player.getFoodData().setFoodSaturationLevel((float) Math.max(0.0, number(state.get("saturation"))));
            }
        }

        shadow.put(id, snapshot(player));
    }

    // ---------------------------------------------------------------- pure helpers

    /**
     * The fields that differ, grouped the way the wire wants them.
     *
     * <p>Package-private and static so it can be exercised without a server: the grouping and the
     * epsilons are the part that would silently misbehave, not the field reads.</p>
     */
    static Map<String, Object> diff(Map<String, Object> before, Map<String, Object> after) {
        Map<String, Object> changed = new LinkedHashMap<>(4);
        if (moved(before, after)) {
            for (String key : new String[]{"x", "y", "z", "yaw", "pitch"}) {
                changed.put(key, after.get(key));
            }
        }
        if (Math.abs(number(before.get("health")) - number(after.get("health"))) > HEALTH_EPSILON) {
            changed.put("health", after.get("health"));
        }
        if (Math.round(number(before.get("maxHealth"))) != Math.round(number(after.get("maxHealth")))) {
            changed.put("maxHealth", after.get("maxHealth"));
        }
        if (!String.valueOf(before.get("gamemode")).equals(String.valueOf(after.get("gamemode")))) {
            changed.put("gamemode", after.get("gamemode"));
        }
        // The selected hotbar slot is a position in the inventory, not inventory content: it is
        // one small int and it travels alone, so a slot press does not ship 41 item stacks.
        if (Math.round(number(before.get("heldSlot"))) != Math.round(number(after.get("heldSlot")))) {
            changed.put("heldSlot", after.get("heldSlot"));
        }
        if (Math.round(number(before.get("xpLevel"))) != Math.round(number(after.get("xpLevel")))) {
            changed.put("xpLevel", after.get("xpLevel"));
        }
        if (Math.abs(number(before.get("xpProgress")) - number(after.get("xpProgress"))) > PROGRESS_EPSILON) {
            changed.put("xpProgress", after.get("xpProgress"));
        }
        if (Math.abs(number(before.get("food")) - number(after.get("food"))) > HUNGER_EPSILON) {
            changed.put("food", after.get("food"));
        }
        if (Math.abs(number(before.get("saturation")) - number(after.get("saturation"))) > HUNGER_EPSILON) {
            changed.put("saturation", after.get("saturation"));
        }
        if (!sameEffects(before.get("effects"), after.get("effects"))) {
            changed.put("effects", after.get("effects"));
        }
        // One bit, server-wide: an operator who was opped did not also move. Keeping it separate
        // also keeps `/op` from producing a position update, which a plugin would read as a
        // teleport.
        if (!Objects.equals(before.get("op"), after.get("op"))) {
            changed.put("op", after.get("op"));
        }
        return changed;
    }

    /**
     * Effect duration counts down every tick, so comparing it exactly would report a change at
     * 10 Hz for as long as any potion is active. Ids, amplifiers and flags are compared exactly;
     * duration is compared in whole seconds, which is the resolution a plugin can act on.
     */
    static boolean sameEffects(Object before, Object after) {
        if (!(before instanceof List<?> a) || !(after instanceof List<?> b)) {
            return Objects.equals(before, after);
        }
        if (a.size() != b.size()) {
            return false;
        }
        for (int i = 0; i < a.size(); i++) {
            if (!(a.get(i) instanceof List<?> left) || !(b.get(i) instanceof List<?> right)
                    || left.size() < 5 || right.size() < 5) {
                if (!Objects.equals(a.get(i), b.get(i))) {
                    return false;
                }
                continue;
            }
            if (Math.round(number(left.get(0))) != Math.round(number(right.get(0)))
                    || effectSecond(left) != effectSecond(right)
                    || Math.round(number(left.get(2))) != Math.round(number(right.get(2)))
                    || !Objects.equals(left.get(3), right.get(3))
                    || !Objects.equals(left.get(4), right.get(4))) {
                return false;
            }
        }
        return true;
    }

    private static int effectSecond(List<?> row) {
        return (int) (number(row.get(1)) / EFFECT_SECOND_TICKS);
    }

    private static boolean moved(Map<String, Object> before, Map<String, Object> after) {
        return Math.abs(number(before.get("x")) - number(after.get("x"))) > POSITION_EPSILON
                || Math.abs(number(before.get("y")) - number(after.get("y"))) > POSITION_EPSILON
                || Math.abs(number(before.get("z")) - number(after.get("z"))) > POSITION_EPSILON
                || Math.abs(number(before.get("yaw")) - number(after.get("yaw"))) > ROTATION_EPSILON
                || Math.abs(number(before.get("pitch")) - number(after.get("pitch"))) > ROTATION_EPSILON;
    }

    private static Map<String, Object> snapshot(Player player) {
        Map<String, Object> state = new LinkedHashMap<>(16);
        state.put("x", player.getX());
        state.put("y", player.getY());
        state.put("z", player.getZ());
        state.put("yaw", player.getYaw());
        state.put("pitch", player.getPitch());
        state.put("health", (double) player.getHealth());
        state.put("maxHealth", player.getMaxHealth());
        state.put("gamemode", gameModeName(player.getGamemode()));
        // Which of the nine hotbar slots the client is holding. Without this, a plugin's
        // `getItemInHand()` answers with slot 0 forever, whoever the player actually selected.
        state.put("heldSlot", player.getInventory().getHeldItemIndex());
        state.put("xpLevel", player.getExperienceLevel());
        state.put("xpProgress", progressOf(player.getExperience(), player.getExperienceLevel()));
        state.put("food", (double) player.getFoodData().getLevel());
        state.put("saturation", (double) player.getFoodData().getFoodSaturationLevel());
        state.put("effects", EffectState.snapshot(player));
        // Nukkit's own answer, read from its operator list. PocketMine turns this into the
        // operator root permission, which is what its plugins check.
        state.put("op", player.isOp());
        return state;
    }

    /** Nukkit's XP is points inside a level; the wire speaks the 0..1 fraction both clients draw. */
    static double progressOf(int exp, int level) {
        return clamp((double) exp / Math.max(1, Player.calculateRequireExperience(level)), 0.0, 1.0);
    }

    private static double clamp(double value, double min, double max) {
        return Math.max(min, Math.min(max, value));
    }

    static String gameModeName(int mode) {
        return switch (mode) {
            case Player.CREATIVE -> "creative";
            case Player.ADVENTURE -> "adventure";
            case Player.SPECTATOR -> "spectator";
            default -> "survival";
        };
    }

    static int gameModeOf(String name) {
        return switch (name.toLowerCase(Locale.ROOT)) {
            case "survival" -> Player.SURVIVAL;
            case "creative" -> Player.CREATIVE;
            case "adventure" -> Player.ADVENTURE;
            case "spectator" -> Player.SPECTATOR;
            default -> -1;
        };
    }

    /**
     * JSON numbers arrive as {@code Long} or {@code Double} depending on how they were written,
     * so every numeric field goes through here rather than a cast.
     */
    private static double number(Object value) {
        return value instanceof Number n ? n.doubleValue() : 0.0;
    }

    /**
     * JSON booleans arrive as {@code Boolean}; a hand-written frame may spell one as a string.
     * Anything else is false, which is the safe answer for "is this player an operator".
     */
    static boolean truth(Object value) {
        if (value instanceof Boolean b) {
            return b;
        }
        if (value instanceof Number n) {
            return n.intValue() != 0;
        }
        return value != null && Boolean.parseBoolean(String.valueOf(value));
    }

    private static UUID parseUuid(String value) {
        try {
            return UUID.fromString(value);
        } catch (IllegalArgumentException e) {
            return null;
        }
    }
}
