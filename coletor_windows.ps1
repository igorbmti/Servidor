# ==============================================================================
# COLETOR DE MÉTRICAS WINDOWS — PAINEL DE CONTROLE NEXUS
# Envia capacidade e uso de armazenamento em tempo real para o servidor
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

if ($disks.Count -gt 0) {
    $body = @{ discos = $disks } | ConvertTo-Json -Depth 3
    try {
        $response = Invoke-RestMethod -Uri "http://192.168.18.166/painel/api/windows.php" -Method Post -Body $body -ContentType "application/json" -TimeoutSec 6
        if ($response.sucesso) {
            Write-Output "[$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss'))] Métricas enviadas com sucesso: $($disks.Count) disco(s)."
        }
    } catch {
        Write-Warning "[$((Get-Date).ToString('yyyy-MM-dd HH:mm:ss'))] Falha ao enviar métricas para o servidor: $_"
    }
}
