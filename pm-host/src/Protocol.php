<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * Wire protocol constants, mirroring cn.mukkit.bridge.Protocol on the JVM side.
 *
 * The protocol is tick-framed on purpose: one inbound frame per server tick, one outbound
 * frame per server tick. Nothing the host does can block the game thread between ticks.
 */
final class Protocol
{
    public const VERSION = 18;
    public const MAX_FRAME = 16 * 1024 * 1024;

    public const HELLO = 'hello';
    public const WELCOME = 'welcome';
    public const PLUGINS = 'plugins';
    public const READY = 'ready';
    public const TICK = 'tick';
    public const TICK_DONE = 'tickdone';
    public const LOG = 'log';
    public const SHUTDOWN = 'shutdown';

    public static function envelope(string $type, int $id, array $payload = []): array
    {
        return ['t' => $type, 'id' => $id, 'p' => $payload];
    }

    public static function frame(array $message): string
    {
        $json = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('cannot encode frame: ' . json_last_error_msg());
        }
        return pack('N', strlen($json)) . $json;
    }

    public static function packetOp(string $target, string $packetClass, array $fields): array
    {
        return ['o' => 'packet', 'to' => $target, 'cls' => $packetClass, 'f' => $fields];
    }

    public static function logOp(string $level, string $message): array
    {
        return ['o' => 'log', 'level' => $level, 'msg' => $message];
    }

    /**
     * The verdict on a cancellable action the adapter is holding back.
     *
     * `id` is allocated by the adapter, echoed verbatim. A host that cannot decide must answer
     * `false`: leaving the action held would freeze the player, and answering `true` would let
     * through something no plugin approved.
     */
    public static function eventResultOp(int $id, bool $allowed): array
    {
        return ['o' => 'event.result', 'id' => $id, 'allowed' => $allowed];
    }

    /**
     * The set of events the host wants intercepted changes when plugins are enabled or disabled.
     *
     * @param list<string> $events
     */
    public static function capabilitiesOp(array $events): array
    {
        return ['o' => 'capabilities', 'events' => $events];
    }

    /**
     * One player's state changed.
     *
     * Only the changed fields are present. `s` may carry any of: `x`,`y`,`z`,`yaw`,`pitch`
     * (always together when any of them changed), `health`, `maxHealth`, `gamemode`.
     *
     * @param array<string, float|int|string> $state
     */
    public static function playerStateOp(string $uuid, array $state): array
    {
        return ['o' => 'player.state', 'to' => $uuid, 's' => $state];
    }

    /**
     * Entities a plugin moved or hurt.
     *
     * `e` is a list of `[id, x, y, z, yaw, pitch]` with an optional seventh element, health.
     * Position and rotation always travel together (an entity that turned without moving still
     * needs the client told where it faces); health is present only when it changed.
     *
     * @param list<array<int, float|int>> $entities
     */
    public static function entityStateOp(string $world, array $entities): array
    {
        return ['o' => 'entity.state', 'world' => $world, 'e' => $entities];
    }

    /**
     * A world's clock changed.
     *
     * `s` may carry `time` (ticks since the world started) and/or `stopped` (the daylight cycle
     * is paused). Only what changed is present; the first frame for a world carries both.
     *
     * @param array{time?: int, stopped?: bool} $state
     */
    public static function worldStateOp(string $world, array $state): array
    {
        return ['o' => 'world.state', 'world' => $world, 's' => $state];
    }

    /**
     * Slot writes the inventory mirror detected this tick.
     *
     * `slots` is a list of `[slot, id, count, meta]` with an optional fifth element, the item's
     * NBT as base64 of Bedrock network little-endian NBT. Only slots that changed are present;
     * an emptied slot is `[slot, "minecraft:air", 0, 0]`.
     *
     * The slot space is shared between the two sides: 0-35 inventory, 36-39 armour (head, chest,
     * legs, feet), 40 offhand.
     *
     * @param list<array{int, string, int, int, string}|array{int, string, int, int}> $slots
     */
    public static function playerInventoryOp(string $uuid, array $slots): array
    {
        return ['o' => 'player.inventory', 'to' => $uuid, 'slots' => $slots];
    }

    /**
     * A container window a plugin opened for a player.
     *
     * `window` is PocketMine's own window id (1-99), so the adapter and the host name the same
     * window. `type` is Bedrock's network window type; `x`,`y`,`z` are the block the window is
     * anchored to, or zero for a virtual one. `actor` is non-zero for entity inventories.
     */
    public static function windowOpenOp(string $uuid, int $window, int $type, int $x, int $y, int $z, int $actor = -1): array
    {
        return [
            'o' => 'window.open',
            'to' => $uuid,
            'window' => $window,
            'type' => $type,
            'x' => $x,
            'y' => $y,
            'z' => $z,
            'actor' => $actor,
        ];
    }

    /**
     * A full container content snapshot.
     *
     * `size` lets the adapter grow a window (a double chest is 54 slots) before writing. `slots`
     * uses the same item dialect as `player.inventory`; the slot is the container's own index.
     *
     * @param list<array{int, string, int, int, string}|array{int, string, int, int}> $slots
     */
    public static function windowContentOp(string $uuid, int $window, int $size, array $slots): array
    {
        return ['o' => 'window.content', 'to' => $uuid, 'window' => $window, 'size' => $size, 'slots' => $slots];
    }

    /**
     * One container slot changed.
     *
     * @param array{int, string, int, int, string}|array{int, string, int, int} $slot
     */
    public static function windowSlotOp(string $uuid, int $window, array $slot): array
    {
        return ['o' => 'window.slot', 'to' => $uuid, 'window' => $window, 'slot' => $slot];
    }

    /** A container window closed (by the plugin, or as a precondition of opening the next one). */
    public static function windowCloseOp(string $uuid, int $window): array
    {
        return ['o' => 'window.close', 'to' => $uuid, 'window' => $window];
    }

    /**
     * Furnace/brewing-stand progress, passed through as the raw property id.
     *
     * The ids are Bedrock's, and inventing a semantic name for each one would be a second table
     * to keep in sync; plugins only ever see them as opaque numbers here.
     */
    public static function windowDataOp(string $uuid, int $window, int $property, int $value): array
    {
        return ['o' => 'window.data', 'to' => $uuid, 'window' => $window, 'property' => $property, 'value' => $value];
    }

    /**
     * Block writes the mirror detected this tick.
     *
     * `b` is a list of [x, y, z, legacy id, meta] - the one dialect both sides speak. Sending one
     * op per block would mean one JSON line per block; a fill operation can easily be thousands.
     *
     * @param list<array{int, int, int, int, int}> $blocks
     */
    public static function blockOp(string $world, array $blocks): array
    {
        return ['o' => 'block', 'world' => $world, 'b' => $blocks];
    }

    /**
     * Block-entity snapshots the mirror detected this tick.
     *
     * Each entry is `{x, y, z, type}` plus whatever that type carries: `items` for containers
     * (the `player.inventory` item dialect with the slot in front), `burn`/`cook`/`max` for
     * furnaces, `front`/`back` (line arrays), `frontColor`/`backColor`, `frontGlow`/`backGlow`
     * for signs, `entity` for spawners, `name` when the tile is named. The snapshot is full:
     * a tile is small enough that a diff would cost more vocabulary than it saves bytes.
     *
     * @param list<array<string, mixed>> $tiles
     */
    public static function tileOp(string $world, array $tiles): array
    {
        return ['o' => 'tile', 'world' => $world, 'tiles' => $tiles];
    }
}
