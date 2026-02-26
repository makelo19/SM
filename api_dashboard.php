<?php
// api_dashboard.php
header('Content-Type: application/json; charset=utf-8');

$arquivo = __DIR__ . '/historico_atendimento.txt';

if (!file_exists($arquivo) || !is_readable($arquivo)) {
    echo json_encode(['erro' => 'Arquivo não encontrado']);
    exit;
}

$linhas = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if (!$linhas || count($linhas) < 1) {
    echo json_encode([]);
    exit;
}

// Cabeçalho (Primeira linha)
$cabecalho = explode("\t", array_shift($linhas));
$cabecalho = array_map('trim', $cabecalho);

$mapaFixos = [
    'ID'              => 'id',
    'Data'            => 'data',
    'Categoria'       => 'categoria',
    'Título(s)'       => 'titulos',
    'Tema(s)'         => 'temas',
    'Tipo/Fechamento' => 'resultado' // Procedente ou Improcedente
];

$dadosCompletos = [];

foreach ($linhas as $linha) {
    $valores = explode("\t", $linha);
    $valores = array_pad($valores, count($cabecalho), '');
    $registro = ['campos_dinamicos' => []];
    $usuarioEncontrado = "Desconhecido";

    foreach ($cabecalho as $index => $coluna) {
        $valor = isset($valores[$index]) ? trim($valores[$index]) : '';

        if (isset($mapaFixos[$coluna])) {
            $registro[$mapaFixos[$coluna]] = $valor;
        } else {
            $registro['campos_dinamicos'][$coluna] = $valor;
            // Captura o usuário para o filtro do painel
            if ($coluna === 'usuarioLogado') {
                $usuarioEncontrado = $valor;
            }
        }
    }
    
    $registro['usuario'] = $usuarioEncontrado;
    $dadosCompletos[] = $registro;
}

echo json_encode($dadosCompletos, JSON_UNESCAPED_UNICODE);