<?php

namespace LaraBug;

use Throwable;
use LaraBug\Http\Client;
use LaraBug\Filters\DataFilter;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;

class LaraBug
{
    /** @var Client */
    private $client;

    /** @var DataFilter */
    private $dataFilter;

    /** @var null|string */
    private $lastExceptionId;

    /** @var array */
    private static $customContext = [];

    /**
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->dataFilter = new DataFilter(config('larabug.blacklist', []));
    }

    /**
     * Set custom context data that will be sent with the next exception
     * 
     * @param array $context
     * @return void
     */
    public static function context(array $context)
    {
        self::$customContext = array_merge(self::$customContext, $context);
    }

    /**
     * Clear custom context data
     * 
     * @return void
     */
    public static function clearContext()
    {
        self::$customContext = [];
    }

    /**
     * @param Throwable $exception
     * @param string $fileType
     * @return bool|mixed
     */
    public function handle(Throwable $exception, $fileType = 'php', array $customData = [])
    {
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

                if (!array_key_exists($index, $lines)) {
                    continue;
                }

                $data['executor'][] = [
                    'line_number' => $currentLine,
                    'line' => $lines[$index],
                ];
            }

            $data['executor'] = array_filter($data['executor']);
        }

        $rawResponse = $this->logError($data);

        if (!$rawResponse) {
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
    }

    /**
     * @return bool
     */
    public function isSkipEnvironment()
    {
        if (count(config('larabug.environments')) == 0) {
            return true;
        }

        if (in_array(App::environment(), config('larabug.environments'))) {
            return false;
        }

        return true;
    }

    /**
     * @param string|null $id
     */
    private function setLastExceptionId(?string $id)
    {
        $this->lastExceptionId = $id;
    }

    /**
     * Get the last exception id given to us by the larabug API.
     * @return string|null
     */
    public function getLastExceptionId()
    {
        return $this->lastExceptionId;
    }

    /**
     * @param Throwable $exception
     * @return array
     */
    public function getExceptionData(Throwable $exception)
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
        $data['class'] = get_class($exception);
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
            'PARAMETERS' => $this->filterVariables($this->filterParameterValues(Request::all()))
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

        // Get project version
        $data['project_version'] = config('larabug.project_version', null);

        // Add custom context data
        if (!empty(self::$customContext)) {
            $data['custom_data'] = self::$customContext;
            // Clear context after adding to exception
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

    /**
     * @param array $parameters
     * @return array
     */
    public function filterParameterValues($parameters)
    {
        return $this->dataFilter->filterParameterValues($parameters);
    }

    /**
     * Determines whether the given parameter value should be filtered.
     *
     * @param mixed $value
     * @return bool
     */
    public function shouldParameterValueBeFiltered($value)
    {
        return $this->dataFilter->shouldParameterValueBeFiltered($value);
    }

    /**
     * @param $variables
     * @return array
     */
    public function filterVariables($variables)
    {
        return $this->dataFilter->filterVariables($variables);
    }

    /**
     * Gets information from the line.
     *
     * @param $lines
     * @param $line
     * @param $i
     *
     * @return array|void
     */
    private function getLineInfo($lines, $line, $i)
    {
        $currentLine = $line + $i;

        $index = $currentLine - 1;

        if (!array_key_exists($index, $lines)) {
            return;
        }

        return [
            'line_number' => $currentLine,
            'line' => $lines[$index],
        ];
    }

    /**
     * @param $exceptionClass
     * @return bool
     */
    public function isSkipException($exceptionClass)
    {
        return in_array($exceptionClass, config('larabug.except'));
    }

    /**
     * @param array $data
     * @return bool
     */
    public function isSleepingException(array $data)
    {
        if (config('larabug.sleep', 0) == 0) {
            return false;
        }

        return Cache::has($this->createExceptionString($data));
    }

    /**
     * @param array $data
     * @return string
     */
    private function createExceptionString(array $data)
    {
        $string = $data['host'] . '_' . $data['method'] . '_' . $data['exception'] . '_' . $data['line'] . '_' . $data['file'] . '_' . $data['class'];
        
        // Hash the string to ensure it never exceeds cache key length limits (255 chars for database driver)
        return 'larabug.' . md5($string);
    }

    /**
     * @param $exception
     * @return \GuzzleHttp\Promise\PromiseInterface|\Psr\Http\Message\ResponseInterface|null
     */
    private function logError($exception)
    {
        return $this->client->report([
            'exception' => $exception,
            'user' => $this->getUser(),
        ]);
    }

    /**
     * @return array|null
     */
    public function getUser()
    {
        if (function_exists('auth') && (app() instanceof \Illuminate\Foundation\Application && auth()->check())) {
            /** @var \Illuminate\Contracts\Auth\Authenticatable $user */
            $user = auth()->user();

            if ($user instanceof \LaraBug\Concerns\Larabugable) {
                return $user->toLarabug();
            }

            if ($user instanceof \Illuminate\Database\Eloquent\Model) {
                return $user->toArray();
            }
        }

        return null;
    }

    /**
     * @param array $data
     * @return bool
     */
    public function addExceptionToSleep(array $data)
    {
        $exceptionString = $this->createExceptionString($data);

        return Cache::put($exceptionString, $exceptionString, config('larabug.sleep'));
    }
}
