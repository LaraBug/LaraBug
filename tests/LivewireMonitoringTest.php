<?php

namespace LaraBug\Tests;

use RuntimeException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use LaraBug\Requests\RequestMonitor;
use LaraBug\Livewire\LivewireContext;
use PHPUnit\Framework\Attributes\Test;
use LaraBug\Livewire\LivewireListeners;

class LivewireMonitoringTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['larabug.requests.track_requests' => true]);
    }

    #[Test]
    public function it_records_the_component_lifecycle_on_the_timeline()
    {
        [$monitor, $listeners] = $this->collector();

        $component = new FakeLivewireComponent();

        $mounted = $listeners->onMount($component);
        $listeners->onRender($component)();
        $listeners->onDehydrate($component);
        $mounted();

        $listeners->onHydrate($component);
        $listeners->onUpdate($component, 'quantity', 3);
        $listeners->onCall($component, 'increment', [])();
        $listeners->onDehydrate($component);

        // Ordered by completion rather than by start, the same as every other
        // stream on this record: a mount lands after the render nested inside
        // it, and start_ms is what puts them back in order.
        $record = $this->record($monitor);

        $this->assertSame(
            ['render', 'dehydrate', 'mount', 'hydrate', 'update', 'call', 'dehydrate'],
            array_column($record['livewire'], 'op')
        );

        $this->assertSame(7, $record['livewire_operations']);

        foreach ($record['livewire'] as $event) {
            $this->assertSame('cart', $event['component']);
            $this->assertSame(FakeLivewireComponent::class, $event['component_class']);
            $this->assertSame('cart-id', $event['component_id']);
        }

        // The method that was called and the property that changed: a name on
        // both, which is what the waterfall labels a bar with.
        $this->assertSame('quantity', $record['livewire'][4]['target']);
        $this->assertSame('increment', $record['livewire'][5]['target']);
    }

    /**
     * A component's own render() method runs before Livewire announces the
     * render, so no event spans it. The turn it belongs to has both ends
     * marked, which is what keeps that time accounted for.
     */
    #[Test]
    public function the_end_of_a_components_turn_is_marked()
    {
        [$monitor, $listeners] = $this->collector();

        $component = new FakeLivewireComponent();

        $listeners->onHydrate($component);
        usleep(5000);
        $listeners->onDehydrate($component);

        $events = $this->record($monitor)['livewire'];

        $this->assertSame(['hydrate', 'dehydrate'], array_column($events, 'op'));
        $this->assertGreaterThan(4.0, $events[1]['start_ms'] - $events[0]['start_ms']);
    }

    #[Test]
    public function a_component_render_is_timed_and_carries_its_offset()
    {
        [$monitor, $listeners] = $this->collector();

        // Real distance between the request starting and the component
        // rendering, so an offset of zero cannot pass by accident.
        usleep(20000);

        $finish = $listeners->onRender(new FakeLivewireComponent());
        usleep(5000);
        $finish();

        $event = $this->record($monitor)['livewire'][0];

        $this->assertGreaterThan(4.0, $event['duration_ms']);
        // Recorded on completion like every other event, so it began however
        // long it took ago.
        $this->assertGreaterThan(15.0, $event['start_ms']);
    }

    #[Test]
    public function a_mount_that_never_finished_records_nothing_but_still_names_the_component_that_threw()
    {
        [$monitor, $listeners, $context] = $this->collector();

        // A component that threw while rendering never reaches its finisher.
        $listeners->onRender(new FakeLivewireComponent());

        $this->assertSame([], $this->record($monitor)['livewire']);

        $this->assertSame('cart', $context->toArray()['component']);
        $this->assertSame('', $context->toArray()['method']);
    }

    #[Test]
    public function the_request_record_names_the_component_the_update_was_addressed_to()
    {
        [$monitor, $listeners] = $this->collector();

        $listeners->onCall(new FakeLivewireComponent(), 'checkout', []);

        $record = $this->record($monitor);

        // Every Livewire update in an application hits the same endpoint, so
        // the route groups them all into one row. This is what tells them apart.
        $this->assertSame('cart', $record['livewire_component']);
        $this->assertSame('checkout', $record['livewire_method']);
    }

    #[Test]
    public function a_request_that_never_touched_livewire_carries_no_component()
    {
        $record = $this->record(new RequestMonitor());

        $this->assertSame('', $record['livewire_component']);
        $this->assertSame('', $record['livewire_method']);
        $this->assertSame([], $record['livewire']);
        $this->assertSame(0, $record['livewire_operations']);
    }

    #[Test]
    public function the_livewire_buffer_is_capped_while_the_counter_keeps_counting()
    {
        config(['larabug.requests.max_livewire_events' => 2]);

        [$monitor, $listeners] = $this->collector();

        for ($i = 0; $i < 5; $i++) {
            $listeners->onHydrate(new FakeLivewireComponent());
        }

        $record = $this->record($monitor);

        $this->assertSame(5, $record['livewire_operations']);
        $this->assertCount(2, $record['livewire']);
    }

    #[Test]
    public function the_timeline_carries_no_component_values_at_all()
    {
        [$monitor, $listeners] = $this->collector();

        $listeners->onUpdate(new FakeLivewireComponent(), 'note', 'meet me at midnight');
        $listeners->onCall(new FakeLivewireComponent(), 'save', ['meet me at midnight'])();

        $this->assertStringNotContainsString(
            'midnight',
            (string) json_encode($this->record($monitor)['livewire'])
        );
    }

    #[Test]
    public function an_exception_carries_the_method_that_was_called_and_its_arguments()
    {
        [, $listeners, $context] = $this->collector();

        $listeners->onCall(new FakeLivewireComponent(), 'save', ['SUITE-1001', 'hunter2']);

        $livewire = $context->toArray();

        $this->assertSame('cart', $livewire['component']);
        $this->assertSame(FakeLivewireComponent::class, $livewire['component_class']);
        $this->assertSame('cart-id', $livewire['component_id']);
        $this->assertSame(__FILE__, $livewire['component_file']);

        $this->assertSame('save', $livewire['method']);
        // Livewire posts arguments as a positional list. Named from the
        // method's signature, which is the only thing the blacklist can match.
        $this->assertSame(['reference' => 'SUITE-1001', 'password' => '***'], $livewire['parameters']);

        $this->assertSame([['method' => 'save', 'parameters' => $livewire['parameters']]], $livewire['calls']);
    }

    #[Test]
    public function component_state_is_scrubbed_before_it_reaches_the_payload()
    {
        [, $listeners, $context] = $this->collector();

        $component = new FakeLivewireComponent();

        $listeners->onUpdate($component, 'form.email', 'alice@example.com');
        $listeners->onUpdate($component, 'form.password', 'hunter2');
        $listeners->onUpdate($component, 'api_token', 'sk-live-1234');
        $listeners->onUpdate($component, 'quantity', 3);

        $updates = $context->toArray()['updates'];

        // Matched against the property path, so a blacklisted property is
        // caught wherever in the component it lives.
        $this->assertSame('***', $updates['form.email']);
        $this->assertSame('***', $updates['form.password']);
        $this->assertSame('***', $updates['api_token']);

        $this->assertSame(3, $updates['quantity']);
    }

    #[Test]
    public function an_argument_that_cannot_be_named_is_still_never_serialised_whole()
    {
        [, $listeners, $context] = $this->collector();

        $listeners->onCall(new FakeLivewireComponent(), 'save', [
            'SUITE-1001',
            'hunter2',
            new FakeLivewireComponent(),
            UploadedFile::fake()->create('passport.pdf'),
        ]);

        $parameters = $context->toArray()['parameters'];

        // Past the signature there are no names left, so the value is reported
        // as what it was and never walked into.
        $this->assertSame('[object '.FakeLivewireComponent::class.']', $parameters['arg2']);
        $this->assertSame('...', $parameters['arg3']);
    }

    #[Test]
    public function a_captured_value_is_bounded()
    {
        config(['larabug.livewire.max_value_length' => 16]);

        [, $listeners, $context] = $this->collector();

        $listeners->onUpdate(new FakeLivewireComponent(), 'note', str_repeat('a', 5000));

        $this->assertSame(str_repeat('a', 16), $context->toArray()['updates']['note']);
    }

    #[Test]
    public function arguments_and_updates_can_be_dropped_entirely()
    {
        config([
            'larabug.livewire.capture_parameters' => false,
            'larabug.livewire.capture_updates' => false,
        ]);

        [, $listeners, $context] = $this->collector();

        $component = new FakeLivewireComponent();

        $listeners->onCall($component, 'save', ['SUITE-1001', 'hunter2']);
        $listeners->onUpdate($component, 'note', 'meet me at midnight');

        $livewire = $context->toArray();

        $this->assertSame('save', $livewire['method']);
        $this->assertSame([], $livewire['parameters']);
        $this->assertSame(['note' => '[not captured]'], $livewire['updates']);
    }

    #[Test]
    public function an_exception_report_carries_the_component_it_happened_in()
    {
        $this->app->make(LivewireContext::class)->recordCall(new FakeLivewireComponent(), 'checkout', ['SUITE-1001']);

        $data = $this->app['larabug']->getExceptionData(new RuntimeException('Card declined'));

        $this->assertSame('cart', $data['livewire']['component']);
        $this->assertSame('checkout', $data['livewire']['method']);
        $this->assertSame(['reference' => 'SUITE-1001'], $data['livewire']['parameters']);
    }

    #[Test]
    public function an_exception_outside_livewire_carries_no_livewire_block()
    {
        $data = $this->app['larabug']->getExceptionData(new RuntimeException('Card declined'));

        $this->assertArrayNotHasKey('livewire', $data);
    }

    #[Test]
    public function nothing_is_collected_when_livewire_monitoring_is_switched_off()
    {
        config(['larabug.livewire.track_livewire' => false]);

        $this->app->make(LivewireContext::class)->recordCall(new FakeLivewireComponent(), 'checkout', []);

        $this->assertArrayNotHasKey(
            'livewire',
            $this->app['larabug']->getExceptionData(new RuntimeException('Card declined'))
        );
    }

    #[Test]
    public function the_request_record_is_left_alone_when_request_monitoring_is_off()
    {
        config(['larabug.requests.track_requests' => false]);

        [$monitor, $listeners, $context] = $this->collector();

        $listeners->onCall(new FakeLivewireComponent(), 'checkout', ['SUITE-1001'])();

        $record = $this->record($monitor);

        $this->assertSame([], $record['livewire']);
        $this->assertSame('', $record['livewire_component']);

        // The exception report is a separate switch and still has its context.
        $this->assertSame('checkout', $context->toArray()['method']);
    }

    /**
     * Livewire is optional, and most applications that use this package do not
     * have it. Nothing may be registered, and nothing may be reached for.
     */
    #[Test]
    public function an_application_without_livewire_registers_nothing_and_reports_nothing()
    {
        $this->assertFalse($this->app->bound('livewire'));
        $this->assertFalse(class_exists(\Livewire\LivewireManager::class));

        $record = $this->record($this->app->make(RequestMonitor::class));

        $this->assertSame([], $record['livewire']);
        $this->assertSame('', $record['livewire_component']);

        $this->assertArrayNotHasKey(
            'livewire',
            $this->app['larabug']->getExceptionData(new RuntimeException('Nothing to do with Livewire'))
        );
    }

    #[Test]
    public function it_subscribes_to_the_lifecycle_livewire_announces()
    {
        [, $listeners] = $this->collector();

        $manager = new FakeLivewireManager();

        $listeners->subscribe($manager);

        $this->assertSame(
            ['mount', 'hydrate', 'update', 'call', 'render', 'dehydrate'],
            array_keys($manager->listeners)
        );
    }

    #[Test]
    public function a_livewire_binding_that_cannot_be_listened_to_is_left_alone()
    {
        [, $listeners] = $this->collector();

        $listeners->subscribe(new \stdClass());

        $this->assertTrue(true);
    }

    /**
     * A listener that threw would surface inside a customer's component, so
     * every one of them swallows.
     */
    #[Test]
    public function a_component_that_cannot_be_described_never_breaks_the_request()
    {
        [$monitor, $listeners] = $this->collector();

        $hostile = new class () {
            public function getName(): string
            {
                throw new RuntimeException('No name for you');
            }

            public function getId(): string
            {
                throw new RuntimeException('No id either');
            }
        };

        $listeners->onHydrate($hostile);
        $listeners->onUpdate($hostile, 'note', 'x');
        $finish = $listeners->onCall($hostile, 'save', ['x']);

        if ($finish !== null) {
            $finish();
        }

        $this->assertSame('', $this->record($monitor)['livewire'][0]['component']);
    }

    /**
     * @return array{0: RequestMonitor, 1: LivewireListeners, 2: LivewireContext}
     */
    private function collector(): array
    {
        $monitor = new RequestMonitor();
        $context = new LivewireContext();

        return [$monitor, new LivewireListeners($monitor, $context), $context];
    }

    /**
     * @return array<string, mixed>
     */
    private function record(RequestMonitor $monitor): array
    {
        return $monitor->toArray(
            Request::create('/livewire/update', 'POST'),
            new Response('', 200),
            1.0
        );
    }
}

/**
 * A stand-in for a Livewire component: the collector duck-types getName() and
 * getId(), and reads the signature of the method that was called, so a plain
 * class with those is the whole of what it touches. Named rather than
 * anonymous, so there is a stable class to assert against.
 */
class FakeLivewireComponent
{
    public function getName(): string
    {
        return 'cart';
    }

    public function getId(): string
    {
        return 'cart-id';
    }

    public function save(string $reference, string $password): void
    {
    }

    public function increment(): void
    {
    }

    public function checkout(string $reference = ''): void
    {
    }
}

/**
 * A stand-in for Livewire's manager: the collector asks whether it can be
 * listened to and then registers, which is all of the surface it uses.
 */
class FakeLivewireManager
{
    /** @var array<string, callable> */
    public array $listeners = [];

    public function listen(string $event, callable $callback): void
    {
        $this->listeners[$event] = $callback;
    }
}
