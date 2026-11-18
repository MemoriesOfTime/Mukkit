<?php

declare(strict_types=1);

namespace mukkit\blockprobe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\permission\DefaultPermissions;
use pocketmine\plugin\PluginBase;

/**
 * Cancellable-event probe: a protection plugin in miniature.
 *
 * It only cancels breaks inside a box that the test sets up, which is enough to prove the whole
 * path: Nukkit holds the action, PocketMine dispatches the event to this listener, the cancellation
 * travels back, and the adapter drops the action instead of replaying it.
 *
 * Everything here is ordinary PocketMine code — `BlockBreakEvent`, `cancel()`, nothing
 * bridge-specific.
 */
final class Main extends PluginBase implements Listener
{
    /** @var array{int, int, int, int, int, int}|null */
    private ?array $box = null;

    public function onEnable(): void
    {
        //Registering is what makes the bridge intercept breaks at all: the host reports the
        //demand, and the adapter only holds actions somebody is listening for.
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $map = $this->getServer()->getCommandMap();
        foreach (['bp-protect' => 'Protect a box', 'bp-clear' => 'Clear the protected box'] as $name => $description) {
            $map->register('mukkitblockprobe', new class ($name, $description, $this) extends Command {
                public function __construct(string $name, string $description, private readonly Main $plugin)
                {
                    parent::__construct($name, $description, '/' . $name . ' [x1 y1 z1 x2 y2 z2]');
                    $this->setPermission(DefaultPermissions::ROOT_USER);
                }

                public function execute(CommandSender $sender, string $label, array $args): bool
                {
                    if ($label === 'bp-clear') {
                        $this->plugin->setBox(null);
                        $sender->sendMessage('bp-clear done');
                        return true;
                    }
                    if (count($args) < 6) {
                        $sender->sendMessage('usage: /bp-protect <x1> <y1> <z1> <x2> <y2> <z2>');
                        return true;
                    }
                    $this->plugin->setBox(array_map('intval', array_slice($args, 0, 6)));
                    $sender->sendMessage('bp-protect done');
                    return true;
                }
            });
        }
        $this->getLogger()->info('block probe enabled');
    }

    /**
     * @param array{int, int, int, int, int, int}|null $box
     */
    public function setBox(?array $box): void
    {
        $this->box = $box;
    }

    public function onBlockBreak(BlockBreakEvent $event): void
    {
        $pos = $event->getBlock()->getPosition();
        $this->getLogger()->info(sprintf(
            'break at %d,%d,%d block=%s item=%s x%d',
            $pos->getX(),
            $pos->getY(),
            $pos->getZ(),
            $event->getBlock()->getName(),
            $event->getItem()->getVanillaName(),
            $event->getItem()->getCount()
        ));

        if ($this->box === null) {
            return;
        }
        [$x1, $y1, $z1, $x2, $y2, $z2] = $this->box;
        if (
            $pos->getX() >= min($x1, $x2) && $pos->getX() <= max($x1, $x2)
            && $pos->getY() >= min($y1, $y2) && $pos->getY() <= max($y1, $y2)
            && $pos->getZ() >= min($z1, $z2) && $pos->getZ() <= max($z1, $z2)
        ) {
            $event->cancel();
            $this->getLogger()->info('break denied');
        }
    }
}
