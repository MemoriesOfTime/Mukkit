<?php

declare(strict_types=1);

namespace mukkit\inventoryprobe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

/**
 * Inventory probe: every command here is ordinary PocketMine API.
 *
 * The point is to prove that `getInventory()->setItem()`, `getArmorInventory()->setHelmet()`
 * and `getOffHandInventory()` reach the client (through Nukkit), and that what Nukkit's player
 * picks up reaches the plugin. No bridge calls: if this plugin works, any shop or kit plugin's
 * inventory writes work.
 *
 * Slot numbers are deliberately *not* the bridge's numbering here. The plugin uses PM's own
 * objects (`getInventory()` index, `getArmorInventory()->setHelmet()`, offhand slot 0), which is
 * what a real plugin would do. The bridge's 0-40 numbering is asserted from the frame side.
 */
final class Main extends PluginBase
{
    private const COMMANDS = [
        'inv-set' => ['Put an item in a player inventory slot', 'inv-set <slot 0-35> <item> <count> [custom name]'],
        'inv-armor' => ['Put an item in an armour slot', 'inv-armor <head|chest|legs|feet> <item>'],
        'inv-offhand' => ['Put an item in the offhand', 'inv-offhand <item>'],
        'inv-clear' => ['Empty every mirrored slot', 'inv-clear'],
        'inv-report' => ['Report what PocketMine thinks the inventory holds', 'inv-report'],
    ];

    public function onEnable(): void
    {
        $map = $this->getServer()->getCommandMap();
        foreach (self::COMMANDS as $name => [$description, $usage]) {
            $map->register('mukkitinventoryprobe', new class ($name, $description, $usage) extends Command {
                public function __construct(string $name, string $description, string $usage)
                {
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
                        $sender->sendMessage('inventory probe: no player online');
                        return true;
                    }

                    switch ($label) {
                        case 'inv-set':
                            if (count($args) < 3) {
                                return $this->usage($sender);
                            }
                            $item = $this->item((string) $args[1], (int) $args[2]);
                            if ($item === null) {
                                return true;
                            }
                            if (isset($args[3])) {
                                $item->setCustomName((string) $args[3]);
                            }
                            $player->getInventory()->setItem((int) $args[0], $item);
                            $sender->sendMessage('inv-set done');
                            return true;

                        case 'inv-armor':
                            if (count($args) < 2) {
                                return $this->usage($sender);
                            }
                            $item = $this->item((string) $args[1], 1);
                            if ($item === null) {
                                return true;
                            }
                            $armor = $player->getArmorInventory();
                            switch ((string) $args[0]) {
                                case 'head':
                                    $armor->setHelmet($item);
                                    break;
                                case 'chest':
                                    $armor->setChestplate($item);
                                    break;
                                case 'legs':
                                    $armor->setLeggings($item);
                                    break;
                                case 'feet':
                                    $armor->setBoots($item);
                                    break;
                                default:
                                    $sender->sendMessage('inv-armor: unknown slot ' . $args[0]);
                                    return true;
                            }
                            $sender->sendMessage('inv-armor done');
                            return true;

                        case 'inv-offhand':
                            if (count($args) < 1) {
                                return $this->usage($sender);
                            }
                            $item = $this->item((string) $args[0], 1);
                            if ($item === null) {
                                return true;
                            }
                            $player->getOffHandInventory()->setItem(0, $item);
                            $sender->sendMessage('inv-offhand done');
                            return true;

                        case 'inv-clear':
                            $player->getInventory()->clearAll();
                            $player->getArmorInventory()->clearAll();
                            $player->getOffHandInventory()->clearAll();
                            $sender->sendMessage('inv-clear done');
                            return true;

                        case 'inv-report':
                            $inventory = $player->getInventory();
                            //One line, because the test asserts on it verbatim.
                            $sender->sendMessage(sprintf(
                                'inv-report 0=%s 1=%s 2=%s 3=%s 4=%s head=%s offhand=%s',
                                self::describe($inventory->getItem(0)),
                                self::describe($inventory->getItem(1)),
                                self::describe($inventory->getItem(2)),
                                self::describe($inventory->getItem(3)),
                                self::describe($inventory->getItem(4)),
                                self::describe($player->getArmorInventory()->getHelmet()),
                                self::describe($player->getOffHandInventory()->getItem(0))
                            ));
                            return true;
                    }

                    return $this->usage($sender);
                }

                private function item(string $name, int $count): ?Item
                {
                    $item = StringToItemParser::getInstance()->parse($name);
                    if ($item === null) {
                        //No plugin logger here: this is an anonymous Command, not a PluginBase.
                        Server::getInstance()->getLogger()->warning('inv: no item named ' . $name);
                        return null;
                    }
                    return $count > 0 ? $item->setCount($count) : $item;
                }

                private static function describe(Item $item): string
                {
                    if ($item->isNull()) {
                        return 'empty';
                    }
                    return sprintf(
                        '%s x%d%s%s',
                        $item->getVanillaName(),
                        $item->getCount(),
                        $item instanceof \pocketmine\item\Durable ? ' dmg=' . $item->getDamage() : '',
                        $item->hasCustomName() ? ' "' . $item->getCustomName() . '"' : ''
                    );
                }

                private function usage(CommandSender $sender): bool
                {
                    $sender->sendMessage('usage: ' . $this->getUsage());
                    return true;
                }
            });
        }
        $this->getLogger()->info('inventory probe enabled');
    }
}
