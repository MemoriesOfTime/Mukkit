package cn.mukkit.nukkit;

import java.util.ArrayList;
import java.util.List;
import java.util.Map;

/**
 * Offline checks for {@link EntityMirror}'s change detection.
 *
 * <p>Snapshots are only sent when they differ from the last one, so the fingerprint decides two
 * things at once: whether plugins ever hear about a mob, and whether a field of stationary mobs
 * costs bandwidth every second. Both failures are silent - a missing update looks like a mob that
 * does not exist, a spurious one looks like a server doing work for nothing - so the fields are
 * pinned here.</p>
 *
 * <p>The extras map is the part that recently grew a second shape: `hp` for anything living,
 * `item` for a dropped stack. A change of either has to wake the snapshot up, and the two must not
 * be confused for one another.</p>
 */
public final class EntityMirrorCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        List<Object> zombie = tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0,
                Map.of("hp", 20.0));

        check("an identical snapshot has the same fingerprint",
                EntityMirror.fingerprint(zombie).equals(EntityMirror.fingerprint(
                        tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0, Map.of("hp", 20.0)))));
        check("a different id is a different entity",
                !EntityMirror.fingerprint(zombie).equals(EntityMirror.fingerprint(
                        tuple(701L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0, Map.of("hp", 20.0)))));
        check("a different type is a different entity",
                !EntityMirror.fingerprint(zombie).equals(EntityMirror.fingerprint(
                        tuple(700L, "minecraft:zombie_villager", 10.0, 64.0, 10.0, 90.0, 0.0, Map.of("hp", 20.0)))));
        check("movement is a change",
                !EntityMirror.fingerprint(zombie).equals(EntityMirror.fingerprint(
                        tuple(700L, "minecraft:zombie", 10.5, 64.0, 10.0, 90.0, 0.0, Map.of("hp", 20.0)))));
        check("turning is a change",
                !EntityMirror.fingerprint(zombie).equals(EntityMirror.fingerprint(
                        tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 45.0, 0.0, Map.of("hp", 20.0)))));
        check("damage is a change",
                !EntityMirror.fingerprint(zombie).equals(EntityMirror.fingerprint(
                        tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0, Map.of("hp", 18.0)))));

        // Name tags and potion effects are extras too; each one alone has to wake the snapshot,
        // and an identical extras map must not.
        Map<String, Object> named = Map.of("hp", 20.0, "name", "Bob");
        check("a name tag changes the fingerprint",
                !EntityMirror.fingerprint(zombie).equals(EntityMirror.fingerprint(
                        tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0, named))));
        check("the same name tag does not",
                EntityMirror.fingerprint(tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0, named))
                        .equals(EntityMirror.fingerprint(
                                tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0,
                                        Map.of("hp", 20.0, "name", "Bob")))));

        List<Object> speed = List.of(1, 600, 0, true, false);
        Map<String, Object> hasted = Map.of("hp", 20.0, "effects", List.of(speed));
        check("an effect changes the fingerprint",
                !EntityMirror.fingerprint(zombie).equals(EntityMirror.fingerprint(
                        tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0, hasted))));
        check("a different amplifier is a different fingerprint",
                !EntityMirror.fingerprint(tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0, hasted))
                        .equals(EntityMirror.fingerprint(
                                tuple(700L, "minecraft:zombie", 10.0, 64.0, 10.0, 90.0, 0.0,
                                        Map.of("hp", 20.0, "effects", List.of(List.of(1, 600, 1, true, false)))))));

        // A non-living entity has no extras at all; carrying one must be visible.
        List<Object> arrow = tuple(702L, "minecraft:arrow", 1.0, 65.0, 1.0, 0.0, 0.0, Map.of());
        check("a bare projectile has no extras", arrow.size() == 7);
        check("health appearing is a change",
                !EntityMirror.fingerprint(arrow).equals(EntityMirror.fingerprint(
                        tuple(702L, "minecraft:arrow", 1.0, 65.0, 1.0, 0.0, 0.0, Map.of("hp", 1.0)))));

        // Dropped stacks: the item tuple is the inventory dialect, nested in the extras map.
        Map<String, Object> diamond = Map.of("item", List.of("minecraft:diamond", 2, 0));
        List<Object> dropped = tuple(704L, "minecraft:item", 3.0, 64.0, 3.0, 0.0, 0.0, diamond);
        check("the same stack has the same fingerprint",
                EntityMirror.fingerprint(dropped).equals(EntityMirror.fingerprint(
                        tuple(704L, "minecraft:item", 3.0, 64.0, 3.0, 0.0, 0.0, diamond))));
        check("a different stack size is a change",
                !EntityMirror.fingerprint(dropped).equals(EntityMirror.fingerprint(
                        tuple(704L, "minecraft:item", 3.0, 64.0, 3.0, 0.0, 0.0,
                                Map.of("item", List.of("minecraft:diamond", 3, 0))))));
        check("a different item is a change",
                !EntityMirror.fingerprint(dropped).equals(EntityMirror.fingerprint(
                        tuple(704L, "minecraft:item", 3.0, 64.0, 3.0, 0.0, 0.0,
                                Map.of("item", List.of("minecraft:emerald", 2, 0))))));
        check("an item appears where there was none",
                !EntityMirror.fingerprint(tuple(704L, "minecraft:item", 3.0, 64.0, 3.0, 0.0, 0.0, Map.of()))
                        .equals(EntityMirror.fingerprint(dropped)));
        check("health and item are different extras",
                !EntityMirror.fingerprint(dropped).equals(EntityMirror.fingerprint(
                        tuple(704L, "minecraft:item", 3.0, 64.0, 3.0, 0.0, 0.0, Map.of("hp", 2.0)))));

        System.out.println("entity-mirror " + (failures == 0 ? "PASS" : "FAILED")
                + " (" + checks + " checks)");
        if (failures > 0) {
            System.exit(1);
        }
    }

    private static List<Object> tuple(long id, String type, double x, double y, double z,
                                      double yaw, double pitch, Map<String, Object> extra) {
        List<Object> tuple = new ArrayList<>(8);
        tuple.add(id);
        tuple.add(type);
        tuple.add(x);
        tuple.add(y);
        tuple.add(z);
        tuple.add(yaw);
        tuple.add(pitch);
        if (!extra.isEmpty()) {
            tuple.add(extra);
        }
        return tuple;
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

    private EntityMirrorCheck() {
    }
}
