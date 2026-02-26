<?php
session_start();
$usuarioLogado = $_SESSION['nome_completo'] ?? null;

header('Content-Type: application/json');

$arquivo = __DIR__ . '/historico_atendimento.txt';

$jsonInput = file_get_contents('php://input');
$data = json_decode($jsonInput, true);

if (!$data || !is_array($data)) {
    echo json_encode(['status' => 'error', 'message' => 'JSON inválido']);
    exit;
}

// Novo: parâmetro de confirmação vindo do JavaScript
$confirmadoPeloUsuario = $data['confirmado'] ?? false;

// Colunas fixas
$colunasFixas = [
    'id' => 'ID',
    'data' => 'Data',
    'categoria' => 'Categoria',
    'titulos' => 'Título(s)',
    'temas' => 'Tema(s)',
    'sfSelecionado' => 'Tipo/Fechamento'
];

$dadosPrincipais = $data;
$camposExtras = [];
if (isset($dadosPrincipais['campos']) && is_array($dadosPrincipais['campos'])) {
    $camposExtras = $dadosPrincipais['campos'];
    unset($dadosPrincipais['campos']);
}

if (isset($camposExtras['mapaTitulosTemas']) && is_array($camposExtras['mapaTitulosTemas'])) {
    $camposExtras['mapaTitulosTemas'] = json_encode($camposExtras['mapaTitulosTemas'], JSON_UNESCAPED_UNICODE);
}

if ($usuarioLogado) {
    $camposExtras['usuarioLogado'] = $usuarioLogado;
}

$linhasExistentes = [];
$cabecalho = array_values($colunasFixas);
if (file_exists($arquivo)) {
    $linhasExistentes = file($arquivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!empty($linhasExistentes)) {
        $cabecalho = explode("\t", array_shift($linhasExistentes)); 
    }
}

// Garante que o usuário logado esteja no cabeçalho para validação
if (!in_array('usuarioLogado', $cabecalho)) {
    $cabecalho[] = 'usuarioLogado';
}

foreach ($camposExtras as $campo => $valor) {
    if (!in_array($campo, $cabecalho)) {
        $cabecalho[] = $campo;
    }
}

// --- LÓGICA DE VALIDAÇÃO DE PROPRIEDADE E DUPLICIDADE ---
$idNovo = $dadosPrincipais['id'] ?? null;
$dataNova = $dadosPrincipais['data'] ?? null;

$indiceID = array_search('ID', $cabecalho);
$indiceUser = array_search('usuarioLogado', $cabecalho);
$indiceData = array_search('Data', $cabecalho);

// 1. Verificação de Segurança (Propriedade)
foreach ($linhasExistentes as $linhaAntiga) {
    $valores = explode("\t", $linhaAntiga);
    if ($indiceID !== false && isset($valores[$indiceID]) && $valores[$indiceID] == $idNovo) {
        $donoExistente = $valores[$indiceUser] ?? 'Desconhecido';

        // Se a ocorrência pertence a outra pessoa
        if ($usuarioLogado && $donoExistente !== $usuarioLogado) {
            echo json_encode([
                'status' => 'blocked', 
                'message' => "A ocorrência $idNovo pertence ao usuário: $donoExistente. Você não tem permissão para alterá-la."
            ]);
            exit;
        }

        // Se a ocorrência é minha, mas eu ainda não confirmei a atualização no JavaScript
        if (!$confirmadoPeloUsuario) {
            echo json_encode([
                'status' => 'needs_confirmation',
                'message' => "⚠️ Você já possui um registro para a ocorrência $idNovo. Deseja atualizar os dados?"
            ]);
            exit;
        }
    }
}

// --- LÓGICA DE LIMPEZA E ATUALIZAÇÃO DO CONTEÚDO ---
$conteudoAtualizado = [];
$conteudoAtualizado[] = implode("\t", $cabecalho);

foreach ($linhasExistentes as $linhaAntiga) {
    $valores = explode("\t", $linhaAntiga);
    
    if ($indiceID !== false && isset($valores[$indiceID]) && $valores[$indiceID] == $idNovo) {
        $dataAntiga = $valores[$indiceData] ?? '';
        
        // Se o ID é igual, mas a DATA/HORA é diferente, removemos a antiga para sobrescrever
        if ($dataAntiga !== $dataNova) {
            continue; 
        }
    }

    while (count($valores) < count($cabecalho)) {
        $valores[] = '';
    }
    $conteudoAtualizado[] = implode("\t", $valores);
}

// Monta e adiciona a nova linha
$linhaNova = [];
foreach ($cabecalho as $col) {
    $valor = '';
    $chave = array_search($col, $colunasFixas, true);
    if ($chave === false) $chave = $col;

    if (isset($dadosPrincipais[$chave])) {
        $valor = $dadosPrincipais[$chave];
    } elseif (isset($camposExtras[$chave])) {
        $valor = $camposExtras[$chave];
    }

    $valor = str_replace(["\n", "\r", "\t"], ' ', (string)$valor);
    $linhaNova[] = $valor;
}
$conteudoAtualizado[] = implode("\t", $linhaNova);

// Grava o arquivo com segurança
file_put_contents($arquivo, implode(PHP_EOL, $conteudoAtualizado) . PHP_EOL);

echo json_encode(['status' => 'success']);