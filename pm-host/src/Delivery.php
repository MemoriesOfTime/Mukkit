<?php

declare(strict_types=1);

namespace Mukkit\Host;

/**
 * Who delivers chat and the join/quit announcements to players.
 *
 * This is one decision, in one place, because getting it wrong is invisible until a real client
 * connects: two senders means every line appears twice, one sender that does not send means
 * chat disappears. It is a property of the profile, not of whether the host is connected —
 * `stub` and `plugin` observe events and deliver nothing, so the JVM must keep its own
 * broadcast for them.
 */
final class Delivery
{
    /** The host broadcasts through PocketMine's own chat formatter. */
    public const HOST = 'host';

    /** The JVM keeps broadcasting; the host only watches. */
    public const JVM = 'jvm';

    public static function ownerFor(string $profileName): string
    {
        return $profileName === 'pm' ? self::HOST : self::JVM;
    }

    /**
     * Whether the profile keeps a mirror of the Nukkit world.
     *
     * Only `pm` boots a PocketMine server with a `World` in it, so only `pm` can answer
     * `$world->getBlock()`. The other profiles would just drop every chunk frame.
     */
    public static function mirrorsWorld(string $profileName): bool
    {
        return $profileName === 'pm';
    }
}
