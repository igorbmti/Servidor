<?php
declare(strict_types=1);

if (!ini_get('zlib.output_compression') && function_exists('ob_gzhandler') && !isset($_GET['acao'])) {
    ob_start('ob_gzhandler');
}

session_start();

// Proteção de autenticação: redireciona ao login se não autenticado
if (empty($_SESSION['usuario_logado'])) {
    if (isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['sucesso' => false, 'erro' => 'Sessão expirada ou não autenticada']);
        exit;
    }
    header('Location: login.php');
    exit;
}

$usuarioLogado = (string)$_SESSION['usuario_logado'];

// Libera o lock da sessão: evita que requisições paralelas (APIs de polling) fiquem enfileiradas
session_write_close();


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
        'saude_disco' => '100% Saudável',
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
        $res['saude_disco'] = '100% Saudável';
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
    $ret = 1;
    if (stripos(PHP_OS, 'WIN') !== 0) {
        @exec('lsblk -b -J -e 7,11 -o NAME,PATH,SIZE,TYPE,FSTYPE,MOUNTPOINTS,PKNAME,MODEL 2>/dev/null', $output, $ret);
    }
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
            'saude' => '100% Saudável'
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
            'saude' => '100% Saudável'
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
        'discos' => [],
        'recursos' => [
            'ip' => WINDOWS_IP,
            'uptime' => 'Ativo',
            'mem_resumo' => '—',
            'mem_usada_gb' => '—',
            'mem_total_gb' => '—',
            'saude_disco' => '100% Saudável',
            'disco_modelo' => 'KINGSTON SA400S37120G',
            'disco_capacidade_gb' => '111,8 GB'
        ],
        'disco_fisico' => [
            'modelo' => 'KINGSTON SA400S37120G',
            'total_gb' => '111,8 GB'
        ]
    ];

    // Checagem real de conectividade via Ping ICMP
    $pingOut = [];
    $pingRet = 1;
    $isWin = (stripos(PHP_OS, 'WIN') === 0);
    if ($isWin) {
        $resultado['ping_online'] = true;
    } else {
        @exec("ping -c 1 -W 0.3 " . escapeshellarg(WINDOWS_IP) . " 2>/dev/null", $pingOut, $pingRet);
        $resultado['ping_online'] = ($pingRet === 0);
    }

    $caminhoWindows = file_exists(__DIR__ . '/windows.json') ? __DIR__ . '/windows.json' : WINDOWS_JSON;
    if (!file_exists($caminhoWindows) && file_exists(WINDOWS_JSON)) {
        $caminhoWindows = WINDOWS_JSON;
    }

    if (!file_exists($caminhoWindows)) {
        if ($resultado['ping_online']) {
            $resultado['status_texto'] = 'Conectado (Sem dados)';
            $resultado['status_classe'] = 'warn';
        } else {
            $resultado['status_texto'] = 'Offline';
            $resultado['status_classe'] = 'critico';
        }
        return $resultado;
    }

    $conteudo = @file_get_contents($caminhoWindows);
    if ($conteudo === false) {
        return $resultado;
    }

    $json = json_decode($conteudo, true);
    if (!is_array($json)) {
        return $resultado;
    }

    $mtime = @filemtime($caminhoWindows);
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

    $rec = $json['recursos'] ?? [];
    if (!empty($rec)) {
        $resultado['ip'] = $rec['ip'] ?? WINDOWS_IP;
        $saudeRec = (string)($rec['saude_disco'] ?? '100% Saudável');
        if (strpos($saudeRec, 'Saud') !== false || strpos($saudeRec, 'Healthy') !== false || strpos($saudeRec, 'Running') !== false) {
            $saudeRec = '100% Saudável';
        }
        $saudeRec = trim(str_ireplace(['(Running)', 'Running'], '', $saudeRec));
        if ($saudeRec === '') {
            $saudeRec = '100% Saudável';
        }
        $resultado['recursos'] = [
            'ip' => $resultado['ip'],
            'uptime' => $rec['uptime'] ?? 'Ativo',
            'mem_resumo' => $rec['mem_resumo'] ?? '—',
            'mem_usada_gb' => $rec['mem_usada_gb'] ?? '—',
            'mem_total_gb' => $rec['mem_total_gb'] ?? '—',
            'saude_disco' => $saudeRec,
            'disco_modelo' => $rec['disco_modelo'] ?? 'KINGSTON SA400S37120G',
            'disco_capacidade_gb' => $rec['disco_capacidade_gb'] ?? '111,8 GB'
        ];
        $resultado['disco_fisico'] = [
            'modelo' => $resultado['recursos']['disco_modelo'],
            'total_gb' => $resultado['recursos']['disco_capacidade_gb']
        ];
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
            'saude_disco' => $resultado['recursos']['saude_disco']
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

    $isWin = (stripos(PHP_OS, 'WIN') === 0);

    // 1. Teste de Ping Real (rápido e compatível com Windows/Linux)
    $pingOut = [];
    $pingRet = 1;
    $cmdPing = $isWin ? "ping -n 1 -w 250 " . escapeshellarg($ip) . " 2>NUL" : "ping -c 1 -W 0.25 " . escapeshellarg($ip) . " 2>/dev/null";
    @exec($cmdPing, $pingOut, $pingRet);
    if ($pingRet === 0) {
        $res['online'] = true;
        $res['estado'] = 'Conectado à Rede';
        $res['estado_classe'] = 'atencao';
        $str = implode(' ', $pingOut);
        if (preg_match('/(?:time|tempo)[=<]\s*([\d\.]+)\s*ms/i', $str, $m)) {
            $res['ping_ms'] = number_format((float)$m[1], 1, ',', '.') . ' ms';
        }
    }

    // 2. Consulta ao protocolo DDP via UDP 987 (com checagem segura de socket_create)
    if (function_exists('socket_create')) {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock) {
            @socket_set_option($sock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 0, 'usec' => 150000]);
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
    }

    // 3. Checagem de porta TCP 9295 (Remote Play / Second Screen) apenas se necessário
    if (!$res['ddp_confirmado']) {
        $fp = @fsockopen($ip, 9295, $errno, $errstr, 0.25);
        if ($fp) {
            $res['porta_remota_aberta'] = true;
            fclose($fp);
            $res['estado'] = 'Conexão Remota Ativa (Porta 9295)';
            $res['estado_classe'] = 'normal';
        } elseif (!$res['online']) {
            $res['estado'] = 'Offline / Desligado';
            $res['estado_classe'] = 'critico';
        }
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

function processarResultadoPing(string $saida): array
{
    if (preg_match('/(?:time|tempo)[=<]\s*([\d\.]+)\s*ms/i', $saida, $m)) {
        $ms = (float)$m[1];
        $intMs = max(1, (int)round($ms));
        $texto = $intMs . 'ms';

        if ($intMs <= 40) {
            $classe = 'ping-verde';
        } elseif ($intMs <= 120) {
            $classe = 'ping-amarelo';
        } else {
            $classe = 'ping-vermelho';
        }

        return [
            'online' => true,
            'ms' => $intMs,
            'texto' => $texto,
            'classe' => $classe
        ];
    }

    return [
        'online' => false,
        'ms' => null,
        'texto' => 'Offline',
        'classe' => 'ping-vermelho'
    ];
}

function executarPingUnico(string $ip, bool $isWin): array
{
    $out = [];
    $ret = 1;
    $cmd = $isWin ? "ping -n 1 -w 250 " . escapeshellarg($ip) . " 2>NUL" : "ping -c 1 -W 0.25 " . escapeshellarg($ip) . " 2>/dev/null";
    @exec($cmd, $out, $ret);
    return processarResultadoPing(implode(' ', $out));
}

function coletarPingsRede(): array
{
    $isWin = (stripos(PHP_OS, 'WIN') === 0);

    if ($isWin) {
        return [
            'linux' => executarPingUnico(LINUX_IP, true),
            'windows' => ['online' => true, 'ms' => 1, 'texto' => '1ms', 'classe' => 'ping-verde'],
            'ps4' => executarPingUnico(PS4_IP, true)
        ];
    }

    $fLin = tempnam(sys_get_temp_dir(), 'plin_');
    $fWin = tempnam(sys_get_temp_dir(), 'pwin_');
    $fPs4 = tempnam(sys_get_temp_dir(), 'pps4_');

    $cmd = 'ping -c 1 -W 0.25 127.0.0.1 > ' . escapeshellarg($fLin) . ' 2>&1 & ' .
           'ping -c 1 -W 0.25 ' . escapeshellarg(WINDOWS_IP) . ' > ' . escapeshellarg($fWin) . ' 2>&1 & ' .
           'ping -c 1 -W 0.25 ' . escapeshellarg(PS4_IP) . ' > ' . escapeshellarg($fPs4) . ' 2>&1 & wait';
    @exec($cmd);

    $outLin = @file_get_contents($fLin) ?: '';
    $outWin = @file_get_contents($fWin) ?: '';
    $outPs4 = @file_get_contents($fPs4) ?: '';

    @unlink($fLin);
    @unlink($fWin);
    @unlink($fPs4);

    return [
        'linux' => processarResultadoPing($outLin),
        'windows' => processarResultadoPing($outWin),
        'ps4' => processarResultadoPing($outPs4)
    ];
}

/**
 * Cache inteligente em memória/arquivo temporário para evitar travamentos
 * e garantir resposta em menos de 10ms na navegação do usuário.
 */
function obterDadosCompletos(bool $forcarAtualizacao = false, bool $aceitarAntigo = false): array
{
    $cacheFile = __DIR__ . '/cache_painel.json';
    $ttl = 15; // Cache de 15 segundos para resposta instantânea (<5ms) e navegação ultra-fluida

    if (!$forcarAtualizacao && file_exists($cacheFile)) {
        $mtime = @filemtime($cacheFile);
        // $aceitarAntigo: renderização inicial usa cache de até 1h; o JS atualiza logo após o load
        if ($mtime !== false && (time() - $mtime) <= ($aceitarAntigo ? 3600 : $ttl)) {
            $conteudo = @file_get_contents($cacheFile);
            if ($conteudo !== false) {
                $dec = json_decode($conteudo, true);
                if (is_array($dec) && isset($dec['linux'], $dec['windows'], $dec['ps4'], $dec['pings'])) {
                    return $dec;
                }
            }
        }
    }

    $l = coletarArmazenamentoLinux();
    $w = coletarArmazenamentoWindows();
    $p = coletarDadosPS4();
    $pings = coletarPingsRede();

    $resultado = [
        'linux' => $l,
        'windows' => $w,
        'ps4' => $p,
        'pings' => $pings,
        'timestamp' => time()
    ];

    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($resultado)) !== false) {
        @rename($tmp, $cacheFile);
    }
    return $resultado;
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

$forcar = isset($_GET['force']) && ($_GET['force'] === '1' || $_GET['force'] === 'true');

if (isset($_GET['api']) && $_GET['api'] === 'pings') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $dados = obterDadosCompletos($forcar);
    echo json_encode([
        'sucesso' => true,
        'timestamp' => time(),
        'pings' => $dados['pings']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_GET['api']) && $_GET['api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    $dados = obterDadosCompletos($forcar);
    $l = $dados['linux'];
    $w = $dados['windows'];
    $p = $dados['ps4'];

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

$dadosIniciais = obterDadosCompletos(false, true);
$cacheEraAntigo = isset($dadosIniciais['timestamp']) && (time() - (int)$dadosIniciais['timestamp']) > 15;
$linux = $dadosIniciais['linux'];
$windows = $dadosIniciais['windows'];
$ps4 = $dadosIniciais['ps4'];
$pingsIniciais = $dadosIniciais['pings'];

// Verificação de espaço livre < 20 GB em qualquer equipamento
$limite20GB = 20.0 * 1024 * 1024 * 1024; // 20 GB em bytes
$alertasEspaco = [];

foreach ($linux['sistemas_arquivos'] as $fs) {
    if ($fs['ponto'] === '/' && isset($fs['livre_bytes']) && $fs['livre_bytes'] < $limite20GB) {
        $alertasEspaco[] = "Linux ({$fs['nome']}): apenas " . $fs['livre_gb'] . " livres (< 20 GB)";
    }
}
foreach ($windows['discos'] as $wd) {
    if (isset($wd['livre_bytes']) && $wd['livre_bytes'] < $limite20GB) {
        $alertasEspaco[] = "Windows ({$wd['unidade']}): apenas " . $wd['livre_gb'] . " livres (< 20 GB)";
    }
}
$temAlerta20GB = !empty($alertasEspaco);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de controle — NEXUS | Monitoramento 360°</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet"></noscript>
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

        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            background-color: #000000 !important;
            background: #000000 !important;
            background-image: none !important;
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
            background: rgba(10, 14, 12, 0.94);
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
            border: 1px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
            cursor: pointer;
            background: transparent;
            font-family: inherit;
            transition: all 0.2s ease;
            user-select: none;
            outline: none;
        }

        .status-alerta-badge:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
        }

        .status-alerta-badge:active {
            transform: translateY(0);
        }

        .status-alerta-badge .badge-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            line-height: 1;
        }

        .status-alerta-badge.atencao {
            background: rgba(245, 158, 11, 0.12);
            border-color: rgba(245, 158, 11, 0.35);
            color: var(--amarelo);
        }

        .status-alerta-badge.atencao:hover {
            background: rgba(245, 158, 11, 0.2);
            border-color: var(--amarelo);
        }

        .status-alerta-badge.normal {
            background: rgba(34, 197, 94, 0.12);
            border-color: rgba(34, 197, 94, 0.35);
            color: var(--brand);
        }

        .status-alerta-badge.normal:hover {
            background: rgba(34, 197, 94, 0.2);
            border-color: var(--brand);
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

        
        /* BADGE DO USUÁRIO E LOGOUT */
        .user-session-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .user-name-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--ink);
            background: var(--bg-secundario);
            border: 1px solid var(--borda);
            padding: 5px 10px;
            border-radius: 8px;
        }

        .btn-logout {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 11.5px;
            font-weight: 600;
            color: var(--ink-sec);
            background: var(--bg-secundario);
            border: 1px solid var(--borda);
            padding: 5px 9px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-logout:hover {
            color: #FFFFFF;
            background: rgba(239, 68, 68, 0.16);
            border-color: rgba(239, 68, 68, 0.45);
        }

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
            z-index: 9999;
            animation: toastPop 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            pointer-events: auto;
        }

        @keyframes toastPop {
            from { opacity: 0; transform: translateX(-50%) translateY(10px); }
            to { opacity: 1; transform: translateX(-50%) translateY(0); }
        }

        /* =============================================================
           NOVA ESTRUTURA: SIDEBAR FLUTUANTE À ESQUERDA + PALCO CENTRAL
           ============================================================= */
        .painel-main-layout {
            width: 100%;
            min-height: calc(100vh - 52px);
            padding: 30px 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 40px;
            background: transparent;
            position: relative;
            box-sizing: border-box;
            max-width: 1560px;
            margin: 0 auto;
        }

        /* BARRA TOGGLE DE HIDEBAR NO MOBILE */
        .mobile-hidebar-bar {
            display: none;
            width: 100%;
            margin-bottom: 12px;
        }

        .btn-hidebar-toggle {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 11px 16px;
            background: rgba(17, 23, 20, 0.95);
            border: 1px solid var(--borda);
            border-radius: 12px;
            color: var(--ink);
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            transition: border-color 0.2s, background 0.2s;
        }

        .btn-hidebar-toggle:hover {
            border-color: var(--brand);
            background: var(--bg-secundario);
        }

        .btn-hidebar-toggle .pulse-net-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 10px var(--brand);
            display: inline-block;
        }

        .btn-hidebar-toggle .hidebar-toggle-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-hidebar-toggle .arrow-icon {
            transition: transform 0.3s ease;
            color: var(--ink-sec);
        }

        .btn-hidebar-toggle[aria-expanded="true"] .arrow-icon {
            transform: rotate(180deg);
        }

        /* SIDEBAR FLUTUANTE À ESQUERDA (SEM LINHAS EXTERNAS / SEM BORDAS DE QUADRO) */
        .network-sidebar {
            width: 180px;
            flex-shrink: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            background: transparent;
            border: none;
            padding: 0;
            z-index: 20;
            user-select: none;
        }

        /* HEADER ESPECIFICANDO CONTROLE PELA REDE (LIQUID FLUID GLASS CAPSULE) */
        .sidebar-net-header {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 7px 16px 7px 12px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0.01) 100%), rgba(14, 22, 18, 0.65);
            backdrop-filter: blur(16px) saturate(190%);
            -webkit-backdrop-filter: blur(16px) saturate(190%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-top: 1px solid rgba(255, 255, 255, 0.22);
            border-radius: 999px;
            box-shadow: 
                0 8px 20px -4px rgba(0, 0, 0, 0.55),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.18),
                inset 0 -1px 1px 0 rgba(0, 0, 0, 0.4);
            margin-bottom: 6px;
            width: auto;
            max-width: 175px;
            transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .sidebar-net-header:hover {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.08) 0%, rgba(255, 255, 255, 0.02) 100%), rgba(18, 28, 22, 0.78);
            border-color: rgba(34, 197, 94, 0.35);
            border-top-color: rgba(74, 222, 128, 0.6);
            transform: translateY(-1px);
            box-shadow: 
                0 12px 24px -4px rgba(0, 0, 0, 0.65),
                0 0 14px -2px rgba(34, 197, 94, 0.25),
                inset 0 1px 1.5px 0 rgba(255, 255, 255, 0.25);
        }

        .net-radar-pulse {
            position: relative;
            width: 18px;
            height: 18px;
            display: grid;
            place-items: center;
        }

        /* LUZ DO RADAR EM 'SISTEMAS E HOSTS': PULSO LIQUID FLUID */
        .net-radar-pulse .radar-ping {
            display: block;
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.8) 0%, rgba(34, 197, 94, 0) 70%);
            opacity: 0.75;
            animation: radarPulse 2.4s cubic-bezier(0.16, 1, 0.3, 1) infinite;
        }

        .net-radar-pulse .radar-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #22C55E;
            box-shadow: 
                0 0 10px #22C55E,
                0 0 18px rgba(34, 197, 94, 0.6),
                inset 0 1px 1px rgba(255, 255, 255, 0.6);
            z-index: 1;
            transition: all 0.3s ease;
        }

        .net-header-info {
            display: flex;
            flex-direction: column;
            text-align: left;
        }

        .net-badge-title {
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: 0.8px;
            color: #FFFFFF;
            text-transform: uppercase;
            line-height: 1.2;
            transition: color 0.25s ease;
        }

        .net-badge-sub {
            font-size: 9.5px;
            color: var(--ink-sec);
            line-height: 1.2;
        }

        /* ESTADOS DE FOCO / SELEÇÃO */
        .net-radar-pulse.is-online .radar-dot {
            background: #22C55E;
            box-shadow: 0 0 10px #22C55E, 0 0 18px rgba(34, 197, 94, 0.6);
        }
        .net-radar-pulse.is-online .radar-ping {
            display: block;
            background: radial-gradient(circle, rgba(34, 197, 94, 0.8) 0%, rgba(34, 197, 94, 0) 70%);
            animation: radarPulse 2.4s cubic-bezier(0.16, 1, 0.3, 1) infinite;
        }
        .sidebar-net-header.is-online .net-badge-title {
            color: #FFFFFF;
        }

        .net-radar-pulse.is-offline .radar-dot {
            background: var(--vermelho);
            box-shadow: 0 0 10px var(--vermelho), 0 0 18px rgba(239, 68, 68, 0.6);
        }
        .net-radar-pulse.is-offline .radar-ping {
            display: block;
            background: radial-gradient(circle, rgba(239, 68, 68, 0.8) 0%, rgba(239, 68, 68, 0) 70%);
            animation: radarPulse 2.4s cubic-bezier(0.16, 1, 0.3, 1) infinite;
        }
        .sidebar-net-header.is-offline .net-badge-title {
            color: var(--vermelho);
        }

        @keyframes radarPulse {
            0% { transform: scale(0.7); opacity: 0.9; }
            50% { opacity: 0.4; }
            100% { transform: scale(2.8); opacity: 0; }
        }

        /* PILHA DE BOTÕES (DEVICES STACK) COMPACTA COM LIQUID GLASS */
        .devices-stack {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 13px;
            margin-top: 4px;
            width: 100%;
            position: relative;
        }

        .device-btn {
            width: 100%;
            max-width: 125px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            padding: 7px 14px;
            background: rgba(16, 23, 19, 0.65);
            backdrop-filter: blur(14px) saturate(180%);
            -webkit-backdrop-filter: blur(14px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 10px;
            box-shadow: 
                0 4px 12px -2px rgba(0, 0, 0, 0.5),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.2),
                inset 0 -1px 1px 0 rgba(0, 0, 0, 0.45);
            color: var(--ink-sec);
            cursor: pointer;
            text-align: center;
            font-family: 'Inter', system-ui, sans-serif;
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
            box-sizing: border-box;
            position: relative;
        }

        .device-btn:hover {
            background: rgba(24, 34, 28, 0.8);
            border-color: rgba(34, 197, 94, 0.4);
            color: #FFFFFF;
            transform: translateY(-1.5px);
            box-shadow: 
                0 6px 16px -2px rgba(0, 0, 0, 0.65),
                0 0 10px -2px rgba(34, 197, 94, 0.25),
                inset 0 1px 1.5px 0 rgba(255, 255, 255, 0.3),
                inset 0 -1px 1px 0 rgba(0, 0, 0, 0.45);
        }

        .device-btn.is-active {
            color: #FFFFFF;
            transform: translateY(-1.5px);
            background: rgba(34, 197, 94, 0.16);
            border-color: rgba(34, 197, 94, 0.75);
            box-shadow: 
                0 6px 16px -2px rgba(0, 0, 0, 0.6),
                0 0 14px -1px rgba(34, 197, 94, 0.45),
                inset 0 1px 1.5px 0 rgba(255, 255, 255, 0.35),
                inset 0 -1px 1px 0 rgba(34, 197, 94, 0.3);
        }

        .device-btn .btn-os-icon {
            display: none !important;
            width: 0 !important;
            height: 0 !important;
            overflow: hidden !important;
            visibility: hidden !important;
        }

        .device-btn .btn-dev-label {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: 0.2px;
            line-height: 1;
        }

        .device-btn.is-active .btn-dev-label {
            color: #FFFFFF;
        }

        /* INFORMATIVO DE PING DO SISTEMA CENTRALIZADO NO RODAPÉ DO CARD */
        .card-footer-ping {
            text-align: center;
            font-size: 11px;
            color: var(--ink-muted);
            padding: 8px 0 2px 0;
            margin-top: auto;
            width: 100%;
            letter-spacing: 0.2px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }

        .card-footer-ping .card-ping-val {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            font-size: 11px;
            transition: color 0.3s ease;
        }

        .card-footer-ping .card-ping-val.ping-verde {
            color: #22C55E !important;
        }

        .card-footer-ping .card-ping-val.ping-amarelo {
            color: #F59E0B !important;
        }

        .card-footer-ping .card-ping-val.ping-vermelho {
            color: #EF4444 !important;
        }

        /* =============================================================
           PLAYER DE MÚSICA LIQUID GLASS — BH FM (COMPACTO / MINI WIDGET)
           ============================================================= */
        .liquid-radio-player {
            position: fixed;
            bottom: 20px;
            left: 48px;
            z-index: 95;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 10px 4px 5px;
            background: rgba(14, 20, 17, 0.72);
            backdrop-filter: blur(16px) saturate(190%);
            -webkit-backdrop-filter: blur(16px) saturate(190%);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 30px;
            box-shadow: 
                0 10px 24px -4px rgba(0, 0, 0, 0.65),
                0 0 0 1px rgba(34, 197, 94, 0.12),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.18),
                inset 0 -1px 1px 0 rgba(0, 0, 0, 0.4);
            transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            user-select: none;
            height: 32px;
            box-sizing: border-box;
        }

        .liquid-radio-player:hover {
            background: rgba(18, 26, 22, 0.85);
            border-color: rgba(34, 197, 94, 0.35);
            box-shadow: 
                0 14px 28px -4px rgba(0, 0, 0, 0.75),
                0 0 16px -2px rgba(34, 197, 94, 0.22),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }

        .radio-play-btn {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: linear-gradient(135deg, #22C55E 0%, #15803D 100%);
            border: none;
            cursor: pointer;
            display: grid;
            place-items: center;
            color: #FFFFFF;
            box-shadow: 0 2px 8px rgba(34, 197, 94, 0.4);
            transition: transform 0.2s cubic-bezier(0.34, 1.56, 0.64, 1), box-shadow 0.2s;
            flex-shrink: 0;
            outline: none;
            padding: 0;
        }

        .radio-play-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 4px 12px rgba(34, 197, 94, 0.6);
        }

        .radio-play-btn:active {
            transform: scale(0.94);
        }

        .radio-play-btn svg {
            width: 10px;
            height: 10px;
        }

        .radio-info {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .radio-title {
            font-size: 11px;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: 0.2px;
            line-height: 1;
        }

        .radio-live-badge {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-size: 7.5px;
            font-weight: 700;
            padding: 1.5px 4.5px;
            border-radius: 3px;
            background: rgba(34, 197, 94, 0.15);
            border: 1px solid rgba(34, 197, 94, 0.25);
            color: var(--brand);
            text-transform: uppercase;
            letter-spacing: 0.3px;
            line-height: 1;
        }

        .radio-live-dot {
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 4px var(--brand);
        }

        .liquid-radio-player.is-playing .radio-live-dot {
            animation: radioPulseLive 1.5s ease-in-out infinite alternate;
        }

        @keyframes radioPulseLive {
            0% { transform: scale(0.85); opacity: 0.6; }
            100% { transform: scale(1.25); opacity: 1; box-shadow: 0 0 6px var(--brand); }
        }

        /* EQUALIZADOR ANIMADO MICRO */
        .radio-equalizer {
            display: flex;
            align-items: flex-end;
            gap: 1.5px;
            height: 12px;
            width: 12px;
            padding: 0 1px;
            flex-shrink: 0;
        }

        .radio-eq-bar {
            width: 2px;
            height: 2px;
            background: var(--brand);
            border-radius: 1px;
            transition: height 0.2s ease, opacity 0.2s ease;
            opacity: 0.35;
        }

        .liquid-radio-player.is-playing .radio-eq-bar:nth-child(1) {
            animation: eqDanceMini 0.75s ease-in-out infinite alternate;
        }
        .liquid-radio-player.is-playing .radio-eq-bar:nth-child(2) {
            animation: eqDanceMini 1.05s ease-in-out 0.15s infinite alternate;
        }
        .liquid-radio-player.is-playing .radio-eq-bar:nth-child(3) {
            animation: eqDanceMini 0.85s ease-in-out 0.3s infinite alternate;
        }
        .liquid-radio-player.is-playing .radio-eq-bar:nth-child(4) {
            animation: eqDanceMini 1.15s ease-in-out 0.1s infinite alternate;
        }

        @keyframes eqDanceMini {
            0% { height: 2px; opacity: 0.5; }
            50% { height: 11px; opacity: 1; }
            100% { height: 5px; opacity: 0.8; }
        }

        /* CONTROLE DE VOLUME MINI */
        .radio-vol-box {
            display: flex;
            align-items: center;
            gap: 4px;
            padding-left: 6px;
            border-left: 1px solid rgba(255, 255, 255, 0.12);
            height: 16px;
        }

        .radio-mute-btn {
            background: transparent;
            border: none;
            color: var(--ink-sec);
            cursor: pointer;
            padding: 2px;
            border-radius: 4px;
            display: grid;
            place-items: center;
            transition: color 0.2s, transform 0.2s;
        }

        .radio-mute-btn:hover {
            color: var(--ink);
            transform: scale(1.15);
        }

        .radio-mute-btn svg {
            width: 12px;
            height: 12px;
        }

        .radio-vol-slider {
            -webkit-appearance: none;
            appearance: none;
            width: 42px;
            height: 3px;
            background: rgba(255, 255, 255, 0.15);
            border-radius: 2px;
            outline: none;
            cursor: pointer;
            transition: background 0.2s;
        }

        .radio-vol-slider::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 6px var(--brand);
            cursor: pointer;
            transition: transform 0.15s;
        }

        .radio-vol-slider::-webkit-slider-thumb:hover {
            transform: scale(1.25);
        }

        .radio-vol-slider::-moz-range-thumb {
            width: 8px;
            height: 8px;
            border: none;
            border-radius: 50%;
            background: var(--brand);
            box-shadow: 0 0 6px var(--brand);
            cursor: pointer;
        }

        @media (max-width: 900px) {
            .liquid-radio-player {
                left: 16px;
                bottom: 16px;
                height: 30px;
                padding: 4px 8px 4px 4px;
                gap: 6px;
            }
            .radio-vol-box {
                display: none;
            }
        }

        

        
        

        /* =============================================================
           CARROSSEL 3D EM PERSPECTIVA NO CENTRO DA PÁGINA
           ============================================================= */
        .painel-center-stage {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            position: static; /* o carrossel se posiciona em relação ao layout inteiro */
            min-height: 520px;
            padding: 0;
            box-sizing: border-box;
        }

        /* Card centralizado no MEIO DA PÁGINA (dimensões amplas e confortáveis) */
        .carousel-3d-wrapper {
            --fit: 1;
            position: fixed;
            left: 50%;
            top: calc(50% + 26px);
            transform: translate(-50%, -50%) scale(var(--fit));
            transform-origin: center center;
            width: 30rem;
            height: 33rem;
            z-index: 15;
        }

        @media (max-width: 768px) {
            .painel-center-stage {
                position: relative;
                width: 100%;
            }
            .carousel-3d-wrapper {
                position: relative;
                left: auto;
                top: auto;
                transform: none;
                margin: 0 auto;
                width: calc(100% - 120px);
                max-width: 22rem;
                height: 41rem;
            }
        }

        .carousel {
            position: relative;
            width: 100%;
            height: 100%;
            perspective: 500px;
            transform-style: preserve-3d;
        }

        .card-container {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            transform: 
                rotateY(calc(var(--offset) * 50deg)) 
                scaleY(calc(1 + var(--abs-offset) * -0.4))
                translateZ(calc(var(--abs-offset) * -30rem))
                translateX(calc(var(--direction) * -5rem));
            filter: blur(calc(var(--abs-offset) * 1rem));
            transition: all 0.3s ease-out;
            transform-style: preserve-3d;
            box-sizing: border-box;
            cursor: pointer;
        }

        .card-container[style*="--active: 1"],
        .card-container[style*="--active:1"] {
            cursor: default;
            filter: none !important;
            z-index: 25 !important;
        }

        /* =============================================================
           ESTILO LIQUID GLASS (VIDRO LÍQUIDO ULTRA-MODERNO)
           ============================================================= */
        .card {
            width: 100%;
            height: 100%;
            padding: 16px 18px 14px;
            background: linear-gradient(150deg, rgba(14, 22, 18, 0.90) 0%, rgba(8, 12, 10, 0.96) 100%) !important;
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-top: 1px solid rgba(255, 255, 255, 0.25);
            border-left: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 18px;
            color: var(--ink-sec);
            text-align: left;
            transition: all 0.3s ease-out;
            box-shadow: 
                0 25px 60px -10px rgba(0, 0, 0, 0.88),
                0 10px 20px -5px rgba(0, 0, 0, 0.6),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.18),
                inset 0 -1px 2px 0 rgba(0, 0, 0, 0.5);
            display: flex;
            flex-direction: column;
            gap: 13px;
            overflow: hidden;
            box-sizing: border-box;
            position: relative;
            backface-visibility: hidden;
            -webkit-backface-visibility: hidden;
        }

        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 38%;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.05) 0%, rgba(255, 255, 255, 0) 100%);
            pointer-events: none;
            border-top-left-radius: 18px;
            border-top-right-radius: 18px;
            z-index: 1;
        }

        /* CONTEÚDO DO CARD: TRANSIÇÃO E OPACIDADE DO MODELO DE REFERÊNCIA */
        .carousel .card > * {
            box-sizing: border-box;
            transition: all 0.3s ease-out;
            opacity: var(--active, 1);
        }

        .card-container[style*="--active: 1"] .card > *,
        .card-container[style*="--active:1"] .card > * {
            opacity: 1 !important;
            pointer-events: auto !important;
        }

        .card-container:not([style*="--active: 1"]):not([style*="--active:1"]) .card > * {
            opacity: 0 !important;
            pointer-events: none !important;
        }

        .card::-webkit-scrollbar {
            width: 3px;
        }
        .card::-webkit-scrollbar-track {
            background: transparent;
        }
        .card::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 3px;
        }

        .card-brand-title {
            font-size: 19px;
            font-weight: 700;
            color: #FFFFFF;
            margin: 0;
            line-height: 1.15;
            text-align: center;
            letter-spacing: 0.2px;
        }

        /* CABEÇALHO CENTRALIZADO (TÍTULO NO CENTRO COMO NO MODELO) */
        .carousel .card-top {
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding-bottom: 10px;
            margin-bottom: 4px;
            text-align: center;
        }

        .carousel .card-brand {
            flex-direction: row;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .carousel .card-brand > div:last-child {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 2px;
        }

        .carousel .card-brand .card-brand-title {
            text-align: left;
        }

        .carousel .brand-icon-box {
            width: 36px;
            height: 36px;
            border-radius: 10px;
        }

        .carousel .brand-icon-box svg {
            width: 20px;
            height: 20px;
        }

        .carousel .card-brand .card-sub {
            font-size: 9.5px;
        }

        .carousel .card-top-right {
            justify-content: center;
        }

        /* Efeito de destaque temático Liquid Glass quando o card estiver ativo */
        #card-linux {
            background: linear-gradient(150deg, rgba(16, 28, 20, 0.72) 0%, rgba(8, 14, 10, 0.88) 100%) !important;
            border-color: rgba(34, 197, 94, 0.22);
            border-top-color: rgba(34, 197, 94, 0.45);
        }
        .card-container[style*="--active: 1"] #card-linux,
        .card-container[style*="--active:1"] #card-linux {
            border-color: rgba(34, 197, 94, 0.45);
            border-top-color: rgba(74, 222, 128, 0.75);
            box-shadow: 
                0 30px 70px -10px rgba(0, 0, 0, 0.92),
                0 0 35px -5px rgba(34, 197, 94, 0.25),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.22),
                inset 0 -1px 2px 0 rgba(0, 0, 0, 0.6);
        }

        #card-windows {
            background: linear-gradient(150deg, rgba(14, 26, 36, 0.72) 0%, rgba(7, 13, 20, 0.88) 100%) !important;
            border-color: rgba(56, 189, 248, 0.22);
            border-top-color: rgba(56, 189, 248, 0.45);
        }
        .card-container[style*="--active: 1"] #card-windows,
        .card-container[style*="--active:1"] #card-windows {
            border-color: rgba(56, 189, 248, 0.45);
            border-top-color: rgba(125, 211, 252, 0.75);
            box-shadow: 
                0 30px 70px -10px rgba(0, 0, 0, 0.92),
                0 0 35px -5px rgba(56, 189, 248, 0.25),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.22),
                inset 0 -1px 2px 0 rgba(0, 0, 0, 0.6);
        }

        #card-ps4 {
            background: linear-gradient(150deg, rgba(10, 20, 38, 0.72) 0%, rgba(6, 10, 22, 0.88) 100%) !important;
            border-color: rgba(0, 112, 209, 0.25);
            border-top-color: rgba(56, 189, 248, 0.45);
        }
        .card-container[style*="--active: 1"] #card-ps4,
        .card-container[style*="--active:1"] #card-ps4 {
            border-color: rgba(0, 112, 209, 0.5);
            border-top-color: rgba(96, 165, 250, 0.75);
            box-shadow: 
                0 30px 70px -10px rgba(0, 0, 0, 0.92),
                0 0 35px -5px rgba(0, 112, 209, 0.3),
                inset 0 1px 1px 0 rgba(255, 255, 255, 0.22),
                inset 0 -1px 2px 0 rgba(0, 0, 0, 0.6);
        }

        /* BOTÕES DE NAVEGAÇÃO PASSAR PARA DIREITA E ESQUERDA (EXATO MODELO IMAGEM 2) */
        .nav {
            color: #FFFFFF;
            position: absolute;
            display: flex;
            align-items: center;
            justify-content: center;
            top: 50%;
            z-index: 50;
            cursor: pointer;
            user-select: none;
            background: transparent !important;
            border: none !important;
            outline: none;
            padding: 0;
            width: 60px;
            height: 60px;
            transition: all 0.3s ease-out;
            line-height: 1;
        }

        .nav svg {
            width: 56px !important;
            height: 56px !important;
            stroke: #FFFFFF;
            stroke-width: 2.4;
            fill: none;
            filter: drop-shadow(0 2px 10px rgba(0, 0, 0, 0.95));
            transition: stroke 0.25s ease, filter 0.25s ease, transform 0.25s ease;
        }

        .nav:hover svg {
            stroke: #FFFFFF;
            filter: drop-shadow(0 0 16px rgba(255, 255, 255, 0.7));
            transform: scale(1.15);
        }

        .nav.left {
            left: 0;
            transform: translateX(calc(-100% - 10px)) translateY(-50%);
        }

        .nav.right {
            right: 0;
            transform: translateX(calc(100% + 10px)) translateY(-50%);
        }

        @media (max-width: 900px) {
            .nav {
                width: 44px;
                height: 44px;
            }
            .nav svg {
                width: 38px !important;
                height: 38px !important;
            }
            .nav.left {
                transform: translateX(calc(-100% - 4px)) translateY(-50%);
            }
            .nav.right {
                transform: translateX(calc(100% + 4px)) translateY(-50%);
            }
        }

        .card-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 5px;
            border-bottom: 1px solid var(--borda);
        }

        .card-brand {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .brand-icon-box {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            display: grid;
            place-items: center;
            background: var(--bg-secundario);
            border: 1px solid var(--borda);
        }

        .brand-icon-box svg {
            width: 14px;
            height: 14px;
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
            font-size: 12.5px;
            font-weight: 700;
            color: var(--ink);
            line-height: 1.1;
        }

        .card-brand .card-sub {
            font-size: 9px;
            color: var(--ink-muted);
        }

        .card-top-right {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .pill-status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 9.5px;
            font-weight: 600;
            padding: 2px 6px;
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
            width: 20px;
            height: 20px;
            border-radius: 5px;
            display: grid;
            place-items: center;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-card-close:hover {
            color: #fff;
            border-color: var(--vermelho);
            background: rgba(239, 68, 68, 0.15);
        }

        /* =============================================================
           4 MÉTRICAS-CHAVE EM GRID 2x2 LIQUID GLASS
           ============================================================= */
        .quick-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 8px 10px;
        }

        .quick-metric {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.04) 0%, rgba(255, 255, 255, 0.01) 100%);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-top: 1px solid rgba(255, 255, 255, 0.18);
            border-radius: 10px;
            padding: 8px 12px;
            display: flex;
            flex-direction: column;
            gap: 4px;
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.06), 0 3px 8px rgba(0, 0, 0, 0.25);
            transition: border-color 0.2s ease, background 0.2s ease, transform 0.2s ease;
        }

        .quick-metric:hover {
            border-color: rgba(255, 255, 255, 0.18);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.06) 0%, rgba(255, 255, 255, 0.02) 100%);
            transform: translateY(-1px);
        }

        .quick-metric .q-label {
            font-size: 8.5px;
            color: rgba(255, 255, 255, 0.55);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 600;
            line-height: 1.2;
            margin-bottom: 2px;
        }

        .quick-metric .q-val {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--ink);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            line-height: 1.2;
        }

        .quick-metric .q-val.ok {
            color: var(--brand);
            text-shadow: 0 0 10px rgba(34, 197, 94, 0.35);
        }

        /* =============================================================
           BOX DE ARMAZENAMENTO PRINCIPAL LIQUID GLASS
           ============================================================= */
        .main-storage-box {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.045) 0%, rgba(255, 255, 255, 0.015) 100%);
            border: 1px solid rgba(255, 255, 255, 0.09);
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 11px 13px;
            display: flex;
            flex-direction: column;
            gap: 9px;
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.08), 0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .storage-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1px;
        }

        .storage-title {
            font-size: 11px;
            font-weight: 600;
            color: var(--ink);
            letter-spacing: 0.15px;
        }

        .storage-pct {
            font-size: 11px;
            font-weight: 800;
            padding: 2px 7px;
            border-radius: 6px;
            letter-spacing: 0.3px;
        }

        .storage-pct.normal {
            color: var(--brand);
            background: rgba(34, 197, 94, 0.14);
            border: 1px solid rgba(34, 197, 94, 0.35);
            text-shadow: 0 0 8px rgba(34, 197, 94, 0.4);
        }
        .storage-pct.atencao {
            color: var(--amarelo);
            background: rgba(245, 158, 11, 0.14);
            border: 1px solid rgba(245, 158, 11, 0.35);
            text-shadow: 0 0 8px rgba(245, 158, 11, 0.4);
        }
        .storage-pct.critico {
            color: var(--vermelho);
            background: rgba(239, 68, 68, 0.14);
            border: 1px solid rgba(239, 68, 68, 0.35);
            text-shadow: 0 0 8px rgba(239, 68, 68, 0.4);
        }

        .storage-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            background: rgba(0, 0, 0, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            padding: 8px 12px;
            border-radius: 8px;
            gap: 8px;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.5);
        }

        .storage-stats .s-lbl {
            font-size: 8px;
            font-weight: 600;
            color: rgba(255, 255, 255, 0.45);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            display: block;
            margin-bottom: 4px;
        }

        .storage-stats .s-num {
            font-size: 11.5px;
            font-weight: 700;
            color: #FFFFFF;
        }

        .storage-stats .s-num.livre {
            color: var(--brand);
            text-shadow: 0 0 8px rgba(34, 197, 94, 0.35);
        }

        .storage-bar {
            width: 100%;
            height: 6px;
            background: rgba(0, 0, 0, 0.55);
            border-radius: 9999px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, 0.08);
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.6);
            margin-top: 2px;
        }

        .storage-bar-fill {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.45s ease;
        }

        .storage-bar-fill.normal {
            background: linear-gradient(90deg, #16a34a 0%, #22c55e 100%);
            box-shadow: 0 0 10px rgba(34, 197, 94, 0.7);
        }
        .storage-bar-fill.atencao {
            background: linear-gradient(90deg, #d97706 0%, #f59e0b 100%);
            box-shadow: 0 0 10px rgba(245, 158, 11, 0.7);
        }
        .storage-bar-fill.critico {
            background: linear-gradient(90deg, #dc2626 0%, #ef4444 100%);
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.7);
        }

        /* GRID INFERIOR (DISCO TOTAL E MODELO) */
        .bottom-grid {
            display: grid;
            grid-template-columns: minmax(0, 0.85fr) minmax(0, 1.15fr);
            gap: 8px 10px;
            align-items: stretch;
        }

        /* Modelo do disco: quebra em linhas em vez de cortar o texto */
        .bottom-grid .quick-metric .q-val {
            white-space: normal !important;
            overflow: visible !important;
            text-overflow: clip !important;
            overflow-wrap: anywhere;
            line-height: 1.35;
        }

        /* CAIXA DE CONTROLES DO PS4 LIQUID GLASS */
        .ps4-controls-box {
            display: flex;
            flex-direction: column;
            gap: 9px;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.04) 0%, rgba(255, 255, 255, 0.01) 100%);
            padding: 10px 13px;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-top: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: inset 0 1px 1px rgba(255, 255, 255, 0.06), 0 3px 10px rgba(0, 0, 0, 0.3);
        }

        /* INFOGRÁFICO RESUMIDO E COMPACTO */
        .infografico-mini {
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.04) 0%, rgba(255, 255, 255, 0.01) 100%);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-top: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 10px;
            padding: 6px 10px;
            display: flex;
            flex-direction: column;
            gap: 4px;
        }

        .info-mini-head {
            display: flex;
            justify-content: space-between;
            font-size: 9.5px;
            color: var(--ink-sec);
        }

        .barra-seg-mini {
            display: flex;
            height: 6px;
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
            gap: 6px;
            font-size: 8.5px;
            color: var(--ink-sec);
        }

        .leg-mini-item {
            display: inline-flex;
            align-items: center;
            gap: 3px;
        }

        .leg-mini-item .ponto {
            width: 5px;
            height: 5px;
            border-radius: 50%;
        }

        /* BOTÕES DE CONTROLE DO PS4 */
        .btn-ps4-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.06) 0%, rgba(255, 255, 255, 0.015) 100%);
            color: var(--ink);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12), 0 3px 8px rgba(0, 0, 0, 0.3);
            transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
        }

        .btn-ps4-wake:hover {
            border-color: rgba(34, 197, 94, 0.5);
            border-top-color: rgba(74, 222, 128, 0.8);
            color: #4ade80;
            background: rgba(34, 197, 94, 0.15);
            box-shadow: 0 0 15px rgba(34, 197, 94, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }

        .btn-ps4-standby:hover {
            border-color: rgba(245, 158, 11, 0.5);
            border-top-color: rgba(251, 191, 36, 0.8);
            color: #fbbf24;
            background: rgba(245, 158, 11, 0.15);
            box-shadow: 0 0 15px rgba(245, 158, 11, 0.3), inset 0 1px 0 rgba(255, 255, 255, 0.25);
            transform: translateY(-1px);
        }

        .btn-ps4-action:active {
            transform: scale(0.98);
        }

        .btn-ps4-action:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .card-footer-ping {
            font-size: 11px;
            color: var(--ink-muted);
            text-align: center;
            margin-top: 14px;
            padding-top: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            letter-spacing: 0.2px;
        }

        .card-ping-val {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 4px;
        }

        .card-ping-val.ping-verde {
            color: #22C55E !important;
            text-shadow: 0 0 8px rgba(34, 197, 94, 0.4);
        }
        .card-ping-val.ping-amarelo {
            color: #F59E0B !important;
            text-shadow: 0 0 8px rgba(245, 158, 11, 0.4);
        }
        .card-ping-val.ping-vermelho {
            color: #EF4444 !important;
            text-shadow: 0 0 8px rgba(239, 68, 68, 0.4);
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
            .painel-main-layout {
                padding: 24px 24px;
                gap: 28px;
            }
            .compact-card {
                max-width: 100%;
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
            .painel-main-layout {
                flex-direction: column;
                align-items: stretch;
                padding: 14px 12px 40px 12px;
                gap: 0;
            }
            .mobile-hidebar-bar {
                display: block;
            }
            /* SIDEBAR VIRA HIDEBAR NO MOBILE */
            .network-sidebar {
                width: 100%;
                max-height: 0;
                opacity: 0;
                overflow: hidden;
                pointer-events: none;
                transform: translateY(-8px);
                transition: max-height 0.35s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease, transform 0.25s ease, margin 0.3s ease;
                margin-bottom: 0;
            }
            .network-sidebar.hidebar-open {
                max-height: 500px;
                opacity: 1;
                pointer-events: auto;
                transform: translateY(0);
                margin-bottom: 16px;
            }
            .painel-center-stage {
                width: 100%;
                min-height: auto;
                padding: 0;
            }
            .compact-card {
                width: 100%;
                max-width: 100%;
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

            <button type="button" class="status-alerta-badge <?= $temAlerta20GB ? 'atencao' : 'normal' ?>" id="header-alerta-badge" onclick="verificarNotificacaoEspaco()" title="Clique para verificar notificações de armazenamento">
                <span class="badge-icon" id="header-alerta-icone">
                    <?php if ($temAlerta20GB): ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                            <line x1="12" y1="9" x2="12" y2="13"></line>
                            <line x1="12" y1="17" x2="12.01" y2="17"></line>
                        </svg>
                    <?php else: ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="20 6 9 17 4 12"></polyline>
                        </svg>
                    <?php endif; ?>
                </span>
                <span id="header-alerta-texto"><?= $temAlerta20GB ? 'Atenção' : 'Normal' ?></span>
            </button>

            <button id="btn-sync-top" class="btn-sync" onclick="sincronizarManual()" title="Atualizar dados de todas as fontes agora">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21.5 2v6h-6M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"></path>
                </svg>
                <span id="btn-sync-text">Atualizar dados</span>
            </button>

            <!-- BADGE DO OPERADOR AUTENTICADO E BOTÃO LOGOUT -->
            <div class="user-session-badge" title="Usuário conectado">
                <span class="user-name-tag">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="color: var(--brand);">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span><?= htmlspecialchars($usuarioLogado) ?></span>
                </span>
                <a href="logout.php" class="btn-logout" title="Encerrar sessão">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span>Sair</span>
                </a>
            </div>
        </div>
    </header>

    <!-- NOTIFICAÇÃO TOAST VISUAL DE SINCRONIZAÇÃO -->
    <div id="sync-toast" class="sync-toast" style="display:none"></div>

    <!-- =============================================================
         2. LAYOUT PRINCIPAL: SIDEBAR ESQUERDA (REDE) + PALCO CENTRAL
         ============================================================= -->
    <main class="painel-main-layout" id="painel-main-layout">

        <!-- TOGGLE DA HIDEBAR NO CELULAR -->
        <div class="mobile-hidebar-bar">
            <button type="button" class="btn-hidebar-toggle" id="btn-hidebar-toggle" onclick="toggleMobileHidebar()" aria-expanded="false" title="Mostrar/ocultar menu de dispositivos">
                <div class="hidebar-toggle-left">
                    <span class="pulse-net-dot"></span>
                    <span class="hidebar-toggle-title">Sistemas e Hosts</span>
                </div>
                <div class="hidebar-toggle-right" style="display: flex; align-items: center; gap: 8px;">
                    <span id="hidebar-active-label" style="color: var(--brand); font-weight: 700;">LINUX</span>
                    <svg class="arrow-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </div>
            </button>
        </div>

        <!-- SIDEBAR FLUTUANTE À ESQUERDA (SEM LINHAS EXTERNAS) -->
        <aside class="network-sidebar" id="network-sidebar" aria-label="Sistemas e Hosts Conectados">
            <div class="sidebar-net-header" id="sidebar-net-header">
                <div class="net-radar-pulse is-online" id="net-radar-pulse">
                    <span class="radar-ping"></span>
                    <span class="radar-dot"></span>
                </div>
                <div class="net-header-info">
                    <span class="net-badge-title">Sistemas e Hosts</span>
                    <span class="net-badge-sub">Dispositivos Conectados</span>
                </div>
            </div>

            <div class="devices-stack" id="devices-stack">
                <!-- BOTÃO LINUX -->
                <?php $linOn = true; ?>
                <button type="button" class="device-btn <?= $linOn ? 'host-online' : 'host-offline' ?>" id="btn-dev-linux" data-section="linux" data-online="<?= $linOn ? '1' : '0' ?>" onclick="selectDevice('linux')" aria-pressed="false">
                    <span class="btn-os-icon">
                        <svg viewBox="0 0 24 24" fill="#FCC624">
                            <path d="M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489a.424.424 0 00-.11.135c-.26.268-.45.6-.663.839-.199.199-.485.267-.797.4-.313.136-.658.269-.864.68-.09.189-.136.394-.132.602 0 .199.027.4.055.536.058.399.116.728.04.97-.249.68-.28 1.145-.106 1.484.174.334.535.47.94.601.81.2 1.91.135 2.774.6.926.466 1.866.67 2.616.47.526-.116.97-.464 1.208-.946.587-.003 1.23-.269 2.26-.334.699-.058 1.574.267 2.577.2.025.134.063.198.114.333l.003.003c.391.778 1.113 1.132 1.884 1.071.771-.06 1.592-.536 2.257-1.306.631-.765 1.683-1.084 2.378-1.503.348-.199.629-.469.649-.853.023-.4-.2-.811-.714-1.376v-.097l-.003-.003c-.17-.2-.25-.535-.338-.926-.085-.401-.182-.786-.492-1.046h-.003c-.059-.054-.123-.067-.188-.135a.357.357 0 00-.19-.064c.431-1.278.264-2.55-.173-3.694-.533-1.41-1.465-2.638-2.175-3.483-.796-1.005-1.576-1.957-1.56-3.368.026-2.152.236-6.133-3.544-6.139zm.529 3.405h.013c.213 0 .396.062.584.198.19.135.33.332.438.533.105.259.158.459.166.724 0-.02.006-.04.006-.06v.105a.086.086 0 01-.004-.021l-.004-.024a1.807 1.807 0 01-.15.706.953.953 0 01-.213.335.71.71 0 00-.088-.042c-.104-.045-.198-.064-.284-.133a1.312 1.312 0 00-.22-.066c.05-.06.146-.133.183-.198.053-.128.082-.264.088-.402v-.02a1.21 1.21 0 00-.061-.4c-.045-.134-.101-.2-.183-.333-.084-.066-.167-.132-.267-.132h-.016c-.093 0-.176.03-.262.132a.8.8 0 00-.205.334 1.18 1.18 0 00-.09.4v.019c.002.089.008.179.02.267-.193-.067-.438-.135-.607-.202a1.635 1.635 0 01-.018-.2v-.02a1.772 1.772 0 01.15-.768c.082-.22.232-.406.43-.533a.985.985 0 01.594-.2zm-2.962.059h.036c.142 0 .27.048.399.135.146.129.264.288.344.465.09.199.14.4.153.667v.004c.007.134.006.2-.002.266v.08c-.03.007-.056.018-.083.024-.152.055-.274.135-.393.2.012-.09.013-.18.003-.267v-.015c-.012-.133-.04-.2-.082-.333a.613.613 0 00-.166-.267.248.248 0 00-.183-.064h-.021c-.071.006-.13.04-.186.132a.552.552 0 00-.12.27.944.944 0 00-.023.33v.015c.012.135.037.2.08.334.046.134.098.2.166.268.01.009.02.018.034.024-.07.057-.117.07-.176.136a.304.304 0 01-.131.068 2.62 2.62 0 01-.275-.402 1.772 1.772 0 01-.155-.667 1.759 1.759 0 01.08-.668 1.43 1.43 0 01.283-.535c.128-.133.26-.2.418-.2zm1.37 1.706c.332 0 .733.065 1.216.399.293.2.523.269 1.052.468h.003c.255.136.405.266.478.399v-.131a.571.571 0 01.016.47c-.123.31-.516.643-1.063.842v.002c-.268.135-.501.333-.775.465-.276.135-.588.292-1.012.267a1.139 1.139 0 01-.448-.067 3.566 3.566 0 01-.322-.198c-.195-.135-.363-.332-.612-.465v-.005h-.005c-.4-.246-.616-.512-.686-.71-.07-.268-.005-.47.193-.6.224-.135.38-.271.483-.336.104-.074.143-.102.176-.131h.002v-.003c.169-.202.436-.47.839-.601.139-.036.294-.065.466-.065zm2.8 2.142c.358 1.417 1.196 3.475 1.735 4.473.286.534.855 1.659 1.102 3.024.156-.005.33.018.513.064.646-1.671-.546-3.467-1.089-3.966-.22-.2-.232-.335-.123-.335.59.534 1.365 1.572 1.646 2.757.13.535.16 1.104.021 1.67.067.028.135.06.205.067 1.032.534 1.413.938 1.23 1.537v-.043c-.06-.003-.12 0-.18 0h-.016c.151-.467-.182-.825-1.065-1.224-.915-.4-1.646-.336-1.77.465-.008.043-.013.066-.018.135-.068.023-.139.053-.209.064-.43.268-.662.669-.793 1.187-.13.533-.17 1.156-.205 1.869v.003c-.02.334-.17.838-.319 1.35-1.5 1.072-3.58 1.538-5.348.334a2.645 2.645 0 00-.402-.533 1.45 1.45 0 00-.275-.333c.182 0 .338-.03.465-.067a.615.615 0 00.314-.334c.108-.267 0-.697-.345-1.163-.345-.467-.931-.995-1.788-1.521-.63-.4-.986-.87-1.15-1.396-.165-.534-.143-1.085-.015-1.645.245-1.07.873-2.11 1.274-2.763.107-.065.037.135-.408.974-.396.751-1.14 2.497-.122 3.854a8.123 8.123 0 01.647-2.876c.564-1.278 1.743-3.504 1.836-5.268.048.036.217.135.289.202.218.133.38.333.59.465.21.201.477.335.876.335.039.003.075.006.11.006.412 0 .73-.134.997-.268.29-.134.52-.334.74-.4h.005c.467-.135.835-.402 1.044-.7zm2.185 8.958c.037.6.343 1.245.882 1.377.588.134 1.434-.333 1.791-.765l.211-.01c.315-.007.577.01.847.268l.003.003c.208.199.305.53.391.876.085.4.154.78.409 1.066.486.527.645.906.636 1.14l.003-.007v.018l-.003-.012c-.015.262-.185.396-.498.595-.63.401-1.746.712-2.457 1.57-.618.737-1.37 1.14-2.036 1.191-.664.053-1.237-.2-1.574-.898l-.005-.003c-.21-.4-.12-1.025.056-1.69.176-.668.428-1.344.463-1.897.037-.714.076-1.335.195-1.814.12-.465.308-.797.641-.984l.045-.022zm-10.814.049h.01c.053 0 .105.005.157.014.376.055.706.333 1.023.752l.91 1.664.003.003c.243.533.754 1.064 1.189 1.637.434.598.77 1.131.729 1.57v.006c-.057.744-.48 1.148-1.125 1.294-.645.135-1.52.002-2.395-.464-.968-.536-2.118-.469-2.857-.602-.369-.066-.61-.2-.723-.4-.11-.2-.113-.602.123-1.23v-.004l.002-.003c.117-.334.03-.752-.027-1.118-.055-.401-.083-.71.043-.94.16-.334.396-.4.69-.533.294-.135.64-.202.915-.47h.002v-.002c.256-.268.445-.601.668-.838.19-.201.38-.336.663-.336zm7.159-9.074c-.435.201-.945.535-1.488.535-.542 0-.97-.267-1.28-.466-.154-.134-.28-.268-.373-.335-.164-.134-.144-.333-.074-.333.109.016.129.134.199.2.096.066.215.2.36.333.292.2.68.467 1.167.467.485 0 1.053-.267 1.398-.466.195-.135.445-.334.648-.467.156-.136.149-.267.279-.267.128.016.034.134-.147.332a8.097 8.097 0 01-.69.468zm-1.082-1.583V5.64c-.006-.02.013-.042.029-.05.074-.043.18-.027.26.004.063 0 .16.067.15.135-.006.049-.085.066-.135.066-.055 0-.092-.043-.141-.068-.052-.018-.146-.008-.163-.065zm-.551 0c-.02.058-.113.049-.166.066-.047.025-.086.068-.14.068-.05 0-.13-.02-.136-.068-.01-.066.088-.133.15-.133.08-.031.184-.047.259-.005.019.009.036.03.03.05v.02h.003z"/>
                        </svg>
                    </span>
                    <span class="btn-dev-label">Linux</span>
                </button>

                <!-- BOTÃO WINDOWS -->
                <?php $winOn = !empty($windows['ativo_online']); ?>
                <button type="button" class="device-btn <?= $winOn ? 'host-online' : 'host-offline' ?>" id="btn-dev-windows" data-section="windows" data-online="<?= $winOn ? '1' : '0' ?>" onclick="selectDevice('windows')" aria-pressed="false">
                    <span class="btn-os-icon">
                        <svg viewBox="0 0 24 24" fill="#38BDF8">
                            <path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.902-1.799"/>
                        </svg>
                    </span>
                    <span class="btn-dev-label">Windows</span>
                </button>

                <!-- BOTÃO PS4 -->
                <?php $ps4On = !empty($ps4['online']); ?>
                <button type="button" class="device-btn <?= $ps4On ? 'host-online' : 'host-offline' ?>" id="btn-dev-ps4" data-section="ps4" data-online="<?= $ps4On ? '1' : '0' ?>" onclick="selectDevice('ps4')" aria-pressed="false">
                    <span class="btn-os-icon">
                        <svg viewBox="0 0 24 24" fill="#0070D1">
                            <path d="M8.984 2.596v17.547l3.915 1.261V6.688c0-.69.304-1.151.794-.991.636.18.76.814.76 1.505v5.875c2.441 1.193 4.362-.002 4.362-3.152 0-3.237-1.126-4.675-4.438-5.827-1.307-.448-3.728-1.186-5.39-1.502zm4.656 16.241l6.296-2.275c.715-.258.826-.625.246-.818-.586-.192-1.637-.139-2.357.123l-4.205 1.5V14.98l.24-.085s1.201-.42 2.913-.615c1.696-.18 3.785.03 5.437.661 1.848.601 2.04 1.472 1.576 2.072-.465.6-1.622 1.036-1.622 1.036l-8.544 3.107V18.86zM1.807 18.6c-1.9-.545-2.214-1.668-1.352-2.32.801-.586 2.16-1.052 2.16-1.052l5.615-2.013v2.313L4.205 17c-.705.271-.825.632-.239.826.586.195 1.637.15 2.343-.12L8.247 17v2.074c-.12.03-.256.044-.39.073-1.939.331-3.996.196-6.038-.479z"/>
                        </svg>
                    </span>
                    <span class="btn-dev-label">PS4</span>
                </button>
            </div>
        </aside>

        <!-- PALCO CENTRAL ONDE OS CARDS SE ABREM NO MODELO CARROSSEL 3D -->
        <section class="painel-center-stage" id="painel-center-stage">
            <div class="carousel-3d-wrapper">
                <!-- BOTÃO NAVEGAÇÃO VOLTAR PARA ESQUERDA -->
                <button type="button" class="nav left" id="carousel-nav-prev" onclick="carouselMove(-1)" aria-label="Voltar para a esquerda" title="Voltar para a esquerda">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"></polyline>
                    </svg>
                </button>

                <!-- CONTAINER DO CARROSSEL 3D -->
                <div class="carousel" id="carousel-3d">

                    <!-- =========================================================
                         CARD 1: SISTEMA LINUX (NOTEBOOK SERVIDOR)
                         ========================================================= -->
                    <div class="card-container" id="container-linux" data-index="0" onclick="carouselGoTo(0)">
                        <div class="card compact-card" id="card-linux">
                            <div class="card-timeout-bar" aria-hidden="true"></div>
                            <div class="card-top">
                                <div class="card-brand">
                                    <div class="brand-icon-box linux-brand" title="Sistema linux">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="#FCC624">
                                            <path d="M12.504 0c-.155 0-.315.008-.48.021-4.226.333-3.105 4.807-3.17 6.298-.076 1.092-.3 1.953-1.05 3.02-.885 1.051-2.127 2.75-2.716 4.521-.278.832-.41 1.684-.287 2.489a.424.424 0 00-.11.135c-.26.268-.45.6-.663.839-.199.199-.485.267-.797.4-.313.136-.658.269-.864.68-.09.189-.136.394-.132.602 0 .199.027.4.055.536.058.399.116.728.04.97-.249.68-.28 1.145-.106 1.484.174.334.535.47.94.601.81.2 1.91.135 2.774.6.926.466 1.866.67 2.616.47.526-.116.97-.464 1.208-.946.587-.003 1.23-.269 2.26-.334.699-.058 1.574.267 2.577.2.025.134.063.198.114.333l.003.003c.391.778 1.113 1.132 1.884 1.071.771-.06 1.592-.536 2.257-1.306.631-.765 1.683-1.084 2.378-1.503.348-.199.629-.469.649-.853.023-.4-.2-.811-.714-1.376v-.097l-.003-.003c-.17-.2-.25-.535-.338-.926-.085-.401-.182-.786-.492-1.046h-.003c-.059-.054-.123-.067-.188-.135a.357.357 0 00-.19-.064c.431-1.278.264-2.55-.173-3.694-.533-1.41-1.465-2.638-2.175-3.483-.796-1.005-1.576-1.957-1.56-3.368.026-2.152.236-6.133-3.544-6.139zm.529 3.405h.013c.213 0 .396.062.584.198.19.135.33.332.438.533.105.259.158.459.166.724 0-.02.006-.04.006-.06v.105a.086.086 0 01-.004-.021l-.004-.024a1.807 1.807 0 01-.15.706.953.953 0 01-.213.335.71.71 0 00-.088-.042c-.104-.045-.198-.064-.284-.133a1.312 1.312 0 00-.22-.066c.05-.06.146-.133.183-.198.053-.128.082-.264.088-.402v-.02a1.21 1.21 0 00-.061-.4c-.045-.134-.101-.2-.183-.333-.084-.066-.167-.132-.267-.132h-.016c-.093 0-.176.03-.262.132a.8.8 0 00-.205.334 1.18 1.18 0 00-.09.4v.019c.002.089.008.179.02.267-.193-.067-.438-.135-.607-.202a1.635 1.635 0 01-.018-.2v-.02a1.772 1.772 0 01.15-.768c.082-.22.232-.406.43-.533a.985.985 0 01.594-.2zm-2.962.059h.036c.142 0 .27.048.399.135.146.129.264.288.344.465.09.199.14.4.153.667v.004c.007.134.006.2-.002.266v.08c-.03.007-.056.018-.083.024-.152.055-.274.135-.393.2.012-.09.013-.18.003-.267v-.015c-.012-.133-.04-.2-.082-.333a.613.613 0 00-.166-.267.248.248 0 00-.183-.064h-.021c-.071.006-.13.04-.186.132a.552.552 0 00-.12.27.944.944 0 00-.023.33v.015c.012.135.037.2.08.334.046.134.098.2.166.268.01.009.02.018.034.024-.07.057-.117.07-.176.136a.304.304 0 01-.131.068 2.62 2.62 0 01-.275-.402 1.772 1.772 0 01-.155-.667 1.759 1.759 0 01.08-.668 1.43 1.43 0 01.283-.535c.128-.133.26-.2.418-.2z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 class="card-brand-title">Sistema linux</h2>
                                        <span class="card-sub">Notebook Servidor • Ubuntu 24.04 LTS</span>
                                    </div>
                                </div>
                                <div class="card-top-right">
                                    <span class="pill-status ok" id="linux-pill-status">
                                        <span class="ponto-pulsar"></span>
                                        <span id="linux-texto-status">Online</span>
                                    </span>
                                </div>
                            </div>

                            <!-- 4 MÉTRICAS-CHAVE EM GRID 2x2 (PADRÃO HARMONIZADO) -->
                            <div class="quick-grid">
                                <div class="quick-metric">
                                    <span class="q-label">Endereço IP</span>
                                    <span class="q-val mono" id="draw-linux-ip" style="color: var(--brand);"><?= htmlspecialchars($linux['ip']) ?></span>
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
                                    <span class="q-label">Saúde Disco</span>
                                    <span class="q-val ok" id="draw-linux-saude"><?= htmlspecialchars(trim(str_ireplace(['(Running)', 'Running'], '', $linux['recursos']['saude_disco'] ?? '100% Saudável'))) ?></span>
                                </div>
                            </div>

                            <!-- ARMAZENAMENTO PRINCIPAL (PARTIÇÃO RAIZ /) -->
                            <?php $raiz = $linux['sistemas_arquivos'][0] ?? null; ?>
                            <div class="main-storage-box">
                                <div class="storage-head">
                                    <span class="storage-title">
                                        <strong>Armazenamento Partição (/)</strong> — <?= htmlspecialchars($raiz['label'] ?? 'ubuntu-lv') ?>
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

                            <!-- INFORMATIVO SOBRE A QUANTIDADE TOTAL DO DISCO (PADRÃO HARMONIZADO) -->
                            <div class="bottom-grid">
                                <div class="quick-metric">
                                    <span class="q-label">Disco Total (SDA)</span>
                                    <span class="q-val mono" id="draw-linux-total" title="Disco SDA: <?= htmlspecialchars($linux['disco_fisico']['total_gb']) ?>" style="font-size: 11px; color: var(--ink);">Disco SDA: <?= $linux['disco_fisico']['total_gb'] ?></span>
                                </div>
                                <div class="quick-metric">
                                    <span class="q-label">Modelo & Pool Livre</span>
                                    <span class="q-val mono ok" title="<?= htmlspecialchars($linux['disco_fisico']['modelo']) ?> • <?= $linux['lvm']['livre_gb'] ?? '212,7 GB' ?> Livre" style="font-size: 10px;"><?= htmlspecialchars($linux['disco_fisico']['modelo']) ?> • <?= $linux['lvm']['livre_gb'] ?? '212,7 GB' ?> Livre</span>
                                </div>
                            </div>

                            <div class="card-footer-ping">
                                Ping do sistema: <span id="card-ping-linux" class="card-ping-val <?= htmlspecialchars($pingsIniciais['linux']['classe'] ?? 'ping-verde') ?>"><?= htmlspecialchars($pingsIniciais['linux']['texto'] ?? '1ms') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- =========================================================
                         CARD 2: SISTEMA WINDOWS (NOTEBOOK ESTAÇÃO)
                         ========================================================= -->
                    <div class="card-container" id="container-windows" data-index="1" onclick="carouselGoTo(1)">
                        <div class="card compact-card" id="card-windows">
                            <div class="card-timeout-bar" aria-hidden="true"></div>
                            <div class="card-top">
                                <div class="card-brand">
                                    <div class="brand-icon-box win-brand" title="Sistema Windows">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#38BDF8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <rect x="3" y="4" width="18" height="12" rx="2"></rect>
                                            <line x1="2" y1="20" x2="22" y2="20"></line>
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 class="card-brand-title">Sistema Windows</h2>
                                        <span class="card-sub">Notebook • Windows 10/11</span>
                                    </div>
                                </div>
                                <div class="card-top-right">
                                    <span class="pill-status <?= $windows['status_classe'] ?? ($windows['ativo_online'] ? 'ok' : 'warn') ?>" id="win-pill-status">
                                        <span class="ponto-pulsar"></span>
                                        <span id="win-texto-status"><?= htmlspecialchars($windows['status_texto'] ?? ($windows['ativo_online'] ? 'Online' : 'Conectado')) ?></span>
                                    </span>
                                </div>
                            </div>

                            <!-- 4 MÉTRICAS-CHAVE EM GRID 2x2 (PADRÃO HARMONIZADO) -->
                            <div class="quick-grid">
                                <div class="quick-metric">
                                    <span class="q-label">Endereço IP</span>
                                    <span class="q-val mono" id="draw-win-ip" style="color: var(--brand);"><?= htmlspecialchars($windows['ip']) ?></span>
                                </div>
                                <div class="quick-metric">
                                    <span class="q-label">Tempo de Atividade</span>
                                    <span class="q-val mono" id="draw-win-uptime" style="color: var(--brand);"><?= htmlspecialchars($windows['recursos']['uptime'] ?? 'Ativo') ?></span>
                                </div>
                                <div class="quick-metric">
                                    <span class="q-label">Memória RAM</span>
                                    <span class="q-val mono" id="draw-win-ram"><?= htmlspecialchars($windows['recursos']['mem_resumo'] ?? '—') ?></span>
                                </div>
                                <div class="quick-metric">
                                    <span class="q-label">Saúde Disco</span>
                                    <span class="q-val ok" id="draw-win-saude"><?= htmlspecialchars(trim(str_ireplace(['(Running)', 'Running'], '', $windows['recursos']['saude_disco'] ?? '100% Saudável'))) ?></span>
                                </div>
                            </div>

                            <!-- ARMAZENAMENTO PRINCIPAL (PARTIÇÃO C:) -->
                            <?php $winDisco = $windows['discos'][0] ?? null; ?>
                            <div id="lista-volumes-windows" style="display: contents;">
                                <?php if ($winDisco): ?>
                                    <div class="main-storage-box">
                                        <div class="storage-head">
                                            <span class="storage-title">
                                                <strong>Armazenamento Partição (<?= htmlspecialchars($winDisco['unidade']) ?>)</strong> — <?= htmlspecialchars($winDisco['rotulo']) ?>
                                            </span>
                                            <span class="storage-pct mono <?= $winDisco['situacao']['classe'] ?>" id="win-c-pct">
                                                <?= number_format($winDisco['pct'], 1, ',', '.') ?>%
                                            </span>
                                        </div>

                                        <div class="storage-stats">
                                            <div>
                                                <span class="s-lbl">Total</span>
                                                <span class="s-num mono" id="win-c-tot"><?= $winDisco['total_gb'] ?></span>
                                            </div>
                                            <div>
                                                <span class="s-lbl">Usado</span>
                                                <span class="s-num mono" id="win-c-usa"><?= $winDisco['usado_gb'] ?></span>
                                            </div>
                                            <div>
                                                <span class="s-lbl">Livre</span>
                                                <span class="s-num mono livre" id="win-c-liv"><?= $winDisco['livre_gb'] ?></span>
                                            </div>
                                        </div>

                                        <div class="storage-bar">
                                            <div class="storage-bar-fill <?= $winDisco['situacao']['classe'] ?>" id="win-c-bar" style="width: <?= number_format($winDisco['pct'], 1, '.', '') ?>%"></div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div style="padding: 14px; text-align: center; color: var(--ink-muted); font-size: 11px; background: var(--bg-secundario); border: 1px solid var(--borda); border-radius: 6px;">
                                        Aguardando telemetria do script PowerShell do Windows
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- INFORMATIVO SOBRE A QUANTIDADE TOTAL DO DISCO (PADRÃO HARMONIZADO) -->
                            <div class="bottom-grid">
                                <div class="quick-metric">
                                    <span class="q-label">Disco Total (C:)</span>
                                    <span class="q-val mono" id="win-disco-total" title="Disco C: <?= htmlspecialchars($windows['recursos']['disco_capacidade_gb'] ?? '111,8 GB') ?>" style="font-size: 11px; color: var(--ink);">Disco C: <?= htmlspecialchars($windows['recursos']['disco_capacidade_gb'] ?? '111,8 GB') ?></span>
                                </div>
                                <div class="quick-metric">
                                    <span class="q-label">Modelo & Sincronização</span>
                                    <span class="q-val mono ok" id="win-disco-modelo" title="<?= htmlspecialchars($windows['recursos']['disco_modelo'] ?? 'KINGSTON SA400S37120G') ?> • Sincronizado" style="font-size: 10px;"><?= htmlspecialchars($windows['recursos']['disco_modelo'] ?? 'KINGSTON SA400S37120G') ?> • Sincronizado</span>
                                </div>
                            </div>

                            <div class="card-footer-ping">
                                Ping do sistema: <span id="card-ping-windows" class="card-ping-val <?= htmlspecialchars($pingsIniciais['windows']['classe'] ?? 'ping-verde') ?>"><?= htmlspecialchars($pingsIniciais['windows']['texto'] ?? '2ms') ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- =========================================================
                         CARD 3: CONSOLE PS4 (PLAYSTATION 4 FAT ORIGINAL)
                         ========================================================= -->
                    <div class="card-container" id="container-ps4" data-index="2" onclick="carouselGoTo(2)">
                        <div class="card compact-card" id="card-ps4">
                            <div class="card-timeout-bar" aria-hidden="true"></div>
                            <div class="card-top">
                                <div class="card-brand">
                                    <div class="brand-icon-box ps4-brand" title="Console PS4">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#0070D1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <line x1="6" y1="12" x2="10" y2="12"></line>
                                            <line x1="8" y1="10" x2="8" y2="14"></line>
                                            <line x1="15" y1="13" x2="15.01" y2="13"></line>
                                            <line x1="18" y1="11" x2="18.01" y2="11"></line>
                                            <rect x="2" y="6" width="20" height="12" rx="6"></rect>
                                        </svg>
                                    </div>
                                    <div>
                                        <h2 class="card-brand-title">Console PS4</h2>
                                        <span class="card-sub">Console Videogame • PS4 Fat Original</span>
                                    </div>
                                </div>
                                <div class="card-top-right">
                                    <span class="pill-status <?= $ps4['online'] ? ($ps4['ddp_confirmado'] ? 'ok' : 'warn') : 'critico' ?>" id="ps4-pill-status">
                                        <span class="ponto-pulsar"></span>
                                        <span id="ps4-texto-status"><?= $ps4['online'] ? ($ps4['ddp_confirmado'] ? 'Online (Ativo)' : 'Conectado') : 'Offline' ?></span>
                                    </span>
                                </div>
                            </div>

                            <!-- 1. IP & ÚLTIMA VERIFICAÇÃO EM GRID 2x2 -->
                            <div class="quick-grid">
                                <div class="quick-metric">
                                    <span class="q-label">Endereço IP</span>
                                    <span class="q-val mono" style="color: var(--brand);"><?= htmlspecialchars($ps4['ip']) ?></span>
                                </div>
                                <div class="quick-metric">
                                    <span class="q-label">Última Verificação</span>
                                    <span class="q-val mono" id="ps4-ultima-verif" style="color: var(--brand);"><?= $ps4['ultima_verificacao'] ?></span>
                                </div>
                                <div class="quick-metric">
                                    <span class="q-label">Latência (Ping)</span>
                                    <span class="q-val mono" id="ps4-ping-val" style="color: var(--brand);"><?= $ps4['ping_ms'] ?? 'Timeout' ?></span>
                                </div>
                                <div class="quick-metric">
                                    <span class="q-label">Identificação Console</span>
                                    <span class="q-val mono" id="ps4-host-val"><?= htmlspecialchars($ps4['host_name']) ?> (<?= htmlspecialchars($ps4['host_id']) ?>)</span>
                                </div>
                            </div>

                            <!-- 2. ARMAZENAMENTO DO PS4 (FAT ORIGINAL - 500 GB HDD) -->
                            <div class="main-storage-box">
                                <div class="storage-head">
                                    <span class="storage-title">
                                        <strong>Armazenamento Interno</strong> — HDD 500 GB
                                    </span>
                                    <span class="storage-pct mono normal" style="font-size: 10px;">
                                        500 GB HDD
                                    </span>
                                </div>

                                <div class="storage-stats">
                                    <div>
                                        <span class="s-lbl">Capacidade</span>
                                        <span class="s-num mono">500,0 GB</span>
                                    </div>
                                    <div>
                                        <span class="s-lbl">Partição Jogos</span>
                                        <span class="s-num mono">~408 GB</span>
                                    </div>
                                    <div>
                                        <span class="s-lbl">Formato</span>
                                        <span class="s-num mono livre">PlayStation OS</span>
                                    </div>
                                </div>

                                <div class="storage-bar">
                                    <div class="storage-bar-fill normal" style="width: 100%"></div>
                                </div>
                            </div>

                            <!-- 3. CONTROLES DO CONSOLE (LIGAR / REPOUSO) -->
                            <div class="ps4-controls-box">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-size: 10px; font-weight: 600; color: var(--ink);">Controles do Console (Rede Local)</span>
                                    <span style="font-size: 9px; color: var(--ink-muted);">MAC: <code class="mono"><?= htmlspecialchars($ps4['mac']) ?></code></span>
                                </div>

                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 7px;">
                                    <button type="button" class="btn-ps4-action btn-ps4-wake" onclick="acionarPS4('wake')" id="btn-ps4-wake" title="Ligar o console a partir do modo de repouso (Magic Packet + DDP Wake)">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                                        </svg>
                                        <span id="btn-ps4-wake-text">Ligar PS4</span>
                                    </button>

                                    <button type="button" class="btn-ps4-action btn-ps4-standby" onclick="acionarPS4('standby')" id="btn-ps4-standby" title="Colocar em Modo de Repouso via protocolo Second Screen autenticado">
                                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>
                                        </svg>
                                        <span id="btn-ps4-standby-text">Colocar em repouso</span>
                                    </button>
                                </div>

                                <!-- FEEDBACK DE STATUS DAS AÇÕES DO PS4 -->
                                <div id="ps4-action-feedback" style="display: none; font-size: 9.5px; padding: 5px 8px; border-radius: 5px; line-height: 1.3;"></div>

                                <!-- CAMPO DE REGISTRO / PAREAMENTO DE CÓDIGO (PIN) DO PS4 -->
                                <div id="ps4-pin-container" style="display: none; margin-top: 2px; padding: 7px; background: rgba(0, 112, 209, 0.08); border: 1px solid rgba(0, 112, 209, 0.25); border-radius: 5px;">
                                    <div style="font-size: 10px; font-weight: 600; color: #38BDF8; margin-bottom: 3px;">Pareamento de Código (PIN) do Console</div>
                                    <div style="font-size: 9px; color: var(--ink-muted); margin-bottom: 6px; line-height: 1.25;">
                                        Digite o código de 8 dígitos exibido na tela da sua TV (Adicionar Dispositivo) para autorizar e colocar em repouso:
                                    </div>
                                    <div style="display: flex; gap: 5px;">
                                        <input type="text" id="ps4-pin-input" placeholder="Ex: 39822790" maxlength="8" style="flex: 1; background: var(--bg-card); border: 1px solid var(--borda); color: var(--ink); border-radius: 4px; padding: 4px 6px; font-size: 11px; font-family: var(--font-mono); text-align: center; letter-spacing: 2px;">
                                        <button type="button" id="btn-ps4-pair" onclick="acionarPS4('standby', true)" style="background: #0070D1; color: #fff; border: none; border-radius: 4px; padding: 4px 10px; font-size: 10px; font-weight: 600; cursor: pointer;">Conectar</button>
                                    </div>
                                </div>
                            </div>

                            <div class="card-footer-ping">
                                Ping do sistema: <span id="card-ping-ps4" class="card-ping-val <?= htmlspecialchars($pingsIniciais['ps4']['classe'] ?? 'ping-vermelho') ?>"><?= htmlspecialchars($pingsIniciais['ps4']['texto'] ?? 'Offline') ?></span>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- BOTÃO NAVEGAÇÃO PASSAR PARA DIREITA -->
                <button type="button" class="nav right" id="carousel-nav-next" onclick="carouselMove(1)" aria-label="Passar para a direita" title="Passar para a direita">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="9 18 15 12 9 6"></polyline>
                    </svg>
                </button>
            </div>
        </section>
    </main>

    <!-- =============================================================
         PLAYER DE MÚSICA LIQUID GLASS — BH FM (CANTO INFERIOR ESQUERDO)
         ============================================================= -->
    <audio id="audio-bhfm" preload="none" src="https://24233.live.streamtheworld.com/BHFMAAC.aac" style="display:none;"></audio>

    <div class="liquid-radio-player" id="liquid-radio-player" aria-label="Player de Rádio BH FM">
        <button type="button" class="radio-play-btn" id="radio-play-btn" onclick="toggleRadio()" title="Reproduzir / Pausar Rádio BH FM">
            <!-- Ícone Play -->
            <svg id="radio-play-icon" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" style="margin-left: 1.5px;">
                <polygon points="5 3 19 12 5 21 5 3"></polygon>
            </svg>
            <!-- Ícone Pause -->
            <svg id="radio-pause-icon" width="10" height="10" viewBox="0 0 24 24" fill="currentColor" style="display:none;">
                <rect x="6" y="4" width="4" height="16"></rect>
                <rect x="14" y="4" width="4" height="16"></rect>
            </svg>
        </button>

        <div class="radio-equalizer" aria-hidden="true">
            <span class="radio-eq-bar"></span>
            <span class="radio-eq-bar"></span>
            <span class="radio-eq-bar"></span>
            <span class="radio-eq-bar"></span>
        </div>

        <div class="radio-info">
            <span class="radio-title">BH FM</span>
            <span class="radio-live-badge">
                <span class="radio-live-dot"></span>
                Ao Vivo
            </span>
        </div>

        <div class="radio-vol-box">
            <button type="button" class="radio-mute-btn" id="radio-mute-btn" onclick="toggleRadioMute()" title="Mutar / Ativar áudio">
                <svg id="icon-vol-high" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <path d="M19.07 4.93a10 10 0 0 1 0 14.14M15.54 8.46a5 5 0 0 1 0 7.07"></path>
                </svg>
                <svg id="icon-vol-muted" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none; color:var(--vermelho);">
                    <polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"></polygon>
                    <line x1="23" y1="9" x2="17" y2="15"></line>
                    <line x1="17" y1="9" x2="23" y2="15"></line>
                </svg>
            </button>
            <input type="range" class="radio-vol-slider" id="radio-vol-slider" min="0" max="1" step="0.05" value="0.8" oninput="setRadioVolume(this.value)" title="Volume da Rádio">
        </div>
    </div>

    <!-- =============================================================
         6. JAVASCRIPT: CONTROLE DE DISPOSITIVOS POR REDE & ANIMAÇÃO FLIP
         ============================================================= -->
    <script>
        (() => {
            const stack = document.getElementById('devices-stack');
            const cardLinux = document.getElementById('card-linux');
            const cardWindows = document.getElementById('card-windows');
            const cardPs4 = document.getElementById('card-ps4');
            const hidebarToggle = document.getElementById('btn-hidebar-toggle');
            const hidebarLabel = document.getElementById('hidebar-active-label');
            const networkSidebar = document.getElementById('network-sidebar');

            let painelAberto = null;
            let activeDevice = null;
            let timerAutoFechar = null;


            function iniciarTimerAutoFechar() {
                pararTimerAutoFechar();
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

            // FLIP ANIMATION (First, Last, Invert, Play) PARA SUBIR O BOTAO INTERAGINDO
            function animateReorder(targetBtn) {
                if (!stack) return;
                const allBtns = [...stack.querySelectorAll('.device-btn')];
                if (allBtns.length <= 1 || allBtns[0] === targetBtn) return; // Ja esta no topo

                // 1. FIRST: Posicoes no viewport antes da reordenacao
                const firstPositions = new Map();
                allBtns.forEach(btn => {
                    firstPositions.set(btn, btn.getBoundingClientRect().top);
                });

                // 2. LAST: Move o elemento no DOM para o topo da pilha
                stack.prepend(targetBtn);

                // 3. INVERT: Aplica transformacao inversa imediata
                allBtns.forEach(btn => {
                    const firstTop = firstPositions.get(btn);
                    const lastTop = btn.getBoundingClientRect().top;
                    const deltaY = firstTop - lastTop;

                    if (deltaY !== 0) {
                        btn.style.transition = 'none';
                        btn.style.transform = `translateY(${deltaY}px)`;
                    }
                });

                // 4. PLAY: Anima suavemente para zero
                requestAnimationFrame(() => {
                    requestAnimationFrame(() => {
                        allBtns.forEach(btn => {
                            btn.style.transition = 'transform 0.45s cubic-bezier(0.16, 1, 0.3, 1), background 0.25s, border-color 0.25s, box-shadow 0.25s';
                            btn.style.transform = '';
                        });
                    });
                });
            }

            function ajustarEscalaCarrossel() {
                const wrap = document.querySelector('.carousel-3d-wrapper');
                if (!wrap) return;
                // Altura real = maior conteúdo entre os cards (nada é cortado, sem rolagem)
                let maxH = 0;
                wrap.querySelectorAll('.card').forEach(c => {
                    const prev = c.style.height;
                    c.style.height = 'auto';
                    maxH = Math.max(maxH, c.offsetHeight);
                    c.style.height = prev;
                });
                if (maxH > 0) wrap.style.height = (maxH + 4) + 'px';
                if (window.innerWidth <= 768) {
                    wrap.style.setProperty('--fit', 1);
                    return;
                }
                const naturalH = wrap.offsetHeight;
                const naturalW = wrap.offsetWidth;
                const availH = window.innerHeight - 52 - 90;
                const availW = window.innerWidth - 120;
                const s = Math.min(1, availH / naturalH, availW / (naturalW * 1.7));
                wrap.style.setProperty('--fit', Math.max(0.4, s).toFixed(3));
            }
            window.addEventListener('resize', ajustarEscalaCarrossel);
            window.addEventListener('load', ajustarEscalaCarrossel);
            if (document.fonts && document.fonts.ready) {
                document.fonts.ready.then(ajustarEscalaCarrossel);
                document.fonts.addEventListener && document.fonts.addEventListener('loadingdone', ajustarEscalaCarrossel);
            }
            window.ajustarEscalaCarrossel = ajustarEscalaCarrossel;
            ajustarEscalaCarrossel();

            const CARDS_COUNT = 3;
            const SECTION_KEYS = ['linux', 'windows', 'ps4'];
            let activeIndex = 1; // Inicia em Windows (1) como no modelo de referência Card 3

            function updateCarousel(newActive) {
                if (newActive < 0) newActive = 0;
                if (newActive >= CARDS_COUNT) newActive = CARDS_COUNT - 1;
                activeIndex = newActive;
                activeDevice = SECTION_KEYS[activeIndex];

                const containers = document.querySelectorAll('.carousel .card-container');
                containers.forEach((cardEl, i) => {
                    const offset = (activeIndex - i) / 3;
                    const direction = Math.sign(activeIndex - i);
                    const absOffset = Math.abs(activeIndex - i) / 3;
                    const isActive = i === activeIndex ? 1 : 0;

                    cardEl.style.setProperty('--active', isActive);
                    cardEl.style.setProperty('--offset', offset);
                    cardEl.style.setProperty('--direction', direction);
                    cardEl.style.setProperty('--abs-offset', absOffset);
                    cardEl.style.zIndex = 10 - Math.round(absOffset * 10);
                    cardEl.style.opacity = Math.abs(activeIndex - i) >= 3 ? '0' : '1';
                    cardEl.style.display = Math.abs(activeIndex - i) > 3 ? 'none' : 'block';
                });

                // Botões de navegação condicionados ao índice (exato modelo do código React de referência)
                const navLeft = document.getElementById('carousel-nav-prev');
                const navRight = document.getElementById('carousel-nav-next');
                if (navLeft) {
                    navLeft.style.display = activeIndex > 0 ? 'flex' : 'none';
                }
                if (navRight) {
                    navRight.style.display = activeIndex < (CARDS_COUNT - 1) ? 'flex' : 'none';
                }

                // Sincroniza estado visual do botão na sidebar 'Sistemas e Hosts'
                const currentSection = SECTION_KEYS[activeIndex];
                if (stack) {
                    stack.querySelectorAll('.device-btn').forEach(btn => {
                        const isSelected = btn.dataset.section === currentSection;
                        btn.classList.toggle('is-active', isSelected);
                        btn.setAttribute('aria-pressed', String(isSelected));
                    });
                }
                if (hidebarLabel) {
                    hidebarLabel.textContent = currentSection.toUpperCase();
                }

                atualizarLuzRadar();
            }

            function carouselMove(delta) {
                const next = activeIndex + delta;
                if (next >= 0 && next < CARDS_COUNT) {
                    updateCarousel(next);
                }
            }

            function carouselGoTo(index) {
                updateCarousel(index);
            }

            function selectDevice(secao) {
                const idx = SECTION_KEYS.indexOf(secao);
                if (idx !== -1) {
                    updateCarousel(idx);
                }
                if (window.innerWidth <= 768 && networkSidebar && networkSidebar.classList.contains('hidebar-open')) {
                    toggleMobileHidebar(false);
                }
            }

            function atualizarLuzRadar() {
                const radar = document.getElementById('net-radar-pulse');
                const netHeader = document.getElementById('sidebar-net-header');
                if (!radar) return;

                // Bolinha do lado esquerdo em Sistemas e Hosts permanece verde por padrão
                radar.classList.remove('is-offline');
                radar.classList.add('is-online');

                if (netHeader) {
                    netHeader.classList.remove('is-offline');
                    netHeader.classList.remove('is-online');
                }
            }

            function fecharPaineis() {
                // Compatibilidade legado
            }

            function toggleMobileHidebar(forceState) {
                if (!networkSidebar) return;
                const shouldOpen = typeof forceState === 'boolean' ? forceState : !networkSidebar.classList.contains('hidebar-open');
                networkSidebar.classList.toggle('hidebar-open', shouldOpen);
                if (hidebarToggle) {
                    hidebarToggle.setAttribute('aria-expanded', String(shouldOpen));
                }
            }

            window.carouselMove = carouselMove;
            window.carouselGoTo = carouselGoTo;
            window.updateCarousel = updateCarousel;
            window.selectDevice = selectDevice;
            window.fecharTodosPaineis = fecharPaineis;
            window.toggleMobileHidebar = toggleMobileHidebar;
            window.atualizarLuzRadar = atualizarLuzRadar;

            // Usa os mesmos ícones oficiais da sidebar (Tux, Windows, PlayStation) nos cards
            SECTION_KEYS.forEach(key => {
                const src = document.querySelector(`#btn-dev-${key} .btn-os-icon svg`);
                const dst = document.querySelector(`#card-${key} .brand-icon-box`);
                if (src && dst) dst.innerHTML = src.outerHTML;
            });

            // Inicia no card do meio (Sistema Windows) para exibir painéis dos dois lados, como no modelo
            updateCarousel(1);

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
        <?php if ($cacheEraAntigo): ?>
        // Cache inicial estava antigo: atualiza em segundo plano após a página carregar
        window.addEventListener('load', () => setTimeout(() => sincronizarDados(true), 50));
        <?php endif; ?>

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

        window.notificacaoEspacoDados = {
            temAlerta: <?= $temAlerta20GB ? 'true' : 'false' ?>,
            alertas: <?= json_encode($alertasEspaco, JSON_UNESCAPED_UNICODE) ?>
        };

        function verificarNotificacaoEspaco() {
            const toast = document.getElementById('sync-toast');
            if (!toast) return;

            const dados = window.notificacaoEspacoDados || { temAlerta: false, alertas: [] };
            toast.style.display = 'flex';

            if (dados.temAlerta) {
                toast.style.borderColor = 'rgba(245, 158, 11, 0.45)';
                toast.style.background = 'rgba(17, 23, 20, 0.95)';
                const itensHtml = dados.alertas.map(a => `<li style="margin-top:2px;">${escapeHtml(a)}</li>`).join('');
                toast.innerHTML = `
                    <div style="display:flex; flex-direction:column; gap:4px; text-align:left;">
                        <div style="display:flex; align-items:center; gap:6px; font-weight:700; color:var(--amarelo);">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                            <span>Notificação de Armazenamento (Atenção)</span>
                        </div>
                        <div style="font-size:11px; color:var(--ink-sec);">
                            Equipamentos com menos de 20 GB de espaço livre disponível:
                            <ul style="margin:4px 0 0 16px; padding:0; color:var(--ink);">
                                ${itensHtml}
                            </ul>
                        </div>
                    </div>
                `;
            } else {
                toast.style.borderColor = 'rgba(34, 197, 94, 0.45)';
                toast.style.background = 'rgba(17, 23, 20, 0.95)';
                toast.innerHTML = `
                    <div style="display:flex; align-items:center; gap:8px;">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#22C55E" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg>
                        <span style="font-size:11.5px; color:var(--ink);"><strong style="color:var(--brand)">Armazenamento Normal:</strong> Todos os equipamentos possuem mais de 20 GB de espaço livre.</span>
                    </div>
                `;
            }

            clearTimeout(window.toastTimeout);
            window.toastTimeout = setTimeout(() => {
                if (toast) toast.style.display = 'none';
            }, 6000);
        }
        window.verificarNotificacaoEspaco = verificarNotificacaoEspaco;

        async function sincronizarDados(manual = false) {
            if (!manual && document.hidden) return;
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
                    if (saude && d.recursos?.saude_disco) saude.textContent = String(d.recursos.saude_disco).replace(/\s*\(Running\)/gi, '').trim();

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

                    // 4 Métricas Chave do Windows
                    const winIp = document.getElementById('draw-win-ip');
                    if (winIp && d.windows.ip) winIp.textContent = d.windows.ip;

                    const winUp = document.getElementById('draw-win-uptime');
                    if (winUp && d.windows.recursos?.uptime) winUp.textContent = d.windows.recursos.uptime;

                    const winRam = document.getElementById('draw-win-ram');
                    if (winRam && d.windows.recursos?.mem_resumo) winRam.textContent = d.windows.recursos.mem_resumo;

                    const winSaude = document.getElementById('draw-win-saude');
                    if (winSaude && d.windows.recursos?.saude_disco) winSaude.textContent = String(d.windows.recursos.saude_disco).replace(/\s*\(Running\)/gi, '').trim();

                    const winTot = document.getElementById('win-disco-total');
                    if (winTot && d.windows.recursos?.disco_capacidade_gb) winTot.textContent = 'Disco C: ' + d.windows.recursos.disco_capacidade_gb;

                    const winMod = document.getElementById('win-disco-modelo');
                    if (winMod && d.windows.recursos?.disco_modelo) winMod.textContent = d.windows.recursos.disco_modelo + ' • Sincronizado';

                    if (d.windows.discos && d.windows.discos.length > 0) {
                        const w = d.windows.discos[0];
                        const cPct = document.getElementById('win-c-pct');
                        const cTot = document.getElementById('win-c-tot');
                        const cUsa = document.getElementById('win-c-usa');
                        const cLiv = document.getElementById('win-c-liv');
                        const cBar = document.getElementById('win-c-bar');

                        const pctVal = typeof w.pct === 'number' ? w.pct : parseFloat(w.pct);
                        const sitCls = w.situacao?.classe || 'normal';

                        if (cPct) {
                            cPct.textContent = pctVal.toFixed(1).replace('.', ',') + '%';
                            cPct.className = 'storage-pct mono ' + sitCls;
                        }
                        if (cTot) cTot.textContent = w.total_gb;
                        if (cUsa) cUsa.textContent = w.usado_gb;
                        if (cLiv) cLiv.textContent = w.livre_gb;
                        if (cBar) {
                            cBar.style.width = pctVal.toFixed(1) + '%';
                            cBar.className = 'storage-bar-fill ' + sitCls;
                        }
                    }
                }

                // Atualizar luzes de status nos botões (Verde = Online, Vermelho = Offline)
                const btnLin = document.getElementById('btn-dev-linux');
                if (btnLin) {
                    btnLin.classList.toggle('host-online', true);
                    btnLin.classList.toggle('host-offline', false);
                }
                const btnWin = document.getElementById('btn-dev-windows');
                if (btnWin && d.windows) {
                    const winIsOn = !!d.windows.ativo_online;
                    btnWin.classList.toggle('host-online', winIsOn);
                    btnWin.classList.toggle('host-offline', !winIsOn);
                }
                const btnPs = document.getElementById('btn-dev-ps4');
                if (btnPs && d.ps4) {
                    const psIsOn = !!d.ps4.online;
                    btnPs.classList.toggle('host-online', psIsOn);
                    btnPs.classList.toggle('host-offline', !psIsOn);
                }

                if (window.atualizarLuzRadar) {
                    window.atualizarLuzRadar();
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

                // 5. Alerta de Armazenamento (< 20 GB livres em qualquer equipamento)
                const limite20GBBytes = 20 * 1024 * 1024 * 1024;
                let listaAlertas20GB = [];

                if (d.linux_volumes) {
                    d.linux_volumes.forEach(v => {
                        if (v.ponto === '/' && v.livre_bytes !== undefined && v.livre_bytes < limite20GBBytes) {
                            listaAlertas20GB.push(`Linux (${v.nome}): apenas ${v.livre_gb} livres (< 20 GB)`);
                        }
                    });
                }
                if (d.windows?.discos) {
                    d.windows.discos.forEach(w => {
                        if (w.livre_bytes !== undefined && w.livre_bytes < limite20GBBytes) {
                            listaAlertas20GB.push(`Windows (${w.unidade}): apenas ${w.livre_gb} livres (< 20 GB)`);
                        }
                    });
                }

                window.notificacaoEspacoDados = {
                    temAlerta: listaAlertas20GB.length > 0,
                    alertas: listaAlertas20GB
                };

                const hBadge = document.getElementById('header-alerta-badge');
                const hBadgeTxt = document.getElementById('header-alerta-texto');
                const hBadgeIcon = document.getElementById('header-alerta-icone');

                if (hBadge && hBadgeTxt && hBadgeIcon) {
                    if (listaAlertas20GB.length > 0) {
                        hBadge.className = 'status-alerta-badge atencao';
                        hBadgeTxt.textContent = 'Atenção';
                        hBadgeIcon.innerHTML = `
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                                <line x1="12" y1="9" x2="12" y2="13"></line>
                                <line x1="12" y1="17" x2="12.01" y2="17"></line>
                            </svg>
                        `;
                    } else {
                        hBadge.className = 'status-alerta-badge normal';
                        hBadgeTxt.textContent = 'Normal';
                        hBadgeIcon.innerHTML = `
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                        `;
                    }
                }

                return d;
            } catch (e) {
                console.warn('Erro na atualização:', e);
                throw e;
            }
        }

        // =============================================================
        // 7. CONTABILIZADOR DE PING EM TEMPO REAL (OTIMIZADO)
        // =============================================================
        async function atualizarPings() {
            if (document.hidden) return;
            try {
                const resp = await fetch('index.php?api=pings&t=' + Date.now(), {
                    headers: { 'Accept': 'application/json' },
                    cache: 'no-store'
                });
                if (!resp.ok) return;
                const d = await resp.json();
                if (d && d.sucesso && d.pings) {
                    ['linux', 'windows', 'ps4'].forEach(host => {
                        const info = d.pings[host];
                        if (!info) return;

                        // Atualiza o ping do sistema centralizado no rodapé do card
                        const cardPing = document.getElementById('card-ping-' + host);
                        if (cardPing) {
                            cardPing.textContent = info.texto;
                            cardPing.className = 'card-ping-val ' + (info.classe || 'ping-vermelho');
                        }

                        const badge = document.getElementById('ping-badge-' + host);
                        if (badge) {
                            badge.textContent = info.texto;
                            badge.className = 'btn-ping-badge ' + (info.classe || 'ping-vermelho');
                            badge.title = 'Latência ICMP: ' + info.texto;
                        }
                    });
                }
            } catch (err) {
                // Silencioso para evitar ruído de console em caso de oscilação momentânea
            }
        }

        // Executa atualização do ping a cada 12 segundos e retoma instantaneamente ao focar na aba
        setInterval(atualizarPings, 12000);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                atualizarPings();
            }
        });

        // =============================================================
        // 8. CONTROLES DO PLAYER LIQUID GLASS — BH FM
        // =============================================================
        const audioRadio = document.getElementById('audio-bhfm');
        const radioPlayerEl = document.getElementById('liquid-radio-player');
        const radioPlayIcon = document.getElementById('radio-play-icon');
        const radioPauseIcon = document.getElementById('radio-pause-icon');
        const radioVolSlider = document.getElementById('radio-vol-slider');

        function toggleRadio() {
            if (!audioRadio) return;
            if (audioRadio.paused) {
                // Carrega stream para garantir transmissão em tempo real sem latência acumulada
                audioRadio.load();
                audioRadio.play().then(() => {
                    if (radioPlayerEl) radioPlayerEl.classList.add('is-playing');
                    if (radioPlayIcon) radioPlayIcon.style.display = 'none';
                    if (radioPauseIcon) radioPauseIcon.style.display = 'block';
                }).catch(err => {
                    console.warn('Erro ao reproduzir Rádio BH FM:', err);
                });
            } else {
                audioRadio.pause();
                if (radioPlayerEl) radioPlayerEl.classList.remove('is-playing');
                if (radioPlayIcon) radioPlayIcon.style.display = 'block';
                if (radioPauseIcon) radioPauseIcon.style.display = 'none';
            }
        }

        function setRadioVolume(val) {
            if (!audioRadio) return;
            const vol = parseFloat(val);
            audioRadio.volume = vol;
            if (audioRadio.muted && vol > 0) {
                audioRadio.muted = false;
                atualizarIconeMute(false);
            }
        }

        function toggleRadioMute() {
            if (!audioRadio) return;
            audioRadio.muted = !audioRadio.muted;
            atualizarIconeMute(audioRadio.muted);
        }

        function atualizarIconeMute(mutado) {
            const icoMuted = document.getElementById('icon-vol-muted');
            const icoHigh = document.getElementById('icon-vol-high');
            if (icoMuted && icoHigh) {
                icoMuted.style.display = mutado ? 'block' : 'none';
                icoHigh.style.display = mutado ? 'none' : 'block';
            }
        }

        if (audioRadio) {
            audioRadio.volume = 0.8;
            audioRadio.addEventListener('pause', () => {
                if (radioPlayerEl) radioPlayerEl.classList.remove('is-playing');
                if (radioPlayIcon) radioPlayIcon.style.display = 'block';
                if (radioPauseIcon) radioPauseIcon.style.display = 'none';
            });
            audioRadio.addEventListener('play', () => {
                if (radioPlayerEl) radioPlayerEl.classList.add('is-playing');
                if (radioPlayIcon) radioPlayIcon.style.display = 'none';
                if (radioPauseIcon) radioPauseIcon.style.display = 'block';
            });
            audioRadio.addEventListener('ended', () => {
                if (radioPlayerEl) radioPlayerEl.classList.remove('is-playing');
                if (radioPlayIcon) radioPlayIcon.style.display = 'block';
                if (radioPauseIcon) radioPauseIcon.style.display = 'none';
            });
        }

        window.toggleRadio = toggleRadio;
        window.setRadioVolume = setRadioVolume;
        window.toggleRadioMute = toggleRadioMute;
        window.atualizarPings = atualizarPings;
    </script>
</body>
</html>
