<?php

declare(strict_types=1);

namespace mukkit\formprobe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

/**
 * Form probe: a menu plugin in miniature.
 *
 * The whole point is that nothing here knows about the bridge. It calls `Player::sendForm()` and
 * gets a callback, which is the entire PocketMine contract; whether the client is a real one or
 * the adapter forwarding a `ModalFormResponsePacket` is invisible to it.
 *
 * Note what is *not* here: no event listeners. Forms travel on their own channel, so a plugin that
 * only shows menus must work without `eventDemand()` ever mentioning anything.
 */
final class Main extends PluginBase
{
    public function onEnable(): void
    {
        $map = $this->getServer()->getCommandMap();
        $commands = [
            'form-open' => ['Open the button form', 'form-open <player>'],
            'form-custom' => ['Open the element form', 'form-custom <player>'],
            'form-close' => ['Close whatever form is open', 'form-close <player>'],
        ];
        foreach ($commands as $name => [$description, $usage]) {
            $map->register('mukkitformprobe', new class ($name, $description, $usage, $this) extends Command {
                public function __construct(
                    string $name,
                    string $description,
                    string $usage,
                    private readonly Main $plugin,
                ) {
                    parent::__construct($name, $description, $usage);
                    $this->setPermission(DefaultPermissions::ROOT_USER);
                }

                public function execute(CommandSender $sender, string $label, array $args): bool
                {
                    return $this->plugin->handleCommand($label, $args, $sender);
                }
            });
        }
        $this->getLogger()->info('form probe enabled');
    }

    /**
     * @param list<string> $args
     */
    public function handleCommand(string $label, array $args, CommandSender $sender): bool
    {
        $name = $args[0] ?? '';
        $player = $name === '' ? null : $this->getServer()->getPlayerExact($name);
        if (!$player instanceof Player) {
            $sender->sendMessage('no such player: ' . $name);
            return true;
        }

        switch ($label) {
            case 'form-open':
                $player->sendForm(new ProbeSimpleForm(
                    'Mukkit simple probe',
                    ['First', 'Second'],
                    function (Player $player, ?int $index): void {
                        $this->getLogger()->info($index === null
                            ? 'simple: closed'
                            : 'simple: index=' . $index);
                    }
                ));
                $sender->sendMessage('form-open done');
                return true;

            case 'form-custom':
                $player->sendForm(new ProbeCustomForm(
                    function (Player $player, ?array $data): void {
                        $this->getLogger()->info($data === null
                            ? 'custom: closed'
                            : 'custom: ' . json_encode($data));
                    }
                ));
                $sender->sendMessage('form-custom done');
                return true;

            case 'form-close':
                $player->closeAllForms();
                $sender->sendMessage('form-close done');
                return true;
        }

        return false;
    }
}
