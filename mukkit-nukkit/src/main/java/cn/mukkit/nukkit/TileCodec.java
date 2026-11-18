package cn.mukkit.nukkit;

import cn.nukkit.blockentity.BlockEntity;
import cn.nukkit.blockentity.BlockEntityChest;
import cn.nukkit.blockentity.BlockEntityFurnace;
import cn.nukkit.blockentity.BlockEntityNameable;
import cn.nukkit.blockentity.BlockEntitySign;
import cn.nukkit.inventory.ContainerInventory;
import cn.nukkit.inventory.InventoryHolder;
import cn.nukkit.item.Item;
import cn.nukkit.nbt.tag.CompoundTag;

import java.util.ArrayList;
import java.util.Comparator;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

/**
 * The bridge dialect for block entities.
 *
 * <p>A tile travels as a small self-describing record: position, the save id both servers use
 * (`Chest`, `Sign`, `Furnace`, ...), and the fields that make the tile useful to a plugin. It is
 * deliberately not Nukkit's NBT: Nukkit item stacks are numeric-id compounds, PocketMine's are
 * flattened-name compounds, and shipping either one would force the other side to grow a second
 * item parser. Reusing the existing item tuple keeps one item vocabulary in the whole bridge.</p>
 *
 * <p>Only types PocketMine can represent are encoded. A dispenser holding items is a real block
 * entity here, but PM has no `Dispenser` tile class, so pretending it was a chest would put the
 * wrong class behind `getTile()` - a silent lie is worse than a documented null.</p>
 */
final class TileCodec {

    private TileCodec() {
    }

    /**
     * Save ids whose PocketMine counterpart exists. `MobSpawner` is absent on purpose: Nukkit
     * stores the spawn type as a network entity id and PM wants an identifier string, and a
     * wrong mapping would spawn the wrong mob rather than report a gap.
     */
    private static final Set<String> SUPPORTED = Set.of(
            "Chest", "Barrel", "ShulkerBox", "Hopper",
            "Furnace", "BlastFurnace", "Smoker", "BrewingStand",
            "Sign"
    );

    static boolean supported(BlockEntity blockEntity) {
        String id = blockEntity.getSaveId();
        return id != null && SUPPORTED.contains(id);
    }

    /**
     * One tile in bridge form, or null when the host has no way to represent it.
     *
     * <p>Insertion order is fixed because the payload's string form doubles as the change
     * fingerprint on this side: two encodes of the same state must compare equal.</p>
     */
    static Map<String, Object> encode(BlockEntity blockEntity) {
        if (!supported(blockEntity)) {
            return null;
        }
        String type = blockEntity.getSaveId();
        Map<String, Object> payload = new LinkedHashMap<>();
        payload.put("x", blockEntity.getFloorX());
        payload.put("y", blockEntity.getFloorY());
        payload.put("z", blockEntity.getFloorZ());
        payload.put("type", type);

        if (blockEntity instanceof BlockEntityNameable nameable && nameable.hasName()) {
            payload.put("name", nameable.getName());
        }

        if (blockEntity instanceof BlockEntitySign sign) {
            addSide(payload, "front", sign.namedTag.getCompound(BlockEntitySign.TAG_FRONT_TEXT));
            addSide(payload, "back", sign.namedTag.getCompound(BlockEntitySign.TAG_BACK_TEXT));
        } else {
            ContainerInventory inventory = containerInventory(blockEntity);
            if (inventory != null) {
                payload.put("items", itemsOf(inventory));
                if (blockEntity instanceof BlockEntityFurnace furnace) {
                    payload.put("burn", furnace.getBurnTime());
                    payload.put("cook", furnace.getCookTime());
                    payload.put("max", furnace.getMaxTime());
                }
            }
        }

        return payload;
    }

    /**
     * The inventory one block entity owns, or null for a tile that holds none.
     *
     * <p>A paired chest is the reason this exists: {@link BlockEntityChest#getInventory()} returns
     * the 54-slot {@code DoubleChestInventory}, so encoding a paired chest through it would report
     * both halves as one tile, and writing would clear the neighbour's 27 slots. The per-block
     * inventory is {@code getRealInventory()}.</p>
     */
    static ContainerInventory containerInventory(BlockEntity blockEntity) {
        if (blockEntity instanceof BlockEntityChest chest) {
            return chest.getRealInventory();
        }
        if (blockEntity instanceof InventoryHolder holder
                && holder.getInventory() instanceof ContainerInventory inventory) {
            return inventory;
        }
        return null;
    }

    /** Slots in order, each `[slot, id, count, damage, nbt?]` in the shared item dialect. */
    private static List<List<Object>> itemsOf(ContainerInventory inventory) {
        List<List<Object>> items = new ArrayList<>();
        for (Map.Entry<Integer, Item> entry : inventory.getContents().entrySet()) {
            Item item = entry.getValue();
            if (item == null || item.isNull()) {
                continue;
            }
            List<Object> tuple = new ArrayList<>(5);
            tuple.add(entry.getKey());
            tuple.addAll(InventoryMirror.itemTuple(item));
            items.add(tuple);
        }
        items.sort(Comparator.comparingInt(tuple -> ((Number) tuple.get(0)).intValue()));
        return items;
    }

    private static void addSide(Map<String, Object> payload, String prefix, CompoundTag tag) {
        payload.put(prefix, linesOf(tag.getString(BlockEntitySign.TAG_TEXT_BLOB)));
        payload.put(prefix + "Color", tag.contains(BlockEntitySign.TAG_TEXT_COLOR)
                ? tag.getInt(BlockEntitySign.TAG_TEXT_COLOR)
                : -16777216);
        payload.put(prefix + "Glow", tag.getBoolean(BlockEntitySign.TAG_GLOWING_TEXT));
    }

    /**
     * A sign always has four lines. Nukkit stores them as one newline-joined blob and omits
     * trailing empty lines, so padding here keeps the payload shape stable instead of making
     * "line 3 is empty" and "there is no line 3" two different states on the wire.
     */
    private static List<String> linesOf(String blob) {
        List<String> lines = new ArrayList<>(4);
        String[] split = blob.split("\n", 4);
        for (int i = 0; i < 4; i++) {
            lines.add(i < split.length ? split[i] : "");
        }
        return lines;
    }
}
