package cn.mukkit.nukkit;

import cn.nukkit.Player;
import cn.nukkit.Server;
import cn.nukkit.item.Item;
import cn.nukkit.nbt.NBTIO;
import cn.nukkit.nbt.tag.CompoundTag;

import java.io.ByteArrayInputStream;
import java.nio.ByteOrder;
import java.util.ArrayList;
import java.util.Base64;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Queue;
import java.util.Set;
import java.util.UUID;

/**
 * Keeps Nukkit's inventories and PocketMine's view of the same inventories aligned.
 *
 * <p>Same shape as {@link PlayerStateMirror}: polled every {@code mukkit.stateInterval} ticks,
 * diffed against a per-player shadow, and echo-suppressed by re-reading the player after an
 * inbound write. Polling rather than listening to {@code InventoryTransactionEvent} keeps the
 * mirror correct for changes that produce no transaction (a plugin calling {@code setItem()},
 * a hopper, a plugin cancelling the change event) - the same argument that decided the state
 * mirror.</p>
 *
 * <p>The slot space is shared with the host and is wider than Nukkit's {@code getSize()}: slots
 * 36-39 are the armour slots that {@code PlayerInventory} keeps past its reported size, 40 is
 * the offhand. Both sides map that number onto their own inventory objects.</p>
 */
final class InventoryMirror {

    static final int INVENTORY_SLOTS = 36;
    static final int ARMOR_HEAD = 36;
    static final int ARMOR_FEET = 39;
    static final int OFFHAND = 40;
    static final int SLOTS = 41;

    /** Same cadence as the state mirror: fast enough for a plugin, cheap enough to ignore. */
    private static final int INTERVAL = Math.max(1, Integer.getInteger("mukkit.stateInterval", 2));

    private static final String AIR = "air";
    private static final String OPAQUE = "opaque";

    /** Which of Nukkit's three inventory objects a bridge slot lives in. */
    enum SlotKind {
        INVENTORY,
        ARMOR,
        OFFHAND,
        INVALID
    }

    private final Server server;
    private final Queue<Map<String, Object>> inbound;
    private final java.util.function.BooleanSupplier enabled;

    /** Last observed fingerprint per slot, per online player. */
    private final Map<UUID, Map<Integer, String>> shadow = new LinkedHashMap<>();

    private final Set<String> reported = new HashSet<>();

    private int tick;

    InventoryMirror(Server server, Queue<Map<String, Object>> inbound, java.util.function.BooleanSupplier enabled) {
        this.server = server;
        this.inbound = inbound;
        this.enabled = enabled;
    }

    void forget(UUID id) {
        if (id != null) {
            shadow.remove(id);
        }
    }

    /**
     * Drops every shadow so the next poll is a full snapshot.
     *
     * <p>A host that has just booted has empty inventories. Keeping the shadows from the
     * previous host would leave the new one believing it already knew them, and it would never
     * see an item until the player moved one.</p>
     */
    void forgetAll() {
        shadow.clear();
        reported.clear();
    }

    /** Collects what Nukkit changed since the last poll. Runs on the tick thread. */
    void poll() {
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
            Map<Integer, String> after = snapshot(player);
            Map<Integer, String> before = shadow.put(id, after);

            // No shadow means this host has never seen the player: send everything that is not
            // air. The state mirror suppresses the first poll instead, because the join frame
            // already carries the position; nothing carries an inventory.
            boolean first = before == null;

            List<List<Object>> changed = new ArrayList<>();
            for (int slot = 0; slot < SLOTS; slot++) {
                String fingerprint = after.get(slot);
                if (first && (AIR.equals(fingerprint) || OPAQUE.equals(fingerprint))) {
                    continue;
                }
                if (!first && fingerprint.equals(before.get(slot))) {
                    continue;
                }
                if (OPAQUE.equals(fingerprint)) {
                    report("slot " + slot + " holds an item this build cannot name; not mirrored");
                    continue;
                }
                changed.add(tuple(slot, itemAt(player, slot)));
            }

            if (!changed.isEmpty()) {
                Map<String, Object> item = new LinkedHashMap<>(4);
                item.put("k", "player.inventory");
                item.put("player", id.toString());
                item.put("slots", changed);
                inbound.add(item);
            }
        }
        shadow.keySet().retainAll(online.keySet());
    }

    /** Applies slot writes the plugin host pushed, then re-reads the player into the shadow. */    @SuppressWarnings("unchecked")
    void apply(Map<String, Object> op) {
        Player player = server.getOnlinePlayers().get(parseUuid(String.valueOf(op.get("to"))));
        if (player == null) {
            return;
        }
        if (!(op.get("slots") instanceof List<?> slots)) {
            return;
        }

        for (Object entry : slots) {
            if (!(entry instanceof List<?> tuple) || tuple.size() < 3) {
                System.out.println("[mukkit] player.inventory: malformed slot entry");
                continue;
            }
            int slot = ((Number) tuple.get(0)).intValue();
            if (!validSlot(slot)) {
                report("slot " + slot + " is outside the mirrored range");
                continue;
            }
            String name = String.valueOf(tuple.get(1));
            int count = ((Number) tuple.get(2)).intValue();
            int meta = tuple.size() > 3 && tuple.get(3) instanceof Number n ? n.intValue() : 0;
            String nbt = tuple.size() > 4 && tuple.get(4) instanceof String text && !text.isEmpty() ? text : null;

            Item item = itemOf(name, count, meta, nbt, this::report);
            if (item == null) {
                continue;
            }
            try {
                setItem(player, slot, item);
            } catch (RuntimeException e) {
                // A Nukkit plugin can veto EntityInventoryChangeEvent; the slot keeps its old
                // contents and the re-read below records that. Nothing to undo.
                System.out.println("[mukkit] player.inventory: slot " + slot + " refused " + name
                        + " (" + e.getMessage() + ")");
            }
        }

        UUID id = player.getUniqueId();
        if (id != null) {
            // From the player, not from the op: a vetoed change or a clamped stack means the
            // request is not what happened, and echoing a change that did not happen would keep
            // the two sides arguing forever.
            shadow.put(id, snapshot(player));
        }
    }

    /**
     * Resolves a few names the mirror depends on and says so once, at host-ready time.
     *
     * <p>Sound only because both sides name items with the flattened ids from the runtime
     * palette. That is a property of the Nukkit build, not of this code, so it is worth one line
     * in the log: a build whose palette cannot resolve `minecraft:red_wool` would otherwise only
     * show up later as a plugin's shop quietly failing to hand out wool.</p>
     *
     * <p>Silent when everything resolves except for a single informational line - this is a
     * diagnostic, not a feature, and it must not look like plugin activity.</p>
     */
    void verifyVocabulary() {
        String[] probes = {"minecraft:diamond", "minecraft:diamond_sword", "minecraft:red_wool", "minecraft:oak_planks"};
        List<String> missing = new ArrayList<>();
        for (String name : probes) {
            try {
                Item item = Item.fromString(name);
                if (item == null || item.isNull()) {
                    missing.add(name);
                }
            } catch (RuntimeException e) {
                missing.add(name);
            }
        }
        System.out.println("[mukkit] item vocabulary: " + (probes.length - missing.size()) + "/" + probes.length
                + " names resolved" + (missing.isEmpty() ? "" : " (missing: " + missing + ")"));
    }

    // ---------------------------------------------------------------- pure helpers

    static boolean validSlot(int slot) {
        return kindOf(slot) != SlotKind.INVALID;
    }

    static SlotKind kindOf(int slot) {
        if (slot < 0) {
            return SlotKind.INVALID;
        }
        if (slot < INVENTORY_SLOTS) {
            return SlotKind.INVENTORY;
        }
        if (slot <= ARMOR_FEET) {
            return SlotKind.ARMOR;
        }
        return slot == OFFHAND ? SlotKind.OFFHAND : SlotKind.INVALID;
    }

    /**
     * What changed is compared as this string, not as item objects: two {@code Item} instances
     * can differ in fields nobody can see, and a comparison on those would report a change every
     * tick. It contains exactly the fields that travel.
     */
    static String fingerprint(String id, int count, int meta, String nbt) {
        if (count <= 0 || id.isEmpty() || "minecraft:air".equals(id)) {
            return AIR;
        }
        return id + '|' + count + '|' + meta + '|' + (nbt == null ? "" : nbt);
    }

    static List<Object> airTuple(int slot) {
        List<Object> tuple = new ArrayList<>(4);
        tuple.add(slot);
        tuple.add("minecraft:air");
        tuple.add(0);
        tuple.add(0);
        return tuple;
    }

    // ---------------------------------------------------------------- inventory access

    private static Item itemAt(Player player, int slot) {
        return kindOf(slot) == SlotKind.OFFHAND
                ? player.getOffhandInventory().getItem(0)
                : player.getInventory().getItem(slot);
    }

    private static void setItem(Player player, int slot, Item item) {
        if (kindOf(slot) == SlotKind.OFFHAND) {
            player.getOffhandInventory().setItem(0, item);
        } else {
            // Slots 36-39 are the armour slots PlayerInventory keeps past getSize(); the same
            // accessor reaches them, which is what makes the two numberings line up.
            player.getInventory().setItem(slot, item);
        }
    }

    private Map<Integer, String> snapshot(Player player) {
        Map<Integer, String> snapshot = new LinkedHashMap<>(SLOTS);
        for (int slot = 0; slot < SLOTS; slot++) {
            snapshot.put(slot, fingerprintOf(itemAt(player, slot)));
        }
        return snapshot;
    }

    private String fingerprintOf(Item item) {
        if (item == null || item.isNull()) {
            return AIR;
        }
        String id;
        try {
            id = item.getNamespaceId();
        } catch (RuntimeException e) {
            // Outside a running server the runtime item palette is not loaded; in production this
            // means an item this build has no name for.
            return OPAQUE;
        }
        String nbt;
        try {
            nbt = nbtBase64(item);
        } catch (Exception e) {
            return OPAQUE;
        }
        return fingerprint(id, item.getCount(), item.getDamage(), nbt);
    }

    private static String nbtBase64(Item item) throws Exception {
        CompoundTag tag = item.getNamedTag();
        if (tag == null) {
            return null;
        }
        byte[] bytes = NBTIO.write(tag, ByteOrder.LITTLE_ENDIAN, true);
        return Base64.getEncoder().encodeToString(bytes);
    }

    /**
     * The item dialect without a slot: `[id, count, damage]` plus base64 NBT when there is any.
     *
     * <p>Shared with the entity mirror: a dropped stack is the same thing one level down, and
     * having two translators for it would be the kind of divergence that only shows up when a
     * renamed sword is picked up and comes back unnamed.</p>
     */
    static List<Object> itemTuple(Item item) {
        if (item == null || item.isNull()) {
            return List.of("minecraft:air", 0, 0);
        }
        String id = item.getNamespaceId();
        if ("minecraft:air".equals(id)) {
            return List.of("minecraft:air", 0, 0);
        }
        List<Object> tuple = new ArrayList<>(4);
        tuple.add(id);
        tuple.add(item.getCount());
        tuple.add(item.getDamage());
        try {
            String nbt = nbtBase64(item);
            if (nbt != null) {
                tuple.add(nbt);
            }
        } catch (Exception e) {
            // The fingerprint already managed to encode it, so this is the same failure one step
            // later. The item still travels; only the extra data is lost.
        }
        return tuple;
    }

    private List<Object> tuple(int slot, Item item) {
        List<Object> itemTuple = itemTuple(item);
        if ("minecraft:air".equals(itemTuple.get(0))) {
            return airTuple(slot);
        }
        List<Object> tuple = new ArrayList<>(5);
        tuple.add(slot);
        tuple.addAll(itemTuple);
        return tuple;
    }

    static Item itemOf(String name, int count, int meta, String nbt, java.util.function.Consumer<String> report) {
        if (count <= 0 || name.isEmpty() || "minecraft:air".equals(name)) {
            return Item.AIR_ITEM.clone();
        }
        Item item;
        try {
            item = Item.fromString(name);
        } catch (RuntimeException e) {
            item = null;
        }
        if (item == null || item.isNull()) {
            // `Item.fromString` falls back to air for a name this build does not know. Inventing
            // an item would put something wrong in the slot; keeping the old one is visible and
            // can be reported, which is the direction this project always chooses.
            report.accept("item.unsupported: " + name);
            return null;
        }
        item.setCount(Math.min(count, item.getMaxStackSize()));
        if (meta > 0 && item.getMaxDurability() > 0) {
            item.setDamage(Math.min(meta, item.getMaxDurability()));
        }
        if (nbt != null) {
            try {
                byte[] raw = Base64.getDecoder().decode(nbt);
                CompoundTag tag = NBTIO.read(new ByteArrayInputStream(raw), ByteOrder.LITTLE_ENDIAN, true);
                item.setNamedTag(tag);
            } catch (Exception e) {
                report.accept("item " + name + " has NBT this build cannot read: " + e.getMessage());
                return null;
            }
        }
        return item;
    }

    /** One line per distinct reason; a mirror that cannot represent something must not repeat it. */
    private void report(String message) {
        if (reported.add(message)) {
            System.out.println("[mukkit] player.inventory: " + message + " (further occurrences suppressed)");
        }
    }

    private static UUID parseUuid(String value) {
        try {
            return UUID.fromString(value);
        } catch (IllegalArgumentException e) {
            return null;
        }
    }
}
