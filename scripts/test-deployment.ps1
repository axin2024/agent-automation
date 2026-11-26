param(
    [Parameter(Mandatory)][string]$Php,
    [string[]]$Extensions,
    [Parameter(Mandatory)][string]$Fixture,
    [Parameter(Mandatory)][string]$MySQLBin,
    [Parameter(Mandatory)][int]$Port,
    [Parameter(Mandatory)][string]$RootPassword
)

$ErrorActionPreference = 'Stop'
$project = Split-Path $PSScriptRoot -Parent
$mysql = Join-Path $MySQLBin 'mysql.exe'
$dump = Join-Path $MySQLBin 'mysqldump.exe'
$client = @('--no-defaults', '--protocol=TCP', '--host=127.0.0.1', "--port=$Port")
$web = $null
$worker = $null
$oldToken = $env:AUTOMATION_SMOKE_TOKEN
$oldDsn = $env:AUTOMATION_MYSQL_DSN
$oldPwd = $env:MYSQL_PWD
try {
    & $Php @Extensions bin/automation.php migrate
    if ($LASTEXITCODE -ne 0) { throw 'Migrations failed.' }
    $provisioned = & $Php @Extensions bin/automation.php provision deployment test fixture-admin admin 1
    if ($LASTEXITCODE -ne 0) { throw 'Provisioning failed.' }
    $env:AUTOMATION_SMOKE_TOKEN = ($provisioned | ConvertFrom-Json).token
    $listener = [Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback, 0)
    $listener.Start()
    try { $httpPort = $listener.LocalEndpoint.Port } finally { $listener.Stop() }
    $baseUrl = "http://127.0.0.1:$httpPort"
    $web = Start-Process -FilePath $Php -ArgumentList (@($Extensions) + @('-S',"127.0.0.1:$httpPort",'-t','public','public/router.php')) `
        -WorkingDirectory $project -RedirectStandardError (Join-Path $Fixture 'web.log') -PassThru -WindowStyle Hidden
    $worker = Start-Process -FilePath $Php -ArgumentList (@($Extensions) + @('bin/automation.php','worker')) `
        -WorkingDirectory $project -RedirectStandardError (Join-Path $Fixture 'worker.log') -PassThru -WindowStyle Hidden
    $ready = $false
    for ($i = 0; $i -lt 30; $i++) {
        if ($web.HasExited -or $worker.HasExited) { throw 'Web or worker process stopped before readiness.' }
        try {
            $ready = (Invoke-RestMethod "$baseUrl/api/health" -TimeoutSec 2 -NoProxy).status -eq 'ready'
            if ($ready) { break }
        } catch { Start-Sleep -Milliseconds 100 }
    }
    if (!$ready) { throw 'Web API did not become ready.' }
    $workbench = Invoke-WebRequest "$baseUrl/" -TimeoutSec 2 -NoProxy
    if ($workbench.Content -notmatch '流程工作台' -or !$workbench.Headers['Content-Security-Policy']) { throw 'Workbench entry failed.' }
    $evidence = & $Php @Extensions scripts/smoke.php $baseUrl
    if ($LASTEXITCODE -ne 0) { throw 'HTTP sample failed.' }
    if ($env:AUTOMATION_TEST_BROWSER_REPORT) {
        node scripts/browser-template.cjs $baseUrl $env:AUTOMATION_TEST_BROWSER_REPORT
        if ($LASTEXITCODE -ne 0) { throw 'Browser workbench scenarios failed.' }
    }
    Stop-Process -InputObject $worker
    $worker = $null
    Stop-Process -InputObject $web
    $web = $null

    $databaseFile = Join-Path $Fixture 'backup.sql'
    & $dump @client --user=automation_test --single-transaction --no-tablespaces --set-gtid-purged=OFF --hex-blob "--result-file=$databaseFile" automation_test
    if ($LASTEXITCODE -ne 0) { throw 'Backup failed.' }
    $env:MYSQL_PWD = $RootPassword
    & $mysql @client --user=root "--execute=CREATE DATABASE automation_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin; GRANT ALL ON automation_restore.* TO 'automation_test'@'127.0.0.1'"
    if ($LASTEXITCODE -ne 0) { throw 'Restore target creation failed.' }
    $env:MYSQL_PWD = $oldPwd
    $imports = Start-Process -FilePath $mysql -ArgumentList ($client + @('--user=automation_test','automation_restore')) `
        -RedirectStandardInput $databaseFile -RedirectStandardError (Join-Path $Fixture 'restore.log') -PassThru -Wait -WindowStyle Hidden
    if ($imports.ExitCode -ne 0) { throw 'Restore failed.' }
    $env:AUTOMATION_MYSQL_DSN = "mysql:host=127.0.0.1;port=$Port;dbname=automation_restore;charset=utf8mb4"
    & $Php @Extensions bin/automation.php migrate
    if ($LASTEXITCODE -ne 0) { throw 'Restored schema could not be loaded.' }
    $result = $evidence | ConvertFrom-Json
    & $Php @Extensions tests/restore.php $result.run $result.receipt
    if ($LASTEXITCODE -ne 0) { throw 'Restored data failed verification.' }
    Write-Output $evidence
} finally {
    foreach ($child in @($worker, $web)) { if ($child -and !$child.HasExited) { Stop-Process -InputObject $child } }
    $env:AUTOMATION_SMOKE_TOKEN = $oldToken
    $env:AUTOMATION_MYSQL_DSN = $oldDsn
    $env:MYSQL_PWD = $oldPwd
}
