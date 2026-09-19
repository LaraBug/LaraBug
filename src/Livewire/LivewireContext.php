<?php

namespace LaraBug\Livewire;

use WeakMap;
use Throwable;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Str;
use LaraBug\Filters\DataFilter;

/**
 * What the Livewire part of this execution was actually doing.
 *
 * A Livewire update is opaque from the outside. Every component in the
 * application posts to the same endpoint, so the route says nothing, and the
 * body is a snapshot blob that no exception report can be read against. The
 * diagnostic part is which component was addressed, which method it was asked
 * to run, and which properties the client changed — which is what this holds,
 * and all it holds.
 *
 * Scrubbed on the way in rather than on the way out. The blacklist runs at the
 * moment a value is collected, so an argument that must never leave the
 * application is never sitting in memory waiting for something downstream to
 * remember to filter it.
 *
 * Component state is not collected at all. Not scrubbed state, none: a
 * component's properties are the whole form the customer was filling in, and
 * the updates already say which of them changed.
 */
class LivewireContext
{
    /**
     * The component the update was addressed to. First one wins: Livewire
     * hands the root component to both the call and the update hooks, and a
     * later child cannot take the request's subject away from it.
     *
     * @var array<string, string>|null
     */
    protected ?array $targeted = null;

    /**
     * Components currently executing, innermost last. A mount or a render that
     * threw never reaches its finisher, so whatever is still on this stack when
     * an exception is reported is the component that was running when it threw.
     *
     * @var array<int, array<string, string>>
     */
    protected array $active = [];

    /** @var array<int, array{method: string, parameters: array<string, mixed>}> */
    protected array $calls = [];

    /** @var array<string, mixed> */
    protected array $updates = [];

    protected ?DataFilter $filter = null;

    /**
     * Identities already worked out, so reflecting a class is paid for once
     * per component rather than once per event: a page of components that each
     * hydrate, update, render and dehydrate asks this the same question over
     * and over. Weak, so holding the answer never keeps the component alive.
     *
     * @var WeakMap<object, array<string, string>>|null
     */
    protected ?WeakMap $identities = null;

    /**
     * Identity of a component that has begun executing.
     *
     * @return array<string, string>
     */
    public function enter(object $component): array
    {
        $identity = $this->identify($component);

        $this->active[] = $identity;

        return $identity;
    }

    public function leave(): void
    {
        array_pop($this->active);
    }

    /**
     * Record a method the client asked the component to run, with its
     * arguments named and scrubbed.
     *
     * @param  array<int|string, mixed>  $parameters
     */
    public function recordCall(object $component, string $method, array $parameters): void
    {
        $this->targeted ??= $this->identify($component);

        // A Livewire request carries a queue of calls and almost always
        // exactly one. The handful past this say nothing the first few did not.
        if (count($this->calls) >= 10) {
            return;
        }

        $this->calls[] = [
            'method' => $this->bounded($method),
            'parameters' => $this->parameters($component, $method, $parameters),
        ];
    }

    /**
     * Record one property the client changed, as the path it changed and the
     * value it changed to, scrubbed.
     */
    public function recordUpdate(object $component, string $path, mixed $value): void
    {
        $this->targeted ??= $this->identify($component);

        if (count($this->updates) >= 50) {
            return;
        }

        if (! config('larabug.livewire.capture_updates', true)) {
            $this->updates[$this->bounded($path)] = '[not captured]';

            return;
        }

        // Through the same filter a request parameter goes through, keyed by
        // the property path, so a blacklisted property is caught by its name
        // wherever in the component it lives.
        $filtered = $this->filter()->filterVariables([$path => $value]);

        $this->updates[$this->bounded($path)] = $this->bound($filtered[$path] ?? null);
    }

    /**
     * The block an exception report carries, empty when this execution had
     * nothing to do with Livewire.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $identity = $this->targeted ?? $this->innermostActive();

        if ($identity === null) {
            return [];
        }

        $primary = $this->calls[0] ?? ['method' => '', 'parameters' => []];

        return $identity + [
            // The first call, lifted out of the queue: a Livewire request runs
            // one method almost every time, and the card that matters shows it
            // without the panel having to reach into a list to find it.
            'method' => $primary['method'],
            'parameters' => $primary['parameters'],

            'calls' => $this->calls,
            'updates' => $this->updates,
        ];
    }

    /**
     * The component the panel should attribute this request to, empty when
     * there is none. A full page load mounts many components and singles out
     * none of them; an update addresses exactly one.
     */
    public function targetedComponent(): string
    {
        return (string) ($this->targeted['component'] ?? '');
    }

    public function targetedMethod(): string
    {
        return (string) ($this->calls[0]['method'] ?? '');
    }

    /**
     * @return array<string, string>|null
     */
    protected function innermostActive(): ?array
    {
        $last = array_key_last($this->active);

        return $last === null ? null : $this->active[$last];
    }

    /**
     * How a component is named in the payload. The same four fields appear on
     * the timeline events, so the panel can join a bar on the waterfall to the
     * component an issue is about.
     *
     * @return array<string, string>
     */
    public function identify(object $component): array
    {
        $this->identities ??= new WeakMap();

        if (isset($this->identities[$component])) {
            return $this->identities[$component];
        }

        return $this->identities[$component] = $this->describe($component);
    }

    /**
     * @return array<string, string>
     */
    protected function describe(object $component): array
    {
        $class = get_class($component);
        $file = '';

        try {
            $reflection = new ReflectionClass($component);

            // An anonymous component's class name is its file path after a null
            // byte, which is not a name anything can be grouped by. The file is
            // kept separately, where it is useful rather than confusing.
            if ($reflection->isAnonymous()) {
                $class = 'anonymous';
            }

            $file = (string) $reflection->getFileName();
        } catch (Throwable) {
            //
        }

        return [
            'component' => $this->componentName($component),
            'component_class' => $this->bounded($class),
            'component_id' => $this->bounded($this->stringValue($component, 'getId')),
            'component_file' => $this->bounded($file),
        ];
    }

    private function componentName(object $component): string
    {
        return $this->bounded(Str::ascii($this->stringValue($component, 'getName')));
    }

    /**
     * Livewire's own components answer getName() and getId(). Duck-typed
     * rather than type-hinted, because this package does not depend on
     * Livewire and must not load its classes to find out.
     */
    private function stringValue(object $component, string $getter): string
    {
        if (! method_exists($component, $getter)) {
            return '';
        }

        try {
            return (string) $component->{$getter}();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * A called method's arguments, named from its signature and then filtered.
     *
     * The naming is the point. Livewire posts arguments as a positional list,
     * and a list cannot be scrubbed: the blacklist matches keys, so
     * login('me@example.com', 'hunter2') arrives as two anonymous strings with
     * nothing to match against. Reflecting the method turns them back into
     * 'email' and 'password', which the existing filter then catches.
     *
     * @param  array<int|string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function parameters(object $component, string $method, array $parameters): array
    {
        if (! config('larabug.livewire.capture_parameters', true)) {
            return [];
        }

        $named = $this->nameParameters($component, $method, $parameters);

        // filterParameters is the path a request's own parameters take:
        // uploaded files out first, then blacklisted keys at any depth.
        $filtered = $this->filter()->filterParameters($named);

        $bounded = [];

        foreach ($filtered as $key => $value) {
            $bounded[$this->bounded((string) $key)] = $this->bound($value);
        }

        return $bounded;
    }

    /**
     * @param  array<int|string, mixed>  $parameters
     * @return array<string, mixed>
     */
    protected function nameParameters(object $component, string $method, array $parameters): array
    {
        $names = [];

        try {
            if (method_exists($component, $method)) {
                foreach ((new ReflectionMethod($component, $method))->getParameters() as $parameter) {
                    $names[] = $parameter->getName();
                }
            }
        } catch (Throwable) {
            //
        }

        $named = [];
        $position = 0;

        foreach ($parameters as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;

                continue;
            }

            // Falls back to the position when the method has no signature to
            // read: a magic __call target, or a name the client invented. An
            // argument nobody can name is still bounded and still filtered,
            // it just has nothing for the blacklist to match on.
            $named[$names[$position] ?? 'arg'.$position] = $value;

            $position++;
        }

        return $named;
    }

    /**
     * Everything that reaches the payload, bounded.
     *
     * Two jobs. Nothing unbounded: a string is cut, an array is cut both wide
     * and deep, so a component holding a base64 upload or a thousand-row
     * collection cannot decide how large our payload is. And nothing
     * serialised: an object is reported as its class, never walked into, so a
     * model that survived the blacklist because its property was not named
     * after anything sensitive still cannot pour its attributes into the
     * report.
     */
    protected function bound(mixed $value, int $depth = 0): mixed
    {
        if (is_string($value)) {
            return $this->bounded($value);
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value)) {
            if ($depth >= 3) {
                return '[array]';
            }

            $bounded = [];
            $entries = 0;

            foreach ($value as $key => $entry) {
                if ($entries++ >= 50) {
                    $bounded['...'] = '[truncated]';

                    break;
                }

                $bounded[is_string($key) ? $this->bounded($key) : $key] = $this->bound($entry, $depth + 1);
            }

            return $bounded;
        }

        if (is_object($value)) {
            return '[object '.$this->bounded(get_class($value)).']';
        }

        return '['.gettype($value).']';
    }

    protected function bounded(string $value): string
    {
        return mb_substr($value, 0, $this->maxValueLength());
    }

    protected function maxValueLength(): int
    {
        $length = (int) config('larabug.livewire.max_value_length', 255);

        return $length > 0 ? $length : 255;
    }

    protected function filter(): DataFilter
    {
        return $this->filter ??= new DataFilter((array) config('larabug.blacklist', []));
    }
}
