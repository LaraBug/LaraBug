<?php

declare(strict_types=1);

namespace LaraBug\Http\Controllers;

use Illuminate\Http\Request;

class LaraBugReportController
{
    public function report(Request $request): \Illuminate\Http\Response|\Illuminate\Contracts\Routing\ResponseFactory
    {
        /** @var \LaraBug\LaraBug $laraBug */
        $laraBug = app('larabug');

        $laraBug->handle(
            new \ErrorException($request->input('message')),
            'javascript',
            [
                'file' => $request->input('file'),
                'line' => $request->input('line'),
                'message' => $request->input('message'),
                'stack' => $request->input('stack'),
                'url' => $request->input('url'),
            ]
        );

        return response('ok', 200);
    }
}
