<?php

declare(strict_types=1);

namespace Mukkit\Host;

/** What a plugin runtime has to be able to do for the bridge to drive it. */
interface Profile
{
    public function name(): string;

    /**
     * @param list<array<string, mixed>> $plugins
     * @param array<string, mixed> $context facts the adapter learned before boot; profiles that
     *        can use them read them, the rest ignore them. Currently: `defaultWorld`, the level
     *        name Nukkit calls its default, so a profile can create it before plugins enable.
     * @return array{loaded: list<array<string, mixed>>, rejected: list<array<string, mixed>>}
     */
    public function load(array $plugins, array $context = []): array;

    /**
     * @param list<array<string, mixed>> $inbound events and commands accumulated since the last tick
     * @return list<array<string, mixed>> outbound operations for the server to apply
     */
    public function tick(array $inbound): array;

    public function shutdown(): void;

    /**
     * Whether this profile executes commands players type.
     *
     * Yes means "claim every command name Nukkit itself does not know": the adapter cancels the
     * preprocess event and forwards the line instead of letting Nukkit answer "unknown command",
     * which is the only way a PocketMine plugin's `/money` exists at all. Only a profile with a
     * real `CommandMap` behind it can say yes - `pm`. The observing profiles must say no, or they
     * would swallow commands they cannot run.
     */
    public function ownsCommands(): bool;

    /**
     * Events this profile wants the adapter to hold back for a decision.
     *
     * Empty means "intercept nothing": the adapter then lets every action through immediately,
     * which is the only acceptable default for a profile that cannot dispatch PM events.
     *
     * @return list<string>
     */
    public function eventDemand(): array;
}
