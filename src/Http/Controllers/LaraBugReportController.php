<?php

declare(strict_types=1);

namespace LaraBug\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Contracts\Routing\ResponseFactory;

class LaraBugReportController
{
    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function report(Request $request): Response|ResponseFactory
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
