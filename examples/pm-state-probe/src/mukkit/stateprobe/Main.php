<?php

declare(strict_types=1);

namespace mukkit\stateprobe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\data\bedrock\EffectIdMap;
use pocketmine\entity\effect\EffectInstance;
use pocketmine\entity\Location;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;

/**
 * Player-state probe: every command here uses nothing but the ordinary PocketMine API.
 *
 * The point is to prove that a plugin changing `teleport()` / `setHealth()` / `setGamemode()`
 * reaches the client, and that a change made on the Nukkit side reaches the plugin. No bridge
 * calls: if this plugin works, any economy or minigame plugin's state changes work.
 */
final class Main extends PluginBase implements Listener
{
    private const COMMANDS = [
        'st-move' => ['Teleport the player', 'st-move <x> <y> <z> [yaw] [pitch]'],
        'st-health' => ['Set the player health', 'st-health <amount>'],
        'st-gm' => ['Set the player gamemode', 'st-gm <survival|creative|adventure|spectator>'],
        'st-xp' => ['Set the player experience', 'st-xp <level> <progress>'],
        'st-food' => ['Set the player hunger', 'st-food <food> <saturation>'],
        'st-hold' => ['Select a hotbar slot', 'st-hold <0-8>'],
        'st-effect' => ['Add a potion effect', 'st-effect <id> <ticks> [amplifier]'],
        'st-cleareffects' => ['Remove all potion effects', 'st-cleareffects'],
        'st-op' => ['Grant or revoke operator status', 'st-op <on|off>'],
        'st-kick' => ['Kick the player', 'st-kick [reason]'],
        'st-report' => ['Report what PocketMine thinks the player state is', 'st-report'],
    ];

    /**
     * Reports the permissions the player already has inside `PlayerJoinEvent`.
     *
     * This is the ordering assertion for the operator bridge: if the bit were applied on the
     * first state poll instead of with the join, this line would say `op=false` for a player the
     * Nukkit console just opped - and a plugin gating its welcome logic on a `default: op` node
     * would refuse for the same reason.
     */
    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();
        $this->getLogger()->info(sprintf(
            'st-join name=%s op=%s admin=%s held=%d',
            $player->getName(),
            $player->hasPermission(DefaultPermissions::ROOT_OPERATOR) ? 'true' : 'false',
            $player->hasPermission('mukkit.stateprobe.admin') ? 'true' : 'false',
            //Which slot the client is holding: the join frame has to land this before the event,
            //or `getItemInHand()` answers with slot 0 for a player holding something else.
            $player->getInventory()->getHeldItemIndex()
        ));

        //Test hook, not a feature: a plugin that picks a hotbar slot while handling the join.
        //The bridge has to report it in the same tick's diff; if the shadow were seeded from the
        //post-event player, this change would be absorbed and Nukkit would never hear about it.
        if ($player->getName() === 'SlotJoin') {
            $player->getInventory()->setHeldItemIndex(6);
        }
    }

    /**
     * Logs the quit as PocketMine sees it, once per event.
     *
     * The kick path is the reason this exists: `Player::kick()` runs this event *before* anything
     * reaches the bridge, so a host that also ran the quit path on the way back would print two
     * lines for one kick. The test counts them.
     */
    public function onQuit(PlayerQuitEvent $event): void
    {
        $reason = $event->getQuitReason();
        $this->getLogger()->info(sprintf(
            'st-quit name=%s reason=%s',
            $event->getPlayer()->getName(),
            is_string($reason) ? $reason : 'translatable'
        ));
    }

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $map = $this->getServer()->getCommandMap();
        foreach (self::COMMANDS as $name => [$description, $usage]) {
            $map->register('mukkitstateprobe', new class ($name, $description, $usage) extends Command {
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
                        $sender->sendMessage('state probe: no player online');
                        return true;
                    }

                    switch ($label) {
                        case 'st-move':
                            if (count($args) < 3) {
                                return $this->usage($sender);
                            }
                            $location = $player->getLocation();
                            //The yaw/pitch on the target are placeholders: teleport() takes them
                            //as separate arguments below.
                            $player->teleport(
                                new Location((float) $args[0], (float) $args[1], (float) $args[2], $location->getWorld(), 0.0, 0.0),
                                isset($args[3]) ? (float) $args[3] : $location->yaw,
                                isset($args[4]) ? (float) $args[4] : $location->pitch
                            );
                            $sender->sendMessage('st-move done');
                            return true;

                        case 'st-health':
                            if (count($args) < 1) {
                                return $this->usage($sender);
                            }
                            $player->setHealth((float) $args[0]);
                            $sender->sendMessage('st-health done');
                            return true;

                        case 'st-gm':
                            if (count($args) < 1) {
                                return $this->usage($sender);
                            }
                            $mode = GameMode::fromString($args[0]);
                            if ($mode === null) {
                                $sender->sendMessage('st-gm: unknown gamemode ' . $args[0]);
                                return true;
                            }
                            $sender->sendMessage($player->setGamemode($mode) ? 'st-gm done' : 'st-gm refused');
                            return true;

                        case 'st-xp':
                            if (count($args) < 2) {
                                return $this->usage($sender);
                            }
                            $sender->sendMessage($player->getXpManager()->setXpAndProgress(
                                (int) $args[0],
                                (float) $args[1]
                            ) ? 'st-xp done' : 'st-xp refused');
                            return true;

                        case 'st-food':
                            if (count($args) < 2) {
                                return $this->usage($sender);
                            }
                            $hunger = $player->getHungerManager();
                            $hunger->setFood((float) $args[0]);
                            $hunger->setSaturation((float) $args[1]);
                            $sender->sendMessage('st-food done');
                            return true;

                        case 'st-hold':
                            if (count($args) < 1) {
                                return $this->usage($sender);
                            }
                            //Ordinary API: what a menu plugin uses to point the client at a slot,
                            //and what `getItemInHand()` follows. Out-of-range throws upstream.
                            try {
                                $player->getInventory()->setHeldItemIndex((int) $args[0]);
                            } catch (\InvalidArgumentException $e) {
                                $sender->sendMessage('st-hold: ' . $e->getMessage());
                                return true;
                            }
                            $sender->sendMessage('st-hold done');
                            return true;

                        case 'st-effect':
                            if (count($args) < 2) {
                                return $this->usage($sender);
                            }
                            $type = EffectIdMap::getInstance()->fromId((int) $args[0]);
                            if ($type === null) {
                                $sender->sendMessage('st-effect: unknown effect id ' . $args[0]);
                                return true;
                            }
                            $player->getEffects()->add(new EffectInstance(
                                $type,
                                (int) $args[1],
                                isset($args[2]) ? (int) $args[2] : 0
                            ));
                            $sender->sendMessage('st-effect done');
                            return true;

                        case 'st-cleareffects':
                            $player->getEffects()->clear();
                            $sender->sendMessage('st-cleareffects done');
                            return true;

                        case 'st-kick':
                            //Ordinary API, same call a ban or anti-cheat plugin makes. The reason
                            //ends up both on the client's disconnect screen and in the quit event.
                            $reason = $args === [] ? 'kicked by the probe' : implode(' ', $args);
                            $sender->sendMessage($player->kick($reason) ? 'st-kick done' : 'st-kick refused');
                            return true;

                        case 'st-op':
                            if (count($args) < 1 || ($args[0] !== 'on' && $args[0] !== 'off')) {
                                return $this->usage($sender);
                            }
                            //The ordinary API a plugin uses on its own players. `Server::addOp()`
                            //also flips the root permission on an online player, which is why
                            //the next `/st-report` already sees it.
                            if ($args[0] === 'on') {
                                Server::getInstance()->addOp($player->getName());
                            } else {
                                Server::getInstance()->removeOp($player->getName());
                            }
                            $sender->sendMessage('st-op done');
                            return true;

                        case 'st-report':
                            $location = $player->getLocation();
                            $xp = $player->getXpManager();
                            $hunger = $player->getHungerManager();
                            $effects = [];
                            foreach ($player->getEffects()->all() as $instance) {
                                $id = EffectIdMap::getInstance()->toId($instance->getType());
                                $effects[$id] = sprintf('%d:%d:%d', $id, $instance->getAmplifier(), $instance->getDuration());
                            }
                            ksort($effects);
                            //One line, because the test asserts on it verbatim.
                            $sender->sendMessage(sprintf(
                                'st-report x=%.2f y=%.2f z=%.2f yaw=%.2f pitch=%.2f health=%.2f max=%.2f gm=%s held=%d xp=%d:%.2f food=%.2f:%.2f effects=%s op=%s admin=%s',
                                $location->x,
                                $location->y,
                                $location->z,
                                $location->yaw,
                                $location->pitch,
                                $player->getHealth(),
                                $player->getMaxHealth(),
                                strtolower($player->getGamemode()->name),
                                $player->getInventory()->getHeldItemIndex(),
                                $xp->getXpLevel(),
                                $xp->getXpProgress(),
                                $hunger->getFood(),
                                $hunger->getSaturation(),
                                $effects === [] ? 'none' : implode(',', $effects),
                                //`op` is the root permission every `default: op` node hangs
                                //off; `admin` is this plugin's own node, declared that way in
                                //plugin.yml. A real plugin checks the second kind.
                                $player->hasPermission(DefaultPermissions::ROOT_OPERATOR) ? 'true' : 'false',
                                $player->hasPermission('mukkit.stateprobe.admin') ? 'true' : 'false'
                            ));
                            return true;
                    }

                    return $this->usage($sender);
                }

                private function usage(CommandSender $sender): bool
                {
                    $sender->sendMessage('usage: ' . $this->getUsage());
                    return true;
                }
            });
        }
        $this->getLogger()->info('state probe enabled');
    }
}
