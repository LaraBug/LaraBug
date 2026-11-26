<?php

namespace LaraBug\Security\Checks;

/**
 * Whether `app.debug` is on. Whether that is a problem depends on the
 * environment, which the snapshot carries next to it.
 */
class DebugModeCheck implements PostureCheck
{
    /**
     * @return array{enabled: bool}
     */
    public function collect(): array
    {
        return [
            'enabled' => (bool) config('app.debug'),
        ];
    }
}
