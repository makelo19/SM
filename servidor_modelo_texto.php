<?php
// backup.php
header('Content-Type: application/json');

$backupFile = __DIR__ . '/servidor_modelo_texto/Orientacoes_TransferenciaPF.txt';
$chaveMestra = "Transferencia@15"; // <-- Altere aqui para a sua chave simples

// --- LÓGICA DE GRAVAÇÃO (POST) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $chaveInformada = $input['key'] ?? '';
    $novoConteudo = $input['content'] ?? '';

    // Verifica a chave
    if ($chaveInformada !== $chaveMestra) {
        http_response_code(403);
        echo json_encode(['error' => 'Chave de acesso incorreta.']);
        exit;
    }

    // Tenta salvar o arquivo
    if (file_put_contents($backupFile, $novoConteudo) !== false) {
        echo json_encode(['success' => 'Backup atualizado com sucesso!']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao escrever no arquivo. Verifique as permissões da pasta.']);
    }
    exit;
}

// --- LÓGICA DE LEITURA (GET) ---
if (!file_exists($backupFile)) {
    http_response_code(404);
    echo json_encode(['error' => 'Arquivo de backup não encontrado.']);
    exit;
}

try {
    $content = file_get_contents($backupFile);
    echo json_encode(['data' => $content]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao ler o arquivo: ' . $e->getMessage()]);
}
?>