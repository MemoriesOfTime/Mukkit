package cn.mukkit.nukkit;

import cn.nukkit.AdventureSettings;
import cn.nukkit.Player;
import cn.nukkit.Server;
import cn.nukkit.event.Cancellable;
import cn.nukkit.event.EventHandler;
import cn.nukkit.event.EventPriority;
import cn.nukkit.event.Listener;
import cn.nukkit.block.Block;
import cn.nukkit.block.BlockEntityHolder;
import cn.nukkit.blockentity.BlockEntity;
import cn.nukkit.event.block.BlockBreakEvent;
import cn.nukkit.event.block.BlockBurnEvent;
import cn.nukkit.event.block.BlockFormEvent;
import cn.nukkit.event.block.BlockGrowEvent;
import cn.nukkit.event.block.BlockSpreadEvent;
import cn.nukkit.event.block.BlockUpdateEvent;
import cn.nukkit.event.block.LeavesDecayEvent;
import cn.nukkit.event.player.PlayerChatEvent;
import cn.nukkit.event.player.PlayerCommandPreprocessEvent;
import cn.nukkit.event.player.PlayerDeathEvent;
import cn.nukkit.event.player.PlayerDropItemEvent;
import cn.nukkit.event.player.PlayerItemHeldEvent;
import cn.nukkit.event.player.PlayerJoinEvent;
import cn.nukkit.event.player.PlayerQuitEvent;
import cn.nukkit.event.player.PlayerRespawnEvent;
import cn.nukkit.event.player.PlayerTeleportEvent;
import cn.nukkit.event.player.PlayerToggleFlightEvent;
import cn.nukkit.event.player.PlayerToggleGlideEvent;
import cn.nukkit.event.player.PlayerToggleSneakEvent;
import cn.nukkit.event.player.PlayerToggleSprintEvent;
import cn.nukkit.event.player.PlayerToggleSwimEvent;
import cn.nukkit.event.inventory.InventoryTransactionEvent;
import cn.nukkit.event.server.DataPacketReceiveEvent;
import cn.nukkit.event.server.RemoteServerCommandEvent;
import cn.nukkit.event.server.ServerCommandEvent;
import cn.nukkit.item.Item;
import cn.nukkit.level.Level;
import cn.nukkit.level.Position;
import cn.nukkit.math.Vector3;
import cn.nukkit.network.protocol.DataPacket;
import cn.nukkit.network.protocol.ModalFormRequestPacket;
import cn.nukkit.network.protocol.ModalFormResponsePacket;
import cn.mukkit.bridge.Gateway;

import java.util.ArrayList;
import java.util.Collection;
import java.util.HashSet;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;
import java.util.Queue;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ConcurrentLinkedQueue;
import java.util.function.BooleanSupplier;

/**
 * The game-server half of the bridge: collects what happened into the tick frame, and applies
 * what the host asked for back onto the world.
 *
 * <p>Everything here runs on the server's main thread. Inbound events are only queued here —
 * they leave on the next tick frame — so a slow plugin can never stall the event bus.</p>
 */
final class NukkitGateway implements Gateway, Listener {

    private static final int SAMPLE_LIMIT = 8;

    private final Server server;
    private final Queue<Map<String, Object>> inbound = new ConcurrentLinkedQueue<>();
    private final Map<String, Integer> opKinds = new LinkedHashMap<>();
    private final List<String> samples = new ArrayList<>(SAMPLE_LIMIT);

    /**
     * Who owns chat and the join/quit announcements.
     *
     * <p>Both sides can produce these messages, and while both do the player sees every line
     * twice. The host says which side delivers in its `ready` frame ({@code broadcasts}), because
     * that is a property of the profile it is running: the {@code pm} profile broadcasts through
     * PocketMine's formatter, the {@code stub} and {@code plugin} profiles only observe. Until a
     * host says otherwise, and again after it is lost, Nukkit keeps its own broadcast, so a
     * missing or passive host degrades to plain Nukkit chat instead of swallowing it.</p>
     */
    private volatile boolean hostOwnsBroadcasts = false;

    /**
     * Whether the host keeps a world mirror.
     *
     * <p>Only the {@code pm} profile does. Sending it chunk frames the other profiles do not
     * understand (and pushing every block change at them) would be pure noise, so the host
     * declares this in its ready frame just like {@code broadcasts}.</p>
     */
    private volatile boolean hostMirrors = false;

    /**
     * Whether the host runs the commands players type.
     *
     * <p>Only the {@code pm} profile does: it has a PocketMine {@code CommandMap} with plugin
     * commands in it. While this is false the adapter leaves every command to Nukkit, so a
     * missing or observing host degrades to Nukkit's own dispatch (including its "unknown
     * command" answer) instead of eating the line.</p>
     */
    private volatile boolean hostOwnsCommands = false;

    /**
     * Nukkit's side of the player-state mirror. Alive from the first tick, whether or not a host
     * ever connects: keeping the shadow warm costs a few map writes and makes the first poll after
     * a host restart correct instead of treating every player as brand new.
     */
    private final PlayerStateMirror playerState;

    /**
     * Nukkit's side of the container windows PocketMine plugins open.
     *
     * <p>Separate from {@link InventoryMirror}: a window's slot space belongs to the plugin's
     * inventory object, not to the player, and it has no per-tick shadow to diff.</p>
     */
    private final ContainerMirror containers;

    /**
     * Nukkit's side of the inventory mirror. Same liveness rule as the state mirror: the shadow
     * stays warm while no host is connected, and is dropped on `onReady` so a fresh host gets a
     * full snapshot instead of only the slots that change next.
     */
    private final InventoryMirror inventory;

    /** Non-zero while a host block op is being applied, so the resulting event is not echoed. */
    private int applyingRemoteBlocks = 0;

    private long blocksApplied;

    /**
     * Cancellable actions PocketMine wants to judge.
     *
     * <p>Empty unless a PocketMine plugin is actually listening: holding and replaying every block
     * break would cost a tick of latency and a client-side flicker for a decision nobody asked for.</p>
     */
    private volatile Set<String> hostWantsEvents = Set.of();

    /** Non-zero while a verdict-approved action is being replayed, so it is not held again. */
    private int replayingActions = 0;

    /**
     * Forms PocketMine has shown and is waiting on.
     *
     * <p>Nukkit drops a response it cannot match against {@code Player::formWindows}, and a form
     * sent by the bridge is not in that map — the bridge never called {@code showFormWindow()}.
     * So the ids are remembered here and the raw response is intercepted before Nukkit's own
     * processor runs.</p>
     */
    private final FormLedger forms = new FormLedger();

    /** The literal the client sends in place of form data when it dismisses a form. */
    private static final String FORM_CLOSED = "null";

    private long formResponses;
    private long formResponsesStale;

    /** Block breaks and item drops waiting for a verdict; 40 ticks ≈ 2 s, over a slow host tick. */
    private final PendingActions<HeldAction> heldActions = new PendingActions<>(40);

    /** Per event name, so a summary can say which action a count belongs to. */
    private final Map<String, Long> heldCounts = new LinkedHashMap<>();
    private final Map<String, Long> deniedCounts = new LinkedHashMap<>();
    private final Map<String, Long> replayedCounts = new LinkedHashMap<>();
    private final Map<String, Long> expiredCounts = new LinkedHashMap<>();
    private final Set<String> expiryReported = new HashSet<>();

    /** Block writes whose block entity could not be created, one line per distinct reason. */
    private final Set<String> blockedReports = new HashSet<>();

    private long verdictsStale;
    private long dropsUnreplayable;
    private boolean dropReplayReported;

    /**
     * An action the adapter froze, and what "allowed" and "denied" mean for it.
     *
     * <p>The verdict itself is one boolean, but neither side of it is uniform: a break has to be
     * re-run through {@code Level::useBreakOn()}, a hotbar switch has to re-send the equipment
     * packet, a denied one has to put the client's selection back. Carrying the two behaviours
     * instead of a payload type is what makes the hold table a channel: a new event is a builder
     * and a lambda, not a third record and two switch arms.</p>
     *
     * <p>{@code replay} reports whether it really performed the action, so the summary counts a
     * replay whose player logged off in the meantime as neither replayed nor denied.</p>
     */
    private record HeldAction(String event, BooleanSupplier replay, Runnable deny) {

        HeldAction(String event, BooleanSupplier replay) {
            this(event, replay, () -> {
            });
        }
    }

    /** Chunks the host mirrors; see {@link ChunkStreamer}. */
    private final ChunkStreamer chunks;

    /** Block entities inside those chunks; see {@link TileMirror}. */
    private final TileMirror tiles;

    /** World clocks; see {@link WorldStateMirror}. */
    private final WorldStateMirror worldState;

    /** Entities around the players; see {@link EntityMirror}. */
    private final EntityMirror entities;

    private long events;
    private long commands;
    private long playerCommands;
    private long packets;
    private long recipients;
    private long packetsTooOld;
    private long unknownOps;
    private long disconnects;

    /**
     * Players this bridge closed because the host asked it to.
     *
     * <p>{@code Player.close()} fires {@code PlayerQuitEvent} synchronously, so the marker has to
     * be set before the call. Its only job is to keep the echo off the wire: the host ran its own
     * quit path - event, leave broadcast, bookkeeping - before the op was even sent, and a second
     * {@code player.quit} would read as a different player leaving.</p>
     */
    private final Set<UUID> hostDisconnects = new HashSet<>();

    NukkitGateway(Server server) {
        this.server = server;
        this.playerState = new PlayerStateMirror(server, inbound, () -> hostMirrors);
        this.inventory = new InventoryMirror(server, inbound, () -> hostMirrors);
        this.containers = new ContainerMirror(server, inbound, () -> hostMirrors);
        this.chunks = new ChunkStreamer(server, inbound, () -> hostMirrors);
        this.tiles = new TileMirror(server, inbound, () -> hostMirrors, chunks);
        this.worldState = new WorldStateMirror(server, inbound, () -> hostMirrors);
        this.entities = new EntityMirror(server, inbound, () -> hostMirrors, chunks);
    }

    // ---------------------------------------------------------------- inbound

    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onJoin(PlayerJoinEvent event) {
        Player player = event.getPlayer();
        Map<String, Object> data = new LinkedHashMap<>(8);
        data.put("name", nameOf(player));
        // The PHP side builds a real pocketmine Player, whose position has to come from
        // somewhere. Nukkit owns the world, so it is the authority on where the player is.
        data.put("x", player.getX());
        data.put("y", player.getY());
        data.put("z", player.getZ());
        data.put("yaw", player.getYaw());
        data.put("pitch", player.getPitch());
        data.put("world", player.getLevel() == null ? "" : player.getLevel().getName());
        // Operator status has to arrive with the join, not on the next state poll: PocketMine
        // reads its operator list once, in `Player::__construct()`, and `PlayerJoinEvent` fires
        // a moment later. A plugin that checks a `default: op` permission in that event would
        // otherwise see a refusal for a player the console just opped.
        data.put("op", player.isOp());
        // Part of "where the player is", not of the inventory contents: a plugin reading
        // `getItemInHand()` inside PlayerJoinEvent must see the slot the client is holding, not
        // slot 0. It is applied before that event for the same reason `op` is.
        data.put("heldSlot", player.getInventory().getHeldItemIndex());
        // A player who is joining is by definition not one this bridge closed: any marker left
        // for this uuid (a kick whose quit event never fired) must not silence their next quit.
        hostDisconnects.remove(player.getUniqueId());
        queueEvent("player.join", uuidOf(player), data);
        playerState.remember(player);
        // The streamer works out which chunks this player needs from where they are; there is
        // nothing to send here that it would not send on its next poll anyway.
        chunks.invalidate();
        if (hostOwnsBroadcasts) {
            // PocketMine broadcasts its own join line through the plugin's formatter; Nukkit's
            // default one would be a second, differently formatted copy. Empty, not null:
            // Nukkit's own tail calls `getJoinMessage().toString().isBlank()`, and a null message
            // makes that toString() return null - an NPE in Player::doFirstSpawn that leaves the
            // client stuck on the loading screen.
            event.setJoinMessage("");
        }
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onQuit(PlayerQuitEvent event) {
        Player player = event.getPlayer();
        playerState.forget(player.getUniqueId());
        inventory.forget(player.getUniqueId());
        containers.forget(player.getUniqueId());
        // Somebody left: their square stops being part of the union, and the chunks nobody else
        // needs can be dropped this tick instead of at the next interval.
        chunks.invalidate();
        // A player who left cannot answer an open form, and holding the id would mean a later
        // response from a recycled UUID being attributed to it.
        forms.forget(player.getUniqueId());
        if (hostOwnsBroadcasts) {
            // Same rule as the join line, and the same trap: Nukkit compares the quit message with
            // "" before broadcasting. A null container is not equal to "", so it would be
            // broadcast as a null and throw on the way out.
            event.setQuitMessage("");
        }
        // A kick the host asked for. It has already run its own quit path - the event, the leave
        // line, the player list - so this quit is not news, and forwarding it would look like a
        // second player leaving. The bookkeeping above still has to happen.
        UUID id = player.getUniqueId();
        if (id != null && hostDisconnects.remove(id)) {
            return;
        }
        Map<String, Object> data = new LinkedHashMap<>(2);
        data.put("name", nameOf(player));
        data.put("reason", event.getReason() == null ? "" : event.getReason());
        queueEvent("player.quit", uuidOf(player), data);
    }

    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onChat(PlayerChatEvent event) {
        Player player = event.getPlayer();
        Map<String, Object> data = new LinkedHashMap<>(2);
        data.put("name", nameOf(player));
        data.put("message", event.getMessage());
        queueEvent("player.chat", uuidOf(player), data);
        if (hostOwnsBroadcasts) {
            // Cancelling here is what "the host delivers" means: PM's PlayerChatEvent runs with
            // its own formatter and its result comes back as packet ops. Handlers that ran
            // before MONITOR have already had their say; handlers after it do not exist.
            event.setCancelled();
        }
    }

    /**
     * Console commands.
     *
     * <p>Same ownership rule as player-typed ones ({@link #onCommandPreprocess}): a name Nukkit
     * has stays in Nukkit, and only what Nukkit cannot dispatch travels to the host. Forwarding
     * everything is what printed {@code list} and {@code ver} twice - PocketMine's answer
     * ({@code 0/20}) and Nukkit's ({@code 0/50}) - and what made every vanilla command also get a
     * "command not handled by any plugin" from the PM side.</p>
     *
     * <p>While the host does not own commands (the {@code plugin} profile) the line is forwarded
     * as an observation and Nukkit keeps running it: that is the plugin-profile contract, and nothing there
     * dispatches a second time.</p>
     */
    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onCommand(ServerCommandEvent event) {
        // `RemoteServerCommandEvent` extends this class but has its own HandlerList. Routing it
        // here as well would send every RCON line twice if Nukkit ever calls both lists.
        if (event instanceof RemoteServerCommandEvent) {
            return;
        }
        routeConsoleCommand(event);
    }

    /**
     * RCON is a second console, not a different command surface: a PM plugin command typed over
     * RCON must reach PM the same way it does on stdin. Nukkit dispatches RCON commands directly
     * from `RCON::check()` and never raises `ServerCommandEvent`, so the console handler above
     * cannot see them.
     */
    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onRemoteCommand(RemoteServerCommandEvent event) {
        routeConsoleCommand(event);
    }

    private void routeConsoleCommand(ServerCommandEvent event) {
        String line = event.getCommand();
        if (hostOwnsCommands) {
            line = CommandRouting.route(
                    line,
                    label -> server.getCommandMap().getCommand(label) != null
            );
            if (line == null) {
                return;
            }
            // Cancelled at MONITOR: the console dispatches whatever is not cancelled, so this is
            // what stops Nukkit's "Unknown command" answer for a PM plugin's command.
            event.setCancelled();
        }
        Map<String, Object> item = new LinkedHashMap<>(4);
        item.put("k", "command");
        item.put("sender", event.getSender() == null ? "console" : String.valueOf(event.getSender().getName()));
        item.put("line", line);
        inbound.add(item);
        commands++;
    }

    /**
     * Player-typed commands Nukkit has no command for: the host runs them.
     *
     * <p>This is the gap that kept the bridge console-only. Nukkit's processor fires
     * {@code PlayerCommandPreprocessEvent} right before {@code dispatchCommand}, and dispatch of
     * a name it does not know is where a PocketMine plugin's {@code /money} used to die with
     * "unknown command". Cancelling the event is what stops that answer; the line travels
     * instead, with the player's uuid so the host dispatches as the real {@code Player} and not
     * as console.</p>
     *
     * <p>Names Nukkit knows are left untouched - vanilla ones and other Nukkit plugins' -
     * because Nukkit owns the world they act on and running both would act twice. A name in
     * both maps therefore resolves to Nukkit, which is the side that can actually move a block.</p>
     */
    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onCommandPreprocess(PlayerCommandPreprocessEvent event) {
        if (!hostOwnsCommands) {
            return;
        }
        String line = CommandRouting.route(
                event.getMessage(),
                label -> server.getCommandMap().getCommand(label) != null
        );
        if (line == null) {
            return;
        }
        Player player = event.getPlayer();
        // Cancelled at MONITOR: every other handler has had its say, and the processor checks
        // the flag as soon as callEvent() returns.
        event.setCancelled();

        Map<String, Object> item = new LinkedHashMap<>(6);
        item.put("k", "command");
        // `sender` is the log line; `player` is what the host resolves the Player by. A name is
        // display only - it can change mid-session, a uuid cannot.
        item.put("sender", nameOf(player));
        item.put("player", uuidOf(player));
        item.put("line", line);
        inbound.add(item);
        commands++;
        playerCommands++;
    }

    /**
     * Claims a form response before Nukkit can drop it.
     *
     * <p>Nukkit's {@code ModalFormResponseProcessor} only looks at ids present in
     * {@code Player::formWindows}, a map that is filled by {@code showFormWindow()} and therefore
     * never contains a form the bridge sent directly. An unrecognised id is not merely ignored:
     * the processor logs it at debug level and gives up, so the answer to a PocketMine form would
     * vanish without a trace.</p>
     *
     * <p>Cancelling the event is what "we took it" means — it stops {@code Player::handleDataPacket}
     * before the processor runs. Ids the ledger does not know are left alone, so a Nukkit plugin's
     * own forms keep working; the two id ranges are disjoint by construction.</p>
     */
    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onPacketReceive(DataPacketReceiveEvent event) {
        if (!(event.getPacket() instanceof ModalFormResponsePacket response)) {
            return;
        }
        Player player = event.getPlayer();
        UUID id = player.getUniqueId();
        if (id == null) {
            return;
        }
        if (!forms.take(id, response.formId)) {
            // Either a Nukkit plugin's form, or an answer to something already resolved.
            formResponsesStale++;
            return;
        }
        event.setCancelled(true);
        formResponses++;

        // `data` is the JSON text the client produced. It travels verbatim: which shape the answer
        // takes (an index, a bool, an array of element values) is the form implementation's
        // business, and the host is where that implementation lives.
        String data = response.data == null ? FORM_CLOSED : response.data;
        Map<String, Object> item = new LinkedHashMap<>(6);
        item.put("k", "form.response");
        item.put("player", id.toString());
        item.put("formId", response.formId);
        // The client sending no data at all is how a dismissal arrives; PM's own packet handler
        // maps the same signal to a `null` response.
        item.put("closed", FORM_CLOSED.equals(data));
        item.put("data", data);
        inbound.add(item);
    }

    /**
     * A teleport can change the level or move the player a thousand blocks in one tick. The
     * streamer would notice at its next interval, but a plugin that reads the destination in the
     * same tick would see air, so the recompute is pulled forward.
     */
    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onTeleport(PlayerTeleportEvent event) {
        chunks.invalidate();
    }

    /**
     * Applies block writes the host detected in its mirror.
     *
     * <p>{@code applyingRemoteBlocks} suppresses the BlockUpdateEvent echo: without it every
     * host write would come straight back as a {@code block.update} and the two sides would
     * ping-pong the same block forever.</p>
     */
    @SuppressWarnings("unchecked")
    private void applyBlocks(Map<String, Object> op) {
        Object raw = op.get("b");
        if (!(raw instanceof List<?> list) || list.isEmpty()) {
            return;
        }
        Level level = server.getDefaultLevel();
        String world = String.valueOf(op.getOrDefault("world", ""));
        if (!world.isEmpty() && server.getLevelByName(world) != null) {
            level = server.getLevelByName(world);
        }
        if (level == null) {
            return;
        }

        applyingRemoteBlocks++;
        try {
            for (Object entry : list) {
                if (!(entry instanceof List<?> tuple) || tuple.size() < 5) {
                    continue;
                }
                int x = ((Number) tuple.get(0)).intValue();
                int y = ((Number) tuple.get(1)).intValue();
                int z = ((Number) tuple.get(2)).intValue();
                int id = ((Number) tuple.get(3)).intValue();
                int meta = ((Number) tuple.get(4)).intValue();
                applyBlock(level, new Vector3(x, y, z), id, meta);
                blocksApplied++;
            }
        } finally {
            applyingRemoteBlocks--;
        }
    }

    /**
     * Writes one block and keeps the block entity at that position in step.
     *
     * <p>`Level::setBlock()` does not touch block entities: a chest the host broke would leave
     * its tile behind, and a chest the host placed would never get one. Both are visible to
     * plugins - the first as a chest that still answers `getBlockEntity()` on air, the second as
     * one that never does - so the block write owns the transition.</p>
     *
     * <p>An existing entity of the same type is left alone: replacing a chest block with another
     * chest block must keep the contents.</p>
     */
    private void applyBlock(Level level, Vector3 pos, int id, int meta) {
        Block block = Block.get(id, meta);
        block.position(new Position(pos.x, pos.y, pos.z, level));
        String wanted = block instanceof BlockEntityHolder<?> holder ? holder.getBlockEntityType() : null;

        BlockEntity existing = level.getBlockEntityIfLoaded(pos);
        if (existing != null && (wanted == null || !wanted.equals(existing.getSaveId()))) {
            existing.close();
            existing = null;
        }
        if (wanted != null && existing == null) {
            try {
                BlockEntityHolder.setBlockAndCreateEntity((BlockEntityHolder) block, true, true);
                return;
            } catch (RuntimeException e) {
                reportBlock("block entity " + wanted + " at " + pos + " could not be created: " + e.getMessage());
                // Fall through: the block itself still has to match the host.
            }
        }
        level.setBlock(pos, block, true);
    }

    /** One line per distinct reason; a block write must not fill the log with the same warning. */
    private void reportBlock(String message) {
        if (blockedReports.add(message)) {
            System.out.println("[mukkit] block: " + message + " (further occurrences suppressed)");
        }
    }


    /**
     * Reports block changes Nukkit made on its own - a player placing a block, physics, another
     * plugin - so the host's mirror does not drift.
     */
    @EventHandler(priority = EventPriority.MONITOR)
    public void onBlockUpdate(BlockUpdateEvent event) {
        if (!hostMirrors || applyingRemoteBlocks > 0) {
            return;
        }
        Block block = event.getBlock();
        Level level = block.getLevel();
        if (level == null || !chunks.mirrors(level.getName())) {
            // A level nobody is in has no mirrored chunks; forwarding its block changes would
            // make the host create a world it can never fill.
            return;
        }
        Map<String, Object> item = new LinkedHashMap<>(8);
        item.put("k", "block.update");
        item.put("world", level == null ? "" : level.getName());
        item.put("x", block.getFloorX());
        item.put("y", block.getFloorY());
        item.put("z", block.getFloorZ());
        item.put("id", block.getId());
        item.put("meta", block.getDamage());
        inbound.add(item);
    }

    /**
     * Holds a block break until PocketMine decides.
     *
     * <p>MONITOR on purpose: every other Nukkit plugin has already seen the event uncancelled, so
     * their behaviour does not change. Cancelling here means "not yet", not "no" — if the host
     * approves, the break is replayed through {@code Level::useBreakOn()} on the tick the verdict
     * arrives.</p>
     *
     * <p>Only active when a PocketMine plugin actually listens for {@code BlockBreakEvent}. Without
     * a listener there is no decision to wait for, and holding the action would cost a tick of
     * latency and a client-side flicker for nothing.</p>
     */
    @EventHandler(priority = EventPriority.MONITOR)
    public void onBlockBreak(BlockBreakEvent event) {
        if (!wants("block.break") || event.isCancelled()) {
            return;
        }
        Player player = event.getPlayer();
        UUID id = player.getUniqueId();
        Block block = event.getBlock();
        Level level = block.getLevel();
        if (id == null || level == null) {
            return;
        }
        Item held = event.getItem().clone();
        int x = block.getFloorX();
        int y = block.getFloorY();
        int z = block.getFloorZ();
        String world = level.getName();

        Map<String, Object> data = new LinkedHashMap<>(8);
        data.put("x", x);
        data.put("y", y);
        data.put("z", z);
        data.put("world", world);
        // Items travel as namespaced ids, never as numbers: both sides' numeric ids are
        // build-specific, so a number would be a different item on the other side.
        data.put("item", held.getNamespaceId());
        data.put("itemCount", held.getCount());
        data.put("instaBreak", event.getInstaBreak());

        event.setCancelled(true);
        hold("block.break", player, data, () -> {
            Player online = server.getOnlinePlayers().get(id);
            Level target = server.getLevelByName(world);
            if (online == null || target == null) {
                // The player left (or changed world) between the request and the verdict.
                return false;
            }
            target.useBreakOn(new Vector3(x, y, z), held, online, true);
            return true;
        });
    }

    /**
     * Holds an item drop until PocketMine decides.
     *
     * <p>Both of Nukkit's drop paths resynchronise the client when the event is cancelled - the
     * pre-1.2 packet processor calls {@code sendContents()}, the modern one fails the inventory
     * transaction and resends - so cancelling here is enough to keep the stack visibly in the
     * player's inventory while the verdict is in flight.</p>
     *
     * <p>The stack travels in the inventory dialect (namespaced id, count, damage, optional NBT)
     * rather than the flat pair {@code block.break} uses: a dropped renamed sword has to arrive in
     * PocketMine with its name, and that dialect is the one both sides already agree on.</p>
     */
    @EventHandler(priority = EventPriority.MONITOR)
    public void onDrop(PlayerDropItemEvent event) {
        if (!wants("player.drop") || event.isCancelled()) {
            return;
        }
        Player player = event.getPlayer();
        Item stack = event.getItem();
        if (player.getUniqueId() == null || stack == null || stack.isNull()) {
            // Dropping air is not a drop; holding it would ask the host a question with no content.
            return;
        }
        Item item = stack.clone();
        event.setCancelled(true);
        hold("player.drop", player, Map.of("item", InventoryMirror.itemTuple(item)),
                () -> replayDrop(player, item));
    }

    /**
     * Holds a hotbar switch until PocketMine decides.
     *
     * <p>Cancelling {@code PlayerItemHeldEvent} stops Nukkit from moving the selection, but its own
     * cancel path only re-sends the inventory contents - the client's selected slot stays where the
     * player put it. So both verdicts need a correction: "allowed" moves the selection (which
     * sends the equipment packet), "denied" re-sends the selection the server still has.</p>
     */
    @EventHandler(priority = EventPriority.MONITOR)
    public void onItemHeld(PlayerItemHeldEvent event) {
        if (!wants("player.item.held") || event.isCancelled()) {
            return;
        }
        Player player = event.getPlayer();
        int slot = event.getSlot();
        if (player.getUniqueId() == null || slot < 0 || slot > 8) {
            // Not a hotbar slot: Nukkit's own answer is better than a guess.
            return;
        }
        int from = player.getInventory().getHeldItemIndex();
        if (from == slot) {
            return;
        }
        Map<String, Object> data = new LinkedHashMap<>(3);
        data.put("slot", slot);
        data.put("from", from);
        if (event.getItem() != null) {
            data.put("item", InventoryMirror.itemTuple(event.getItem()));
        }
        event.setCancelled(true);
        hold("player.item.held", player, data,
                () -> {
                    player.getInventory().setHeldItemIndex(slot);
                    return true;
                },
                () -> player.getInventory().setHeldItemIndex(from));
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onToggleSneak(PlayerToggleSneakEvent event) {
        Player player = event.getPlayer();
        holdToggle("player.toggle.sneak", event, player, event.isSneaking(), player::setSneaking);
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onToggleSprint(PlayerToggleSprintEvent event) {
        Player player = event.getPlayer();
        holdToggle("player.toggle.sprint", event, player, event.isSprinting(), player::setSprinting);
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onToggleFlight(PlayerToggleFlightEvent event) {
        Player player = event.getPlayer();
        holdToggle("player.toggle.fly", event, player, event.isFlying(), state -> {
            player.getAdventureSettings().set(AdventureSettings.Type.FLYING, state);
            player.getAdventureSettings().update();
        });
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onToggleGlide(PlayerToggleGlideEvent event) {
        Player player = event.getPlayer();
        holdToggle("player.toggle.glide", event, player, event.isGliding(), player::setGliding);
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onToggleSwim(PlayerToggleSwimEvent event) {
        Player player = event.getPlayer();
        holdToggle("player.toggle.swim", event, player, event.isSwimming(), player::setSwimming);
    }

    /**
     * Holds a block change Nukkit decided on its own (growth, spread, forming, burning, decay).
     *
     * <p>These are the one family where the replay is exact without a player: every one of them
     * ends in {@code Level::setBlock()} with a state Nukkit already computed, so "allowed" is that
     * same write a tick later. The event carries the state, not a click, which is why this works
     * where {@code BlockPlaceEvent} cannot.</p>
     *
     * <p>One handler for the grow/form/spread hierarchy on purpose: Nukkit dispatches parent
     * handlers too, so registering three would hold the same event three times.</p>
     */
    @EventHandler(priority = EventPriority.MONITOR)
    public void onBlockChange(BlockGrowEvent event) {
        String name = event instanceof BlockSpreadEvent ? "block.spread"
                : event instanceof BlockFormEvent ? "block.form"
                : "block.grow";
        if (!wants(name) || event.isCancelled()) {
            return;
        }
        Block block = event.getBlock();
        if (!mirrorsBlock(block)) {
            return;
        }
        Block newState = event.getNewState();
        Block source = event instanceof BlockSpreadEvent spread ? spread.getSource() : null;
        event.setCancelled(true);
        holdBlockChange(name, block, newState, source, true);
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onBlockBurn(BlockBurnEvent event) {
        if (!wants("block.burn") || event.isCancelled() || !mirrorsBlock(event.getBlock())) {
            return;
        }
        Block block = event.getBlock();
        // Nukkit's own replay target after the event is air, written without a neighbour update.
        Block air = Block.get(Block.AIR);
        event.setCancelled(true);
        holdBlockChange("block.burn", block, air, null, false);
    }

    @EventHandler(priority = EventPriority.MONITOR)
    public void onLeavesDecay(LeavesDecayEvent event) {
        if (!wants("leaves.decay") || event.isCancelled() || !mirrorsBlock(event.getBlock())) {
            return;
        }
        Block block = event.getBlock();
        Level level = block.getLevel();
        String world = level == null ? "" : level.getName();
        int x = block.getFloorX();
        int y = block.getFloorY();
        int z = block.getFloorZ();
        Map<String, Object> data = blockChangeData(block, block, null);
        event.setCancelled(true);
        // Decay is a break, not a state swap: `useBreakOn()` is what drops saplings and plays the
        // sound, and it is exactly what Nukkit would have called had the event not been held.
        hold("leaves.decay", null, data, () -> {
            Level target = server.getLevelByName(world);
            if (target == null) {
                return false;
            }
            Block fresh = target.getBlock(new Vector3(x, y, z));
            if (fresh.getId() == Block.AIR) {
                return false;
            }
            target.useBreakOn(fresh);
            return true;
        });
    }

    /**
     * A respawn is news, not a decision: PocketMine's event cannot be cancelled, and Nukkit has
     * already worked out where the player goes. Sending it lets kits, statistics and spawn logic
     * see the moment without pretending they can change it.
     */
    @EventHandler(priority = EventPriority.MONITOR)
    public void onRespawn(PlayerRespawnEvent event) {
        Player player = event.getPlayer();
        UUID id = player.getUniqueId();
        Position position = event.getRespawnPosition();
        if (id == null || position == null) {
            return;
        }
        Map<String, Object> data = new LinkedHashMap<>(8);
        data.put("x", position.getX());
        data.put("y", position.getY());
        data.put("z", position.getZ());
        // A respawn position carries no rotation; the player's own yaw/pitch are unchanged by it.
        data.put("yaw", player.getYaw());
        data.put("pitch", player.getPitch());
        data.put("world", position.getLevel() == null ? "" : position.getLevel().getName());
        data.put("firstSpawn", event.isFirstSpawn());
        queueEvent("player.respawn", id.toString(), data);
    }

    /**
     * A death is news: PocketMine 5's {@code PlayerDeathEvent} is not cancellable, and Nukkit has
     * already run the death tail. The drops and experience travel so statistics, death messages
     * and economy penalties can see the same numbers the client is about to see.
     */
    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onDeath(PlayerDeathEvent event) {
        Player player = event.getEntity();
        UUID id = player == null ? null : player.getUniqueId();
        if (id == null) {
            return;
        }
        List<List<Object>> drops = new ArrayList<>();
        Item[] dropped = event.getDrops();
        if (dropped != null) {
            for (Item item : dropped) {
                if (item != null && !item.isNull()) {
                    drops.add(InventoryMirror.itemTuple(item));
                }
            }
        }
        Map<String, Object> data = new LinkedHashMap<>(4);
        data.put("drops", drops);
        data.put("xp", event.getExperience());
        data.put("message", event.getDeathMessage() == null ? "" : event.getDeathMessage().getText());
        queueEvent("player.death", id.toString(), data);
    }

    /**
     * A client moved items in a container a PocketMine plugin owns.
     *
     * <p>Held rather than observed: Nukkit's event runs before the transaction executes, so
     * cancelling it keeps both sides honest. If the plugin allows the move, the host executes the
     * (possibly rewritten) actions and the resulting content snapshot reaches the client; if it
     * denies, Nukkit has already sent its own correction. A transaction that touches only the
     * player's inventory is left alone - that mirror has its own channel.</p>
     */
    @EventHandler(priority = EventPriority.MONITOR, ignoreCancelled = true)
    public void onInventoryTransaction(InventoryTransactionEvent event) {
        if (!hostMirrors) {
            return;
        }
        Map<String, Object> data = containers.capture(event);
        if (data == null) {
            return;
        }
        Player player = event.getTransaction().getSource();
        if (player.getUniqueId() == null) {
            return;
        }
        if (wants("inventory.transaction")) {
            event.setCancelled(true);
            // The host applies the actions; there is nothing to replay on this side. Nukkit
            // already sent the pre-transaction contents when the event was cancelled, and the
            // host's window content/slot op is what puts the verdict on screen.
            hold("inventory.transaction", player, data, () -> true);
            return;
        }
        // Nobody listens for the decision. Nukkit has already applied the move; the host still
        // needs it so the plugin's window inventory does not silently drift. No id means no
        // verdict is expected, and the host's event dispatch applies the same target items.
        queueEvent("inventory.transaction", uuidOf(player), data);
    }

    /**
     * Applies a verdict for a held action.
     *
     * <p>Denied means "put it back": for a break or a drop the action was already cancelled, but a
     * hotbar switch has to re-send the selection the server kept. Approved means replay it, which
     * is why the replay runs under {@code replayingActions} — replaying a break fires the same
     * event again and would otherwise be held a second time.</p>
     */
    private void resolveEvent(Map<String, Object> op) {
        long id = op.get("id") instanceof Number n ? n.longValue() : -1L;
        boolean allowed = Boolean.TRUE.equals(op.get("allowed"));
        HeldAction action = id < 0 ? null : heldActions.take(id);
        if (action == null) {
            // Timed out already, or the bookkeeping was dropped when interception was switched off.
            verdictsStale++;
            return;
        }
        if (!allowed) {
            deniedCounts.merge(action.event(), 1L, Long::sum);
            try {
                action.deny().run();
            } catch (RuntimeException e) {
                System.out.println("[mukkit] " + action.event() + ": putting the client back failed: "
                        + e.getMessage());
            }
            return;
        }
        replayingActions++;
        try {
            if (action.replay().getAsBoolean()) {
                replayedCounts.merge(action.event(), 1L, Long::sum);
            }
        } catch (RuntimeException e) {
            System.out.println("[mukkit] " + action.event() + ": replay failed: " + e.getMessage());
        } finally {
            replayingActions--;
        }
    }

    /**
     * Performs a drop the host allowed, after the fact.
     *
     * <p>The transaction that would have removed the stack was rolled back when the event was
     * cancelled, so the removal is part of the replay. It is checked first: if the stack is no
     * longer there in full (the player moved it, ate it, died), dropping anyway would create items
     * out of nothing, which is worse than losing the drop. Creative players keep the stack - that is
     * what Nukkit's own pre-1.2 path does, and the difference is visible to anyone who has dropped
     * something in creative.</p>
     */
    private boolean replayDrop(Player player, Item item) {
        if (player == null || player.isClosed()) {
            return false;
        }
        if (!player.isCreative()) {
            if (!hasFullStack(player, item)) {
                dropsUnreplayable++;
                if (!dropReplayReported) {
                    dropReplayReported = true;
                    System.out.println("[mukkit] player.drop: the stack was gone by the time the host"
                            + " allowed it; not dropping a phantom item (further occurrences only counted)");
                }
                return false;
            }
            player.getInventory().removeItem(item);
        }
        player.dropItem(item);
        return true;
    }

    /**
     * Whether a PocketMine plugin is listening for this event right now.
     *
     * <p>{@code replayingActions} is part of the answer: a replay must not be held again, or the
     * verdict for the first one would create a second pending action nobody will answer.</p>
     */
    private boolean wants(String event) {
        return replayingActions == 0 && hostWantsEvents.contains(event);
    }

    private void hold(String event, Player player, Map<String, Object> data, BooleanSupplier replay) {
        hold(event, player, data, replay, () -> {
        });
    }

    /**
     * Records an action, its replay, and asks the host.
     *
     * @param player the decision's subject, or {@code null} for a world event (a growing block has
     *               no player behind it; the frame then carries only the world)
     */
    private void hold(String event, Player player, Map<String, Object> data, BooleanSupplier replay, Runnable deny) {
        long requestId = heldActions.add(new HeldAction(event, replay, deny));
        heldCounts.merge(event, 1L, Long::sum);
        Map<String, Object> frame = new LinkedHashMap<>(6);
        frame.put("k", "event");
        frame.put("name", event);
        if (player != null) {
            frame.put("player", uuidOf(player));
        }
        frame.put("id", requestId);
        frame.put("data", data);
        inbound.add(frame);
    }

    /** A toggle: "allowed" applies the state Nukkit was about to apply; "denied" needs no replay. */
    private void holdToggle(String name, Cancellable event, Player player, boolean state,
                            java.util.function.Consumer<Boolean> apply) {
        if (!wants(name) || event.isCancelled() || player.getUniqueId() == null) {
            return;
        }
        Map<String, Object> data = new LinkedHashMap<>(1);
        data.put("state", state);
        event.setCancelled(true);
        hold(name, player, data, () -> {
            apply.accept(state);
            return true;
        });
    }

    private void holdBlockChange(String name, Block block, Block newState, Block source, boolean update) {
        Level level = block.getLevel();
        if (level == null) {
            return;
        }
        String world = level.getName();
        int x = block.getFloorX();
        int y = block.getFloorY();
        int z = block.getFloorZ();
        int newId = newState.getId();
        int newMeta = newState.getDamage();
        Map<String, Object> data = blockChangeData(block, newState, source);
        hold(name, null, data, () -> {
            Level target = server.getLevelByName(world);
            if (target == null) {
                return false;
            }
            return update
                    ? target.setBlock(new Vector3(x, y, z), Block.get(newId, newMeta), true, true)
                    : target.setBlock(new Vector3(x, y, z), Block.get(newId, newMeta), true);
        });
    }

    private static Map<String, Object> blockChangeData(Block block, Block newState, Block source) {
        Map<String, Object> data = new LinkedHashMap<>(10);
        data.put("world", block.getLevel() == null ? "" : block.getLevel().getName());
        data.put("x", block.getFloorX());
        data.put("y", block.getFloorY());
        data.put("z", block.getFloorZ());
        data.put("id", block.getId());
        data.put("meta", block.getDamage());
        data.put("newId", newState.getId());
        data.put("newMeta", newState.getDamage());
        if (source != null) {
            data.put("source", Map.of(
                    "x", source.getFloorX(),
                    "y", source.getFloorY(),
                    "z", source.getFloorZ(),
                    "id", source.getId(),
                    "meta", source.getDamage()));
        }
        return data;
    }

    /**
     * Whether the host mirrors the chunk this block lives in.
     *
     * <p>Level-wide is not enough: a level may have mirrored chunks around one player and none
     * around another. A block event from an unmirrored chunk would be applied by the host to air,
     * so it is not sent at all.</p>
     */
    private boolean mirrorsBlock(Block block) {
        Level level = block.getLevel();
        return hostMirrors && level != null
                && chunks.mirrors(level.getName(), block.getChunkX(), block.getChunkZ());
    }

    /** Whether the inventory still holds the whole stack, so removing it cannot eat a partial one. */
    private static boolean hasFullStack(Player player, Item item) {
        int found = 0;
        for (Item slot : player.getInventory().getContents().values()) {
            if (slot != null && slot.equals(item, true, true)) {
                found += slot.getCount();
            }
        }
        return found >= item.getCount();
    }

    /** Replaces the set of actions PocketMine wants to judge. */
    private void applyCapabilities(Map<String, Object> op) {
        Set<String> wanted = Set.copyOf(strings(op.get("events")));
        hostWantsEvents = wanted;
        // Interception can be switched off under our feet (the plugin that listened was disabled).
        // Hanging on to actions nobody will judge would freeze those players for the full timeout,
        // so each withdrawn name drops its own pending entries and leaves the rest alone.
        int dropped = heldActions.removeIf(action -> !wanted.contains(action.event()));
        if (dropped > 0) {
            System.out.println("[mukkit] capabilities: " + dropped
                    + " held action(s) dropped, nobody is listening for them any more");
        }
        System.out.println("[mukkit] capabilities: events=" + wanted);
    }

    private static List<String> strings(Object value) {
        if (!(value instanceof List<?> list)) {
            return List.of();
        }
        List<String> out = new ArrayList<>(list.size());
        for (Object entry : list) {
            if (entry != null) {
                out.add(String.valueOf(entry));
            }
        }
        return out;
    }

    private void queueEvent(String name, String playerUuid, Map<String, Object> data) {
        Map<String, Object> item = new LinkedHashMap<>(4);
        item.put("k", "event");
        item.put("name", name);
        item.put("player", playerUuid);
        item.put("data", data);
        inbound.add(item);
        events++;
    }

    /** Lifecycle notices for the host: the JVM is what decides when a plugin turns on or off. */
    void queuePluginLifecycle(String pluginName, String action) {
        Map<String, Object> item = new LinkedHashMap<>(3);
        item.put("k", "plugin");
        item.put("plugin", pluginName);
        item.put("name", action);
        inbound.add(item);
    }

    /**
     * Feeds a synthetic event into the same queue real events use, so harnesses can drive the
     * full host round trip before a player proxy exists. Never call this in production paths.
     */
    void queueSyntheticEvent(String name, String playerUuid, Map<String, Object> data) {
        queueEvent(name, playerUuid, data);
    }

    @Override
    public List<Map<String, Object>> drainInbound() {
        // The one per-tick hook this gateway gets. Polling here rather than in a scheduler task
        // keeps player state on the same tick boundary as the frame that carries it.
        playerState.poll();
        inventory.poll();
        chunks.poll();
        // After chunks, not before: a chunk sent this tick already carries its tiles, and the
        // tile mirror seeds those instead of emitting them a second time.
        tiles.poll();
        worldState.poll();
        entities.poll();

        // A held action must not outlive the host that was supposed to judge it.
        List<HeldAction> expired = heldActions.advance();
        for (HeldAction action : expired) {
            expiredCounts.merge(action.event(), 1L, Long::sum);
            if (expiryReported.add(action.event())) {
                System.out.println("[mukkit] " + action.event() + ": host did not answer in time, "
                        + "action dropped and not replayed (further occurrences only counted)");
            }
        }
        if (inbound.isEmpty()) {
            return List.of();
        }
        List<Map<String, Object>> drained = new ArrayList<>(inbound.size());
        Map<String, Object> item;
        while ((item = inbound.poll()) != null) {
            drained.add(item);
        }
        return drained;
    }

    // ---------------------------------------------------------------- outbound

    @Override
    public void applyOp(Map<String, Object> op) {
        String kind = String.valueOf(op.get("o"));
        opKinds.merge(kind, 1, Integer::sum);
        switch (kind) {
            case "log" -> printPluginLog(op);
            case "packet" -> applyPacket(op);
            case "block" -> applyBlocks(op);
            case "tile" -> tiles.apply(op);
            case "player.state" -> playerState.apply(op);
            case "player.inventory" -> inventory.apply(op);
            case "window.open" -> containers.open(op);
            case "window.content" -> containers.content(op);
            case "window.slot" -> containers.slot(op);
            case "window.close" -> containers.close(op);
            case "window.data" -> containers.data(op);
            case "world.state" -> worldState.apply(op);
            case "entity.state" -> entities.apply(op);
            case "event.result" -> resolveEvent(op);
            case "capabilities" -> applyCapabilities(op);
            default -> {
                unknownOps++;
                System.out.println("[mukkit] skipped unknown op '" + kind + "' (protocol is newer than this bridge)");
            }
        }
    }

    /**
     * Prints one line a PocketMine plugin logged.
     *
     * <p>The level travels with the op, so it is honoured here instead of every level landing on
     * the console: a plugin's {@code debug()} calls are debug lines on this side too, and show up
     * only when Nukkit's own level is raised. Everything from {@code info} up keeps going to
     * stdout, which is where the console and the file appender pick it up.</p>
     */
    private void printPluginLog(Map<String, Object> op) {
        String level = String.valueOf(op.get("level"));
        String line = "[plugin:" + level + "] " + op.get("msg");
        if ("debug".equals(level)) {
            server.getLogger().debug(line);
            return;
        }
        System.out.println(line);
    }

    @SuppressWarnings("unchecked")
    private void applyPacket(Map<String, Object> op) {
        String target = String.valueOf(op.get("to"));
        String className = String.valueOf(op.get("cls"));
        Map<String, Object> fields = op.get("f") instanceof Map<?, ?> map
                ? (Map<String, Object>) map
                : Map.of();

        // The one "packet" that is not delivered as a packet: a disconnect is the end of the
        // player, not something to show them. See DisconnectRouting.
        if ("DisconnectPacket".equals(className)) {
            applyDisconnect(target, fields);
            return;
        }

        DataPacket packet;
        try {
            packet = PacketMapper.map(className, fields);
        } catch (RuntimeException e) {
            System.out.println("[mukkit] bad packet op: " + e.getMessage());
            return;
        }
        if (packet == null) {
            System.out.println("[mukkit] packet " + className + " is not in the whitelist, dropped");
            return;
        }

        Collection<Player> targets = resolveTargets(target);
        int minimum = PacketMapper.minimumProtocol(packet);
        List<Player> reachable = new ArrayList<>(targets.size());
        for (Player player : targets) {
            if (player.protocol < minimum) {
                // The client predates this packet; sending an id it does not know can disconnect it.
                packetsTooOld++;
                continue;
            }
            reachable.add(player);
        }
        if (packet instanceof ModalFormRequestPacket request) {
            // Recorded before the packet leaves, not after the client answers: the response can
            // arrive on the very next tick, and a form the ledger has not heard of is a form whose
            // answer gets thrown away.
            for (Player player : reachable) {
                UUID id = player.getUniqueId();
                if (id != null) {
                    forms.remember(id, request.formId);
                }
            }
        }
        for (Player player : reachable) {
            player.dataPacket(packet);
        }
        packets++;
        recipients += reachable.size();

        String hint = String.valueOf(fields.getOrDefault("message",
                fields.getOrDefault("text",
                        fields.getOrDefault("title", fields.getOrDefault("objectiveName", "")))));
        if (samples.size() < SAMPLE_LIMIT) {
            samples.add(className + " -> " + target + " (" + reachable.size() + ") " + trim(hint));
        }
        // Debug, not info: a scoreboard plugin alone sends a handful of SetScorePacket per second,
        // per player, and at INFO that is a console nobody can read. The counts and the first few
        // samples are still in `summary()` (`mukkit status`), so nothing is lost by staying quiet;
        // an operator who needs the trace raises Nukkit's own level:
        // `debug-settings.debug-level: 2` in nukkit-mot.yml, or `-debug` on the command line.
        server.getLogger().debug("[packet] " + className + " to=" + target + " recipients=" + reachable.size()
                + " hint=" + trim(hint));
    }

    /**
     * Closes a player the PocketMine side has already disconnected.
     *
     * <p>Two things make this different from sending a packet. The player has to be removed from
     * Nukkit as well, otherwise it lives on as a ghost that plugins can still act on; and the
     * host must not be told about the quit it caused, because it ran that path already.</p>
     */
    private void applyDisconnect(String target, Map<String, Object> fields) {
        String message = DisconnectRouting.screenMessage(fields);
        int closed = 0;
        // A copy: closing a player removes it from the online map, and a broadcast target is a
        // live view of that map.
        for (Player player : List.copyOf(resolveTargets(target))) {
            if (player.isClosed()) {
                continue;
            }
            UUID id = player.getUniqueId();
            if (id != null) {
                // Before close(): PlayerQuitEvent is fired from inside that call.
                hostDisconnects.add(id);
            }
            // Empty quit message (the host already broadcast the leave line), non-empty screen
            // text (Nukkit sends no disconnect packet at all without one).
            player.close("", message, true);
            closed++;
            disconnects++;
        }
        System.out.println("[mukkit] kick " + target + " recipients=" + closed + " hint=" + trim(message));
    }

    private Collection<Player> resolveTargets(String target) {
        Collection<Player> online = server.getOnlinePlayers().values();
        if ("*".equals(target) || "console".equals(target)) {
            return online;
        }
        UUID uuid;
        try {
            uuid = UUID.fromString(target);
        } catch (IllegalArgumentException e) {
            return List.of();
        }
        Player player = server.getOnlinePlayers().get(uuid);
        return player == null ? List.of() : List.of(player);
    }

    // ---------------------------------------------------------------- Gateway

    @Override
    public void onLog(String level, String message) {
        System.out.println("[host:" + level + "] " + message);
    }

    @Override
    public void onReady(Map<String, Object> payload) {
        hostOwnsBroadcasts = "host".equals(payload.get("broadcasts"));
        hostMirrors = Boolean.TRUE.equals(payload.get("mirror"));
        hostOwnsCommands = Boolean.TRUE.equals(payload.get("commands"));
        hostWantsEvents = Set.copyOf(strings(payload.get("events")));
        // A host that has just booted has no forms open, whatever the previous one had. Keeping
        // the old ids would let an answer be forwarded to a process that will only report it as
        // unexpected.
        int dropped = forms.clear();
        // A host that has just booted has empty inventories. Dropping the shadows makes the next
        // poll a full snapshot; keeping them would leave the new host blind until a slot changed.
        inventory.forgetAll();
        inventory.verifyVocabulary();
        // A new host has no windows open either; leaving the old stand-ins registered would
        // route clicks into an inventory nobody owns any more.
        containers.forgetAll();
        // A new host has no chunks either; whatever the previous one was subscribed to is gone.
        chunks.forgetAll();
        // ...and no block entities: the chunk frames that follow carry them again.
        tiles.forgetAll();
        // Quit echoes were for the previous host, which already ran its own quit paths.
        hostDisconnects.clear();
        worldState.forgetAll();
        entities.forgetAll();
        System.out.println("[mukkit] host ready: loaded=" + payload.get("loaded")
                + " rejected=" + payload.get("rejected")
                + " broadcasts=" + (hostOwnsBroadcasts ? "host" : "nukkit")
                + " commands=" + (hostOwnsCommands ? "host" : "nukkit")
                + " mirror=" + hostMirrors
                + " events=" + hostWantsEvents
                + (dropped == 0 ? "" : " (dropped " + dropped + " stale form id(s))"));
    }

    @Override
    public void onHostLost(String reason) {
        hostOwnsBroadcasts = false;
        hostOwnsCommands = false;
        System.out.println("[mukkit] host lost: " + reason
                + " (Nukkit now delivers chat and answers commands itself)");
    }

    // ---------------------------------------------------------------- report

    String summary() {
        StringBuilder out = new StringBuilder();
        out.append("inbound          events ").append(events).append(", commands ").append(commands)
                .append(" (").append(playerCommands).append(" from players)\n");
        if (formResponses > 0 || formResponsesStale > 0 || forms.size() > 0) {
            out.append("forms            ").append(formResponses).append(" answered, ")
                    .append(formResponsesStale).append(" not ours, ")
                    .append(forms.size()).append(" still open\n");
        }
        out.append("packets          ").append(packets).append(" sent to ").append(recipients).append(" recipients\n");
        if (disconnects > 0) {
            out.append("kicks            ").append(disconnects).append(" closed at the host's request\n");
        }
        if (packetsTooOld > 0) {
            out.append("packets          ").append(packetsTooOld)
                    .append(" not sent: the client protocol predates them\n");
        }
        out.append("op kinds         ").append(opKinds).append('\n');
        if (!heldCounts.isEmpty()) {
            out.append("held actions     ").append(heldCounts)
                    .append(" denied ").append(deniedCounts)
                    .append(" replayed ").append(replayedCounts);
            if (!expiredCounts.isEmpty()) {
                out.append(" expired ").append(expiredCounts);
            }
            if (verdictsStale > 0) {
                out.append(" stale verdicts ").append(verdictsStale);
            }
            out.append('\n');
        }
        java.util.List<String> mirrored = chunks.describe();
        if (!mirrored.isEmpty()) {
            out.append("chunks mirrored  ").append(String.join(", ", mirrored)).append('\n');
        }
        if (unknownOps > 0) {
            out.append("unknown ops      ").append(unknownOps).append('\n');
        }
        for (String sample : samples) {
            out.append("  sample: ").append(sample).append('\n');
        }
        return out.toString();
    }

    long packets() {
        return packets;
    }

    private static String nameOf(Player player) {
        String name = player.getName();
        return name == null ? "unknown" : name;
    }

    private static String uuidOf(Player player) {
        UUID uuid = player.getUniqueId();
        return uuid == null ? "" : uuid.toString();
    }

    private static String trim(String message) {
        if (message == null) {
            return "null";
        }
        return message.length() <= 60 ? message : message.substring(0, 60) + "...";
    }
}
