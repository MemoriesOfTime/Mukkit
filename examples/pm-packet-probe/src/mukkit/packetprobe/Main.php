<?php

declare(strict_types=1);

namespace mukkit\packetprobe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\network\mcpe\protocol\ClientboundCloseFormPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\ToastRequestPacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

/**
 * M4 acceptance plugin: on `/m4packets` it sends exactly one of every packet the bridge claims
 * to map, to the player who asked.
 *
 * Everything goes through `sendDataPacket()` on the live session - the same call a real plugin
 * makes - so what the test asserts is the production path, not a synthetic one. The values are
 * deliberately awkward (fractional and negative sound coordinates, two scoreboard slots, a
 * non-ASCII title) because those are where a mapper that "mostly works" falls over.
 */
final class Main extends PluginBase
{
    public function onEnable(): void
    {
        $this->getServer()->getCommandMap()->register('mukkitpacketprobe', new class extends Command {
            public function __construct()
            {
                parent::__construct('m4packets', 'Sends one of every M4-whitelisted packet', '/m4packets');
                $this->setPermission(DefaultPermissions::ROOT_USER);
            }

            public function execute(CommandSender $sender, string $label, array $args): bool
            {
                // From the console/RCON the probe needs an explicit target; a real client
                // normally runs this as a player, which is the path P13's smoke test uses.
                $player = $sender instanceof Player
                    ? $sender
                    : (isset($args[0]) ? $sender->getServer()->getPlayerExact($args[0]) : null);
                if (!$player instanceof Player) {
                    $sender->sendMessage('m4packets needs a player');
                    return true;
                }
                $this->emit($player, $args[1] ?? 'all');
                return true;
            }

            private function emit(Player $player, string $mode = 'all'): void
            {
                $session = $player->getNetworkSession();

                // P13 screenshots need the transient packets to stay on screen. The default
                // mode still fires the whole whitelist (including the immediate remove), while
                // `title` / `toast` / `board` leave one family visible long enough to capture.
                if ($mode === 'title') {
                    $session->sendDataPacket(SetTitlePacket::title('Mukkit M4'));
                    $session->sendDataPacket(SetTitlePacket::subtitle('sub'));
                    $session->sendDataPacket(SetTitlePacket::setAnimationTimes(10, 200, 10));
                    $player->getServer()->getLogger()->info('m4packets title sent');
                    return;
                }
                if ($mode === 'toast') {
                    $session->sendDataPacket(ToastRequestPacket::create('Mukkit', 'toast body'));
                    $player->getServer()->getLogger()->info('m4packets toast sent');
                    return;
                }
                if ($mode === 'board') {
                    $this->sendBoard($player);
                    $player->getServer()->getLogger()->info('m4packets board sent');
                    return;
                }

                // TextPacket, for completeness: it is the one packet M3 already shipped, and
                // sending it here keeps the fixture a full picture of the whitelist.
                $session->sendDataPacket(\pocketmine\network\mcpe\protocol\TextPacket::raw('m4 raw text'));

                // Title family: two different variants, plus animation times, because a mapper
                // that assumes "title" everywhere would still look right for one packet.
                $session->sendDataPacket(SetTitlePacket::title('Mukkit M4'));
                $session->sendDataPacket(SetTitlePacket::subtitle('sub'));
                $session->sendDataPacket(SetTitlePacket::actionBarMessage('action bar'));
                $session->sendDataPacket(SetTitlePacket::setAnimationTimes(10, 40, 10));

                $session->sendDataPacket(ToastRequestPacket::create('Mukkit', 'toast body'));

                $session->sendDataPacket(ModalFormRequestPacket::create(
                    7,
                    '{"type":"form","title":"Pick one","content":"","buttons":[{"text":"A"},{"text":"B"}]}'
                ));

                // The other half of the form story: closing whatever is on screen. It carries no
                // payload on either side, so the assertion is that a payloadless packet survives
                // the trip at all.
                $session->sendDataPacket(ClientboundCloseFormPacket::create());

                // -3.25 is the interesting coordinate: flooring must go towards negative
                // infinity, not towards zero, or the sound lands a block away.
                $session->sendDataPacket(PlaySoundPacket::create('random.orb', 12.5, 64.0, -3.25, 0.7, 1.4, null));

                $this->sendBoard($player);
                $session->sendDataPacket(RemoveObjectivePacket::create('mukkit_obj'));

                // Not in the whitelist: the host must count and report it rather than drop it
                // silently, so the test can prove the boundary is still a boundary.
                $session->sendDataPacket(SetTimePacket::create(6000));

                // NB: inside an anonymous Command, `$this` is the command, not the plugin.
                $player->getServer()->getLogger()->info('m4packets sent');
            }

            /** Objective + two entries, without the remove: a sidebar a screenshot can catch. */
            private function sendBoard(Player $player): void
            {
                $session = $player->getNetworkSession();
                $session->sendDataPacket(SetDisplayObjectivePacket::create(
                    SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
                    'mukkit_obj',
                    'Mukkit Board',
                    'dummy',
                    SetDisplayObjectivePacket::SORT_ORDER_DESCENDING
                ));

                $playerEntry = new ScorePacketEntry();
                $playerEntry->scoreboardId = 1;
                $playerEntry->objectiveName = 'mukkit_obj';
                $playerEntry->score = 42;
                $playerEntry->type = ScorePacketEntry::TYPE_PLAYER;
                $playerEntry->actorUniqueId = $player->getId();

                $fakeEntry = new ScorePacketEntry();
                $fakeEntry->scoreboardId = 2;
                $fakeEntry->objectiveName = 'mukkit_obj';
                $fakeEntry->score = 7;
                $fakeEntry->type = ScorePacketEntry::TYPE_FAKE_PLAYER;
                $fakeEntry->customName = 'Steve';

                $session->sendDataPacket(SetScorePacket::create(
                    SetScorePacket::TYPE_CHANGE,
                    [$playerEntry, $fakeEntry]
                ));
            }
        });
        $this->getLogger()->info('packet probe enabled');
    }
}
