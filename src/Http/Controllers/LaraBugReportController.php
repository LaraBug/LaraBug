<?php

namespace LaraBug\Http\Controllers;

use Illuminate\Http\Request;

class LaraBugReportController
{
    public function report(Request $request)
    {
        $laraBug = app('larabug');

        $data = [
            'file' => $request->input('file'),
            'line' => $request->input('line'),
            'message' => $request->input('message'),
            'stack' => $request->input('stack'),
            'url' => $request->input('url'),
        ];

        $laraBug->handle(
            new \ErrorException(
                $request->input('message')
            ),
            'javascript',
            $data
        );

        return response('ok', 200);
    }
}
