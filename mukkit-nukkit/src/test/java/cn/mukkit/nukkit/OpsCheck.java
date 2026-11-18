package cn.mukkit.nukkit;

import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * Offline checks for the operator half of {@link PlayerStateMirror}.
 *
 * <p>Operator status is one boolean that decides whether a plugin's `default: op` permissions
 * are granted, so both failure modes are silent in opposite ways: a change that is never sent
 * leaves the PocketMine side refusing commands for an operator, and a change that is sent every
 * tick would rewrite Nukkit's `ops.txt` ten times a second. The grouping is pinned here too -
 * an operator change must not look like a teleport, or a plugin tracking movement sees one.</p>
 */
public final class OpsCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        Map<String, Object> plain = state(false);
        Map<String, Object> operator = state(true);

        check("an identical state is not a change", PlayerStateMirror.diff(plain, state(false)).isEmpty());
        check("an operator change travels on its own",
                PlayerStateMirror.diff(plain, operator).keySet().equals(java.util.Set.of("op")));
        check("the operator change is a boolean on the wire",
                Boolean.TRUE.equals(PlayerStateMirror.diff(plain, operator).get("op")));
        check("and travelling the other way too",
                Boolean.FALSE.equals(PlayerStateMirror.diff(operator, plain).get("op")));
        check("an operator change does not drag position along",
                !PlayerStateMirror.diff(plain, operator).containsKey("x"));
        check("an operator change does not drag health along",
                !PlayerStateMirror.diff(plain, operator).containsKey("health"));

        // A state frame is JSON: a hand-written one may spell the flag several ways, and getting
        // "true" wrong would silently de-op a player.
        check("a real boolean is read as itself", PlayerStateMirror.truth(Boolean.TRUE));
        check("a JSON string is read", PlayerStateMirror.truth("true"));
        check("a JSON number is read", PlayerStateMirror.truth(1L));
        check("false stays false", !PlayerStateMirror.truth(Boolean.FALSE) && !PlayerStateMirror.truth("false"));
        check("a missing flag is not an operator", !PlayerStateMirror.truth(null));

        System.out.println("ops-jvm " + (failures == 0 ? "PASS" : "FAILED")
                + " (" + checks + " checks)");
        if (failures > 0) {
            System.exit(1);
        }
    }

    /** Every field {@link PlayerStateMirror#diff} looks at, with the operator bit as a knob. */
    private static Map<String, Object> state(boolean operator) {
        Map<String, Object> state = new LinkedHashMap<>(16);
        state.put("x", 0.5);
        state.put("y", 64.0);
        state.put("z", 0.5);
        state.put("yaw", 0.0);
        state.put("pitch", 0.0);
        state.put("health", 20.0);
        state.put("maxHealth", 20);
        state.put("gamemode", "survival");
        state.put("xpLevel", 0);
        state.put("xpProgress", 0.0);
        state.put("food", 20.0);
        state.put("saturation", 5.0);
        state.put("effects", List.of());
        state.put("op", operator);
        return state;
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

    private OpsCheck() {
    }
}
