package cn.mukkit.nukkit;

import cn.nukkit.Player;
import cn.nukkit.Server;
import cn.nukkit.blockentity.BlockEntity;
import cn.nukkit.event.inventory.InventoryTransactionEvent;
import cn.nukkit.inventory.Inventory;
import cn.nukkit.inventory.InventoryHolder;
import cn.nukkit.inventory.InventoryType;
import cn.nukkit.inventory.transaction.action.InventoryAction;
import cn.nukkit.inventory.transaction.action.SlotChangeAction;
import cn.nukkit.item.Item;
import cn.nukkit.level.Level;
import cn.nukkit.math.Vector3;
import cn.nukkit.network.protocol.ContainerSetDataPacket;

import java.util.ArrayList;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Queue;
import java.util.Set;
import java.util.UUID;
import java.util.function.BooleanSupplier;

/**
 * Nukkit's side of a PocketMine container window.
 *
 * <p>There are two kinds. A PM plugin's own chest ({@code new ChestInventory(...)}, a menu, a
 * shop) is not a Nukkit block entity: the adapter builds a {@link BridgedWindowInventory} with
 * the same window id and lets Nukkit route the client's clicks into it, then forwards the
 * resulting slot changes to the host. A window anchored to a real chest, furnace or hopper is
 * bound to that block entity's inventory instead: the level owns the contents, the client's
 * moves land there, and the tile mirror carries them to PM - which is what makes a chest GUI
 * work even when no plugin listens to {@code InventoryTransactionEvent}.</p>
 *
 * <p>Content flows the other way as full snapshots for the bridged kind only. A container has no
 * per-slot mirror: PM only announces a change when the plugin made one, and a snapshot is the
 * only thing that also clears slots the plugin removed. A bound window ignores those snapshots
 * because its block entity is the authority and PM's copy is one tick behind it.</p>
 *
 * <p>Only window ids 1-99 are containers. The player's own inventory (0), offhand (119), armour
 * (120) and the UI space (124) are covered by {@link InventoryMirror} and never appear here.</p>
 */
final class ContainerMirror {

    private static final int FIRST_WINDOW = 1;
    private static final int LAST_WINDOW = 99;

    private final Server server;
    private final Queue<Map<String, Object>> inbound;
    private final BooleanSupplier enabled;

    /**
     * One window a host plugin opened for a player.
     *
     * <p>`bridged` says whether the inventory is a {@link BridgedWindowInventory} we built (PM's
     * own container) or the real block entity's inventory. The distinction matters for content:
     * a bridged window is fed by the host's snapshots, while a real block entity is the
     * authority and the host's snapshots for it are echoes of the tile mirror.</p>
     */
    private record Window(Inventory inventory, boolean bridged) {
    }

    /** Player uuid -> PocketMine window id -> the Nukkit inventory standing in for it. */
    private final Map<UUID, Map<Integer, Window>> windows = new LinkedHashMap<>();

    private final Set<String> reported = new HashSet<>();

    ContainerMirror(Server server, Queue<Map<String, Object>> inbound, BooleanSupplier enabled) {
        this.server = server;
        this.inbound = inbound;
        this.enabled = enabled;
    }

    /** A player left: Nukkit closes their windows, and the map must not keep a ghost. */
    void forget(UUID id) {
        if (id == null) {
            return;
        }
        Map<Integer, Window> owned = windows.remove(id);
        Player player = server.getOnlinePlayers().get(id);
        if (owned == null) {
            return;
        }
        if (player != null) {
            for (Window window : new ArrayList<>(owned.values())) {
                player.removeWindow(window.inventory());
            }
        }
    }

    /**
     * The host restarted with empty plugin state: every window it had opened is gone, so the
     * stand-ins must go too. On the next join/open the host sends a fresh window.
     */
    void forgetAll() {
        for (UUID id : new ArrayList<>(windows.keySet())) {
            forget(id);
        }
        windows.clear();
        reported.clear();
    }

    void open(Map<String, Object> op) {
        if (!enabled.getAsBoolean()) {
            return;
        }
        Player player = player(op);
        int windowId = intOf(op.get("window"), -1);
        if (player == null || !validWindow(windowId)) {
            return;
        }

        // A PM plugin opening a second window means the first one is gone; InventoryManager sends
        // a close, but an adapter that starts after that frame must still not stack windows.
        Map<Integer, Window> owned =
                windows.computeIfAbsent(player.getUniqueId(), ignored -> new LinkedHashMap<>());
        for (Window old : new ArrayList<>(owned.values())) {
            player.removeWindow(old.inventory());
        }
        owned.clear();

        int networkType = intOf(op.get("type"), 0);
        InventoryType type = InventoryType.from(networkType);
        if (type == null) {
            // Nukkit has no Java inventory for this window kind. Chest is the least surprising
            // stand-in: it has 27 slots and Nukkit routes clicks to it. The client still draws
            // whatever `networkType` says.
            type = InventoryType.CHEST;
        }

        // A window anchored to a real chest/furnace/hopper belongs to the block entity, not to a
        // stand-in. Binding makes the client's item moves land on the level's own inventory,
        // which is what lets PM's tile mirror see them even when no plugin listens to
        // `InventoryTransactionEvent`.
        Inventory real = bindable(player, networkType, intOf(op.get("x"), 0), intOf(op.get("y"), 0), intOf(op.get("z"), 0));
        if (real != null) {
            int registered = player.addWindow(real);
            if (registered < 0) {
                report("window " + windowId + " refused: Nukkit already has a window open");
                return;
            }
            owned.put(windowId, new Window(real, false));
            System.out.println("[mukkit] window " + windowId + " (nukkit " + registered
                    + ") bound to the block entity at " + intOf(op.get("x"), 0) + ","
                    + intOf(op.get("y"), 0) + "," + intOf(op.get("z"), 0) + " for " + player.getName());
            return;
        }

        BridgedWindowInventory inventory = new BridgedWindowInventory(
                player,
                type,
                windowId,
                networkType,
                intOf(op.get("x"), 0),
                intOf(op.get("y"), 0),
                intOf(op.get("z"), 0),
                intOf(op.get("actor"), -1)
        );
        int registered = player.addWindow(inventory);
        if (registered < 0) {
            report("window " + windowId + " refused: Nukkit already has a window open");
            return;
        }
        owned.put(windowId, new Window(inventory, true));
        System.out.println("[mukkit] window " + windowId + " (nukkit " + registered + ") opened ("
                + networkType + ", " + inventory.getSize() + " slots) for " + player.getName());
    }

    /**
     * The real inventory a window points at, or null when there is nothing to bind.
     *
     * <p>Matches the Java inventory type against the Bedrock window type: a PM chest window on a
     * position that holds a real chest binds, a chest window on empty air (a menu at 0,64,0)
     * does not. Without that check every virtual menu would silently attach itself to whatever
     * block happened to share its anchor.</p>
     */
    private static Inventory bindable(Player player, int networkType, int x, int y, int z) {
        Level level = player.getLevel();
        InventoryType wanted = InventoryType.from(networkType);
        if (level == null || wanted == null) {
            return null;
        }
        BlockEntity blockEntity = level.getBlockEntityIfLoaded(new Vector3(x, y, z));
        if (!(blockEntity instanceof InventoryHolder holder)) {
            return null;
        }
        Inventory inventory = holder.getInventory();
        return inventory != null && inventory.getType() == wanted ? inventory : null;
    }

    void content(Map<String, Object> op) {
        Player player = player(op);
        int windowId = intOf(op.get("window"), -1);
        Window window = window(player, windowId);
        if (window == null) {
            return;
        }
        if (!window.bridged()) {
            // The block entity is the authority for a bound window. PM's snapshot is its own
            // mirror of that tile, and applying it here would overwrite newer contents the tile
            // mirror has not delivered yet - an item a player just took would come back.
            return;
        }
        BridgedWindowInventory inventory = (BridgedWindowInventory) window.inventory();

        int size = Math.max(1, intOf(op.get("size"), inventory.getSize()));
        inventory.resize(size);
        List<Item> items = new ArrayList<>(size);
        for (int slot = 0; slot < size; slot++) {
            items.add(Item.AIR_ITEM.clone());
        }
        for (Object entry : list(op.get("slots"))) {
            if (!(entry instanceof List<?> tuple) || tuple.size() < 3) {
                continue;
            }
            int slot = intOf(tuple.get(0), -1);
            if (slot < 0 || slot >= size) {
                continue;
            }
            Item item = itemOf(tuple, 1);
            if (item != null) {
                items.set(slot, item);
            }
        }
        inventory.replaceContents(items);
        inventory.sendContents(player);
    }

    void slot(Map<String, Object> op) {
        Player player = player(op);
        int windowId = intOf(op.get("window"), -1);
        Window window = window(player, windowId);
        if (window == null || !window.bridged()) {
            // Same reason as `content()`: a bound window's contents come from the block entity,
            // and the host's single-slot write is only its view of a change the tile mirror or
            // the player already made here.
            return;
        }
        BridgedWindowInventory inventory = (BridgedWindowInventory) window.inventory();
        if (!(op.get("slot") instanceof List<?> tuple) || tuple.size() < 3) {
            return;
        }
        int slot = intOf(tuple.get(0), -1);
        if (slot < 0 || slot >= inventory.getSize()) {
            return;
        }
        Item item = itemOf(tuple, 1);
        if (item != null) {
            inventory.setItem(slot, item, true);
        }
    }

    void close(Map<String, Object> op) {
        Player player = player(op);
        int windowId = intOf(op.get("window"), -1);
        Window window = window(player, windowId);
        if (window == null) {
            return;
        }
        Map<Integer, Window> owned = windows.get(player.getUniqueId());
        if (owned != null) {
            owned.remove(windowId);
        }
        player.removeWindow(window.inventory());
        System.out.println("[mukkit] window " + windowId + " closed for " + player.getName());
    }

    void data(Map<String, Object> op) {
        Player player = player(op);
        int windowId = intOf(op.get("window"), -1);
        Window window = window(player, windowId);
        if (window == null || !window.bridged()) {
            // Nukkit's own furnace/brewing stand already pushes its progress for a bound window;
            // PM's copy is a view of the same tile and would only race it.
            return;
        }
        ContainerSetDataPacket packet = new ContainerSetDataPacket();
        packet.windowId = windowId;
        packet.property = intOf(op.get("property"), 0);
        packet.value = intOf(op.get("value"), 0);
        player.dataPacket(packet);
    }

    /**
     * Turns a Nukkit transaction into the frame the host builds a PocketMine event from.
     *
     * <p>Only transactions that touch a bridged window are captured. A player moving items inside
     * their own inventory stays on the existing {@code player.inventory} mirror; forwarding it
     * here as well would create a second, subtly different event stream for the same change.</p>
     *
     * <p>Returns {@code null} when there is nothing to ask about, so the caller can leave the
     * transaction alone.</p>
     *
     * @return the {@code data} payload for an {@code inventory.transaction} frame, or null
     */
    Map<String, Object> capture(InventoryTransactionEvent event) {
        if (!enabled.getAsBoolean()) {
            return null;
        }
        Player player = event.getTransaction().getSource();
        UUID id = player.getUniqueId();
        Map<Integer, Window> owned = id == null ? null : windows.get(id);
        if (owned == null || owned.isEmpty()) {
            return null;
        }

        Inventory window = null;
        int windowId = -1;
        for (InventoryAction action : event.getTransaction().getActionList()) {
            if (!(action instanceof SlotChangeAction change)) {
                continue;
            }
            for (Map.Entry<Integer, Window> entry : owned.entrySet()) {
                if (entry.getValue().inventory() == change.getInventory()) {
                    window = entry.getValue().inventory();
                    windowId = entry.getKey();
                    break;
                }
            }
            if (window != null) {
                break;
            }
        }
        if (window == null) {
            return null;
        }

        List<Map<String, Object>> actions = new ArrayList<>();
        for (InventoryAction action : event.getTransaction().getActionList()) {
            if (!(action instanceof SlotChangeAction change)) {
                // Crafting/enchanting actions have no slot to replay. A container transaction
                // should not contain them; leaving them out is visible in the event's action
                // count rather than silently inventing a slot.
                continue;
            }
            Inventory inventory = change.getInventory();
            String kind;
            int slot;
            if (inventory == window) {
                kind = "window";
                slot = change.getSlot();
            } else if (inventory == player.getInventory()) {
                kind = "player";
                slot = change.getSlot();
            } else if (inventory == player.getOffhandInventory()) {
                kind = "player";
                slot = InventoryMirror.OFFHAND;
            } else {
                continue;
            }
            Map<String, Object> entry = new LinkedHashMap<>(4);
            entry.put("inv", kind);
            entry.put("slot", slot);
            entry.put("from", InventoryMirror.itemTuple(change.getSourceItem()));
            entry.put("to", InventoryMirror.itemTuple(change.getTargetItem()));
            actions.add(entry);
        }
        if (actions.isEmpty()) {
            return null;
        }

        Map<String, Object> data = new LinkedHashMap<>(4);
        data.put("window", windowId);
        data.put("actions", actions);
        return data;
    }

    // ---------------------------------------------------------------- helpers

    private Player player(Map<String, Object> op) {
        UUID id = parseUuid(String.valueOf(op.get("to")));
        return id == null ? null : server.getOnlinePlayers().get(id);
    }

    private Window window(Player player, int windowId) {
        if (player == null || !validWindow(windowId)) {
            return null;
        }
        Map<Integer, Window> owned = windows.get(player.getUniqueId());
        return owned == null ? null : owned.get(windowId);
    }

    private static boolean validWindow(int windowId) {
        return windowId >= FIRST_WINDOW && windowId <= LAST_WINDOW;
    }

    private static List<?> list(Object value) {
        return value instanceof List<?> list ? list : List.of();
    }

    private static int intOf(Object value, int fallback) {
        return value instanceof Number number ? number.intValue() : fallback;
    }

    /**
     * Parses a bridge item tuple whose item fields start at {@code offset}.
     *
     * <p>Package-private because block-entity contents use the same tuple: one item dialect for
     * windows, player inventories and tiles means one place where a name this build does not know
     * turns into "keep the old item and report it".</p>
     */
    static Item itemOf(List<?> tuple, int offset) {
        int count = intOf(tuple.get(offset + 1), 0);
        int meta = tuple.size() > offset + 2 ? intOf(tuple.get(offset + 2), 0) : 0;
        String nbt = tuple.size() > offset + 3 && tuple.get(offset + 3) instanceof String text && !text.isEmpty()
                ? text
                : null;
        return InventoryMirror.itemOf(String.valueOf(tuple.get(offset)), count, meta, nbt, message -> {
        });
    }

    private static UUID parseUuid(String value) {
        try {
            return UUID.fromString(value);
        } catch (IllegalArgumentException e) {
            return null;
        }
    }

    /** One line per distinct reason; a mirror that cannot represent something must not repeat it. */
    private void report(String message) {
        if (reported.add(message)) {
            System.out.println("[mukkit] window: " + message + " (further occurrences suppressed)");
        }
    }
}
