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

    /** @var array */
    private $blacklist = [];

    /** @var null|string */
    private $lastExceptionId;

    /**
     * @param Client $client
     */
    public function __construct(Client $client)
    {
        $this->client = $client;

        $this->blacklist = array_map(function($blacklist) {
            return strtolower($blacklist);
        }, config('larabug.blacklist', []));

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
            return false;
        }

        $data = $this->getExceptionData($exception);

        if ($this->isSkipException($data['class'])) {
            return false;
        }

        if ($this->isSleepingException($data)) {
            return false;
        }

        $rawResponse = $this->logError($data);

        if(!$rawResponse) {
            return false;
        }

        $response = json_decode($rawResponse->getBody()->getContents());

        if(isset($response->id)) {
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
     * Get the last exception id given to us by the larabug API
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

        $data['enviroment'] = App::environment();
        $data['host'] = Request::server('SERVER_NAME');
        $data['method'] = Request::method();
        $data['fullUrl'] = Request::fullUrl();
        $data['exception'] = $exception->getMessage();
        $data['error'] = $exception->getTraceAsString();
        $data['line'] = $exception->getLine();
        $data['file'] = $exception->getFile();
        $data['class'] = get_class($exception);
        $data['release'] = config('larabug.release', null);
        $data['storage'] = [
            'SERVER' => $this->filterVariables(Request::server()),
            'GET' => $this->filterVariables(Request::query()),
            'POST' => $this->filterVariables($_POST),
            'FILE' => Request::file(),
            'OLD' => $this->filterVariables(Request::hasSession() ? Request::old() : []),
            'COOKIE' => $this->filterVariables(Request::cookie()),
            'SESSION' => $this->filterVariables(Request::hasSession() ? Session::all() : []),
            'HEADERS' => $this->filterVariables(Request::header()),
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
     * @param $variables
     * @return array
     */
    public function filterVariables($variables)
    {
        if(is_array($variables)) {
            array_walk($variables, function($val, $key) use(&$variables) {
                if(is_array($val)) {
                    $variables[$key] = $this->filterVariables($val);
                }
                if(in_array(strtolower($key), $this->blacklist)) {
                    unset($variables[$key]);
                }
            });
            return $variables;
        }
        return [];
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
        return $this->client->report($exception);
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

