<?php

declare(strict_types=1);

namespace mukkit\worldprobe;

use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

/**
 * M5's probe: the world mirror is only real if ordinary plugin code can read and write blocks
 * through the ordinary API. Everything this plugin does is `$world->getBlockAt()` /
 * `$world->setBlockAt()` - no bridge-specific calls.
 */
final class Main extends PluginBase
{
    public function onEnable(): void
    {
        $commands = $this->getServer()->getCommandMap();
        $definitions = [
            'm5read' => ['Report the block at a position', '/m5read <x> <y> <z>'],
            'm5write' => ['Write a diamond block', '/m5write <x> <y> <z>'],
            'm5time' => ['Set the world time', '/m5time <ticks>'],
            'm5stop' => ['Stop the world clock', '/m5stop'],
            'm5start' => ['Start the world clock', '/m5start'],
            'm5clock' => ['Report the world clock', '/m5clock'],
            'm5worlds' => ['Report the worlds the manager knows', '/m5worlds'],
        ];
        foreach ($definitions as $name => [$description, $usage]) {
            $commands->register('mukkitworldprobe', new class ($name, $description, $usage) extends Command {
                public function __construct(string $name, string $description, string $usage)
                {
                    parent::__construct($name, $description, $usage);
                    //PM5 refuses to register a command without a permission; ROOT_USER is the
                    //one every player has, so the test does not need an ops entry.
                    $this->setPermission(DefaultPermissions::ROOT_USER);
                }

                public function execute(CommandSender $sender, string $label, array $args): bool
                {
                    //World discovery does not need a player: the manager is the thing under test.
                    if ($label === 'm5worlds') {
                        $manager = \pocketmine\Server::getInstance()->getWorldManager();
                        $names = array_map(
                            static fn (\pocketmine\world\World $world): string => $world->getFolderName(),
                            array_values($manager->getWorlds())
                        );
                        sort($names);
                        $sender->sendMessage(sprintf(
                            'm5worlds default=%s count=%d names=%s world=%s arena=%s',
                            $manager->getDefaultWorld()?->getFolderName() ?? 'none',
                            count($names),
                            implode(',', $names),
                            $manager->getWorldByName('world') !== null ? 'yes' : 'no',
                            $manager->getWorldByName('arena') !== null ? 'yes' : 'no'
                        ));
                        return true;
                    }

                    //Console has no world of its own; the world under test is the one the bridged
                    //player is in, which is also the one that matters for a plugin.
                    $world = $sender instanceof Player
                        ? $sender->getWorld()
                        : (array_values(\pocketmine\Server::getInstance()->getOnlinePlayers())[0] ?? null)?->getWorld();
                    if ($world === null) {
                        $sender->sendMessage('no world to probe');
                        return true;
                    }

                    if ($label === 'm5clock') {
                        $sender->sendMessage(sprintf(
                            'm5clock time=%d stopped=%s',
                            $world->getTime(),
                            $world->stopTime ? 'yes' : 'no'
                        ));
                        return true;
                    }

                    if ($label === 'm5stop' || $label === 'm5start') {
                        $label === 'm5stop' ? $world->stopTime() : $world->startTime();
                        $sender->sendMessage($label . ' done');
                        return true;
                    }

                    if ($label === 'm5time') {
                        if (count($args) < 1) {
                            $sender->sendMessage('usage: ' . $this->getUsage());
                            return true;
                        }
                        $world->setTime((int) $args[0]);
                        $sender->sendMessage('m5time ' . (int) $args[0] . ' done');
                        return true;
                    }

                    if (count($args) < 3) {
                        $sender->sendMessage('usage: ' . $this->getUsage());
                        return true;
                    }
                    [$x, $y, $z] = [(int) $args[0], (int) $args[1], (int) $args[2]];

                    if ($label === 'm5read') {
                        $block = $world->getBlockAt($x, $y, $z);
                        $sender->sendMessage(sprintf('m5read %d,%d,%d = %s', $x, $y, $z, $block->getName()));
                        return true;
                    }

                    $world->setBlockAt($x, $y, $z, VanillaBlocks::DIAMOND());
                    $sender->sendMessage(sprintf('m5write %d,%d,%d done', $x, $y, $z));
                    return true;
                }
            });
        }
        $this->getLogger()->info('world probe enabled');
    }
}
