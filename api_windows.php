<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Use o método POST']);
    exit;
}

$raw = file_get_contents('php://input');
$dados = json_decode($raw, true);

if (!is_array($dados) || !isset($dados['discos']) || !is_array($dados['discos'])) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Dados inválidos']);
    exit;
}

$discosValidados = [];
foreach ($dados['discos'] as $disco) {
    if (!isset($disco['unidade'], $disco['total_bytes'], $disco['livre_bytes'])) {
        continue;
    }
    $discosValidados[] = [
        'unidade' => (string)$disco['unidade'],
        'rotulo' => (string)($disco['rotulo'] ?? 'Disco Local'),
        'total_bytes' => (int)$disco['total_bytes'],
        'livre_bytes' => (int)$disco['livre_bytes'],
    ];
}

$resultado = [
    'maquina' => 'Windows',
    'recebido_em' => gmdate('c'),
    'discos' => $discosValidados,
    'recursos' => isset($dados['recursos']) && is_array($dados['recursos']) ? $dados['recursos'] : null
];

$json = json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Grava no diretório do painel
$destLocal = __DIR__ . '/windows.json';
@file_put_contents($destLocal, $json, LOCK_EX);

// Tenta também no /var/lib/monitoramento/windows.json se disponível
$destGlobal = '/var/lib/monitoramento/windows.json';
if (@file_exists($destGlobal) && is_writable($destGlobal)) {
    @file_put_contents($destGlobal, $json, LOCK_EX);
}

echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Dados do Windows recebidos e atualizados',
    'quantidade_discos' => count($discosValidados),
    'recursos' => $resultado['recursos'] !== null
]);
