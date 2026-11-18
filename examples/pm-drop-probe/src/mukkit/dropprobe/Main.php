<?php

declare(strict_types=1);

namespace mukkit\dropprobe;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\item\Durable;
use pocketmine\item\VanillaItems;
use pocketmine\plugin\PluginBase;

/**
 * Drop probe: the smallest plugin that can veto a drop.
 *
 * It cancels diamonds and lets everything else through, which is enough to prove both branches of
 * the second cancellable event: the adapter holds the drop, PocketMine dispatches the event here,
 * the verdict travels back, and the adapter either replays the drop or leaves the stack alone.
 *
 * Everything here is ordinary PocketMine code - `PlayerDropItemEvent`, `cancel()`, nothing
 * bridge-specific.
 */
final class Main extends PluginBase implements Listener
{
    public function onEnable(): void
    {
        //Registering is what makes the bridge intercept drops at all: the host reports the demand,
        //and the adapter only holds actions somebody is listening for.
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info('drop probe enabled');
    }

    public function onDrop(PlayerDropItemEvent $event): void
    {
        $item = $event->getItem();
        //Damage is durability now and only `Durable` has it; asking every item for it is how the
        //first version of this probe turned a drop into an exception (and a fail-closed verdict).
        $this->getLogger()->info(sprintf(
            'drop item=%s x%d dmg=%d name=%s',
            $item->getVanillaName(),
            $item->getCount(),
            $item instanceof Durable ? $item->getDamage() : 0,
            $item->getCustomName()
        ));

        if ($item->getTypeId() === VanillaItems::DIAMOND()->getTypeId()) {
            $event->cancel();
            $this->getLogger()->info('drop denied');
        }
    }
}
