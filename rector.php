<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\SetList;
use Rector\Core\ValueObject\PhpVersion;

return static function (RectorConfig $rectorConfig): void {
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ]);

    $rectorConfig->sets([
        SetList::PHP_80,
    ]);

    $rectorConfig->phpVersion(PhpVersion::PHP_80);
};
