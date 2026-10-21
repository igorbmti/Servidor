<?php
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Use o metodo POST']);
    exit;
}

$entrada = file_get_contents('php://input');
$dados = json_decode($entrada, true);

if (!is_array($dados) || !isset($dados['discos']) ||
    !is_array($dados['discos'])) {
    http_response_code(400);
    echo json_encode(['erro' => 'Dados invalidos']);
    exit;
}

foreach ($dados['discos'] as $disco) {
    if (
        !isset($disco['unidade'], $disco['total_bytes'], $disco['livre_bytes']) ||
        !is_string($disco['unidade']) ||
        !preg_match('/^[A-Z]:$/', $disco['unidade']) ||
        !is_int($disco['total_bytes']) ||
        !is_int($disco['livre_bytes']) ||
        $disco['total_bytes'] <= 0 ||
        $disco['livre_bytes'] < 0 ||
        $disco['livre_bytes'] > $disco['total_bytes']
    ) {
        http_response_code(400);
        echo json_encode(['erro' => 'Informacoes de disco invalidas']);
        exit;
    }
}

$arquivo = '/var/lib/monitoramento/windows.json';

$resultado = [
    'maquina' => 'Windows',
    'recebido_em' => gmdate('c'),
    'discos' => $dados['discos'],
    'recursos' => isset($dados['recursos']) && is_array($dados['recursos']) ? $dados['recursos'] : null
];

$json = json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

if ($json === false ||
    file_put_contents($arquivo, $json, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['erro' => 'Falha ao salvar os dados']);
    exit;
}

echo json_encode([
    'sucesso' => true,
    'mensagem' => 'Dados recebidos',
    'quantidade_discos' => count($dados['discos'])
]);
