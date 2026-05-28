$ErrorActionPreference = 'SilentlyContinue'

$projectPath = 'C:\Users\pc\Documents\Codex\2026-05-22\files-mentioned-by-the-user-translink\extracted\Translink file library\website'
$phpExe = 'C:\xampp\php\php.exe'
$mysqlStartBat = 'C:\xampp\mysql_start.bat'
$healthUrl = 'http://127.0.0.1:8000/login.php?redirect=%2F'

if (-not (Get-Process -Name mysqld -ErrorAction SilentlyContinue)) {
    Start-Process -FilePath $mysqlStartBat -WindowStyle Hidden | Out-Null
    Start-Sleep -Seconds 2
}

$serverUp = $false
try {
    $resp = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 3
    if ($resp.StatusCode -ge 200 -and $resp.StatusCode -lt 500) {
        $serverUp = $true
    }
} catch {}

if (-not $serverUp) {
    Start-Process -FilePath $phpExe -ArgumentList @(
        '-d', 'upload_max_filesize=256M',
        '-d', 'post_max_size=256M',
        '-d', 'memory_limit=512M',
        '-d', 'max_execution_time=300',
        '-d', 'max_input_time=300',
        '-d', 'display_errors=0',
        '-S', '0.0.0.0:8000',
        '-t', $projectPath
    ) -WorkingDirectory $projectPath -WindowStyle Hidden | Out-Null
}
