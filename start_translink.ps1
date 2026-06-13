$php = "C:\xampp\php\php.exe"
$docRoot = "C:\Users\pc\Downloads\-translink-file-library-master\-translink-file-library-master\extracted\Translink file library\website"
$logDir = "$docRoot\tmp"
if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Path $logDir -Force }

# Kill old processes
Get-Process -Name "php" -ErrorAction SilentlyContinue | Stop-Process -Force

# Start PHP dev server
Start-Process -WindowStyle Hidden -FilePath $php -ArgumentList "-S 0.0.0.0:8000 -t `"$docRoot`""

# Wait for server to start
Start-Sleep -Seconds 2

# Start localtunnel
$tunnelJob = Start-Job -ScriptBlock { param($port) lt --port $port 2>&1 }
$tunnelJob | Out-File "$logDir\tunnel_job.txt"

Write-Output "Translink GPS Library started!"
Write-Output "Local: http://127.0.0.1:8000"
Write-Output "To get tunnel URL: Receive-Job -Job $tunnelJob"
