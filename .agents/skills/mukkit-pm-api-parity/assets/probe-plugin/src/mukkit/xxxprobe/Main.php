<?php

declare(strict_types=1);

namespace mukkit\xxxprobe;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

/**
 * 模板：把 xxx / Xxx 替换成能力名（如 state / State、tile / Tile）。
 *
 * 三条约束，违反任何一条判据就作废：
 *   1. 只用 pocketmine\* 的普通 API，不出现任何 bridge 专用类；
 *   2. 命令只负责「做事」和「报告」，断言留给宿主判据与 Nukkit Check；
 *   3. report 输出一行固定前缀的 key=value，反向验证靠它。
 */
final class Main extends PluginBase
{
    public function onEnable(): void
    {
        $commands = $this->getServer()->getCommandMap();
        $definitions = [
            'xx-do' => ['Change something through the ordinary API', '/xx-do <value>'],
            'xx-report' => ['Report what the plugin sees', '/xx-report'],
        ];
        foreach ($definitions as $name => [$description, $usage]) {
            $commands->register('mukkitxxxprobe', new class ($name, $description, $usage) extends Command {
                public function __construct(string $name, string $description, string $usage)
                {
                    parent::__construct($name, $description, $usage);
                    // ROOT_USER 让测试不必配 OP；真实管理命令可以改成 plugin.yml 里的 admin 节点。
                    $this->setPermission(DefaultPermissions::ROOT_USER);
                }

                public function execute(CommandSender $sender, string $label, array $args): bool
                {
                    if ($label === 'xx-report') {
                        $sender->sendMessage(sprintf(
                            'xx-report value=%s',
                            // 读回插件视角的真实状态，不要回显命令参数——那证明不了转换。
                            \mukkit\xxxprobe\Main::probeValue()
                        ));
                        return true;
                    }

                    // 控制台没有世界/玩家；需要时取第一个在线玩家，并显式处理拿不到的情况。
                    $player = $sender instanceof Player
                        ? $sender
                        : (array_values(\pocketmine\Server::getInstance()->getOnlinePlayers())[0] ?? null);
                    if ($player === null) {
                        $sender->sendMessage('xx: no player to probe');
                        return true;
                    }

                    // ↓↓↓ 这里放被测的普通 API 调用，例如：
                    // $player->setHealth((float) ($args[0] ?? 7));
                    // $player->getWorld()->setBlockAt((int) $args[0], 64, (int) $args[1], VanillaBlocks::DIAMOND());
                    $player->sendMessage(sprintf('xx-do value=%s', $args[0] ?? 'none'));
                    return true;
                }
            });
        }
    }

    /** 报告当前状态。真实探针里从 Player/World 读，不要回显参数。 */
    public static function probeValue(): string
    {
        $player = array_values(\pocketmine\Server::getInstance()->getOnlinePlayers())[0] ?? null;
        return $player === null ? 'none' : (string) $player->getHealth();
    }
}
