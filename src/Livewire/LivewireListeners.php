<?php

namespace LaraBug\Livewire;

use Closure;
use Throwable;
use LaraBug\Requests\RequestMonitor;

/**
 * The Livewire lifecycle, as events on the request timeline.
 *
 * Livewire announces its own lifecycle on its own event bus, so nothing here
 * wraps or decorates anything: mounting, hydrating, updating, calling and
 * rendering are all things Livewire already says out loud, and this listens.
 *
 * A component's work happens inside the request, beside the queries and the
 * cache calls it makes, so it is recorded the same way they are — one event
 * with a duration and an offset from the start of the request — rather than as
 * an eighth stage. The stages are a fixed partition of the request and a
 * component render is not a slice of one; on an update it lives inside the
 * action, on a full page load inside the render.
 *
 * Every handler is wrapped. These fire in the middle of the customer's own
 * component code, and a listener that threw would surface as an error in
 * whatever component happened to be rendering.
 */
class LivewireListeners
{
    public function __construct(
        protected readonly RequestMonitor $monitor,
        protected readonly LivewireContext $context,
    ) {
    }

    /**
     * Livewire's manager, duck-typed. This package does not depend on
     * Livewire, so the manager arrives as whatever the container has under
     * 'livewire' and is asked whether it can be listened to.
     */
    public function subscribe(object $livewire): void
    {
        if (! method_exists($livewire, 'listen')) {
            return;
        }

        $livewire->listen('mount', $this->onMount(...));
        $livewire->listen('hydrate', $this->onHydrate(...));
        $livewire->listen('update', $this->onUpdate(...));
        $livewire->listen('call', $this->onCall(...));
        $livewire->listen('render', $this->onRender(...));
        $livewire->listen('dehydrate', $this->onDehydrate(...));
    }

    /**
     * A component being mounted for the first time.
     *
     * Livewire calls this hook's finisher once the component has mounted,
     * rendered and dehydrated, so the duration is the whole of a component
     * appearing on the page, and it contains the render event recorded inside
     * it. Nested on purpose: a parent's mount covers its children's, which is
     * the shape a waterfall wants.
     *
     * The mount parameters are not collected. They are whatever the blade tag
     * passed in, which is routinely a model, and the update hooks already say
     * what the client actually sent.
     */
    public function onMount(object $component): ?Closure
    {
        return $this->span($component, 'mount', '');
    }

    /**
     * A component being rebuilt from the snapshot the browser posted back.
     *
     * Recorded where it happened rather than how long it took: Livewire runs
     * no finisher for this hook, and a duration invented from the next event
     * would be a guess presented as a measurement.
     */
    public function onHydrate(object $component): void
    {
        $this->guard(function () use ($component) {
            $this->record($this->context->identify($component), 'hydrate', '', 0.0);
        });
    }

    /**
     * One property the client changed.
     *
     * A point event for the same reason hydrate is: Livewire defers every
     * update's finisher until all of them have run, so the only duration on
     * offer is the batch's, which would be reported identically against each
     * property and be wrong for all of them.
     */
    public function onUpdate(object $component, mixed $path = '', mixed $value = null): void
    {
        $this->guard(function () use ($component, $path, $value) {
            $property = is_string($path) ? $path : '';

            $this->context->recordUpdate($component, $property, $value);

            $this->record($this->context->identify($component), 'update', $property, 0.0);

            $this->tellTheRequestItsLivewire();
        });
    }

    /**
     * A method the client asked the component to run.
     *
     * The one event on this timeline with a duration that is exactly what it
     * claims: Livewire's finisher runs on the far side of the method call and
     * nothing else.
     */
    public function onCall(object $component, mixed $method = '', mixed $parameters = null): ?Closure
    {
        $finish = null;

        $this->guard(function () use ($component, $method, $parameters, &$finish) {
            $name = is_string($method) ? $method : '';

            $this->context->recordCall($component, $name, is_array($parameters) ? $parameters : []);

            $this->tellTheRequestItsLivewire();

            $finish = $this->span($component, 'call', $name);
        });

        return $finish;
    }

    /**
     * A component rendering its view. Fires on a full page load and on an
     * update alike, which is what makes "this endpoint spends 300ms rendering
     * one component" visible at all.
     *
     * The view, precisely: Livewire calls a component's own render() method to
     * get the view and only then announces the render, so the method itself
     * falls outside this span. Timing it from here anyway would report a
     * component that runs twelve queries in render() as rendering instantly.
     */
    public function onRender(object $component): ?Closure
    {
        return $this->span($component, 'render', '');
    }

    /**
     * A component finishing its turn and packing itself back into a snapshot.
     *
     * Here because of a gap Livewire's hooks leave. A component's own render()
     * method runs before the render hook fires, so the render event below times
     * the blade view and not the method that built it — which is where a
     * component's queries usually live. Marking the end of the turn hands the
     * panel the interval between hydrate and here, so the time no single event
     * accounts for is at least bounded by two that do.
     */
    public function onDehydrate(object $component): void
    {
        $this->guard(function () use ($component) {
            $this->record($this->context->identify($component), 'dehydrate', '', 0.0);
        });
    }

    /**
     * Open an event, and hand Livewire back the closure that closes it.
     *
     * Livewire treats a listener's return value as a finisher and calls it with
     * whatever the phase produced — the rendered html, the method's return
     * value — keeping it only if the finisher returns something of its own.
     * This one returns nothing, deliberately: an observer that rewrote a
     * component's html would be a defect, not a feature.
     */
    protected function span(object $component, string $op, string $target): ?Closure
    {
        $identity = null;
        $startedAt = microtime(true);

        $this->guard(function () use ($component, &$identity) {
            $identity = $this->context->enter($component);
        });

        if ($identity === null) {
            return null;
        }

        return function () use ($identity, $op, $target, $startedAt): void {
            $this->guard(function () use ($identity, $op, $target, $startedAt) {
                $this->context->leave();

                $this->record($identity, $op, $target, round((microtime(true) - $startedAt) * 1000, 3));
            });
        };
    }

    /**
     * @param  array<string, string>  $identity
     */
    protected function record(array $identity, string $op, string $target, float $durationMs): void
    {
        // The timeline belongs to the request record, so it follows the request
        // record's switch. The context does not: an exception report carries
        // its component whether or not this application records requests.
        if (! config('larabug.requests.track_requests', false)) {
            return;
        }

        $this->monitor->recordLivewireEvent($identity + [
            'op' => $op,
            // The method that was called or the property that changed. A name
            // either way, never a value: the request record carries no
            // component data at all, which is what keeps it cheap to keep for
            // every sampled request rather than only for the failed ones.
            'target' => mb_substr($target, 0, 255),
            'duration_ms' => $durationMs,
        ]);
    }

    /**
     * Stamp the component the update addressed onto the request record.
     *
     * Every Livewire update in an application hits the same endpoint, so the
     * route on these records is the same row for all of them. This is the
     * field that tells them apart.
     */
    protected function tellTheRequestItsLivewire(): void
    {
        if (! config('larabug.requests.track_requests', false)) {
            return;
        }

        $this->monitor->setLivewireSubject(
            $this->context->targetedComponent(),
            $this->context->targetedMethod(),
        );
    }

    protected function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            // Never let instrumentation surface inside a customer's component.
        }
    }
}
