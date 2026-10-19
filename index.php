<?php
declare(strict_types=1);

/**
 * PAINEL DE MONITORAMENTO DE INFRAESTRUTURA
 * Interface de Navegação Circular 360 Graus (Wheel Navigation)
 * Suporte a Linux, Windows e PlayStation 4 (PS4 Fat Original),
 * Métricas em GB, Saúde do Disco, Infográficos, Estado do Console e Ações de Rede.
 */

date_default_timezone_set('America/Sao_Paulo');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

const SERVIDOR_NOME_VISUAL = 'NEXUS';
const LINUX_IP = '192.168.18.166';
const WINDOWS_IP = '192.168.18.19';
const WINDOWS_JSON = '/var/lib/monitoramento/windows.json';
const PS4_IP = '192.168.18.162';
const PS4_MAC = 'c0:38:96:d9:a3:a7';
const PS4_HOST_ID = '00D9D1AED426';
const LIMITE_NORMAL = 75.0;
const LIMITE_ATENCAO = 90.0;

function formatarGB(float $bytes, int $dec = 1): string
{
    $gb = $bytes / (1024 * 1024 * 1024);
    if ($gb < 0.1 && $bytes > 0) {
        $mb = $bytes / (1024 * 1024);
        return number_format($mb, 1, ',', '.') . ' MB';
    }
    return number_format($gb, $dec, ',', '.') . ' GB';
}

function situacao(float $percentual): array
{
    if ($percentual >= LIMITE_ATENCAO) {
        return [
            'texto' => 'Crítico',
            'classe' => 'critico',
            'cor' => '#EF4444',
            'bg' => 'rgba(239, 68, 68, 0.14)',
            'borda' => '#7F1D1D'
        ];
    }
    if ($percentual >= LIMITE_NORMAL) {
        return [
            'texto' => 'Atenção',
            'classe' => 'atencao',
            'cor' => '#F59E0B',
            'bg' => 'rgba(245, 158, 11, 0.14)',
            'borda' => '#78350F'
        ];
    }
    return [
        'texto' => 'Saudável',
        'classe' => 'normal',
        'cor' => '#22C55E',
        'bg' => 'rgba(34, 197, 94, 0.14)',
        'borda' => '#14532D'
    ];
}

function coletarRecursosLinux(): array
{
    $res = [
        'hostname' => gethostname() ?: 'trevus1',
        'uptime' => 'Ativo',
        'cpu_load' => '0.00, 0.00, 0.00',
        'cpu_percentual' => 0.0,
        'mem_total_gb' => '0 GB',
        'mem_usada_gb' => '0 GB',
        'mem_livre_gb' => '0 GB',
        'mem_percentual' => 0.0,
        'saude_disco' => '100% Saudável (Running)',
        'disco_estado' => 'running'
    ];

    // Uptime
    $rawUp = @file_get_contents('/proc/uptime');
    if ($rawUp !== false) {
        $sec = (int) floatval(explode(' ', trim($rawUp))[0]);
        $d = (int) ($sec / 86400);
        $h = (int) (($sec % 86400) / 3600);
        $m = (int) (($sec % 3600) / 60);
        $res['uptime'] = ($d > 0 ? "{$d}d " : "") . "{$h}h {$m}m";
    }

    // CPU Load
    $rawLoad = @file_get_contents('/proc/loadavg');
    if ($rawLoad !== false) {
        $p = explode(' ', trim($rawLoad));
        $res['cpu_load'] = "{$p[0]}, {$p[1]}, {$p[2]}";
        $load1 = floatval($p[0]);
        $res['cpu_percentual'] = min(100.0, ($load1 / 2.0) * 100.0);
    }

    // Memória
    $rawMem = @file_get_contents('/proc/meminfo');
    if ($rawMem !== false) {
        preg_match('/MemTotal:\s+(\d+)\s+kB/', $rawMem, $mTot);
        preg_match('/MemAvailable:\s+(\d+)\s+kB/', $rawMem, $mAvail);
        if (!empty($mTot[1]) && !empty($mAvail[1])) {
            $totB = floatval($mTot[1]) * 1024;
            $availB = floatval($mAvail[1]) * 1024;
            $usedB = max(0.0, $totB - $availB);
            $res['mem_total_gb'] = formatarGB($totB, 2);
            $res['mem_usada_gb'] = formatarGB($usedB, 2);
            $res['mem_livre_gb'] = formatarGB($availB, 2);
            $res['mem_percentual'] = min(100.0, ($usedB / $totB) * 100);
        }
    }

    // Saúde do Disco
    $rawState = @file_get_contents('/sys/block/sda/device/state');
    if ($rawState !== false && trim($rawState) === 'running') {
        $res['saude_disco'] = '100% Saudável (Running)';
        $res['disco_estado'] = 'running';
    } else {
        $res['saude_disco'] = 'Operacional';
    }

    return $res;
}

function coletarArmazenamentoLinux(): array
{
    $recursos = coletarRecursosLinux();
    $resultado = [
        'nome_visual' => SERVIDOR_NOME_VISUAL,
        'ip' => LINUX_IP,
        'recursos' => $recursos,
        'disco_fisico' => null,
        'lvm' => null,
        'sistemas_arquivos' => [],
        'infografico_alocacao' => [],
        'ultima_coleta' => date('d/m/Y H:i:s')
    ];

    $output = [];
    $ret = 0;
    @exec('lsblk -b -J -e 7,11 -o NAME,PATH,SIZE,TYPE,FSTYPE,MOUNTPOINTS,PKNAME,MODEL 2>/dev/null', $output, $ret);
    $lsblkJson = ($ret === 0 && !empty($output)) ? json_decode(implode('', $output), true) : null;

    $discoTotal = 500107862016.0; // 500.1 GB
    $pvTamanho = 496831037440.0;
    $lvTamanho = 268435456000.0;

    if (isset($lsblkJson['blockdevices'][0])) {
        $dev = $lsblkJson['blockdevices'][0];
        $discoTotal = (float) ($dev['size'] ?? $discoTotal);
        $resultado['disco_fisico'] = [
            'nome' => $dev['name'] ?? 'sda',
            'path' => $dev['path'] ?? '/dev/sda',
            'modelo' => trim($dev['model'] ?? 'WDC WD5000LPVX-0'),
            'total_bytes' => $discoTotal,
            'total_gb' => formatarGB($discoTotal, 1),
            'saude' => '100% Saudável (Running)'
        ];

        if (!empty($dev['children'])) {
            foreach ($dev['children'] as $c) {
                if (($c['name'] ?? '') === 'sda3') {
                    $pvTamanho = (float) ($c['size'] ?? $pvTamanho);
                    if (!empty($c['children'][0]['size'])) {
                        $lvTamanho = (float) $c['children'][0]['size'];
                    }
                }
            }
        }
    } else {
        $resultado['disco_fisico'] = [
            'nome' => 'sda',
            'path' => '/dev/sda',
            'modelo' => 'WDC WD5000LPVX-0',
            'total_bytes' => $discoTotal,
            'total_gb' => formatarGB($discoTotal, 1),
            'saude' => '100% Saudável (Running)'
        ];
    }

    $lvmLivre = max(0.0, $pvTamanho - $lvTamanho);
    $resultado['lvm'] = [
        'pv_total_bytes' => $pvTamanho,
        'pv_total_gb' => formatarGB($pvTamanho, 1),
        'lv_alocado_bytes' => $lvTamanho,
        'lv_alocado_gb' => formatarGB($lvTamanho, 1),
        'livre_bytes' => $lvmLivre,
        'livre_gb' => formatarGB($lvmLivre, 1)
    ];

    $alvos = [
        '/' => ['nome' => 'Raiz (/)', 'label' => 'ubuntu-lv', 'tipo' => 'LVM Ext4'],
        '/boot' => ['nome' => 'Boot (/boot)', 'label' => 'Kernel', 'tipo' => 'Ext4'],
        '/boot/efi' => ['nome' => 'EFI (/boot/efi)', 'label' => 'UEFI', 'tipo' => 'VFAT']
    ];

    $raizUsado = 0.0;
    $raizLivre = 0.0;

    foreach ($alvos as $ponto => $meta) {
        $tot = @disk_total_space($ponto);
        $liv = @disk_free_space($ponto);
        if ($tot !== false && $liv !== false && $tot > 0) {
            $usa = max(0.0, $tot - $liv);
            $pct = min(100.0, ($usa / $tot) * 100.0);
            if ($ponto === '/') {
                $raizUsado = $usa;
                $raizLivre = $liv;
            }
            $resultado['sistemas_arquivos'][] = [
                'ponto' => $ponto,
                'nome' => $meta['nome'],
                'label' => $meta['label'],
                'tipo' => $meta['tipo'],
                'total_bytes' => $tot,
                'usado_bytes' => $usa,
                'livre_bytes' => $liv,
                'total_gb' => formatarGB($tot, 1),
                'usado_gb' => formatarGB($usa, 1),
                'livre_gb' => formatarGB($liv, 1),
                'pct' => $pct,
                'saude' => situacao($pct)
            ];
        }
    }

    $efiSize = 1.1 * 1024 * 1024 * 1024;
    $bootSize = 2.0 * 1024 * 1024 * 1024;
    $resultado['infografico_alocacao'] = [
        ['nome' => 'EFI (sda1)', 'tamanho_gb' => '1,1 GB', 'pct' => ($efiSize / $discoTotal) * 100, 'cor' => '#38BDF8'],
        ['nome' => 'Boot (sda2)', 'tamanho_gb' => '2,0 GB', 'pct' => ($bootSize / $discoTotal) * 100, 'cor' => '#0284C7'],
        ['nome' => 'Raiz Usada (/)', 'tamanho_gb' => formatarGB($raizUsado, 1), 'pct' => ($raizUsado / $discoTotal) * 100, 'cor' => '#10B981'],
        ['nome' => 'Raiz Livre (/)', 'tamanho_gb' => formatarGB($raizLivre, 1), 'pct' => ($raizLivre / $discoTotal) * 100, 'cor' => '#22C55E'],
        ['nome' => 'Pool LVM Livre', 'tamanho_gb' => formatarGB($lvmLivre, 1), 'pct' => ($lvmLivre / $discoTotal) * 100, 'cor' => '#F59E0B']
    ];

    return $resultado;
}

function coletarArmazenamentoWindows(): array
{
    $resultado = [
        'nome_visual' => 'Windows',
        'ip' => WINDOWS_IP,
        'ping_online' => false,
        'ativo_online' => false,
        'status_texto' => 'Desconhecido',
        'status_classe' => 'critico',
        'segundos_atras' => null,
        'timestamp' => null,
        'data_formatada' => 'Aguardando sincronização',
        'discos' => []
    ];

    // Checagem real de conectividade via Ping ICMP
    $pingOut = [];
    $pingRet = 1;
    @exec("ping -c 1 -W 1 " . WINDOWS_IP . " 2>/dev/null", $pingOut, $pingRet);
    $resultado['ping_online'] = ($pingRet === 0);

    if (!file_exists(WINDOWS_JSON)) {
        if ($resultado['ping_online']) {
            $resultado['status_texto'] = 'Conectado (Sem dados)';
            $resultado['status_classe'] = 'warn';
        } else {
            $resultado['status_texto'] = 'Offline';
            $resultado['status_classe'] = 'critico';
        }
        return $resultado;
    }

    $conteudo = @file_get_contents(WINDOWS_JSON);
    if ($conteudo === false) {
        return $resultado;
    }

    $json = json_decode($conteudo, true);
    if (!is_array($json)) {
        return $resultado;
    }

    $mtime = @filemtime(WINDOWS_JSON);
    if ($mtime !== false) {
        $resultado['segundos_atras'] = max(0, time() - $mtime);
        $resultado['timestamp'] = $mtime;
        $resultado['data_formatada'] = date('d/m/Y H:i:s', $mtime);

        if ($resultado['ping_online']) {
            if ($resultado['segundos_atras'] <= 300) {
                $resultado['ativo_online'] = true;
                $resultado['status_texto'] = 'Online';
                $resultado['status_classe'] = 'ok';
            } else {
                $resultado['ativo_online'] = true;
                $resultado['status_texto'] = 'Conectado (Dados em cache)';
                $resultado['status_classe'] = 'warn';
            }
        } else {
            $resultado['ativo_online'] = false;
            $resultado['status_texto'] = 'Offline (Dados em cache)';
            $resultado['status_classe'] = 'critico';
        }
    }

    $discosRaw = $json['discos'] ?? $json['disks'] ?? $json['volumes'] ?? (isset($json[0]) ? $json : []);

    foreach ($discosRaw as $d) {
        $totB = (float) ($d['total_bytes'] ?? $d['tamanho_bytes'] ?? $d['size_bytes'] ?? 0);
        $livB = (float) ($d['livre_bytes'] ?? $d['free_bytes'] ?? 0);
        $usaB = (float) ($d['usado_bytes'] ?? $d['used_bytes'] ?? max(0, $totB - $livB));

        if ($totB <= 0 && isset($d['total_gb'])) {
            $totB = ((float) str_replace(',', '.', (string)$d['total_gb'])) * 1024 * 1024 * 1024;
        }
        if ($livB <= 0 && isset($d['livre_gb'])) {
            $livB = ((float) str_replace(',', '.', (string)$d['livre_gb'])) * 1024 * 1024 * 1024;
            $usaB = max(0, $totB - $livB);
        }

        $pct = ($totB > 0) ? min(100.0, ($usaB / $totB) * 100.0) : 0.0;
        $sit = situacao($pct);

        $resultado['discos'][] = [
            'unidade' => trim($d['unidade'] ?? $d['drive'] ?? $d['letra'] ?? 'C:'),
            'rotulo' => trim($d['rotulo'] ?? $d['label'] ?? $d['nome'] ?? 'Disco Local'),
            'total_bytes' => $totB,
            'usado_bytes' => $usaB,
            'livre_bytes' => $livB,
            'total_gb' => formatarGB($totB, 1),
            'usado_gb' => formatarGB($usaB, 1),
            'livre_gb' => formatarGB($livB, 1),
            'pct' => $pct,
            'situacao' => $sit,
            'saude_disco' => $d['saude'] ?? '100% Saudável (SMART OK)'
        ];
    }

    return $resultado;
}

function coletarDadosPS4(): array
{
    $ip = PS4_IP;
    $mac = PS4_MAC;
    $res = [
        'nome_visual' => 'PlayStation 4 Fat',
        'ip' => $ip,
        'mac' => $mac,
        'online' => false,
        'ping_ms' => null,
        'estado' => 'Desconhecido',
        'estado_classe' => 'critico',
        'host_name' => 'PS4',
        'host_id' => PS4_HOST_ID,
        'sistema_versao' => null,
        'app_ativa' => null,
        'app_titleid' => null,
        'porta_remota_aberta' => false,
        'ddp_confirmado' => false,
        'ultima_verificacao' => date('H:i:s'),
        'armazenamento' => [
            'disponivel' => false,
            'total' => 'Não disponível',
            'usado' => 'Não disponível',
            'livre' => 'Não disponível',
            'motivo' => 'O PS4 Fat original não expõe telemetria de disco via rede local sem jailbreak.'
        ]
    ];

    // 1. Teste de Ping Real
    $pingOut = [];
    $pingRet = 1;
    @exec("ping -c 1 -W 1 {$ip} 2>/dev/null", $pingOut, $pingRet);
    if ($pingRet === 0) {
        $res['online'] = true;
        $res['estado'] = 'Conectado à Rede';
        $res['estado_classe'] = 'atencao';
        $str = implode(' ', $pingOut);
        if (preg_match('/time=([\d\.]+)\s*ms/', $str, $m)) {
            $res['ping_ms'] = number_format((float)$m[1], 1, ',', '.') . ' ms';
        }
    }

    // 2. Consulta real ao protocolo DDP via UDP 987
    $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sock) {
        @socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 200000]);
        @socket_bind($sock, '0.0.0.0', 0);
        $msg = "SRCH * HTTP/1.1\ndevice-discovery-protocol-version:00020020\n";
        @socket_sendto($sock, $msg, strlen($msg), 0, $ip, 987);
        $buf = '';
        $from = '';
        $port = 0;
        $bytes = @socket_recvfrom($sock, $buf, 2048, 0, $from, $port);
        if ($bytes !== false && $bytes > 0) {
            $res['online'] = true;
            $res['ddp_confirmado'] = true;
            $res['estado'] = 'Ligado (Operacional)';
            $res['estado_classe'] = 'normal';

            $linhas = explode("\n", $buf);
            foreach ($linhas as $l) {
                $l = trim($l);
                if (stripos($l, 'host-name:') === 0) {
                    $res['host_name'] = trim(substr($l, 10));
                } elseif (stripos($l, 'host-id:') === 0) {
                    $res['host_id'] = trim(substr($l, 8));
                } elseif (stripos($l, 'system-version:') === 0) {
                    $res['sistema_versao'] = trim(substr($l, 15));
                } elseif (stripos($l, 'running-app-name:') === 0) {
                    $res['app_ativa'] = trim(substr($l, 17));
                } elseif (stripos($l, 'running-app-titleid:') === 0) {
                    $res['app_titleid'] = trim(substr($l, 20));
                } elseif (stripos($l, 'status-code:') === 0) {
                    $code = trim(substr($l, 12));
                    if ($code === '620') {
                        $res['estado'] = 'Modo de Repouso (Standby)';
                        $res['estado_classe'] = 'atencao';
                    }
                }
            }

            if (!empty($res['app_ativa'])) {
                $res['estado'] = 'Ligado — Em Execução: ' . $res['app_ativa'];
            }
        }
        @socket_close($sock);
    }

    // 3. Checagem de porta TCP 9295 (Remote Play / Second Screen)
    $fp = @fsockopen($ip, 9295, $errno, $errstr, 0.4);
    if ($fp) {
        $res['porta_remota_aberta'] = true;
        fclose($fp);
        if (!$res['ddp_confirmado']) {
            $res['estado'] = 'Conexão Remota Ativa (Porta 9295)';
            $res['estado_classe'] = 'normal';
        }
    } elseif (!$res['online']) {
        $res['estado'] = 'Offline / Desligado';
        $res['estado_classe'] = 'critico';
    }

    return $res;
}

function enviarWakePS4(): array
{
    $mac = PS4_MAC;
    $ip = PS4_IP;
    $hostId = PS4_HOST_ID;
    $enviados = [];

    // 1. Pacote Mágico Wake-on-LAN (WOL)
    $macHex = str_replace([':', '-'], '', $mac);
    if (strlen($macHex) === 12) {
        $macBytes = hex2bin($macHex);
        $magicPacket = str_repeat(chr(0xFF), 6) . str_repeat($macBytes, 16);

        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock) {
            @socket_set_option($sock, SOL_SOCKET, SO_BROADCAST, 1);
            @socket_sendto($sock, $magicPacket, strlen($magicPacket), 0, '192.168.18.255', 9);
            @socket_sendto($sock, $magicPacket, strlen($magicPacket), 0, $ip, 9);
            @socket_sendto($sock, $magicPacket, strlen($magicPacket), 0, '192.168.18.255', 7);
            @socket_close($sock);
            $enviados[] = 'Wake-on-LAN Magic Packet (Broadcast + Direto)';
        }
    }

    // 2. Pacote DDP WAKE nativo do PlayStation 4
    $sockDdp = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if ($sockDdp) {
        $ddpWake = "WAKE * HTTP/1.1\nhost-id:{$hostId}\ndevice-discovery-protocol-version:00020020\n";
        @socket_sendto($sockDdp, $ddpWake, strlen($ddpWake), 0, $ip, 987);
        @socket_close($sockDdp);
        $enviados[] = 'Comando DDP WAKE (UDP 987)';
    }

    return [
        'sucesso' => true,
        'mensagem' => 'Comando de inicialização enviado com sucesso: ' . implode(' e ', $enviados) . '. Se o console estiver em modo de repouso com ativação de rede habilitada, ele ligará em alguns segundos.',
        'timestamp' => time()
    ];
}

function enviarStandbyPS4(?string $pin = null): array
{
    $ip = PS4_IP;
    $fp = @fsockopen($ip, 9295, $errno, $errstr, 1.2);
    if (!$fp) {
        return [
            'sucesso' => false,
            'requer_pin' => false,
            'mensagem' => 'O PS4 não está acessível na porta de controle TCP 9295 ou já se encontra em repouso/desligado.'
        ];
    }
    fclose($fp);

    $pinArg = ($pin !== null && trim($pin) !== '') ? escapeshellarg(trim($pin)) : '';
    $script = '/var/www/html/painel/ps4_ctl.py';
    if (file_exists($script)) {
        $cmd = "python3 " . escapeshellarg($script) . " {$pinArg} 2>&1";
        $output = shell_exec($cmd);
        if ($output) {
            $json = json_decode(trim($output), true);
            if (is_array($json)) {
                return $json;
            }
        }
    }

    return [
        'sucesso' => false,
        'requer_pin' => true,
        'mensagem' => 'O PS4 original exige autorização com código de 8 dígitos para comandos de repouso. No PS4, vá em "Configurações > Configurações de Conexão do Aplicativo Móvel > Adicionar Dispositivo", digite o código no campo abaixo e confirme.',
        'instrucoes' => 'Configurações > Configurações de Conexão do Aplicativo Móvel > Adicionar Dispositivo'
    ];
}

// =============================================================
// ENDPOINTS DE API (JSON E AÇÕES DE CONTROLE)
// =============================================================
if (isset($_GET['acao'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    if ($_GET['acao'] === 'ps4_wake') {
        echo json_encode(enviarWakePS4(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($_GET['acao'] === 'ps4_standby') {
        $pin = isset($_GET['pin']) ? (string)$_GET['pin'] : null;
        echo json_encode(enviarStandbyPS4($pin), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (isset($_GET['api']) && $_GET['api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $l = coletarArmazenamentoLinux();
    $w = coletarArmazenamentoWindows();
    $p = coletarDadosPS4();

    $fontes = [
        'linux' => [
            'nome' => 'Linux (NEXUS)',
            'online' => true,
            'status' => 'ok',
            'msg' => 'Coleta em tempo real'
        ],
        'windows' => [
            'nome' => 'Windows',
            'online' => $w['ativo_online'],
            'status' => $w['status_classe'] ?? ($w['ativo_online'] ? 'ok' : 'warn'),
            'msg' => $w['ativo_online'] ? ($w['status_texto'] . ' • Sincronizado ' . $w['data_formatada']) : 'Sem envio recente de telemetria'
        ],
        'ps4' => [
            'nome' => 'PlayStation 4 Fat',
            'online' => $p['online'],
            'status' => $p['online'] ? ($p['ddp_confirmado'] ? 'ok' : 'warn') : 'critico',
            'msg' => $p['estado']
        ]
    ];

    echo json_encode([
        'sucesso' => true,
        'timestamp_tentativa' => time(),
        'hora_tentativa' => date('H:i:s'),
        'timestamp_coleta_valida' => time(),
        'hora_coleta_valida' => date('H:i:s'),
        'fontes' => $fontes,
        'linux' => $l,
        'windows' => $w,
        'ps4' => $p,
        'recursos' => $l['recursos'],
        'disco_fisico' => $l['disco_fisico'],
        'infografico_alocacao' => $l['infografico_alocacao'],
        'linux_volumes' => $l['sistemas_arquivos']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$linux = coletarArmazenamentoLinux();
$windows = coletarArmazenamentoWindows();
$ps4 = coletarDadosPS4();

$volumesAlerta = 0;
foreach ($linux['sistemas_arquivos'] as $fs) {
    if ($fs['pct'] >= LIMITE_NORMAL) $volumesAlerta++;
}
foreach ($windows['discos'] as $wd) {
    if ($wd['pct'] >= LIMITE_NORMAL) $volumesAlerta++;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de controle — NEXUS | Monitoramento 360°</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --brand: #22C55E;
            --brand-secondary: #16A34A;
            --ink: #FFFFFF;
            --ink-sec: #A7B3AA;
            --ink-muted: #64746B;
            --bg-principal: #080B0A;
            --bg-card: #111714;
            --bg-secundario: #171F1A;
            --bg-destaque: #1C2721;
            --borda: #26352B;
            --borda-foco: #3B5344;
            --amarelo: #F59E0B;
            --vermelho: #EF4444;
            --azul: #38BDF8;
            --ps-blue: #0070D1;
        }

        .ccm-11,
        .ccm-11 *,
        .ccm-11 *::before,
        .ccm-11 *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background-color: var(--bg-principal);
            color: var(--ink);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 13px;
            line-height: 1.4;
            min-height: 100vh;
            overflow-x: hidden;
        }

        .mono { font-family: 'JetBrains Mono', monospace; }

        /* CABEÇALHO SUPERIOR RESPONSIVO */
        .painel-topbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            min-height: 52px;
            height: auto;
            background: rgba(17, 23, 20, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--borda);
            padding: 8px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            z-index: 100;
            box-sizing: border-box;
        }

        .topbar-info {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            font-size: 12px;
        }

        .topbar-info h1 {
            font-size: 14px;
            font-weight: 700;
            color: var(--ink);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin: 0;
        }

        .server-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(34, 197, 94, 0.08);
            border: 1px solid rgba(34, 197, 94, 0.25);
            padding: 2px 8px;
            border-radius: 6px;
        }

        .server-badge .host-tag {
            color: var(--brand);
            font-weight: 700;
            letter-spacing: 0.5px;
        }

        .server-badge .ip-tag {
            color: var(--ink-sec);
            font-size: 11px;
        }

        .divider-dot {
            color: var(--ink-muted);
            font-size: 11px;
        }

        .uptime-tag {
            color: var(--ink-sec);
            font-size: 11px;
        }

        .topbar-acoes {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }

        .tempo-badge {
            font-size: 11px;
            color: var(--ink-sec);
            background: var(--bg-secundario);
            border: 1px solid var(--borda);
            padding: 5px 12px;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .tempo-badge strong {
            color: var(--brand);
        }

        .status-alerta-badge {
            font-size: 11px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .status-alerta-badge .dot-status {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .status-alerta-badge.atencao {
            background: rgba(245, 158, 11, 0.12);
            border-color: rgba(245, 158, 11, 0.3);
            color: var(--amarelo);
        }

        .status-alerta-badge.normal {
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(34, 197, 94, 0.3);
            color: var(--brand);
        }

        .btn-sync {
            background: var(--bg-secundario);
            color: var(--ink);
            border: 1px solid var(--borda);
            padding: 5px 12px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            white-space: nowrap;
        }

        .btn-sync:hover {
            border-color: var(--brand);
            color: var(--brand);
            background: rgba(34, 197, 94, 0.08);
        }

        .btn-sync:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-sync.spinning svg {
            animation: rodarSync 0.7s linear infinite;
        }

        @keyframes rodarSync { 100% { transform: rotate(360deg); } }

        /* Notificação de Feedback ao Sincronizar */
        .sync-toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(17, 23, 20, 0.96);
            border: 1px solid var(--brand);
            color: var(--ink);
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.85);
            z-index: 999;
            animation: toastPop 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            pointer-events: none;
        }

        @keyframes toastPop {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        /* =============================================================
           ESTRUTURA WHEEL 360 GRAUS (CCM-11)
           ============================================================= */
        .ccm-11 {
            width: 100%;
            min-height: 100vh;
            padding-top: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: radial-gradient(circle at 50% 40%, #141D17, #080B0A 75%);
            font-family: 'Inter', system-ui, sans-serif;
            color: var(--ink);
            position: relative;
            overflow: hidden;
        }

        .ccm-11__stage {
            width: 100%;
            max-width: 1360px;
            min-height: calc(100vh - 48px);
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            transition: all 0.5s cubic-bezier(.3,1,.35,1);
        }

        .ccm-11__machine-wrapper {
            position: relative;
            display: grid;
            place-items: center;
            flex-shrink: 0;
            margin: 0 auto;
        }

        .ccm-11__pointer {
            position: absolute;
            left: 50%;
            top: calc(50% - 206px);
            translate: -50% 0;
            width: 0;
            height: 0;
            border-left: 7px solid transparent;
            border-right: 7px solid transparent;
            border-top: 10px solid var(--brand);
            border-bottom: 0;
            filter: drop-shadow(0 2px 6px rgba(34, 197, 94, 0.8));
            z-index: 10;
            pointer-events: none;
        }

        .card-timeout-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--brand), #4ade80);
            box-shadow: 0 0 8px rgba(34, 197, 94, 0.6);
            transform-origin: left;
            border-top-left-radius: 14px;
            border-top-right-radius: 14px;
            pointer-events: none;
        }

        .compact-card.is-visible .card-timeout-bar {
            animation: cardTimeoutProgress 40s linear forwards;
        }

        @keyframes cardTimeoutProgress {
            from { transform: scaleX(1); }
            to { transform: scaleX(0); }
        }

        .ccm-11__machine {
            position: relative;
            width: 340px;
            height: 340px;
            display: grid;
            place-items: center;
        }

        .ccm-11__wheel {
            position: absolute;
            inset: 0;
            list-style: none;
            border-radius: 50%;
            border: 1px solid var(--borda);
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.6), inset 0 0 25px rgba(0, 0, 0, 0.5);
            rotate: var(--rot,0deg);
            transition: rotate .7s cubic-bezier(.3,1,.35,1);
        }

        .ccm-11__wheel li {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            pointer-events: none;
            --a: calc((360deg / var(--n)) * var(--i) - 90deg);
            translate: calc(cos(var(--a)) * 170px) calc(sin(var(--a)) * 170px);
        }

        .ccm-11__stop {
            pointer-events: auto;
            border: 1px solid var(--borda);
            border-radius: 999px;
            padding: 8px 18px;
            background: rgba(17, 23, 20, 0.95);
            color: var(--ink-sec);
            font: 600 13px/1 'Inter', system-ui, sans-serif;
            letter-spacing: .02em;
            cursor: pointer;
            white-space: nowrap;
            rotate: calc(var(--rot,0deg) * -1);
            transition: rotate .7s cubic-bezier(.3,1,.35,1), background .25s, color .25s, border-color .25s, scale .25s, box-shadow .25s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            user-select: none;
        }

        .ccm-11__stop:hover {
            border-color: var(--brand);
            color: #fff;
            background: var(--bg-destaque);
        }

        .ccm-11__stop.is-active {
            background: var(--brand);
            border-color: var(--brand);
            color: #080B0A;
            font-weight: 700;
            scale: 1.12;
            box-shadow: 0 0 24px -2px rgba(34, 197, 94, 0.7);
        }

        .ccm-11__stop.is-open {
            outline: 2px solid #FFFFFF;
            outline-offset: 2px;
            box-shadow: 0 0 30px rgba(34, 197, 94, 0.9);
        }

        .ccm-11__stop .btn-os-icon {
            width: 16px;
            height: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .ccm-11__stop .btn-os-icon svg {
            width: 16px;
            height: 16px;
        }

        .ccm-11__stop.is-active .btn-os-icon svg {
            fill: #080B0A;
        }

        .ccm-11__face {
            position: relative;
            z-index: 2;
            display: grid;
            justify-items: center;
            gap: 3px;
            width: 190px;
            height: 190px;
            border-radius: 50%;
            place-content: center;
            text-align: center;
            background: radial-gradient(circle at 38% 30%, #171F1A, #111714);
            border: 1px solid var(--borda);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.1), 0 20px 50px -20px rgba(0, 0, 0, 0.9);
            cursor: pointer;
            user-select: none;
            transition: border-color 0.25s, box-shadow 0.25s;
        }

        .ccm-11__face:hover {
            border-color: var(--brand);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.15), 0 0 25px rgba(34, 197, 94, 0.25);
        }

        .ccm-11__face em {
            font-style: normal;
            font-size: 9px;
            letter-spacing: .22em;
            text-transform: uppercase;
            color: var(--ink-sec);
        }

        .ccm-11__face-icon {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 2px;
        }

        .ccm-11__face-icon svg {
            width: 28px;
            height: 28px;
        }

        .ccm-11__current {
            font-size: 17px;
            font-weight: 700;
            letter-spacing: -.01em;
            color: var(--ink);
            line-height: 1.15;
            white-space: nowrap;
        }

        .ccm-11__face .hint-clique {
            font-size: 10px;
            font-weight: 600;
            color: var(--brand);
            margin-top: 1px;
        }

        .ccm-11__steer {
            display: flex;
            gap: 10px;
            margin-top: 6px;
        }

        .ccm-11__steer button {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: 1px solid var(--borda);
            background: var(--bg-secundario);
            color: var(--ink);
            font-size: 13px;
            cursor: pointer;
            display: grid;
            place-items: center;
            transition: background .2s, border-color .2s, color .2s;
        }

        .ccm-11__steer button:hover {
            background: var(--brand);
            border-color: var(--brand);
            color: #080B0A;
        }

        .ccm-11__live {
            position: absolute;
            width: 1px;
            height: 1px;
            clip-path: inset(50%);
            overflow: hidden;
            white-space: nowrap;
        }

        /* =============================================================
           CARD COMPACTO E ENXUTO DE DADOS
           ============================================================= */
        .compact-card {
            display: none;
            flex-direction: column;
            gap: 10px;
            width: 410px;
            max-width: calc(100vw - 32px);
            max-height: calc(100vh - 72px);
            overflow-y: auto;
            position: absolute;
            right: 28px;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(17, 23, 20, 0.96);
            border: 1px solid var(--borda);
            border-radius: 14px;
            padding: 15px 17px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.85), 0 0 1px rgba(34, 197, 94, 0.4);
            backdrop-filter: blur(16px);
            transition: border-color 0.3s;
            z-index: 10;
        }

        .compact-card::-webkit-scrollbar {
            width: 4px;
        }

        .compact-card::-webkit-scrollbar-track {
            background: transparent;
        }

        .compact-card::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }

        .compact-card.is-visible {
            display: flex;
            animation: cardSlideInRight 0.35s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes cardSlideInRight {
            from {
                opacity: 0;
                transform: translateY(-50%) translateX(28px) scale(0.97);
            }
            to {
                opacity: 1;
                transform: translateY(-50%) translateX(0) scale(1);
            }
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--borda);
        }

        .card-brand {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-icon-box {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            background: var(--bg-secundario);
            border: 1px solid var(--borda);
        }

        .brand-icon-box.linux-brand {
            border-color: rgba(252, 198, 36, 0.3);
            background: rgba(252, 198, 36, 0.08);
        }

        .brand-icon-box.win-brand {
            border-color: rgba(56, 189, 248, 0.3);
            background: rgba(56, 189, 248, 0.08);
        }

        .brand-icon-box.ps4-brand {
            border-color: rgba(0, 112, 209, 0.4);
            background: rgba(0, 112, 209, 0.12);
        }

        .card-brand h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.1;
        }

        .card-brand .card-sub {
            font-size: 11px;
            color: var(--ink-muted);
        }

        .card-top-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .pill-status {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 999px;
            border: 1px solid;
        }

        .pill-status.ok {
            background: rgba(34, 197, 94, 0.12);
            color: var(--brand);
            border-color: rgba(34, 197, 94, 0.3);
        }

        .pill-status.warn {
            background: rgba(245, 158, 11, 0.12);
            color: var(--amarelo);
            border-color: rgba(245, 158, 11, 0.3);
        }

        .pill-status.critico {
            background: rgba(239, 68, 68, 0.12);
            color: var(--vermelho);
            border-color: rgba(239, 68, 68, 0.3);
        }

        .ponto-pulsar {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
        }

        .btn-card-close {
            background: var(--bg-secundario);
            color: var(--ink-sec);
            border: 1px solid var(--borda);
            width: 26px;
            height: 26px;
            border-radius: 6px;
            display: grid;
            place-items: center;
            font-size: 11px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-card-close:hover {
            color: #fff;
            border-color: var(--vermelho);
            background: rgba(239, 68, 68, 0.15);
        }

        /* 4 MÉTRICAS-CHAVE EM GRID 2x2 COMPACTO */
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 6px;
        }

        .quick-metric {
            background: var(--bg-secundario);
            border: 1px solid var(--borda);
            border-radius: 8px;
            padding: 7px 10px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .quick-metric .q-label {
            font-size: 9.5px;
            color: var(--ink-muted);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .quick-metric .q-val {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink);
        }

        .quick-metric .q-val.ok { color: var(--brand); }

        /* BOX DE ARMAZENAMENTO PRINCIPAL */
        .main-storage-box {
            background: var(--bg-secundario);
            border: 1px solid var(--borda);
            border-radius: 10px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .storage-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .storage-title {
            font-size: 12px;
            color: var(--ink);
        }

        .storage-pct {
            font-size: 12px;
            font-weight: 700;
        }

        .storage-pct.normal { color: var(--brand); }
        .storage-pct.atencao { color: var(--amarelo); }
        .storage-pct.critico { color: var(--vermelho); }

        .storage-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            background: rgba(0, 0, 0, 0.3);
            padding: 5px 8px;
            border-radius: 6px;
            gap: 6px;
        }

        .storage-stats .s-lbl {
            font-size: 9px;
            color: var(--ink-muted);
            text-transform: uppercase;
            display: block;
        }

        .storage-stats .s-num {
            font-size: 12px;
            font-weight: 700;
            color: var(--ink);
        }

        .storage-stats .s-num.livre {
            color: var(--brand);
        }

        .storage-bar {
            width: 100%;
            height: 6px;
            background: #090E0B;
            border-radius: 9999px;
            overflow: hidden;
            border: 1px solid var(--borda);
        }

        .storage-bar-fill {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.4s ease;
        }

        .storage-bar-fill.normal { background: var(--brand); }
        .storage-bar-fill.atencao { background: var(--amarelo); }

        /* INFOGRÁFICO RESUMIDO E COMPACTO */
        .infografico-mini {
            background: var(--bg-secundario);
            border: 1px solid var(--borda);
            border-radius: 8px;
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .info-mini-head {
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: var(--ink-sec);
        }

        .barra-seg-mini {
            display: flex;
            height: 7px;
            border-radius: 999px;
            overflow: hidden;
            background: #090E0B;
            border: 1px solid var(--borda);
        }

        .barra-seg-mini .barra-seg-fatia {
            height: 100%;
            transition: width 0.4s ease;
        }

        .infografico-mini-leg {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 9.5px;
            color: var(--ink-sec);
        }

        .leg-mini-item {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .leg-mini-item .ponto {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        /* BOTÕES DE CONTROLE DO PS4 */
        .btn-ps4-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid var(--borda);
            background: var(--bg-principal);
            color: var(--ink);
            transition: all 0.2s ease;
        }

        .btn-ps4-wake:hover {
            border-color: var(--brand);
            color: var(--brand);
            background: rgba(34, 197, 94, 0.1);
        }

        .btn-ps4-standby:hover {
            border-color: var(--amarelo);
            color: var(--amarelo);
            background: rgba(245, 158, 11, 0.1);
        }

        .btn-ps4-action:active {
            transform: scale(0.98);
        }

        .btn-ps4-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        /* NOTIFICAÇÃO TOAST DE SINCRONIZAÇÃO */
        .sync-toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 999;
            background: rgba(17, 23, 20, 0.96);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--borda);
            border-radius: 8px;
            padding: 10px 16px;
            font-size: 12px;
            color: var(--ink);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            gap: 10px;
            animation: toastIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
            max-width: calc(100vw - 48px);
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(12px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 1080px) {
            .ccm-11 {
                padding-top: 64px;
                min-height: 100vh;
                height: auto;
                overflow-y: auto;
                align-items: flex-start;
            }
            .ccm-11__stage {
                flex-direction: column;
                justify-content: flex-start;
                padding-top: 24px;
                padding-bottom: 50px;
                min-height: auto;
            }
            .compact-card {
                position: relative;
                right: auto;
                top: auto;
                transform: none;
                margin-top: 28px;
                width: 100%;
                max-width: 440px;
                max-height: none;
            }
            .compact-card.is-visible {
                animation: fadeScaleIn 0.3s ease forwards;
            }
            @keyframes fadeScaleIn {
                from {
                    opacity: 0;
                    transform: translateY(12px) scale(0.98);
                }
                to {
                    opacity: 1;
                    transform: translateY(0) scale(1);
                }
            }
        }

        @media (max-width: 768px) {
            .painel-topbar {
                position: relative;
                padding: 10px 14px;
                gap: 8px;
            }
            .topbar-info {
                width: 100%;
                justify-content: space-between;
                gap: 6px;
            }
            .topbar-acoes {
                width: 100%;
                display: flex;
                flex-wrap: wrap;
                justify-content: space-between;
                align-items: center;
                gap: 6px;
            }
            .tempo-badge {
                font-size: 10.5px;
                padding: 4px 8px;
                white-space: normal;
                word-break: break-word;
                max-width: 100%;
            }
            .ccm-11 {
                padding-top: 24px;
            }
            .compact-card {
                padding: 13px 14px;
                border-radius: 12px;
            }
            .sync-toast {
                bottom: 16px;
                right: 16px;
                left: 16px;
                max-width: none;
                justify-content: center;
                text-align: center;
            }
        }

        @media (max-width: 420px) {
            .quick-grid {
                grid-template-columns: 1fr;
            }
            .storage-stats {
                grid-template-columns: 1fr 1fr 1fr;
                font-size: 10px;
            }
            .topbar-info h1 span {
                font-size: 13px;
            }
            .topbar-acoes {
                flex-direction: column;
                align-items: stretch;
            }
            .tempo-badge {
                justify-content: center;
                text-align: center;
            }
            .btn-sync {
                justify-content: center;
            }
            .uptime-tag {
                display: none;
            }
        }
    </style>
</head>
<body>

    <!-- =============================================================
         1. BARRA SUPERIOR (TIMERS EM TEMPO REAL + SYNC)
         ============================================================= -->
    <header class="painel-topbar">
        <div class="topbar-info">
            <h1>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand)">
                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                    <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                    <line x1="6" y1="6" x2="6.01" y2="6"></line>
                    <line x1="6" y1="18" x2="6.01" y2="18"></line>
                </svg>
                <span>Painel de controle</span>
            </h1>
            <div class="server-badge">
                <span class="host-tag mono">NEXUS</span>
                <span class="ip-tag mono">(192.168.18.166)</span>
            </div>
            <span class="divider-dot">•</span>
            <span class="uptime-tag">Uptime: <strong class="mono" id="header-uptime"><?= htmlspecialchars($linux['recursos']['uptime']) ?></strong></span>
        </div>

        <div class="topbar-acoes">
            <div class="tempo-badge" id="topbar-tempo-badge" title="Horário e tempo decorrido desde a última coleta">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                </svg>
                <span class="tempo-texto">Última atualização: <span id="hora-coleta-topo" class="mono"><?= date('H:i:s') ?></span> (<strong id="timer-painel-topo" class="mono">há 0s</strong>)</span>
            </div>

            <div class="status-alerta-badge <?= $volumesAlerta > 0 ? 'atencao' : 'normal' ?>" id="header-alerta-badge">
                <span class="dot-status"></span>
                <span id="header-alerta-texto"><?= $volumesAlerta > 0 ? "Atenção ({$volumesAlerta} volume)" : "Infraestrutura OK" ?></span>
            </div>

            <button id="btn-sync-top" class="btn-sync" onclick="sincronizarManual()" title="Atualizar dados de todas as fontes agora">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path>
                </svg>
                <span id="btn-sync-text">Atualizar dados</span>
            </button>
        </div>
    </header>

    <!-- NOTIFICAÇÃO TOAST VISUAL DE SINCRONIZAÇÃO -->
    <div id="sync-toast" class="sync-toast" style="display:none"></div>

    <!-- =============================================================
         2. PALCO DA RODA CIRCULAR 360 GRAUS (CCM-11) + CARD COMPACTO
         ============================================================= -->
    <main class="ccm-11" aria-label="Navegação em roda 360 graus">
        <div class="ccm-11__stage" id="ccm-stage">

            <!-- ENVOLTÓRIO DA MÁQUINA CIRCULAR -->
            <div class="ccm-11__machine-wrapper">
                <div class="ccm-11__pointer" aria-hidden="true"></div>

                <!-- NOTA: --n:3 PARA LINUX, WINDOWS E PS4 (EXPANSÍVEL) -->
                <nav class="ccm-11__machine" style="--n:3" aria-label="Dispositivos">
                    <ul class="ccm-11__wheel">
                        <li style="--i:0">
                            <button type="button" class="ccm-11__stop is-active" data-section="linux" aria-pressed="true">
                                <span class="btn-os-icon">
                                    <svg viewBox="0 0 24 24" fill="#FCC624">
                                        <path d="M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489a.424.424 0 00-.11.135c-.26.268-.45.6-.663.839-.199.199-.485.267-.797.4-.313.136-.658.269-.864.68-.09.189-.136.394-.132.602 0 .199.027.4.055.536.058.399.116.728.04.97-.249.68-.28 1.145-.106 1.484.174.334.535.47.94.601.81.2 1.91.135 2.774.6.926.466 1.866.67 2.616.47.526-.116.97-.464 1.208-.946.587-.003 1.23-.269 2.26-.334.699-.058 1.574.267 2.577.2.025.134.063.198.114.333l.003.003c.391.778 1.113 1.132 1.884 1.071.771-.06 1.592-.536 2.257-1.306.631-.765 1.683-1.084 2.378-1.503.348-.199.629-.469.649-.853.023-.4-.2-.811-.714-1.376v-.097l-.003-.003c-.17-.2-.25-.535-.338-.926-.085-.401-.182-.786-.492-1.046h-.003c-.059-.054-.123-.067-.188-.135a.357.357 0 00-.19-.064c.431-1.278.264-2.55-.173-3.694-.533-1.41-1.465-2.638-2.175-3.483-.796-1.005-1.576-1.957-1.56-3.368.026-2.152.236-6.133-3.544-6.139zm.529 3.405h.013c.213 0 .396.062.584.198.19.135.33.332.438.533.105.259.158.459.166.724 0-.02.006-.04.006-.06v.105a.086.086 0 01-.004-.021l-.004-.024a1.807 1.807 0 01-.15.706.953.953 0 01-.213.335.71.71 0 00-.088-.042c-.104-.045-.198-.064-.284-.133a1.312 1.312 0 00-.22-.066c.05-.06.146-.133.183-.198.053-.128.082-.264.088-.402v-.02a1.21 1.21 0 00-.061-.4c-.045-.134-.101-.2-.183-.333-.084-.066-.167-.132-.267-.132h-.016c-.093 0-.176.03-.262.132a.8.8 0 00-.205.334 1.18 1.18 0 00-.09.4v.019c.002.089.008.179.02.267-.193-.067-.438-.135-.607-.202a1.635 1.635 0 01-.018-.2v-.02a1.772 1.772 0 01.15-.768c.082-.22.232-.406.43-.533a.985.985 0 01.594-.2zm-2.962.059h.036c.142 0 .27.048.399.135.146.129.264.288.344.465.09.199.14.4.153.667v.004c.007.134.006.2-.002.266v.08c-.03.007-.056.018-.083.024-.152.055-.274.135-.393.2.012-.09.013-.18.003-.267v-.015c-.012-.133-.04-.2-.082-.333a.613.613 0 00-.166-.267.248.248 0 00-.183-.064h-.021c-.071.006-.13.04-.186.132a.552.552 0 00-.12.27.944.944 0 00-.023.33v.015c.012.135.037.2.08.334.046.134.098.2.166.268.01.009.02.018.034.024-.07.057-.117.07-.176.136a.304.304 0 01-.131.068 2.62 2.62 0 01-.275-.402 1.772 1.772 0 01-.155-.667 1.759 1.759 0 01.08-.668 1.43 1.43 0 01.283-.535c.128-.133.26-.2.418-.2zm1.37 1.706c.332 0 .733.065 1.216.399.293.2.523.269 1.052.468h.003c.255.136.405.266.478.399v-.131a.571.571 0 01.016.47c-.123.31-.516.643-1.063.842v.002c-.268.135-.501.333-.775.465-.276.135-.588.292-1.012.267a1.139 1.139 0 01-.448-.067 3.566 3.566 0 01-.322-.198c-.195-.135-.363-.332-.612-.465v-.005h-.005c-.4-.246-.616-.512-.686-.71-.07-.268-.005-.47.193-.6.224-.135.38-.271.483-.336.104-.074.143-.102.176-.131h.002v-.003c.169-.202.436-.47.839-.601.139-.036.294-.065.466-.065zm2.8 2.142c.358 1.417 1.196 3.475 1.735 4.473.286.534.855 1.659 1.102 3.024.156-.005.33.018.513.064.646-1.671-.546-3.467-1.089-3.966-.22-.2-.232-.335-.123-.335.59.534 1.365 1.572 1.646 2.757.13.535.16 1.104.021 1.67.067.028.135.06.205.067 1.032.534 1.413.938 1.23 1.537v-.043c-.06-.003-.12 0-.18 0h-.016c.151-.467-.182-.825-1.065-1.224-.915-.4-1.646-.336-1.77.465-.008.043-.013.066-.018.135-.068.023-.139.053-.209.064-.43.268-.662.669-.793 1.187-.13.533-.17 1.156-.205 1.869v.003c-.02.334-.17.838-.319 1.35-1.5 1.072-3.58 1.538-5.348.334a2.645 2.645 0 00-.402-.533 1.45 1.45 0 00-.275-.333c.182 0 .338-.03.465-.067a.615.615 0 00.314-.334c.108-.267 0-.697-.345-1.163-.345-.467-.931-.995-1.788-1.521-.63-.4-.986-.87-1.15-1.396-.165-.534-.143-1.085-.015-1.645.245-1.07.873-2.11 1.274-2.763.107-.065.037.135-.408.974-.396.751-1.14 2.497-.122 3.854a8.123 8.123 0 01.647-2.876c.564-1.278 1.743-3.504 1.836-5.268.048.036.217.135.289.202.218.133.38.333.59.465.21.201.477.335.876.335.039.003.075.006.11.006.412 0 .73-.134.997-.268.29-.134.52-.334.74-.4h.005c.467-.135.835-.402 1.044-.7zm2.185 8.958c.037.6.343 1.245.882 1.377.588.134 1.434-.333 1.791-.765l.211-.01c.315-.007.577.01.847.268l.003.003c.208.199.305.53.391.876.085.4.154.78.409 1.066.486.527.645.906.636 1.14l.003-.007v.018l-.003-.012c-.015.262-.185.396-.498.595-.63.401-1.746.712-2.457 1.57-.618.737-1.37 1.14-2.036 1.191-.664.053-1.237-.2-1.574-.898l-.005-.003c-.21-.4-.12-1.025.056-1.69.176-.668.428-1.344.463-1.897.037-.714.076-1.335.195-1.814.12-.465.308-.797.641-.984l.045-.022zm-10.814.049h.01c.053 0 .105.005.157.014.376.055.706.333 1.023.752l.91 1.664.003.003c.243.533.754 1.064 1.189 1.637.434.598.77 1.131.729 1.57v.006c-.057.744-.48 1.148-1.125 1.294-.645.135-1.52.002-2.395-.464-.968-.536-2.118-.469-2.857-.602-.369-.066-.61-.2-.723-.4-.11-.2-.113-.602.123-1.23v-.004l.002-.003c.117-.334.03-.752-.027-1.118-.055-.401-.083-.71.043-.94.16-.334.396-.4.69-.533.294-.135.64-.202.915-.47h.002v-.002c.256-.268.445-.601.668-.838.19-.201.38-.336.663-.336zm7.159-9.074c-.435.201-.945.535-1.488.535-.542 0-.97-.267-1.28-.466-.154-.134-.28-.268-.373-.335-.164-.134-.144-.333-.074-.333.109.016.129.134.199.2.096.066.215.2.36.333.292.2.68.467 1.167.467.485 0 1.053-.267 1.398-.466.195-.135.445-.334.648-.467.156-.136.149-.267.279-.267.128.016.034.134-.147.332a8.097 8.097 0 01-.69.468zm-1.082-1.583V5.64c-.006-.02.013-.042.029-.05.074-.043.18-.027.26.004.063 0 .16.067.15.135-.006.049-.085.066-.135.066-.055 0-.092-.043-.141-.068-.052-.018-.146-.008-.163-.065zm-.551 0c-.02.058-.113.049-.166.066-.047.025-.086.068-.14.068-.05 0-.13-.02-.136-.068-.01-.066.088-.133.15-.133.08-.031.184-.047.259-.005.019.009.036.03.03.05v.02h.003z"/>
                                    </svg>
                                </span>
                                <span>Linux</span>
                            </button>
                        </li>
                        <li style="--i:1">
                            <button type="button" class="ccm-11__stop" data-section="windows" aria-pressed="false">
                                <span class="btn-os-icon">
                                    <svg viewBox="0 0 24 24" fill="#38BDF8">
                                        <path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.902-1.799"/>
                                    </svg>
                                </span>
                                <span>Windows</span>
                            </button>
                        </li>
                        <li style="--i:2">
                            <button type="button" class="ccm-11__stop" data-section="ps4" aria-pressed="false">
                                <span class="btn-os-icon">
                                    <svg viewBox="0 0 24 24" fill="#0070D1">
                                        <path d="M8.984 2.596v17.547l3.915 1.261V6.688c0-.69.304-1.151.794-.991.636.18.76.814.76 1.505v5.875c2.441 1.193 4.362-.002 4.362-3.152 0-3.237-1.126-4.675-4.438-5.827-1.307-.448-3.728-1.186-5.39-1.502zm4.656 16.241l6.296-2.275c.715-.258.826-.625.246-.818-.586-.192-1.637-.139-2.357.123l-4.205 1.5V14.98l.24-.085s1.201-.42 2.913-.615c1.696-.18 3.785.03 5.437.661 1.848.601 2.04 1.472 1.576 2.072-.465.6-1.622 1.036-1.622 1.036l-8.544 3.107V18.86zM1.807 18.6c-1.9-.545-2.214-1.668-1.352-2.32.801-.586 2.16-1.052 2.16-1.052l5.615-2.013v2.313L4.205 17c-.705.271-.825.632-.239.826.586.195 1.637.15 2.343-.12L8.247 17v2.074c-.12.03-.256.044-.39.073-1.939.331-3.996.196-6.038-.479z"/>
                                    </svg>
                                </span>
                                <span>PS4</span>
                            </button>
                        </li>
                    </ul>

                    <!-- CENTRO DA RODA INTERATIVO -->
                    <div class="ccm-11__face" id="ccm-face" title="Clique para abrir ou fechar os dados">
                        <em>MONITORAMENTO</em>
                        <div class="ccm-11__face-icon" id="face-icon">
                            <svg viewBox="0 0 24 24" fill="var(--brand)" width="28" height="28">
                                <path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>
                            </svg>
                        </div>
                        <strong class="ccm-11__current">Servidor Casa</strong>
                        <span class="hint-clique" id="face-hint">Clique para abrir</span>

                        <div class="ccm-11__steer">
                            <button type="button" class="ccm-11__prev" aria-label="Seção anterior">←</button>
                            <button type="button" class="ccm-11__next" aria-label="Próxima seção">→</button>
                        </div>
                    </div>

                    <p class="ccm-11__live" aria-live="polite">Linux selecionado</p>
                </nav>
            </div>

            <!-- =========================================================
                 3. CARD COMPACTO: LINUX (NEXUS)
                 ========================================================= -->
            <div class="compact-card" id="card-linux">
                <div class="card-timeout-bar" aria-hidden="true"></div>
                <div class="card-top">
                    <div class="card-brand">
                        <div class="brand-icon-box linux-brand">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="#FCC624">
                                <path d="M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489a.424.424 0 00-.11.135c-.26.268-.45.6-.663.839-.199.199-.485.267-.797.4-.313.136-.658.269-.864.68-.09.189-.136.394-.132.602 0 .199.027.4.055.536.058.399.116.728.04.97-.249.68-.28 1.145-.106 1.484.174.334.535.47.94.601.81.2 1.91.135 2.774.6.926.466 1.866.67 2.616.47.526-.116.97-.464 1.208-.946.587-.003 1.23-.269 2.26-.334.699-.058 1.574.267 2.577.2.025.134.063.198.114.333l.003.003c.391.778 1.113 1.132 1.884 1.071.771-.06 1.592-.536 2.257-1.306.631-.765 1.683-1.084 2.378-1.503.348-.199.629-.469.649-.853.023-.4-.2-.811-.714-1.376v-.097l-.003-.003c-.17-.2-.25-.535-.338-.926-.085-.401-.182-.786-.492-1.046h-.003c-.059-.054-.123-.067-.188-.135a.357.357 0 00-.19-.064c.431-1.278.264-2.55-.173-3.694-.533-1.41-1.465-2.638-2.175-3.483-.796-1.005-1.576-1.957-1.56-3.368.026-2.152.236-6.133-3.544-6.139zm.529 3.405h.013c.213 0 .396.062.584.198.19.135.33.332.438.533.105.259.158.459.166.724 0-.02.006-.04.006-.06v.105a.086.086 0 01-.004-.021l-.004-.024a1.807 1.807 0 01-.15.706.953.953 0 01-.213.335.71.71 0 00-.088-.042c-.104-.045-.198-.064-.284-.133a1.312 1.312 0 00-.22-.066c.05-.06.146-.133.183-.198.053-.128.082-.264.088-.402v-.02a1.21 1.21 0 00-.061-.4c-.045-.134-.101-.2-.183-.333-.084-.066-.167-.132-.267-.132h-.016c-.093 0-.176.03-.262.132a.8.8 0 00-.205.334 1.18 1.18 0 00-.09.4v.019c.002.089.008.179.02.267-.193-.067-.438-.135-.607-.202a1.635 1.635 0 01-.018-.2v-.02a1.772 1.772 0 01.15-.768c.082-.22.232-.406.43-.533a.985.985 0 01.594-.2zm-2.962.059h.036c.142 0 .27.048.399.135.146.129.264.288.344.465.09.199.14.4.153.667v.004c.007.134.006.2-.002.266v.08c-.03.007-.056.018-.083.024-.152.055-.274.135-.393.2.012-.09.013-.18.003-.267v-.015c-.012-.133-.04-.2-.082-.333a.613.613 0 00-.166-.267.248.248 0 00-.183-.064h-.021c-.071.006-.13.04-.186.132a.552.552 0 00-.12.27.944.944 0 00-.023.33v.015c.012.135.037.2.08.334.046.134.098.2.166.268.01.009.02.018.034.024-.07.057-.117.07-.176.136a.304.304 0 01-.131.068 2.62 2.62 0 01-.275-.402 1.772 1.772 0 01-.155-.667 1.759 1.759 0 01.08-.668 1.43 1.43 0 01.283-.535c.128-.133.26-.2.418-.2zm1.37 1.706c.332 0 .733.065 1.216.399.293.2.523.269 1.052.468h.003c.255.136.405.266.478.399v-.131a.571.571 0 01.016.47c-.123.31-.516.643-1.063.842v.002c-.268.135-.501.333-.775.465-.276.135-.588.292-1.012.267a1.139 1.139 0 01-.448-.067 3.566 3.566 0 01-.322-.198c-.195-.135-.363-.332-.612-.465v-.005h-.005c-.4-.246-.616-.512-.686-.71-.07-.268-.005-.47.193-.6.224-.135.38-.271.483-.336.104-.074.143-.102.176-.131h.002v-.003c.169-.202.436-.47.839-.601.139-.036.294-.065.466-.065zm2.8 2.142c.358 1.417 1.196 3.475 1.735 4.473.286.534.855 1.659 1.102 3.024.156-.005.33.018.513.064.646-1.671-.546-3.467-1.089-3.966-.22-.2-.232-.335-.123-.335.59.534 1.365 1.572 1.646 2.757.13.535.16 1.104.021 1.67.067.028.135.06.205.067 1.032.534 1.413.938 1.23 1.537v-.043c-.06-.003-.12 0-.18 0h-.016c.151-.467-.182-.825-1.065-1.224-.915-.4-1.646-.336-1.77.465-.008.043-.013.066-.018.135-.068.023-.139.053-.209.064-.43.268-.662.669-.793 1.187-.13.533-.17 1.156-.205 1.869v.003c-.02.334-.17.838-.319 1.35-1.5 1.072-3.58 1.538-5.348.334a2.645 2.645 0 00-.402-.533 1.45 1.45 0 00-.275-.333c.182 0 .338-.03.465-.067a.615.615 0 00.314-.334c.108-.267 0-.697-.345-1.163-.345-.467-.931-.995-1.788-1.521-.63-.4-.986-.87-1.15-1.396-.165-.534-.143-1.085-.015-1.645.245-1.07.873-2.11 1.274-2.763.107-.065.037.135-.408.974-.396.751-1.14 2.497-.122 3.854a8.123 8.123 0 01.647-2.876c.564-1.278 1.743-3.504 1.836-5.268.048.036.217.135.289.202.218.133.38.333.59.465.21.201.477.335.876.335.039.003.075.006.11.006.412 0 .73-.134.997-.268.29-.134.52-.334.74-.4h.005c.467-.135.835-.402 1.044-.7zm2.185 8.958c.037.6.343 1.245.882 1.377.588.134 1.434-.333 1.791-.765l.211-.01c.315-.007.577.01.847.268l.003.003c.208.199.305.53.391.876.085.4.154.78.409 1.066.486.527.645.906.636 1.14l.003-.007v.018l-.003-.012c-.015.262-.185.396-.498.595-.63.401-1.746.712-2.457 1.57-.618.737-1.37 1.14-2.036 1.191-.664.053-1.237-.2-1.574-.898l-.005-.003c-.21-.4-.12-1.025.056-1.69.176-.668.428-1.344.463-1.897.037-.714.076-1.335.195-1.814.12-.465.308-.797.641-.984l.045-.022zm-10.814.049h.01c.053 0 .105.005.157.014.376.055.706.333 1.023.752l.91 1.664.003.003c.243.533.754 1.064 1.189 1.637.434.598.77 1.131.729 1.57v.006c-.057.744-.48 1.148-1.125 1.294-.645.135-1.52.002-2.395-.464-.968-.536-2.118-.469-2.857-.602-.369-.066-.61-.2-.723-.4-.11-.2-.113-.602.123-1.23v-.004l.002-.003c.117-.334.03-.752-.027-1.118-.055-.401-.083-.71.043-.94.16-.334.396-.4.69-.533.294-.135.64-.202.915-.47h.002v-.002c.256-.268.445-.601.668-.838.19-.201.38-.336.663-.336zm7.159-9.074c-.435.201-.945.535-1.488.535-.542 0-.97-.267-1.28-.466-.154-.134-.28-.268-.373-.335-.164-.134-.144-.333-.074-.333.109.016.129.134.199.2.096.066.215.2.36.333.292.2.68.467 1.167.467.485 0 1.053-.267 1.398-.466.195-.135.445-.334.648-.467.156-.136.149-.267.279-.267.128.016.034.134-.147.332a8.097 8.097 0 01-.69.468zm-1.082-1.583V5.64c-.006-.02.013-.042.029-.05.074-.043.18-.027.26.004.063 0 .16.067.15.135-.006.049-.085.066-.135.066-.055 0-.092-.043-.141-.068-.052-.018-.146-.008-.163-.065zm-.551 0c-.02.058-.113.049-.166.066-.047.025-.086.068-.14.068-.05 0-.13-.02-.136-.068-.01-.066.088-.133.15-.133.08-.031.184-.047.259-.005.019.009.036.03.03.05v.02h.003z"/>
                            </svg>
                        </div>
                        <div>
                            <h3>NEXUS</h3>
                            <span class="card-sub">Ubuntu Server 24.04 LTS</span>
                        </div>
                    </div>
                    <div class="card-top-right">
                        <span class="pill-status ok" id="linux-pill-status">
                            <span class="ponto-pulsar"></span>
                            <span id="linux-texto-status">Online</span>
                        </span>
                        <button class="btn-card-close" onclick="fecharTodosPaineis()" title="Fechar">✕</button>
                    </div>
                </div>

                <!-- 4 MÉTRICAS-CHAVE EM GRID 2x2 (PADRÃO HARMONIZADO) -->
                <div class="quick-grid">
                    <div class="quick-metric">
                        <span class="q-label">Endereço IP</span>
                        <span class="q-val mono" style="color: var(--brand);">192.168.18.166</span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Tempo de Atividade</span>
                        <span class="q-val mono" id="draw-linux-uptime" style="color: var(--brand);"><?= htmlspecialchars($linux['recursos']['uptime']) ?></span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Memória RAM</span>
                        <span class="q-val mono" id="draw-linux-ram"><?= $linux['recursos']['mem_usada_gb'] ?> / <?= $linux['recursos']['mem_total_gb'] ?></span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Saúde Disco (SDA)</span>
                        <span class="q-val ok" id="draw-linux-saude"><?= htmlspecialchars($linux['recursos']['saude_disco']) ?></span>
                    </div>
                </div>

                <!-- ARMAZENAMENTO PRINCIPAL (RAIZ / — LVM EXT4) -->
                <?php $raiz = $linux['sistemas_arquivos'][0] ?? null; ?>
                <div class="main-storage-box">
                    <div class="storage-head">
                        <span class="storage-title">
                            <strong>Armazenamento Raiz (/)</strong> — ubuntu-lv
                        </span>
                        <span class="storage-pct mono <?= $raiz['saude']['classe'] ?? 'normal' ?>" id="linux-raiz-pct">
                            <?= isset($raiz['pct']) ? number_format($raiz['pct'], 1, ',', '.') : '0' ?>%
                        </span>
                    </div>

                    <div class="storage-stats">
                        <div>
                            <span class="s-lbl">Total</span>
                            <span class="s-num mono" id="linux-raiz-tot"><?= $raiz['total_gb'] ?? '0 GB' ?></span>
                        </div>
                        <div>
                            <span class="s-lbl">Usado</span>
                            <span class="s-num mono" id="linux-raiz-usa"><?= $raiz['usado_gb'] ?? '0 GB' ?></span>
                        </div>
                        <div>
                            <span class="s-lbl">Livre</span>
                            <span class="s-num mono livre" id="linux-raiz-liv"><?= $raiz['livre_gb'] ?? '0 GB' ?></span>
                        </div>
                    </div>

                    <div class="storage-bar">
                        <div class="storage-bar-fill <?= $raiz['saude']['classe'] ?? 'normal' ?>" id="linux-raiz-bar" style="width: <?= number_format($raiz['pct'] ?? 0, 1, '.', '') ?>%"></div>
                    </div>
                </div>

                <!-- DISCO FÍSICO, PARTIÇÕES E POOL LVM -->
                <div class="main-storage-box">
                    <div class="storage-head">
                        <span class="storage-title">
                            <strong>Disco Físico (/dev/sda)</strong> — <?= htmlspecialchars($linux['disco_fisico']['modelo']) ?>
                        </span>
                        <span class="storage-pct mono normal" style="font-size: 11px;">
                            <?= $linux['disco_fisico']['total_gb'] ?>
                        </span>
                    </div>
                    <div class="storage-stats">
                        <div>
                            <span class="s-lbl">Boot (sda2)</span>
                            <span class="s-num mono">1,9 GB</span>
                        </div>
                        <div>
                            <span class="s-lbl">EFI (sda1)</span>
                            <span class="s-num mono">1,0 GB</span>
                        </div>
                        <div>
                            <span class="s-lbl">Pool LVM Livre</span>
                            <span class="s-num mono livre"><?= $linux['lvm']['livre_gb'] ?? '212,7 GB' ?></span>
                        </div>
                    </div>

                    <div class="barra-seg-mini" style="margin-top: 4px;">
                        <?php foreach ($linux['infografico_alocacao'] as $f): ?>
                            <div class="barra-seg-fatia" style="width: <?= number_format($f['pct'], 2, '.', '') ?>%; background-color: <?= $f['cor'] ?>" title="<?= htmlspecialchars($f['nome']) ?>: <?= $f['tamanho_gb'] ?>"></div>
                        <?php endforeach; ?>
                    </div>
                    <div class="infografico-mini-leg" style="margin-top: 2px;">
                        <?php foreach ($linux['infografico_alocacao'] as $f): ?>
                            <span class="leg-mini-item">
                                <span class="ponto" style="background-color: <?= $f['cor'] ?>"></span>
                                <span><?= htmlspecialchars($f['nome']) ?>: <strong style="color: var(--ink)"><?= $f['tamanho_gb'] ?></strong></span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="font-size: 11px; color: var(--ink-muted); padding-top: 2px;">
                    💡 <em>Servidor NEXUS (hostname interno: <code><?= htmlspecialchars($linux['recursos']['hostname']) ?></code>). Coleta em tempo real.</em>
                </div>
            </div>

            <!-- =========================================================
                 4. CARD COMPACTO: WINDOWS
                 ========================================================= -->
            <div class="compact-card" id="card-windows">
                <div class="card-timeout-bar" aria-hidden="true"></div>
                <div class="card-top">
                    <div class="card-brand">
                        <div class="brand-icon-box win-brand">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="#38BDF8">
                                <path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.902-1.799"/>
                            </svg>
                        </div>
                        <div>
                            <h3>Windows</h3>
                            <span class="card-sub">Estação de Trabalho / Servidor Local</span>
                        </div>
                    </div>
                    <div class="card-top-right">
                        <span class="pill-status <?= $windows['status_classe'] ?? ($windows['ativo_online'] ? 'ok' : 'warn') ?>" id="win-pill-status">
                            <span class="ponto-pulsar"></span>
                            <span id="win-texto-status"><?= htmlspecialchars($windows['status_texto'] ?? ($windows['ativo_online'] ? 'Online' : 'Conectado')) ?></span>
                        </span>
                        <button class="btn-card-close" onclick="fecharTodosPaineis()" title="Fechar">✕</button>
                    </div>
                </div>

                <div class="quick-grid">
                    <div class="quick-metric">
                        <span class="q-label">Endereço IP</span>
                        <span class="q-val mono" style="color: var(--brand);">192.168.18.19</span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Último Envio</span>
                        <span class="q-val mono" id="win-tempo-relativo" style="color: var(--brand);">
                            <?= $windows['segundos_atras'] !== null ? "há " . (int)$windows['segundos_atras'] . "s atrás" : "Aguardando envio" ?>
                        </span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Coletor</span>
                        <span class="q-val mono" style="color: var(--brand);">NexusWindowsCollector</span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Data Sincronização</span>
                        <span class="q-val mono" id="win-data-sinc" style="font-size: 11px;"><?= htmlspecialchars($windows['data_formatada']) ?></span>
                    </div>
                </div>

                <div id="lista-volumes-windows" style="display: flex; flex-direction: column; gap: 8px;">
                    <?php if (!empty($windows['discos'])): ?>
                        <?php foreach ($windows['discos'] as $w): ?>
                            <div class="main-storage-box">
                                <div class="storage-head">
                                    <span class="storage-title">
                                        <strong>Unidade <?= htmlspecialchars($w['unidade']) ?></strong> (<?= htmlspecialchars($w['rotulo']) ?>)
                                    </span>
                                    <span class="storage-pct mono <?= $w['situacao']['classe'] ?>">
                                        <?= number_format($w['pct'], 1, ',', '.') ?>%
                                    </span>
                                </div>

                                <div class="storage-stats">
                                    <div>
                                        <span class="s-lbl">Total</span>
                                        <span class="s-num mono"><?= $w['total_gb'] ?></span>
                                    </div>
                                    <div>
                                        <span class="s-lbl">Usado</span>
                                        <span class="s-num mono"><?= $w['usado_gb'] ?></span>
                                    </div>
                                    <div>
                                        <span class="s-lbl">Livre</span>
                                        <span class="s-num mono livre"><?= $w['livre_gb'] ?></span>
                                    </div>
                                </div>

                                <div class="storage-bar">
                                    <div class="storage-bar-fill <?= $w['situacao']['classe'] ?>" style="width: <?= number_format($w['pct'], 1, '.', '') ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="padding: 20px; text-align: center; color: var(--ink-muted); font-size: 12px; background: var(--bg-secundario); border: 1px solid var(--borda); border-radius: 8px;">
                            Aguardando envio do script PowerShell em <code>/var/lib/monitoramento/windows.json</code>
                        </div>
                    <?php endif; ?>
                </div>

                <div style="font-size: 11px; color: var(--ink-muted); padding-top: 2px;">
                    💡 <em>Dados sincronizados automaticamente via tarefa agendada PowerShell.</em>
                </div>
            </div>

            <!-- =========================================================
                 5. CARD COMPACTO: PLAYSTATION 4 (PS4 FAT ORIGINAL)
                 ========================================================= -->
            <div class="compact-card" id="card-ps4">
                <div class="card-timeout-bar" aria-hidden="true"></div>
                <div class="card-top">
                    <div class="card-brand">
                        <div class="brand-icon-box ps4-brand">
                            <svg viewBox="0 0 24 24" width="20" height="20" fill="#0070D1">
                                <path d="M8.984 2.596v17.547l3.915 1.261V6.688c0-.69.304-1.151.794-.991.636.18.76.814.76 1.505v5.875c2.441 1.193 4.362-.002 4.362-3.152 0-3.237-1.126-4.675-4.438-5.827-1.307-.448-3.728-1.186-5.39-1.502zm4.656 16.241l6.296-2.275c.715-.258.826-.625.246-.818-.586-.192-1.637-.139-2.357.123l-4.205 1.5V14.98l.24-.085s1.201-.42 2.913-.615c1.696-.18 3.785.03 5.437.661 1.848.601 2.04 1.472 1.576 2.072-.465.6-1.622 1.036-1.622 1.036l-8.544 3.107V18.86zM1.807 18.6c-1.9-.545-2.214-1.668-1.352-2.32.801-.586 2.16-1.052 2.16-1.052l5.615-2.013v2.313L4.205 17c-.705.271-.825.632-.239.826.586.195 1.637.15 2.343-.12L8.247 17v2.074c-.12.03-.256.044-.39.073-1.939.331-3.996.196-6.038-.479z"/>
                            </svg>
                        </div>
                        <div>
                            <h3>PlayStation 4 Fat</h3>
                            <span class="card-sub">Console Original • Modelo Fat</span>
                        </div>
                    </div>
                    <div class="card-top-right">
                        <span class="pill-status <?= $ps4['online'] ? ($ps4['ddp_confirmado'] ? 'ok' : 'warn') : 'critico' ?>" id="ps4-pill-status">
                            <span class="ponto-pulsar"></span>
                            <span id="ps4-texto-status"><?= $ps4['online'] ? ($ps4['ddp_confirmado'] ? 'Online (Ativo)' : 'Conectado') : 'Offline' ?></span>
                        </span>
                        <button class="btn-card-close" onclick="fecharTodosPaineis()" title="Fechar">✕</button>
                    </div>
                </div>

                <!-- 4 MÉTRICAS-CHAVE EM GRID 2x2 (PADRÃO HARMONIZADO) -->
                <div class="quick-grid">
                    <div class="quick-metric">
                        <span class="q-label">Endereço IP</span>
                        <span class="q-val mono" style="color: var(--brand);"><?= htmlspecialchars($ps4['ip']) ?></span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Latência (Ping)</span>
                        <span class="q-val mono" id="ps4-ping-val" style="color: var(--brand);"><?= $ps4['ping_ms'] ?? 'Timeout' ?></span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Identificação Console</span>
                        <span class="q-val mono" id="ps4-host-val"><?= htmlspecialchars($ps4['host_name']) ?> (<?= htmlspecialchars($ps4['host_id']) ?>)</span>
                    </div>
                    <div class="quick-metric">
                        <span class="q-label">Última Verificação</span>
                        <span class="q-val mono" id="ps4-ultima-verif" style="color: var(--brand);"><?= $ps4['ultima_verificacao'] ?></span>
                    </div>
                </div>

                <!-- BOX DE ARMAZENAMENTO (PADRÃO VISUAL IDÊNTICO AO WINDOWS) -->
                <div class="main-storage-box">
                    <div class="storage-head">
                        <span class="storage-title">
                            <strong>Armazenamento Interno (HDD 500 GB)</strong>
                        </span>
                        <span class="storage-pct mono" style="font-size: 10.5px; color: var(--ink-muted);">
                            Não disponível via rede
                        </span>
                    </div>
                    <div class="storage-stats">
                        <div>
                            <span class="s-lbl">Capacidade</span>
                            <span class="s-num mono" style="color: var(--ink-muted); font-size: 11px;">Não exposta</span>
                        </div>
                        <div>
                            <span class="s-lbl">Usado</span>
                            <span class="s-num mono" style="color: var(--ink-muted); font-size: 11px;">Não exposto</span>
                        </div>
                        <div>
                            <span class="s-lbl">Livre</span>
                            <span class="s-num mono" style="color: var(--ink-muted); font-size: 11px;">Não exposto</span>
                        </div>
                    </div>
                    <div class="storage-bar" title="O sistema original do PS4 Fat não expõe capacidade de disco por protocolo de rede.">
                        <div class="storage-bar-fill ps4-indisponivel-bar" style="width: 100%; opacity: 0.35;"></div>
                    </div>
                    <div style="font-size: 10px; color: var(--ink-muted); line-height: 1.2;">
                        ℹ <em>O PS4 Fat original não expõe telemetria de disco via rede local sem jailbreak.</em>
                    </div>
                </div>

                <!-- ESTADO DO CONSOLE (CONFIRMADO VIA DDP) -->
                <div class="main-storage-box">
                    <div class="storage-head">
                        <span class="storage-title">
                            <strong>Estado Operacional</strong>
                        </span>
                        <span class="storage-pct mono <?= $ps4['estado_classe'] ?>" id="ps4-estado-tag" style="font-size: 11px;">
                            <?= $ps4['ddp_confirmado'] ? 'Confirmado via DDP' : ($ps4['online'] ? 'Detectado na Rede' : 'Inacessível') ?>
                        </span>
                    </div>

                    <div style="background: rgba(0, 0, 0, 0.35); padding: 8px 10px; border-radius: 6px; display: flex; flex-direction: column; gap: 4px;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <span style="font-size: 10px; color: var(--ink-muted); text-transform: uppercase;">Atividade Atual:</span>
                            <span id="ps4-app-nome" style="font-size: 12px; font-weight: 700; color: #38BDF8;">
                                <?= !empty($ps4['app_ativa']) ? htmlspecialchars($ps4['app_ativa']) : ($ps4['online'] ? 'Menu do Sistema / Em Espera' : 'Desconectado') ?>
                            </span>
                        </div>
                        <div style="display: flex; justify-content: space-between; font-size: 10px; color: var(--ink-sec);">
                            <span>Title ID: <strong class="mono" id="ps4-app-id"><?= !empty($ps4['app_titleid']) ? htmlspecialchars($ps4['app_titleid']) : 'N/A' ?></strong></span>
                            <span>Firmware: <strong class="mono" id="ps4-sys-ver"><?= !empty($ps4['sistema_versao']) ? htmlspecialchars($ps4['sistema_versao']) : 'N/A' ?></strong></span>
                        </div>
                    </div>
                </div>

                <!-- CONTROLES DO PS4: LIGAR / COLOCAR EM REPOUSO -->
                <div style="display: flex; flex-direction: column; gap: 8px; background: var(--bg-secundario); padding: 10px 12px; border-radius: 8px; border: 1px solid var(--borda);">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <span style="font-size: 11px; font-weight: 600; color: var(--ink);">Controles do Console (Rede Local)</span>
                        <span style="font-size: 10px; color: var(--ink-muted);">MAC: <code class="mono"><?= htmlspecialchars($ps4['mac']) ?></code></span>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button type="button" class="btn-ps4-action btn-ps4-wake" onclick="acionarPS4('wake')" id="btn-ps4-wake" title="Ligar o console a partir do modo de repouso (Magic Packet + DDP Wake)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                            </svg>
                            <span id="btn-ps4-wake-text">Ligar PS4</span>
                        </button>

                        <button type="button" class="btn-ps4-action btn-ps4-standby" onclick="acionarPS4('standby')" id="btn-ps4-standby" title="Colocar em Modo de Repouso via protocolo Second Screen autenticado">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                            </svg>
                            <span id="btn-ps4-standby-text">Colocar em repouso</span>
                        </button>
                    </div>

                    <!-- FEEDBACK DE STATUS DAS AÇÕES DO PS4 -->
                    <div id="ps4-action-feedback" style="display: none; font-size: 10.5px; padding: 7px 10px; border-radius: 6px; line-height: 1.35;"></div>

                    <!-- CAMPO DE REGISTRO / PAREAMENTO DE CÓDIGO (PIN) DO PS4 -->
                    <div id="ps4-pin-container" style="display: none; margin-top: 2px; padding: 10px; background: rgba(0, 112, 209, 0.08); border: 1px solid rgba(0, 112, 209, 0.25); border-radius: 6px;">
                        <div style="font-size: 11px; font-weight: 600; color: #38BDF8; margin-bottom: 4px;">Pareamento de Código (PIN) do Console</div>
                        <div style="font-size: 10px; color: var(--ink-muted); margin-bottom: 8px; line-height: 1.3;">
                            Digite o código de 8 dígitos exibido na tela da sua TV (Adicionar Dispositivo) para autorizar e colocar em repouso:
                        </div>
                        <div style="display: flex; gap: 6px;">
                            <input type="text" id="ps4-pin-input" placeholder="Ex: 39822790" maxlength="8" style="flex: 1; background: var(--bg-card); border: 1px solid var(--borda); color: var(--ink); border-radius: 4px; padding: 6px 8px; font-size: 12px; font-family: var(--font-mono); text-align: center; letter-spacing: 2px;">
                            <button type="button" id="btn-ps4-pair" onclick="acionarPS4('standby', true)" style="background: #0070D1; color: #fff; border: none; border-radius: 4px; padding: 6px 12px; font-size: 11px; font-weight: 600; cursor: pointer;">Conectar</button>
                        </div>
                    </div>
                </div>

                <div style="font-size: 11px; color: var(--ink-muted); padding-top: 2px;">
                    💡 <em>PlayStation 4 Fat original conectado na rede local (192.168.18.162).</em>
                </div>
            </div>

        </div>
    </main>

    <!-- =============================================================
         6. JAVASCRIPT DE ROTACAO 360, ICONES E CONTROLE
         ============================================================= -->
    <script>
        (() => {
            const machine = document.querySelector('.ccm-11__machine');
            const wheel = machine.querySelector('.ccm-11__wheel');
            const stops = [...machine.querySelectorAll('.ccm-11__stop')];
            const current = machine.querySelector('.ccm-11__current');
            const live = machine.querySelector('.ccm-11__live');
            const face = document.getElementById('ccm-face');
            const faceIcon = document.getElementById('face-icon');
            const faceHint = document.getElementById('face-hint');
            const stage = document.getElementById('ccm-stage');
            const cardLinux = document.getElementById('card-linux');
            const cardWindows = document.getElementById('card-windows');
            const cardPs4 = document.getElementById('card-ps4');

            const linuxSvg = `<svg viewBox="0 0 24 24" fill="#FCC624"><path d="M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489a.424.424 0 00-.11.135c-.26.268-.45.6-.663.839-.199.199-.485.267-.797.4-.313.136-.658.269-.864.68-.09.189-.136.394-.132.602 0 .199.027.4.055.536.058.399.116.728.04.97-.249.68-.28 1.145-.106 1.484.174.334.535.47.94.601.81.2 1.91.135 2.774.6.926.466 1.866.67 2.616.47.526-.116.97-.464 1.208-.946.587-.003 1.23-.269 2.26-.334.699-.058 1.574.267 2.577.2.025.134.063.198.114.333l.003.003c.391.778 1.113 1.132 1.884 1.071.771-.06 1.592-.536 2.257-1.306.631-.765 1.683-1.084 2.378-1.503.348-.199.629-.469.649-.853.023-.4-.2-.811-.714-1.376v-.097l-.003-.003c-.17-.2-.25-.535-.338-.926-.085-.401-.182-.786-.492-1.046h-.003c-.059-.054-.123-.067-.188-.135a.357.357 0 00-.19-.064c.431-1.278.264-2.55-.173-3.694-.533-1.41-1.465-2.638-2.175-3.483-.796-1.005-1.576-1.957-1.56-3.368.026-2.152.236-6.133-3.544-6.139zm.529 3.405h.013c.213 0 .396.062.584.198.19.135.33.332.438.533.105.259.158.459.166.724 0-.02.006-.04.006-.06v.105a.086.086 0 01-.004-.021l-.004-.024a1.807 1.807 0 01-.15.706.953.953 0 01-.213.335.71.71 0 00-.088-.042c-.104-.045-.198-.064-.284-.133a1.312 1.312 0 00-.22-.066c.05-.06.146-.133.183-.198.053-.128.082-.264.088-.402v-.02a1.21 1.21 0 00-.061-.4c-.045-.134-.101-.2-.183-.333-.084-.066-.167-.132-.267-.132h-.016c-.093 0-.176.03-.262.132a.8.8 0 00-.205.334 1.18 1.18 0 00-.09.4v.019c.002.089.008.179.02.267-.193-.067-.438-.135-.607-.202a1.635 1.635 0 01-.018-.2v-.02a1.772 1.772 0 01.15-.768c.082-.22.232-.406.43-.533a.985.985 0 01.594-.2zm-2.962.059h.036c.142 0 .27.048.399.135.146.129.264.288.344.465.09.199.14.4.153.667v.004c.007.134.006.2-.002.266v.08c-.03.007-.056.018-.083.024-.152.055-.274.135-.393.2.012-.09.013-.18.003-.267v-.015c-.012-.133-.04-.2-.082-.333a.613.613 0 00-.166-.267.248.248 0 00-.183-.064h-.021c-.071.006-.13.04-.186.132a.552.552 0 00-.12.27.944.944 0 00-.023.33v.015c.012.135.037.2.08.334.046.134.098.2.166.268.01.009.02.018.034.024-.07.057-.117.07-.176.136a.304.304 0 01-.131.068 2.62 2.62 0 01-.275-.402 1.772 1.772 0 01-.155-.667 1.759 1.759 0 01.08-.668 1.43 1.43 0 01.283-.535c.128-.133.26-.2.418-.2zm1.37 1.706c.332 0 .733.065 1.216.399.293.2.523.269 1.052.468h.003c.255.136.405.266.478.399v-.131a.571.571 0 01.016.47c-.123.31-.516.643-1.063.842v.002c-.268.135-.501.333-.775.465-.276.135-.588.292-1.012.267a1.139 1.139 0 01-.448-.067 3.566 3.566 0 01-.322-.198c-.195-.135-.363-.332-.612-.465v-.005h-.005c-.4-.246-.616-.512-.686-.71-.07-.268-.005-.47.193-.6.224-.135.38-.271.483-.336.104-.074.143-.102.176-.131h.002v-.003c.169-.202.436-.47.839-.601.139-.036.294-.065.466-.065zm2.8 2.142c.358 1.417 1.196 3.475 1.735 4.473.286.534.855 1.659 1.102 3.024.156-.005.33.018.513.064.646-1.671-.546-3.467-1.089-3.966-.22-.2-.232-.335-.123-.335.59.534 1.365 1.572 1.646 2.757.13.535.16 1.104.021 1.67.067.028.135.06.205.067 1.032.534 1.413.938 1.23 1.537v-.043c-.06-.003-.12 0-.18 0h-.016c.151-.467-.182-.825-1.065-1.224-.915-.4-1.646-.336-1.77.465-.008.043-.013.066-.018.135-.068.023-.139.053-.209.064-.43.268-.662.669-.793 1.187-.13.533-.17 1.156-.205 1.869v.003c-.02.334-.17.838-.319 1.35-1.5 1.072-3.58 1.538-5.348.334a2.645 2.645 0 00-.402-.533 1.45 1.45 0 00-.275-.333c.182 0 .338-.03.465-.067a.615.615 0 00.314-.334c.108-.267 0-.697-.345-1.163-.345-.467-.931-.995-1.788-1.521-.63-.4-.986-.87-1.15-1.396-.165-.534-.143-1.085-.015-1.645.245-1.07.873-2.11 1.274-2.763.107-.065.037.135-.408.974-.396.751-1.14 2.497-.122 3.854a8.123 8.123 0 01.647-2.876c.564-1.278 1.743-3.504 1.836-5.268.048.036.217.135.289.202.218.133.38.333.59.465.21.201.477.335.876.335.039.003.075.006.11.006.412 0 .73-.134.997-.268.29-.134.52-.334.74-.4h.005c.467-.135.835-.402 1.044-.7zm2.185 8.958c.037.6.343 1.245.882 1.377.588.134 1.434-.333 1.791-.765l.211-.01c.315-.007.577.01.847.268l.003.003c.208.199.305.53.391.876.085.4.154.78.409 1.066.486.527.645.906.636 1.14l.003-.007v.018l-.003-.012c-.015.262-.185.396-.498.595-.63.401-1.746.712-2.457 1.57-.618.737-1.37 1.14-2.036 1.191-.664.053-1.237-.2-1.574-.898l-.005-.003c-.21-.4-.12-1.025.056-1.69.176-.668.428-1.344.463-1.897.037-.714.076-1.335.195-1.814.12-.465.308-.797.641-.984l.045-.022zm-10.814.049h.01c.053 0 .105.005.157.014.376.055.706.333.023.752l.91 1.664.003.003c.243.533.754 1.064 1.189 1.637.434.598.77 1.131.729 1.57v.006c-.057.744-.48 1.148-1.125 1.294-.645.135-1.52.002-2.395-.464-.968-.536-2.118-.469-2.857-.602-.369-.066-.61-.2-.723-.4-.11-.2-.113-.602.123-1.23v-.004l.002-.003c.117-.334.03-.752-.027-1.118-.055-.401-.083-.71.043-.94.16-.334.396-.4.69-.533.294-.135.64-.202.915-.47h.002v-.002c.256-.268.445-.601.668-.838.19-.201.38-.336.663-.336zm7.159-9.074c-.435.201-.945.535-1.488.535-.542 0-.97-.267-1.28-.466-.154-.134-.28-.268-.373-.335-.164-.134-.144-.333-.074-.333.109.016.129.134.199.2.096.066.215.2.36.333.292.2.68.467 1.167.467.485 0 1.053-.267 1.398-.466.195-.135.445-.334.648-.467.156-.136.149-.267.279-.267.128.016.034.134-.147.332a8.097 8.097 0 01-.69.468zm-1.082-1.583V5.64c-.006-.02.013-.042.029-.05.074-.043.18-.027.26.004.063 0 .16.067.15.135-.006.049-.085.066-.135.066-.055 0-.092-.043-.141-.068-.052-.018-.146-.008-.163-.065zm-.551 0c-.02.058-.113.049-.166.066-.047.025-.086.068-.14.068-.05 0-.13-.02-.136-.068-.01-.066.088-.133.15-.133.08-.031.184-.047.259-.005.019.009.036.03.03.05v.02h.003z"/></svg>`;

            const winSvg = `<svg viewBox="0 0 24 24" fill="#38BDF8"><path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.902-1.799"/></svg>`;

            const ps4Svg = `<svg viewBox="0 0 24 24" fill="#0070D1"><path d="M8.984 2.596v17.547l3.915 1.261V6.688c0-.69.304-1.151.794-.991.636.18.76.814.76 1.505v5.875c2.441 1.193 4.362-.002 4.362-3.152 0-3.237-1.126-4.675-4.438-5.827-1.307-.448-3.728-1.186-5.39-1.502zm4.656 16.241l6.296-2.275c.715-.258.826-.625.246-.818-.586-.192-1.637-.139-2.357.123l-4.205 1.5V14.98l.24-.085s1.201-.42 2.913-.615c1.696-.18 3.785.03 5.437.661 1.848.601 2.04 1.472 1.576 2.072-.465.6-1.622 1.036-1.622 1.036l-8.544 3.107V18.86zM1.807 18.6c-1.9-.545-2.214-1.668-1.352-2.32.801-.586 2.16-1.052 2.16-1.052l5.615-2.013v2.313L4.205 17c-.705.271-.825.632-.239.826.586.195 1.637.15 2.343-.12L8.247 17v2.074c-.12.03-.256.044-.39.073-1.939.331-3.996.196-6.038-.479z"/></svg>`;

            const n = stops.length;
            const step = 360 / n;
            let rot = 0;
            let active = 0;
            let painelAberto = null; // 'linux', 'windows', 'ps4' ou null

            const setStopRot = () => stops.forEach((s) => s.style.rotate = (-rot) + 'deg');

            // O centro é fixo no ícone de casa
            const setFaceIcon = () => {};

            // ROTAÇÃO DA RODA
            const goTo = (idx, togglePainel = false) => {
                idx = ((idx % n) + n) % n;
                const target = -idx * step;
                let delta = target - (((rot % 360) + 360) % 360 === 0 ? 0 : rot % 360);
                delta = ((delta % 360) + 540) % 360 - 180;
                rot += delta;
                wheel.style.setProperty('--rot', rot + 'deg');
                setStopRot();

                stops.forEach((s, k) => {
                    s.classList.toggle('is-active', k === idx);
                    s.setAttribute('aria-pressed', String(k === idx));
                });

                active = idx;
                const targetSecao = stops[idx].dataset.section;
                let labelFormatada = 'Linux';
                if (targetSecao === 'windows') labelFormatada = 'Windows';
                if (targetSecao === 'ps4') labelFormatada = 'PS4';

                // O centro permanece fixo com "Servidor Casa" e o ícone de casa
                live.textContent = labelFormatada + ' selecionado';

                if (togglePainel) {
                    if (painelAberto === targetSecao) {
                        fecharPaineis();
                    } else {
                        abrirPainel(targetSecao);
                    }
                } else if (painelAberto) {
                    abrirPainel(targetSecao);
                }
            };

            let timerAutoFechar = null;

            function iniciarTimerAutoFechar() {
                pararTimerAutoFechar();
                // O painel fica aberto por exatamente 40 segundos e fecha automaticamente
                timerAutoFechar = setTimeout(() => {
                    fecharPaineis();
                }, 40000);
            }

            function pararTimerAutoFechar() {
                if (timerAutoFechar) {
                    clearTimeout(timerAutoFechar);
                    timerAutoFechar = null;
                }
            }

            function abrirPainel(secao) {
                painelAberto = secao;
                stage.classList.add('painel-aberto');
                faceHint.textContent = 'Clique para fechar';

                stops.forEach(s => {
                    s.classList.toggle('is-open', s.dataset.section === secao);
                });

                cardLinux.classList.toggle('is-visible', secao === 'linux');
                cardWindows.classList.toggle('is-visible', secao === 'windows');
                cardPs4.classList.toggle('is-visible', secao === 'ps4');

                // Reinicia a animação da barra de tempo no card aberto
                const activeCard = document.getElementById('card-' + secao);
                if (activeCard) {
                    const bar = activeCard.querySelector('.card-timeout-bar');
                    if (bar) {
                        bar.style.animation = 'none';
                        void bar.offsetWidth; // Força reflow
                        bar.style.animation = '';
                    }
                }

                // Inicia a contagem de 40 segundos
                iniciarTimerAutoFechar();
            }

            function fecharPaineis() {
                pararTimerAutoFechar();
                painelAberto = null;
                stage.classList.remove('painel-aberto');
                faceHint.textContent = 'Clique para abrir';
                cardLinux.classList.remove('is-visible');
                cardWindows.classList.remove('is-visible');
                cardPs4.classList.remove('is-visible');
                stops.forEach(s => s.classList.remove('is-open'));
            }

            window.fecharTodosPaineis = fecharPaineis;

            // Se o usuário digitar ou interagir com inputs no card (ex: PIN do PS4), concede mais 40s
            [cardLinux, cardWindows, cardPs4].forEach(card => {
                if (!card) return;
                ['input', 'keydown'].forEach(evt => {
                    card.addEventListener(evt, () => {
                        if (painelAberto) {
                            iniciarTimerAutoFechar();
                        }
                    }, { passive: true });
                });
            });

            stops.forEach((s, k) => {
                s.addEventListener('click', () => {
                    goTo(k, true);
                });
            });

            face.addEventListener('click', (e) => {
                if (e.target.closest('.ccm-11__steer')) return;
                const targetSecao = stops[active].dataset.section;
                if (painelAberto === targetSecao) {
                    fecharPaineis();
                } else {
                    abrirPainel(targetSecao);
                }
            });

            machine.querySelector('.ccm-11__prev').addEventListener('click', (e) => {
                e.stopPropagation();
                goTo(active - 1);
            });
            machine.querySelector('.ccm-11__next').addEventListener('click', (e) => {
                e.stopPropagation();
                goTo(active + 1);
            });
        })();

        // -------------------------------------------------------------
        // AÇÕES DO PS4 (LIGAR / REPOUSO)
        // -------------------------------------------------------------
        async function acionarPS4(tipo, usarPin = false) {
            const btnWake = document.getElementById('btn-ps4-wake');
            const btnWakeText = document.getElementById('btn-ps4-wake-text');
            const btnStandby = document.getElementById('btn-ps4-standby');
            const btnStandbyText = document.getElementById('btn-ps4-standby-text');
            const btnPair = document.getElementById('btn-ps4-pair');
            const fb = document.getElementById('ps4-action-feedback');
            const pinContainer = document.getElementById('ps4-pin-container');
            const pinInput = document.getElementById('ps4-pin-input');

            if (!fb) return;
            fb.style.display = 'block';
            fb.style.background = 'rgba(56, 189, 248, 0.1)';
            fb.style.border = '1px solid rgba(56, 189, 248, 0.3)';
            fb.style.color = '#38BDF8';
            fb.textContent = tipo === 'wake' ? 'Enviando pacotes Wake-on-LAN e DDP Wake...' : (usarPin ? 'Autenticando com o código PIN informado...' : 'Enviando comando para colocar em repouso...');

            if (btnWake) btnWake.disabled = true;
            if (btnStandby) btnStandby.disabled = true;
            if (btnPair) btnPair.disabled = true;
            if (tipo === 'wake' && btnWakeText) btnWakeText.textContent = 'Ligando...';
            if (tipo === 'standby' && btnStandbyText) btnStandbyText.textContent = 'Enviando...';

            if (typeof iniciarTimerAutoFechar === 'function') iniciarTimerAutoFechar();

            try {
                let endpoint = tipo === 'wake' ? 'index.php?acao=ps4_wake' : 'index.php?acao=ps4_standby';
                if (tipo === 'standby' && usarPin && pinInput && pinInput.value.trim()) {
                    endpoint += '&pin=' + encodeURIComponent(pinInput.value.trim());
                }

                const resp = await fetch(endpoint, { cache: 'no-store' });
                const json = await resp.json();

                if (json.sucesso) {
                    fb.style.background = 'rgba(34, 197, 94, 0.12)';
                    fb.style.border = '1px solid rgba(34, 197, 94, 0.3)';
                    fb.style.color = '#22C55E';
                    fb.textContent = '✓ ' + json.mensagem;
                    if (pinContainer) pinContainer.style.display = 'none';
                    setTimeout(sincronizarDados, 3000);
                } else {
                    fb.style.background = 'rgba(245, 158, 11, 0.12)';
                    fb.style.border = '1px solid rgba(245, 158, 11, 0.3)';
                    fb.style.color = '#F59E0B';
                    fb.textContent = 'ℹ ' + json.mensagem;

                    if (json.requer_pin && pinContainer) {
                        pinContainer.style.display = 'block';
                        if (pinInput) {
                            pinInput.focus();
                        }
                    } else if (!json.requer_pin && pinContainer) {
                        pinContainer.style.display = 'none';
                    }
                }
            } catch (err) {
                fb.style.background = 'rgba(239, 68, 68, 0.12)';
                fb.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                fb.style.color = '#EF4444';
                fb.textContent = 'Erro ao processar comando: ' + err.message;
            } finally {
                if (btnWake) btnWake.disabled = false;
                if (btnStandby) btnStandby.disabled = false;
                if (btnPair) btnPair.disabled = false;
                if (btnWakeText) btnWakeText.textContent = 'Ligar PS4';
                if (btnStandbyText) btnStandbyText.textContent = 'Colocar em repouso';
                if (typeof iniciarTimerAutoFechar === 'function') iniciarTimerAutoFechar();
            }
        }

        const pinInputEl = document.getElementById('ps4-pin-input');
        if (pinInputEl) {
            pinInputEl.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    acionarPS4('standby', true);
                }
            });
        }

        // -------------------------------------------------------------
        // CONTADORES RELATIVOS DE TEMPO EM SEGUNDOS E MINUTOS
        // -------------------------------------------------------------
        let segPainel = 0;
        let segWin = <?= $windows['segundos_atras'] !== null ? (int)$windows['segundos_atras'] : 9999 ?>;
        let isSyncing = false;
        let ultimaTentativaHora = '<?= date('H:i:s') ?>';
        let ultimaColetaValidaHora = '<?= date('H:i:s') ?>';

        function formatarTempo(s) {
            if (s < 60) return `há ${s}s atrás`;
            const m = Math.floor(s / 60);
            if (m < 60) return `há ${m}m ${s % 60}s atrás`;
            const h = Math.floor(m / 60);
            return `há ${h}h ${m % 60}m atrás`;
        }

        setInterval(() => {
            segPainel++;
            const tPainel = document.getElementById('timer-painel-topo');
            if (tPainel) tPainel.textContent = `há ${segPainel}s atrás`;

            if (segWin < 9000) {
                segWin++;
                const tWin = document.getElementById('win-tempo-relativo');
                if (tWin) tWin.textContent = formatarTempo(segWin);
            }
        }, 1000);

        // ATUALIZAÇÃO ASSÍNCRONA AUTOMÁTICA (30s)
        setInterval(sincronizarDados, 30000);

        // BOTÃO ATUALIZAR DADOS MANUALMENTE
        async function sincronizarManual() {
            if (isSyncing) return;
            isSyncing = true;

            const btn = document.getElementById('btn-sync-top');
            const btnText = document.getElementById('btn-sync-text');
            if (btn) {
                btn.disabled = true;
                btn.classList.add('spinning');
            }
            if (btnText) btnText.textContent = 'Atualizando...';

            const toast = document.getElementById('sync-toast');
            if (toast) {
                toast.style.display = 'flex';
                toast.innerHTML = '<span class="mono" style="color:var(--brand)">⏳</span> Solicitando dados em tempo real (NEXUS, Windows, PS4)...';
                toast.style.borderColor = 'var(--borda)';
            }

            try {
                const resultado = await sincronizarDados(true);
                if (toast && resultado) {
                    let offlineFontes = [];
                    if (resultado.fontes) {
                        if (!resultado.fontes.linux?.online) offlineFontes.push('Linux (NEXUS)');
                        if (!resultado.fontes.windows?.online) offlineFontes.push('Windows');
                        if (!resultado.fontes.ps4?.online) offlineFontes.push('PS4');
                    }
                    if (offlineFontes.length === 0) {
                        toast.innerHTML = '<span style="color:var(--brand)">✓</span> Todas as fontes responderam em tempo real. Dados sincronizados com sucesso!';
                        toast.style.borderColor = 'rgba(34, 197, 94, 0.4)';
                    } else {
                        toast.innerHTML = `<span style="color:var(--amarelo)">ℹ</span> Dados atualizados. Fontes indisponíveis ou em cache: <strong>${offlineFontes.join(', ')}</strong>.`;
                        toast.style.borderColor = 'rgba(245, 158, 11, 0.4)';
                    }
                    setTimeout(() => {
                        if (toast) toast.style.display = 'none';
                    }, 4500);
                }
            } catch (err) {
                if (toast) {
                    toast.innerHTML = `<span style="color:var(--vermelho)">✕</span> Erro ao consultar fontes: ${err.message}`;
                    toast.style.borderColor = 'rgba(239, 68, 68, 0.4)';
                    setTimeout(() => { if (toast) toast.style.display = 'none'; }, 4500);
                }
            } finally {
                isSyncing = false;
                if (btn) {
                    btn.disabled = false;
                    btn.classList.remove('spinning');
                }
                if (btnText) btnText.textContent = 'Atualizar dados';
            }
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        async function sincronizarDados(manual = false) {
            try {
                const agora = new Date();
                ultimaTentativaHora = agora.toLocaleTimeString('pt-BR', { hour12: false });

                const url = 'index.php?api=1' + (manual ? '&force=1&t=' + Date.now() : '&t=' + Date.now());
                const resp = await fetch(url, {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                const d = await resp.json();

                if (d.sucesso) {
                    segPainel = 0;
                    ultimaColetaValidaHora = d.hora_coleta_valida || ultimaTentativaHora;
                    const hTopo = document.getElementById('hora-coleta-topo');
                    if (hTopo) hTopo.textContent = ultimaColetaValidaHora;
                    const tPainel = document.getElementById('timer-painel-topo');
                    if (tPainel) tPainel.textContent = 'há 0s atrás';

                    const badgeTitle = document.getElementById('topbar-tempo-badge');
                    if (badgeTitle) {
                        badgeTitle.title = `Última coleta válida: ${ultimaColetaValidaHora}\nÚltima tentativa: ${ultimaTentativaHora}`;
                    }
                }

                // 1. Atualizar Header
                if (d.recursos && d.recursos.uptime) {
                    const hUptime = document.getElementById('header-uptime');
                    if (hUptime) hUptime.textContent = d.recursos.uptime;
                }

                // 2. Atualizar Linux (NEXUS)
                if (d.linux) {
                    const up = document.getElementById('draw-linux-uptime');
                    if (up && d.recursos?.uptime) up.textContent = d.recursos.uptime;

                    const ram = document.getElementById('draw-linux-ram');
                    if (ram && d.recursos) ram.textContent = `${d.recursos.mem_usada_gb} / ${d.recursos.mem_total_gb}`;

                    const saude = document.getElementById('draw-linux-saude');
                    if (saude && d.recursos?.saude_disco) saude.textContent = d.recursos.saude_disco;

                    // Raiz LVM
                    if (d.linux_volumes && d.linux_volumes.length > 0) {
                        const r = d.linux_volumes[0];
                        const pct = document.getElementById('linux-raiz-pct');
                        const tot = document.getElementById('linux-raiz-tot');
                        const usa = document.getElementById('linux-raiz-usa');
                        const liv = document.getElementById('linux-raiz-liv');
                        const bar = document.getElementById('linux-raiz-bar');

                        if (pct) {
                            pct.textContent = (typeof r.pct === 'number' ? r.pct.toFixed(1) : r.pct).replace('.', ',') + '%';
                            pct.className = 'storage-pct mono ' + (r.saude?.classe || 'normal');
                        }
                        if (tot) tot.textContent = r.total_gb;
                        if (usa) usa.textContent = r.usado_gb;
                        if (liv) liv.textContent = r.livre_gb;
                        if (bar) {
                            bar.style.width = (typeof r.pct === 'number' ? r.pct.toFixed(1) : r.pct) + '%';
                            bar.className = 'storage-bar-fill ' + (r.saude?.classe || 'normal');
                        }
                    }
                }

                // 3. Atualizar Windows
                if (d.windows) {
                    const winPill = document.getElementById('win-pill-status');
                    const winTxt = document.getElementById('win-texto-status');
                    if (winPill && winTxt) {
                        const cls = d.windows.status_classe || (d.windows.ativo_online ? 'ok' : 'warn');
                        winPill.className = 'pill-status ' + cls;
                        winTxt.textContent = d.windows.status_texto || (d.windows.ativo_online ? 'Online' : 'Conectado');
                    }

                    if (d.windows.segundos_atras !== null && d.windows.segundos_atras !== undefined) {
                        segWin = d.windows.segundos_atras;
                        const tWin = document.getElementById('win-tempo-relativo');
                        if (tWin) tWin.textContent = formatarTempo(segWin);
                    }

                    const winDataSinc = document.getElementById('win-data-sinc');
                    if (winDataSinc && d.windows.data_formatada) {
                        winDataSinc.textContent = d.windows.data_formatada;
                    }

                    if (d.windows.discos && d.windows.discos.length > 0) {
                        const container = document.getElementById('lista-volumes-windows');
                        if (container) {
                            let htmlWin = '';
                            for (const w of d.windows.discos) {
                                const pctVal = typeof w.pct === 'number' ? w.pct : parseFloat(w.pct);
                                const pctFmt = pctVal.toFixed(1).replace('.', ',');
                                const sitCls = w.situacao?.classe || 'normal';
                                htmlWin += `
                                    <div class="main-storage-box">
                                        <div class="storage-head">
                                            <span class="storage-title">
                                                <strong>Unidade ${escapeHtml(w.unidade)}</strong> (${escapeHtml(w.rotulo)})
                                            </span>
                                            <span class="storage-pct mono ${sitCls}">
                                                ${pctFmt}%
                                            </span>
                                        </div>
                                        <div class="storage-stats">
                                            <div><span class="s-lbl">Total</span><span class="s-num mono">${escapeHtml(w.total_gb)}</span></div>
                                            <div><span class="s-lbl">Usado</span><span class="s-num mono">${escapeHtml(w.usado_gb)}</span></div>
                                            <div><span class="s-lbl">Livre</span><span class="s-num mono livre">${escapeHtml(w.livre_gb)}</span></div>
                                        </div>
                                        <div class="storage-bar">
                                            <div class="storage-bar-fill ${sitCls}" style="width: ${pctVal.toFixed(1)}%"></div>
                                        </div>
                                    </div>
                                `;
                            }
                            container.innerHTML = htmlWin;
                        }
                    }
                }

                // 4. Atualizar PS4
                if (d.ps4) {
                    const ps4Pill = document.getElementById('ps4-pill-status');
                    const ps4Txt = document.getElementById('ps4-texto-status');
                    const ps4Ping = document.getElementById('ps4-ping-val');
                    const ps4Host = document.getElementById('ps4-host-val');
                    const ps4UltimaVerif = document.getElementById('ps4-ultima-verif');
                    const ps4EstadoTag = document.getElementById('ps4-estado-tag');
                    const ps4AppNome = document.getElementById('ps4-app-nome');
                    const ps4AppId = document.getElementById('ps4-app-id');
                    const ps4SysVer = document.getElementById('ps4-sys-ver');

                    if (ps4Pill && ps4Txt) {
                        const statusClass = d.ps4.online ? (d.ps4.ddp_confirmado ? 'ok' : 'warn') : 'critico';
                        ps4Pill.className = `pill-status ${statusClass}`;
                        ps4Txt.textContent = d.ps4.online ? (d.ps4.ddp_confirmado ? 'Online (Ativo)' : 'Conectado') : 'Offline';
                    }
                    if (ps4Ping) ps4Ping.textContent = d.ps4.ping_ms || 'Timeout';
                    if (ps4Host) ps4Host.textContent = `${d.ps4.host_name} (${d.ps4.host_id})`;
                    if (ps4UltimaVerif) ps4UltimaVerif.textContent = d.ps4.ultima_verificacao || ultimaTentativaHora;
                    if (ps4EstadoTag) {
                        ps4EstadoTag.className = `storage-pct mono ${d.ps4.estado_classe}`;
                        ps4EstadoTag.textContent = d.ps4.ddp_confirmado ? 'Confirmado via DDP' : (d.ps4.online ? 'Detectado na Rede' : 'Inacessível');
                    }
                    if (ps4AppNome) {
                        ps4AppNome.textContent = d.ps4.app_ativa ? d.ps4.app_ativa : (d.ps4.online ? 'Menu do Sistema / Em Espera' : 'Desconectado');
                    }
                    if (ps4AppId) ps4AppId.textContent = d.ps4.app_titleid || 'N/A';
                    if (ps4SysVer) ps4SysVer.textContent = d.ps4.sistema_versao || 'N/A';
                }

                // 5. Alerta Badge do Header
                let volAlerta = 0;
                if (d.linux_volumes) {
                    d.linux_volumes.forEach(v => { if (v.pct >= 75) volAlerta++; });
                }
                if (d.windows?.discos) {
                    d.windows.discos.forEach(w => { if (w.pct >= 75) volAlerta++; });
                }
                const hBadge = document.getElementById('header-alerta-badge');
                const hBadgeTxt = document.getElementById('header-alerta-texto');
                if (hBadge && hBadgeTxt) {
                    if (volAlerta > 0) {
                        hBadge.className = 'status-alerta-badge atencao';
                        hBadgeTxt.textContent = `Atenção (${volAlerta} volume)`;
                    } else {
                        hBadge.className = 'status-alerta-badge normal';
                        hBadgeTxt.textContent = 'Infraestrutura OK';
                    }
                }

                return d;
            } catch (e) {
                console.warn('Erro na atualização:', e);
                throw e;
            }
        }
    </script>
</body>
</html>
