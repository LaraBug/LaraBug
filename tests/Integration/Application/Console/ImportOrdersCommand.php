<?php

namespace ExampleApp\Console;

use Illuminate\Console\Command;

/** A command of the kind an application schedules and runs by hand. */
class ImportOrdersCommand extends Command
{
    protected $signature = 'example:import-orders {source=ftp} {--token=} {--dry-run}';

    protected $description = 'Import today\'s orders';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
