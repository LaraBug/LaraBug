<script defer>
    class LarabugJsClient {
        constructor() {
            window.addEventListener('error', e => {
                this.send(e);
            });
        }

        send(e) {
            return new Promise(function (resolve, reject) {
                let stack = e.error.stack;
                let exception = e.error.toString();

                if (stack) {
                    exception += '\n' + stack;
                }

                let data = {
                    message: e.message,
                    exception: exception,
                    file: e.filename,
                    url: window.location.origin + window.location.pathname,
                    line: e.lineno,
                    column: e.colno,
                    error: e.message,
                    stack: e.error.stack,
                };

                let xhr = new XMLHttpRequest();
                xhr.open("POST", window.location.origin + '/larabug-api/javascript-report', true);
                xhr.setRequestHeader("Content-Type", "application/json;charset=UTF-8");
                xhr.onload = function () {
                    if (this.status >= 200 && this.status < 300) {
                        resolve(xhr.response);
                    } else {
                        reject({
                            status: this.status,
                            statusText: xhr.statusText
                        });
                    }
                };
                xhr.onerror = function () {
                    reject({
                        status: this.status,
                        statusText: xhr.statusText
                    });
                };
                xhr.send(JSON.stringify(data));
            });
        }
    }

    new LarabugJsClient();
</script>
