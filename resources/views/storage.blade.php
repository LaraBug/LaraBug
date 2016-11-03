
<tr style="border-bottom: #55688A 1px solid;">
    <td style="vertical-align: top; padding: 15px 50px; background: #272727; color: #dddddd; border-right: #55688A 1px solid;">
        {{ $caption }}
    </td>
    <td style="width: 100%; background: #272727; padding: 2px 10px;">
        <table style="border-collapse:collapse;">
            <tbody>
            @foreach ($data as $key => $val)
                <tr>
                    <td style="padding: 0; vertical-align: top; text-align: right;">
                        <pre style="margin: 0; background: #272727; color: #aaaaaa; font-family: monospace; font-size: 12px; padding: 5px 0px 5px 0px; white-space: nowrap; word-break: keep-all;">{{ $key }}</pre>
                    </td>
                    <td style="padding: 0; vertical-align: top;">
                        <pre style="margin: 0; background: #272727; color: #aaaaaa; font-family: monospace; font-size: 12px; padding: 5px 0px 5px 12px; white-space: nowrap; word-break: keep-all;">=&gt;</pre>
                    </td>
                    <td style="padding: 0;">
                        <pre style="margin: 0; background: #272727; color: #aaaaaa; font-family: monospace; font-size: 12px; padding: 5px 12px 5px 12px; white-space: pre-wrap; word-break: break-word;">{{ print_r($val, true) }}</pre>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </td>
</tr>
