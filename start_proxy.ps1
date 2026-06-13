$phpPort = 8000
$proxyPort = 8002

$script = @"
import http.server, urllib.request, sys, threading, os
PORT = $proxyPort
TARGET = 'http://127.0.0.1:$phpPort'
class PHProxy(http.server.SimpleHTTPRequestHandler):
    def do_GET(self):
        try:
            req = urllib.request.Request(TARGET + self.path, headers=dict(self.headers))
            with urllib.request.urlopen(req, timeout=30) as resp:
                self.send_response(resp.status)
                for k, v in resp.headers.items():
                    if k.lower() not in ('transfer-encoding', 'content-encoding', 'connection'):
                        self.send_header(k, v)
                self.end_headers()
                self.wfile.write(resp.read())
        except Exception as e:
            self.send_error(502, str(e))
    do_POST = do_GET; do_PUT = do_GET; do_DELETE = do_GET
    def log_message(self, fmt, *args): pass
httpd = http.server.HTTPServer(('0.0.0.0', PORT), PHProxy)
httpd.serve_forever()
"@

$proxyFile = "$env:TEMP\translink_proxy_service.py"
Set-Content -Path $proxyFile -Value $script
python $proxyFile
