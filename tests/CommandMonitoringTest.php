<?php

namespace LaraBug\Tests;

use LaraBug\Console\CommandBuffer;
use LaraBug\Console\CommandListeners;

class CommandMonitoringTest extends TestCase
{
    /** @test */
    public function it_records_a_finished_command_with_its_exit_code_and_arguments()
    {
        config(['larabug.commands.redact' => ['password']]);

        $buffer = $this->recordingBuffer();
        $listeners = new CommandListeners($buffer);

        $input = $this->fakeInput(['command' => 'migrate', 'connection' => 'mysql'], ['force' => true, 'password' => 'hunter2']);

        $listeners->onCommandStarting($this->startingEvent('migrate', $input));
        $listeners->onCommandFinished($this->finishedEvent('migrate', 0, $input));

        $this->assertCount(1, $buffer->records);

        $record = $buffer->records[0];
        $this->assertSame('migrate', $record['command']);
        $this->assertSame(0, $record['exit_code']);
        // A command is its own execution, so it has a trace of its own.
        $this->assertNotSame('', $record['trace_id']);

        $arguments = json_decode($record['arguments'], true);
        // The command name is the record's own column, not an argument.
        $this->assertArrayNotHasKey('command', $arguments['arguments']);
        $this->assertSame('mysql', $arguments['arguments']['connection']);
        // The password option is replaced with a marker.
        $this->assertSame('[redacted]', $arguments['options']['password']);
        $this->assertTrue($arguments['options']['force']);
    }

    /** @test */
    public function it_does_not_record_an_ignored_command()
    {
        config(['larabug.commands.ignore' => ['queue:work', 'horizon:*']]);

        $buffer = $this->recordingBuffer();
        $listeners = new CommandListeners($buffer);
        $input = $this->fakeInput([], []);

        $listeners->onCommandStarting($this->startingEvent('horizon:work', $input));
        $listeners->onCommandFinished($this->finishedEvent('horizon:work', 0, $input));

        $this->assertCount(0, $buffer->records);
    }

    /** @test */
    public function a_failing_command_carries_its_non_zero_exit_code()
    {
        $buffer = $this->recordingBuffer();
        $listeners = new CommandListeners($buffer);
        $input = $this->fakeInput([], []);

        $listeners->onCommandStarting($this->startingEvent('backup:run', $input));
        $listeners->onCommandFinished($this->finishedEvent('backup:run', 1, $input));

        $this->assertSame(1, $buffer->records[0]['exit_code']);
    }

    /** @test */
    public function nested_commands_each_get_their_own_record()
    {
        $buffer = $this->recordingBuffer();
        $listeners = new CommandListeners($buffer);
        $input = $this->fakeInput([], []);

        // An outer command calls an inner one: starting, starting, finished,
        // finished. Each finish must match the start it belongs to.
        $listeners->onCommandStarting($this->startingEvent('app:outer', $input));
        $listeners->onCommandStarting($this->startingEvent('app:inner', $input));
        $listeners->onCommandFinished($this->finishedEvent('app:inner', 0, $input));
        $listeners->onCommandFinished($this->finishedEvent('app:outer', 0, $input));

        $this->assertSame(['app:inner', 'app:outer'], array_column($buffer->records, 'command'));
    }

    private function recordingBuffer(): CommandBuffer
    {
        // A buffer that records rather than sends. It skips the parent
        // constructor, so no shutdown flush is registered and no client is
        // needed for a test that only cares what was buffered.
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

    private function startingEvent(string $command, object $input): object
    {
        $event = new \stdClass();
        $event->command = $command;
        $event->input = $input;

        return $event;
    }

    private function finishedEvent(string $command, int $exitCode, object $input): object
    {
        $event = new \stdClass();
        $event->command = $command;
        $event->exitCode = $exitCode;
        $event->input = $input;

        return $event;
    }

    /**
     * A stand-in for the console input: the handler reads only getArguments and
     * getOptions, so a plain object with those is enough.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $options
     */
    private function fakeInput(array $arguments, array $options): object
    {
        return new class($arguments, $options) {
            /** @var array<string, mixed> */
            private $arguments;

            /** @var array<string, mixed> */
            private $options;

            public function __construct(array $arguments, array $options)
            {
                $this->arguments = $arguments;
                $this->options = $options;
            }

            /** @return array<string, mixed> */
            public function getArguments(): array
            {
                return $this->arguments;
            }

            /** @return array<string, mixed> */
            public function getOptions(): array
            {
                return $this->options;
            }
        };
    }
}
