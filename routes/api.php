<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::post('javascript-report', 'LaraBugReportController@report');
