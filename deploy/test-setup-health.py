#!/usr/bin/env python3
"""Exercise the native setup HTTP readiness check against a real local server."""
import http.server
import pathlib
import subprocess
import threading

helper = pathlib.Path(__file__).resolve().with_name('setup-filesystem.sh')


def check(responses, expected_success, expected_calls):
    calls = []

    class Handler(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            calls.append((self.path, self.headers.get('Host')))
            self.send_response(responses[min(len(calls) - 1, len(responses) - 1)])
            self.end_headers()

        def log_message(self, *_):
            pass

    with http.server.ThreadingHTTPServer(('127.0.0.1', 0), Handler) as server:
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            result = subprocess.run(
                ['sh', '-c', '. "$1"; bulls_wait_http example.test "$2"',
                 'health-test', str(helper), f'http://127.0.0.1:{server.server_port}/up'],
                capture_output=True, text=True, timeout=40,
            )
            assert (result.returncode == 0) == expected_success, result.stderr
            assert len(calls) == expected_calls, calls
            assert all(call == ('/up', 'example.test') for call in calls), calls
        finally:
            server.shutdown()
            thread.join()


check([404, 200], True, 2)
check([404], False, 6)
check([302], False, 1)
print('Setup HTTP readiness regressions passed.')
