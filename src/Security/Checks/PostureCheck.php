<?php

namespace LaraBug\Security\Checks;

/**
 * One part of the security posture snapshot.
 *
 * What it collects goes to LaraBug as it is, so it is only ever booleans,
 * names from a fixed set and versions. Never a value read from the
 * application's configuration: not a path, an origin, an address or a key.
 * The server decides what passes.
 */
interface PostureCheck
{
    /**
     * @return array<string, mixed>
     */
    public function collect(): array;
}
