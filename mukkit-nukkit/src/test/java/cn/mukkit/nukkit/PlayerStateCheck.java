package cn.mukkit.nukkit;

import cn.nukkit.Player;

import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Offline checks for the decisions {@link PlayerStateMirror} makes about what travels on the wire.
 *
 * <p>The field reads themselves are trivial; what can silently misbehave is the grouping (a
 * teleport must carry its facing), the epsilons (float noise between two coordinate pipelines is
 * not a movement) and the game mode mapping (a wrong integer is a wrong game mode, not an error).
 * Those are all pure functions, so they are exercised without a running server.</p>
 */
public final class PlayerStateCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        // ---------------------------------------------------------------- gamemode mapping
        check("survival maps both ways",
                PlayerStateMirror.gameModeOf("survival") == Player.SURVIVAL
                        && "survival".equals(PlayerStateMirror.gameModeName(Player.SURVIVAL)));
        check("creative maps both ways",
                PlayerStateMirror.gameModeOf("creative") == Player.CREATIVE
                        && "creative".equals(PlayerStateMirror.gameModeName(Player.CREATIVE)));
        check("adventure maps both ways",
                PlayerStateMirror.gameModeOf("adventure") == Player.ADVENTURE
                        && "adventure".equals(PlayerStateMirror.gameModeName(Player.ADVENTURE)));
        check("spectator maps both ways",
                PlayerStateMirror.gameModeOf("spectator") == Player.SPECTATOR
                        && "spectator".equals(PlayerStateMirror.gameModeName(Player.SPECTATOR)));
        check("case does not matter for gamemode names",
                PlayerStateMirror.gameModeOf("CrEaTiVe") == Player.CREATIVE);
        check("an unknown gamemode is refused rather than defaulted",
                PlayerStateMirror.gameModeOf("skyblock") == -1);

        // ---------------------------------------------------------------- diff grouping
        Map<String, Object> base = state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival");

        check("an unchanged player produces no state", PlayerStateMirror.diff(base, state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival")).isEmpty());

        // Two coordinate pipelines disagree in the last decimals all the time; that is not movement.
        check("sub-millimetre jitter is not a move",
                PlayerStateMirror.diff(base, state(0.5005, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival")).isEmpty());
        check("sub-degree rotation jitter is not a turn",
                PlayerStateMirror.diff(base, state(0.5, 64.0, 0.5, 0.02, 0.0, 20.0, 20, "survival")).isEmpty());

        Map<String, Object> moved = PlayerStateMirror.diff(base, state(1.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival"));
        check("a move reports the whole position group", moved.keySet().equals(java.util.Set.of("x", "y", "z", "yaw", "pitch")));
        check("and carries the new value", number(moved.get("x")) == 1.5);

        Map<String, Object> turned = PlayerStateMirror.diff(base, state(0.5, 64.0, 0.5, 90.0, 0.0, 20.0, 20, "survival"));
        check("a pure rotation still reports position and rotation together",
                turned.keySet().equals(java.util.Set.of("x", "y", "z", "yaw", "pitch")));

        Map<String, Object> hurt = PlayerStateMirror.diff(base, state(0.5, 64.0, 0.5, 0.0, 0.0, 12.5, 20, "survival"));
        check("damage reports health alone", hurt.keySet().equals(java.util.Set.of("health")));
        check("and leaves position out of it", number(hurt.get("health")) == 12.5);

        Map<String, Object> healed = PlayerStateMirror.diff(
                state(0.5, 64.0, 0.5, 0.0, 0.0, 12.5, 20, "survival"),
                state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival"));
        check("healing is a change too", healed.keySet().equals(java.util.Set.of("health")));

        Map<String, Object> stronger = PlayerStateMirror.diff(base, state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 40, "survival"));
        check("a max-health change travels on its own", stronger.keySet().equals(java.util.Set.of("maxHealth")));

        Map<String, Object> switched = PlayerStateMirror.diff(base, state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "creative"));
        check("a gamemode change travels on its own", switched.keySet().equals(java.util.Set.of("gamemode")));

        // ---------------------------------------------------------------- hotbar selection
        Map<String, Object> hotbar = state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival");
        hotbar.put("heldSlot", 5);
        check("a hotbar change travels on its own",
                PlayerStateMirror.diff(base, hotbar).keySet().equals(java.util.Set.of("heldSlot")));
        check("and carries the slot", Math.round(number(PlayerStateMirror.diff(base, hotbar).get("heldSlot"))) == 5);
        check("the same hotbar slot is not a change", PlayerStateMirror.diff(hotbar, with(hotbar, "heldSlot", 5)).isEmpty());
        check("slot zero is a change from slot five", !PlayerStateMirror.diff(hotbar, with(hotbar, "heldSlot", 0)).isEmpty());

        // ---------------------------------------------------------------- experience and hunger
        Map<String, Object> levelled = state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival");
        levelled.put("xpLevel", 4);
        Map<String, Object> levelledBefore = state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival");
        check("an xp level travels on its own",
                PlayerStateMirror.diff(levelledBefore, levelled).keySet().equals(java.util.Set.of("xpLevel")));

        Map<String, Object> progressed = state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival");
        progressed.put("xpProgress", 0.75);
        check("xp progress travels on its own",
                PlayerStateMirror.diff(levelledBefore, progressed).keySet().equals(java.util.Set.of("xpProgress")));
        check("sub-thousandth progress jitter is not a change",
                PlayerStateMirror.diff(progressed, with(progressed, "xpProgress", 0.7505)).isEmpty());

        Map<String, Object> fed = state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival");
        fed.put("food", 12.0);
        check("food travels on its own",
                PlayerStateMirror.diff(levelledBefore, fed).keySet().equals(java.util.Set.of("food")));

        Map<String, Object> saturated = state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival");
        saturated.put("saturation", 1.5);
        check("saturation travels on its own",
                PlayerStateMirror.diff(levelledBefore, saturated).keySet().equals(java.util.Set.of("saturation")));

        // ---------------------------------------------------------------- effects
        List<Object> speed = List.of(1, 600, 0, true, false);
        Map<String, Object> hasted = state(0.5, 64.0, 0.5, 0.0, 0.0, 20.0, 20, "survival");
        hasted.put("effects", List.of(speed));
        check("an effect appearing travels as a whole list",
                PlayerStateMirror.diff(levelledBefore, hasted).keySet().equals(java.util.Set.of("effects")));
        check("the same effect list is not a change",
                PlayerStateMirror.diff(hasted, with(hasted, "effects", List.of(List.of(1, 600, 0, true, false)))).isEmpty());
        check("an amplifier change is a change",
                !PlayerStateMirror.diff(hasted, with(hasted, "effects",
                        List.of(List.of(1, 600, 1, true, false)))).isEmpty());
        check("a duration change inside the same second is not a change",
                PlayerStateMirror.diff(hasted, with(hasted, "effects",
                        List.of(List.of(1, 605, 0, true, false)))).isEmpty());
        check("a duration change across a second is a change",
                !PlayerStateMirror.diff(hasted, with(hasted, "effects",
                        List.of(List.of(1, 580, 0, true, false)))).isEmpty());
        check("an effect expiring is a change",
                !PlayerStateMirror.diff(hasted, with(hasted, "effects", List.of())).isEmpty());

        // Nukkit stores points, the wire carries the fraction the client draws.
        check("xp progress is points over the level's requirement", PlayerStateMirror.progressOf(0, 0) == 0.0);
        check("a full bar is 1.0", PlayerStateMirror.progressOf(Player.calculateRequireExperience(0), 0) == 1.0);
        check("an overfull bar is clamped", PlayerStateMirror.progressOf(9999, 0) == 1.0);

        // PHP writes 7.0 where the JVM may read a Long; both must compare equal to themselves.
        Map<String, Object> integral = state(0.5, 64.0, 0.5, 0.0, 0.0, 7L, 20L, "survival");
        check("integers from JSON compare as numbers, not as types",
                PlayerStateMirror.diff(integral, state(0.5, 64.0, 0.5, 0.0, 0.0, 7.0, 20.0, "survival")).isEmpty());

        System.out.println("player-state-jvm " + (failures == 0 ? "PASS" : "FAILED")
                + " (" + checks + " checks)");
        if (failures > 0) {
            System.exit(1);
        }
    }

    private static Map<String, Object> state(double x, double y, double z, double yaw, double pitch,
                                             Number health, Number maxHealth, String gamemode) {
        Map<String, Object> state = new LinkedHashMap<>(16);
        state.put("x", x);
        state.put("y", y);
        state.put("z", z);
        state.put("yaw", yaw);
        state.put("pitch", pitch);
        state.put("health", health);
        state.put("maxHealth", maxHealth);
        state.put("gamemode", gamemode);
        state.put("heldSlot", 0);
        state.put("xpLevel", 0);
        state.put("xpProgress", 0.0);
        state.put("food", 20.0);
        state.put("saturation", 5.0);
        state.put("effects", List.of());
        // Operator status is part of a snapshot; OpsCheck is where its behaviour is pinned.
        state.put("op", false);
        return state;
    }

    private static Map<String, Object> with(Map<String, Object> source, String key, Object value) {
        Map<String, Object> copy = new LinkedHashMap<>(source);
        copy.put(key, value);
        return copy;
    }

    private static double number(Object value) {
        return value instanceof Number n ? n.doubleValue() : 0.0;
    }

    private static void check(String label, boolean ok) {
        checks++;
        if (ok) {
            System.out.println("ok    " + label);
        } else {
            failures++;
            System.out.println("FAIL  " + label);
        }
    }

    private PlayerStateCheck() {
    }
}
