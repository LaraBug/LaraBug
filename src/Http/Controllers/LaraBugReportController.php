<?php

namespace LaraBug\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class LaraBugReportController
{
    /**
     * @param Request $request
     * @return \Illuminate\Http\Response|\Illuminate\Contracts\Routing\ResponseFactory
     */
    public function report(Request $request)
    {
        // Validated by hand rather than through $request->validate(), because this
        // route deliberately runs without the web group and a redirect response
        // would need a session that is not there.
        $validator = Validator::make($request->all(), [
            'message' => ['required', 'string', 'max:2000'],
            'line' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'file' => ['nullable', 'string', 'max:2048'],
            'stack' => ['nullable', 'string', 'max:20000'],
            'url' => ['nullable', 'string', 'max:2048'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $report = $validator->validated();

        /** @var \LaraBug\LaraBug $laraBug */
        $laraBug = app('larabug');

        $laraBug->handle(
            new \ErrorException($report['message']),
            'javascript',
            [
                'file' => $report['file'] ?? null,
                'line' => isset($report['line']) ? (int) $report['line'] : null,
                'message' => $report['message'],
                'stack' => $report['stack'] ?? null,
                'url' => $report['url'] ?? null,
            ]
        );

        return response('ok', 200);
    }
}
