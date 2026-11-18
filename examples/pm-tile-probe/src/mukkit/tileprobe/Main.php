<?php

declare(strict_types=1);

namespace mukkit\tileprobe;

use pocketmine\block\tile\Container;
use pocketmine\block\tile\Furnace;
use pocketmine\block\tile\Sign;
use pocketmine\block\utils\SignText;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

/**
 * Block-entity probe: every command is ordinary PocketMine API.
 *
 * `$world->getTile()` is the interface a plugin uses for chests, signs and furnaces. If this
 * plugin can read the contents Nukkit sent and its own writes come back as a `tile` op, then the
 * mirror is doing its job - no bridge call appears here.
 */
final class Main extends PluginBase
{
    private const COMMANDS = [
        'tl-report' => ['Report the tile at a position', 'tl-report <x> <y> <z>'],
        'tl-set' => ['Put an item into a container tile', 'tl-set <x> <y> <z> <slot> <item> <count>'],
        'tl-sign' => ['Write the first line of a sign tile', 'tl-sign <x> <y> <z> <line>'],
        'tl-open' => ['Open a container tile as the current window', 'tl-open <x> <y> <z>'],
        'tl-close' => ['Close the current window', 'tl-close'],
    ];

    public function onEnable(): void
    {
        $map = $this->getServer()->getCommandMap();
        foreach (self::COMMANDS as $name => [$description, $usage]) {
            $map->register('mukkittileprobe', new class ($name, $description, $usage, $this) extends Command {
                public function __construct(
                    string $name,
                    string $description,
                    string $usage,
                    private readonly Main $plugin
                ) {
                    parent::__construct($name, $description, '/' . $usage);
                    //PM5 refuses to register a command without a permission set.
                    $this->setPermission(DefaultPermissions::ROOT_USER);
                }

                public function execute(CommandSender $sender, string $label, array $args): bool
                {
                    $player = $sender instanceof Player
                        ? $sender
                        : array_values(Server::getInstance()->getOnlinePlayers())[0] ?? null;
                    if (!$player instanceof Player) {
                        $sender->sendMessage('tile probe: no player online');
                        return true;
                    }
                    return $this->plugin->run($sender, $player, $label, $args);
                }
            });
        }
    }

    /**
     * @param list<string> $args
     */
    public function run(CommandSender $sender, Player $player, string $label, array $args): bool
    {
        switch ($label) {
            case 'tl-report':
                if (count($args) < 3) {
                    return $this->usage($sender);
                }
                $tile = $player->getWorld()->getTileAt((int) $args[0], (int) $args[1], (int) $args[2]);
                if ($tile === null) {
                    $this->getLogger()->info('tl-report none');
                    return true;
                }
                $parts = ['tl-report', 'type=' . (new \ReflectionClass($tile))->getShortName()];
                if ($tile instanceof Container) {
                    $slots = [];
                    foreach ($tile->getRealInventory()->getContents() as $slot => $item) {
                        if ($item->isNull()) {
                            continue;
                        }
                        $slots[] = $slot . '=' . strtolower($item->getVanillaName()) . ':' . $item->getCount();
                    }
                    sort($slots);
                    $parts[] = 'items=' . implode(',', $slots);
                }
                if ($tile instanceof Sign) {
                    $parts[] = 'front=' . implode('|', $tile->getText()->getLines());
                    $parts[] = 'back=' . implode('|', $tile->getBackText()->getLines());
                    $parts[] = 'frontColor=' . ($tile->getText()->getBaseColor()->toARGB() & 0xffffffff);
                }
                if ($tile instanceof Furnace) {
                    $nbt = $tile->getCleanedNBT();
                    $parts[] = 'burn=' . ($nbt?->getShort('BurnTime') ?? 0);
                    $parts[] = 'cook=' . ($nbt?->getShort('CookTime') ?? 0);
                    $parts[] = 'max=' . ($nbt?->getShort('MaxTime') ?? 0);
                }
                $this->getLogger()->info(implode(' ', $parts));
                return true;

            case 'tl-set':
                if (count($args) < 6) {
                    return $this->usage($sender);
                }
                $tile = $player->getWorld()->getTileAt((int) $args[0], (int) $args[1], (int) $args[2]);
                if (!$tile instanceof Container) {
                    $this->getLogger()->info('tl-set not a container');
                    return true;
                }
                $item = $this->item((string) $args[4], (int) $args[5]);
                if ($item === null) {
                    return true;
                }
                $tile->getRealInventory()->setItem((int) $args[3], $item);
                $this->getLogger()->info('tl-set slot=' . (int) $args[3] . ' item=' . strtolower($item->getVanillaName()) . ':' . $item->getCount());
                return true;

            case 'tl-sign':
                if (count($args) < 4) {
                    return $this->usage($sender);
                }
                $tile = $player->getWorld()->getTileAt((int) $args[0], (int) $args[1], (int) $args[2]);
                if (!$tile instanceof Sign) {
                    $this->getLogger()->info('tl-sign not a sign');
                    return true;
                }
                $lines = $tile->getText()->getLines();
                $lines[0] = (string) $args[3];
                $tile->setText(new SignText($lines));
                $this->getLogger()->info('tl-sign line0=' . $args[3]);
                return true;

            case 'tl-open':
                if (count($args) < 3) {
                    return $this->usage($sender);
                }
                $tile = $player->getWorld()->getTileAt((int) $args[0], (int) $args[1], (int) $args[2]);
                if (!$tile instanceof Container) {
                    $this->getLogger()->info('tl-open not a container');
                    return true;
                }
                $inventory = $tile->getRealInventory();
                try {
                    $opened = $player->setCurrentWindow($inventory);
                } catch (\Throwable $e) {
                    $this->getLogger()->info('tl-open failed: ' . $e->getMessage());
                    return true;
                }
                $manager = $player->getNetworkSession()->getInvManager();
                $id = $manager?->getWindowId($inventory);
                $this->getLogger()->info('tl-open window=' . ($id ?? -1) . ' size=' . $inventory->getSize()
                    . ' ok=' . ($opened ? 'yes' : 'no'));
                return true;

            case 'tl-close':
                $player->removeCurrentWindow();
                $this->getLogger()->info('tl-close done');
                return true;
        }

        return true;
    }

    private function item(string $name, int $count): ?Item
    {
        $item = StringToItemParser::getInstance()->parse($name);
        if ($item === null) {
            $this->getLogger()->info('tile probe: unknown item ' . $name);
            return null;
        }
        return $item->setCount(max(1, $count));
    }

    private function usage(CommandSender $sender): bool
    {
        $sender->sendMessage('usage: tl-report <x> <y> <z> | tl-set <x> <y> <z> <slot> <item> <count> | tl-sign <x> <y> <z> <line> | tl-open <x> <y> <z> | tl-close');
        return true;
    }
}
