# Muestrea CPU y memoria de los procesos PHP de medición (PIDs de start-servers.ps1), MySQL y el
# balanceador mientras corre k6. CPU en % de la máquina completa (todos los núcleos = 100 %).
#
#   .\tests\perf\monitor.ps1 -Seconds 480 -Out storage\app\qa\perf\monitor-rampa.csv
param(
    [int]$Seconds = 300,
    [int]$Interval = 2,
    [string]$Out = 'storage\app\qa\perf\monitor.csv'
)

$pidFile = Join-Path $env:TEMP 'crm-b2g-perf-pids.txt'
$perfPids = if (Test-Path $pidFile) { Get-Content $pidFile | ForEach-Object { [int]$_ } } else { @() }
$cores = (Get-CimInstance Win32_ComputerSystem).NumberOfLogicalProcessors

function Snapshot {
    $groups = @{
        php   = @(Get-Process -Id $perfPids -ErrorAction SilentlyContinue | Where-Object { $_.ProcessName -eq 'php' })
        node  = @(Get-Process -Id $perfPids -ErrorAction SilentlyContinue | Where-Object { $_.ProcessName -eq 'node' })
        mysql = @(Get-Process -Name mysqld -ErrorAction SilentlyContinue)
    }
    $result = @{}
    foreach ($k in $groups.Keys) {
        $result[$k] = [pscustomobject]@{
            cpu = ($groups[$k] | Measure-Object -Property TotalProcessorTime -Sum -ErrorAction SilentlyContinue | ForEach-Object { $_.Sum })
            mem = [math]::Round((($groups[$k] | Measure-Object -Property WorkingSet64 -Sum).Sum) / 1MB, 1)
            n   = $groups[$k].Count
        }
        if (-not $result[$k].cpu) { $result[$k].cpu = 0 }
        $result[$k].cpu = ($groups[$k] | ForEach-Object { $_.TotalProcessorTime.TotalMilliseconds } | Measure-Object -Sum).Sum
    }
    return $result
}

"time,php_cpu_pct,php_mem_mb,php_procs,mysql_cpu_pct,mysql_mem_mb,lb_cpu_pct,lb_mem_mb,total_cpu_pct" | Set-Content $Out
$prev = Snapshot
$end = (Get-Date).AddSeconds($Seconds)
while ((Get-Date) -lt $end) {
    Start-Sleep -Seconds $Interval
    $now = Snapshot
    $pct = { param($k) [math]::Round((($now[$k].cpu - $prev[$k].cpu) / ($Interval * 1000 * $cores)) * 100, 1) }
    $total = [math]::Round((Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average, 0)
    "{0},{1},{2},{3},{4},{5},{6},{7},{8}" -f (Get-Date -Format 'HH:mm:ss'), (& $pct 'php'), $now['php'].mem, $now['php'].n, (& $pct 'mysql'), $now['mysql'].mem, (& $pct 'node'), $now['node'].mem, $total | Add-Content $Out
    $prev = $now
}
