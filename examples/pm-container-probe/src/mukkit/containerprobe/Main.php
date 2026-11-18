<?php

declare(strict_types=1);

namespace mukkit\containerprobe;

use pocketmine\block\inventory\ChestInventory;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\world\Position;

/**
 * Container probe: every command is ordinary PocketMine API.
 *
 * `setCurrentWindow()` on a real `ChestInventory` is what a menu, shop or lottery plugin does.
 * The bridge must turn PocketMine's own `ContainerOpen` / `InventoryContent` / `InventorySlot`
 * packets into a Nukkit window, and a client click must come back as an
 * `InventoryTransactionEvent`. No bridge calls here - if this plugin works, real menu plugins do.
 */
final class Main extends PluginBase implements Listener
{
    private const COMMANDS = [
        'ct-open' => ['Open a 27-slot chest with two items', 'ct-open'],
        'ct-set' => ['Put an item in the current window', 'ct-set <slot> <item> <count>'],
        'ct-report' => ['Report the current window and its contents', 'ct-report'],
        'ct-close' => ['Close the current window', 'ct-close'],
        'ct-cancel' => ['Cancel (on) or allow (off) container transactions', 'ct-cancel <on|off>'],
    ];

    /** @var array<string, ChestInventory> */
    private array $windows = [];

    private bool $cancel = false;

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $map = $this->getServer()->getCommandMap();
        foreach (self::COMMANDS as $name => [$description, $usage]) {
            $map->register('mukkitcontainerprobe', new class ($name, $description, $usage, $this) extends Command {
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
                        $sender->sendMessage('container probe: no player online');
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
            case 'ct-open':
                // Anchor the window where the player stands. A fixed (0,64,0) is fine for the
                // offline bridge tests, but a real client only accepts a container open packet
                // for a block it has loaded and is near; otherwise it closes the window again.
                $base = $player->getPosition()->floor();
                $chest = new ChestInventory(new Position($base->getX(), $base->getY(), $base->getZ(), $player->getWorld()));
                $chest->setItem(0, $this->item('diamond', 5) ?? Item::get(Item::AIR));
                $chest->setItem(1, $this->item('apple', 3) ?? Item::get(Item::AIR));
                try {
                    $opened = $player->setCurrentWindow($chest);
                } catch (\Throwable $e) {
                    $this->getLogger()->info('ct-open failed: ' . $e->getMessage());
                    return true;
                }
                if (!$opened) {
                    $this->getLogger()->info('ct-open refused');
                    return true;
                }
                $this->windows[$player->getUniqueId()->toString()] = $chest;
                $manager = $player->getNetworkSession()->getInvManager();
                $id = $manager?->getWindowId($chest);
                $this->getLogger()->info('ct-open window=' . ($id === null ? -1 : $id) . ' size=' . $chest->getSize());
                return true;

            case 'ct-set':
                if (count($args) < 3) {
                    return $this->usage($sender);
                }
                $window = $player->getCurrentWindow();
                if ($window === null) {
                    $this->getLogger()->info('ct-set: no window');
                    return true;
                }
                $item = $this->item((string) $args[1], (int) $args[2]);
                if ($item === null) {
                    return true;
                }
                $window->setItem((int) $args[0], $item);
                $this->getLogger()->info('ct-set slot=' . (int) $args[0] . ' item=' . $item->getVanillaName());
                return true;

            case 'ct-report':
                $window = $player->getCurrentWindow();
                if ($window === null) {
                    $this->getLogger()->info('ct-report window=none');
                    return true;
                }
                $manager = $player->getNetworkSession()->getInvManager();
                $id = $manager?->getWindowId($window) ?? -1;
                $parts = [];
                foreach ($window->getContents(true) as $slot => $item) {
                    if ($item->isNull()) {
                        continue;
                    }
                    $parts[] = $slot . '=' . $item->getVanillaName() . 'x' . $item->getCount();
                }
                $this->getLogger()->info('ct-report window=' . $id . ' size=' . $window->getSize()
                    . ' slots=' . implode(',', $parts));
                return true;

            case 'ct-close':
                $player->removeCurrentWindow();
                $this->getLogger()->info('ct-close done');
                return true;

            case 'ct-cancel':
                $state = (string) ($args[0] ?? '');
                if ($state !== 'on' && $state !== 'off') {
                    return $this->usage($sender);
                }
                $this->cancel = $state === 'on';
                $this->getLogger()->info('ct-cancel ' . $state);
                return true;
        }

        return true;
    }

    public function onTransaction(InventoryTransactionEvent $event): void
    {
        $lines = [];
        foreach ($event->getTransaction()->getActions() as $action) {
            if ($action instanceof SlotChangeAction) {
                $lines[] = $action->getInventory()::class . '#' . $action->getSlot()
                    . ' ' . $action->getSourceItem()->getVanillaName()
                    . '->' . $action->getTargetItem()->getVanillaName();
            }
        }
        $this->getLogger()->info('ct-event ' . implode(',', $lines) . ($this->cancel ? ' cancelled' : ' allowed'));
        if ($this->cancel) {
            $event->cancel();
        }
    }

    private function item(string $name, int $count): ?Item
    {
        $item = StringToItemParser::getInstance()->parse($name);
        if ($item === null) {
            $this->getLogger()->info('container probe: unknown item ' . $name);
            return null;
        }
        return $item->setCount(max(1, $count));
    }

    private function usage(CommandSender $sender): bool
    {
        $sender->sendMessage('usage: ct-open | ct-set <slot> <item> <count> | ct-report | ct-close | ct-cancel <on|off>');
        return true;
    }
}
