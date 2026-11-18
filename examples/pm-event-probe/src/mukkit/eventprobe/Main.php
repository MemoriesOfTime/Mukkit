<?php

declare(strict_types=1);

namespace mukkit\eventprobe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBurnEvent;
use pocketmine\event\block\BlockFormEvent;
use pocketmine\event\block\BlockGrowEvent;
use pocketmine\event\block\BlockSpreadEvent;
use pocketmine\event\block\LeavesDecayEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerItemHeldEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\player\PlayerToggleGlideEvent;
use pocketmine\event\player\PlayerToggleSneakEvent;
use pocketmine\event\player\PlayerToggleSprintEvent;
use pocketmine\event\player\PlayerToggleSwimEvent;
use pocketmine\permission\DefaultPermissions;
use pocketmine\plugin\PluginBase;

/**
 * Event-surface probe: listens to every bridged event, logs what it saw, and can veto one kind at
 * a time.
 *
 * One plugin instead of five because the point is the channel, not any single event: the test turns
 * cancellation on for one name, checks that only that name is denied, and turns it off again. The
 * per-name switch is also what proves the host's demand list is granular.
 *
 * Everything here is ordinary PocketMine code - no bridge-specific API.
 */
final class Main extends PluginBase implements Listener
{
    /** @var array<string, true> */
    private array $cancelling = [];

    /** @var array<string, int> */
    private array $seen = [];

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $map = $this->getServer()->getCommandMap();
        $map->register('mukkiteventprobe', new class ('ev-cancel', 'Veto one bridged event', $this) extends Command {
            public function __construct(string $name, string $description, private readonly Main $plugin)
            {
                parent::__construct($name, $description, '/' . $name . ' <event> <on|off>');
                $this->setPermission(DefaultPermissions::ROOT_USER);
            }

            public function execute(CommandSender $sender, string $label, array $args): bool
            {
                if (count($args) < 2) {
                    $sender->sendMessage('usage: /ev-cancel <event> <on|off>');
                    return true;
                }
                $on = $args[1] === 'on';
                $this->plugin->setCancelling($args[0], $on);
                $sender->sendMessage('ev-cancel ' . $args[0] . '=' . ($on ? 'on' : 'off'));
                return true;
            }
        });
        $map->register('mukkiteventprobe', new class ('ev-report', 'Print what this probe has seen', $this) extends Command {
            public function __construct(string $name, string $description, private readonly Main $plugin)
            {
                parent::__construct($name, $description, '/' . $name);
                $this->setPermission(DefaultPermissions::ROOT_USER);
            }

            public function execute(CommandSender $sender, string $label, array $args): bool
            {
                foreach ($this->plugin->report() as $line) {
                    $sender->sendMessage($line);
                }
                return true;
            }
        });
        $this->getLogger()->info('event probe enabled');
    }

    public function setCancelling(string $event, bool $on): void
    {
        if ($on) {
            $this->cancelling[$event] = true;
        } else {
            unset($this->cancelling[$event]);
        }
    }

    /** @return list<string> */
    public function report(): array
    {
        $lines = [];
        foreach ($this->seen as $name => $count) {
            $lines[] = 'ev-seen ' . $name . '=' . $count;
        }
        return $lines === [] ? ['ev-seen none'] : $lines;
    }

    private function decide(string $name, string $detail, object $event): void
    {
        $this->seen[$name] = ($this->seen[$name] ?? 0) + 1;
        $this->getLogger()->info('ev name=' . $name . ' ' . $detail);
        if (!isset($this->cancelling[$name])) {
            return;
        }
        if (method_exists($event, 'cancel')) {
            $event->cancel();
            $this->getLogger()->info('ev cancelled ' . $name);
        }
    }

    // ---------------------------------------------------------------- players

    public function onItemHeld(PlayerItemHeldEvent $event): void
    {
        $this->decide('player.item.held', sprintf(
            'slot=%d item=%s x%d',
            $event->getSlot(),
            $event->getItem()->getVanillaName(),
            $event->getItem()->getCount()
        ), $event);
    }

    public function onSneak(PlayerToggleSneakEvent $event): void
    {
        $this->decide('player.toggle.sneak', 'state=' . ($event->isSneaking() ? 'true' : 'false'), $event);
    }

    public function onSprint(PlayerToggleSprintEvent $event): void
    {
        $this->decide('player.toggle.sprint', 'state=' . ($event->isSprinting() ? 'true' : 'false'), $event);
    }

    public function onFly(PlayerToggleFlightEvent $event): void
    {
        $this->decide('player.toggle.fly', 'state=' . ($event->isFlying() ? 'true' : 'false'), $event);
    }

    public function onGlide(PlayerToggleGlideEvent $event): void
    {
        $this->decide('player.toggle.glide', 'state=' . ($event->isGliding() ? 'true' : 'false'), $event);
    }

    public function onSwim(PlayerToggleSwimEvent $event): void
    {
        $this->decide('player.toggle.swim', 'state=' . ($event->isSwimming() ? 'true' : 'false'), $event);
    }

    public function onDeath(PlayerDeathEvent $event): void
    {
        $drops = [];
        foreach ($event->getDrops() as $item) {
            $drops[] = $item->getVanillaName() . 'x' . $item->getCount();
        }
        $this->seen['player.death'] = ($this->seen['player.death'] ?? 0) + 1;
        $this->getLogger()->info(sprintf(
            'ev name=player.death message=%s xp=%d drops=%s',
            $event->getDeathMessage() instanceof \pocketmine\lang\Translatable
                ? $event->getDeathMessage()->getText()
                : (string) $event->getDeathMessage(),
            $event->getXpDropAmount(),
            implode(',', $drops)
        ));
    }

    public function onRespawn(PlayerRespawnEvent $event): void
    {
        $pos = $event->getRespawnPosition();
        $this->seen['player.respawn'] = ($this->seen['player.respawn'] ?? 0) + 1;
        $this->getLogger()->info(sprintf(
            'ev name=player.respawn pos=%d,%d,%d world=%s',
            $pos->getFloorX(),
            $pos->getFloorY(),
            $pos->getFloorZ(),
            $pos->getWorld()->getFolderName()
        ));
    }

    // ---------------------------------------------------------------- blocks

    /**
     * PocketMine's grow/form/spread events are siblings, not a chain, so each needs its own
     * handler: registering only the base would silently miss two thirds of the family.
     */
    public function onGrow(BlockGrowEvent $event): void
    {
        $this->decide('block.grow', $this->blockDetail($event->getBlock(), $event->getNewState()), $event);
    }

    public function onForm(BlockFormEvent $event): void
    {
        $this->decide('block.form', $this->blockDetail($event->getBlock(), $event->getNewState()), $event);
    }

    public function onSpread(BlockSpreadEvent $event): void
    {
        $this->decide(
            'block.spread',
            $this->blockDetail($event->getBlock(), $event->getNewState()) . ' source=' . $event->getSource()->getName(),
            $event
        );
    }

    private function blockDetail(\pocketmine\block\Block $block, \pocketmine\block\Block $newState): string
    {
        return sprintf(
            'block=%s new=%s pos=%d,%d,%d',
            $block->getName(),
            $newState->getName(),
            $block->getPosition()->getFloorX(),
            $block->getPosition()->getFloorY(),
            $block->getPosition()->getFloorZ()
        );
    }

    public function onBurn(BlockBurnEvent $event): void
    {
        $block = $event->getBlock();
        $this->decide('block.burn', sprintf(
            'block=%s pos=%d,%d,%d',
            $block->getName(),
            $block->getPosition()->getFloorX(),
            $block->getPosition()->getFloorY(),
            $block->getPosition()->getFloorZ()
        ), $event);
    }

    public function onLeavesDecay(LeavesDecayEvent $event): void
    {
        $block = $event->getBlock();
        $this->decide('leaves.decay', sprintf(
            'block=%s pos=%d,%d,%d',
            $block->getName(),
            $block->getPosition()->getFloorX(),
            $block->getPosition()->getFloorY(),
            $block->getPosition()->getFloorZ()
        ), $event);
    }
}
