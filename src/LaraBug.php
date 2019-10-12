<?php

namespace LaraBug;

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use LaraBug\Http\Client;
use Throwable;

class LaraBug
{
    /** @var Client */
    private $client;

    /**
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * @return bool|Factory|\Illuminate\View\View
     */
    public function errorView()
    {
        if (View::exists($errorView = config('larabug.errorView'))) {
            return view($errorView);
        }

        return false;
    }

    /**
     * @param Throwable $exception
     */
    public function handle(Throwable $exception)
    {
        if ($this->isSkipEnvironment()) {
            return;
        }

        $data = $this->getExceptionData($exception);

        if ($this->isSkipException($data['class'])) {
            return;
        }

        if ($this->isSleepingException($data)) {
            return;
        }

        $this->logError($data);

        if (config('larabug.sleep') !== 0) {
            $this->addExceptionToSleep($data);
        }
    }

    /**
     * @return bool
     */
    public function isSkipEnvironment()
    {
        if (count(config('larabug.environments')) == 0) {
            return false;
        }

        if (in_array(App::environment(), config('larabug.environments'))) {
            return true;
        }

        return false;
    }

    /**
     * @param Throwable $exception
     * @return array
     */
    public function getExceptionData(Throwable $exception)
    {
        $data = [];

        $data['enviroment'] = App::environment();
        $data['host'] = Request::server('SERVER_NAME');
        $data['method'] = Request::method();
        $data['fullUrl'] = Request::fullUrl();
        $data['exception'] = $exception->getMessage();
        $data['error'] = $exception->getTraceAsString();
        $data['line'] = $exception->getLine();
        $data['file'] = $exception->getFile();
        $data['class'] = get_class($exception);
        $data['storage'] = [
            'SERVER' => Request::server(),
            'GET' => Request::query(),
            'POST' => $_POST,
            'FILE' => Request::file(),
            'OLD' => Request::hasSession() ? Request::old() : [],
            'COOKIE' => Request::cookie(),
            'SESSION' => Request::hasSession() ? Session::all() : [],
            'HEADERS' => Request::header(),
        ];

        $data['storage'] = array_filter($data['storage']);

        $count = config('larabug.lines_count');
        $lines = file($data['file']);
        $data['exegutor'] = [];

        for ($i = -1 * abs($count); $i <= abs($count); $i++) {
            $data['exegutor'][] = $this->getLineInfo($lines, $data['line'], $i);
        }
        $data['exegutor'] = array_filter($data['exegutor']);

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
     * Gets information from the line
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
            'line' => '<span class="exception-currentline">' . $currentLine . '.</span> ' . SyntaxHighlight::process($lines[$index]),
            'wrap_left' => $i ? '' : '<span class="exception-line">', // color: #F5F5F5; background-color: #5A3E3E; width: 100%; display: block;
            'wrap_right' => $i ? '' : '</span>',
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
        return 'larabug.' . Str::slug($data['host'] . '_' . $data['method'] . '_' . $data['exception'] . '_' . $data['line'] . '_' . $data['file'] . '_' . $data['class']);
    }

    /**
     * @param $exception
     */
    private function logError($exception)
    {
        $this->client->report($exception);
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

