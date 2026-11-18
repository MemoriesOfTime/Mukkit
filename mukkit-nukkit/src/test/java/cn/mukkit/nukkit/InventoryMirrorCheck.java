package cn.mukkit.nukkit;

import java.util.List;

/**
 * Offline checks for the decisions {@link InventoryMirror} makes about what travels on the wire.
 *
 * <p>The slot mapping is the part that fails silently: sending the helmet to slot 0 would look
 * like a working mirror until a player put it on, and the two sides would disagree about which
 * item is where without either reporting anything. The fingerprint is the other one - it decides
 * when an item is "changed", so getting it wrong means either a missing update or an op every
 * tick.</p>
 */
public final class InventoryMirrorCheck {

    private static int checks;
    private static int failures;

    public static void main(String[] args) {
        // ---------------------------------------------------------------- slot mapping
        check("slot 0 is the first inventory slot",
                InventoryMirror.kindOf(0) == InventoryMirror.SlotKind.INVENTORY);
        check("slot 35 is the last inventory slot",
                InventoryMirror.kindOf(35) == InventoryMirror.SlotKind.INVENTORY);
        check("slot 36 is the helmet",
                InventoryMirror.kindOf(36) == InventoryMirror.SlotKind.ARMOR);
        check("slot 39 is the boots",
                InventoryMirror.kindOf(39) == InventoryMirror.SlotKind.ARMOR);
        check("slot 40 is the offhand",
                InventoryMirror.kindOf(40) == InventoryMirror.SlotKind.OFFHAND);
        check("a negative slot is not addressable",
                InventoryMirror.kindOf(-1) == InventoryMirror.SlotKind.INVALID);
        check("slot 41 does not exist",
                InventoryMirror.kindOf(41) == InventoryMirror.SlotKind.INVALID);
        check("a far-off slot does not exist",
                InventoryMirror.kindOf(999) == InventoryMirror.SlotKind.INVALID);
        check("validSlot agrees with kindOf",
                InventoryMirror.validSlot(0) && InventoryMirror.validSlot(40)
                        && !InventoryMirror.validSlot(41) && !InventoryMirror.validSlot(-1));

        // ---------------------------------------------------------------- fingerprints
        check("nothing is air",
                "air".equals(InventoryMirror.fingerprint("minecraft:air", 0, 0, null)));
        check("an air item with a count is still air",
                "air".equals(InventoryMirror.fingerprint("minecraft:air", 5, 0, null)));
        check("an empty name is air",
                "air".equals(InventoryMirror.fingerprint("", 1, 0, null)));

        String diamond = InventoryMirror.fingerprint("minecraft:diamond", 3, 0, null);
        check("the same item produces the same fingerprint",
                diamond.equals(InventoryMirror.fingerprint("minecraft:diamond", 3, 0, null)));
        check("a different count is a different item",
                !diamond.equals(InventoryMirror.fingerprint("minecraft:diamond", 4, 0, null)));
        check("a different name is a different item",
                !diamond.equals(InventoryMirror.fingerprint("minecraft:emerald", 3, 0, null)));
        check("different durability is a different item",
                !diamond.equals(InventoryMirror.fingerprint("minecraft:diamond", 3, 7, null)));
        check("different NBT is a different item",
                !diamond.equals(InventoryMirror.fingerprint("minecraft:diamond", 3, 0, "AAAA")));
        check("NBT travels in the fingerprint",
                InventoryMirror.fingerprint("minecraft:diamond", 3, 0, "AAAA")
                        .contains("AAAA"));

        // ---------------------------------------------------------------- empty tuple
        List<Object> air = InventoryMirror.airTuple(40);
        check("an empty tuple has four fields", air.size() == 4);
        check("an empty tuple names air", "minecraft:air".equals(air.get(1)));
        check("an empty tuple has count 0", Integer.valueOf(0).equals(air.get(2)));
        check("an empty tuple keeps its slot", Integer.valueOf(40).equals(air.get(0)));

        System.out.println("inventory-jvm " + (failures == 0 ? "PASS" : "FAILED")
                + " (" + checks + " checks)");
        if (failures > 0) {
            System.exit(1);
        }
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

    private InventoryMirrorCheck() {
    }
}
