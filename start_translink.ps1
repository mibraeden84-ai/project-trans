$php = "C:\xampp\php\php.exe"
$docRoot = "C:\Users\pc\Downloads\-translink-file-library-master\-translink-file-library-master\extracted\Translink file library\website"
$proxyPort = 8002
$phpPort = 8000

# Kill old processes
Get-Process -Name "php" -ErrorAction SilentlyContinue | Stop-Process -Force

# Start PHP dev server with router for API support
Start-Process -WindowStyle Hidden -FilePath $php -ArgumentList "-S 0.0.0.0:$phpPort -t `"$docRoot`" `"$docRoot\router.php`""
Start-Sleep -Seconds 2

# Start Python proxy (multi-threaded, handles tunnel traffic better)
$proxyScript = @"
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
$proxyFile = "$env:TEMP\translink_proxy.py"
Set-Content -Path $proxyFile -Value $proxyScript
Start-Process -WindowStyle Hidden -FilePath "python" -ArgumentList $proxyFile

Start-Sleep -Seconds 2
Write-Output "Translink GPS Library started!"
Write-Output "Local: http://127.0.0.1:$phpPort"
Write-Output "Proxy: http://127.0.0.1:$proxyPort"
Write-Output "Run: lt --port $proxyPort  # for public URL"
