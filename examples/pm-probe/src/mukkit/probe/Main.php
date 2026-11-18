<?php

declare(strict_types=1);

namespace mukkit\probe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\item\VanillaItems;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

/**
 * M3 acceptance plugin. Every line it logs is an assertion in pm-host/tests/pm-player-test.php.
 *
 * It is deliberately ordinary: a real `PluginBase`, real listeners, the four APIs the roadmap
 * names (`getName`, `sendMessage`, `getPosition`, inventory). If this plugin works unmodified
 * then the proxy is honest.
 */
final class Main extends PluginBase implements Listener
{
    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $self = $this;
        $this->getServer()->getCommandMap()->register('mukkitprobe', new class extends Command {
            public function __construct()
            {
                parent::__construct('probe', 'Replies with the sender as PocketMine sees it', '/probe');
                //PM5 refuses to register a command with no permission; ROOT_USER is the one every
                //player has, so the proxy test does not also need an ops entry.
                $this->setPermission(DefaultPermissions::ROOT_USER);
            }

            public function execute(CommandSender $sender, string $label, array $args): bool
            {
                if (!$sender instanceof Player) {
                    $sender->sendMessage('probe pong console');
                    return true;
                }
                $pos = $sender->getPosition();
                $sender->sendMessage(sprintf(
                    'probe pong name=%s pos=%.2f,%.2f,%.2f',
                    $sender->getName(),
                    $pos->getX(),
                    $pos->getY(),
                    $pos->getZ()
                ));
                return true;
            }
        });
        // The command a *player* types. Its senders differ in everything that matters - class,
        // name, permissions, position - so one line naming them is enough to tell a real player
        // dispatch from a console fallback.
        $this->getServer()->getCommandMap()->register('mukkitprobe', new class($self) extends Command {
            public function __construct(private readonly PluginBase $plugin)
            {
                parent::__construct('cmdprobe', 'Reports who PocketMine thinks typed the command', '/cmdprobe <words...>');
                $this->setPermission(DefaultPermissions::ROOT_USER);
            }

            public function execute(CommandSender $sender, string $label, array $args): bool
            {
                $line = sprintf(
                    'probe cmd sender=%s name=%s args=%s',
                    $sender instanceof Player ? 'player' : 'console',
                    $sender->getName(),
                    implode(' ', $args)
                );
                // Two sinks on purpose: the log op is what the test greps, and the message proves
                // the answer travelled back through this sender's own stream.
                $this->plugin->getLogger()->info($line);
                $sender->sendMessage($line);
                return true;
            }
        });
        $this->getLogger()->info('probe enabled');
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();

        //Inventory round trip: proves the item stack lives in this process and survives a write.
        $player->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(3));
        $item = $player->getInventory()->getItem(0);

        $pos = $player->getPosition();
        $this->getLogger()->info(sprintf(
            'probe join name=%s pos=%.2f,%.2f,%.2f world=%s item=%d x%d',
            $player->getName(),
            $pos->getX(),
            $pos->getY(),
            $pos->getZ(),
            $player->getWorld()->getDisplayName(),
            $item->getTypeId(),
            $item->getCount()
        ));

        $player->sendMessage('welcome ' . $player->getName());
    }

    public function onChat(PlayerChatEvent $event): void
    {
        $this->getLogger()->info('probe chat from=' . $event->getPlayer()->getName() . ' message=' . $event->getMessage());
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $this->getLogger()->info('probe quit name=' . $event->getPlayer()->getName());
    }
}
