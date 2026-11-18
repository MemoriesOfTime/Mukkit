package cn.mukkit.nukkit;

import cn.nukkit.block.Block;
import cn.nukkit.inventory.InventoryType;
import cn.nukkit.item.Item;

import java.util.ArrayList;
import java.util.List;

/**
 * Offline checks for the Nukkit inventory that stands in for a PocketMine window.
 *
 * <p>The interesting part is not "a map holds items"; it is that a chest starts at 27 slots and a
 * double chest's content packet can grow it to 54 without re-creating the window. Getting that
 * wrong drops every slot past 26 on the floor - silently, because `setItem` on a missing slot is
 * an air write, not an exception.</p>
 *
 * <p>`Block.init()` is what makes this runnable without a server: `BaseInventory` builds one air
 * block in its constructor, and Nukkit's item palette is not populated until the block registry
 * is. No player and no client are involved; `onOpen`/`onClose` are the part only a real client can
 * judge.</p>
 */
public final class ContainerInventoryCheck {

    private static int checks;
    private static int failures;

    private ContainerInventoryCheck() {
    }

    public static void main(String[] args) {
        Block.init();

        BridgedWindowInventory inventory = new BridgedWindowInventory(
                null,
                InventoryType.CHEST,
                7,
                0,
                1,
                64,
                2,
                -1
        );

        check("a chest starts with 27 slots", inventory.getSize() == 27);
        check("the PocketMine window id is kept", inventory.bridgeWindowId() == 7);

        List<Item> single = new ArrayList<>();
        for (int slot = 0; slot < 27; slot++) {
            single.add(Item.AIR_ITEM.clone());
        }
        single.set(3, Item.get(Item.DIAMOND, 0, 5));
        inventory.replaceContents(single);
        check("a slot keeps its item", inventory.getItem(3).getId() == Item.DIAMOND);
        check("a slot keeps its count", inventory.getItem(3).getCount() == 5);
        check("an untouched slot is air", inventory.getItem(4).isNull());

        // A double chest announces 54 slots in the first content packet.
        inventory.resize(54);
        check("a content packet can grow the window to 54", inventory.getSize() == 54);

        List<Item> doubled = new ArrayList<>();
        for (int slot = 0; slot < 54; slot++) {
            doubled.add(Item.AIR_ITEM.clone());
        }
        doubled.set(3, Item.get(Item.DIAMOND, 0, 5));
        doubled.set(53, Item.get(Item.EMERALD, 0, 2));
        inventory.replaceContents(doubled);
        check("the last slot of a double chest is addressable", inventory.getItem(53).getId() == Item.EMERALD);
        check("the last slot keeps its count", inventory.getItem(53).getCount() == 2);
        check("a slot below the old size is still right", inventory.getItem(3).getId() == Item.DIAMOND);

        System.out.println();
        if (failures > 0) {
            System.out.println("container-inventory FAIL  (" + failures + " of " + checks + " checks)");
            System.exit(1);
        }
        System.out.println("container-inventory PASS  (" + checks + " checks)");
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
}
