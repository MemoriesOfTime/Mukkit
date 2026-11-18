<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * Boots the PocketMine fork inside this process and turns bridge frames into real PM calls.
 *
 * This is the only place in pm-host that is allowed to touch the fork (see
 * scripts/check-architecture.sh). Everything it does is "call upstream, do not reimplement":
 * plugins are loaded by PM's own PluginManager, commands go through PM's CommandMap, ticks call
 * Server::tick().
 *
 * Player events are dispatched against real `Player` instances: `player.join` creates a
 * bridged player through `Server::addOnlinePlayer()` + `Player::doFirstSpawn()`, `player.chat`
 * goes through `Player::chat()`, `player.quit` through `NetworkSession::disconnect()` +
 * `Player::onPostDisconnect()`. Clientbound packets those calls produce are drained from the
 * session outbox and forwarded as `packet` ops through the packet whitelist.
 */
final class PmRuntime
{
    private const EVENT_UNSUPPORTED = 'event.unsupported';

    /** Upstream's own limits (`InGamePacketHandler`); a real client never comes close. */
    private const MAX_FORM_RESPONSE_SIZE = 10 * 1024;
    private const MAX_FORM_RESPONSE_DEPTH = 2;

    private ?\pocketmine\mukkit\HeadlessServer $server = null;

    private ?\pocketmine\mukkit\BridgedPlayers $players = null;

    /** @var array<string, \pocketmine\mukkit\BridgeWorldMirror> world name => mirror */
    private array $mirrors = [];

    private ?\pocketmine\mukkit\BridgePlayerState $playerState = null;

    private ?\pocketmine\mukkit\BridgePlayerInventory $inventory = null;

    private ?\pocketmine\mukkit\BridgeWorldState $worldState = null;

    private ?\pocketmine\mukkit\BridgeEntityMirror $entities = null;

    private ?\pocketmine\mukkit\BridgeEvents $events = null;

    /** @var list<string> */
    private array $announcedEvents = [];

    /** @var array<string, true> */
    private array $reportedGaps = [];

    /** @var list<array<string, mixed>> */
    private array $logOps = [];

    /** @var array<string, true> */
    private array $reportedUnsupported = [];

    /** @var array<string, int> */
    private array $droppedPackets = [];

    public function __construct(
        private readonly string $pmRoot,
        private readonly string $dataPath,
        private readonly string $pluginPath,
        /** Nukkit's default level, so `getDefaultWorld()` answers before the first join. */
        private readonly ?string $defaultWorld = null,
        /**
         * Where each plugin's own data folder goes. `plugin-folder` is PocketMine's "legacy"
         * layout and also Nukkit's habit: `<server>/plugins/<PluginName>/`, beside the plugin
         * itself. `isolated` keeps everything under pm-data/plugin_data/. Anything else falls
         * back to `plugin-folder`, which is what the JVM validates and sends.
         */
        private readonly string $dataLayout = 'plugin-folder',
    ) {
    }

    public function isBooted(): bool
    {
        return $this->server !== null;
    }

    /**
     * Boots the fork and loads whatever is in the plugin directory.
     *
     * @return array{loaded: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    public function boot(): array
    {
        $this->requireFork();

        if (!is_dir($this->dataPath) && !mkdir($this->dataPath, 0777, true) && !is_dir($this->dataPath)) {
            throw new \RuntimeException('cannot create PM data dir: ' . $this->dataPath);
        }
        if (!is_dir($this->pluginPath) && !mkdir($this->pluginPath, 0777, true) && !is_dir($this->pluginPath)) {
            throw new \RuntimeException('cannot create PM plugin dir: ' . $this->pluginPath);
        }
        $this->writeHeadlessConfig();

        // The bridge owns lifecycle and liveness; PM must not throttle its own ticks or try to
        // read a console nobody is attached to.
        // Anything at error level also goes to STDERR immediately: a boot-time crash must not be
        // discoverable only after the process has already died with the ops unsent.
        $logger = new \pocketmine\mukkit\HeadlessLogger(false);
        $logger->setSink(function (string $level, string $message): void {
            $this->logOps[] = Protocol::logOp(strtolower($level), $message);
            if (in_array(strtolower($level), ['alert', 'critical', 'emergency', 'error'], true)) {
                fwrite(STDERR, '[mukkit-host][' . strtoupper($level) . '] ' . $message . PHP_EOL);
            }
        });

        $loader = new \pocketmine\thread\ThreadSafeClassLoader();
        $loader->register(false);

        $server = new \pocketmine\mukkit\HeadlessServer($loader, $logger, $this->dataPath, $this->pluginPath, $this->defaultWorld);
        if (!$server->isBootComplete()) {
            throw new \RuntimeException('PM constructor returned before boot completed');
        }
        $this->server = $server;
        $this->players = new \pocketmine\mukkit\BridgedPlayers($server);

        $loaded = [];
        $rejected = [];
        foreach ($server->getPluginManager()->getPlugins() as $plugin) {
            $description = $plugin->getDescription();
            $loaded[] = [
                'name' => $description->getName(),
                'version' => $description->getVersion(),
                'api' => implode(', ', $description->getCompatibleApis()),
                'status' => $plugin->isEnabled() ? 'enabled' : 'loaded',
                'runtime' => 'pocketmine',
            ];
        }
        // PM reports load failures through its logger rather than a return value, so anything it
        // logged at error level during boot is what the bridge gets to show.
        foreach ($this->drainLogOps('error') as $op) {
            $rejected[] = ['name' => 'unknown', 'reason' => (string) ($op['msg'] ?? 'load error')];
        }

        //The ready frame already carries this, but it has to be remembered here: otherwise the
        //first plugin toggle would compare against "nothing announced" and see no change when
        //interception has in fact just been switched off.
        $this->announcedEvents = $this->eventDemand();

        return ['loaded' => $loaded, 'rejected' => $rejected];
    }

    /**
     * @param list<array<string, mixed>> $inbound
     * @return list<array<string, mixed>>
     */
    public function tick(array $inbound): array
    {
        $server = $this->requireServer();
        $ops = [];

        foreach ($inbound as $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = (string) ($item['k'] ?? '');
            try {
                switch ($kind) {
                    case 'command':
                        $this->dispatchCommand($item);
                        break;

                    case 'event':
                        $this->dispatchEvent($item, $ops);
                        break;

                    case 'plugin':
                        $this->togglePlugin($item, $ops);
                        break;

                    case 'chunk':
                        $this->applyChunk($item);
                        break;

                    case 'chunk.unload':
                        $this->applyChunkUnload($item);
                        break;

                    case 'block.update':
                        $this->applyRemoteBlock($item);
                        break;

                    case 'tile':
                        $this->applyRemoteTiles($item);
                        break;

                    case 'player.state':
                        $this->applyRemotePlayerState($item);
                        break;

                    case 'player.inventory':
                        $this->applyRemoteInventory($item);
                        break;

                    case 'world.state':
                        $this->applyRemoteWorldState($item);
                        break;

                    case 'entity.sync':
                        $this->applyEntitySync($item);
                        break;

                    case 'form.response':
                        $this->dispatchFormResponse($item);
                        break;

                    default:
                        $ops[] = Protocol::logOp('warn', 'pm profile: unknown inbound kind: ' . ($kind === '' ? '?' : $kind));
                }
            } catch (\Throwable $e) {
                $ops[] = Protocol::logOp('error', sprintf(
                    'pm %s failed: %s',
                    $kind === '' ? '?' : $kind,
                    $e->getMessage()
                ));
            }
        }

        $server->tick();

        // Local edits are collected *after* the tick, so a block a plugin wrote this tick goes out
        // together with everything else the tick produced. Inbound changes were applied before
        // `tick()`, which is what makes the mirror lag by at most one tick and never lead.
        //
        // One op per world: `block` already names its world, and a plugin writing in two arenas
        // in the same tick must not have its edits welded into one list with one world name.
        foreach ($this->mirrors as $world => $mirror) {
            try {
                $changes = $mirror->takeChanges();
            } catch (\Throwable $e) {
                // A mirror that cannot diff must not wedge every later tick: the chunk stays
                // dirty, so the next tick would throw the same thing again and the bridge would
                // never advance. Drop this tick's edits, say so, keep going - same rule as a
                // throwing plugin in Host::handleTick().
                $ops[] = Protocol::logOp('error', sprintf('pm block diff failed in %s: %s', (string) $world, $e->getMessage()));
                $mirror->discardPendingChanges();
                continue;
            }
            if ($changes !== []) {
                $ops[] = Protocol::blockOp((string) $world, $changes);
            }

            // Tiles are collected separately: a tile edit is not a block edit, and one chest
            // opening at the same tick a block changed must not be welded into the block op.
            try {
                $tiles = $mirror->takeTileChanges();
            } catch (\Throwable $e) {
                $ops[] = Protocol::logOp('error', sprintf('pm tile diff failed in %s: %s', (string) $world, $e->getMessage()));
                $tiles = [];
            }
            if ($tiles !== []) {
                $ops[] = Protocol::tileOp((string) $world, $tiles);
            }
        }

        // One clock per world, not per player: everybody in a world is looking at the same
        // number, and a world with nobody in it has no client to show it to.
        $worlds = [];
        foreach ($this->players?->all() ?? [] as $player) {
            $world = $player->getWorld();
            $worlds[$world->getFolderName()] ??= $world;
        }
        foreach ($worlds as $name => $world) {
            $state = $this->worldStateOrFail()->diff($name, $world);
            if ($state !== []) {
                $ops[] = Protocol::worldStateOp($name, $state);
            }

            // Entities a plugin moved or hurt. The mirror's own sync re-seeds the shadow, so
            // Nukkit-driven changes never show up here.
            $changed = $this->entitiesOrFail()->diff($name);
            if ($changed !== []) {
                $ops[] = Protocol::entityStateOp($name, $changed);
            }
        }

        // Same "collect after the tick" rule as blocks: a plugin that teleported a player in this
        // tick gets its teleport in this tick's frame, and one that did nothing produces no op.
        foreach ($this->players?->all() ?? [] as $uuid => $player) {
            $state = $this->playerStateOrFail()->diff((string) $uuid, $player);
            if ($state !== []) {
                $ops[] = Protocol::playerStateOp((string) $uuid, $state);
            }

            // The first diff for a player is a full snapshot: nothing carried the inventory over
            // the way the join frame carries the position, so the host seeds it here.
            $inventory = $this->inventoryOrFail()->diff((string) $uuid, $player);
            foreach ($inventory['gaps'] as $gap) {
                $this->reportGap('inventory: ' . $gap);
            }
            if ($inventory['slots'] !== []) {
                $ops[] = Protocol::playerInventoryOp((string) $uuid, $inventory['slots']);
            }
        }

        // Outboxes are drained before the log queue on purpose: mapping a packet can itself
        // produce a log op (a drop warning), and draining logs first would push that warning
        // into the next tick, where it looks like it belongs to whatever happens next.
        //
        // Container windows need one explicit flush: a bridged session is not registered with
        // PocketMine's network manager, so `NetworkSession::tick()` never runs for it and the
        // inventory manager's pending slot/content packets would otherwise stay queued forever.
        foreach ($this->players?->all() ?? [] as $player) {
            $session = $player->getNetworkSession();
            if ($session instanceof \pocketmine\mukkit\BridgedSession) {
                $session->flushInventoryUpdates();
            }
        }
        $packets = $this->drainSessionOutboxes();

        return array_merge($this->drainLogOps(), $ops, $packets);
    }

    public function shutdown(): void
    {
        if ($this->server === null) {
            return;
        }
        try {
            // Plugins expect a quit event and their data saved; leaving players "online" while
            // the server dies is the kind of half-state that corrupts EconomyAPI's Money.yml.
            foreach ($this->players?->all() ?? [] as $uuid => $player) {
                try {
                    $this->players->quit($uuid, 'server shutting down');
                } catch (\Throwable $e) {
                    fwrite(STDERR, '[mukkit-host] quit ' . $player->getName() . ' failed: ' . $e->getMessage() . PHP_EOL);
                }
            }
            // Despawn mirrored entities while plugins are still enabled, so anything cleaning up
            // on EntityDespawnEvent sees a coherent world instead of objects vanishing with the
            // process.
            $this->entities?->forgetAll();
            $this->server->shutdown();
        } finally {
            $this->server = null;
            $this->players = null;
            // The worlds outlive the mirrors for as long as the process does; dropping the
            // mirrors keeps a later `boot()` from diffing against a world nobody is serving.
            $this->mirrors = [];
            $this->worldState = null;
            $this->entities = null;
            $this->events = null;
        }
    }

    private function dispatchCommand(array $item): void
    {
        $server = $this->requireServer();
        $line = $item['line'] ?? null;
        if (!is_string($line) || trim($line) === '') {
            throw new \RuntimeException('command requires a non-empty line');
        }
        // The slash is punctuation, not part of the name; both senders want it gone.
        $line = ltrim($line, '/');

        $uuid = (string) ($item['player'] ?? '');
        if ($uuid !== '') {
            // A player typed this. Dispatching as the player and not as console is the whole
            // point: permissions, `$sender instanceof Player` and `getPosition()` all depend on
            // it, and a plugin answering `$sender` would otherwise talk to the wrong party.
            $sender = $this->playersOrFail()->get($uuid);
            if ($sender === null) {
                // Left between frames, or a uuid that was never proxied. Running it as console
                // would let a gone player quietly execute server commands.
                $name = (string) ($item['sender'] ?? '');
                $this->logOps[] = Protocol::logOp('warn', sprintf(
                    'command from unknown player %s dropped: %s',
                    $name === '' ? $uuid : $name . ' (' . $uuid . ')',
                    $line
                ));
                return;
            }
        } else {
            $sender = new \pocketmine\console\ConsoleCommandSender($server, $server->getLanguage());
        }

        // PM's console sender writes command output with echo (Terminal::writeLine), so this is
        // where it becomes bridge ops instead of escaping to the host's stdout unlabelled. A
        // player's output travels as packets instead, but a handler that prints would leak for
        // either sender, so the capture covers both.
        ob_start();
        try {
            $handled = $server->dispatchCommand($sender, $line);
        } finally {
            $output = (string) ob_get_clean();
        }

        foreach (preg_split('/\R/', trim($output)) ?: [] as $row) {
            if ($row !== '') {
                $this->logOps[] = Protocol::logOp('info', self::stripAnsi($row));
            }
        }

        if (!$handled) {
            $this->logOps[] = Protocol::logOp('warn', 'command not handled by any plugin: ' . $line);
        }
    }

    /**
     * Turns one inbound `event` frame into the upstream event it names.
     *
     * Dotted names are the bridge's vocabulary, not PocketMine's: the JVM decides what happened
     * on the Nukkit side and this maps it onto PM's own lifecycle calls. Anything else is
     * reported once instead of being silently swallowed.
     *
     * @param list<array<string, mixed>> $ops
     */
    private function dispatchEvent(array $item, array &$ops): void
    {
        $name = (string) ($item['name'] ?? '');
        $uuid = (string) ($item['player'] ?? '');
        $data = is_array($item['data'] ?? null) ? $item['data'] : [];

        // Decisions and observations are answered even when the frame names no player: a crop
        // growing has no player behind it, and the adapter is waiting for a verdict either way.
        // Falling through to the join/quit/chat switch with an empty uuid stays a reported drop.
        if (isset(\pocketmine\mukkit\BridgeEvents::CANCELLABLE[$name])) {
            $this->dispatchBridgeEvent($item, $name, $uuid, $data, $ops);
            return;
        }

        if (isset(\pocketmine\mukkit\BridgeEvents::NOTIFY[$name])) {
            $this->dispatchNotifyEvent($name, $uuid, $data);
            return;
        }

        if ($uuid === '') {
            $this->logOps[] = Protocol::logOp('warn', "pm event {$name} has no player uuid; dropped");
            return;
        }

        try {
            switch ($name) {
                case 'player.join':
                    $name = (string) ($data['name'] ?? 'player');
                    //Before the player exists: `Player::__construct()` reads the operator list
                    //once and never looks at it again, so an operator has to be in it by then or
                    //`PlayerJoinEvent` handlers see a player who cannot use their own commands.
                    foreach (\pocketmine\mukkit\BridgeOps::sync($this->requireServer(), $name, ($data['op'] ?? false) === true) as $gap) {
                        $this->reportGap('operator: ' . $gap);
                    }
                    //The hotbar selection is one small int. Anything else is a broken frame, not
                    //a slot, and `BridgedPlayers::join()` would rightly ignore it - say so here
                    //instead of letting the player silently start on slot 0.
                    if (array_key_exists('heldSlot', $data)
                        && (!is_int($data['heldSlot']) || $data['heldSlot'] < 0 || $data['heldSlot'] > 8)) {
                        $this->reportGap('player.join: held slot '
                            . (is_scalar($data['heldSlot']) ? var_export($data['heldSlot'], true) : gettype($data['heldSlot']))
                            . ' is outside 0-8; ignored');
                        unset($data['heldSlot']);
                    }
                    $player = $this->playersOrFail()->join($uuid, $name, $data);
                    //Seed from the join position: nothing has changed yet, so the first tick must
                    //not push a state op for a player that just arrived. The held slot is seeded
                    //from the frame, not from the player, so a plugin that selects a slot inside
                    //PlayerJoinEvent still shows up in this tick's diff.
                    $this->playerStateOrFail()->remember(
                        $uuid,
                        $player,
                        isset($data['heldSlot']) && is_int($data['heldSlot']) ? $data['heldSlot'] : null
                    );
                    return;

                case 'player.quit':
                    $player = $this->playersOrFail()->quit($uuid, (string) ($data['reason'] ?? 'client disconnected'));
                    $this->playerStateOrFail()->forget($uuid);
                    $this->inventoryOrFail()->forget($uuid);
                    if ($player === null) {
                        $this->logOps[] = Protocol::logOp('warn', 'player.quit for unknown player ' . $uuid);
                    }
                    return;

                case 'player.chat':
                    $this->playerChat($uuid, (string) ($data['message'] ?? ''));
                    return;
            }
        } catch (\Throwable $e) {
            $this->logOps[] = Protocol::logOp('error', sprintf('pm event %s failed: %s', $name, $e->getMessage()));
            return;
        }

        $this->reportUnsupportedEvent($name, $ops);
    }

    /**
     * Chat goes through `Player::chat()` rather than building PlayerChatEvent by hand: that way
     * plugins see the event with upstream's formatter and recipients, and the formatted result
     * is broadcast to every online player's session - which is where the `packet` ops for the
     * other players come from.
     */
    private function playerChat(string $uuid, string $message): void
    {
        $players = $this->playersOrFail();
        $player = $players->get($uuid);
        if ($player === null) {
            $this->logOps[] = Protocol::logOp('warn', 'player.chat from unproxied player ' . $uuid . ' (join was never dispatched)');
            return;
        }
        if (trim($message) === '') {
            return;
        }
        $player->chat($message);
    }

    /**
     * Drains whatever the players' sessions tried to send, as bridge `packet` ops.
     *
     * @return list<array<string, mixed>>
     */
    private function drainSessionOutboxes(): array
    {
        if ($this->players === null) {
            return [];
        }

        $ops = [];
        foreach ($this->players->all() as $uuid => $player) {
            foreach ($player->getNetworkSession()->takeOutbox() as $packet) {
                //A disconnect is not a packet to deliver, it is the end of the player. PocketMine
                //has already run its quit path by the time this is queued (`kick()` calls
                //`disconnect()`, which fires the event and broadcasts the leave line), so the
                //host stops tracking the player here instead of waiting for Nukkit to confirm.
                //Waiting would leave a ghost if the adapter never answers, and diffing a corpse
                //for a tick is work nobody asked for.
                if ($packet instanceof \pocketmine\network\mcpe\protocol\DisconnectPacket) {
                    $message = (string) ($packet->message ?? '');
                    $this->dropPlayer((string) $uuid, $message);
                    $ops[] = Protocol::packetOp((string) $uuid, 'DisconnectPacket', ['message' => $message]);
                    //Everything still queued for this player was meant for a client that is
                    //already gone.
                    break;
                }
                //The real InventoryManager is what makes containers work, but it also tracks the
                //player's own inventory/armour/offhand/cursor. Those changes already travel as
                //`player.inventory` from the mirror; forwarding the packets as well would send
                //the same slot twice with different bookkeeping.
                if (self::isMirrorBookkeeping($packet)) {
                    continue;
                }
                try {
                    $op = $this->mapPacket((string) $uuid, $packet);
                } catch (\Throwable $e) {
                    //One malformed packet must not take the whole host down: a plugin that
                    //builds a TextPacket by hand can leave typed properties uninitialised.
                    $op = null;
                    $this->logOps[] = Protocol::logOp('error', 'packet ' . $packet::class . ' could not be mapped: ' . $e->getMessage());
                }
                if ($op !== null) {
                    $ops[] = $op;
                    continue;
                }
                $class = $packet::class;
                $this->droppedPackets[$class] = ($this->droppedPackets[$class] ?? 0) + 1;
                //One warning per class, not per packet: a plugin that sends a HUD every tick
                //would otherwise turn the log into noise and hide the one line that matters.
                if (!isset($this->reportedUnsupported[$class])) {
                    $this->reportedUnsupported[$class] = true;
                    $this->logOps[] = Protocol::logOp(
                        'warn',
                        'packet.unsupported: ' . $class . ' is not in the packet whitelist; dropped'
                    );
                }
            }
        }

        return $ops;
    }

    /**
     * Stops tracking a player the PocketMine side has already disconnected.
     *
     * The order matters: `BridgedPlayers::quit()` is what takes the player out of the host's map,
     * and it is a no-op lifecycle-wise for a player whose session is already closed. Doing this
     * in the same tick as the packet keeps the mirrors (`player.state`, `player.inventory`) from
     * diffing a despawned player, and keeps a later `player.quit` from Nukkit from looking like
     * a player who was never there.
     */
    private function dropPlayer(string $uuid, string $reason): void
    {
        $this->playersOrFail()->quit($uuid, $reason === '' ? 'disconnected by a plugin' : $reason);
        $this->playerStateOrFail()->forget($uuid);
        $this->inventoryOrFail()->forget($uuid);
    }

    /**
     * Packet whitelist. Field names are PocketMine's, values are semantic (names, not constants), so
     * the JVM side can resolve them against whichever Nukkit build it is running: no byte, no
     * protocol number and no enum ordinal ever crosses the bridge.
     *
     * Everything not listed here is counted by the caller and reported, never guessed at.
     *
     * @return array<string, mixed>|null
     */
    private function mapPacket(string $uuid, object $packet): ?array
    {
        if ($packet instanceof \pocketmine\network\mcpe\protocol\TextPacket) {
            $type = self::textTypeName($packet->type);
            if ($type === null) {
                return null;
            }
            return Protocol::packetOp($uuid, 'TextPacket', [
                'type' => $type,
                //`TextPacket::raw()` leaves $sourceName uninitialised (typed property), so `??`
                //is the only safe read here.
                'source' => $packet->sourceName ?? '',
                'message' => $packet->message,
            ]);
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\SetTitlePacket) {
            $type = self::titleTypeName($packet->type ?? -1);
            if ($type === null) {
                return null;
            }
            return Protocol::packetOp($uuid, 'SetTitlePacket', [
                'type' => $type,
                'text' => $packet->text ?? '',
                'fadeInTime' => $packet->fadeInTime ?? 0,
                'stayTime' => $packet->stayTime ?? 0,
                'fadeOutTime' => $packet->fadeOutTime ?? 0,
            ]);
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\ModalFormRequestPacket) {
            return Protocol::packetOp($uuid, 'ModalFormRequestPacket', [
                'formId' => $packet->formId ?? 0,
                'formData' => $packet->formData ?? '{}',
            ]);
        }

        //`Player::closeAllForms()`; Nukkit's packet of the same name carries no payload either.
        if ($packet instanceof \pocketmine\network\mcpe\protocol\ClientboundCloseFormPacket) {
            return Protocol::packetOp($uuid, 'ClientboundCloseFormPacket', []);
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\ToastRequestPacket) {
            return Protocol::packetOp($uuid, 'ToastRequestPacket', [
                'title' => $packet->getTitle(),
                'body' => $packet->getBody(),
            ]);
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\PlaySoundPacket) {
            return Protocol::packetOp($uuid, 'PlaySoundPacket', [
                'soundName' => $packet->soundName ?? '',
                'x' => $packet->x ?? 0.0,
                'y' => $packet->y ?? 0.0,
                'z' => $packet->z ?? 0.0,
                'volume' => $packet->volume ?? 1.0,
                'pitch' => $packet->pitch ?? 1.0,
            ]);
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket) {
            return Protocol::packetOp($uuid, 'SetDisplayObjectivePacket', [
                'displaySlot' => $packet->displaySlot ?? 'sidebar',
                'objectiveName' => $packet->objectiveName ?? '',
                'displayName' => $packet->displayName ?? '',
                'criteriaName' => $packet->criteriaName ?? 'dummy',
                'sortOrder' => $packet->sortOrder ?? 0,
            ]);
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\SetScorePacket) {
            $entries = [];
            foreach (($packet->entries ?? []) as $entry) {
                $entries[] = [
                    'scoreboardId' => $entry->scoreboardId ?? 0,
                    'objectiveName' => $entry->objectiveName ?? '',
                    'score' => $entry->score ?? 0,
                    'type' => self::scorerTypeName($entry->type ?? 0),
                    'actorUniqueId' => $entry->actorUniqueId ?? null,
                    'customName' => $entry->customName ?? null,
                ];
            }
            return Protocol::packetOp($uuid, 'SetScorePacket', [
                'type' => ($packet->type ?? 0) === \pocketmine\network\mcpe\protocol\SetScorePacket::TYPE_REMOVE ? 'remove' : 'change',
                'entries' => $entries,
            ]);
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\RemoveObjectivePacket) {
            return Protocol::packetOp($uuid, 'RemoveObjectivePacket', [
                'objectiveName' => $packet->objectiveName ?? '',
            ]);
        }

        //Container windows. PocketMine's InventoryManager is the producer: the plug-in never
        //sees these packets, but setCurrentWindow()/setItem() make it queue them, and this is
        //where they become semantic ops the adapter can rebuild against Nukkit's own windows.
        if ($packet instanceof \pocketmine\network\mcpe\protocol\ContainerOpenPacket) {
            $position = $packet->blockPosition;
            return Protocol::windowOpenOp(
                $uuid,
                (int) ($packet->windowId ?? 0),
                (int) ($packet->windowType ?? 0),
                $position === null ? 0 : $position->getX(),
                $position === null ? 0 : $position->getY(),
                $position === null ? 0 : $position->getZ(),
                (int) ($packet->actorUniqueId ?? -1)
            );
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\ContainerClosePacket) {
            return Protocol::windowCloseOp($uuid, (int) ($packet->windowId ?? 0));
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\ContainerSetDataPacket) {
            return Protocol::windowDataOp(
                $uuid,
                (int) ($packet->windowId ?? 0),
                (int) ($packet->property ?? 0),
                (int) ($packet->value ?? 0)
            );
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\InventoryContentPacket) {
            $session = $this->playersOrFail()->get($uuid)?->getNetworkSession();
            if ($session === null || $packet->windowId < 1 || $packet->windowId > 99) {
                return null;
            }
            $slots = [];
            foreach (($packet->items ?? []) as $slot => $wrapper) {
                if (!$wrapper instanceof \pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper) {
                    continue;
                }
                $slots[] = self::itemTuple($session, $wrapper, (int) $slot);
            }
            return Protocol::windowContentOp($uuid, (int) $packet->windowId, count($packet->items ?? []), $slots);
        }

        if ($packet instanceof \pocketmine\network\mcpe\protocol\InventorySlotPacket) {
            $session = $this->playersOrFail()->get($uuid)?->getNetworkSession();
            if ($session === null || $packet->windowId < 1 || $packet->windowId > 99) {
                return null;
            }
            return Protocol::windowSlotOp(
                $uuid,
                (int) $packet->windowId,
                self::itemTuple($session, $packet->item, (int) ($packet->inventorySlot ?? 0))
            );
        }

        return null;
    }

    /**
     * Packets InventoryManager produces for the player's own inventories.
     *
     * Window content has ids 1-99; `0` is the main inventory, `119` offhand, `120` armour and
     * `124` the UI/cursor/crafting space. The mirror covers the first three; the UI slots are not
     * part of the bridge's slot space at all.
     */
    private static function isMirrorBookkeeping(object $packet): bool
    {
        if (
            $packet instanceof \pocketmine\network\mcpe\protocol\InventoryContentPacket
            || $packet instanceof \pocketmine\network\mcpe\protocol\InventorySlotPacket
        ) {
            return $packet->windowId < 1 || $packet->windowId > 99;
        }
        //The held-slot mirror reports this as `player.state.heldSlot`; the manager's copy would
        //be a second, redundant instruction to the same client.
        return $packet instanceof \pocketmine\network\mcpe\protocol\MobEquipmentPacket;
    }

    /**
     * One protocol item stack in the bridge's item dialect.
     *
     * `InventoryContent`/`InventorySlot` carry `ItemStackWrapper`s, not core `Item`s, because
     * the client's item-stack ids are part of the packet. Converting through the session's own
     * type converter is what keeps the name/damage/NBT identical to `player.inventory`.
     *
     * @return array<int, int|string>
     */
    private static function itemTuple(
        \pocketmine\mukkit\BridgedSession $session,
        \pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper $wrapper,
        int $slot
    ): array {
        $item = $session->getTypeConverter()->netItemStackToCore($wrapper->getItemStack());
        return \pocketmine\mukkit\BridgePlayerInventory::tupleFor($slot, $item);
    }

    private static function textTypeName(int $type): ?string
    {
        return match ($type) {
            \pocketmine\network\mcpe\protocol\TextPacket::TYPE_RAW => 'raw',
            \pocketmine\network\mcpe\protocol\TextPacket::TYPE_CHAT => 'chat',
            \pocketmine\network\mcpe\protocol\TextPacket::TYPE_SYSTEM => 'system',
            \pocketmine\network\mcpe\protocol\TextPacket::TYPE_TIP => 'tip',
            \pocketmine\network\mcpe\protocol\TextPacket::TYPE_POPUP => 'popup',
            \pocketmine\network\mcpe\protocol\TextPacket::TYPE_ANNOUNCEMENT => 'announcement',
            \pocketmine\network\mcpe\protocol\TextPacket::TYPE_WHISPER => 'whisper',
            default => null,
        };
    }

    private static function titleTypeName(int $type): ?string
    {
        return match ($type) {
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_CLEAR_TITLE => 'clear',
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_RESET_TITLE => 'reset',
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_SET_TITLE => 'title',
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_SET_SUBTITLE => 'subtitle',
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_SET_ACTIONBAR_MESSAGE => 'actionbar',
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_SET_ANIMATION_TIMES => 'times',
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_SET_TITLE_JSON => 'title_json',
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_SET_SUBTITLE_JSON => 'subtitle_json',
            \pocketmine\network\mcpe\protocol\SetTitlePacket::TYPE_SET_ACTIONBAR_MESSAGE_JSON => 'actionbar_json',
            default => null,
        };
    }

    private static function scorerTypeName(int $type): string
    {
        return match ($type) {
            \pocketmine\network\mcpe\protocol\types\ScorePacketEntry::TYPE_PLAYER => 'player',
            \pocketmine\network\mcpe\protocol\types\ScorePacketEntry::TYPE_ENTITY => 'entity',
            default => 'fake_player',
        };
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyChunk(array $item): void
    {
        $x = (int) ($item['x'] ?? 0);
        $z = (int) ($item['z'] ?? 0);
        $world = (string) ($item['world'] ?? '');
        if ($world === '') {
            // Without a name the frame cannot be routed, and guessing the default level would
            // put another world's blocks into this one. The adapter always sends one.
            $this->reportMirrorGap('chunk without a world name');
            return;
        }

        $sections = [];
        foreach (($item['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $ids = self::inflateSectionPayload((string) ($section['ids'] ?? ''));
            $metas = self::inflateSectionPayload((string) ($section['metas'] ?? ''));
            if ($ids === null || $metas === null) {
                $this->logOps[] = Protocol::logOp('error', "chunk $world $x,$z: section payload is not base64+zlib");
                continue;
            }
            $sections[(int) ($section['y'] ?? 0)] = ['ids' => $ids, 'metas' => $metas];
        }

        $gaps = $this->mirrorFor($world)->applyChunk($x, $z, $sections, is_array($item['tiles'] ?? null) ? $item['tiles'] : []);
        foreach ($gaps as $gap) {
            $this->reportMirrorGap($gap);
        }
    }

    /**
     * A chunk the adapter stopped streaming. Reads there go back to air, which is the documented
     * meaning of "outside the mirror"; keeping a stale chunk would be worse than forgetting it.
     *
     * @param array<string, mixed> $item
     */
    private function applyChunkUnload(array $item): void
    {
        $world = (string) ($item['world'] ?? '');
        if ($world === '') {
            $this->reportMirrorGap('chunk unload without a world name');
            return;
        }
        if (!$this->mirrorFor($world)->unloadChunk((int) ($item['x'] ?? 0), (int) ($item['z'] ?? 0))) {
            $this->reportMirrorGap('chunk ' . (int) ($item['x'] ?? 0) . ',' . (int) ($item['z'] ?? 0)
                . ' in ' . $world . ' is in use; kept');
        }
    }

    /**
     * Block entities Nukkit changed since the last poll: full snapshots, plus the positions that
     * stopped being tiles.
     *
     * A tile snapshot is small and always complete. Sending diffs would need a second vocabulary
     * for "slot 4 was emptied", and the tile's own save data is the only thing that can express
     * a chest losing its last item as clearly as it expresses one gaining it.
     *
     * @param array<string, mixed> $item
     */
    private function applyRemoteTiles(array $item): void
    {
        $world = (string) ($item['world'] ?? '');
        if ($world === '') {
            $this->reportMirrorGap('tile update without a world name');
            return;
        }
        $mirror = $this->mirrorFor($world);
        // Removals first: a block that was broken and replaced within one poll would otherwise
        // have its fresh tile deleted by the removal that describes the old one.
        foreach (($item['removed'] ?? []) as $position) {
            if (is_array($position) && count($position) >= 3) {
                $mirror->removeTileAt((int) $position[0], (int) $position[1], (int) $position[2]);
            }
        }
        foreach (($item['tiles'] ?? []) as $tile) {
            if (!is_array($tile)) {
                continue;
            }
            $gap = $mirror->applyTile($tile);
            if ($gap !== null) {
                $this->reportMirrorGap($gap);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyRemoteBlock(array $item): void
    {
        $world = (string) ($item['world'] ?? '');
        if ($world === '') {
            $this->reportMirrorGap('block update without a world name');
            return;
        }
        $id = (int) ($item['id'] ?? 0);
        $meta = (int) ($item['meta'] ?? 0);
        $stateId = \pocketmine\mukkit\BridgeBlocks::stateIdFromIdMeta($id, $meta);
        if ($stateId === null) {
            $this->reportMirrorGap('unknown legacy block id ' . $id);
            return;
        }
        if (!$this->mirrorFor($world)->applyRemoteBlock(
            (int) ($item['x'] ?? 0),
            (int) ($item['y'] ?? 0),
            (int) ($item['z'] ?? 0),
            $stateId
        )) {
            $this->reportMirrorGap('block update outside the mirrored chunks');
        }
    }

    /**
     * Chunk payloads are base64(zlib(bytes)) because JSON arrays of 4096 numbers per section are
     * an order of magnitude larger on the wire than the blocks they describe.
     */
    private static function inflateSectionPayload(string $encoded): ?string
    {
        if ($encoded === '') {
            return null;
        }
        $compressed = base64_decode($encoded, true);
        if ($compressed === false) {
            return null;
        }
        $raw = @gzuncompress($compressed);
        return $raw === false ? null : $raw;
    }

    /**
     * Dispatches a cancellable action and answers the adapter, always.
     *
     * The `finally` is the point of this method: an exception while building or dispatching the
     * event must still produce a verdict, or the adapter holds the action until it times out and
     * the player is left with a block that will not break.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $ops
     */
    private function dispatchBridgeEvent(array $item, string $name, string $uuid, array $data, array &$ops): void
    {
        $id = (int) ($item['id'] ?? 0);
        $worldEvent = isset(\pocketmine\mukkit\BridgeEvents::WORLD_EVENTS[$name]);
        $allowed = false;
        try {
            $player = $uuid === '' ? null : $this->playersOrFail()->get($uuid);
            if ($player === null && !$worldEvent) {
                $this->reportGap('event ' . $name . ': no bridged player for '
                    . ($uuid === '' ? '(frame has no uuid)' : $uuid));
            } else {
                $verdict = ($this->events ??= new \pocketmine\mukkit\BridgeEvents($this->playersOrFail()))
                    ->dispatch($name, $player, $data);
                if ($verdict === null) {
                    $this->reportGap('event ' . $name . ': frame could not be turned into a PocketMine event');
                } else {
                    $allowed = $verdict['allowed'];
                }
            }
        } catch (\Throwable $e) {
            $this->logOps[] = Protocol::logOp('error', sprintf('pm event %s failed: %s', $name, $e->getMessage()));
        } finally {
            if ($id > 0) {
                $ops[] = Protocol::eventResultOp($id, $allowed);
            }
        }
    }

    /**
     * Dispatches an observation: an event the plugin can see but not decide.
     *
     * No verdict goes back, because there is nothing to answer - Nukkit already did the thing and
     * the adapter is not waiting. A frame that cannot be built is reported once, like any other gap.
     *
     * @param array<string, mixed> $data
     */
    private function dispatchNotifyEvent(string $name, string $uuid, array $data): void
    {
        if ($uuid === '') {
            $this->reportGap('event ' . $name . ': no player uuid');
            return;
        }
        $player = $this->playersOrFail()->get($uuid);
        if ($player === null) {
            $this->reportGap('event ' . $name . ': no bridged player for ' . $uuid);
            return;
        }
        try {
            $events = $this->events ??= new \pocketmine\mukkit\BridgeEvents($this->playersOrFail());
            if (!$events->notify($name, $player, $data)) {
                $this->reportGap('event ' . $name . ': frame could not be turned into a PocketMine event');
            }
        } catch (\Throwable $e) {
            $this->logOps[] = Protocol::logOp('error', sprintf('pm event %s failed: %s', $name, $e->getMessage()));
        }
    }

    /**
     * A player answered a form.
     *
     * Nukkit does not hand us a `PlayerFormRespondedEvent`-shaped object: the adapter intercepts
     * the raw `ModalFormResponsePacket` before Nukkit's own processor sees it (that processor only
     * knows about ids it registered itself) and forwards the JSON text verbatim. Decoding it here
     * is deliberate - `Form::handleResponse()` is defined in terms of the decoded value, and which
     * shape it takes is the form implementation's business, not the bridge's.
     *
     * @param array<string, mixed> $item
     */
    private function dispatchFormResponse(array $item): void
    {
        $uuid = (string) ($item['player'] ?? '');
        $player = $uuid === '' ? null : $this->playersOrFail()->get($uuid);
        if ($player === null) {
            $this->reportGap('form response: no bridged player for ' . ($uuid === '' ? '?' : $uuid));
            return;
        }

        $formId = (int) ($item['formId'] ?? -1);
        if ($formId < 0) {
            $this->reportGap('form response: frame carries no form id');
            return;
        }
        if (!$player->hasPendingForm($formId)) {
            // Late answer to a form that already resolved, or one this host never sent (it was
            // restarted, say). Upstream would log and drop it; so do we.
            $this->reportGap('form response: nothing pending for form ' . $formId);
            return;
        }

        if (($item['closed'] ?? false) === true) {
            $player->onFormSubmit($formId, null);
            return;
        }

        $raw = $item['data'] ?? null;
        if (!is_string($raw) || $raw === '') {
            $player->onFormSubmit($formId, null);
            return;
        }
        if (strlen($raw) > self::MAX_FORM_RESPONSE_SIZE) {
            $this->releaseBadForm($player, $formId, 'response is ' . strlen($raw)
                . ' bytes, over the ' . self::MAX_FORM_RESPONSE_SIZE . '-byte limit');
            return;
        }

        try {
            $decoded = json_decode($raw, true, self::MAX_FORM_RESPONSE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->releaseBadForm($player, $formId, 'response is not decodable JSON: ' . $e->getMessage());
            return;
        }

        $player->onFormSubmit($formId, $decoded);
    }

    /**
     * Releases a form whose response cannot be trusted, as "the player closed it".
     *
     * Upstream's packet handler throws on both of these, which on a real server disconnects the
     * client. Disconnecting somebody because their form response was odd is the wrong trade here:
     * `null` is the branch every form already has to handle, so the plugin is told the player
     * walked away and the pending entry is freed instead of leaking until the player leaves.
     */
    private function releaseBadForm(\pocketmine\player\Player $player, int $formId, string $reason): void
    {
        $this->reportGap('form response ' . $formId . ': ' . $reason . '; treated as closed');
        $player->onFormSubmit($formId, null);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyRemotePlayerState(array $item): void
    {
        $uuid = (string) ($item['player'] ?? '');
        $player = $uuid === '' ? null : $this->playersOrFail()->get($uuid);
        if ($player === null) {
            $this->reportGap('player state: update for an unknown player');
            return;
        }
        $state = is_array($item['s'] ?? null) ? $item['s'] : [];
        if ($state === []) {
            return;
        }

        $session = $player->getNetworkSession();
        $queued = $session->outboxSize();
        foreach ($this->playerStateOrFail()->apply($player, $state) as $gap) {
            $this->reportGap('player state: ' . $gap);
        }
        // Applying mirrored state is not an action. Anything upstream emitted in reaction to it
        // (a game mode change syncs the game type, a death syncs the attribute packet) must not
        // travel back to the side that just told us, or the two hosts will talk forever.
        $session->truncateOutbox($queued);
    }

    private function playerStateOrFail(): \pocketmine\mukkit\BridgePlayerState
    {
        return $this->playerState ??= new \pocketmine\mukkit\BridgePlayerState();
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyRemoteInventory(array $item): void
    {
        $uuid = (string) ($item['player'] ?? '');
        $player = $uuid === '' ? null : $this->playersOrFail()->get($uuid);
        if ($player === null) {
            $this->reportGap('inventory: update for an unknown player');
            return;
        }
        $slots = is_array($item['slots'] ?? null) ? $item['slots'] : [];
        if ($slots === []) {
            return;
        }

        $session = $player->getNetworkSession();
        $queued = $session->outboxSize();
        foreach ($this->inventoryOrFail()->apply($player, $slots) as $gap) {
            $this->reportGap('inventory: ' . $gap);
        }
        // Same reasoning as `player.state`: applying a mirror is not an action. Anything upstream
        // emitted while the slots were written must not travel back as an echo.
        $session->truncateOutbox($queued);
    }

    private function worldStateOrFail(): \pocketmine\mukkit\BridgeWorldState
    {
        return $this->worldState ??= new \pocketmine\mukkit\BridgeWorldState();
    }

    private function entitiesOrFail(): \pocketmine\mukkit\BridgeEntityMirror
    {
        return $this->entities ??= new \pocketmine\mukkit\BridgeEntityMirror();
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyEntitySync(array $item): void
    {
        $name = (string) ($item['world'] ?? '');
        $world = $name === '' ? null : $this->playersOrFail()->worldIfExists($name);
        if ($world === null) {
            $this->reportGap('entity sync: no world named ' . ($name === '' ? '?' : $name));
            return;
        }

        $rows = is_array($item['e'] ?? null) ? $item['e'] : [];
        foreach ($this->entitiesOrFail()->sync($name, $world, $rows) as $gap) {
            $this->reportGap('entity: ' . $gap);
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private function applyRemoteWorldState(array $item): void
    {
        $name = (string) ($item['world'] ?? '');
        $world = $name === '' ? null : $this->playersOrFail()->worldIfExists($name);
        if ($world === null) {
            $this->reportGap('world state: no world named ' . ($name === '' ? '?' : $name));
            return;
        }
        $state = is_array($item['s'] ?? null) ? $item['s'] : [];
        if ($state === []) {
            return;
        }

        // `setTime()` and `stopTime()` both send a SetTimePacket to every player in the world.
        // That is upstream doing its job, not a plugin action, and echoing it back is exactly
        // the loop the other mirrors suppress.
        $queued = [];
        foreach ($this->players?->all() ?? [] as $uuid => $player) {
            if ($player->getWorld() === $world) {
                $queued[(string) $uuid] = $player->getNetworkSession()->outboxSize();
            }
        }

        foreach ($this->worldStateOrFail()->apply($world, $state) as $gap) {
            $this->reportGap('world state: ' . $gap);
        }

        foreach ($queued as $uuid => $size) {
            $player = $this->players?->get($uuid);
            if ($player !== null) {
                $player->getNetworkSession()->truncateOutbox($size);
            }
        }
    }

    private function inventoryOrFail(): \pocketmine\mukkit\BridgePlayerInventory
    {
        return $this->inventory ??= new \pocketmine\mukkit\BridgePlayerInventory();
    }

    private function reportMirrorGap(string $reason): void
    {
        $this->reportGap('world mirror: ' . $reason);
    }

    /**
     * One warning per distinct reason. A mirror that cannot represent something would otherwise
     * repeat the same line every tick, which is exactly the noise that hides the next real error.
     */
    private function reportGap(string $message): void
    {
        if (isset($this->reportedGaps[$message])) {
            return;
        }
        $this->reportedGaps[$message] = true;
        $this->logOps[] = Protocol::logOp('warn', $message . ' (further occurrences suppressed)');
    }

    private function mirrorFor(string $world): \pocketmine\mukkit\BridgeWorldMirror
    {
        return $this->mirrors[$world] ??= new \pocketmine\mukkit\BridgeWorldMirror(
            $this->playersOrFail()->world($world)
        );
    }

    private function playersOrFail(): \pocketmine\mukkit\BridgedPlayers
    {
        if ($this->players === null) {
            throw new \RuntimeException('pm runtime is not booted; call load() first');
        }
        return $this->players;
    }

    /**
     * @param list<array<string, mixed>> $ops
     */
    private function reportUnsupportedEvent(string $name, array &$ops): void
    {
        if (isset($this->reportedUnsupported[$name])) {
            return;
        }
        $this->reportedUnsupported[$name] = true;
        $ops[] = Protocol::logOp(
            'warn',
            self::EVENT_UNSUPPORTED . ': ' . $name . ' has no PocketMine equivalent on the bridge; not dispatched'
        );
    }

    private function togglePlugin(array $item, array &$ops): void
    {
        $server = $this->requireServer();
        $name = (string) ($item['plugin'] ?? '');
        $action = (string) ($item['name'] ?? '');
        $plugin = $server->getPluginManager()->getPlugin($name);
        if ($plugin === null) {
            $this->logOps[] = Protocol::logOp('warn', "plugin.{$action} ignored: '{$name}' is not loaded");
            return;
        }
        if ($action === 'enable') {
            $server->getPluginManager()->enablePlugin($plugin);
        } elseif ($action === 'disable') {
            $server->getPluginManager()->disablePlugin($plugin);
        } else {
            throw new \RuntimeException('plugin item requires name=enable|disable');
        }

        //Enabling a plugin can add listeners, disabling one removes them. The adapter has to know
        //within the same tick, otherwise it either keeps holding actions nobody will judge or
        //stops intercepting actions a plugin is now waiting for.
        $demand = $this->eventDemand();
        if ($demand !== $this->announcedEvents) {
            $this->announcedEvents = $demand;
            $ops[] = Protocol::capabilitiesOp($demand);
        }
    }

    /**
     * Events the adapter should hold back for a decision.
     *
     * Empty for anything that cannot dispatch PocketMine events, which is also the only safe
     * default: an action held for a verdict that never comes would freeze the player.
     *
     * @return list<string>
     */
    public function eventDemand(): array
    {
        if ($this->server === null) {
            return [];
        }
        return \pocketmine\mukkit\BridgeEvents::demanded();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drainLogOps(?string $level = null): array
    {
        if ($level === null) {
            $drained = $this->logOps;
            $this->logOps = [];
            return $drained;
        }
        //Only the requested level leaves the buffer. Boot-time info lines ("async pool: ...") are
        //worth sending on the first tick; swallowing them because the caller asked for errors is
        //how a diagnostic becomes invisible.
        $matching = [];
        $rest = [];
        foreach ($this->logOps as $op) {
            if (($op['level'] ?? '') === $level) {
                $matching[] = $op;
            } else {
                $rest[] = $op;
            }
        }
        $this->logOps = $rest;
        return $matching;
    }

    private function requireServer(): \pocketmine\mukkit\HeadlessServer
    {
        if ($this->server === null) {
            throw new \RuntimeException('PM runtime is not booted; call load() first');
        }
        return $this->server;
    }

    private function requireFork(): void
    {
        $polyfill = $this->pmRoot . '/src/mukkit/polyfill.php';
        $autoload = $this->pmRoot . '/vendor/autoload.php';
        if (!is_file($polyfill) || !is_file($autoload)) {
            throw new \RuntimeException(
                'PocketMine fork is not usable at ' . $this->pmRoot
                . ' (need src/mukkit/polyfill.php and vendor/autoload.php; see docs/design/pm-fork.md)'
            );
        }
        if (!class_exists(\pocketmine\mukkit\HeadlessServer::class, false)) {
            require_once $polyfill;
            require_once $autoload;
        }
    }

    /**
     * PM reads its config from disk on boot. Writing the minimum here keeps the bridge in control
     * of the parts that matter headless: no console reader, no stats upload, no update check.
     *
     * <p>The one key with a real choice behind it is {@code plugins.legacy-data-dir}. PM's own
     * default is {@code true}, which puts each plugin's data in {@code plugins/<PluginName>/} —
     * Nukkit's own layout for jar plugins. {@code false} moves it under
     * {@code pm-data/plugin_data/}, where deleting the bridge's folder cannot orphan plugin data
     * in the server's plugin directory. The bridge picks through {@code MUKKIT_PM_DATA_LAYOUT}.</p>
     */
    private function writeHeadlessConfig(): void
    {
        $legacy = $this->dataLayout !== 'isolated';
        $key = 'legacy-data-dir: ' . ($legacy ? 'true' : 'false');
        $why = $legacy
            ? '# One folder per plugin, beside its phar: <server>/plugins/<PluginName>/.'
            : '# Everything the bridge owns lives under pm-data: one folder to back up or delete.';

        $config = $this->dataPath . '/pocketmine.yml';
        if (is_file($config)) {
            $this->rewriteDataLayout($config, $key);
        } else {
            file_put_contents($config, <<<YAML
settings:
  async-workers: 1
  enable-profiling: false
  enable-dev-builds: true
console:
  enable-input: false
  title-tick: false
anonymous-statistics:
  enabled: false
auto-updater:
  enabled: false
plugins:
  {$why}
  {$key}
YAML . "\n");
        }

        $properties = $this->dataPath . '/server.properties';
        if (!is_file($properties)) {
            file_put_contents($properties, <<<PROPS
motd=Mukkit
enable-query=off
enable-ipv6=off
white-list=off
xbox-auth=off
PROPS . "\n");
        }
    }

    /**
     * Rewrites the one key the bridge owns, and only that key. A layout change has to land on the
     * next boot, but an admin's edits to the rest of pocketmine.yml must survive it.
     */
    private function rewriteDataLayout(string $config, string $key): void
    {
        $existing = (string) file_get_contents($config);
        $count = 0;
        $patched = preg_replace('/legacy-data-dir:\s*(?:true|false)/', $key, $existing, 1, $count);
        if (!is_string($patched)) {
            return;
        }
        if ($count === 0) {
            if (preg_match('/^plugins:\s*$/m', $patched) === 1) {
                $patched = (string) preg_replace('/^plugins:\s*$/m', "plugins:\n  {$key}", $patched, 1);
            } else {
                $patched = rtrim($patched, "\n") . "\nplugins:\n  {$key}\n";
            }
        }
        if ($patched !== $existing) {
            file_put_contents($config, $patched);
        }
    }

    private static function stripAnsi(string $line): string
    {
        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
    }
}
