<?php

namespace LaraBug\Tests;

use LaraBug\Console\CommandBuffer;
use LaraBug\Console\CommandListeners;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use LaraBug\Console\ScheduledTaskBuffer;
use LaraBug\Console\ScheduledTaskListeners;

class ScheduledTaskMonitoringTest extends TestCase
{
    protected function tearDown(): void
    {
        // The in-flight counter is static, so an unbalanced test would leak into the next one.
        ScheduledTaskListeners::$inFlight = 0;

        parent::tearDown();
    }

    #[Test]
    public function it_records_a_task_that_ran_with_its_expression_and_duration()
    {
        $buffer = $this->taskBuffer();
        $listeners = new ScheduledTaskListeners($buffer);

        $task = $this->fakeTask('suite:command-ok');

        $listeners->onScheduledTaskStarting($this->taskEvent($task));
        usleep(1000);
        $listeners->onScheduledTaskFinished($this->taskEvent($task, 0.1));

        $record = $buffer->records[0];
        $this->assertSame('suite:command-ok', $record['task']);
        $this->assertSame('* * * * *', $record['expression']);
        $this->assertSame('ran', $record['status']);
        $this->assertGreaterThan(0, $record['duration_ms']);
        $this->assertNotSame('', $record['trace_id']);
    }

    #[Test]
    public function it_records_a_failed_task()
    {
        $buffer = $this->taskBuffer();
        $listeners = new ScheduledTaskListeners($buffer);

        $task = $this->fakeTask('suite:command-fail');

        $listeners->onScheduledTaskStarting($this->taskEvent($task));
        $listeners->onScheduledTaskFailed($this->taskEvent($task));

        $this->assertSame('failed', $buffer->records[0]['status']);
    }

    #[Test]
    public function it_records_a_skipped_task()
    {
        $buffer = $this->taskBuffer();
        $listeners = new ScheduledTaskListeners($buffer);

        // A skipped task never starts, so there is only the skipped event.
        $listeners->onScheduledTaskSkipped($this->taskEvent($this->fakeTask('suite:skipped')));

        $record = $buffer->records[0];
        $this->assertSame('skipped', $record['status']);
        $this->assertSame(0.0, $record['duration_ms']);
    }

    #[Test]
    public function a_command_run_while_a_task_is_in_flight_is_attributed_to_the_schedule()
    {
        $taskBuffer = $this->taskBuffer();
        $commandBuffer = $this->commandBuffer();

        $tasks = new ScheduledTaskListeners($taskBuffer);
        $commands = new CommandListeners($commandBuffer);

        $task = $this->fakeTask('suite:command-ok');

        // The scheduler starts the task, runs the command in the same process, then the task finishes.
        $tasks->onScheduledTaskStarting($this->taskEvent($task));

        $input = $this->fakeInput();
        $commands->onCommandStarting($this->commandEvent('suite:command-ok', $input));
        $commands->onCommandFinished($this->commandEvent('suite:command-ok', $input, 0));

        $tasks->onScheduledTaskFinished($this->taskEvent($task, 0.1));

        $this->assertCount(0, $commandBuffer->records);
        $this->assertCount(1, $taskBuffer->records);
        $this->assertSame('suite:command-ok', $taskBuffer->records[0]['task']);
    }

    #[Test]
    public function a_standalone_command_is_still_recorded()
    {
        $commandBuffer = $this->commandBuffer();
        $commands = new CommandListeners($commandBuffer);
        $input = $this->fakeInput();

        $commands->onCommandStarting($this->commandEvent('suite:command-ok', $input));
        $commands->onCommandFinished($this->commandEvent('suite:command-ok', $input, 0));

        $this->assertCount(1, $commandBuffer->records);
    }

    #[Test]
    public function it_does_not_report_its_own_flush_being_skipped()
    {
        $buffer = $this->taskBuffer();
        $listeners = new ScheduledTaskListeners($buffer);

        $listeners->onScheduledTaskSkipped($this->taskEvent($this->fakeTask("'/usr/bin/php8.4' 'artisan' larabug:flush > '/dev/null' 2>&1")));

        $this->assertCount(0, $buffer->records);
    }

    #[Test]
    public function it_does_not_report_its_own_flush_running_as_a_task_or_as_a_command()
    {
        $taskBuffer = $this->taskBuffer();
        $commandBuffer = $this->commandBuffer();

        $tasks = new ScheduledTaskListeners($taskBuffer);
        $commands = new CommandListeners($commandBuffer);

        $task = $this->fakeTask("'/usr/bin/php8.4' 'artisan' larabug:flush > '/dev/null' 2>&1");

        $tasks->onScheduledTaskStarting($this->taskEvent($task));

        $input = $this->fakeInput();
        $commands->onCommandStarting($this->commandEvent('larabug:flush', $input));
        $commands->onCommandFinished($this->commandEvent('larabug:flush', $input, 0));

        $tasks->onScheduledTaskFinished($this->taskEvent($task, 0.1));

        $this->assertCount(0, $taskBuffer->records);
        $this->assertCount(0, $commandBuffer->records);
        $this->assertSame(0, ScheduledTaskListeners::$inFlight);
    }

    #[Test]
    #[DataProvider('ownCommands')]
    public function it_does_not_report_any_of_its_own_commands_being_skipped(string $command)
    {
        $buffer = $this->taskBuffer();
        $listeners = new ScheduledTaskListeners($buffer);

        $listeners->onScheduledTaskSkipped($this->taskEvent($this->fakeTask("'/usr/bin/php8.4' 'artisan' {$command} > '/dev/null' 2>&1")));

        $this->assertCount(0, $buffer->records);
    }

    #[Test]
    #[DataProvider('ownCommands')]
    public function it_does_not_report_any_of_its_own_commands_running_as_a_task(string $command)
    {
        $buffer = $this->taskBuffer();
        $listeners = new ScheduledTaskListeners($buffer);

        $task = $this->fakeTask("'/usr/bin/php8.4' 'artisan' {$command}");

        $listeners->onScheduledTaskStarting($this->taskEvent($task));
        $listeners->onScheduledTaskFinished($this->taskEvent($task, 0.1));

        $listeners->onScheduledTaskStarting($this->taskEvent($task));
        $listeners->onScheduledTaskFailed($this->taskEvent($task));

        $this->assertCount(0, $buffer->records);
        $this->assertSame(0, ScheduledTaskListeners::$inFlight);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ownCommands(): array
    {
        return [
            'flush' => ['larabug:flush'],
            'queue depth' => ['larabug:queue-depth'],
            'security posture' => ['larabug:security-posture'],
            'cve scan' => ['larabug:scan'],
            'with options' => ['larabug:queue-depth --show'],
        ];
    }

    #[Test]
    public function the_applications_own_tasks_mentioning_larabug_are_still_reported()
    {
        $buffer = $this->taskBuffer();
        $listeners = new ScheduledTaskListeners($buffer);

        $listeners->onScheduledTaskSkipped($this->taskEvent($this->fakeTask('app:larabug-flush')));
        $listeners->onScheduledTaskSkipped($this->taskEvent($this->fakeTask("'/usr/bin/php8.4' 'artisan' reports:send --to=larabug:flush")));

        $this->assertCount(2, $buffer->records);
    }

    private function taskBuffer(): ScheduledTaskBuffer
    {
        return new class () extends ScheduledTaskBuffer {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function __construct()
            {
            }

            public function add(array $record): void
            {
                $this->records[] = $record;
            }
        };
    }

    private function commandBuffer(): CommandBuffer
    {
        return new class () extends CommandBuffer {
            /** @var array<int, array<string, mixed>> */
            public array $records = [];

            public function __construct()
            {
            }

            public function add(array $record): void
            {
                $this->records[] = $record;
            }
        };
    }

    private function fakeTask(string $summary, string $expression = '* * * * *'): object
    {
        return new class ($summary, $expression) {
            public string $expression;

            public string $description;

            public ?string $command = null;

            public bool $withoutOverlapping = false;

            private string $summary;

            public function __construct(string $summary, string $expression)
            {
                $this->summary = $summary;
                $this->description = $summary;
                $this->expression = $expression;
            }

            public function getSummaryForDisplay(): string
            {
                return $this->summary;
            }
        };
    }

    private function taskEvent(object $task, ?float $runtime = null): object
    {
        $event = new \stdClass();
        $event->task = $task;

        if ($runtime !== null) {
            $event->runtime = $runtime;
        }

        return $event;
    }

    private function commandEvent(string $command, object $input, ?int $exitCode = null): object
    {
        $event = new \stdClass();
        $event->command = $command;
        $event->input = $input;

        if ($exitCode !== null) {
            $event->exitCode = $exitCode;
        }

        return $event;
    }

    private function fakeInput(): object
    {
        return new class () {
            /** @return array<string, mixed> */
            public function getArguments(): array
            {
                return [];
            }

            /** @return array<string, mixed> */
            public function getOptions(): array
            {
                return [];
            }
        };
    }
}
