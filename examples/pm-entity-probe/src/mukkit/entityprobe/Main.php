<?php

declare(strict_types=1);

namespace mukkit\entityprobe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\object\ItemEntity;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

/**
 * Entity probe: everything here is ordinary PocketMine code.
 *
 * The point is that a mirrored entity is a *real* entity: `instanceof Living`, `getLocation()`,
 * `setHealth()` and `teleport()` all behave the way a plugin expects. No bridge-specific calls,
 * and no knowledge of the id Nukkit gave the entity.
 */
final class Main extends PluginBase
{
    private const COMMANDS = [
        'e-report' => ['Report the first non-player entity', '/e-report'],
        'e-item' => ['Report the first dropped stack', '/e-item'],
        'e-hurt' => ['Set the first entity health', '/e-hurt <amount>'],
        'e-move' => ['Teleport the first entity', '/e-move <x> <y> <z>'],
        'e-name' => ['Set the first entity name tag', '/e-name <name>'],
        'e-effect' => ['Add a potion effect to the first entity', '/e-effect <id> <ticks> [amplifier]'],
        'e-cleareffects' => ['Remove all effects from the first entity', '/e-cleareffects'],
    ];

    public function onEnable(): void
    {
        $map = $this->getServer()->getCommandMap();
        foreach (self::COMMANDS as $name => [$description, $usage]) {
            $map->register('mukkitentityprobe', new class ($name, $description, $usage) extends Command {
                public function __construct(string $name, string $description, string $usage)
                {
                    parent::__construct($name, $description, $usage);
                    //PM5 refuses to register a command without a permission set.
                    $this->setPermission(DefaultPermissions::ROOT_USER);
                }

                public function execute(CommandSender $sender, string $label, array $args): bool
                {
                    $player = $sender instanceof Player
                        ? $sender
                        : array_values(Server::getInstance()->getOnlinePlayers())[0] ?? null;
                    if (!$player instanceof Player) {
                        $sender->sendMessage('entity probe: no player online');
                        return true;
                    }
                    $world = $player->getWorld();

                    //Players are entities too. A plugin asking for "the mobs here" filters them
                    //out itself, and so does this probe - otherwise every assertion would be
                    //measuring the bridged player.
                    $entities = array_values(array_filter(
                        $world->getEntities(),
                        static fn (Entity $entity): bool => !$entity instanceof Player
                    ));
                    $first = $entities[0] ?? null;

                    if ($label === 'e-report') {
                        if (!$first instanceof Entity) {
                            $sender->sendMessage('e-report count=0');
                            return true;
                        }
                        $location = $first->getLocation();
                        $effects = [];
                        if ($first instanceof Living) {
                            foreach ($first->getEffects()->all() as $instance) {
                                $effectId = EffectIdMap::getInstance()->toId($instance->getType());
                                $effects[$effectId] = sprintf('%d:%d:%d', $effectId, $instance->getAmplifier(), $instance->getDuration());
                            }
                            ksort($effects);
                        }
                        $sender->sendMessage(sprintf(
                            'e-report count=%d type=%s x=%.2f y=%.2f z=%.2f hp=%s name=%s effects=%s',
                            count($entities),
                            $first::getNetworkTypeId(),
                            $location->x,
                            $location->y,
                            $location->z,
                            $first instanceof Living ? sprintf('%.2f', $first->getHealth()) : 'n/a',
                            $first->getNameTag() === '' ? '-' : '"' . $first->getNameTag() . '"',
                            $effects === [] ? 'none' : implode(',', $effects)
                        ));
                        return true;
                    }

                    if ($label === 'e-item') {
                        foreach ($entities as $entity) {
                            if (!$entity instanceof ItemEntity) {
                                continue;
                            }
                            $item = $entity->getItem();
                            $sender->sendMessage(sprintf(
                                'e-item name=%s count=%d custom=%s',
                                $item->getVanillaName(),
                                $item->getCount(),
                                $item->hasCustomName() ? '"' . $item->getCustomName() . '"' : '-'
                            ));
                            return true;
                        }
                        $sender->sendMessage('e-item none');
                        return true;
                    }

                    if (!$first instanceof Entity) {
                        $sender->sendMessage($label . ': no entity in the world');
                        return true;
                    }

                    if ($label === 'e-hurt') {
                        if (!$first instanceof Living) {
                            $sender->sendMessage('e-hurt: first entity is not living');
                            return true;
                        }
                        if (count($args) < 1) {
                            $sender->sendMessage('usage: ' . $this->getUsage());
                            return true;
                        }
                        $first->setHealth((float) $args[0]);
                        $sender->sendMessage(sprintf('e-hurt done hp=%.2f', $first->getHealth()));
                        return true;
                    }

                    if ($label === 'e-name') {
                        if (count($args) < 1) {
                            $sender->sendMessage('usage: ' . $this->getUsage());
                            return true;
                        }
                        //An empty argument clears the name; the bridge has to carry that too.
                        $first->setNameTag($args[0]);
                        $sender->sendMessage(sprintf('e-name done name="%s"', $first->getNameTag()));
                        return true;
                    }

                    if ($label === 'e-effect' || $label === 'e-cleareffects') {
                        if (!$first instanceof Living) {
                            $sender->sendMessage($label . ': first entity is not living');
                            return true;
                        }
                        if ($label === 'e-cleareffects') {
                            $first->getEffects()->clear();
                            $sender->sendMessage('e-cleareffects done');
                            return true;
                        }
                        if (count($args) < 2) {
                            $sender->sendMessage('usage: ' . $this->getUsage());
                            return true;
                        }
                        $type = EffectIdMap::getInstance()->fromId((int) $args[0]);
                        if ($type === null) {
                            $sender->sendMessage('e-effect: unknown effect id ' . $args[0]);
                            return true;
                        }
                        $first->getEffects()->add(new EffectInstance(
                            $type,
                            (int) $args[1],
                            isset($args[2]) ? (int) $args[2] : 0
                        ));
                        $sender->sendMessage('e-effect done');
                        return true;
                    }

                    if (count($args) < 3) {
                        $sender->sendMessage('usage: ' . $this->getUsage());
                        return true;
                    }
                    $location = $first->getLocation();
                    $first->teleport(new Location(
                        (float) $args[0],
                        (float) $args[1],
                        (float) $args[2],
                        $location->getWorld(),
                        $location->yaw,
                        $location->pitch
                    ));
                    $sender->sendMessage(sprintf('e-move done %s,%s,%s', $args[0], $args[1], $args[2]));
                    return true;
                }
            });
        }
        $this->getLogger()->info('entity probe enabled');
    }
}
