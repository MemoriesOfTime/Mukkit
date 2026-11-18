package cn.mukkit.nukkit;

import cn.nukkit.Player;
import cn.nukkit.inventory.ContainerInventory;
import cn.nukkit.inventory.InventoryType;
import cn.nukkit.item.Item;
import cn.nukkit.network.protocol.ContainerClosePacket;
import cn.nukkit.network.protocol.ContainerOpenPacket;
import cn.nukkit.network.protocol.types.inventory.ContainerType;

import java.util.HashMap;

/**
 * A container window whose contents belong to the PocketMine side.
 *
 * <p>Nukkit's own containers are backed by block entities and are ticked by the level. A
 * PocketMine plugin's chest is a plain object in the PHP process; the only thing Nukkit needs is
 * a real {@link ContainerInventory} registered under the same window id, so the client's item
 * moves arrive as an {@code InventoryTransactionEvent} instead of being dropped as an unknown
 * window.</p>
 *
 * <p>{@link #onOpen} sends the window with PocketMine's own network type and anchor, not the
 * type of the Java inventory object: the client draws a furnace because PM said furnace, even
 * though the Java side only cares about slot count.</p>
 */
final class BridgedWindowInventory extends ContainerInventory {

    private final int windowId;
    private final int networkType;
    private final int x;
    private final int y;
    private final int z;
    private final long entityId;

    BridgedWindowInventory(Player holder, InventoryType type, int windowId, int networkType,
                           int x, int y, int z, long entityId) {
        super(holder, type, new HashMap<>(), null, null);
        this.windowId = windowId;
        this.networkType = networkType;
        this.x = x;
        this.y = y;
        this.z = z;
        this.entityId = entityId;
    }

    int bridgeWindowId() {
        return windowId;
    }

    /**
     * Container size is not part of a Bedrock open packet. A double chest (54) or a modded
     * inventory announces its size with the first content packet, so the mirror grows the Java
     * inventory then. {@code size} is protected in {@code BaseInventory}; a subclass is the
     * intended way to change it.
     */
    void resize(int size) {
        if (size > 0 && size != this.size) {
            this.size = size;
        }
    }

    /** Replaces the whole window without sending one packet per slot. */
    void replaceContents(Iterable<Item> items) {
        this.slots.clear();
        int slot = 0;
        for (Item item : items) {
            if (item != null && !item.isNull()) {
                this.slots.put(slot, item.clone());
            }
            slot++;
        }
    }

    @Override
    public void onOpen(Player who) {
        super.onOpen(who);
        ContainerOpenPacket packet = new ContainerOpenPacket();
        // Nukkit's window id, not PocketMine's: the two id spaces are separate, and reusing
        // PM's id could overwrite a window a Nukkit plugin already registered.
        packet.windowId = who.getWindowId(this);
        packet.type = networkType;
        packet.x = x;
        packet.y = y;
        packet.z = z;
        packet.entityId = entityId;
        who.dataPacket(packet);
        sendContents(who);
    }

    @Override
    public void onClose(Player who) {
        ContainerClosePacket packet = new ContainerClosePacket();
        packet.windowId = who.getWindowId(this);
        packet.wasServerInitiated = true;
        try {
            packet.type = ContainerType.from(networkType);
        } catch (RuntimeException e) {
            packet.type = ContainerType.CONTAINER;
        }
        who.dataPacket(packet);
        super.onClose(who);
    }
}
