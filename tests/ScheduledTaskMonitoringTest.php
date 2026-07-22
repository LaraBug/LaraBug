<?php

namespace LaraBug\Tests;

use LaraBug\Console\CommandBuffer;
use LaraBug\Console\CommandListeners;
use LaraBug\Console\ScheduledTaskBuffer;
use LaraBug\Console\ScheduledTaskListeners;

class ScheduledTaskMonitoringTest extends TestCase
{
    protected function tearDown(): void
    {
        // The in-flight counter is static, so an unbalanced test would leak into
        // the next one.
        ScheduledTaskListeners::$inFlight = 0;

        parent::tearDown();
    }

    /** @test */
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

    /** @test */
    public function it_records_a_failed_task()
    {
        $buffer = $this->taskBuffer();
        $listeners = new ScheduledTaskListeners($buffer);

        $task = $this->fakeTask('suite:command-fail');

        $listeners->onScheduledTaskStarting($this->taskEvent($task));
        $listeners->onScheduledTaskFailed($this->taskEvent($task));

        $this->assertSame('failed', $buffer->records[0]['status']);
    }

    /** @test */
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

    /** @test */
    public function a_command_run_while_a_task_is_in_flight_is_attributed_to_the_schedule()
    {
        $taskBuffer = $this->taskBuffer();
        $commandBuffer = $this->commandBuffer();

        $tasks = new ScheduledTaskListeners($taskBuffer);
        $commands = new CommandListeners($commandBuffer);

        $task = $this->fakeTask('suite:command-ok');

        // The scheduler starts the task, then runs the command in the same
        // process, then the task finishes.
        $tasks->onScheduledTaskStarting($this->taskEvent($task));

        $input = $this->fakeInput();
        $commands->onCommandStarting($this->commandEvent('suite:command-ok', $input));
        $commands->onCommandFinished($this->commandEvent('suite:command-ok', $input, 0));

        $tasks->onScheduledTaskFinished($this->taskEvent($task, 0.1));

        // Counted once, against the schedule: no command record, one task record.
        $this->assertCount(0, $commandBuffer->records);
        $this->assertCount(1, $taskBuffer->records);
        $this->assertSame('suite:command-ok', $taskBuffer->records[0]['task']);
    }

    /** @test */
    public function a_standalone_command_is_still_recorded()
    {
        // With no task in flight, the same command is a command as usual.
        $commandBuffer = $this->commandBuffer();
        $commands = new CommandListeners($commandBuffer);
        $input = $this->fakeInput();

        $commands->onCommandStarting($this->commandEvent('suite:command-ok', $input));
        $commands->onCommandFinished($this->commandEvent('suite:command-ok', $input, 0));

        $this->assertCount(1, $commandBuffer->records);
    }

    private function taskBuffer(): ScheduledTaskBuffer
    {
        return new class extends ScheduledTaskBuffer {
            /** @var array<int, array<string, mixed>> */
            public $records = [];

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
        return new class extends CommandBuffer {
            /** @var array<int, array<string, mixed>> */
            public $records = [];

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
        return new class($summary, $expression) {
            /** @var string */
            public $expression;

            /** @var string */
            public $description;

            /** @var string|null */
            public $command = null;

            /** @var bool */
            public $withoutOverlapping = false;

            /** @var string */
            private $summary;

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
        return new class {
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
