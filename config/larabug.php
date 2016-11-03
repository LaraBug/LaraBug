<?php

return [

    /**
     * Environments where LaraBug should report
     */
    'environments' => [
        'local'
    ],

    /*
     * How many lines to show near exception line.
     */
    'lines_count' => 12,

    /**
     * Set the sleep time between duplicate exceptions.
     */
    'sleep' => 0,

    /*
     * List of exceptions to skip sending.
     */
    'except' => [
        'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
    ],

];
