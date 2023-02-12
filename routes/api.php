<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::group(
    [
        'namespace' => '\LaraBug\Http\Controllers',
        'prefix' => 'larabug-api'
    ],
    function () {
        Route::post('javascript-report', 'LaraBugReportController@report');
    }
);
