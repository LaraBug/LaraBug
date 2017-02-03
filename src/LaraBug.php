<?php namespace LaraBug;

use Exception;

use LaraBug\Helpers\Logger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Session;

class LaraBug
{
    const SERVICE = 'larabug';

    private $config = [];

    /**
     * LaraBug constructor.
     */
    public function __construct()
    {
        $this->config['except'] = config('larabug.except', []);
        $this->config['count'] = config('larabug.lines_count', 12);
        $this->config['environments'] = config('larabug.environments', []);
        $this->config['sleep'] = config('larabug.sleep', 0);
        $this->config['errorView'] = config('larabug.errorView', 'errors.500');
    }

    public function handle($exception, array $additionalData = [])
    {
        try {
            $data = $this->getExceptionData($exception);

            /**
             * Check if we should skip this exception
             */
            if ($this->isSkipException($data['class'])) {
                return;
            }

            /*
             * Check environments
             */
            if (!$this->checkEnvironments()) {
                return;
            }

            /*
             * Check if sleep time has been set and
             * exception is not a duplicate entry
             */
            if ($this->config['sleep'] !== 0 && $this->hasSleepingException($data)) {
                return;
            }

            /*
             * Send to error
             */
            $this->logError($data, $additionalData);

            /*
             * If sleep has been enabled, add the new exception
             */
            if ($this->config['sleep'] !== 0) {
                $this->addExceptionToSleep($data);
            }

            return;
        } catch (Exception $e) {
            Log::error($e);
        }
    }

    public function errorView()
    {
        if(\Illuminate\Support\Facades\View::exists($this->config['errorView'])){
            return view($this->config['errorView']);
        }

        return false;
    }

    /**
     * @param $exceptionClass
     *
     * @return mixed
     */
    public function isSkipException($exceptionClass)
    {
        return in_array($exceptionClass, $this->config['except']);
    }

    /**
     * @param $exception
     *
     * @return array
     */
    private function getExceptionData($exception)
    {
        $data = [];

        $data['enviroment'] = env('APP_ENV');
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

        $count = $this->config['count'];
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
     * checkEnvironments function.
     *
     * @return bool
     */
    public function checkEnvironments()
    {
        if (in_array(env('APP_ENV'), $this->config['environments'])) {
            return true;
        }

        return false;
    }

    /**
     * hasDuplicateEntry function.
     *
     * @param array $data
     *
     * @return bool
     */
    private function hasSleepingException(array $data)
    {
        return Cache::has($this->createExceptionString($data));
    }

    /**
     * createExceptionString function.
     * Generate a string that should be unique for these exceptions.
     *
     * @param array $data
     *
     * @return string
     */
    private function createExceptionString(array $data)
    {
        return 'larabug.' . str_slug($data['host'] . '_' . $data['method'] . '_' . $data['exception'] . '_' . $data['line'] . '_' . $data['file'] . '_' . $data['class']);
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
     * @param array $exception
     * @param array $additionalData
     *
     */
    private function logError($exception, array $additionalData = [])
    {
        $logger = (new Logger($exception));

        if(count($additionalData)){
            $logger->addAdditionalData($additionalData);
        }

        $logger->send();
    }


    /**
     * addExceptionToSleep function.
     *
     * @param array $data
     *
     * @return bool
     */
    private function addExceptionToSleep(array $data)
    {
        $exceptionString = $this->createExceptionString($data);

        return Cache::put($exceptionString, $exceptionString, $this->config['sleep']);
    }
}

