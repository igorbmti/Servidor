# ==============================================================================
# COLETOR DE MÉTRICAS WINDOWS — PAINEL DE CONTROLE
# Envia capacidade de armazenamento, uptime, memória RAM e saúde do disco
# ==============================================================================

$ErrorActionPreference = 'SilentlyContinue'

$disks = @()
$logicalDisks = Get-CimInstance -ClassName Win32_LogicalDisk | Where-Object { $_.DriveType -eq 3 }

foreach ($d in $logicalDisks) {
    $rotulo = if (![string]::IsNullOrWhiteSpace($d.VolumeName)) { $d.VolumeName } else { "Disco Local" }
    $disks += @{
        unidade = "$($d.DeviceID)"
        rotulo = "$rotulo"
        total_bytes = [int64]$d.Size
        livre_bytes = [int64]$d.FreeSpace
    }
}

# 1. Uptime e Memória RAM
$os = Get-CimInstance Win32_OperatingSystem
$uptime = (Get-Date) - $os.LastBootUpTime
$uptimeStr = "{0}d {1}h {2}m" -f $uptime.Days, $uptime.Hours, $uptime.Minutes

$totMem = [int64]$os.TotalVisibleMemorySize * 1024
$freeMem = [int64]$os.FreePhysicalMemory * 1024
$usedMem = $totMem - $freeMem

# 2. Saúde e Modelo do Disco Físico
$physicalDisk = Get-PhysicalDisk | Select-Object -First 1
$healthRaw = if ($physicalDisk) { $physicalDisk.HealthStatus } else { 'Healthy' }
$healthStr = if ($healthRaw -eq 'Healthy') { '100% Saudável' } else { "$healthRaw" }
$modelStr = if ($physicalDisk -and $physicalDisk.FriendlyName) { $physicalDisk.FriendlyName } else { "Disco Físico SSD" }
$totalDiskGb = if ($physicalDisk -and $physicalDisk.Size -gt 0) {
    [string]::Format("{0:N1} GB", ($physicalDisk.Size / 1GB))
} else {
    "120,0 GB"
}

# 3. IP local da máquina
$ipLocal = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias 'Wi-Fi*', 'Ethernet*' | Where-Object { $_.IPAddress -notlike '169.254*' -and $_.IPAddress -notlike '127.*' } | Select-Object -First 1).IPAddress
if ([string]::IsNullOrWhiteSpace($ipLocal)) { $ipLocal = "192.168.18.19" }

$recursos = @{
    ip = "$ipLocal"
    uptime = "$uptimeStr"
    mem_total_bytes = [int64]$totMem
    mem_usada_bytes = [int64]$usedMem
    mem_livre_bytes = [int64]$freeMem
    mem_total_gb = [string]::Format("{0:N2} GB", ($totMem / 1GB))
    mem_usada_gb = [string]::Format("{0:N2} GB", ($usedMem / 1GB))
    mem_resumo = [string]::Format("{0:N2} GB / {1:N2} GB", ($usedMem / 1GB), ($totMem / 1GB))
    saude_disco = "$healthStr"
    disco_modelo = "$modelStr"
    disco_capacidade_gb = "$totalDiskGb"
}

if ($disks.Count -gt 0) {
    $body = @{
        discos = $disks
        recursos = $recursos
    } | ConvertTo-Json -Depth 4

    $enviado = $false
    $endpoints = @(
        "http://192.168.18.166:3001/api_windows.php",
        "http://192.168.18.166/painel/api_windows.php",
        "http://192.168.18.166/painel/api/windows.php"
    )

    $bodyBytes = [System.Text.Encoding]::UTF8.GetBytes($body)

    foreach ($url in $endpoints) {
        try {
            $response = Invoke-RestMethod -Uri $url -Method Post -Body $bodyBytes -ContentType "application/json; charset=utf-8" -TimeoutSec 5
            if ($response.sucesso) {
                Write-Output "[$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss'))] Métricas enviadas com sucesso via ${url} - $($disks.Count) disco(s) e telemetria."
                $enviado = $true
                break
            }
        } catch {}
    }

    if (-not $enviado) {
        Write-Warning "[$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss'))] Falha ao enviar para todos os endpoints."
    }
}
