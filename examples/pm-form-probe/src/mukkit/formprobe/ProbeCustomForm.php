<?php

declare(strict_types=1);

namespace mukkit\formprobe;

use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;

/**
 * A mixed-element form, which is where the response stops being a scalar.
 *
 * A custom form answers with an array of one value per element, in declaration order, and the
 * types differ per element (dropdown index, string, bool, float). If the bridge mangled the JSON -
 * flattened it, reordered it, turned numbers into strings - this is the form that would notice.
 */
final class ProbeCustomForm implements Form
{
    /**
     * @param \Closure(Player, ?array): void $handle
     */
    public function __construct(
        private readonly \Closure $handle,
    ) {
    }

    public function jsonSerialize(): array
    {
        return [
            // Bedrock's wire value is `custom_form`; PM5 no longer ships a form class, so the
            // probe writes the JSON itself and a wrong `"custom"` here is rejected by the client.
            'type' => 'custom_form',
            'title' => 'Mukkit custom probe',
            'content' => [
                ['type' => 'dropdown', 'text' => 'Pick one', 'options' => ['alpha', 'beta', 'gamma'], 'default' => 0],
                ['type' => 'input', 'text' => 'Name', 'placeholder' => 'type here', 'default' => ''],
                ['type' => 'toggle', 'text' => 'On?', 'default' => false],
                ['type' => 'slider', 'text' => 'Amount', 'min' => 0, 'max' => 10, 'step' => 1, 'default' => 1],
            ],
        ];
    }

    public function handleResponse(Player $player, $data): void
    {
        if ($data === null) {
            ($this->handle)($player, null);
            return;
        }
        if (!is_array($data) || count($data) !== 4) {
            throw new FormValidationException(
                'expected 4 element values or null, got ' . get_debug_type($data)
                . (is_array($data) ? ' with ' . count($data) . ' entries' : '')
            );
        }
        foreach (['int', 'string', 'bool'] as $offset => $type) {
            if (get_debug_type($data[$offset]) !== $type) {
                throw new FormValidationException("element {$offset} is not a {$type}");
            }
        }
        if (!is_int($data[3]) && !is_float($data[3])) {
            throw new FormValidationException('element 3 is not a number');
        }
        ($this->handle)($player, $data);
    }
}
