<?php

declare(strict_types=1);

namespace mukkit\formprobe;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

/**
 * A button list, written out by hand.
 *
 * PocketMine 5 no longer ships `SimpleForm`; plugins either bundle a form library or implement the
 * interface themselves, and this is the second case. That makes the probe a better witness than a
 * built-in class would be: the bridge has no knowledge of any particular implementation, it only
 * knows `sendForm()` and `handleResponse()`.
 */
final class ProbeSimpleForm implements Form
{
    /**
     * @param \Closure(Player, ?int): void $handle
     * @param list<string> $buttons
     */
    public function __construct(
        private readonly string $title,
        private readonly array $buttons,
        private readonly \Closure $handle,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            'type' => 'form',
            'title' => $this->title,
            'content' => 'Pick one',
            'buttons' => array_map(static fn (string $text): array => ['text' => $text], $this->buttons),
        ];
    }

    public function handleResponse(Player $player, $data): void
    {
        //The shape is part of the contract: a simple form answers with a button index or null.
        //Getting anything else means the bridge decoded the response into the wrong shape, which
        //is exactly the failure a hand-written form would surface first.
        if ($data !== null && !is_int($data)) {
            throw new FormValidationException('expected a button index or null, got ' . get_debug_type($data));
        }
        ($this->handle)($player, $data);
    }
}
