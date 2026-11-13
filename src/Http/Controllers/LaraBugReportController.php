<?php

namespace LaraBug\Http\Controllers;

use ErrorException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class LaraBugReportController
{
    public function report(Request $request): Response
    {
        // Every field here comes from an anonymous browser and ends up in the
        // report sent to the project, so none of it is taken on trust.
        // Validated by hand rather than through a form request: this route
        // runs without the web group, so there is no session to flash to.
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:2000'],
            'line' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'file' => ['nullable', 'string', 'max:2000'],
            'stack' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($validator->fails()) {
            return response('invalid report', 422);
        }

        $report = $validator->validated();

        /** @var \LaraBug\LaraBug $laraBug */
        $laraBug = app('larabug');

        $laraBug->handle(
            new ErrorException($report['message']),
            'javascript',
            [
                'file' => $report['file'] ?? null,
                'line' => $report['line'] ?? null,
                'message' => $report['message'],
                'stack' => $report['stack'] ?? null,
                'url' => $report['url'] ?? null,
            ]
        );

        return response('ok', 200);
    }
}
