<?php

namespace LaraBug;

use Throwable;
use LaraBug\Http\Client;
use LaraBug\Filters\DataFilter;
use LaraBug\Concerns\Larabugable;
use LaraBug\Requests\TraceContext;
use Illuminate\Support\Facades\App;
use LaraBug\Requests\RequestMonitor;
use Illuminate\Support\Facades\Cache;
use Illuminate\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Psr\Http\Message\ResponseInterface;

class LaraBug
{
    private readonly Client $client;

    private readonly DataFilter $dataFilter;

    private ?string $lastExceptionId = null;

    /** @var array<string, mixed> */
    private static array $customContext = [];

    /**
     * Re-entry guard. Set while handle() is executing so any exception
     * thrown *inside* the capture path (HTTP errors, serialization, etc.)
     * can't recursively trigger another capture and blow the stack.
     */
    private static bool $capturing = false;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->dataFilter = new DataFilter(config('larabug.blacklist', []));
    }

    /**
     * Set custom context data that will be sent with the next exception.
     *
     * @param array<string, mixed> $context
     */
    public static function context(array $context): void
    {
        self::$customContext = array_merge(self::$customContext, $context);
    }

    public static function clearContext(): void
    {
        self::$customContext = [];
    }

    public function handle(Throwable $exception, string $fileType = 'php', array $customData = []): mixed
    {
        // Drop any capture that re-enters while another capture is still in flight.
        // Without this, an error thrown inside logError() would be caught by
        // Laravel's handler and fed back into handle(), looping forever.
        if (self::$capturing) {
            return false;
        }

        self::$capturing = true;

        try {
            if ($this->isSkipEnvironment()) {
                return false;
            }

            $data = $this->getExceptionData($exception);

            if ($this->isSkipException($data['class'])) {
                return false;
            }

            if ($this->isSleepingException($data)) {
                return false;
            }

            // The request being served, if there is one, learns that it threw.
            // Read from the container rather than injected: this class is
            // resolved in applications with request monitoring switched off,
            // where the monitor is never bound at all.
            $this->tellTheRequestMonitor();

            if ($fileType == 'javascript') {
                $data['fullUrl'] = $customData['url'];
                $data['file'] = $customData['file'];
                $data['file_type'] = $fileType;
                $data['error'] = $customData['message'];
                $data['exception'] = $customData['stack'];
                $data['line'] = $customData['line'];
                $data['class'] = null;

                $count = config('larabug.lines_count');

                if ($count > 50) {
                    $count = 12;
                }

                $lines = file($data['file']);
                $data['executor'] = [];

                for ($i = -1 * abs($count); $i <= abs($count); $i++) {
                    $currentLine = $data['line'] + $i;

                    $index = $currentLine - 1;

                    if (! array_key_exists($index, $lines)) {
                        continue;
                    }

                    $data['executor'][] = [
                        'line_number' => $currentLine,
                        'line' => $lines[$index],
                    ];
                }

                $data['executor'] = array_filter($data['executor']);

                // The frames collected above walked the PHP wrapper's trace,
                // which says nothing about where the JavaScript error was.
                $data['frames'] = [];
            }

            $rawResponse = $this->logError($data);

            if (! $rawResponse) {
                return false;
            }

            $response = json_decode($rawResponse->getBody()->getContents());

            if (isset($response->id)) {
                $this->setLastExceptionId($response->id);
            }

            if (config('larabug.sleep') !== 0) {
                $this->addExceptionToSleep($data);
            }

            return $response;
        } catch (Throwable $inner) {
            // Anything that blows up inside capture must NOT re-enter Laravel's
            // exception handler — that would loop back into this method. Swallow
            // to stderr and let the outer process keep going.
            error_log('[LaraBug] capture failed: '.$inner->getMessage());

            return false;
        } finally {
            self::$capturing = false;
        }
    }

    /**
     * @internal Used by tests and the queue tracking path to check whether
     * a capture is currently in flight.
     */
    public static function isCapturing(): bool
    {
        return self::$capturing;
    }

    public function isSkipEnvironment(): bool
    {
        if (count(config('larabug.environments', [])) == 0) {
            return true;
        }

        if (in_array(App::environment(), config('larabug.environments', []))) {
            return false;
        }

        return true;
    }

    private function setLastExceptionId(?string $id): void
    {
        $this->lastExceptionId = $id;
    }

    /**
     * Get the last exception id given to us by the larabug API.
     */
    public function getLastExceptionId(): ?string
    {
        return $this->lastExceptionId;
    }

    /**
     * Count this exception against the request that caused it, and stamp the
     * shared trace id onto the report.
     *
     * Wrapped whole: an application with request monitoring off has no monitor
     * bound, and a failure to record a count must never stop an exception being
     * reported.
     */
    protected function tellTheRequestMonitor(): void
    {
        try {
            if (! config('larabug.requests.track_requests', false)) {
                return;
            }

            app(RequestMonitor::class)->recordException();
        } catch (Throwable) {
            //
        }
    }

    /** @return array<string, mixed> */
    public function getExceptionData(Throwable $exception): array
    {
        $data = [];

        $data['environment'] = App::environment();
        $data['host'] = Request::server('SERVER_NAME');
        $data['method'] = Request::method();
        $data['fullUrl'] = Request::fullUrl();
        $data['exception'] = $exception->getMessage() ?? '-';
        $data['error'] = $exception->getTraceAsString();
        $data['line'] = $exception->getLine();
        $data['file'] = $exception->getFile();
        $data['class'] = $exception::class;
        $data['release'] = config('larabug.release', null);
        $data['storage'] = [
            'SERVER' => [
                'USER' => Request::server('USER'),
                'HTTP_USER_AGENT' => Request::server('HTTP_USER_AGENT'),
                'SERVER_PROTOCOL' => Request::server('SERVER_PROTOCOL'),
                'SERVER_SOFTWARE' => Request::server('SERVER_SOFTWARE'),
                'PHP_VERSION' => PHP_VERSION,
            ],
            'OLD' => $this->filterVariables(Request::hasSession() ? Request::old() : []),
            'COOKIE' => $this->filterVariables(Request::cookie()),
            'SESSION' => $this->filterVariables(Request::hasSession() ? Session::all() : []),
            'HEADERS' => $this->filterVariables(Request::header()),
            'PARAMETERS' => $this->filterVariables($this->filterParameterValues(Request::all())),
        ];

        $data['storage'] = array_filter($data['storage']);

        $count = config('larabug.lines_count');

        if ($count > 50) {
            $count = 12;
        }

        $lines = @file($data['file']);
        $data['executor'] = [];

        if ($lines !== false && count($lines) < $count) {
            $count = count($lines) - $data['line'];
        }

        if ($lines !== false) {
            for ($i = -1 * abs($count); $i <= abs($count); $i++) {
                $data['executor'][] = $this->getLineInfo($lines, $data['line'], $i);
            }
            $data['executor'] = array_filter($data['executor']);
        }

        $data['frames'] = $this->getExceptionFrames($exception);

        $data['project_version'] = config('larabug.project_version', null);

        // An exception thrown while serving a tracked request carries that
        // request's id (the middleware set it before routing), which is what
        // lets the server join the failed request to the issue it caused.
        // Outside a tracked request this is a fresh id no request shares.
        $data['trace_id'] = TraceContext::id();

        if (! empty(self::$customContext)) {
            $data['custom_data'] = self::$customContext;
            self::$customContext = [];
        }

        // to make symfony exception more readable
        if ($data['class'] == 'Symfony\Component\Debug\Exception\FatalErrorException') {
            preg_match("~^(.+)' in ~", $data['exception'], $matches);
            if (isset($matches[1])) {
                $data['exception'] = $matches[1];
            }
        }

        return $data;
    }

    public function filterParameterValues(array $parameters): array
    {
        return $this->dataFilter->filterParameterValues($parameters);
    }

    public function shouldParameterValueBeFiltered(mixed $value): bool
    {
        return $this->dataFilter->shouldParameterValueBeFiltered($value);
    }

    public function filterVariables(mixed $variables): array
    {
        return $this->dataFilter->filterVariables($variables);
    }

    /**
     * The stack as structured frames, each with a window of source around its
     * line, the way Sentry and Flare capture one. The throw site leads, since
     * getTrace() does not include it.
     *
     * Bounded twice: frame_lines_count lines of source either side of a
     * frame's line, and source for at most max_code_frames frames. Deeper
     * frames keep their file, line and function so the trace stays whole.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getExceptionFrames(Throwable $exception): array
    {
        $lineCount = (int) config('larabug.frame_lines_count', 5);

        if ($lineCount > 25) {
            $lineCount = 5;
        }

        $maxCodeFrames = (int) config('larabug.max_code_frames', 20);

        $frames = [[
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'function' => null,
            'class' => null,
            'type' => null,
        ]];

        foreach ($exception->getTrace() as $trace) {
            $frames[] = [
                'file' => $trace['file'] ?? null,
                'line' => $trace['line'] ?? null,
                'function' => $trace['function'] ?? null,
                'class' => $trace['class'] ?? null,
                'type' => $trace['type'] ?? null,
            ];

            // A runaway recursion dies hundreds of frames deep; past this
            // point the frames say nothing the first two hundred did not.
            if (count($frames) >= 200) {
                break;
            }
        }

        // Files repeat across frames constantly, so each is read once.
        $fileCache = [];
        $framesWithCode = 0;

        foreach ($frames as $index => $frame) {
            $frames[$index]['code'] = [];

            if ($framesWithCode >= $maxCodeFrames || ! $frame['file'] || ! $frame['line']) {
                continue;
            }

            if (! array_key_exists($frame['file'], $fileCache)) {
                $fileCache[$frame['file']] = @file($frame['file']);
            }

            $lines = $fileCache[$frame['file']];

            if ($lines === false) {
                continue;
            }

            $code = [];

            for ($i = -1 * abs($lineCount); $i <= abs($lineCount); $i++) {
                $code[] = $this->getLineInfo($lines, $frame['line'], $i);
            }

            // Reindexed, or the filtered gaps make json_encode emit an
            // object where the server expects a list.
            $frames[$index]['code'] = array_values(array_filter($code));

            if (count($frames[$index]['code'])) {
                $framesWithCode++;
            }
        }

        return $frames;
    }

    private function getLineInfo(array $lines, int $line, int $i): ?array
    {
        $currentLine = $line + $i;

        $index = $currentLine - 1;

        if (! array_key_exists($index, $lines)) {
            return null;
        }

        return [
            'line_number' => $currentLine,
            'line' => $lines[$index],
        ];
    }

    public function isSkipException(mixed $exceptionClass): bool
    {
        return in_array($exceptionClass, config('larabug.except'));
    }

    public function isSleepingException(array $data): bool
    {
        if (config('larabug.sleep', 0) == 0) {
            return false;
        }

        return Cache::has($this->createExceptionString($data));
    }

    private function createExceptionString(array $data): string
    {
        $string = "{$data['host']}_{$data['method']}_{$data['exception']}_{$data['line']}_{$data['file']}_{$data['class']}";

        // Hashed so the key never exceeds cache key length limits (255 chars for the database driver).
        return 'larabug.'.md5($string);
    }

    private function logError(array $exception): ?ResponseInterface
    {
        return $this->client->report([
            'exception' => $exception,
            'user' => $this->getUser(),
        ]);
    }

    public function getUser(): ?array
    {
        if (! function_exists('auth')) {
            return null;
        }

        if (! (app() instanceof Application)) {
            return null;
        }

        if (! auth()->check()) {
            return null;
        }

        $user = auth()->user();

        if ($user instanceof Larabugable) {
            return $user->toLarabug();
        }

        if ($user instanceof Model) {
            return $user->toArray();
        }

        return null;
    }

    public function addExceptionToSleep(array $data): bool
    {
        $exceptionString = $this->createExceptionString($data);

        return Cache::put($exceptionString, $exceptionString, config('larabug.sleep'));
    }
}
