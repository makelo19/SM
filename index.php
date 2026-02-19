<?php
// SM - SISTEMA MODULAR - VERSÃO COMPLETA
@set_time_limit(60000);
session_start();

if (!isset($_SESSION['base_context'])) {
    $_SESSION['base_context'] = 'atual'; 
}
if (!isset($_SESSION['selected_years'])) {
    $_SESSION['selected_years'] = [date('Y')];
}
if (!isset($_SESSION['selected_months'])) {
    $_SESSION['selected_months'] = [(int)date('n')];
}

require_once 'classes.php';

// Configurações
date_default_timezone_set('America/Sao_Paulo');

$db = new Database('dados');

// SM Settings - Configurable system parameters
$settings = new Settings('dados/');
$ID_FIELD_KEY = $settings->getIdFieldKey();
$ID_LABEL = $settings->getIdLabel();
$SYSTEM_NAME = $settings->getSystemName();
$CURRENCY_SYMBOL = $settings->getCurrencySymbol();
$CURRENCY_ICON = $settings->getCurrencyIcon();
$IDENT_LABEL = $settings->getIdentificationLabel();
$IDENT_ICON = $settings->getIdentificationIcon();
$IDENT_ID_FIELD = $settings->getIdentificationIdField();

// Configure Database with dynamic ID fields
$db->setIdFields($ID_FIELD_KEY, $IDENT_ID_FIELD);

// Ensure current period exists on startup
$db->ensurePeriodStructure(date('Y'), date('n'));

$indexer = new ProcessIndexer();
$indexer->ensureIndex($db);

$config = new Config($db);
// Ensure fixed fields exist
$config->ensureField('Base_processos_schema', ['key'=>'ID', 'label'=>'ID', 'type'=>'text']);
$config->ensureField('Base_processos_schema', ['key'=>'Nome_atendente', 'label'=>'Nome Atendente', 'type'=>'text']);
$config->ensureField('Base_processos_schema', ['key'=>'DATA', 'label'=>'Data/Hora', 'type'=>'datetime']);
$config->ensureField('Base_processos_schema', ['key'=>'STATUS', 'label'=>'STATUS', 'type'=>'select', 'options'=>'EM ANDAMENTO, CONCLUÍDO, CANCELADO, ASSINADO']);

// Ensure Data_Ultima_Cobranca exists in Processos
$config->ensureField('Base_processos_schema', ['key'=>'Data_Ultima_Cobranca', 'label'=>'Data da Última Cobrança', 'type'=>'date']);
// Ensure Ultima_Alteracao exists and is manual
$config->ensureField('Base_processos_schema', ['key'=>'Ultima_Alteracao', 'label'=>'Data da Última Atualização', 'type'=>'datetime-local']);
// ID Label should be managed via Config UI or Settings defaults. No auto-update here to prevent locking.
// Ensure Data_Lembrete exists
$config->ensureField('Base_processos_schema', ['key'=>'Data_Lembrete', 'label'=>'Data e Hora (Lembrete)', 'type'=>'datetime-local']);

$templates = new Templates($IDENT_ID_FIELD);
$lockManager = new LockManager('dados');
$uploadDir = __DIR__ . '/uploads/';

// Helper to normalize attendant name
if (!function_exists('normalizeName')) {
    function normalizeName($name) {
        return mb_strtoupper(trim(preg_replace('/\s+/', ' ', $name)), 'UTF-8');
    }
}

function deleteDirectory($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// ===================================================================================
// AJAX HANDLERS
// ===================================================================================
if (isset($_POST['acao']) && strpos($_POST['acao'], 'ajax_') === 0) {
    header('Content-Type: application/json');
    
    // Security Check
    if (!isset($_SESSION['logado']) || !$_SESSION['logado']) {
        echo json_encode(['status' => 'error', 'message' => 'Sessão expirada. Por favor, faça login novamente.']);
        exit;
    }

    $act = $_POST['acao'];
    
    if ($act == 'ajax_set_base_selection') {
        $years = $_POST['years'] ?? [];
        $months = $_POST['months'] ?? [];
        
        if (!is_array($years)) $years = [];
        if (!is_array($months)) $months = [];

        $_SESSION['selected_years'] = array_unique(array_filter($years));
        $_SESSION['selected_months'] = array_unique(array_filter($months));

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($act == 'ajax_reactivate_field') {
        $file = $_POST['file'];
        $key = $_POST['key'];
        $config->reactivateField($file, $key);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    // 1. Busca Cliente (Now searches in Processos)
    if ($act == 'ajax_search_client') {
        $cpf = $_POST['cpf'] ?? '';
        $data = null;
        
        $files = $db->getAllProcessFiles();
        // Sort files to search newest first? getAllProcessFiles usually returns alphabetical (date order).
        // We reverse to get newest first.
        rsort($files);
        
        foreach ($files as $f) {
            $rec = $db->find($f, 'CPF', $cpf);
            if ($rec) {
                $data = $rec;
                break;
            }
        }
        
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['found' => !!$data, 'data' => $data]);
        exit;
    }

    // 2. Busca Processos (Modal)
    if ($act == 'ajax_check_cpf_processes') {
        $cpf = $_POST['cpf'] ?? '';
        
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $files = $db->getProcessFiles($years, $months);
        
        $candidates = array_filter(array_unique([
            $cpf,
            format_field_value('CPF', $cpf),
            normalize_field_value('CPF', $cpf)
        ]));

        $res = $db->select($files, ['callback' => function($row) use ($candidates) {
            $rowCpf = get_value_ci($row, 'CPF');
            return in_array($rowCpf, $candidates);
        }], 1, 1000);
        $rows = $res['data'];
        
        $result = [];
        foreach($rows as $r) {
            $result[] = [
                'port' => get_value_ci($r, $ID_FIELD_KEY),
                'data' => get_value_ci($r, 'DATA'),
                'status' => get_value_ci($r, 'STATUS')
            ];
        }
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['found' => !empty($result), 'processes' => $result]);
        exit;
    }
    
    if ($act == 'ajax_search_agency') {
        $ag = $_POST['ag'] ?? '';
        $data = null;
        
        $files = $db->getAllProcessFiles();
        rsort($files);
        
        foreach ($files as $f) {
            // Check if file contains this agency
            // Ideally we want the record to have populated Agency data (e.g. UF, SR).
            // Since we save these in the process record now, we look for any record with this AG.
            // Using findReverse on each file to get latest in that file.
            $rec = $db->findReverse($f, 'AG', $ag);
            if ($rec) {
                // Only return if it has useful agency data? Or just any record?
                // User said "Caso a Agência seja localizada em outro cadastro válido".
                // We assume any record with this AG is valid.
                $data = $rec;
                break;
            }
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['found' => !!$data, 'data' => $data]);
        exit;
    }

    if ($act == 'ajax_check_process') {
        $port = trim($_POST['port'] ?? '');
        
        $data = null;
        
        // Prepare search candidates: Raw Input, Formatted, Normalized
        $candidates = array_unique([
            $port,
            format_field_value($ID_FIELD_KEY, $port),
            normalize_field_value($ID_FIELD_KEY, $port)
        ]);

        foreach ($candidates as $cand) {
            if (!$cand) continue;
            
            $file = $indexer->get($cand);
            if ($file) {
                // Try finding with exact candidate key
                $res = $db->select($file, [$ID_FIELD_KEY => $cand], 1, 1);
                if (!empty($res['data'])) {
                    $data = $res['data'][0];
                    break;
                }
                
                // Fallback: Check if file contains the ID even if stored differently (e.g. key mismatch in indexer vs file)
                // This is expensive, maybe skip? 
                // Indexer key IS the stored ID generally.
            }
        }

        // Fallback: If not found in index, scan files for all candidates
        if (!$data) {
            $files = $db->getAllProcessFiles();
            foreach ($candidates as $cand) {
                if (!$cand) continue;
                $foundFile = $db->findFileForRecord($files, $ID_FIELD_KEY, $cand);
                if ($foundFile) {
                    $res = $db->select($foundFile, [$ID_FIELD_KEY => $cand], 1, 1);
                    if (!empty($res['data'])) {
                        $data = $res['data'][0];
                        // Update Index to prevent future scans
                        // Note: Indexing the *first* matching candidate found
                        $indexer->set($cand, $foundFile);
                        break;
                    }
                }
            }
        }

        $creditData = null;
        if (!$data) {
             // Search in Credit Base if not found in Process Base
             $creditData = $db->find('Identificacao.json', $IDENT_ID_FIELD, $port);
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['found' => !!$data, 'port' => $port, 'credit_data' => $creditData]);
        exit;
    }

    if ($act == 'ajax_check_cert') {
        $cert = $_POST['cert'] ?? '';
        
        $files = $db->getAllProcessFiles();

        $res = $db->select($files, ['callback' => function($row) use ($cert) {
            $val = get_value_ci($row, 'Certificado');
            return $val !== '' && stripos($val, $cert) !== false;
        }], 1, 100);

        $rows = $res['data'];

        $results = [];
        foreach($rows as $r) {
            $results[] = ['port' => get_value_ci($r, $ID_FIELD_KEY), 'data' => get_value_ci($r, 'DATA'), 'status' => get_value_ci($r, 'STATUS'), 'source' => 'Processo'];
        }
        
        // Also check Credits
        $resCred = $db->select('Identificacao.json', ['callback' => function($row) use ($cert) {
            // Check CERTIFICADO (or fallback case-insensitive if needed, but file has CERTIFICADO)
            $val = get_value_ci($row, 'CERTIFICADO');
            return $val !== '' && stripos($val, $cert) !== false;
        }], 1, 100);
        $rowsCred = $resCred['data'];
        
        foreach($rowsCred as $r) {
            // Avoid duplicates if possible, though structure differs
            $exists = false;
            $rPort = get_value_ci($r, $IDENT_ID_FIELD);
            foreach($results as $ex) { if($ex['port'] == $rPort) $exists = true; }
            if(!$exists) {
                $results[] = ['port' => $rPort, 'data' => get_value_ci($r, 'DATA_DEPOSITO') ?: 'N/A', 'status' => get_value_ci($r, 'STATUS') ?: 'Identificação', 'source' => 'Identificacao'];
            }
        }

        ob_clean(); header('Content-Type: application/json');
        if (!empty($results)) {
            echo json_encode(['found' => true, 'count' => count($results), 'processes' => $results]);
        } else {
            echo json_encode(['found' => false]);
        }
        exit;
    }

    if ($act == 'ajax_save_field') {
        $id = $_POST['id'] ?? '';
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';

        if (!$id || !$field) {
            echo json_encode(['status' => 'error', 'message' => 'Parâmetros inválidos']);
            exit;
        }
        
        // Fix for Data_Lembrete format to match system standard (d/m/Y H:i)
        // because the input type=datetime-local sends Y-m-dTH:i
        if (($field === 'Data_Lembrete' || $field === 'Ultima_Alteracao') && !empty($value)) {
             $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
             if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value); // Fallback
             if ($dt) {
                 if($field === 'Ultima_Alteracao') $value = $dt->format('d/m/Y H:i:s');
                 else $value = $dt->format('d/m/Y H:i');
             }
        }
        
        $files = $db->getAllProcessFiles();
        $foundFile = $db->findFileForRecord($files, $ID_FIELD_KEY, $id);
        
        if ($foundFile) {
            $db->update($foundFile, $ID_FIELD_KEY, $id, [$field => $value]);
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'ok']);
            exit;
        }
        
        echo json_encode(['status' => 'error', 'message' => 'Processo não encontrado']);
        exit;
    }

    if ($act == 'ajax_delete_credit_bulk') {
        $ports = $_POST['ports'] ?? [];
        ob_clean(); header('Content-Type: application/json');
        if (!empty($ports)) {
            if ($db->deleteMany('Identificacao.json', $IDENT_ID_FIELD, $ports)) {
                // Cleanup Uploads
                foreach($ports as $p) {
                    $uDir = 'upload/' . $p;
                    if(is_dir($uDir)) deleteDirectory($uDir);
                }
                echo json_encode(['status'=>'ok', 'message'=>'Registros excluídos.']);
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir registros.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Nenhum registro selecionado.']);
        }
        exit;
    }

    if ($act == 'ajax_reorder_fields') {
        $file = $_POST['file'];
        $order = $_POST['order']; 
        $config->reorderFields($file, $order);
        
        // If reordering 'Processos', clear any Dashboard specific order to ensure new schema order is respected
        if ($file === 'Base_processos_schema') {
            $settings->set('dashboard_columns_order', []);
        }
        
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($act == 'ajax_save_columns_order') {
        $file = $_POST['file'];
        $order = $_POST['order'];
        // Reorder schema based on visible columns, preserving hidden ones
        $current = $config->getFields($file);
        $newFields = [];
        $indexed = [];
        foreach($current as $f) $indexed[$f['key']] = $f;
        
        // Add ordered visible fields
        foreach($order as $key) {
            if (isset($indexed[$key])) {
                $newFields[] = $indexed[$key];
                unset($indexed[$key]);
            }
        }
        // Append remaining (hidden) fields
        foreach($indexed as $f) $newFields[] = $f;
        
        $config->saveFields($file, $newFields);
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($act == 'ajax_save_dash_order') {
        $order = $_POST['order'];
        // Use set method, assuming 'dashboard_columns_order' is the key used by getDashboardColumns
        // If getDashboardColumns uses specific logic, we assume it respects this key.
        // We also clear any cache if needed.
        $settings->set('dashboard_columns_order', $order);
        echo json_encode(['status' => 'ok']);
        exit;
    }
    
    if ($act == 'ajax_save_base_order') {
        $base = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $_POST['base']);
        $order = $_POST['order'];
        if($base && is_array($order)) {
            $settings->set('base_columns_order_' . $base, $order);
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($act == 'ajax_get_process_filter_options') {
         ob_clean(); header('Content-Type: application/json');
         echo json_encode(['status'=>'ok', 'status_opts'=>[], 'atendentes_opts'=>[]]);
         exit;
    }

    if ($act == 'ajax_get_base_filter_options') {
        $base = $_POST['base'] ?? '';
        if (!$base) { echo json_encode(['status'=>'error']); exit; }
        
        $schemaKey = $base;
        if ($base === 'Processos') $schemaKey = 'Base_processos_schema';
        
        $fields = $config->getFields($schemaKey);
        $optionsMap = [];
        
        // Identify fields that need options
        $targetFields = [];
        foreach($fields as $f) {
            $fType = $f['type'] ?? 'text';
            $isSpecial = ($fType === 'select');
            // Check visibility
            $show = (isset($f['show_base_filter']) && $f['show_base_filter']) || (!isset($f['show_base_filter']) && isset($f['show_filter']) && $f['show_filter']);
            
            if ($show && $isSpecial) {
                $targetFields[] = $f;
            }
        }
        
        // Determine data source files
        $dataFiles = [];
        if ($base === 'Processos') {
             $years = $_SESSION['selected_years'] ?? [date('Y')];
             $months = $_SESSION['selected_months'] ?? [(int)date('n')];
             $dataFiles = $db->getProcessFiles($years, $months);
        } else {
             $dataFiles = [$base];
        }
        
        foreach($targetFields as $f) {
            $key = $f['key'];
            $opts = [];
            
            // 1. Check Schema Options
            if (!empty($f['options'])) {
                $opts = array_map('trim', explode(',', $f['options']));
            } else {
                // 2. Fetch from Data
                foreach($dataFiles as $df) {
                    $vals = $db->getUniqueValues($df, $key);
                    $opts = array_merge($opts, $vals);
                }
                $opts = array_unique($opts);
                sort($opts);
            }
            // Key for response (matches input name suffix)
            // If key is STATUS, we handle ID selection in JS? 
            // My JS render uses f_KEY.
            // So we return { KEY: [opts], ... }
            $optionsMap[$key] = array_values($opts);
        }
        
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'options'=>$optionsMap]);
        exit;
    }


    // 3. GERAÇÃO DE TEXTO
    if ($act == 'ajax_generate_text') {
        $tplId = $_POST['tpl_id'];
        $formData = json_decode($_POST['data'], true); 
        
        // Busca manual para garantir compatibilidade
        $allTemplates = $templates->getAll();
        $textoBase = '';
        foreach($allTemplates as $t) {
            if($t['id'] == $tplId) {
                $textoBase = $t['corpo'];
                break;
            }
        }
        
        ob_clean(); header('Content-Type: application/json');
        if ($textoBase) {
            // 1. Build Normalized Data Map with Formatting
            $normalizedData = [];
            foreach ($formData as $key => $val) {
                $upperKey = mb_strtoupper($key, 'UTF-8');
                
                // Prioritize non-empty values in case of collision
                if (isset($normalizedData[$upperKey])) {
                    if (trim((string)$normalizedData[$upperKey]) === '' && trim((string)$val) !== '') {
                        // overwrite with new value
                    } else {
                        continue; // keep existing non-empty
                    }
                }
                
                // Formatting: Date YYYY-MM-DD -> DD/MM/YYYY
                if (is_string($val) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $val);
                    if ($dt) $val = $dt->format('d/m/Y');
                }
                
                // Formatting: Empty -> _______
                if (trim((string)$val) === '') {
                    $val = '_______';
                }
                
                $normalizedData[$upperKey] = $val;
            }

            // 2. Replace using Callback (Case Insensitive Matching of Placeholders)
            // This ensures {Certificado}, {CERTIFICADO}, {certificado} are all matched against the data.
            $textoBase = preg_replace_callback('/\{([^}]+)\}/', function($matches) use ($normalizedData) {
                $original = $matches[0];
                $key = trim($matches[1]);
                $upperKey = mb_strtoupper($key, 'UTF-8');
                
                if (isset($normalizedData[$upperKey])) {
                    return $normalizedData[$upperKey];
                }
                return $original;
            }, $textoBase);
            
            echo json_encode(['status' => 'ok', 'text' => $textoBase]);
        } else {
            echo json_encode(['status' => 'error', 'text' => 'Modelo não encontrado.']);
        }
        exit;
    }

    // 4. Salvar Histórico
    if ($act == 'ajax_save_history') {
        $extra = isset($_POST['extra']) ? json_decode($_POST['extra'], true) : []; 
        // If you have additional fields
        $id = $templates->recordHistory($_SESSION['nome_completo'], '', '', $_POST['port'], $_POST['modelo'], $_POST['texto'], $_POST['destinatarios'] ?? '', $extra);
        
        // Update Timestamp
        $port = $_POST['port'];
        $file = $indexer->get($port);
        if (!$file) {
             $years = $_SESSION['selected_years'] ?? [date('Y')];
             $months = $_SESSION['selected_months'] ?? [(int)date('n')];
             $files = $db->getProcessFiles($years, $months);
             $file = $db->findFileForRecord($files, $ID_FIELD_KEY, $port);
        }
        if ($file) {
             $db->update($file, $ID_FIELD_KEY, $port, [
                 'Data' => date('d/m/Y H:i'), 
                 'Nome_atendente' => $_SESSION['nome_completo']
             ]);
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'data' => date('d/m/Y H:i'), 'usuario' => $_SESSION['nome_completo'], 'id' => $id]);
        exit;
    }

    if ($act == 'ajax_delete_history') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = json_decode($ids, true);
        if ($templates->deleteHistoryItems($ids)) {
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    if ($act == 'ajax_delete_process_history') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = json_decode($ids, true);
        if ($db->deleteRecords('Base_registros_dados.json', $ids)) {
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    // 6. Config Auth
    if ($act == 'ajax_get_config_auth') {
        $def = ['required' => true];
        if (is_file('config_auth.json')) {
            $conf = json_decode(file_get_contents('config_auth.json'), true);
            if (is_array($conf)) $def = array_merge($def, $conf);
        }
        echo json_encode(['status'=>'ok', 'required'=>$def['required']]);
        exit;
    }
    if ($act == 'ajax_save_config_auth') {
        $required = filter_var($_POST['required'], FILTER_VALIDATE_BOOLEAN);
        if (file_put_contents('config_auth.json', json_encode(['required'=>$required])) !== false) {
             echo json_encode(['status'=>'ok']);
        } else {
             echo json_encode(['status'=>'error', 'message'=>'Falha ao salvar arquivo.']);
        }
        exit;
    }

    // 5. Locking
    if ($act == 'ajax_acquire_lock') {
        $res = $lockManager->acquireLock($_POST['port'], $_SESSION['nome_completo']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode($res);
        exit;
    }
    if ($act == 'ajax_release_lock') {
        $lockManager->releaseLock($_POST['port'], $_SESSION['nome_completo']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($act == 'ajax_save_credit') {
        ob_clean(); header('Content-Type: application/json');
        $headers = ['STATUS', 'NUMERO_DEPOSITO', 'DATA_DEPOSITO', 'VALOR_DEPOSITO_PRINCIPAL', 'TEXTO_PAGAMENTO', $IDENT_ID_FIELD, 'CERTIFICADO', 'STATUS_2', 'CPF', 'AG'];
        $data = [];
        foreach ($headers as $h) {
             $data[$h] = $_POST[$h] ?? '';
        }

        $port = $data[$IDENT_ID_FIELD];
        $originalPort = $_POST['original_port'] ?? '';
        
        if (!$port) {
            echo json_encode(['status'=>'error', 'message'=>htmlspecialchars($IDENT_LABEL) . ' é obrigatória.']);
            exit;
        }

        // Date Conversion YYYY-MM-DD -> d/m/Y
        if ($data['DATA_DEPOSITO']) {
             $dt = DateTime::createFromFormat('Y-m-d', $data['DATA_DEPOSITO']);
             if ($dt) $data['DATA_DEPOSITO'] = $dt->format('d/m/Y');
        }
        
        if ($originalPort) {
             // Updating
             if ($port != $originalPort) {
                 $exists = $db->find('Identificacao.json', $IDENT_ID_FIELD, $port);
                 if ($exists) {
                     echo json_encode(['status'=>'error', 'message'=>'Nova ' . htmlspecialchars($IDENT_LABEL) . ' já existe na base.']);
                     exit;
                 }
             }
             $res = $db->update('Identificacao.json', $IDENT_ID_FIELD, $originalPort, $data);
             $msg = "Registro atualizado com sucesso!";
        } else {
             // Inserting
             $exists = $db->find('Identificacao.json', $IDENT_ID_FIELD, $port);
             if ($exists) {
                 echo json_encode(['status'=>'error', 'message'=>htmlspecialchars($IDENT_LABEL) . ' já existe.']);
                 exit;
             }
             $res = $db->insert('Identificacao.json', $data);
             $msg = "Registro criado com sucesso!";
        }

        if ($res) {
            echo json_encode(['status'=>'ok', 'message'=>$msg]);
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Erro ao salvar registro.']);
        }
        exit;
    }

    if ($act == 'ajax_delete_credit') {
        $ports = $_POST['ports'] ?? []; // Changed from $port to $ports
        if (!is_array($ports)) $ports = json_decode($ports, true); // Ensure it's an array
        ob_clean(); header('Content-Type: application/json');
        if (!empty($ports)) { // Check if $ports is not empty
            if ($db->deleteMany('Identificacao.json', $IDENT_ID_FIELD, $ports)) { // Changed from delete to deleteMany
                // Cleanup Uploads for each port
                foreach ($ports as $port) {
                    $uDir = 'upload/' . $port;
                    if(is_dir($uDir)) deleteDirectory($uDir);
                }
                echo json_encode(['status'=>'ok', 'message'=>'Registro(s) excluído(s).']); // Updated message for multiple deletions
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Identificador inválido.']);
        }
        exit;
    }

    // --- ATTACHMENT HANDLERS ---
    if ($act == 'ajax_upload_file') {
        $port = $_POST['port'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        if (!$port) { echo json_encode(['status'=>'error', 'message'=>'ID do processo inválido.']); exit; }
        
        if (!isset($_FILES['file']) || $_FILES['file']['error'] != UPLOAD_ERR_OK) {
            echo json_encode(['status'=>'error', 'message'=>'Erro no upload do arquivo.']); exit;
        }
        
        $uploadBase = 'upload/';
        if (!is_dir($uploadBase)) mkdir($uploadBase, 0777, true);
        
        $targetDir = $uploadBase . $port . '/';
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);
        
        $fileName = basename($_FILES['file']['name']);
        // Sanitize
        $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
        
        $targetFile = $targetDir . $fileName;
        
        if (move_uploaded_file($_FILES['file']['tmp_name'], $targetFile)) {
            echo json_encode(['status'=>'ok', 'message'=>'Arquivo anexado com sucesso.']);
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Falha ao salvar arquivo.']);
        }
        exit;
    }

    if ($act == 'ajax_list_attachments') {
        $port = $_POST['port'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        if (!$port) { echo json_encode(['status'=>'error', 'message'=>'ID inválido.']); exit; }
        
        $targetDir = 'upload/' . $port . '/';
        $files = [];
        
        if (is_dir($targetDir)) {
            foreach (scandir($targetDir) as $file) {
                 if ($file == '.' || $file == '..') continue;
                 $path = $targetDir . $file;
                 $size = filesize($path);
                 $sizeStr = ($size > 1048576) ? round($size/1048576, 2) . ' MB' : round($size/1024, 2) . ' KB';
                 
                 $files[] = [
                     'name' => $file,
                     'size' => $sizeStr
                 ];
            }
        }
        
        echo json_encode(['status'=>'ok', 'files'=>$files]);
        exit;
    }

    if ($act == 'ajax_delete_attachment') {
        $port = $_POST['port'] ?? '';
        $file = $_POST['file'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        if (!$port || !$file) { echo json_encode(['status'=>'error', 'message'=>'Dados inválidos.']); exit; }
        
        $file = basename($file);
        $targetFile = 'upload/' . $port . '/' . $file;
        
        if (file_exists($targetFile)) {
            if (unlink($targetFile)) {
                echo json_encode(['status'=>'ok', 'message'=>'Arquivo excluído.']);
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir arquivo.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Arquivo não encontrado.']);
        }
        exit;
    }

    if ($act == 'ajax_rename_attachment') {
        $port = $_POST['port'] ?? '';
        $oldName = $_POST['old'] ?? '';
        $newName = $_POST['new'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        if (!$port || !$oldName || !$newName) { echo json_encode(['status'=>'error', 'message'=>'Dados inválidos.']); exit; }
        
        $oldName = basename($oldName);
        $newName = basename($newName);
        // Sanitize new name
        $newName = preg_replace('/[^A-Za-z0-9._-]/', '_', $newName);
        
        $dir = 'upload/' . $port . '/';
        if (file_exists($dir . $oldName)) {
            if (rename($dir . $oldName, $dir . $newName)) {
                echo json_encode(['status'=>'ok', 'message'=>'Arquivo renomeado.']);
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao renomear.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Arquivo original não encontrado.']);
        }
        exit;
    }

    // --- TEMPLATE LISTS HANDLERS ---
    if ($act == 'ajax_save_list') {
        $id = $_POST['id'] ?? '';
        $name = $_POST['name'] ?? '';
        $user = $_SESSION['nome_completo'] ?? ($_SESSION['usuario'] ?? 'Unknown');
        ob_clean(); header('Content-Type: application/json');
        
        if (!$name) { echo json_encode(['status'=>'error', 'message'=>'Nome obrigatório.']); exit; }
        
        $templates->saveList($id, $name, $user);
        echo json_encode(['status'=>'ok', 'message'=>'Lista salva.']);
        exit;
    }
    
    if ($act == 'ajax_delete_list') {
        $id = $_POST['id'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        if (!$id) { echo json_encode(['status'=>'error', 'message'=>'ID inválido.']); exit; }
        
        $templates->deleteList($id);
        echo json_encode(['status'=>'ok', 'message'=>'Lista excluída.']);
        exit;
    }

    if ($act == 'ajax_reorder_lists') {
        $order = $_POST['order'] ?? [];
        $user = $_SESSION['nome_completo'] ?? ($_SESSION['usuario'] ?? 'Unknown');
        
        ob_clean(); header('Content-Type: application/json');
        if (!is_array($order)) { echo json_encode(['status'=>'error', 'message'=>'Dados inválidos.']); exit; }
        
        $templates->saveOrder($user, $order);
        echo json_encode(['status'=>'ok', 'message'=>'Ordem salva.']);
        exit;
    }

    if ($act == 'ajax_save_template') {
        $id = $_POST['id'] ?? '';
        $title = $_POST['titulo'] ?? '';
        $body = $_POST['corpo'] ?? '';
        $list_id = $_POST['list_id'] ?? null;
        
        ob_clean(); header('Content-Type: application/json');
        if (!$title) { echo json_encode(['status'=>'error', 'message'=>'Título obrigatório.']); exit; }
        
        $templates->save($id, $title, $body, $list_id);
        echo json_encode(['status'=>'ok', 'message'=>'Modelo salvo.']);
        exit;
    }

    if ($act == 'ajax_delete_template') {
        $id = $_POST['id'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        $templates->delete($id);
        echo json_encode(['status'=>'ok', 'message'=>'Modelo excluído.']);
        exit;
    }
    
    if ($act == 'ajax_get_templates_data') {
         $user = $_SESSION['nome_completo'] ?? ($_SESSION['usuario'] ?? 'Unknown');
         ob_clean(); header('Content-Type: application/json');
         
         $data = [
             'templates' => $templates->getAll(),
             'lists' => $templates->getLists(),
             'order' => $templates->getOrder($user)
         ];
         echo json_encode(['status'=>'ok', 'data'=>$data]);
         exit;
    }

    if ($act == 'ajax_save_process_data_record') {
        $port = $_POST['port'] ?? '';
        $usuario = $_SESSION['nome_completo'];
        $data = date('d/m/Y H:i');
        
        $fields = $config->getFields('Base_registros_schema');
        $errors = [];
        $record = [
            'DATA' => $data,
            'USUARIO' => $usuario,
            $IDENT_ID_FIELD => $port
        ];
        
        foreach($fields as $f) {
            if (isset($f['type']) && $f['type'] === 'title') continue;
            $key = $f['key'];
            // PHP mangle spaces/dots to underscores in $_POST keys
            $postKey = str_replace([' ', '.'], '_', $key);
            
            $val = $_POST[$postKey] ?? ($_POST[$key] ?? '');
            
            if (is_array($val)) {
                $val = implode(', ', $val);
            }

            if ($f['type'] == 'date' && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d', $val);
                if ($dtObj) $val = $dtObj->format('d/m/Y');
            }
            if ($f['type'] == 'datetime' && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                if ($dtObj) $val = $dtObj->format('d/m/Y H:i');
            }

            if (($f['type'] ?? '') === 'custom' || ($f['custom_mask'] ?? '')) {
                 $val = format_field_value($f['key'], $val);
            }

            $record[$f['key']] = $val;
            
            // Validation
            if (isset($f['required']) && $f['required'] && (!isset($f['deleted']) || !$f['deleted'])) {
                if ($f['key'] === 'Nome_atendente' || $f['key'] === 'Data') {
                    // skip server-side required enforcement for fixed system fields
                } else {
                    if (trim((string)$val) === '') {
                        $errors[] = "Campo obrigatório não preenchido: " . ($f['label'] ?: $f['key']);
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => implode("<br>", $errors)]);
            exit;
        }
        
        $targetFile = 'Base_registros_dados.json';
        if (!file_exists($db->getPath($targetFile))) {
            $db->writeJSON($db->getPath($targetFile), []);
        }
        
        if ($db->insert($targetFile, $record)) {
            // Update Parent Timestamp
            $file = $indexer->get($port);
            if (!$file) {
                 $years = $_SESSION['selected_years'] ?? [date('Y')];
                 $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                 $files = $db->getProcessFiles($years, $months);
                 $file = $db->findFileForRecord($files, $ID_FIELD_KEY, $port);
            }
            if ($file) {
                 $db->update($file, $ID_FIELD_KEY, $port, ['Ultima_Alteracao' => date('d/m/Y H:i'), 'Nome_atendente' => $_SESSION['nome_completo']]);
            }

            $registros = $db->findMany($targetFile, $IDENT_ID_FIELD, [$port]);
            $registros = array_reverse($registros);
            
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'history' => $registros]);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar.']);
        }
        exit;
    }

    if ($act == 'ajax_salvar_processo') {
        // Optimistic Locking Check
        $portCheck = $_POST[$ID_FIELD_KEY] ?? '';
        $tsCheck = $_POST['timestamp_controle'] ?? null;
        if($portCheck && $tsCheck !== null) {
            $fCheck = $indexer->get($portCheck);
            if($fCheck) {
                 $currCheck = $db->find($fCheck, $ID_FIELD_KEY, $portCheck);
                 if($currCheck && isset($currCheck['Ultima_Alteracao']) && $currCheck['Ultima_Alteracao'] !== $tsCheck) {
                     ob_clean(); header('Content-Type: application/json');
                     echo json_encode(['status'=>'error', 'message'=>'Este registro foi modificado por outro usuário em '.$currCheck['Ultima_Alteracao'].'. Por favor, recarregue a página.']);
                     exit;
                 }
            }
        } 
        ob_clean(); header('Content-Type: application/json');

        // Pre-populate Process fields from Client/Agency inputs for storage
        if(isset($_POST['client_Nome'])) $_POST['Nome'] = $_POST['client_Nome'];
        
        $agMap = ['UF', 'SR', 'NOME_SR', 'FILIAL', 'E-MAIL_AG', 'E-MAILS_SR', 'E-MAILS_FILIAL', 'E-MAIL_GERENTE'];
        foreach($agMap as $k) {
            if(isset($_POST['agency_' . $k])) $_POST[$k] = $_POST['agency_' . $k];
        }

        $fields = $config->getFields('Base_processos_schema'); 
        $data = [];
        $errors = [];
        
        foreach ($fields as $f) {
            if (isset($f['type']) && $f['type'] === 'title') continue;

            $key = $f['key'];
            $postKey = str_replace(' ', '_', $key);
            
            // Deleted field traceability: skip if deleted and not present in POST
            if ((isset($f['deleted']) && $f['deleted']) && !isset($_POST[$postKey]) && !isset($_POST[$key])) {
                continue;
            }

            $val = $_POST[$postKey] ?? ($_POST[$key] ?? '');
            
            if (is_array($val)) {
                $val = implode(', ', $val);
            }

            if ($f['type'] == 'date' && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d', $val);
                if ($dtObj) {
                    $val = $dtObj->format('d/m/Y');
                }
            }
            if (($f['type'] == 'datetime' || $f['type'] == 'datetime-local') && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                
                if ($dtObj) $val = $dtObj->format('d/m/Y H:i');
            }
            $data[$key] = $val;
            
            // Validation
            if ($f['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                $errors[] = "O campo " . ($f['label'] ?: $key) . " deve conter apenas números.";
            }

            if ($f['type'] === 'custom') {
                // Normalize to Formatted Value
                $val = format_field_value($key, $val);
                $data[$key] = $val;
            }

            if (isset($f['required']) && $f['required'] && (!isset($f['deleted']) || !$f['deleted'])) {
                    // Do not require Nome_atendente here; session user will be saved automatically
                    if (mb_strtoupper($f['key'], 'UTF-8') === 'NOME_ATENDENTE') {
                        // skip
                    } else {
                        if (trim((string)$val) === '') {
                            $errors[] = "Campo obrigatório do processo não preenchido: " . ($f['label'] ?: $key);
                        }
                    }
                }
        }

        $cpf = $_POST['CPF'] ?? '';
        $port = $_POST[$ID_FIELD_KEY] ?? '';

        if (!$port) {
            echo json_encode(['status'=>'error', 'message'=>"Erro: " . $ID_LABEL . " é obrigatório."]);
            exit;
        } 

        // Lock Check
        $lockInfo = $lockManager->checkLock($port, $_SESSION['nome_completo']);
        if ($lockInfo['locked']) {
            echo json_encode(['status'=>'error', 'message'=>"Este processo está bloqueado por {$lockInfo['by']} e não pode ser salvo."]);
            exit;
        }
        
        $data['Nome_atendente'] = $_SESSION['nome_completo'] ?? 'Desconhecido';
        $data['Data'] = date('d/m/Y H:i');
        $data['Ultima_Alteracao'] = date('d/m/Y H:i');
        if (empty($data['DATA'])) $data['DATA'] = date('d/m/Y');

        // Client Validation (Removed as Base_clientes is deprecated)
        // if ($cpf) { ... }
        
        // Agency Validation (Removed as Base_agencias is deprecated)
        // if ($ag) { ... }

        if (!empty($errors)) {
             echo json_encode(['status'=>'error', 'message'=>implode("<br>", array_unique($errors))]);
             exit;
        }

        // Determine correct storage file based on date
        $dt = DateTime::createFromFormat('d/m/Y', $data['DATA']);
        if (!$dt) $dt = new DateTime();
        $targetFile = $db->ensurePeriodStructure($dt->format('Y'), $dt->format('n'));

        // Check for existing record via Index
        $foundFile = $indexer->get($port);

        if ($foundFile) {
            // Check if date changed such that it belongs to a different file
            if ($foundFile !== $targetFile) {
                // Move record: Delete from old, Insert into new
                $oldData = $db->find($foundFile, $ID_FIELD_KEY, $port);
                $fullData = $oldData ? array_merge($oldData, $data) : $data;

                $db->delete($foundFile, $ID_FIELD_KEY, $port);
                $db->insert($targetFile, $fullData);
                $indexer->set($port, $targetFile); // Update Index
                $msg = "Processo atualizado e movido para o período correto!";
            } else {
                $db->update($foundFile, $ID_FIELD_KEY, $port, $data);
                $msg = "Processo atualizado com sucesso!";
            }
        } else {
            $db->insert($targetFile, $data);
            $indexer->set($port, $targetFile); // Add to Index
            $msg = "Processo criado com sucesso!";
        }

        // Updates to Base_clientes/Base_agencias removed as requested (all data in Processos)
        
        echo json_encode(['status'=>'ok', 'message'=>$msg]);
        exit;
    }

    if ($act == 'ajax_render_dashboard_table') {
        $fAtendente = $_POST['fAtendente'] ?? '';
        $fStatus = $_POST['fStatus'] ?? '';
        $fDataIni = $_POST['fDataIni'] ?? '';
        $fDataFim = $_POST['fDataFim'] ?? '';
        $fMes = $_POST['fMes'] ?? '';
        $fAno = $_POST['fAno'] ?? '';
        $fBusca = $_POST['fBusca'] ?? '';
        $pPagina = $_POST['pag'] ?? 1;
        
        $sortCol = $_POST['sortCol'] ?? 'DATA';
        $sortDir = $_POST['sortDir'] ?? 'desc';
        $desc = ($sortDir === 'desc');

        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $targetFile = $db->getProcessFiles($years, $months);

        $filters = [];
        // Fixed Filters Logic

        
        // Logic for Dynamic Filters
        $procFields = $config->getFields('Base_processos_schema');
        $visFilters = [];
        $fieldMap = [];
        $fieldConfigMap = [];
        foreach($procFields as $f) {
             $show = (isset($f['show_dashboard_filter']) && $f['show_dashboard_filter']) || (!isset($f['show_dashboard_filter']) && isset($f['show_filter']) && $f['show_filter']);
             if($show && !($f['deleted']??false)) {
                 $visFilters[] = $f['key'];
                 $fieldMap[$f['key']] = $f['type'] ?? 'text';
                 $fieldConfigMap[$f['key']] = $f;
             }
        }
        
        $checkDate = function($row) use ($fDataIni, $fDataFim, $fMes, $fAno, $visFilters, $fieldMap, $fieldConfigMap) {
            // Apply Dynamic Filters first (Type Aware)
            foreach($visFilters as $vk) {
                if ($vk === $ID_FIELD_KEY) continue;
                $valReq = $_POST['f_' . $vk] ?? '';
                if ($valReq !== '') {
                    $rowVal = get_value_ci($row, $vk);
                    $fType = $fieldMap[$vk] ?? 'text';
                    $fConfig = $fieldConfigMap[$vk] ?? [];

                    // Check for Mask / Custom Type: Normalize for flexible search
                    $mask = $fConfig['custom_mask'] ?? ($fConfig['mask'] ?? '');
                    if (!empty($mask) || $fType === 'custom') {
                        // Normalize both to alphanumeric
                        $vNorm = preg_replace('/[^a-zA-Z0-9]/', '', $valReq);
                        $rNorm = preg_replace('/[^a-zA-Z0-9]/', '', (string)$rowVal);
                        
                        if ($vNorm !== '') {
                            if (mb_stripos($rNorm, $vNorm) === false) return false;
                            continue;
                        }
                    }

                    if (in_array($fType, ['text', 'textarea', 'custom'])) {
                        if (mb_stripos($rowVal, $valReq) === false) {
                            // Try normalized fallback anyway if exact fails
                            $vNorm = preg_replace('/[^a-zA-Z0-9]/', '', $valReq);
                            $rNorm = preg_replace('/[^a-zA-Z0-9]/', '', (string)$rowVal);
                            if ($vNorm === '' || mb_stripos($rNorm, $vNorm) === false) return false;
                        }
                    } else {
                        if (mb_strtoupper(trim((string)$rowVal)) !== mb_strtoupper(trim((string)$valReq))) return false;
                    }
                }
            }
            // Status Check (Fixed)
            if (!empty($_POST['fStatus'])) {
                 $rStat = get_value_ci($row, 'STATUS');
                 if (mb_strtoupper(trim((string)$rStat)) !== mb_strtoupper(trim((string)$_POST['fStatus']))) return false;
            } else if (!empty($_POST['f_STATUS'])) {
                 // Or Dynamic Status
                 $rStat = get_value_ci($row, 'STATUS');
                 if (mb_strtoupper(trim((string)$rStat)) !== mb_strtoupper(trim((string)$_POST['f_STATUS']))) return false;
            }

            if (!$fDataIni && !$fDataFim && !$fMes && !$fAno) return true;
            $d = get_value_ci($row, 'DATA'); 
            if (!$d) return false;
            // Parse stored date (may include time)
            $dt = DateTime::createFromFormat('d/m/Y H:i:s', $d);
            if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $d);
            if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $d);
            if (!$dt) return false;
            
            if ($fDataIni) {
                // Support datetime-local (Y-m-dTH:i) or date (Y-m-d)
                $di = DateTime::createFromFormat('Y-m-d\TH:i', $fDataIni);
                if (!$di) $di = DateTime::createFromFormat('!Y-m-d', $fDataIni);
                if ($di && $dt < $di) return false;
            }
            if ($fDataFim) {
                $df = DateTime::createFromFormat('Y-m-d\TH:i', $fDataFim);
                if (!$df) {
                    $df = DateTime::createFromFormat('!Y-m-d', $fDataFim);
                    if ($df) $df->setTime(23, 59, 59);
                }
                if ($df && $dt > $df) return false;
            }
            if ($fMes && $fAno) {
                if ($dt->format('m') != $fMes || $dt->format('Y') != $fAno) return false;
            }
            return true;
        };

        if ($fBusca) {
             $foundCpfs = []; $foundPorts = []; $foundAgs = [];
             if (!preg_match('/^\d+$/', $fBusca)) {
                 $resCli = $db->select('Base_clientes.json', ['global' => $fBusca], 1, 1000); 
                 $foundCpfs = array_column($resCli['data'], 'CPF');
             }
             $resCred = $db->select('Identificacao.json', ['global' => $fBusca], 1, 1000);
             $foundPorts = array_column($resCred['data'], $IDENT_ID_FIELD);
             $resAg = $db->select('Base_agencias.json', ['global' => $fBusca], 1, 1000);
             $foundAgs = array_column($resAg['data'], 'AG');

             $filters['callback'] = function($row) use ($fBusca, $foundCpfs, $foundPorts, $foundAgs, $checkDate) {
                  if (!$checkDate($row)) return false;
                  foreach ($row as $val) {
                      if (stripos((string)$val, $fBusca) !== false) return true;
                      // Normalized fallback for mask compatibility
                      $vNorm = preg_replace('/[^a-zA-Z0-9]/', '', $fBusca);
                      $rNorm = preg_replace('/[^a-zA-Z0-9]/', '', (string)$val);
                      if ($vNorm !== '' && stripos($rNorm, $vNorm) !== false) return true;
                  }
                  $cpf = get_value_ci($row, 'CPF');
                  if (!empty($foundCpfs) && $cpf && in_array($cpf, $foundCpfs)) return true;
                  $port = get_value_ci($row, $ID_FIELD_KEY);
                  if (!empty($foundPorts) && $port && in_array($port, $foundPorts)) return true;
                  $ag = get_value_ci($row, 'AG');
                  if (!empty($foundAgs) && $ag && in_array($ag, $foundAgs)) return true;
                  return false;
             };
        } else {
            // Always apply checkDate because it now handles Dynamic Filters too
            $filters['callback'] = $checkDate;
        }

        if ($sortCol === 'Nome' || $sortCol === 'Nome_atendente') {
            // Fetch ALL (limit 1M) to handle custom sorting
            $res = $db->select($targetFile, $filters, 1, 1000000, null, false);
            $processos = $res['data'];

            $cpfs = [];
            foreach($processos as $p) { $cpfs[] = get_value_ci($p, 'CPF'); }
            // We need clients for 'Nome' sort
            $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
            $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c['Nome'];
            
 usort($processos, function($a, $b) use ($sortCol, $desc, $clientMap) {
                if ($sortCol === 'Nome') {
                    // CORREÇÃO: Tenta buscar no Mapa de Clientes, se não achar, usa o Nome salvo no próprio processo
                    $cpfA = get_value_ci($a, 'CPF') ?: '';
                    $nameA = $clientMap[$cpfA] ?? (get_value_ci($a, 'Nome') ?: '');
                    
                    $cpfB = get_value_ci($b, 'CPF') ?: '';
                    $nameB = $clientMap[$cpfB] ?? (get_value_ci($b, 'Nome') ?: '');

                    $cmp = strnatcasecmp($nameA, $nameB);
                    return $desc ? -$cmp : $cmp;
                }
                if ($sortCol === 'Nome_atendente') {
                    $atA = trim(get_value_ci($a, 'Nome_atendente') ?: '');
                    $atB = trim(get_value_ci($b, 'Nome_atendente') ?: '');
                    $cmp = strnatcasecmp($atA, $atB);
                    
                    if ($cmp !== 0) {
                        return $desc ? -$cmp : $cmp;
                    }
                    
                    // Ordenação secundária: Ultima_Alteracao DESC (Mais recente primeiro)
                    $parseDate = function($val) {
                        $val = trim((string)$val);
                        if (!$val) return 0;
                        
                        $dt = DateTime::createFromFormat('d/m/Y H:i:s', $val);
                        if ($dt) return $dt->getTimestamp();
                        
                        $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
                        if ($dt) return $dt->getTimestamp();

                        $dt = DateTime::createFromFormat('d/m/Y', $val);
                        if ($dt) return $dt->getTimestamp();
                        
                        return 0;
                    };
                    
                    $tA = $parseDate(get_value_ci($a, 'Ultima_Alteracao') ?: (get_value_ci($a, 'DATA') ?: ''));
                    $tB = $parseDate(get_value_ci($b, 'Ultima_Alteracao') ?: (get_value_ci($b, 'DATA') ?: ''));
                    
                    if ($tA == $tB) return 0;
                    return ($tA < $tB) ? 1 : -1; 
                }
                return 0;
            });
            
            $total = count($processos);
            $limit = 20;
            $pages = ceil($total / $limit);
            if ($pPagina > $pages && $pages > 0) $pPagina = $pages;
            if ($pPagina < 1) $pPagina = 1;
            
            $offset = ($pPagina - 1) * $limit;
            $processos = array_slice($processos, $offset, $limit);
            
            $res = [
                'data' => $processos,
                'total' => $total,
                'page' => $pPagina,
                'pages' => $pages
            ];
        } else {
            $res = $db->select($targetFile, $filters, $pPagina, 20, $sortCol, $desc);
            $processos = $res['data'];
        }
        
        $cpfs = []; $ports = [];
        foreach($processos as $p) {
            $cpfs[] = get_value_ci($p, 'CPF');
            $ports[] = get_value_ci($p, $ID_FIELD_KEY);
        }
        $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
        $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c['Nome'];
        $creditos = $db->findMany('Identificacao.json', $IDENT_ID_FIELD, $ports);
        $creditoMap = []; foreach ($creditos as $c) $creditoMap[$c[$IDENT_ID_FIELD]] = $c;

        // Build Dynamic Columns List (Same logic as Header)
        $procFields = $config->getFields('Base_processos_schema');
        $schemaCols = [];
        foreach($procFields as $f) {
            $show = (isset($f['show_dashboard_column']) && $f['show_dashboard_column']) || (!isset($f['show_dashboard_column']) && isset($f['show_column']) && $f['show_column']);
            if($show && ($f['type']??'') !== 'title' && !($f['deleted']??false)) {
                $schemaCols[$f['key']] = ['key'=>$f['key'], 'label'=>$f['label']];
            }
        }
        
        // Ensure fixed columns
        if(!isset($schemaCols[$ID_FIELD_KEY])) $schemaCols[$ID_FIELD_KEY] = ['key'=>$ID_FIELD_KEY, 'label'=>$ID_LABEL];
        
        $dashColumns = array_values($schemaCols);

        // Order Logic
        $dOrder = $settings->get('dashboard_columns_order', []);
        if(!empty($dOrder)) {
            $sorted = []; $indexed = [];
            foreach($dashColumns as $c) $indexed[$c['key']] = $c;
            foreach($dOrder as $k) { if(isset($indexed[$k])) { $sorted[] = $indexed[$k]; unset($indexed[$k]); } }
            foreach($indexed as $c) $sorted[] = $c;
            $dashColumns = $sorted;
        } else {
             // Default Order: Fixed First
             $fixedKeys = [$ID_FIELD_KEY, 'Data', 'Nome_atendente'];
             $sorted = [];
             $indexed = [];
             foreach($dashColumns as $c) $indexed[$c['key']] = $c;
             foreach($fixedKeys as $k) { if(isset($indexed[$k])) { $sorted[] = $indexed[$k]; unset($indexed[$k]); } }
             foreach($indexed as $c) $sorted[] = $c;
             $dashColumns = $sorted;
        }

        ob_start();
        foreach($processos as $proc) {
            $port = get_value_ci($proc, $ID_FIELD_KEY);
            $cred = isset($creditoMap[$port]);
            
            $l = $lockManager->checkLock($port, '');
            $rowClass = $l['locked'] ? 'table-warning' : '';

            echo '<tr class="' . $rowClass . '">';
            
            foreach($dashColumns as $col) {
                $colKey = $col['key'];
                $val = get_value_ci($proc, $colKey) ?: '';
                
                if($colKey === $ID_FIELD_KEY) {
                    echo '<td class="dashboard-text fw-bold">';
                    echo htmlspecialchars(format_field_value($ID_FIELD_KEY, $val));
                    if($cred) {
                         $cIcon = $IDENT_ICON; 
                         if(!$cIcon) $cIcon = '<i class="fas fa-sack-dollar text-success"></i>'; 
                         if(strpos($cIcon, '<') === 0) echo ' ' . $cIcon;
                         else echo ' <span class="ms-2 text-success" title="Identificação Encontrada!">' . $cIcon . '</span>';
                    }
                    echo '</td>';
                } else {
                    if (mb_strtoupper($colKey) === 'STATUS') {
                        echo '<td class="dashboard-text"><span class="badge bg-secondary">' . htmlspecialchars(format_field_value($colKey, $val)) . '</span></td>';
                    } elseif (mb_strtoupper($colKey) === 'NOME_ATENDENTE') {
                        echo '<td class="dashboard-text">';
                        echo htmlspecialchars(format_field_value($colKey, $val));
                        $uAlt = get_value_ci($proc, 'Ultima_Alteracao');
                        if (!empty($uAlt)) {
                            echo '<div class="small text-muted" style="font-size:0.75em"><i class="fas fa-clock me-1"></i>'.htmlspecialchars($uAlt).'</div>';
                        }
                        if ($l['locked']) {
                            echo '<div class="small text-danger fw-bold"><i class="fas fa-lock me-1"></i> '.htmlspecialchars($l['by']).'</div>';
                        }
                        echo '</td>';
                    } else {
                        echo '<td class="dashboard-text">' . htmlspecialchars(format_field_value($colKey, $val)) . '</td>';
                    }
                }
            }

            // Action Column
            echo '<td><button onclick="loadProcess(\'' . htmlspecialchars($port) . '\', this)" class="btn btn-sm btn-outline-dark border-0"><i class="fas fa-folder-open fa-lg text-warning"></i> Abrir</button></td>';
            echo '</tr>';
        }
        $colCount = count($dashColumns) + 1; // Approximate
        if(empty($processos)) echo '<tr><td colspan="' . $colCount . '" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr>';
        $html = ob_get_clean();

        $paginationHtml = '';
        if ($res['pages'] > 1) {
             $paginationHtml .= '<ul class="pagination justify-content-center">';
             if($res['page'] > 1) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterDashboard(event, '.($res['page']-1).')">Anterior</a></li>';
             $paginationHtml .= '<li class="page-item disabled"><a class="page-link">Página '.$res['page'].' de '.$res['pages'].'</a></li>';
             if($res['page'] < $res['pages']) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterDashboard(event, '.($res['page']+1).')">Próxima</a></li>';
             $paginationHtml .= '</ul>';
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'html' => $html, 'pagination' => $paginationHtml, 'page' => $res['page'], 'count' => $res['total']]);
        exit;
    }

    if ($act == 'ajax_render_lembretes_table') {
        $pPagina = $_POST['pag'] ?? 1;
        $fLembreteIni = $_POST['fLembreteIni'] ?? '';
        $fLembreteFim = $_POST['fLembreteFim'] ?? '';
        $fBuscaGlobal = $_POST['fBuscaGlobal'] ?? '';
        
        $sortCol = $_POST['sortCol'] ?? 'Data_Lembrete';
        $sortDir = $_POST['sortDir'] ?? 'asc';

        // Column Filters (passed as JSON)
        $colFilters = isset($_POST['colFilters']) ? json_decode($_POST['colFilters'], true) : [];
        
        // 1. Identify Flagged Fields
        $processFields = $config->getFields('Base_processos_schema');
        $recordFields = $config->getFields('Base_registros_schema');
        
        $flaggedFields = [];
        
        foreach($processFields as $f) {
            if(($f['show_reminder'] ?? false) == true && (!isset($f['deleted']) || !$f['deleted'])) {
                $f['source'] = 'process';
                $flaggedFields[] = $f;
            }
        }

        // Consolidated Display Columns
        $displayColumns = [
            ['key'=>'Ultima_Alteracao', 'label'=>'Ultima Atualização', 'source'=>'process'],
            ['key'=>'Data_Lembrete', 'label'=>'Data Lembrete', 'source'=>'process']
        ];
        foreach($flaggedFields as $f) {
            $displayColumns[] = $f;
        }

        // Apply Custom Order if provided
        $columnOrder = isset($_POST['columnOrder']) ? json_decode($_POST['columnOrder'], true) : [];
        if (!empty($columnOrder) && is_array($columnOrder)) {
            $orderedCols = [];
            // Map existing columns by key
            $colMap = [];
            foreach($displayColumns as $c) $colMap[$c['key']] = $c;
            
            // Add in order
            foreach($columnOrder as $k) {
                if(isset($colMap[$k])) {
                    $orderedCols[] = $colMap[$k];
                    unset($colMap[$k]);
                }
            }
            // Add remaining (new ones or those not in order list)
            foreach($colMap as $c) {
                $orderedCols[] = $c;
            }
            $displayColumns = $orderedCols;
        }
        foreach($recordFields as $f) {
            if(($f['show_reminder'] ?? false) == true && (!isset($f['deleted']) || !$f['deleted'])) {
                $f['source'] = 'record';
                $flaggedFields[] = $f;
            }
        }
        
        // 2. Fetch Processes
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $targetFiles = $db->getProcessFiles($years, $months);
        
        // Main Filters
        $filters = [];
        
        // Date Logic for Data_Lembrete
        $checkLembrete = function($row) use ($fLembreteIni, $fLembreteFim) {
            if (!$fLembreteIni && !$fLembreteFim) return true;
            $val = get_value_ci($row, 'Data_Lembrete');
            if (!$val) return false;
            
            // Format can be d/m/Y H:i or d/m/Y or Y-m-dTH:i
            // Input can be Y-m-d or Y-m-d\TH:i
            $dt = DateTime::createFromFormat('d/m/Y H:i:s', $val);
            if(!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
            if(!$dt) $dt = DateTime::createFromFormat('d/m/Y', $val);
            if(!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
            if(!$dt) return false;
            
            if ($fLembreteIni) {
                $di = DateTime::createFromFormat('Y-m-d\TH:i', $fLembreteIni);
                if (!$di) {
                    $di = DateTime::createFromFormat('Y-m-d', $fLembreteIni);
                    if ($di) $di->setTime(0,0,0);
                }
                if ($di && $dt < $di) return false;
            }
            if ($fLembreteFim) {
                $df = DateTime::createFromFormat('Y-m-d\TH:i', $fLembreteFim);
                if (!$df) {
                    $df = DateTime::createFromFormat('Y-m-d', $fLembreteFim);
                    if ($df) $df->setTime(23,59,59);
                }
                if ($df && $dt > $df) return false;
            }
            return true;
        };
        
        if ($fBuscaGlobal) {
             $filters['global'] = $fBuscaGlobal;
        }
        
        // Combine Date Check with Global
        $filters['callback'] = function($row) use ($checkLembrete) {
            if (!$checkLembrete($row)) return false;
            
            // Filter Empty Fields
            $ua = get_value_ci($row, 'Ultima_Alteracao');
            $dl = get_value_ci($row, 'Data_Lembrete');
            if (trim((string)$ua) === '' || trim((string)$dl) === '') return false;

            return true;
        };

        // Fetch All Matches (pagination done later to handle record merging)
        $res = $db->select($targetFiles, $filters, 1, 100000, $sortCol, ($sortDir === 'desc')); 
        $rows = $res['data'];
        
        // 3. Enrich with Records if needed
        $needsRecords = false;
        foreach($flaggedFields as $f) { if($f['source'] == 'record') $needsRecords = true; }
        
        $recordMap = [];
        if ($needsRecords && !empty($rows)) {
            // Load Records (Optimize: Load only for found ports?)
            $ports = array_column($rows, $ID_FIELD_KEY);
            if(!empty($ports)) {
                $allRecords = $db->readJSON($db->getPath('Base_registros_dados.json'));
                // Group by Port, sort by date desc
                foreach($allRecords as $rec) {
                    $p = get_value_ci($rec, $IDENT_ID_FIELD);
                    if(in_array($p, $ports)) {
                        $currDate = get_value_ci($rec, 'DATA'); // d/m/Y H:i
                        
                        if(!isset($recordMap[$p])) {
                            $recordMap[$p] = $rec;
                        } else {
                            // Compare
                            $oldDate = get_value_ci($recordMap[$p], 'DATA');
                            $dtOld = DateTime::createFromFormat('d/m/Y H:i', $oldDate);
                            $dtNew = DateTime::createFromFormat('d/m/Y H:i', $currDate);
                            if ($dtNew > $dtOld) {
                                $recordMap[$p] = $rec;
                            }
                        }
                    }
                }
            }
        }
        
        // 4. Apply Column Filters (In-Memory)
        if (!empty($colFilters)) {
            $rows = array_filter($rows, function($row) use ($colFilters, $recordMap, $flaggedFields) {
                foreach ($colFilters as $key => $filterVal) {
                    if (trim((string)$filterVal) === '') continue;
                    
                    // Determine where value comes from
                    $val = '';
                    $source = 'process';
                    foreach($flaggedFields as $ff) { if($ff['key'] == $key) { $source = $ff['source']; break; } }
                    
                    if ($key == 'Ultima_Alteracao' || $key == 'Data_Lembrete') $val = get_value_ci($row, $key);
                    elseif ($source == 'process') {
                        $val = get_value_ci($row, $key);
                    } else {
                        $port = get_value_ci($row, $ID_FIELD_KEY);
                        $rec = $recordMap[$port] ?? [];
                        $val = get_value_ci($rec, $key);
                    }
                    
                    if (stripos((string)$val, $filterVal) === false) return false;
                }
                return true;
            });
        }
        
        // 5. Pagination
        $total = count($rows);
        $limit = 20;
        $pages = ceil($total / $limit);
        if ($pPagina > $pages && $pages > 0) $pPagina = $pages;
        if ($pPagina < 1) $pPagina = 1;
        $offset = ($pPagina - 1) * $limit;
        $rows = array_slice($rows, $offset, $limit);
        
        // 6. Render
        ob_start();
        
        // Header
        echo '<thead><tr>';
        
        // Filter Helper (Simplified)
        $renderFilter = function($key, $label) use ($colFilters) {
             $val = isset($colFilters[$key]) ? htmlspecialchars($colFilters[$key]) : '';
             return '<input class="form-control form-control-sm" value="'.$val.'" onkeyup="filterLembretesCol(this, \''.htmlspecialchars($key).'\')" placeholder="Filtro...">';
        };

        foreach($displayColumns as $f) {
            $key = $f['key'];
            $lbl = $f['label'] ?: $key;
            
            $icon = '<i class="fas fa-sort text-muted ms-1" style="font-size:0.8em; opacity:0.3"></i>';
            if ($sortCol === $key) {
                $icon = ($sortDir === 'asc') 
                    ? '<i class="fas fa-sort-up text-dark ms-1"></i>' 
                    : '<i class="fas fa-sort-down text-dark ms-1"></i>';
            }

            echo '<th data-key="' . htmlspecialchars($key) . '" onclick="sortLembretes(\''.htmlspecialchars($key).'\')" style="cursor:pointer">' . htmlspecialchars($lbl) . $icon . '</th>';
        }
        echo '<th>Ações <button class="btn btn-sm btn-light ms-2" onclick="clearLembretesFilters()" title="Limpar Filtros e Restaurar"><i class="fas fa-sync-alt"></i></button></th>';
        echo '</tr>';
        
        // Filters Row
        echo '<tr class="bg-light">';
        foreach($displayColumns as $f) {
            echo '<td>' . $renderFilter($f['key'], $f['label']) . '</td>';
        }
        echo '<td></td>';
        echo '</tr>';
        echo '</thead><tbody>';
        
        foreach($rows as $proc) {
            $port = get_value_ci($proc, $ID_FIELD_KEY);
            $rec = $recordMap[$port] ?? [];
            
            // Format Date Lembrete
            $dlVal = get_value_ci($proc, 'Data_Lembrete');
            $dtDL = null;
            if ($dlVal) {
                // Prioritize Brazilian format
                $dtDL = DateTime::createFromFormat('d/m/Y H:i:s', $dlVal);
                if (!$dtDL) $dtDL = DateTime::createFromFormat('d/m/Y H:i', $dlVal);
                
                // Fallback to standard parsing if needed
                if (!$dtDL) {
                    try {
                        $dtDL = new DateTime($dlVal);
                    } catch(Exception $e) { $dtDL = null; }
                }
            }
            $dlDisplay = $dtDL ? $dtDL->format('d/m/Y H:i') : $dlVal;
            
            // Bell Logic
            $bell = '';
            if ($dtDL && $dtDL < new DateTime()) {
                $bell = ' <i class="fas fa-bell text-danger fa-beat-fade ms-2" title="Lembrete Vencido"></i>';
            }

            echo '<tr>';
            
            foreach($displayColumns as $f) {
                $key = $f['key'];
                if ($key == 'Ultima_Alteracao') {
                    echo '<td>' . htmlspecialchars(get_value_ci($proc, 'Ultima_Alteracao') ?: '') . '</td>';
                } elseif ($key == 'Data_Lembrete') {
                    echo '<td>' . htmlspecialchars($dlDisplay) . $bell . '</td>';
                } else {
                    $val = '';
                    if ($f['source'] == 'process') $val = get_value_ci($proc, $f['key']);
                    else $val = get_value_ci($rec, $f['key']);
                    echo '<td>' . htmlspecialchars(format_field_value($f['key'], $val)) . '</td>';
                }
            }
            
            echo '<td><a href="javascript:void(0)" onclick="loadProcess(\'' . htmlspecialchars($port) . '\')" class="btn btn-sm btn-outline-dark border-0"><i class="fas fa-folder-open fa-lg text-warning"></i> Abrir</a></td>';
            echo '</tr>';
        }
        
        if(empty($rows)) echo '<tr><td colspan="100" class="text-center py-4 text-muted">Nenhum lembrete encontrado.</td></tr>';
        
        echo '</tbody>';
        $html = ob_get_clean();
        
        $paginationHtml = '';
        if ($pages > 1) {
             $paginationHtml .= '<ul class="pagination justify-content-center">';
             if($pPagina > 1) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterLembretes(null, '.($pPagina-1).')">Anterior</a></li>';
             $paginationHtml .= '<li class="page-item disabled"><a class="page-link">'.$pPagina.' / '.$pages.'</a></li>';
             if($pPagina < $pages) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterLembretes(null, '.($pPagina+1).')">Próxima</a></li>';
             $paginationHtml .= '</ul>';
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'html' => $html, 'pagination' => $paginationHtml]);
        exit;
    }

    if ($act == 'ajax_render_credit_table') {
        $cfBusca = $_POST['cfBusca'] ?? '';
        $cpPagina = $_POST['cpPagina'] ?? 1;
        $cfDataIni = $_POST['cfDataIni'] ?? '';
        $cfDataFim = $_POST['cfDataFim'] ?? '';

        $cFilters = [];
        if ($cfBusca) $cFilters['global'] = $cfBusca;
        
        if ($cfDataIni || $cfDataFim) {
            $cFilters['callback'] = function($row) use ($cfDataIni, $cfDataFim) {
                $d = get_value_ci($row, 'DATA_DEPOSITO');
                if (!$d) return false;
                $dt = DateTime::createFromFormat('d/m/Y', $d);
                if (!$dt) return false;
                
                if ($cfDataIni) {
                    $di = DateTime::createFromFormat('Y-m-d', $cfDataIni);
                    if ($dt < $di) return false;
                }
                if ($cfDataFim) {
                    $df = DateTime::createFromFormat('Y-m-d', $cfDataFim);
                    if ($dt > $df) return false;
                }
                return true;
            };
        }
 
        $cRes = $db->select('Identificacao.json', $cFilters, $cpPagina, 50, 'DATA_DEPOSITO', true);
        $creditos = $cRes['data'];

        ob_start();
        foreach($creditos as $c) {
            $port = get_value_ci($c, $IDENT_ID_FIELD);
            echo '<tr>';
            echo '<td><input type="checkbox" class="credit-checkbox" value="' . htmlspecialchars($port ?: '') . '"></td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'STATUS') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'NUMERO_DEPOSITO') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'DATA_DEPOSITO') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'VALOR_DEPOSITO_PRINCIPAL') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars($port ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'CERTIFICADO') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'CPF') ?: '') . '</td>';
            echo '<td>' . htmlspecialchars(get_value_ci($c, 'AG') ?: '') . '</td>';
            echo '<td>
                <button class="btn btn-sm btn-link text-primary p-0 me-2" onclick=\'openCreditModal(' . json_encode($c, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ')\' title="Editar"><i class="fas fa-pen"></i></button>
                <button class="btn btn-sm btn-link text-danger p-0" onclick="deleteCredit(\'' . htmlspecialchars($port) . '\')" title="Excluir"><i class="fas fa-trash"></i></button>
            </td>';
            echo '</tr>';
        }
        if(empty($creditos)) echo '<tr><td colspan="9" class="text-center py-3">Nenhum registro encontrado.</td></tr>';
        $html = ob_get_clean();

        $paginationHtml = '';
        if ($cRes['pages'] > 1) {
            $paginationHtml .= '<ul class="pagination justify-content-center pagination-sm">';
            if($cRes['page'] > 1) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterCredits(event, '.($cRes['page']-1).')">Anterior</a></li>';
            $paginationHtml .= '<li class="page-item disabled"><a class="page-link">'.$cRes['page'].' / '.$cRes['pages'].'</a></li>';
            if($cRes['page'] < $cRes['pages']) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="filterCredits(event, '.($cRes['page']+1).')">Próxima</a></li>';
            $paginationHtml .= '</ul>';
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'html' => $html, 'pagination' => $paginationHtml, 'count' => $cRes['total']]);
        exit;
    }

    if ($act == 'ajax_get_process_full') {
        $port = $_POST['port'] ?? '';
        
        $process = null;
        $file = $indexer->get($port);
        
        if ($file) {
            $res = $db->select($file, [$ID_FIELD_KEY => $port], 1, 1);
            if (!empty($res['data'])) $process = $res['data'][0];
        }

        // Fallback: If not in index, search in current selection
        if (!$process) {
             $years = $_SESSION['selected_years'] ?? [date('Y')];
             $months = $_SESSION['selected_months'] ?? [(int)date('n')];
             $files = $db->getProcessFiles($years, $months);
             
             $foundFile = $db->findFileForRecord($files, $ID_FIELD_KEY, $port);
             if ($foundFile) {
                 $res = $db->select($foundFile, [$ID_FIELD_KEY => $port], 1, 1);
                 if (!empty($res['data'])) {
                     $process = $res['data'][0];
                     // Update Index
                     $indexer->set($port, $foundFile);
                 }
             }
        }

        $client = null; $agency = null; $credit = null;
        
        if ($process) {
            $client = $db->find('Base_clientes.json', 'CPF', get_value_ci($process, 'CPF') ?: '');
            $agency = $db->find('Base_agencias.json', 'AG', get_value_ci($process, 'AG') ?: '');
        }
        $credit = $db->find('Identificacao.json', $IDENT_ID_FIELD, $port);
        if ($credit) {
            foreach ($credit as $k => $v) {
                $credit[$k] = format_field_value($k, $v);
            }
        }
        
        $registrosHistory = [];
        if (!file_exists($db->getPath('Base_registros_dados.json'))) {
             $db->writeJSON($db->getPath('Base_registros_dados.json'), []);
        }
        
        $allRegs = $db->readJSON($db->getPath('Base_registros_dados.json'));
        foreach($allRegs as $row) {
            if(($row[$IDENT_ID_FIELD]??'') == $port) {
                $row['_id'] = isset($row['UID']) ? $row['UID'] : md5(json_encode($row));
                $registrosHistory[] = $row;
            }
        }
        $registrosHistory = array_reverse($registrosHistory);

        $emailHistory = $templates->getHistory($port);

        $lockInfo = $lockManager->checkLock($port, $_SESSION['nome_completo']);

        ob_clean(); header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok', 
            'process' => $process, 
            'client' => $client, 
            'agency' => $agency,
            'credit' => $credit,
            'registros_history' => $registrosHistory,
            'email_history' => $emailHistory,
            'lock' => $lockInfo
        ]);
        exit;
    }

    if ($act == 'ajax_confirm_upload') {
        if (isset($_SESSION['upload_preview']) && !empty($_SESSION['upload_preview'])) {
            $data = $_SESSION['upload_preview'];
            $base = $_SESSION['upload_preview_base'] ?? 'Identificacao.json';
            
            // Clear session to free memory and state
            unset($_SESSION['upload_preview']);
            unset($_SESSION['upload_preview_base']);
            
            session_write_close();
            
            $cleanData = [];
            foreach($data as $row) {
                if (isset($row['DATA_ERROR'])) continue;
                unset($row['DATA_ERROR']);
                $cleanData[] = $row;
            }
            
            try {
                $res = $db->importExcelData($base, $cleanData);
                ob_clean(); header('Content-Type: application/json');
                if ($res) {
                    echo json_encode(['status'=>'ok', 'message'=>"Base ($base) atualizada com sucesso! (Inseridos: {$res['inserted']}, Atualizados: {$res['updated']})"]);
                } else {
                    echo json_encode(['status'=>'error', 'message'=>"Erro: Falha ao atualizar a base de dados."]);
                }
            } catch (Exception $e) {
                ob_clean(); header('Content-Type: application/json');
                echo json_encode(['status'=>'error', 'message'=>"Erro Crítico: " . $e->getMessage()]);
            }
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>"Erro: Sessão de upload expirada."]);
        }
        exit;
    }

    if ($act == 'ajax_cancel_upload') {
        unset($_SESSION['upload_preview']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok']);
        exit;
    }

    if ($act == 'ajax_paste_data') {
        $text = $_POST['paste_content'] ?? '';
        $base = $_POST['base'] ?? 'Identificacao.json';

        if ($text) {
            // Ensure UTF-8
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            }

            $rows = [];
            $lines = explode("\n", $text);
            $delimiter = "\t"; 
            
            if (!empty($lines[0])) {
                if (strpos($lines[0], "\t") !== false) $delimiter = "\t";
                elseif (strpos($lines[0], ";") !== false) $delimiter = ";";
                elseif (strpos($lines[0], ",") !== false) $delimiter = ",";
            }
            
            $confFields = $config->getFields($base);
            $headers = [];
            foreach($confFields as $f) {
                if(isset($f['deleted']) && $f['deleted']) continue;
                if(isset($f['type']) && $f['type'] === 'title') continue;
                $headers[] = $f['key'];
            }
            
            // Fallbacks if headers empty
            if (empty($headers)) {
                if (stripos($base, 'client') !== false) {
                    $headers = ['Nome', 'CPF'];
                } elseif (stripos($base, 'agenc') !== false) {
                    $headers = ['AG', 'UF', 'SR', 'Nome SR', 'Filial', 'E-mail AG', 'E-mails SR', 'E-mails Filial', 'E-mail Gerente'];
                } else {
                    $headers = ['Status', 'Número Depósito', 'Data Depósito', 'Valor Depósito Principal', 'Texto Pagamento', $IDENT_LABEL, 'Certificado', 'Status 2', 'CPF', 'AG'];
                }
            }

            $isHeader = true;
            $headerMap = [];
            
            foreach ($lines as $line) {
                if (!trim($line)) continue;
                $cols = str_getcsv($line, $delimiter);
                $cols = array_map('trim', $cols);

                if ($isHeader) {
                    $isHeader = false;
                    $matches = 0;
                    $tempMap = [];
                    foreach ($cols as $idx => $colVal) {
                        $matchedKey = null;
                        foreach ($confFields as $f) {
                             if (isset($f['type']) && $f['type'] === 'title') continue;
                             $key = $f['key'];
                             $lbl = isset($f['label']) ? $f['label'] : $key;
                             $valUpper = mb_strtoupper($colVal, 'UTF-8');
                             
                             if ($valUpper === mb_strtoupper($key, 'UTF-8') || $valUpper === mb_strtoupper($lbl, 'UTF-8')) {
                                 $matchedKey = $key;
                                 break;
                             }
                        }
                        
                        if (!$matchedKey) {
                            foreach ($headers as $h) {
                                if (mb_strtoupper($colVal, 'UTF-8') === mb_strtoupper($h, 'UTF-8')) {
                                    $matchedKey = $h;
                                    break;
                                }
                            }
                        }

                        if ($matchedKey) {
                            $matches++;
                            $tempMap[$idx] = $matchedKey; 
                        }
                    }
                    
                    if ($matches >= 2 || ($matches > 0 && count($cols) <= 3)) {
                        $headerMap = $tempMap;
                        continue; 
                    }
                }
                $rows[] = $cols;
            }
            
            $mappedData = [];
            foreach ($rows as $cols) {
                $newRow = [];
                if (!empty($headerMap)) {
                    foreach ($headers as $h) {
                        $foundIdx = array_search($h, $headerMap);
                        if ($foundIdx !== false && isset($cols[$foundIdx])) {
                            $newRow[$h] = $cols[$foundIdx];
                        } else {
                            $newRow[$h] = '';
                        }
                    }
                } else {
                    foreach($headers as $i => $h) {
                        $newRow[$h] = isset($cols[$i]) ? $cols[$i] : '';
                    }
                }
                
                if (stripos($base, 'client') !== false) {
                    if (empty(get_value_ci($newRow, 'CPF'))) continue;
                } elseif (stripos($base, 'agenc') !== false) {
                    if (empty(get_value_ci($newRow, 'AG'))) continue;
                } elseif (stripos($base, 'Processos') !== false || stripos($base, 'Base_processos') !== false) {
                    if (empty(get_value_ci($newRow, $ID_FIELD_KEY))) continue;
                } else {
                    if (empty(get_value_ci($newRow, $IDENT_ID_FIELD))) continue;
                }
                $mappedData[] = $newRow;
            }
            
            $validatedData = [];
            foreach ($mappedData as $row) {
                foreach ($confFields as $f) {
                     $val = get_value_ci($row, $f['key']);
                     
                     // Date Validation
                     if (isset($f['type']) && $f['type'] == 'date' && $val !== '') {
                         $validDate = normalizeDate($val);
                         if ($validDate === false) {
                             $row['DATA_ERROR'] = true;
                         } else {
                             $row[$f['key']] = $validDate;
                             $val = $validDate;
                         }
                     }

                     // Custom Formatting (Apply Mask/Case)
                     if ( ($f['type'] ?? '') === 'custom' || !empty($f['custom_mask']) ) {
                         $row[$f['key']] = format_field_value($f['key'], $val);
                     }
                }
                $coreDates = ['DATA_DEPOSITO', 'DATA'];
                foreach($coreDates as $cd) {
                    $val = get_value_ci($row, $cd);
                    if ($val !== '') {
                         $validDate = normalizeDate($val);
                         if ($validDate === false) {
                             $row['DATA_ERROR'] = true;
                         } else {
                             $row[$cd] = $validDate;
                         }
                    }
                }
                $validatedData[] = $row;
            }
            
            if (empty($validatedData)) {
                ob_clean(); header('Content-Type: application/json');
                echo json_encode(['status'=>'error', 'message'=>"Erro: Nenhum dado válido identificado."]);
            } else {
                $_SESSION['upload_preview'] = $validatedData;
                $_SESSION['upload_preview_base'] = $base;
                
                // Build HTML Table for Preview
                $previewFields = $confFields;
                $previewHeaders = [];
                foreach($previewFields as $f) {
                    if(!isset($f['deleted']) || !$f['deleted']) {
                        if (isset($f['type']) && $f['type'] === 'title') continue;
                        $previewHeaders[] = $f['key'];
                    }
                }
                if(empty($previewHeaders)) $previewHeaders = $headers; // Fallback

                ob_start();
                echo '<style>body { font-family: Arial, sans-serif !important; } .import-preview-table th, .import-preview-table td { font-size: 11px !important; }</style>';
                echo '<table class="table table-sm table-bordered table-striped small import-preview-table"><thead><tr class="bg-light"><th>#</th>';
                foreach($previewHeaders as $h) {
                    $lbl = $h;
                    foreach($previewFields as $f) { if($f['key'] == $h) { $lbl = $f['label']; break; } }
                    echo '<th>' . htmlspecialchars($lbl) . '</th>';
                }
                echo '<th>Validação</th></tr></thead><tbody>';
                foreach($validatedData as $idx => $row) {
                    $err = isset($row['DATA_ERROR']) ? 'table-danger' : '';
                    echo '<tr class="'.$err.'"><td>'.($idx + 1).'</td>';
                    foreach($previewHeaders as $h) {
                        echo '<td>' . htmlspecialchars($row[$h] ?? '') . '</td>';
                    }
                    echo '<td>';
                    if(isset($row['DATA_ERROR'])) echo '<span class="badge bg-danger">Data Inválida</span>';
                    else echo '<span class="badge bg-success">OK</span>';
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
                $html = ob_get_clean();

                ob_clean(); header('Content-Type: application/json');
                echo json_encode(['status'=>'ok', 'html'=>$html]);
            }
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Conteúdo vazio.']);
        }
        exit;
    }

    // --- GENERIC BASE HANDLERS ---

    if ($act == 'ajax_get_base_schema') {
        $base = $_POST['base'];
        $fields = $config->getFields($base);
        
        $activeFields = [];
        foreach($fields as $f) {
            if (!isset($f['deleted']) || !$f['deleted']) {
                $key = $f['key'] ?? '';
                if (mb_strtoupper($key) === 'NOME_ATENDENTE') {
                    $f['type'] = 'select';
                    $years = $_SESSION['selected_years'] ?? [date('Y')];
                    $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                    $dataFiles = $db->getProcessFiles($years, $months);
                    $opts = [];
                    foreach($dataFiles as $df) {
                        $vals = $db->getUniqueValues($df, $key);
                        if (is_array($vals)) $opts = array_merge($opts, $vals);
                    }
                    if (isset($_SESSION['nome_completo'])) {
                        $opts[] = $_SESSION['nome_completo'];
                    }
                    $f['options'] = implode(',', array_unique(array_filter($opts)));
                }
                $activeFields[] = $f;
            }
        }
        
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'fields'=>$activeFields]);
        exit;
    }

    if ($act == 'ajax_render_base_table') {
        $base = $_POST['base']; // e.g., 'Base_clientes.json'
        $fBusca = $_POST['cfBusca'] ?? '';
        $page = $_POST['cpPagina'] ?? 1;
        $sortColReq = $_POST['sortCol'] ?? null;
        $sortDirReq = $_POST['sortDir'] ?? null;
        
        $filters = [];
        if ($fBusca) $filters['global'] = $fBusca;

        // Dynamic Filters (Base Agnostic)
        // Load filters from Schema based on show_base_filter
        $baseSchema = $config->getFields($base);
        $bFilters = [];
        $fieldMap = [];
        $fieldConfigMap = [];
        foreach ($baseSchema as $f) {
             $show = (isset($f['show_base_filter']) && $f['show_base_filter']) || (!isset($f['show_base_filter']) && isset($f['show_filter']) && $f['show_filter']);
             if ($show) {
                 $bFilters[] = $f['key'];
                 $fieldMap[$f['key']] = $f['type'] ?? 'text';
                 $fieldConfigMap[$f['key']] = $f;
             }
        }
        // Always include Fixed Atendente check if passed
        if (!empty($_POST['fAtendente'])) {
             // We'll handle Atendente in callback for CI safety or strict here
             // Database select supports equality. But let's use callback for robust normalizing.
             // But existing logic used equality $filters['Nome_atendente'] = ...
             // Let's use callback for all dynamic + fixed to be safe.
        }
        
        // Detect correct keys for Legacy Filters (Status/Atendente)
        $statusKey = 'STATUS';
        $atendenteKey = 'Nome_atendente'; // Default
        
        foreach ($baseSchema as $f) {
            $fkUpper = mb_strtoupper($f['key']);
            if ($fkUpper === 'STATUS') $statusKey = $f['key'];
            if (in_array($fkUpper, ['ATENDENTE', 'NOME_ATENDENTE', 'RESPONSAVEL'])) $atendenteKey = $f['key'];
        }

        $dynamicRules = [];
        foreach($bFilters as $bk) {
             $val = $_POST['f_'.$bk] ?? '';
             if ($val !== '') $dynamicRules[$bk] = $val;
        }
        
        // Map Legacy Filters to actual keys
        if (!empty($_POST['fAtendente'])) $dynamicRules[$atendenteKey] = $_POST['fAtendente'];
        if (!empty($_POST['fStatus'])) $dynamicRules[$statusKey] = $_POST['fStatus'];
        
        // Composite Callback for Dynamic Rules
        $checkDynamicBase = function($row) use ($dynamicRules, $fieldMap, $fieldConfigMap) {
             foreach($dynamicRules as $k => $v) {
                 $rowVal = get_value_ci($row, $k);
                 
                 // Type Aware Check
                 $fType = $fieldMap[$k] ?? 'text';
                 $fConfig = $fieldConfigMap[$k] ?? [];
                 
                 // Special Handling for Dates
                 if ($fType === 'date' || $fType === 'datetime') {
                     if (!$rowVal) return false;
                     // Parse Row Date
                     $dRow = DateTime::createFromFormat('d/m/Y', $rowVal);
                     if (!$dRow) $dRow = DateTime::createFromFormat('Y-m-d', $rowVal); // ISO
                     if (!$dRow) $dRow = DateTime::createFromFormat('d/m/Y H:i:s', $rowVal); // Full
                     if (!$dRow) $dRow = DateTime::createFromFormat('Y-m-d H:i:s', $rowVal); // Full ISO
                     
                     // Parse Filter Date
                     $dFilter = DateTime::createFromFormat('Y-m-d', $v);
                     if (!$dFilter) $dFilter = DateTime::createFromFormat('d/m/Y', $v);
                     
                     if ($dRow && $dFilter) {
                         if ($dRow->format('Y-m-d') !== $dFilter->format('Y-m-d')) return false;
                     } else {
                         // Fallback to string compare if parsing fails
                         if ($rowVal != $v) return false;
                     }
                     continue;
                 }

                 // Check for Mask / Custom Type: Normalize for flexible search
                 $mask = $fConfig['custom_mask'] ?? ($fConfig['mask'] ?? '');
                 if (!empty($mask) || $fType === 'custom') {
                      // Normalize both to alphanumeric
                      $vNorm = preg_replace('/[^a-zA-Z0-9]/', '', $v);
                      $rNorm = preg_replace('/[^a-zA-Z0-9]/', '', $rowVal ?? '');
                      
                      if ($vNorm !== '') {
                          if (mb_stripos($rNorm, $vNorm) === false) return false;
                          continue;
                      }
                      // If vNorm is empty (e.g. only separators entered), fall through to standard string search
                 }

                 // Force exact for Status/Atendente/Select
                 if (in_array(mb_strtoupper($k), ['STATUS', 'NOME_ATENDENTE', 'ATENDENTE']) || $fType === 'select') {
                     if (mb_strtoupper(trim((string)$rowVal)) !== mb_strtoupper(trim((string)$v))) return false;
                     continue;
                 }
                 
                 if (in_array($fType, ['text', 'textarea', 'custom'])) {
                     if (mb_stripos($rowVal, $v) === false) return false;
                 } else {
                     if (mb_strtoupper(trim((string)$rowVal)) !== mb_strtoupper(trim((string)$v))) return false;
                 }
             }
             return true;
        };
        
        // Date filtering if applicable
        $fDataIni = $_POST['cfDataIni'] ?? '';
        $fDataFim = $_POST['cfDataFim'] ?? '';
        
        if ($fDataIni || $fDataFim) {
             $dateCol = null;

             if (stripos($base, 'cred') !== false) $dateCol = 'DATA_DEPOSITO';
             elseif ($base === 'Processos' || stripos($base, 'processo') !== false) $dateCol = 'DATA';
             
             if (!$dateCol) {
                 $baseFields = $config->getFields($base);
                 foreach ($baseFields as $f) {
                     if (isset($f['type']) && $f['type'] == 'date') {
                         $dateCol = $f['key'];
                         break;
                     }
                 }
             }
             
             if ($dateCol) {
                  // Support datetime-local (Y-m-dTH:i) or date-only (Y-m-d) inputs
                  $di = null;
                  $df = null;
                  if ($fDataIni) {
                      $di = DateTime::createFromFormat('Y-m-d\TH:i', $fDataIni);
                      if (!$di) $di = DateTime::createFromFormat('!Y-m-d', $fDataIni);
                      if (!$di) $di = DateTime::createFromFormat('!d/m/Y', $fDataIni); // BR fallback
                  }
                  if ($fDataFim) {
                      $df = DateTime::createFromFormat('Y-m-d\TH:i', $fDataFim);
                      if (!$df) {
                          $df = DateTime::createFromFormat('!Y-m-d', $fDataFim);
                          if (!$df) $df = DateTime::createFromFormat('!d/m/Y', $fDataFim);
                          if ($df) $df->setTime(23, 59, 59);
                      }
                  }

                  $filters['callback'] = function($row) use ($di, $df, $dateCol, $checkDynamicBase) {
                     if (!$checkDynamicBase($row)) return false;
                     
                     $d = get_value_ci($row, $dateCol);
                     if (!$d) return false;
                      // Parse stored date with time support
                      $dt = DateTime::createFromFormat('d/m/Y H:i:s', $d);
                      if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $d);
                      if (!$dt) $dt = DateTime::createFromFormat('!d/m/Y', $d);
                      if (!$dt) $dt = DateTime::createFromFormat('Y-m-d', $d); // ISO
                      if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $d); // ISO Full
                     if (!$dt) return false;
                     
                     if ($di && $dt < $di) return false;
                     if ($df && $dt > $df) return false;
                     return true;
                  };
             } else {
                 if (!empty($dynamicRules)) $filters['callback'] = $checkDynamicBase;
             }
        } else {
             // No Date Filter, apply Dynamic Rules directly
             if (!empty($dynamicRules)) $filters['callback'] = $checkDynamicBase;
        }

        // Determine sort column
        $headers = $db->getHeaders($base);
        $sortCol = $headers[0] ?? null;
        $desc = true;

        if (stripos($base, 'cred') !== false) $sortCol = 'DATA_DEPOSITO';
        if (stripos($base, 'client') !== false) {
            $sortCol = 'Nome'; 
            $desc = false; 
        }

        if ($sortColReq) {
            $sortCol = $sortColReq;
            $desc = ($sortDirReq === 'desc');
        }

        if ($base === 'Processos') {
             $years = $_SESSION['selected_years'] ?? [date('Y')];
             $months = $_SESSION['selected_months'] ?? [(int)date('n')];
             $targetFiles = $db->getProcessFiles($years, $months);
             // Default if not requested
             if (!$sortColReq) { $sortCol = 'DATA'; $desc = true; }
             
             $res = $db->select($targetFiles, $filters, $page, 50, $sortCol, $desc);
        } else {
             $res = $db->select($base, $filters, $page, 50, $sortCol, $desc);
        }

        $rows = $res['data'];
        
        // Fix: Inject Client Names if viewing Processos base
        if ($base === 'Processos') {
             $cpfs = [];
             foreach($rows as $r) {
                 $val = get_value_ci($r, 'CPF');
                 if ($val) $cpfs[] = $val;
             }
             
             if (!empty($cpfs)) {
                 $cpfs = array_unique($cpfs);
                 $clients = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
                 $clientMap = [];
                 foreach ($clients as $c) {
                     $cKey = get_value_ci($c, 'CPF');
                     if ($cKey) $clientMap[$cKey] = get_value_ci($c, 'Nome');
                 }
                 
                 foreach ($rows as &$r) {
                     $cKey = get_value_ci($r, 'CPF');
                     if ($cKey && isset($clientMap[$cKey])) {
                         $r['Nome'] = $clientMap[$cKey];
                     }
                 }
                 unset($r);
             }
        }

        $allFields = $config->getFields($base);
        $confFields = [];
        
        // Dynamic Columns Definition (Configured Only)
        // User requested full control via configuration. Fixed columns logic removed.
        
        foreach($allFields as $f) {
            // Only show if explicitly configured (Base Column Flag OR Legacy Column Flag)
            $show = (isset($f['show_base_column']) && $f['show_base_column']) || (!isset($f['show_base_column']) && isset($f['show_column']) && $f['show_column']);
            
            if ($show) {
                $confFields[] = ['key' => $f['key'], 'label' => $f['label'] ?? $f['key']];
            }
        }
        

        
        // Fallback for fresh bases with no config: Show all?
        // If $confFields only has fixed cols (or empty if fixed don't apply), 
        // we might show nothing for a new base.
        // Let's add a check: if NO show_column is set anywhere, show defaults?
        // But user wants "configurar". It's safer to require config.
        // However, for UX, maybe show all if nothing configured?
        // Let's stick to: Fixed + show_column.
        
        if (empty($confFields)) {
            // Fallback
            foreach($headers as $h) $confFields[] = ['key'=>$h, 'label'=>$h];
        }
        
        // Apply Saved Order
        $bOrder = $settings->get('base_columns_order_' . $base, []);
        if(!empty($bOrder)) {
            $sorted = []; $indexed = [];
            foreach($confFields as $c) $indexed[$c['key']] = $c;
            foreach($bOrder as $k) { if(isset($indexed[$k])) { $sorted[] = $indexed[$k]; unset($indexed[$k]); } }
            foreach($indexed as $c) $sorted[] = $c;
            $confFields = $sorted;
        }

        // Determine PK
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = $IDENT_ID_FIELD;
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = $ID_FIELD_KEY;

        ob_start();
        $fontSize = ($base === 'Processos' || $base === 'Identificacao.json') ? '12px' : '11px';
        echo '<style>body { font-family: Arial, sans-serif !important; } .sortable-header { font-size: ' . $fontSize . '; } #base_table td { font-size: ' . $fontSize . '; }</style>';
        echo '<thead id="base_sortable_head"><tr>';
        echo '<th><input type="checkbox" onchange="toggleSelectAll(this)"></th>';
        foreach($confFields as $f) {
            $colKey = $f['key'];
            $icon = '<i class="fas fa-sort text-muted ms-1" style="font-size:0.8em; opacity:0.5"></i>';
            if ($sortCol === $colKey) {
                $icon = ($desc) ? '<i class="fas fa-sort-down text-dark ms-1"></i>' : '<i class="fas fa-sort-up text-dark ms-1"></i>';
            }
            // Added data-col
            echo '<th class="sortable-header" data-col="'.htmlspecialchars($colKey).'" onclick="setBaseSort(\''.htmlspecialchars($colKey).'\')" style="cursor:pointer">' . htmlspecialchars($f['label']) . ' ' . $icon . '</th>';
        }
        echo '<th>Ações</th>';
        echo '</tr></thead><tbody>';

        foreach($rows as $r) {
            echo '<tr>';
            $idVal = get_value_ci($r, $pk);
            echo '<td><input type="checkbox" class="base-checkbox" value="' . htmlspecialchars($idVal) . '"></td>';
            
            foreach($confFields as $f) {
                 $val = get_value_ci($r, $f['key']);
                 
                 // Rich Rendering for Processos key fields
                 if ($base === 'Processos') {
                     if ($f['key'] === 'STATUS') {
                         echo '<td><span class="badge bg-secondary">' . htmlspecialchars(format_field_value($f['key'], $val)) . '</span></td>';
                         continue;
                     }
                     if ($f['key'] === 'Nome_atendente') {
                         echo '<td>' . htmlspecialchars(format_field_value($f['key'], $val));
                         if (!empty($r['Ultima_Alteracao'])) echo '<div class="small text-muted" style="font-size:0.75em"><i class="fas fa-clock me-1"></i>'.htmlspecialchars($r['Ultima_Alteracao']).'</div>';
                         // Check lock? $lockManager available? Yes global.
                         $l = $lockManager->checkLock(get_value_ci($r, $ID_FIELD_KEY), '');
                         if ($l['locked']) echo '<div class="small text-danger fw-bold"><i class="fas fa-lock me-1"></i> '.htmlspecialchars($l['by']).'</div>';
                         echo '</td>';
                         continue;
                      }
                 }
                 echo '<td>' . htmlspecialchars(format_field_value($f['key'], $val)) . '</td>';
            }
            
            echo '<td>
                <button class="btn btn-sm btn-link text-primary p-0 me-2" onclick=\'openBaseModal(' . json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) . ', this)\' title="Editar"><i class="fas fa-pen"></i></button>
                <button class="btn btn-sm btn-link text-danger p-0" onclick="deleteBaseRecord(\'' . htmlspecialchars($idVal) . '\')" title="Excluir"><i class="fas fa-trash"></i></button>
            </td>';
            echo '</tr>';
        }
        
        if(empty($rows)) echo '<tr><td colspan="10" class="text-center py-3">Nenhum registro encontrado.</td></tr>';
        
        echo '</tbody>';
        $html = ob_get_clean();
        
        $paginationHtml = '';
        if ($res['pages'] > 1) {
            $paginationHtml .= '<ul class="pagination justify-content-center pagination-sm">';
            if($res['page'] > 1) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="renderBaseTable('.($res['page']-1).')">Anterior</a></li>';
            $paginationHtml .= '<li class="page-item disabled"><a class="page-link">'.$res['page'].' / '.$res['pages'].'</a></li>';
            if($res['page'] < $res['pages']) $paginationHtml .= '<li class="page-item"><a class="page-link" href="#" onclick="renderBaseTable('.($res['page']+1).')">Próxima</a></li>';
            $paginationHtml .= '</ul>';
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'html' => $html, 'pagination' => $paginationHtml, 'count' => $res['total']]);
        exit;
    }

    if ($act == 'ajax_save_base_record') {
        $base = $_POST['base'];
        $originalId = $_POST['original_id'] ?? '';
        
        $confFields = $config->getFields($base);
        $data = [];
        $errors = [];
        foreach($confFields as $f) {
            if (($f['type'] ?? '') === 'title') continue;
            $key = $f['key'];
            if(isset($_POST[$key])) {
                $val = $_POST[$key];
                if ($f['type'] == 'date' && !empty($val)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $val);
                    if ($dt && $dt->format('Y-m-d') === $val) {
                        $val = $dt->format('d/m/Y');
                    }
                }
                $data[$key] = $val;
                
                // Validation
                if ($f['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                    $errors[] = "O campo " . ($f['label'] ?: $key) . " deve conter apenas números.";
                }

                if ($f['type'] === 'custom') {
                    // Normalize to Formatted Value
                    $val = format_field_value($key, $val);
                    $data[$key] = $val;
                }

                if (isset($f['required']) && $f['required'] && (!isset($f['deleted']) || !$f['deleted'])) {
                    // Allow Nome_atendente to be empty on save/open; session user will be applied
                    if (mb_strtoupper($f['key'], 'UTF-8') === 'NOME_ATENDENTE') {
                        // skip
                    } else {
                        if (trim((string)$val) === '') {
                            $errors[] = "Campo obrigatório não preenchido: " . ($f['label'] ?: $key);
                        }
                    }
                }
            }
        }
        
        if (!empty($errors)) {
             ob_clean(); header('Content-Type: application/json');
             echo json_encode(['status'=>'error', 'message'=>implode("<br>", $errors)]);
             exit;
        }
        
        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = $IDENT_ID_FIELD;
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = $ID_FIELD_KEY;

        $newId = $data[$pk] ?? '';
        if (!$newId) {
             ob_clean(); header('Content-Type: application/json');
             echo json_encode(['status'=>'error', 'message'=>'Identificador ('.$pk.') é obrigatório.']);
             exit;
        }

        if ($base === 'Processos') {
             // 1. Bloqueio de Duplicidade de ID
             if ((empty($originalId) || $newId != $originalId) && $indexer->get($newId)) {
                 ob_clean(); header('Content-Type: application/json');
                 echo json_encode(['status'=>'error', 'message'=>'Já existe um processo com este ID ('.$newId.'). Rule: Duplicidade não permitida.']);
                 exit;
             }

             $data['Ultima_Alteracao'] = date('d/m/Y H:i');
             // 2. Atribuição do Nome_atendente (Manual ou Automática)
             if (!isset($data['Nome_atendente']) || empty($data['Nome_atendente'])) {
                 $data['Nome_atendente'] = $_SESSION['nome_completo'];
             }
             
             $dt = DateTime::createFromFormat('d/m/Y', $data['DATA'] ?? '');
             if (!$dt) $dt = new DateTime(); 
             $targetFile = $db->ensurePeriodStructure($dt->format('Y'), $dt->format('n'));
             
             if ($originalId) {
                 $oldFile = $indexer->get($originalId);
                 if (!$oldFile) {
                     $years = $_SESSION['selected_years'] ?? [date('Y')];
                     $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                     $files = $db->getProcessFiles($years, $months);
                     
                     $oldFile = $db->findFileForRecord($files, $pk, $originalId);
                 }
                 
                 if ($oldFile) {
                     if ($oldFile !== $targetFile) {
                         // Preserve old data
                         $oldData = $db->find($oldFile, $pk, $originalId);
                         $fullData = $oldData ? array_merge($oldData, $data) : $data;

                         $db->delete($oldFile, $pk, $originalId);
                         $db->insert($targetFile, $fullData);

                         // Update Indexer
                         if ($originalId != $newId) {
                             // Preserve Related History and Records
                             foreach (['Base_registros.json', 'Base_registros_dados.json'] as $relFile) {
                                 $relPath = $db->getPath($relFile);
                                 if (file_exists($relPath)) {
                                     $relData = $db->readJSON($relPath);
                                     $updatedRel = false;
                                     foreach ($relData as &$rItem) {
                                         $rPort = get_value_ci($rItem, $IDENT_ID_FIELD);
                                         if ($rPort == $originalId) {
                                             $rItem[$IDENT_ID_FIELD] = $newId;
                                             $updatedRel = true;
                                         }
                                     }
                                     if ($updatedRel) $db->writeJSON($relPath, $relData);
                                 }
                             }
                             $indexer->delete($originalId);
                         }
                         $indexer->set($newId, $targetFile);

                         $msg = "Processo atualizado e movido para o período correto!";
                     } else {
                         $db->update($oldFile, $pk, $originalId, $data);
                         
                         // Update Indexer if ID changed
                         if ($originalId != $newId) {
                             // Preserve Related History and Records
                             foreach (['Base_registros.json', 'Base_registros_dados.json'] as $relFile) {
                                 $relPath = $db->getPath($relFile);
                                 if (file_exists($relPath)) {
                                     $relData = $db->readJSON($relPath);
                                     $updatedRel = false;
                                     foreach ($relData as &$rItem) {
                                         $rPort = get_value_ci($rItem, $IDENT_ID_FIELD);
                                         if ($rPort == $originalId) {
                                             $rItem[$IDENT_ID_FIELD] = $newId;
                                             $updatedRel = true;
                                         }
                                     }
                                     if ($updatedRel) $db->writeJSON($relPath, $relData);
                                 }
                             }
                             $indexer->delete($originalId);
                             $indexer->set($newId, $oldFile);
                         }
                         $msg = "Processo atualizado!";
                     }
                     $res = true;
                 } else {
                     $res = false;
                     $msg = "Processo original não encontrado na seleção atual.";
                 }
             } else {
                 $res = $db->insert($targetFile, $data);
                 if ($res) $indexer->set($newId, $targetFile);
                 $msg = "Processo criado!";
             }
        } elseif ($originalId) {
             if ($newId != $originalId) {
                 if ($db->find($base, $pk, $newId)) {
                     ob_clean(); header('Content-Type: application/json');
                     echo json_encode(['status'=>'error', 'message'=>'Novo ID já existe.']);
                     exit;
                 }
             }
             $res = $db->update($base, $pk, $originalId, $data);
             $msg = "Registro atualizado!";
        } else {
             if ($db->find($base, $pk, $newId)) {
                 ob_clean(); header('Content-Type: application/json');
                 echo json_encode(['status'=>'error', 'message'=>'Registro já existe.']);
                 exit;
             }
             $res = $db->insert($base, $data);
             $msg = "Registro criado!";
        }

        ob_clean(); header('Content-Type: application/json');
        if($res) echo json_encode(['status'=>'ok', 'message'=>$msg]);
        else echo json_encode(['status'=>'error', 'message'=>'Erro ao salvar.']);
        exit;
    }

    if ($act == 'ajax_delete_base_record') {
        $base = $_POST['base'];
        $id = $_POST['id'];
        
        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = $IDENT_ID_FIELD;
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = $ID_FIELD_KEY;
        
        $res = false;
        if ($base === 'Processos') {
             $oldFile = $indexer->get($id);
             if (!$oldFile) {
                 $years = $_SESSION['selected_years'] ?? [date('Y')];
                 $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                 $files = $db->getProcessFiles($years, $months);
                 $oldFile = $db->findFileForRecord($files, $pk, $id);
             }

             if ($oldFile) {
                 $res = $db->delete($oldFile, $pk, $id);
                 if ($res) $indexer->delete($id);
             }
        } else {
             $res = $db->delete($base, $pk, $id);
        }

        if($res) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'ok', 'message'=>'Excluído.']);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir.']);
        }
        exit;
    }
    
    if ($act == 'ajax_delete_base_bulk') {
        $base = $_POST['base'];
        $ids = $_POST['ids'] ?? [];
        
        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = $IDENT_ID_FIELD;
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = $ID_FIELD_KEY;

        if ($base === 'Processos') {
             $success = true;
             foreach ($ids as $id) {
                 $oldFile = $indexer->get($id);
                 if (!$oldFile) {
                    $years = $_SESSION['selected_years'] ?? [date('Y')];
                    $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                    $files = $db->getProcessFiles($years, $months);
                    $oldFile = $db->findFileForRecord($files, $pk, $id);
                 }

                 if ($oldFile) {
                     if ($db->delete($oldFile, $pk, $id)) {
                         $indexer->delete($id);
                     } else {
                         $success = false;
                     }
                 }
             }
             // Assume success if loop finishes
             $res = $success;
        } else {
             $res = $db->deleteMany($base, $pk, $ids);
        }

        if($res) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'ok', 'message'=>'Registros excluídos.']);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir.']);
        }
        exit;
    }

    if ($act == 'ajax_prepare_bulk_edit') {
        $base = $_POST['base'];
        $ids = $_POST['ids'] ?? [];

        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = $IDENT_ID_FIELD;
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = $ID_FIELD_KEY;

        $selectedRecords = [];
        if ($base === 'Processos') {
            $years = $_SESSION['selected_years'] ?? [date('Y')];
            $months = $_SESSION['selected_months'] ?? [(int)date('n')];
            $currentFiles = $db->getProcessFiles($years, $months);
            
            foreach ($ids as $id) {
                $file = $indexer->get($id);
                if (!$file) {
                    $file = $db->findFileForRecord($currentFiles, $pk, $id);
                }
                
                if ($file) {
                    $rec = $db->find($file, $pk, $id);
                    if ($rec) $selectedRecords[] = $rec;
                }
            }
        } else {
            $selectedRecords = $db->findMany($base, $pk, $ids);
        }
        
        $fields = $config->getFields($base);
        
        $responseFields = [];
        foreach ($fields as $f) {
            if (isset($f['deleted']) && $f['deleted']) continue;
            if (($f['type'] ?? '') === 'title') continue;
            
            $key = $f['key'];
            $firstVal = null;
            $isCommon = true;
            $first = true;
            
            if (empty($selectedRecords)) {
                $isCommon = false;
            } else {
                foreach ($selectedRecords as $r) {
                    $val = get_value_ci($r, $key);
                    if ($first) {
                        $firstVal = $val;
                        $first = false;
                    } else {
                        if ($val != $firstVal) {
                            $isCommon = false;
                            break;
                        }
                    }
                }
            }
            
            $f['value'] = $isCommon ? $firstVal : '';
            $f['is_common'] = $isCommon;

            // Special handling for Nome_atendente in Bulk Edit
            if (mb_strtoupper($key) === 'NOME_ATENDENTE') {
                $f['type'] = 'select';
                $years = $_SESSION['selected_years'] ?? [date('Y')];
                $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                $dataFiles = $db->getProcessFiles($years, $months);
                $opts = [];
                foreach($dataFiles as $df) {
                    $vals = $db->getUniqueValues($df, $key);
                    if (is_array($vals)) $opts = array_merge($opts, $vals);
                }
                // Adiciona o usuário logado se não estiver na lista
                if (isset($_SESSION['nome_completo'])) {
                    $opts[] = $_SESSION['nome_completo'];
                }
                $f['options'] = implode(',', array_unique(array_filter($opts)));
                // No bulk edit, permitimos mudar mesmo se não for comum entre os selecionados
                $f['is_common'] = true; 
            }

            $responseFields[] = $f;
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'fields'=>$responseFields]);
        exit;
    }

    if ($act == 'ajax_save_base_bulk') {
        $base = $_POST['base'];
        $ids = $_POST['ids'] ?? [];
        $data = [];
        
        // Parse data from POST. Since we are bulk editing, we only receive fields that were enabled.
        // We need to match with schema to ensure proper handling (dates, etc).
        $confFields = $config->getFields($base);
        foreach($confFields as $f) {
            $key = $f['key'];
            if(isset($_POST[$key])) {
                $val = $_POST[$key];
                if ($f['type'] == 'date' && !empty($val)) {
                    $dt = DateTime::createFromFormat('Y-m-d', $val);
                    if ($dt && $dt->format('Y-m-d') === $val) {
                        $val = $dt->format('d/m/Y');
                    }
                }
                if ($f['type'] == 'datetime' && !empty($val)) {
                    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                    if ($dt) $val = $dt->format('d/m/Y H:i');
                }
                $data[$key] = $val;
            }
        }
        
        if (empty($data)) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Nenhuma informação para atualizar.']);
            exit;
        }

        $headers = $db->getHeaders($base);
        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = $IDENT_ID_FIELD;
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = $ID_FIELD_KEY;

        if ($base === 'Processos') {
            $data['Data'] = date('d/m/Y H:i');
            // Se o usuário selecionou um atendente no bulk edit, usa ele.
            // Caso contrário, atribui ao usuário atual que está realizando a alteração.
            if (!isset($data['Nome_atendente']) || empty($data['Nome_atendente'])) {
                $data['Nome_atendente'] = $_SESSION['nome_completo'] ?? 'Desconhecido';
            }
            
            $targetFile = null;
            if (isset($data['DATA']) && !empty($data['DATA'])) {
                $dt = DateTime::createFromFormat('d/m/Y', $data['DATA']);
                if ($dt) {
                    $targetFile = $db->ensurePeriodStructure($dt->format('Y'), $dt->format('n'));
                }
            }

            $years = $_SESSION['selected_years'] ?? [date('Y')];
            $months = $_SESSION['selected_months'] ?? [(int)date('n')];
            $currentFiles = $db->getProcessFiles($years, $months);

            foreach ($ids as $id) {
                $file = $indexer->get($id);
                if (!$file) {
                    $file = $db->findFileForRecord($currentFiles, $pk, $id);
                }
                
                if ($file) {
                     if ($targetFile && $file !== $targetFile) {
                         // Move Record
                         $oldData = $db->find($file, $pk, $id);
                         if ($oldData) {
                             $fullData = array_merge($oldData, $data);
                             $db->delete($file, $pk, $id);
                             $db->insert($targetFile, $fullData);
                             $indexer->set($id, $targetFile);
                         }
                     } else {
                         $db->update($file, $pk, $id, $data);
                     }
                }
            }
        } else {
            foreach ($ids as $id) {
                $db->update($base, $pk, $id, $data);
            }
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'message'=>'Registros atualizados com sucesso!']);
        exit;
    }

    if ($act == 'ajax_save_ident_icon') {
        $icon = $_POST['icon'] ?? '';
        $settings->set('ident_icon', $icon);
        $settings->set('identification_icon', $icon); // Consistent global setting
        echo json_encode(['status'=>'ok']);
        exit;
    }

    if ($act == 'ajax_salvar_campo') {
        $file = $_POST['arquivo_base'];
        $oldKey = $_POST['old_key'] ?? '';
        $required = isset($_POST['required']) ? true : false;
        $showReminder = isset($_POST['show_reminder']) ? true : false;
        
        $key = trim($_POST['key']);
        $fieldData = [
            'key' => $key, 
            'label' => $_POST['label'], 
            'type' => $_POST['type'], 
            'options' => $_POST['options'] ?? '', 
            'required' => $required,
            'show_reminder' => $showReminder,
            'custom_mask' => $_POST['custom_mask'] ?? '',
            'custom_case' => $_POST['custom_case'] ?? '',
            'custom_allowed' => $_POST['custom_allowed'] ?? '',
            'custom_allowed' => $_POST['custom_allowed'] ?? '',
            // Legacy Flags (kept for backward compatibility logic if needed, but UI uses specific ones)
            'show_column' => isset($_POST['show_dashboard_column']), // Mapping Dash to Legacy for safety
            'show_filter' => isset($_POST['show_dashboard_filter']), 
            
            // New Specific Flags
            'show_dashboard_column' => isset($_POST['show_dashboard_column']),
            'show_dashboard_filter' => isset($_POST['show_dashboard_filter']),
            'show_base_column' => isset($_POST['show_base_column']),
            'show_base_filter' => isset($_POST['show_base_filter'])
        ];
        
        ob_clean(); header('Content-Type: application/json');
        
        if ($oldKey) {
            $config->updateField($file, $oldKey, $fieldData);
            
            $isBecomingPrimary = isset($_POST['is_primary_id']);
            
            // Check if we renamed the field OR if it's being set as the new primary ID
            // User requirement: "Sempre que a ID principal for alterada para outro campo, o sistema passe a reconhecer corretamente o novo campo selecionado."
            if ($isBecomingPrimary || $oldKey === $ID_FIELD_KEY) {
                // Determine if we actually changed the ID field (either renamed it or switched to another)
                $newIdKey = $key;
                
                // Update Settings global configuration
                $settings->set('id_field_key', $newIdKey);
                $settings->set('id_label', $fieldData['label']);
                
                // SYNC IDENTIFICACAO SETTINGS: ID must be shared.
                $settings->set('identification_id_field', $newIdKey);
                
                // DATA MIGRATION: 
                // Case 1: Renamed the current ID field (oldKey was ID, and we changed key)
                // Case 2: Switched ID to an existing field (isBecomingPrimary is true, and key != ID_FIELD_KEY)
                
                if ($key !== $oldKey && $oldKey === $ID_FIELD_KEY) {
                    // RENAME LOGIC: Move data from old column to new column in all process files
                    $allFiles = $db->getAllProcessFiles();
                    foreach($allFiles as $relPath) {
                        $fullPath = $db->getPath($relPath);
                        if (file_exists($fullPath)) {
                            $json = json_decode(file_get_contents($fullPath), true);
                            if (is_array($json)) {
                                $modified = false;
                                foreach($json as &$record) {
                                    if (array_key_exists($oldKey, $record)) {
                                        $record[$key] = $record[$oldKey];
                                        unset($record[$oldKey]);
                                        $modified = true;
                                    }
                                }
                                unset($record);
                                if ($modified) {
                                    file_put_contents($fullPath, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                }
                            }
                        }
                    }
                    
                    // Sync Identificacao.json column name as well
                    $identPath = $db->getPath('Identificacao.json');
                    if (file_exists($identPath)) {
                         $identJson = json_decode(file_get_contents($identPath), true);
                         if (is_array($identJson)) {
                             $iMod = false;
                             foreach($identJson as &$iRec) {
                                 if (array_key_exists($oldKey, $iRec)) {
                                     $iRec[$key] = $iRec[$oldKey];
                                     unset($iRec[$oldKey]);
                                     $iMod = true;
                                 }
                             }
                             unset($iRec);
                             if ($iMod) {
                                 file_put_contents($identPath, json_encode($identJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                             }
                         }
                    }
                }
                
                // Update the field definition in Identificacao to match Processos ID Schema
                $config->updateField('Identificacao.json', $ID_FIELD_KEY, $fieldData);
                // Also update the key in the schema if it changed
                if ($key !== $ID_FIELD_KEY) {
                    $config->updateField('Identificacao.json', $key, $fieldData);
                }

                // Update local variable for current request scope consistency
                $ID_FIELD_KEY = $key;
            }
            
            echo json_encode(['status'=>'ok', 'message'=>'Campo atualizado com sucesso!']);
        } else {
            // Check for duplicates
            $existing = $config->getFields($file);
            $exists = false;
            $deletedKey = null;

            foreach ($existing as $f) {
                if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                    $exists = true; 
                    if (isset($f['deleted']) && $f['deleted']) {
                         $deletedKey = $f['key'];
                    }
                    break;
                }
            }
            
            if ($exists) {
                if ($deletedKey) {
                    $config->updateField($file, $deletedKey, $fieldData);
                    $config->reactivateField($file, $deletedKey);
                    echo json_encode(['status'=>'ok', 'message'=>'Campo restaurado e atualizado com sucesso!']);
                } else {
                    echo json_encode(['status'=>'error', 'message'=>"Erro: O campo '$key' já existe!"]);
                }
            } else {
                $config->addField($file, $fieldData);
                echo json_encode(['status'=>'ok', 'message'=>'Campo adicionado com sucesso!']);
            }
        }
        
        exit;
    }

    if ($act == 'ajax_copy_field') {
        $sourceFile = $_POST['source_file'];
        $targetFile = $_POST['target_file'];
        $key = $_POST['key'];
        
        $sourceFields = $config->getFields($sourceFile);
        $fieldToCopy = null;
        foreach($sourceFields as $f) {
            if ($f['key'] === $key) {
                $fieldToCopy = $f;
                break;
            }
        }
        
        if ($fieldToCopy) {
            $targetFields = $config->getFields($targetFile);
            $newKey = $fieldToCopy['key'];
            
            // Auto-rename loop to avoid collision
            $counter = 1;
            while (true) {
                $exists = false;
                foreach($targetFields as $tf) {
                    if ($tf['key'] === $newKey) {
                        $exists = true; 
                        break;
                    }
                }
                if (!$exists) break;
                // Generate new key
                $newKey = $fieldToCopy['key'] . '_' . $counter;
                $counter++;
            }
            
            $fieldToCopy['key'] = $newKey;
            // Keep label same or append copy? User requirement suggests a copy. 
            // Usually copying implies same properties.
            
            $config->addField($targetFile, $fieldToCopy);
            echo json_encode(['status'=>'ok', 'message'=>'Campo copiado com sucesso!']);
        } else {
             echo json_encode(['status'=>'error', 'message'=>'Campo origem não encontrado.']);
        }
        exit;

    }

    if ($act == 'ajax_remover_campo') {
        $file = $_POST['arquivo_base'];
        $key = $_POST['key'];
        $config->removeField($file, $key);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'message'=>'Campo removido com sucesso!']);
        exit;
    }

    if ($act == 'ajax_salvar_template') {
        $templates->save($_POST['id_template'] ?? '', $_POST['titulo'], $_POST['corpo']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'message'=>'Modelo salvo com sucesso!']);
        exit;
    }

    if ($act == 'ajax_excluir_template') {
        $templates->delete($_POST['id_exclusao']);
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'message'=>'Modelo excluído!']);
        exit;
    }

    if ($act == 'ajax_excluir_processo') {
        $port = $_POST['id_exclusao'];
        if ($port) {
            $file = $indexer->get($port);
            if ($file) {
                $db->delete($file, $ID_FIELD_KEY, $port);
                $indexer->delete($port);
                $msg = "Processo excluído com sucesso.";
            } else {
                // Fallback (e.g. legacy check)
                $years = $_SESSION['selected_years'] ?? [date('Y')];
                $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                $files = $db->getProcessFiles($years, $months);
                foreach ($files as $f) {
                    if ($db->delete($f, $ID_FIELD_KEY, $port)) {
                         // Found by brute force
                         break;
                    }
                }
                $msg = "Processo excluído (se encontrado).";
            }
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'ok', 'message'=>$msg]);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Identificador inválido.']);
        }
        exit;
    }

    if ($act == 'ajax_render_base_filters') {
         $baseFile = $_POST['file'] ?? 'Identificacao.json'; 
         $baseSchema = $config->getFields($baseFile);
         $renderFilters = [];
         
         foreach ($baseSchema as $f) {
             $show = (isset($f['show_base_filter']) && $f['show_base_filter']) || (!isset($f['show_base_filter']) && isset($f['show_filter']) && $f['show_filter']);
             if ($show) $renderFilters[] = $f['key'];
         }
         
         
         // Fallback removed to allow full user control

         
         ob_start();
         foreach($renderFilters as $bk) {
             $label = $bk;
             $fType = 'text';
             foreach($baseSchema as $f) { 
                 if($f['key'] == $bk) { 
                     $label = $f['label']; 
                     $fType = $f['type'] ?? 'text';
                     break; 
                 } 
             }
             
             $idAttr = '';
             
             // Use smaller columns (col-md-2) and smaller inputs
             echo '<div class="col-md-2 mb-3 me-2">';
             echo '<label class="form-label small mb-1 fw-bold text-muted" style="font-size:0.8rem">' . htmlspecialchars($label) . '</label>';
             if ($fType === 'select') {
                 echo '<select name="f_' . htmlspecialchars($bk) . '" ' . $idAttr . ' class="form-select form-select-sm shadow-sm"></select>';
             } else {
                 echo '<input type="text" name="f_' . htmlspecialchars($bk) . '" class="form-control form-control-sm shadow-sm">';
             }
             echo '</div>';
         }
         $html = ob_get_clean();
         ob_clean();
         header('Content-Type: application/json');
         echo json_encode(['status'=>'ok', 'html'=>$html]);
         exit;
    }

    if ($act == 'ajax_render_config') {
        ob_start();
        ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="text-navy mb-0">Configurações de Campos</h3>
        </div>
        <div class="row mb-5">
            <?php foreach(['Base_processos_schema' => 'Processos', 'Base_registros_schema' => 'Campos de Registros', 'Identificacao.json' => $IDENT_LABEL] as $file => $label): ?>
            <div class="col-md-3">
                <div class="card card-custom p-3 h-100">
                    <h5 class="text-navy"><?= $label ?> <small class="text-muted fs-6">(Arraste para ordenar)</small></h5>
                    <ul class="list-group list-group-flush mb-3 sortable-list" data-file="<?= $file ?>">
                        <?php foreach($config->getFields($file) as $f): 
                            if(isset($f['deleted']) && $f['deleted']) continue;
                            


                            $lockedFields = [$ID_FIELD_KEY, 'Data', 'Nome_atendente']; // Fixed fields are protected 
                            $isLocked = ($file !== 'Base_registros_schema') && in_array($f['key'], $lockedFields);
                            $isTitle = ($f['type'] === 'title');
                            $liClass = $isTitle ? 'list-group-item-secondary fw-bold' : '';
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center <?= $liClass ?>" data-key="<?= htmlspecialchars($f['key']) ?>">
                            <div>
                                <i class="fas fa-grip-vertical text-muted me-2 handle"></i> 
                                <?= htmlspecialchars($f['label']) ?> 
                                <?php if(!$isTitle): ?>
                                    <small class="text-muted">(<?= htmlspecialchars($f['type']) ?>)</small> 
                                    <?php if($f['required'] ?? false): ?><span class="text-danger">*</span><?php endif; ?>
                                <?php else: ?>
                                    <small class="text-muted fst-italic">(Título/Seção)</small>
                                <?php endif; ?>
                                <?php if($f['show_reminder'] ?? false): ?>
                                    <span class="badge bg-warning text-dark ms-2" title="Exibido em Lembretes"><i class="fas fa-bell"></i></span>
                                <?php endif; ?>
                                <?php if($f['show_dashboard_column'] ?? ($f['show_column'] ?? false)): ?>
                                    <span class="badge bg-primary ms-1" title="Coluna Dashboard"><i class="fas fa-table"></i> D</span>
                                <?php endif; ?>
                                <?php if($f['show_dashboard_filter'] ?? ($f['show_filter'] ?? false)): ?>
                                    <span class="badge bg-info text-dark ms-1" title="Filtro Dashboard"><i class="fas fa-filter"></i> D</span>
                                <?php endif; ?>
                                <?php if($f['show_base_column'] ?? ($f['show_column'] ?? false)): ?>
                                    <span class="badge bg-warning text-dark ms-1" title="Coluna Base"><i class="fas fa-database"></i> B</span>
                                <?php endif; ?>
                                <?php if($f['show_base_filter'] ?? ($f['show_filter'] ?? false)): ?>
                                    <span class="badge bg-success ms-1" title="Filtro Base"><i class="fas fa-search"></i> B</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-link text-info" onclick='editField(<?= json_encode($f, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, "<?= htmlspecialchars($file) ?>")'><i class="fas fa-pen"></i></button>
                                <?php if(!$isLocked): ?>
                                <button class="btn btn-sm btn-link text-danger" onclick="removeField('<?= $file ?>', '<?= $f['key'] ?>')"><i class="fas fa-trash"></i></button>
                                <?php else: ?>
                                <button class="btn btn-sm btn-link text-muted" disabled title="Campo Protegido"><i class="fas fa-lock"></i></button>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-auto d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick="addFieldModal('<?= $file ?>')">Add Campo</button>
                        <button class="btn btn-sm btn-outline-secondary flex-grow-1" onclick="addTitleModal('<?= $file ?>')">Add Título</button>
                        <?php if($file === 'Identificacao.json'): ?>
                            <button class="btn btn-sm btn-outline-warning" onclick="changeIdentIcon()" title="Alterar Ícone"><i class="fas fa-icons"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <!--
        <h3 class="text-navy mb-4">Modelos de Textos</h3>
        <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between mb-3">
                <h5 class="text-navy">Modelos Cadastrados</h5>
                <button class="btn btn-navy btn-sm" onclick="modalTemplate()">Novo Modelo</button>
            </div>
            <table class="table table-hover">
                <thead><tr><th>Título</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach($templates->getAll() as $t): ?>
                    <tr>
                        <td><?= htmlspecialchars($t['titulo']) ?></td>
                        <td>
                            <button class="btn btn-sm btn-link text-info" onclick='editTemplate(<?= json_encode($t, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'><i class="fas fa-pen"></i></button>
                            <button class="btn btn-sm btn-link text-danger" onclick="confirmTemplateDelete('<?= htmlspecialchars($t['id']) ?>')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        -->
        <?php
        $html = ob_get_clean();
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'html'=>$html]);
        exit;
    }
    
    exit;
    exit;
}

// LOGIN
if (isset($_POST['acao']) && $_POST['acao'] == 'login') {
    $user = trim($_POST['usuario']);
    if (!empty($user)) {
        $_SESSION['logado'] = true;
        $_SESSION['nome_completo'] = $user;
        header("Location: index.php");
        exit;
    } else {
        $erroLogin = "Informe seu nome.";
    }
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (!isset($_SESSION['logado'])) {
    ?>


    <!DOCTYPE html>
    <html lang="pt-br">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login - <?= htmlspecialchars($SYSTEM_NAME) ?></title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background: linear-gradient(135deg, #FF4500, #FF8C00, #FFA500); height: 100vh; display: flex; align-items: center; justify-content: center; }
            .login-card { background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); width: 100%; max-width: 400px; text-align: center; }
            .btn-navy { background-color: #003366; color: white; border-radius: 20px; width: 100%; padding: 10px; }
            .btn-navy:hover { background-color: #002244; color: white; }
        </style>
    </head>
    <body>
        <div class="login-card">
            <h3 class="mb-4" style="color: #003366;">SPA Login</h3>
            <?php if(isset($erroLogin)) echo "<div class='alert alert-danger'>$erroLogin</div>"; ?>
            <form method="POST">
                <input type="hidden" name="acao" value="login">
                <div class="mb-3">
                    <input type="text" name="usuario" class="form-control" placeholder="Seu Nome Completo" required>
                </div>
                <button class="btn btn-navy">Entrar</button>
            </form>
        </div>
        <script>
            // Global listener to prevent leading whitespace in inputs
            document.addEventListener('input', function(e) {
                var target = e.target;
                if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
                    var type = target.type;
                    // Exclude non-text inputs
                    if (['checkbox', 'radio', 'file', 'button', 'submit', 'reset', 'image', 'hidden', 'range', 'color'].indexOf(type) !== -1) {
                        return;
                    }
                    
                    var val = target.value;
                    if (val && val.length > 0 && /^\s/.test(val)) {
                        var start = target.selectionStart;
                        var end = target.selectionEnd;
                        var newVal = val.replace(/^\s+/, '');
                        
                        if (val !== newVal) {
                            target.value = newVal;
                            // Adjust cursor position
                            if (type !== 'email' && type !== 'number') { 
                                try {
                                    var diff = val.length - newVal.length;
                                    if (start >= diff) {
                                        target.setSelectionRange(start - diff, end - diff);
                                    } else {
                                        target.setSelectionRange(0, 0);
                                    }
                                } catch(err) {
                                    // Ignore errors for input types that don't support selection
                                }
                            }
                        }
                    }
                }
            });
        </script>
    </body>
    </html>
    <?php
    exit;
}

// --- UI HELPERS FOR NAVIGATION ---
$availableYears = [];
$baseDir = 'dados/Processos';
if (is_dir($baseDir)) {
    $dirs = scandir($baseDir);
    foreach ($dirs as $d) {
        if ($d != '.' && $d != '..' && is_dir($baseDir . '/' . $d) && is_numeric($d)) {
            // Remove future years
            if ((int)$d <= (int)date('Y')) {
                $availableYears[] = $d;
            }
        }
    }
}
// Ensure Current Year is always available option
$currentYear = date('Y');
if (!in_array($currentYear, $availableYears)) $availableYears[] = $currentYear;
rsort($availableYears);

$selYears = $_SESSION['selected_years'] ?? [$currentYear];
$selMonths = $_SESSION['selected_months'] ?? [(int)date('n')];

// DOWNLOAD CREDITO TEMPLATE
if (isset($_GET['acao']) && $_GET['acao'] == 'download_identificacao_template') {
    $filename = "Modelo_Importacao_Identificacao.csv";
    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
     $confFields = $config->getFields('Identificacao.json');
     $headerLabels = [];
     foreach($confFields as $f) {
         if (($f['type'] ?? '') === 'title') continue;
         $headerLabels[] = $f['label'];
     }
     echo implode(';', $headerLabels) . "\n";
    exit;
}

// DOWNLOAD CREDITO FULL OR HEADERS
if (isset($_GET['acao']) && ($_GET['acao'] == 'download_base' || $_GET['acao'] == 'download_identificacao_full')) {
    $base = $_GET['base'] ?? 'Identificacao.json';
    $filename = "Base_" . str_replace('.json', '', $base) . "_" . date('d-m-Y') . ".xls";
    
    $headers = $db->getHeaders($base);
    // Fallback if empty
    if (empty($headers)) {
        $confFields = $config->getFields($base);
        foreach($confFields as $f) {
            if (isset($f['type']) && $f['type'] === 'title') continue;
            $headers[] = $f['key'];
        }
    }
    
    if ($base === 'Processos') {
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $targetFiles = $db->getProcessFiles($years, $months);
        $res = $db->select($targetFiles, [], 1, 1000000, 'DATA', true);
        $rows = $res['data'];
    } else {
        $rows = $db->readJSON($db->getPath($base));
    }
    
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    
    echo '<?xml version="1.0"?' . '>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?' . '>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet" xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
    echo '<Styles><Style ss:ID="Header"><Font ss:FontName="Calibri" x:Family="Swiss" ss:Size="11" ss:Color="#FFFFFF" ss:Bold="1"/><Interior ss:Color="#003366" ss:Pattern="Solid"/></Style></Styles>' . "\n";
    echo '<Worksheet ss:Name="Sheet1"><Table>' . "\n";
    
    echo '<Row>' . "\n";
    foreach($headers as $h) echo '<Cell ss:StyleID="Header"><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
    
    foreach($rows as $row) {
        echo '<Row>' . "\n";
        foreach($headers as $h) {
            $val = get_value_ci($row, $h);
            echo '<Cell><Data ss:Type="String">' . htmlspecialchars($val) . '</Data></Cell>' . "\n";
        }
        echo '</Row>' . "\n";
    }
    
    echo '</Table></Worksheet></Workbook>';
    exit;
}

// EXPORT EXCEL (RESTAURADO COMPLETO)
if (isset($_GET['acao']) && $_GET['acao'] == 'exportar_excel') {
    $filename = "Relatorio_SPA_" . date('d-m-Y_His') . ".xls";
    $fBusca = $_GET['fBusca'] ?? '';
    // Extra filters from dynamic UI
    $extraFilters = [];
    foreach($_GET as $gk => $gv) {
        if (strpos($gk, 'f_') === 0 && !empty($gv)) {
            $extraFilters[substr($gk, 2)] = $gv;
        }
    }
    
    $filters = $extraFilters;
    
    $checkDate = function($row) use ($extraFilters) {
        foreach($extraFilters as $fk => $fv) {
             $rowVal = get_value_ci($row, $fk);
             if ($rowVal != $fv) return false;
        }
        return true;
    };

    if ($fBusca) {
          $resCred = $db->select('Identificacao.json', ['global' => $fBusca], 1, 10000);
          $foundPorts = array_column($resCred['data'], $IDENT_ID_FIELD);

          $filters['callback'] = function($row) use ($fBusca, $foundPorts, $checkDate, $ID_FIELD_KEY) {
               if (!$checkDate($row)) return false;
               foreach ($row as $val) {
                   if (stripos((string)$val, $fBusca) !== false) return true;
                   $vNorm = preg_replace('/[^a-zA-Z0-9]/', '', $fBusca);
                   $rNorm = preg_replace('/[^a-zA-Z0-9]/', '', (string)$val);
                   if ($vNorm !== '' && stripos($rNorm, $vNorm) !== false) return true;
               }
               if (!empty($foundPorts) && isset($row[$ID_FIELD_KEY]) && in_array($row[$ID_FIELD_KEY], $foundPorts)) return true;
               return false;
          };
    } else {
         $filters['callback'] = $checkDate;
    }
    
    $years = $_SESSION['selected_years'] ?? [date('Y')];
    $months = $_SESSION['selected_months'] ?? [(int)date('n')];
    $targetFile = $db->getProcessFiles($years, $months);

    $res = $db->select($targetFile, $filters, 1, 100000); 
    $processos = $res['data'];
    
    $ports = array_column($processos, $ID_FIELD_KEY);
    $creditos = $db->findMany('Identificacao.json', $IDENT_ID_FIELD, $ports);
    $creditoMap = []; foreach ($creditos as $c) $creditoMap[$c[$IDENT_ID_FIELD]] = $c;
    
    $hProc = $config->getFields('Base_processos_schema'); 
    $hProc = array_filter($hProc, function($f) { return ($f['type'] ?? '') !== 'title'; });
    $hProcKeys = array_map(function($f){ return $f['key']; }, $hProc);
    if (!in_array('Ultima_Alteracao', $hProcKeys)) $hProcKeys[] = 'Ultima_Alteracao';

    $hCred = $db->getHeaders('Identificacao.json');
    
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");

    // Start XML Output
    echo "<?xml version=\"1.0\"?" . ">\n";
    echo "<?mso-application progid=\"Excel.Sheet\"?" . ">\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n";
    echo " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n";
    echo " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n";
    
    // Styles
    echo "<Styles>\n";
    echo " <Style ss:ID=\"Default\" ss:Name=\"Normal\">\n";
    echo "  <Alignment ss:Vertical=\"Bottom\"/>\n";
    echo "  <Borders/>\n";
    echo "  <Font ss:FontName=\"Calibri\" x:Family=\"Swiss\" ss:Size=\"11\" ss:Color=\"#000000\"/>\n";
    echo "  <Interior/>\n";
    echo "  <NumberFormat/>\n";
    echo "  <Protection/>\n";
    echo " </Style>\n";
    echo " <Style ss:ID=\"Header\">\n";
    echo "  <Font ss:FontName=\"Calibri\" x:Family=\"Swiss\" ss:Size=\"11\" ss:Color=\"#FFFFFF\" ss:Bold=\"1\"/>\n";
    echo "  <Interior ss:Color=\"#003366\" ss:Pattern=\"Solid\"/>\n";
    echo " </Style>\n";
    echo " <Style ss:ID=\"Text\">\n";
    echo "  <NumberFormat ss:Format=\"@\"/>\n";
    echo " </Style>\n";
    echo "</Styles>\n";

    // --- SHEET 1: PROCESSOS ---
    echo "<Worksheet ss:Name=\"Processos\">\n";
    echo " <Table>\n";
    
    // Header Row
    echo "  <Row>\n";
    foreach($hProc as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hCli as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hAg as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hCred as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    echo "  </Row>\n";
    
    $clean = function($str) {
        return htmlspecialchars(str_replace(["\r", "\n", "\t"], " ", $str ?? ''), ENT_XML1, 'UTF-8');
    };
    
    // Helper to determine type
    $getCell = function($h, $val) use ($clean, $ID_FIELD_KEY, $IDENT_ID_FIELD) {
         if (function_exists('format_field_value')) {
             $val = format_field_value($h, $val);
         }
         $val = $clean($val);
         // Force text format for specific number columns
         $textCols = [$ID_FIELD_KEY, $IDENT_ID_FIELD, 'PROPOSTA', 'CPF', 'AG', 'NUMERO_DEPOSITO', 'Certificado'];
         $style = "";
         $type = "String";
         if (in_array($h, $textCols)) {
             $style = "ss:StyleID=\"Text\"";
         }
         return "   <Cell $style><Data ss:Type=\"$type\">$val</Data></Cell>\n";
    };

    foreach ($processos as $proc) {
        $cpf = get_value_ci($proc, 'CPF');
        $port = get_value_ci($proc, $ID_FIELD_KEY);
        $ag = get_value_ci($proc, 'AG');

        $cliData = $clientMap[$cpf] ?? array_fill_keys($hCli, '');
        $agData = $agenciaMap[$ag] ?? array_fill_keys($hAg, '');
        $credData = $creditoMap[$port] ?? array_fill_keys($hCred, '');
        
        echo "  <Row>\n";
        foreach($hProc as $h) echo $getCell($h, get_value_ci($proc, $h));
        foreach($hCli as $h) echo $getCell($h, get_value_ci($cliData, $h));
        foreach($hAg as $h) echo $getCell($h, get_value_ci($agData, $h));
        foreach($hCred as $h) echo $getCell($h, get_value_ci($credData, $h));
        echo "  </Row>\n";
    }
    echo " </Table>\n";
    echo "</Worksheet>\n";

    // --- SHEET 2: HISTÓRICO DE ENVIOS ---
    echo "<Worksheet ss:Name=\"Histórico de Envios\">\n";
    echo " <Table>\n";
    
    // Headers
    $histHeaders = ['Data e Hora', $IDENT_LABEL, 'Usuário', 'Título', 'Tema'];
    echo "  <Row>\n";
    foreach($histHeaders as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    echo "  </Row>\n";

    // Filter History
    // Read Base_registros.json
    $histFile = 'dados/Base_registros.json';
    if (file_exists($histFile)) {
        $rows = json_decode(file_get_contents($histFile), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                // DATA, USUARIO, CLIENTE, CPF, PORTABILIDADE, MODELO, TEXTO, DESTINATARIOS
                $pPort = trim($row[$IDENT_ID_FIELD] ?? '');
                
                // Check if this history belongs to exported processes
                if (in_array($pPort, $ports)) {
                    $valData = $clean($row['DATA'] ?? '');
                    $valPort = $clean($row[$IDENT_ID_FIELD] ?? '');
                    $valUser = $clean($row['USUARIO'] ?? '');
                    $valMod  = $row['MODELO'] ?? ($row['TEXTO'] ?? '');
                    
                    $titulos = [];
                    $temas = [];
                    $parts = explode(';', $valMod);
                    foreach($parts as $part) {
                        $part = trim($part);
                        if(empty($part)) continue;
                         if(preg_match('/Lista:\s*(.*?)\s*-\s*Tema:\s*(.*)/i', $part, $m)) {
                            $titulos[] = trim($m[1]);
                            $temas[] = trim($m[2]);
                        } else {
                            $titulos[] = '-';
                            $temas[] = $part;
                        }
                    }
                    $valTitulo = $clean(implode('; ', array_unique($titulos)));
                    $valTema = $clean(implode('; ', $temas));

                    echo "  <Row>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valData</Data></Cell>\n";
                    echo "   <Cell ss:StyleID=\"Text\"><Data ss:Type=\"String\">$valPort</Data></Cell>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valUser</Data></Cell>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valTitulo</Data></Cell>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valTema</Data></Cell>\n";
                    echo "  </Row>\n";
                }
            }
        }
    }

    echo " </Table>\n";
    echo "</Worksheet>\n";

    // --- SHEET 3: REGISTROS DE PROCESSO ---
    echo "<Worksheet ss:Name=\"Registros de Processo\">\n";
    echo " <Table>\n";
    
    $regFields = $config->getFields('Base_registros_schema');
    $regFields = array_filter($regFields, function($f) { return ($f['type'] ?? '') !== 'title'; });
    echo "  <Row>\n";
    echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Data e Hora</Data></Cell>\n";
    echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">Usuário</Data></Cell>\n";
    echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">" . htmlspecialchars($IDENT_LABEL) . "</Data></Cell>\n";
    foreach($regFields as $f) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">" . htmlspecialchars($f['label']) . "</Data></Cell>\n";
    echo "  </Row>\n";

    $regFile = 'dados/Base_registros_dados.json';
    if (file_exists($regFile)) {
        $rows = json_decode(file_get_contents($regFile), true);
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $pPort = trim(get_value_ci($row, $IDENT_ID_FIELD));
                if (in_array($pPort, $ports)) {
                    $valData = $clean(get_value_ci($row, 'DATA'));
                    $valUser = $clean(get_value_ci($row, 'USUARIO'));
                    $valPort = $clean(get_value_ci($row, $IDENT_ID_FIELD));
                    
                    echo "  <Row>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valData</Data></Cell>\n";
                    echo "   <Cell><Data ss:Type=\"String\">$valUser</Data></Cell>\n";
                    echo "   <Cell ss:StyleID=\"Text\"><Data ss:Type=\"String\">$valPort</Data></Cell>\n";
                    
                    foreach($regFields as $f) {
                        $val = $clean(get_value_ci($row, $f['key']));
                        echo "   <Cell><Data ss:Type=\"String\">$val</Data></Cell>\n";
                    }
                    echo "  </Row>\n";
                }
            }
        }
    }
    echo " </Table>\n";
    echo "</Worksheet>\n";

    echo "</Workbook>";
    exit;
}

// EXPORT EXCEL LEMBRETES (NOVO)
if (isset($_GET['acao']) && $_GET['acao'] == 'exportar_lembretes_excel') {
    $filename = "Lembretes_SPA_" . date('d-m-Y_His') . ".xls";
    
    $fLembreteIni = $_GET['fLembreteIni'] ?? '';
    $fLembreteFim = $_GET['fLembreteFim'] ?? '';
    $fBuscaGlobal = $_GET['fBuscaGlobal'] ?? '';
    
    // 1. Identify Flagged Fields (to include context, though user asked for "Complete Export")
    // "Todos os dados vinculados ao processo; Todas as informações relacionadas ao lembrete"
    // So we basically do a full export of filtered processes + reminders context.
    
    // 2. Fetch Processes
    $years = $_SESSION['selected_years'] ?? [date('Y')];
    $months = $_SESSION['selected_months'] ?? [(int)date('n')];
    $targetFiles = $db->getProcessFiles($years, $months);
    
    // Main Filters
    $filters = [];
    
    // Date Logic for Data_Lembrete
    $checkLembrete = function($row) use ($fLembreteIni, $fLembreteFim) {
        if (!$fLembreteIni && !$fLembreteFim) return true;
        $val = get_value_ci($row, 'Data_Lembrete');
        if (!$val) return false;
        
        $dt = DateTime::createFromFormat('d/m/Y H:i:s', $val);
        if(!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
        if(!$dt) $dt = DateTime::createFromFormat('d/m/Y', $val);
        if(!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
        if(!$dt) return false;
        
        if ($fLembreteIni) {
            $di = DateTime::createFromFormat('Y-m-d\TH:i', $fLembreteIni);
            if (!$di) {
                $di = DateTime::createFromFormat('Y-m-d', $fLembreteIni);
                if ($di) $di->setTime(0,0,0);
            }
            if ($di && $dt < $di) return false;
        }
        if ($fLembreteFim) {
            $df = DateTime::createFromFormat('Y-m-d\TH:i', $fLembreteFim);
            if (!$df) {
                $df = DateTime::createFromFormat('Y-m-d', $fLembreteFim);
                if ($df) $df->setTime(23,59,59);
            }
            if ($df && $dt > $df) return false;
        }
        return true;
    };
    
    if ($fBuscaGlobal) {
         $filters['global'] = $fBuscaGlobal;
    }
    
    // Combine Date Check with Global
    $filters['callback'] = function($row) use ($checkLembrete) {
        if (!$checkLembrete($row)) return false;
        
        // Filter Empty Fields
        $ua = get_value_ci($row, 'Ultima_Alteracao');
        $dl = get_value_ci($row, 'Data_Lembrete');
        if (trim((string)$ua) === '' || trim((string)$dl) === '') return false;

        return true;
    };

    // Fetch All Matches
    $res = $db->select($targetFiles, $filters, 1, 100000); 
    $processos = $res['data'];
    
    // Prepare Data
    $cpfs = array_column($processos, 'CPF');
    $ports = array_column($processos, $ID_FIELD_KEY);
    $ags = array_column($processos, 'AG');

    $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
    $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c;
    
    $creditos = $db->findMany('Identificacao.json', $IDENT_ID_FIELD, $ports);
    $creditoMap = []; foreach ($creditos as $c) $creditoMap[$c[$IDENT_ID_FIELD]] = $c;

    $agencias = $db->findMany('Base_agencias.json', 'AG', $ags);
    $agenciaMap = []; foreach ($agencias as $c) $agenciaMap[$c['AG']] = $c;
    
    // Headers
    $hProc = $config->getFields('Base_processos_schema');
    $hProc = array_filter($hProc, function($f) { return ($f['type'] ?? '') !== 'title'; });
    $hProcKeys = array_map(function($f){ return $f['key']; }, $hProc);
    if (!in_array('Ultima_Alteracao', $hProcKeys)) array_unshift($hProcKeys, 'Ultima_Alteracao');
    if (!in_array('Data_Lembrete', $hProcKeys)) array_unshift($hProcKeys, 'Data_Lembrete');

    $hCli = $db->getHeaders('Base_clientes.json');
    $hAg = $db->getHeaders('Base_agencias.json');
    $hCred = $db->getHeaders('Identificacao.json');
    
    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");

    // Start XML Output
    echo "<?xml version=\"1.0\"?" . ">\n";
    echo "<?mso-application progid=\"Excel.Sheet\"?" . ">\n";
    echo "<Workbook xmlns=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:o=\"urn:schemas-microsoft-com:office:office\"\n";
    echo " xmlns:x=\"urn:schemas-microsoft-com:office:excel\"\n";
    echo " xmlns:ss=\"urn:schemas-microsoft-com:office:spreadsheet\"\n";
    echo " xmlns:html=\"http://www.w3.org/TR/REC-html40\">\n";
    
    echo "<Styles>\n";
    echo " <Style ss:ID=\"Header\">\n";
    echo "  <Font ss:FontName=\"Calibri\" x:Family=\"Swiss\" ss:Size=\"11\" ss:Color=\"#FFFFFF\" ss:Bold=\"1\"/>\n";
    echo "  <Interior ss:Color=\"#003366\" ss:Pattern=\"Solid\"/>\n";
    echo " </Style>\n";
    echo " <Style ss:ID=\"Text\">\n";
    echo "  <NumberFormat ss:Format=\"@\"/>\n";
    echo " </Style>\n";
    echo "</Styles>\n";

    echo "<Worksheet ss:Name=\"Lembretes\">\n";
    echo " <Table>\n";
    
    // Header Row
    echo "  <Row>\n";
    foreach($hProcKeys as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hCli as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hAg as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    foreach($hCred as $h) echo "   <Cell ss:StyleID=\"Header\"><Data ss:Type=\"String\">$h</Data></Cell>\n";
    echo "  </Row>\n";
    
    $clean = function($str) {
        return htmlspecialchars(str_replace(["\r", "\n", "\t"], " ", $str ?? ''), ENT_XML1, 'UTF-8');
    };
    
        $getCell = function($h, $val) use ($clean, $ID_FIELD_KEY, $IDENT_ID_FIELD) {
            if (function_exists('format_field_value')) {
               $val = format_field_value($h, $val);
            }
            $val = $clean($val);
            $textCols = [$ID_FIELD_KEY, $IDENT_ID_FIELD, 'PROPOSTA', 'CPF', 'AG', 'NUMERO_DEPOSITO', 'Certificado'];
            $style = in_array($h, $textCols) ? "ss:StyleID=\"Text\"" : "";
            return "   <Cell $style><Data ss:Type=\"String\">$val</Data></Cell>\n";
        };

    foreach ($processos as $proc) {
        $cpf = get_value_ci($proc, 'CPF');
        $port = get_value_ci($proc, $ID_FIELD_KEY);
        $ag = get_value_ci($proc, 'AG');

        $cliData = $clientMap[$cpf] ?? array_fill_keys($hCli, '');
        $agData = $agenciaMap[$ag] ?? array_fill_keys($hAg, '');
        $credData = $creditoMap[$port] ?? array_fill_keys($hCred, '');
        
        echo "  <Row>\n";
        foreach($hProcKeys as $h) echo $getCell($h, get_value_ci($proc, $h));
        foreach($hCli as $h) echo $getCell($h, get_value_ci($cliData, $h));
        foreach($hAg as $h) echo $getCell($h, get_value_ci($agData, $h));
        foreach($hCred as $h) echo $getCell($h, get_value_ci($credData, $h));
        echo "  </Row>\n";
    }
    echo " </Table>\n";
    echo "</Worksheet>\n";
    echo "</Workbook>";
    exit;
}

// POST PROCESSING
$mensagem = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';


    if ($acao == 'salvar_processo') { // Fallback legacy
        // ... (Keep existing logic just in case, but duplicated logic is bad. For now, let's just make submitProcessForm change the action to ajax_salvar_processo)
        // Actually, let's keep it dry.
    }

    if ($acao == 'limpar_base' || $acao == 'limpar_base_identificacao') {
        $base = $_POST['base'] ?? 'Identificacao.json';
        if ($db->truncate($base)) {
            $mensagem = "Base ($base) limpa com sucesso!";
        } else {
            $mensagem = "Erro: Não foi possível limpar a base.";
        }
    }
    
    if ($acao == 'excluir_processo') {
        $port = $_POST['id_exclusao'];
        if ($port) {
            $file = $indexer->get($port);
            if ($file) {
                $db->delete($file, $ID_FIELD_KEY, $port);
                $indexer->delete($port);
                $mensagem = "Processo excluído com sucesso.";
            } else {
                // Fallback (e.g. legacy check)
                $years = $_SESSION['selected_years'] ?? [date('Y')];
                $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                $files = $db->getProcessFiles($years, $months);
                foreach ($files as $f) {
                    if ($db->delete($f, $ID_FIELD_KEY, $port)) {
                         // Found by brute force
                         break;
                    }
                }
                $mensagem = "Processo excluído (se encontrado).";
            }
        }
    }

    if ($acao == 'confirm_upload') {
        if (isset($_SESSION['upload_preview']) && !empty($_SESSION['upload_preview'])) {
            $data = $_SESSION['upload_preview'];
            $base = $_SESSION['upload_preview_base'] ?? 'Identificacao.json';
            
            // Clear session to free memory and state
            unset($_SESSION['upload_preview']);
            unset($_SESSION['upload_preview_base']);
            
            // Release session lock to prevent blocking other requests (heartbeats)
            session_write_close();
            
            $cleanData = [];
            foreach($data as $row) {
                if (isset($row['DATA_ERROR'])) continue;
                unset($row['DATA_ERROR']);
                $cleanData[] = $row;
            }
            
            try {
                $res = $db->importExcelData($base, $cleanData);
                if ($res) {
                    $mensagem = "Base ($base) atualizada com sucesso! (Inseridos: {$res['inserted']}, Atualizados: {$res['updated']})";
                } else {
                    $mensagem = "Erro: Falha ao atualizar a base de dados.";
                }
            } catch (Exception $e) {
                $mensagem = "Erro Crítico: " . $e->getMessage();
            }
        } else {
            $mensagem = "Erro: Sessão de upload expirada.";
        }
    }

    if ($acao == 'cancel_upload') {
        unset($_SESSION['upload_preview']);
        $mensagem = "Upload cancelado.";
    }
    
    if ($acao == 'paste_data') {
        $text = $_POST['paste_content'] ?? '';
        $base = $_POST['base'] ?? 'Identificacao.json';

        if ($text) {
            // Ensure UTF-8
            if (!mb_check_encoding($text, 'UTF-8')) {
                $text = mb_convert_encoding($text, 'UTF-8', 'auto');
            }

            $rows = [];
            $lines = explode("\n", $text);
            $delimiter = "\t"; // Default
            
            // Check first line for delimiter
            if (!empty($lines[0])) {
                if (strpos($lines[0], "\t") !== false) $delimiter = "\t";
                elseif (strpos($lines[0], ";") !== false) $delimiter = ";";
                elseif (strpos($lines[0], ",") !== false) $delimiter = ",";
            }
            
            $confFields = $config->getFields($base);
            $headers = [];
            foreach($confFields as $f) {
                if(isset($f['deleted']) && $f['deleted']) continue;
                if(isset($f['type']) && $f['type'] === 'title') continue;
                $headers[] = $f['key'];
            }
            
            if (empty($headers)) {
                if (stripos($base, 'client') !== false) {
                    $headers = ['Nome', 'CPF'];
                } elseif (stripos($base, 'agenc') !== false) {
                    $headers = ['AG', 'UF', 'SR', 'Nome SR', 'Filial', 'E-mail AG', 'E-mails SR', 'E-mails Filial', 'E-mail Gerente'];
                } else {
                    $headers = ['Status', 'Número Depósito', 'Data Depósito', 'Valor Depósito Principal', 'Texto Pagamento', $IDENT_LABEL, 'Certificado', 'Status 2', 'CPF', 'AG'];
                }
            }

            $isHeader = true;
            
            $headerMap = [];
            
            // Prepare Normalized Matches
            $normConf = [];
            $accents = ['À','Á','Â','Ã','Ä','Å','Ç','È','É','Ê','Ë','Ì','Í','Î','Ï','Ò','Ó','Ô','Õ','Ö','Ù','Ú','Û','Ü','Ý'];
            $noAccents = ['A','A','A','A','A','A','C','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','Y'];
            
            foreach ($confFields as $f) {
                if (isset($f['type']) && $f['type'] === 'title') continue;
                $key = $f['key'];
                $lbl = isset($f['label']) ? $f['label'] : $key;
                
                // Keys normalization
                $nKey = mb_strtoupper($key, 'UTF-8');
                $nKey = str_replace($accents, $noAccents, $nKey);
                $nKey = preg_replace('/[^A-Z0-9]/', '', $nKey);
                
                // Labels normalization
                $nLbl = mb_strtoupper($lbl, 'UTF-8');
                $nLbl = str_replace($accents, $noAccents, $nLbl);
                $nLbl = preg_replace('/[^A-Z0-9]/', '', $nLbl);
                
                if($nKey) $normConf[$nKey] = $key;
                if($nLbl) $normConf[$nLbl] = $key;
            }
            // Also normalize basic headers fallback
            $normHeaders = [];
            foreach($headers as $h) {
                $nH = mb_strtoupper($h, 'UTF-8');
                $nH = str_replace($accents, $noAccents, $nH);
                $nH = preg_replace('/[^A-Z0-9]/', '', $nH);
                if($nH) $normHeaders[$nH] = $h;
            }

            foreach ($lines as $line) {
                if (!trim($line)) continue;
                $cols = str_getcsv($line, $delimiter);
                $cols = array_map('trim', $cols);

                // Header detection (Smart & Normalized)
                if ($isHeader) {
                    $isHeader = false;
                    
                    $matches = 0;
                    $tempMap = [];
                    foreach ($cols as $idx => $colVal) {
                        $nVal = mb_strtoupper($colVal, 'UTF-8');
                        $nVal = str_replace($accents, $noAccents, $nVal);
                        $nVal = preg_replace('/[^A-Z0-9]/', '', $nVal);
                        
                        $matchedKey = null;
                        if (isset($normConf[$nVal])) {
                            $matchedKey = $normConf[$nVal];
                        } elseif (isset($normHeaders[$nVal])) {
                            $matchedKey = $normHeaders[$nVal];
                        }
                        
                        if ($matchedKey) {
                            $matches++;
                            $tempMap[$idx] = $matchedKey; 
                        }
                    }
                    
                    // If at least one column matches clearly, assume header
                    // User requested robustness, so 1 match is enough to attempt mapping.
                    if ($matches > 0) {
                        $headerMap = $tempMap;
                        continue; // Skip header line
                    }
                }
                
                $rows[] = $cols;
            }
            
            // Mapping Logic
            $mappedData = [];
            foreach ($rows as $cols) {
                $newRow = [];
                
                if (!empty($headerMap)) {
                    // Map by detected headers
                    foreach ($headers as $h) {
                        // Find index for this header
                        $foundIdx = array_search($h, $headerMap);
                        if ($foundIdx !== false && isset($cols[$foundIdx])) {
                            $newRow[$h] = $cols[$foundIdx];
                        } else {
                            $newRow[$h] = '';
                        }
                    }
                } else {
                    // Map by position (Legacy)
                    foreach($headers as $i => $h) {
                        $newRow[$h] = isset($cols[$i]) ? $cols[$i] : '';
                    }
                }
                
                // Validation / Key Check
                if (stripos($base, 'client') !== false) {
                    if (empty($newRow['CPF'])) continue;
                } elseif (stripos($base, 'agenc') !== false) {
                    if (empty($newRow['AG'])) continue;
                } else {
                    // For Processos and Identificacao, allow empty keys (generate temp ID)
                    // This satisfies "aceitar as informações... independentemente da existência ou não de todos os campos"
                    // and aligns identifying behavior.
                    $k = (stripos($base, 'Processos') !== false || stripos($base, 'Base_processos') !== false) ? $ID_FIELD_KEY : $IDENT_ID_FIELD;
                    if (empty($newRow[$k])) {
                         $newRow[$k] = uniqid('import_');
                    }
                }
                
                $mappedData[] = $newRow;
            }
            
            // Validation & Auto-Formatting
            $validatedData = [];
            foreach ($mappedData as $row) {
                // Apply Auto-Formatting based on Configuration checks
                foreach ($confFields as $f) {
                     $key = $f['key'];
                     if (isset($row[$key])) {
                         $val = $row[$key];
                         // Basic cleaning/formatting logic (mirrors format_field_value but scoped to current base)
                         // 1. Case
                         $case = $f['custom_case'] ?? ($f['case'] ?? '');
                         if ($case === 'upper') $val = mb_strtoupper($val, 'UTF-8');
                         elseif ($case === 'lower') $val = mb_strtolower($val, 'UTF-8');

                         // 2. Allowed Chars
                         $allowed = $f['custom_allowed'] ?? '';
                         $stripped = $val;
                         if ($allowed === 'numbers') $stripped = preg_replace('/[^0-9]/', '', $val);
                         elseif ($allowed === 'letters') $stripped = preg_replace('/[^a-zA-Z]/', '', $val);
                         elseif ($allowed === 'alphanumeric') $stripped = preg_replace('/[^a-zA-Z0-9]/', '', $val);
                         
                         $mask = $f['custom_mask'] ?? ($f['mask'] ?? '');
                         $type = $f['type'] ?? '';

                         if (!empty($mask)) {
                             // Apply Mask
                             $output = "";
                             $rawIdx = 0;
                             $strippedLen = mb_strlen($stripped, 'UTF-8');
                             $maskLen = mb_strlen($mask, 'UTF-8');

                             for ($i = 0; $i < $maskLen; $i++) {
                                 $m = mb_substr($mask, $i, 1, 'UTF-8');
                                 if ($m === '0' || $m === 'A' || $m === '*') {
                                     while ($rawIdx < $strippedLen) {
                                         $c = mb_substr($stripped, $rawIdx++, 1, 'UTF-8');
                                         if ($m === '0' && preg_match('/[0-9]/', $c)) { $output .= $c; break; }
                                         if ($m === 'A' && preg_match('/[a-zA-Z]/', $c)) { $output .= $c; break; }
                                         if ($m === '*') { $output .= $c; break; }
                                     }
                                 } else {
                                     $output .= $m;
                                     if ($rawIdx < $strippedLen && mb_substr($stripped, $rawIdx, 1, 'UTF-8') === $m) {
                                         $rawIdx++;
                                     }
                                 }
                             }
                             $row[$key] = $output; // Update row
                         } elseif ($type === 'money') {
                             // Money formatting
                             $cleanNum = preg_replace('/[^\d\.,\-]/', '', $val);
                             if ($cleanNum !== '') {
                                 $lastComma = strrpos($cleanNum, ',');
                                 $lastDot = strrpos($cleanNum, '.');
                                 $decSep = null;
                                 if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) $decSep = ',';
                                 elseif ($lastDot !== false) $decSep = '.';

                                 if ($decSep) {
                                     $parts = explode($decSep, $cleanNum);
                                     $intPart = preg_replace('/\D+/', '', $parts[0]);
                                     $decPart = preg_replace('/\D+/', '', $parts[1] ?? '');
                                     $float = (float) ($intPart . '.' . ($decPart !== '' ? $decPart : '0'));
                                 } else {
                                     $float = (float) preg_replace('/\D+/', '', $cleanNum);
                                 }
                                 $row[$key] = 'R$ ' . number_format($float, 2, ',', '.');
                             }
                         }
                     }
                }

                // Dynamic Date Validation based on Config
                foreach ($confFields as $f) {
                     if (isset($f['type']) && $f['type'] == 'date' && isset($row[$f['key']])) {
                         $val = $row[$f['key']];
                         if ($val !== '') {
                             $validDate = normalizeDate($val);
                             if ($validDate === false) {
                                 $row['DATA_ERROR'] = true;
                             } else {
                                 $row[$f['key']] = $validDate;
                             }
                         }
                     }
                     if (isset($f['type']) && ($f['type'] == 'datetime' || $f['type'] == 'datetime-local') && isset($row[$f['key']])) {
                         $val = trim($row[$f['key']]);
                         if ($val !== '') {
                             if (is_numeric($val)) {
                                 // Excel numeric date/time
                                 $unix = ($val - 25569) * 86400;
                                 $row[$f['key']] = gmdate('d/m/Y H:i', $unix);
                             } else {
                                 // String Parsing
                                 $dt = DateTime::createFromFormat('d/m/Y H:i', $val);
                                 if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i', $val);
                                 if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i:s', $val);
                                 if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $val);
                                 
                                 if ($dt) {
                                     $row[$f['key']] = $dt->format('d/m/Y H:i');
                                 } else {
                                     $validDate = normalizeDate($val);
                                     if ($validDate !== false && $validDate !== '') {
                                         $row[$f['key']] = $validDate . ' 00:00';
                                     } else {
                                         $row['DATA_ERROR'] = true;
                                     }
                                 }
                             }
                         }
                     }
                }

                // Fallback for core date fields
                $coreDates = ['DATA_DEPOSITO', 'DATA'];
                foreach($coreDates as $cd) {
                    // Skip if configured as datetime
                    $isDatetime = false;
                    foreach($confFields as $cf) {
                        if (isset($cf['key']) && $cf['key'] == $cd && isset($cf['type']) && ($cf['type'] == 'datetime' || $cf['type'] == 'datetime-local')) {
                            $isDatetime = true; 
                            break;
                        }
                    }
                    if ($isDatetime) continue;

                    if (isset($row[$cd])) {
                         $val = $row[$cd];
                         if ($val !== '') {
                             $validDate = normalizeDate($val);
                             if ($validDate === false) {
                                 $row['DATA_ERROR'] = true;
                             } else {
                                 $row[$cd] = $validDate;
                             }
                         }
                    }
                }

                $validatedData[] = $row;
            }
            
            if (empty($validatedData)) {
                $mensagem = "Erro: Nenhum dado válido identificado no texto colado.";
            } else {
                $_SESSION['upload_preview'] = $validatedData;
                $_SESSION['upload_preview_base'] = $base;
                $showPreview = true;
            }
        }
    }

    if ($acao == 'salvar_campo') {
        $file = $_POST['arquivo_base'];
        $oldKey = $_POST['old_key'] ?? '';
        $required = isset($_POST['required']) ? true : false;
        
        $key = trim($_POST['key']);
        $fieldData = ['key' => $key, 'label' => $_POST['label'], 'type' => $_POST['type'], 'options' => $_POST['options'] ?? '', 'required' => $required];
        
        if ($oldKey) {
            $config->updateField($file, $oldKey, $fieldData);
            $mensagem = "Campo atualizado com sucesso!";
        } else {
            // Check for duplicates
            $existing = $config->getFields($file);
            $exists = false;
            $deletedKey = null;

            foreach ($existing as $f) {
                if (mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) {
                    $exists = true; 
                    if (isset($f['deleted']) && $f['deleted']) {
                         $deletedKey = $f['key'];
                    }
                    break;
                }
            }
            
            if ($exists) {
                if ($deletedKey) {
                    $config->updateField($file, $deletedKey, $fieldData);
                    $config->reactivateField($file, $deletedKey);
                    $mensagem = "Campo restaurado e atualizado com sucesso!";
                } else {
                    $mensagem = "Erro: O campo '$key' já existe!";
                }
            } else {
                $config->addField($file, $fieldData);
                $mensagem = "Campo adicionado com sucesso!";
            }
        }
    }

    if ($acao == 'remover_campo') {
        $file = $_POST['arquivo_base'];
        $key = $_POST['key'];
        $config->removeField($file, $key);
        $mensagem = "Campo removido com sucesso!";
    }

    if ($acao == 'salvar_template') {
        $templates->save($_POST['id_template'] ?? '', $_POST['titulo'], $_POST['corpo']);
        $mensagem = "Modelo salvo com sucesso!";
    }

    if ($acao == 'excluir_template') {
        $templates->delete($_POST['id_exclusao']);
        $mensagem = "Modelo excluído!";
    }
}

$page = $_GET['p'] ?? 'dashboard';

$getVal = function($arr, $key) {
    if (!is_array($arr)) return '';
    if (isset($arr[$key])) return $arr[$key];
    foreach ($arr as $k => $v) {
        if (mb_strtoupper($k, 'UTF-8') === mb_strtoupper($key, 'UTF-8')) return $v;
    }
    return '';
};
?>



<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($SYSTEM_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Sortable/1.14.0/Sortable.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.7.32/dist/sweetalert2.all.min.js"></script>
    <style>
        :root { --laranja: #FF8C00; --navy: #003366; }
        body { background: #f4f6f9; font-family: Arial, sans-serif !important; }
        .navbar-custom { background: var(--navy); }
        .navbar-brand { font-weight: bold; color: white !important; }
        .nav-link { color: rgba(255,255,255,0.8) !important; }
        .nav-link.active { color: var(--laranja) !important; font-weight: bold; }
        .card-custom { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        
        /* Buttons Enhanced */
        .btn {
            padding: 8px 16px !important;
            font-size: 14px !important;
            border-radius: 6px !important;
            min-height: 38px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-sm {
            padding: 4px 10px !important;
            font-size: 12px !important;
            border-radius: 4px !important;
            min-height: 28px !important;
            line-height: 1.5 !important;
        }

        .btn-navy { 
            background-color: var(--navy); 
            color: white; 
        }
        .btn-navy:hover { background-color: #002244; color: white; transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.2); }
        
        .table-custom thead { background-color: var(--navy); color: white; }
        .status-badge { padding: 5px 10px; border-radius: 12px; font-size: 0.75em; font-weight: bold; }
        .dashboard-text { font-size: 0.85rem; }
        .money-bag { color: var(--laranja); animation: blink 1.5s infinite; }
        #loadingModal { z-index: 10000; }
        
        /* Modal Styling */
        .modal-content {
            border-radius: 15px;
            border: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .modal-header {
            background-color: var(--navy);
            color: white;
            border-radius: 15px 15px 0 0;
        }
        .modal-header .btn-close {
            filter: invert(1) grayscale(100%) brightness(200%);
        }

        /* Custom Field Styling */
        .form-label-custom {
            font-weight: 600;
            color: var(--navy);
            font-size: 0.9rem;
            margin-bottom: 0.3rem;
        }
        .form-control-custom, .form-select-custom {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0.5rem 0.75rem;
            font-size: 0.95rem;
            box-shadow: none; 
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }
        .form-control-custom:focus, .form-select-custom:focus {
            border-color: var(--navy);
            box-shadow: 0 0 0 0.25rem rgba(0, 51, 102, 0.25);
        }
        .form-control-custom:disabled, .form-select-custom:disabled {
            background-color: #e9ecef;
            opacity: 1;
        }
        
        /* Validation Feedback */
        .form-control-custom.is-invalid, .form-select-custom.is-invalid {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.25rem rgba(220, 53, 69, 0.25) !important;
        }

        /* ESTILOS APRIMORADOS PARA ABAS */
        .nav-tabs { border-bottom: 2px solid var(--navy); }
        .nav-tabs .nav-link { 
            color: white !important; 
            background-color: #6c757d; 
            margin-right: 4px; 
            border: 1px solid #6c757d;
            border-bottom: none;
            font-weight: 600;
            opacity: 0.8;
        }
        .nav-tabs .nav-link.active { 
            background-color: var(--laranja) !important; 
            color: white !important; 
            border-color: var(--laranja); 
            font-weight: bold; 
            box-shadow: 0 -2px 5px rgba(0,0,0,0.1);
            opacity: 1;
        }
        .nav-tabs .nav-link:hover { 
            background-color: #5a6268; 
            color: white !important;
            opacity: 1;
        }
        .nav-link { color: rgba(255,255,255,0.8); } /* Navbar links */
        
        /* Base Navigation Pills */
        #base-tab .nav-link {
            background-color: #e9ecef;
            color: var(--navy) !important;
            border: 1px solid #dee2e6;
            margin: 0; /* Reset for gap */
            opacity: 1;
        }
        #base-tab .nav-link.active {
            background-color: var(--navy) !important;
            color: white !important;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        #base-tab .nav-link:hover {
            background-color: #dee2e6;
            color: var(--navy) !important;
        }
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: #dc3545 !important;
        }
        .btn.border-danger {
            border-color: #dc3545 !important;
        }
    </style>
</head>
<body>

<div class="modal fade" id="loadingModal" data-bs-backdrop="static" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content text-center p-4">
            <div class="spinner-border text-primary mx-auto mb-3"></div>
            <h5>Aguarde... Processando</h5>
        </div>
    </div>
</div>

<div class="modal fade" id="modalPaste" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" onsubmit="processPaste(event)">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title"><i class="fas fa-paste me-2"></i>Colar Dados da Planilha</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="acao" value="paste_data">
                <input type="hidden" name="base" id="paste_base_target">
                <div class="alert alert-secondary small">
                    Cole aqui as linhas copiadas diretamente do Excel. O sistema detectará automaticamente se há cabeçalho.
                    <br><strong>Ordem esperada:</strong> Status, Número, Data, Valor, Texto, <?= htmlspecialchars($IDENT_LABEL) ?>, Certificado, Status 2, CPF, AG.
                </div>
                <textarea name="paste_content" class="form-control" rows="15" placeholder="Cole os dados aqui..." required></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Fechar</button>
                <button class="btn btn-info text-white">Processar Dados</button>
            </div>
        </form>
    </div>
</div>

<nav class="navbar navbar-expand-lg navbar-custom mb-4 sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="?p=dashboard" onclick="showPage('dashboard'); return false;"><i class="fas fa-cubes me-2"></i><?= htmlspecialchars($SYSTEM_NAME) ?></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav"><span class="navbar-toggler-icon"></span></button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link <?= $page=='dashboard'?'active':'' ?>" id="nav-link-dashboard" href="?p=dashboard" onclick="showPage('dashboard'); return false;">Processos</a></li>
                <li class="nav-item"><a class="nav-link <?= $page=='detalhes'?'active':'' ?>" href="?p=detalhes" onclick="showPage('detalhes'); return false;">Serviços</a></li>
                <li class="nav-item"><a class="nav-link <?= $page=='lembretes'?'active':'' ?>" href="?p=lembretes" onclick="showPage('lembretes'); return false;">Lembretes</a></li>
                <li class="nav-item"><a class="nav-link <?= in_array($page, ['base', 'config', 'config_hub']) ? 'active' : '' ?>" href="#" onclick="requestConfigAccess(); return false;" id="nav-link-config">Configurações</a></li>
                <li class="nav-item"><a class="nav-link" href="#" onclick="refreshCurrentView(); return false;"><i class="fas fa-sync-alt me-1"></i> Atualizar</a></li>
                <li class="nav-item d-flex align-items-center ms-2">
                    <div class="dropdown" onclick="event.stopPropagation()">
                        <button class="btn btn-sm btn-light dropdown-toggle" type="button" id="dropdownBaseFilter" data-bs-toggle="dropdown" aria-expanded="false" style="color: #003366; font-weight: bold;">
                            <i class="fas fa-database me-1"></i> Filtrar Base
                        </button>
                        <div class="dropdown-menu p-3" aria-labelledby="dropdownBaseFilter" style="min-width: 320px;" onclick="event.stopPropagation()">
                            <div class="row">
                                <div class="col-6 border-end">
                                    <h6 class="dropdown-header text-navy ps-0">Anos</h6>
                                    <div style="max-height: 200px; overflow-y: auto;">
                                        <?php foreach($availableYears as $y): ?>
                                        <div class="form-check">
                                            <input class="form-check-input chk-year" type="checkbox" value="<?= $y ?>" id="year_<?= $y ?>" <?= in_array($y, $selYears)?'checked':'' ?>>
                                            <label class="form-check-label small" for="year_<?= $y ?>"><?= $y ?></label>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <h6 class="dropdown-header text-navy ps-0">Meses</h6>
                                    <div style="max-height: 200px; overflow-y: auto;">
                                        <?php for($i=1; $i<=12; $i++): $mName = $db->getPortugueseMonth($i); ?>
                                        <div class="form-check">
                                            <input class="form-check-input chk-month" type="checkbox" value="<?= $i ?>" id="month_<?= $i ?>" <?= in_array($i, $selMonths)?'checked':'' ?>>
                                            <label class="form-check-label small" for="month_<?= $i ?>"><?= $mName ?></label>
                                        </div>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-3 pt-2 border-top">
                                <button class="btn btn-navy w-100 btn-sm" onclick="applyBaseSelection()">Aplicar Filtro</button>
                            </div>
                        </div>
                    </div>
                </li>
            </ul>
            <div class="d-flex align-items-center text-white">
                <i class="fas fa-user-circle me-2"></i> <?= htmlspecialchars($_SESSION['nome_completo']) ?>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    <?php if($mensagem): ?>
        <?php $alertType = (strpos($mensagem, 'Erro:') === 0) ? 'alert-danger' : 'alert-success'; ?>
        <div class="alert <?= $alertType ?> alert-dismissible fade show"><?= $mensagem ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div id="page-dashboard" class="page-section" style="<?= $page=='dashboard'?'':'display:none' ?>">
        <?php
        // LOGICA DE FILTRO RESTAURADA COMPLETA
        $fAtendente = $_GET['fAtendente'] ?? '';
        $fStatus = $_GET['fStatus'] ?? '';
        $fDataIni = $_GET['fDataIni'] ?? '';
        $fDataFim = $_GET['fDataFim'] ?? '';

        // Buscando valores únicos para os filtros
        $years = $_SESSION['selected_years'] ?? [date('Y')];
        $months = $_SESSION['selected_months'] ?? [(int)date('n')];
        $dashFiles = $db->getProcessFiles($years, $months);

        $uniqueAtendentes = [];
        $uniqueStatus = [];
        
        $dashProcFields = $config->getFields('Base_processos_schema');
        foreach($dashProcFields as $f) {
             if ($f['key'] === 'STATUS' && !empty($f['options'])) {
                 $uniqueStatus = array_map('trim', explode(',', $f['options']));
                 break;
             }
        }
        
        foreach($dashFiles as $df) {
             $ua = $db->getUniqueValues($df, 'Nome_atendente');
             $uniqueAtendentes = array_merge($uniqueAtendentes, $ua);
        }
        $uniqueAtendentes = array_unique($uniqueAtendentes);
        sort($uniqueAtendentes);

        $fMes = $_GET['fMes'] ?? '';
        $fAno = $_GET['fAno'] ?? '';
        $fBusca = $_GET['fBusca'] ?? '';
        $pPagina = $_GET['pag'] ?? 1;
        
        $filters = [];
        // Fixed Filters
        if ($fStatus) $filters['STATUS'] = $fStatus;
        
        $procFields = $config->getFields('Base_processos_schema');
        $visFilters = [];
        $fieldMap = [];
        $fieldConfigMap = [];
        foreach($procFields as $f) {
             $show = (isset($f['show_dashboard_filter']) && $f['show_dashboard_filter']) || (!isset($f['show_dashboard_filter']) && isset($f['show_filter']) && $f['show_filter']);
             if($show && !($f['deleted']??false)) {
                 $visFilters[] = $f['key'];
                 $fieldMap[$f['key']] = $f['type'] ?? 'text';
                 $fieldConfigMap[$f['key']] = $f;
             }
        }
        
        $dynamicCheck = function($row) use ($visFilters, $fieldMap, $fieldConfigMap) {
            foreach($visFilters as $vk) {
                // Skip if fixed
                if ($vk === 'STATUS') continue; 
                
                $valReq = $_GET['f_' . $vk] ?? '';
                if ($valReq !== '') {
                    $rowVal = get_value_ci($row, $vk);
                    $fType = $fieldMap[$vk] ?? 'text';
                    $fConfig = $fieldConfigMap[$vk] ?? [];
                    
                    // Check for Mask / Custom Type: Normalize for flexible search
                    $mask = $fConfig['custom_mask'] ?? ($fConfig['mask'] ?? '');
                    
                    // HEURISTIC: If input contains separators (., -, /) or explicit mask config, try normalized match
                    $hasSeparators = preg_match('/[\.\-\/]/', $valReq);
                    
                    if (!empty($mask) || $fType === 'custom' || $hasSeparators) {
                         // Normalize both to alphanumeric
                         $vNorm = preg_replace('/[^a-zA-Z0-9]/', '', $valReq);
                         $rNorm = preg_replace('/[^a-zA-Z0-9]/', '', $rowVal ?? '');
                         
                         if ($vNorm !== '') {
                             if (mb_stripos($rNorm, $vNorm) === false) return false;
                             continue;
                         }
                    }

                    // Strict/Normal Fallback
                    if (in_array($fType, ['text', 'textarea', 'custom'])) {
                        // TRY NORMALIZED MATCH ANYWAY if standard fails?
                        // If standard match FAILS, let's try normalized as a fallback for ANY text field, just in case.
                        if (mb_stripos($rowVal, $valReq) === false) {
                            $vNorm = preg_replace('/[^a-zA-Z0-9]/', '', $valReq);
                            $rNorm = preg_replace('/[^a-zA-Z0-9]/', '', $rowVal ?? '');
                            if ($vNorm !== '' && mb_stripos($rNorm, $vNorm) !== false) {
                                continue;
                            }
                            return false;
                        }
                    } else {
                        // Exact Match
                        if (mb_strtoupper(trim((string)$rowVal)) !== mb_strtoupper(trim((string)$valReq))) return false;
                    }
                }
            }
            return true;
        };

        // Custom attendant filter logic
        $checkAttendant = function($row) use ($fAtendente) {
            if (!$fAtendente) return true;
            $val = $row['Nome_atendente'] ?? '';
            return normalizeName($val) === normalizeName($fAtendente);
        };
        
        // Helper Data
        $checkDate = function($row) use ($fDataIni, $fDataFim, $fMes, $fAno, $checkAttendant, $dynamicCheck) {
            if (!$checkAttendant($row)) return false;
            
            // Apply Dynamic Filters
            if (!$dynamicCheck($row)) return false;
            
            if (!$fDataIni && !$fDataFim && !$fMes && !$fAno) return true;
            $d = $row['DATA'] ?? ''; 
            if (!$d) return false;
            // Parse stored date (may include time)
            $dt = DateTime::createFromFormat('d/m/Y H:i:s', $d);
            if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $d);
            if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $d);
            if (!$dt) return false;
            
            if ($fDataIni) {
                $di = DateTime::createFromFormat('Y-m-d\TH:i', $fDataIni);
                if (!$di) $di = DateTime::createFromFormat('!Y-m-d', $fDataIni);
                if ($di && $dt < $di) return false;
            }
            if ($fDataFim) {
                $df = DateTime::createFromFormat('Y-m-d\TH:i', $fDataFim);
                if (!$df) {
                    $df = DateTime::createFromFormat('!Y-m-d', $fDataFim);
                    if ($df) $df->setTime(23, 59, 59);
                }
                if ($df && $dt > $df) return false;
            }
            // Year/Month check (legacy or implicit)
            if ($fMes && $fAno) {
                if ($dt->format('m') != $fMes || $dt->format('Y') != $fAno) return false;
            }
            return true;
        };

        if ($fBusca) {
             $foundCpfs = []; $foundPorts = []; $foundAgs = [];
             if (!preg_match('/^\d+$/', $fBusca)) {
                 $resCli = $db->select('Base_clientes.json', ['global' => $fBusca], 1, 1000); 
                 $foundCpfs = array_column($resCli['data'], 'CPF');
             }
             $resCred = $db->select('Identificacao.json', ['global' => $fBusca], 1, 1000);
             $foundPorts = array_column($resCred['data'], $IDENT_ID_FIELD);
             $resAg = $db->select('Base_agencias.json', ['global' => $fBusca], 1, 1000);
             $foundAgs = array_column($resAg['data'], 'AG');

             $filters['callback'] = function($row) use ($fBusca, $foundCpfs, $foundPorts, $foundAgs, $checkDate) {
                  // CheckDate handles Attendant + Dynamic + Dates
                  if (!$checkDate($row)) return false; 
                  
                  foreach ($row as $val) {
                   if (stripos((string)$val, $fBusca) !== false) return true;
                   $vNorm = preg_replace('/[^a-zA-Z0-9]/', '', $fBusca);
                   $rNorm = preg_replace('/[^a-zA-Z0-9]/', '', (string)$val);
                   if ($vNorm !== '' && stripos($rNorm, $vNorm) !== false) return true;
               }
                  if (!empty($foundCpfs) && isset($row['CPF']) && in_array($row['CPF'], $foundCpfs)) return true;
                  if (!empty($foundPorts) && isset($row[$ID_FIELD_KEY]) && in_array($row[$ID_FIELD_KEY], $foundPorts)) return true;
                  if (!empty($foundAgs) && isset($row['AG']) && in_array($row['AG'], $foundAgs)) return true;
                  return false;
             };
        } else {
            // Apply logic if ANY filter (Fixed or Dynamic) is present
            // We assume if $_GET has f_*, we need to filter.
            $hasDynamic = false;
            foreach($_GET as $k=>$v) if(strpos($k, 'f_')===0 && $v) $hasDynamic = true;
            
            if ($fDataIni || $fDataFim || ($fMes && $fAno) || $fAtendente || $hasDynamic) $filters['callback'] = $checkDate;
        }

        // $dashFiles already resolved above
        $dashRes = $db->select($dashFiles, $filters, $pPagina, 20, 'DATA', true);
        $dashProcessos = $dashRes['data'];
        $dashTotal = $dashRes['total'];
        $dashCountStr = str_pad($dashTotal, 2, '0', STR_PAD_LEFT);
        
        $cpfs = array_column($dashProcessos, 'CPF');
        $ports = array_column($dashProcessos, $ID_FIELD_KEY);
        $clientes = $db->findMany('Base_clientes.json', 'CPF', $cpfs);
        $clientMap = []; foreach ($clientes as $c) $clientMap[$c['CPF']] = $c['Nome'];
        $dashCreditos = $db->findMany('Identificacao.json', $IDENT_ID_FIELD, $ports);
        $dashCreditoMap = []; foreach ($dashCreditos as $c) $dashCreditoMap[$c[$IDENT_ID_FIELD]] = $c;
        ?>
        <div class="card card-custom p-4 mb-4">
            <h4 class="text-navy mb-4"><i class="fas fa-filter me-2"></i>Filtros</h4>
            <form onsubmit="filterDashboard(event)" class="row g-3" id="form_dashboard_filter">
                <input type="hidden" name="p" value="dashboard">
                
                <!-- Fixed Filters -->
                <div class="col-md-2">
                    <label>Atendente</label>
                    <select name="fAtendente" class="form-select form-select-sm">
                        <option value="">Todos</option>
                        <?php 
                        // Reuse uniqueAtendentes populated in logic above
                        foreach($uniqueAtendentes as $ua): 
                             $showName = mb_convert_case($ua, MB_CASE_TITLE, 'UTF-8');
                        ?>
                            <option value="<?= htmlspecialchars($ua) ?>" <?= (mb_strtoupper(trim($fAtendente), 'UTF-8') == mb_strtoupper(trim($ua), 'UTF-8'))?'selected':'' ?>><?= htmlspecialchars($showName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="col-md-2"><label>Início</label><input type="datetime-local" name="fDataIni" class="form-control form-control-sm" value="<?= htmlspecialchars($fDataIni) ?>"></div>
                <div class="col-md-2"><label>Fim</label><input type="datetime-local" name="fDataFim" class="form-control form-control-sm" value="<?= htmlspecialchars($fDataFim) ?>"></div>
                
                <!-- Dynamic Filters -->
                <?php
                $procFields = $config->getFields('Base_processos_schema');
                $visFilters = [];
                foreach($procFields as $f) {
                     $show = (isset($f['show_dashboard_filter']) && $f['show_dashboard_filter']) || (!isset($f['show_dashboard_filter']) && isset($f['show_filter']) && $f['show_filter']);
                     if($show && !($f['deleted']??false)) {
                         $visFilters[] = $f['key'];
                     }
                }
                $fieldMap = []; foreach ($procFields as $f) $fieldMap[$f['key']] = $f;
                
                foreach ($visFilters as $vk) {
                    if ($vk === 'Nome_atendente' || $vk === 'DATA') continue; // Skip fixed
                    
                    $fLabel = $vk;
                    $fOptions = null;
                    if (isset($fieldMap[$vk])) {
                         $fLabel = $fieldMap[$vk]['label'];
                         if (!empty($fieldMap[$vk]['options'])) {
                             $fOptions = explode(',', $fieldMap[$vk]['options']);
                         } else {
                             // Fallback for STATUS/ATENDENTE if no options defined
                             // We should consistent with Base View logic scan
                             if (mb_strtoupper($vk) === 'STATUS') {
                                 $status = [];
                                 // Scan all involved files for unique values
                                 foreach ($dashFiles as $df) {
                                     $s = $db->getUniqueValues($df, 'STATUS');
                                     if(is_array($s)) $status = array_merge($status, $s);
                                 }
                                 $status = array_unique($status);
                                 sort($status);
                                 $fOptions = $status;
                             }
                         }
                    }
                    
                    $val = $_GET['f_' . $vk] ?? '';
                    
                    echo '<div class="col-md-2">';
                    echo '<label>' . htmlspecialchars($fLabel) . '</label>';
                    
                    if (is_array($fOptions)) {
                         echo '<select name="f_' . htmlspecialchars($vk) . '" class="form-select form-select-sm">';
                         echo '<option value="">Todos</option>';
                         foreach ($fOptions as $opt) {
                             $opt = trim($opt);
                             $sel = ($val === $opt) ? 'selected' : '';
                             echo '<option value="' . htmlspecialchars($opt) . '" ' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                         }
                         echo '</select>';
                    } else {
                                                   $fMask = $fieldMap[$vk]['custom_mask'] ?? '';
                          $fCase = $fieldMap[$vk]['custom_case'] ?? '';
                          $fAllowed = $fieldMap[$vk]['custom_allowed'] ?? 'all';
                          echo '<input type="text" name="f_' . htmlspecialchars($vk) . '" class="form-control form-control-sm" value="' . htmlspecialchars($val) . '" data-mask="' . htmlspecialchars($fMask) . '" data-case="' . htmlspecialchars($fCase) . '" data-allowed="' . htmlspecialchars($fAllowed) . '" oninput="applyCustomMask(this)">';
                    }
                    echo '</div>';
                }
                ?>
                
                <div class="col-md-2"><label>Busca Global</label><input type="text" name="fBusca" class="form-control form-control-sm" placeholder="CPF, Nome..." value="<?= htmlspecialchars($fBusca) ?>"></div>
                <div class="col-12 text-end">
                    <button class="btn btn-navy btn-sm"><i class="fas fa-search me-1"></i> Filtrar</button>
                    <button type="button" onclick="clearDashboardFilters()" class="btn btn-outline-secondary btn-sm">Limpar</button>
                    <button type="button" onclick="downloadExcel(this)" class="btn btn-success btn-sm"><i class="fas fa-file-excel me-1"></i> Excel</button>
                </div>
            </form>
        </div>

        <div class="card card-custom p-4">
            <h4 class="text-navy mb-3" id="process_list_header"><i class="fas fa-list me-2"></i>Processos (<?= $dashCountStr ?>)</h4>
            <div class="table-responsive">
                <table class="table table-hover table-custom align-middle" id="dash_table_head">
                    <thead><tr>
                        <?php 
                        $procFields = $config->getFields('Base_processos_schema');
                        $schemaCols = [];
                        foreach($procFields as $f) {
                            $show = (isset($f['show_dashboard_column']) && $f['show_dashboard_column']) || (!isset($f['show_dashboard_column']) && isset($f['show_column']) && $f['show_column']);
                            if($show && ($f['type']??'') !== 'title' && !($f['deleted']??false)) {
                                $schemaCols[$f['key']] = ['key'=>$f['key'], 'label'=>$f['label']];
                            }
                        }
                        
                        // Ensure fixed columns
                        if(!isset($schemaCols[$ID_FIELD_KEY])) $schemaCols[$ID_FIELD_KEY] = ['key'=>$ID_FIELD_KEY, 'label'=>$ID_LABEL];
                        if(!isset($schemaCols['Nome_atendente'])) $schemaCols['Nome_atendente'] = ['key'=>'Nome_atendente', 'label'=>'Nome Atendente'];
                        if(!isset($schemaCols['DATA'])) $schemaCols['DATA'] = ['key'=>'DATA', 'label'=>'Data/Hora'];
                        if(!isset($schemaCols['STATUS'])) $schemaCols['STATUS'] = ['key'=>'STATUS', 'label'=>'STATUS'];
                        
                        $dashCols = array_values($schemaCols);

                        // Order Logic
                        $dOrder = $settings->get('dashboard_columns_order', []);
                        if(!empty($dOrder)) {
                            $sorted = [];
                            $indexed = [];
                            foreach($dashCols as $c) $indexed[$c['key']] = $c;
                            foreach($dOrder as $k) {
                                if(isset($indexed[$k])) { $sorted[] = $indexed[$k]; unset($indexed[$k]); }
                            }
                            foreach($indexed as $c) $sorted[] = $c;
                            $dashCols = $sorted;
                        } else {
                            // Default Order: Fixed First
                            $fixedKeys = [$ID_FIELD_KEY, 'Nome_atendente', 'DATA', 'STATUS'];
                            $sorted = [];
                            $indexed = [];
                            foreach($dashCols as $c) $indexed[$c['key']] = $c;
                            foreach($fixedKeys as $k) { if(isset($indexed[$k])) { $sorted[] = $indexed[$k]; unset($indexed[$k]); } }
                            foreach($indexed as $c) $sorted[] = $c;
                            $dashCols = $sorted;
                        }
                        
                        foreach($dashCols as $col): 
                            $icon = '<i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i>';
                            if($col['key'] === $ID_FIELD_KEY) $icon = '<i class="fas fa-sort-down text-dark ms-1"></i>'; // Default sort
                        ?>
                        <th class="sortable-header" data-col="<?= htmlspecialchars($col['key']) ?>" onclick="setDashSort('<?= htmlspecialchars($col['key']) ?>')" style="cursor:pointer"><?= htmlspecialchars($col['label']) ?> <span class="sort-icon"><?= $icon ?></span></th>
                        <?php endforeach; ?>
                        <th>Ação</th>
                    </tr></thead>
                    <tbody id="dash_table_body">
                        <?php foreach($dashProcessos as $proc): 
                            $procIdVal = get_value_ci($proc, $ID_FIELD_KEY);
                            $cred = isset($dashCreditoMap[$procIdVal]); 
                            $l = $lockManager->checkLock($procIdVal, '');
                            $rowClass = $l['locked'] ? 'table-warning' : '';
                        ?>
                        <tr class="<?= $rowClass ?>">
                            <?php foreach($dashCols as $col): 
                                $colKey = $col['key'];
                                if($colKey === $ID_FIELD_KEY): ?>
                                    <td class="dashboard-text fw-bold">
                                        <?= htmlspecialchars(format_field_value($ID_FIELD_KEY, $procIdVal)) ?> 
                                        <?php 
                                        if($cred) {
                                            $cIcon = $IDENT_ICON; 
                                            if(!$cIcon) $cIcon = '<i class="fas fa-sack-dollar text-success"></i>'; 
                                            if(strpos($cIcon, '<') === 0) echo ' ' . $cIcon;
                                            else echo ' <span class="ms-2 text-success" title="Identificação Encontrada!">' . $cIcon . '</span>';
                                        }
                                        ?>
                                    </td>
                                <?php elseif($colKey === 'DATA'): ?>
                                    <td class="dashboard-text"><?= htmlspecialchars(format_field_value('DATA', $proc['DATA'] ?? '')) ?></td>
                                <?php elseif($colKey === 'Nome_atendente'): ?>
                                    <td class="dashboard-text">
                                        <?= htmlspecialchars($proc['Nome_atendente'] ?? '') ?>
                                        <?php if (!empty($proc['Ultima_Alteracao'])): ?>
                                            <div class="small text-muted" style="font-size:0.75rem"><i class="fas fa-clock me-1"></i> <?= htmlspecialchars($proc['Ultima_Alteracao']) ?></div>
                                        <?php endif; ?>
                                        <?php if ($l['locked']): ?>
                                            <div class="small text-danger fw-bold"><i class="fas fa-lock me-1"></i> Em uso por: <?= htmlspecialchars($l['by']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                <?php elseif($colKey === 'STATUS'): 
                                    $colVal = get_value_ci($proc, $colKey);
                                    echo '<td><span class="badge bg-secondary status-badge">' . htmlspecialchars(format_field_value($colKey, $colVal)) . '</span></td>';
                                else: 
                                    $colVal = get_value_ci($proc, $colKey);
                                    echo '<td class="dashboard-text">' . htmlspecialchars(format_field_value($colKey, $colVal)) . '</td>';
                                endif; 
                            endforeach; ?>
                            <td><a href="#" onclick="loadProcess('<?= htmlspecialchars($procIdVal) ?>', this); return false;" class="btn btn-sm btn-outline-dark border-0"><i class="fas fa-folder-open fa-lg text-warning"></i> Abrir</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($dashProcessos)): ?><tr><td colspan="<?= count($dashCols) + 1 ?>" class="text-center text-muted py-4">Nenhum registro encontrado.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div id="dash_pagination_container">
                <?php if($dashRes['pages'] > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if($dashRes['page'] > 1): ?><li class="page-item"><a class="page-link" href="#" onclick="filterDashboard(event, <?= $dashRes['page']-1 ?>)">Anterior</a></li><?php endif; ?>
                        <li class="page-item disabled"><a class="page-link">Página <?= $dashRes['page'] ?> de <?= $dashRes['pages'] ?></a></li>
                        <?php if($dashRes['page'] < $dashRes['pages']): ?><li class="page-item"><a class="page-link" href="#" onclick="filterDashboard(event, <?= $dashRes['page']+1 ?>)">Próxima</a></li><?php endif; ?>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="page-detalhes" class="page-section" style="<?= $page=='detalhes'?'':'display:none' ?>">
        <?php
        $id = $_GET['id'] ?? '';
        
        $processo = null;
        if ($id) {
            $allFiles = $db->getAllProcessFiles();
            $res = $db->select($allFiles, [$ID_FIELD_KEY => $id], 1, 1);
            if (!empty($res['data'])) {
                $processo = $res['data'][0];
            }
        }

        // Identification lookup using configurable field
        $credito = $id ? $db->find('Identificacao.json', $IDENT_ID_FIELD, $id) : null;
        
        // Agency lookup from process data
        $agencia = null;
        if ($processo) {
            $agCode = get_value_ci($processo, 'AG');
            if ($agCode) {
                $agencia = $db->find('Base_agencias.json', 'AG', $agCode);
            }
        }
        
        // Auto-fill Data for JS
        $autoFillData = (!$processo && $credito) ? json_encode($credito) : 'null';
        
        $procFields = $config->getFields('Base_processos_schema'); // Generic schema

        // Helper para verificar obrigatoriedade
        $getReq = function($fields, $key) {
            foreach($fields as $f) {
                if(mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) return ($f['required'] ?? false) ? 'required' : '';
            }
            return '';
        };

        // Helper para exibir asterisco
        $getReqStar = function($fields, $key) {
            foreach($fields as $f) {
                if(mb_strtoupper($f['key'], 'UTF-8') === mb_strtoupper($key, 'UTF-8')) return ($f['required'] ?? false) ? '<span class="text-danger">*</span>' : '';
            }
            return '';
        };

        // Valores únicos para o formulário
        $allStatus = [];
        foreach($procFields as $f) {
            if ($f['key'] === 'STATUS' && !empty($f['options'])) {
                $allStatus = array_map('trim', explode(',', $f['options']));
                break;
            }
        }
        // Get unique values from current period file instead of legacy txt
        $currentPeriodFile = $db->ensurePeriodStructure(date('Y'), date('n'));

        // Load Status options (user defined + existing data)
        $uniqueStatusForm = $db->getUniqueValues($currentPeriodFile, 'STATUS');

        if (empty($allStatus)) {
             $defaultStatus = ['EM ANDAMENTO', 'CONCLUÍDO', 'CANCELADO', 'ASSINADO'];
             $allStatus = array_unique(array_merge($defaultStatus, $uniqueStatusForm));
             sort($allStatus);
        }
        
        $uniqueAtendentesForm = $db->getUniqueValues($currentPeriodFile, 'Nome_atendente');
        
        // Coleta emails da agência se houver agência carregada
        $agencyEmails = [];
        if ($agencia) {
            $emailFields = ['E-MAIL AG', 'E-MAILS SR', 'E-MAIL GERENTE', 'E-MAILS FILIAL'];
            foreach ($emailFields as $ef) {
                if (!empty($agencia[$ef])) {
                    // Split by ; or ,
                    $parts = preg_split('/[;,]/', $agencia[$ef]);
                    foreach ($parts as $p) {
                        $p = trim($p);
                        if ($p && filter_var($p, FILTER_VALIDATE_EMAIL)) {
                            $agencyEmails[] = $p;
                        }
                    }
                }
            }
            $agencyEmails = array_unique($agencyEmails);
            sort($agencyEmails);
        }

        $lockInfo = null;
        if ($id) {
            $lockInfo = $lockManager->checkLock($id, $_SESSION['nome_completo']);
        }
        
        // Prepare Icon HTML for reuse in Tabs/Titles
        $identIconHtml = '';
        if($credito) {
            $cIcon = $IDENT_ICON ?: '<i class="fas fa-sack-dollar text-success"></i>';
            if(strpos($cIcon, '<') === 0) $identIconHtml = ' ' . $cIcon;
            else $identIconHtml = ' <span class="ms-2 text-success">' . $cIcon . '</span>';
        }
        ?>
        <div class="row">
            <div class="col-12 mb-3 d-flex align-items-center gap-2 flex-wrap">
                <a href="?p=dashboard" class="btn btn-outline-secondary" onclick="goBack(); return false;"><i class="fas fa-arrow-left me-1"></i> Voltar</a>
                <button type="button" class="btn btn-navy" onclick="startNewService()">Iniciar novo atendimento</button>
            </div>
            <div class="col-md-12">
                <?php
                // Determine the label for the first tab/section based on the first title in schema
                $firstTitleLabel = '';
                foreach ($procFields as $f) {
                    if (($f['type'] ?? '') === 'title' && !(isset($f['deleted']) && $f['deleted'])) {
                        $firstTitleLabel = $f['label'];
                        break;
                    }
                }
                $tabDisplayLabel = !empty($firstTitleLabel) ? $firstTitleLabel : 'Dados';
                ?>
                <ul class="nav nav-tabs mb-3 justify-content-center">
                    <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-dados"><?= $tabDisplayLabel ?></button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-textos">Registro de Envio</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-registros">Registros de Processo</button></li>
                    <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-anexos"><i class="fas fa-paperclip me-1"></i>Anexos</button></li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tab-dados">
                        <?php if ($lockInfo && $lockInfo['locked']): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-lock me-2"></i> 
                                Este processo está sendo editado por <strong><?= $lockInfo['by'] ?></strong>. Edição bloqueada.
                            </div>
                        <?php endif; ?>

                        <form method="POST" id="form_processo">
                            <input type="hidden" name="timestamp_controle" id="timestamp_controle" value="">
                            <input type="hidden" name="acao" value="salvar_processo">
                            
                            <?php 
                            $isCardOpen = false;
                            
                            // Lembrete Icon Logic
                            $lembreteVal = get_value_ci($processo, 'Data_Lembrete');
                            $lembreteIcon = ' <i class="fas fa-bell text-warning helper-lembrete" style="cursor:pointer" onclick="openLembreteModal()" title="Definir Lembrete"></i>';
                            if (!empty($lembreteVal)) {
                                $now = new DateTime();
                                // Support multiple formats including system standard d/m/Y H:i
                                $lTime = DateTime::createFromFormat('d/m/Y H:i', $lembreteVal);
                                if (!$lTime) $lTime = DateTime::createFromFormat('d/m/Y H:i:s', $lembreteVal);
                                if (!$lTime) $lTime = DateTime::createFromFormat('Y-m-d\TH:i', $lembreteVal);
                                if (!$lTime) $lTime = DateTime::createFromFormat('Y-m-d H:i:s', $lembreteVal);
                                
                                if ($lTime) {
                                    if ($lTime < $now) {
                                        $lembreteIcon = ' <i class="fas fa-bell text-danger fa-beat-fade helper-lembrete" style="cursor:pointer" onclick="openLembreteModal()" title="Lembrete Vencido: '.$lTime->format('d/m/Y H:i').'"></i>';
                                    } else {
                                        $lembreteIcon = ' <i class="fas fa-bell text-success helper-lembrete" style="cursor:pointer" onclick="openLembreteModal()" title="Lembrete: '.$lTime->format('d/m/Y H:i').'"></i>';
                                    }
                                }
                            }
                            
                            $lembreteRendered = false;
                            // Check if first is title
                            $firstIsTitle = !empty($procFields) && ($procFields[0]['type'] ?? '') === 'title' && !(isset($procFields[0]['deleted']) && $procFields[0]['deleted']);
                            
                            if (!$firstIsTitle) {
                                 echo '<div class="card card-custom p-4 mb-4">';
                                 // Dynamic label fallback: uses first found title label or "Dados"
                                 $sectionLabel = !empty($firstTitleLabel) ? $firstTitleLabel : 'Dados';
                                 echo '<h5 class="text-navy fw-bold border-bottom pb-2">' . htmlspecialchars($sectionLabel);
                                 if (!$lembreteRendered) {
                                     echo $lembreteIcon;
                                     $lembreteRendered = true;
                                 }
                                 echo '</h5>';
                                 echo '<div class="row g-3 mb-4">';
                                 $isCardOpen = true;
                            }
                            
                            foreach($procFields as $f): 
                                if (($f['type'] ?? '') === 'title') {
                                    if (isset($f['deleted']) && $f['deleted']) {
                                        continue;
                                    }
                                    if ($isCardOpen) {
                                        echo '</div></div>'; // Close row and card
                                    }
                                    echo '<div class="card card-custom p-4 mb-4">';
                                    echo '<h5 class="text-navy fw-bold border-bottom pb-2">' . htmlspecialchars($f['label']);
                                    
                                    // Always show bell icon on the FIRST title/header displayed
                                    if (!$lembreteRendered) {
                                        echo $lembreteIcon;
                                        $lembreteRendered = true;
                                    }

                                    // Add icon if this title matches the configured Identification Label
                                    if (mb_stripos($f['label'], $IDENT_LABEL) !== false || mb_stripos($f['label'], 'Identificação') !== false) {
                                         echo $identIconHtml;
                                    }
                                    echo '</h5>';
                                    echo '<div class="row g-3 mb-4">';
                                    $isCardOpen = true;
                                    continue;
                                }
                                
                                if (!$isCardOpen) {
                                     echo '<div class="card card-custom p-4 mb-4">';
                                     $sectionLabel = !empty($firstTitleLabel) ? $firstTitleLabel : 'Dados';
                                     echo '<h5 class="text-navy fw-bold border-bottom pb-2">' . htmlspecialchars($sectionLabel);
                                     if (!$lembreteRendered) {
                                         echo $lembreteIcon;
                                         $lembreteRendered = true;
                                     }
                                     echo '</h5>';
                                     echo '<div class="row g-3 mb-4">';
                                     $isCardOpen = true;
                                }

                                // Removed skip logic to allow flexible ordering
                                // if(in_array($f['key'], ['CPF', 'AG', 'Numero_Portabilidade', 'Certificado', 'CERTIFICADO'])) continue;
                                
                                // Hide Data_Lembrete from standard rendering, handled by Bell Icon Modal
                                if ($f['key'] === 'Data_Lembrete') {
                                     $val = $getVal($processo, $f['key']);
                                     echo '<input type="hidden" name="Data_Lembrete" value="'.htmlspecialchars($val).'">';
                                     continue;
                                }
                                
                                // Hide Ultima_Alteracao from standard rendering, irrelevant for manual edit
                                if ($f['key'] === 'Ultima_Alteracao') {
                                     continue;
                                }

                                $val = $getVal($processo, $f['key']);
                                        
                                        // Deleted field handling
                                        $isDeleted = isset($f['deleted']) && $f['deleted'];
                                        $hideClass = '';
                                        if ($isDeleted) {
                                            if (trim($val) === '') $hideClass = 'd-none deleted-field-row';
                                        }

                                        // Fix Date Format for input type=date
                                        if ($f['type'] == 'date' && !empty($val)) {
                                            $dtObj = DateTime::createFromFormat('d/m/Y', $val);
                                            if ($dtObj) $val = $dtObj->format('Y-m-d');
                                        }

                                        // Fix DateTime Local Format
                                        if ($f['type'] == 'datetime-local' && !empty($val)) {
                                            // Try d/m/Y H:i:s or d/m/Y H:i
                                            $dtObj = DateTime::createFromFormat('d/m/Y H:i:s', $val);
                                            if (!$dtObj) $dtObj = DateTime::createFromFormat('d/m/Y H:i', $val);
                                            if ($dtObj) $val = $dtObj->format('Y-m-d\TH:i');
                                        }

                                        $isAtendente = ($f['key'] == 'Nome_atendente');
                                        // If it's a new process and attendant is empty, default to current user
                                        if ($isAtendente && !$val) { $val = $_SESSION['nome_completo']; }
                                    ?>
                                    <div class="col-md-4 <?= $hideClass ?>" data-field-key="<?= $f['key'] ?>">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <label class="form-label-custom">
                                                <?= $f['label'] ?> 
                                                <?php if($f['required'] ?? false): ?><span class="text-danger small">*</span><?php endif; ?>
                                                <?php 
                                                // Add identification icon if label matches
                                                if (!empty($identIconHtml) && (mb_stripos($f['label'], $IDENT_LABEL) !== false || mb_stripos($f['label'], 'Identificação') !== false)) {
                                                     echo $identIconHtml;
                                                }
                                                ?>
                                            </label> 
                                            <?php if($isDeleted): ?>
                                                <button type="button" class="btn btn-sm btn-link text-warning p-0" onclick="reactivateField('Base_processos_schema', '<?= $f['key'] ?>')" title="Reativar Campo"><i class="fas fa-undo"></i></button>
                                            <?php endif; ?>
                                        </div>
                                        <?php 
                                            $req = ($f['required'] ?? false) ? 'required' : ''; 
                                            $disabled = $isDeleted ? 'disabled' : '';
                                        ?>
                                        <?php if($f['key'] == $ID_FIELD_KEY): ?>
                                            <div class="input-group">
                                                <input type="text" name="<?= htmlspecialchars($ID_FIELD_KEY) ?>" id="proc_port" class="form-control form-control-custom fw-bold" value="<?= htmlspecialchars($val ?: ($id ?? '')) ?>" required placeholder="<?= htmlspecialchars($settings->get('id_search_label', 'Buscar/Criar...')) ?>" data-mask="<?= htmlspecialchars($f['custom_mask'] ?? '') ?>" data-case="<?= htmlspecialchars($f['custom_case'] ?? '') ?>" data-allowed="<?= htmlspecialchars($f['custom_allowed'] ?? 'all') ?>" oninput="applyCustomMask(this)">
                                                <button type="button" class="btn btn-outline-secondary" onclick="checkProcess()" id="btn_search_port">
                                                    <i class="fas fa-search"></i>
                                                    <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                                                </button>
                                            </div>

                                        <?php elseif($f['type'] == 'textarea'): ?>
                                            <textarea name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" rows="1" <?= $req ?> <?= $disabled ?>><?= htmlspecialchars($val) ?></textarea>
                                        
                                        <?php elseif ($isAtendente): ?>
                                            <input type="text" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" readonly style="background-color: #e9ecef;">
                                            <input type="hidden" name="<?= $f['key'] ?>" value="<?= htmlspecialchars($val) ?>">

                                        <?php elseif($f['type'] == 'select' || $f['key'] == 'STATUS' || $f['key'] == 'Status_ocorrencia'): ?>
                                            <select name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-select form-select-custom" <?= $req ?> <?= $disabled ?>>
                                                <option value="">...</option>
                                                <?php 
                                                if($f['key'] == 'STATUS'): 
                                                    foreach($allStatus as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach;
                                                elseif($f['key'] == 'Status_ocorrencia'):
                                                    $optsOco = ['Procedente', 'Parcialmente Procedente', 'Improcedente'];
                                                    foreach($optsOco as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach;
                                                elseif ($f['key'] == 'UF'): 
                                                    $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
                                                    sort($ufs);
                                                    foreach($ufs as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach;
                                                else: 
                                                    // Handle user defined options from Config
                                                    $opts = [];
                                                    if(isset($f['options']) && $f['options']) {
                                                        $opts = array_map('trim', explode(',', $f['options']));
                                                    }
                                                    foreach($opts as $opt): ?>
                                                        <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                                <?php endforeach; endif; ?>
                                            </select>
                                        <?php elseif($f['type'] == 'multiselect'): ?>
                                            <?php 
                                                $opts = [];
                                                if(isset($f['options']) && $f['options']) {
                                                    $opts = array_map('trim', explode(',', $f['options']));
                                                }
                                                // Handle val as array or comma separated string
                                                $selectedValues = [];
                                                if (is_array($val)) $selectedValues = $val;
                                                elseif ($val) $selectedValues = array_map('trim', explode(',', $val));
                                                
                                                $btnLabel = empty($selectedValues) ? 'Selecione...' : implode(', ', $selectedValues);
                                                if (strlen($btnLabel) > 30) $btnLabel = substr($btnLabel, 0, 27) . '...';
                                            ?>
                                            <div class="dropdown">
                                                <button class="btn btn-outline-secondary w-100 text-start dropdown-toggle bg-white form-control-custom" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="btn_ms_<?= $f['key'] ?>">
                                                    <?= htmlspecialchars($btnLabel) ?>
                                                </button>
                                                <ul class="dropdown-menu w-100 p-2" style="max-height: 250px; overflow-y: auto;">
                                                <?php foreach($opts as $opt): 
                                                    $chkId = 'chk_' . $f['key'] . '_' . md5($opt);
                                                    $isChecked = in_array($opt, $selectedValues) ? 'checked' : '';
                                                ?>
                                                    <li class="form-check mb-1" onclick="event.stopPropagation()">
                                                        <input class="form-check-input ms-checkbox" type="checkbox" name="<?= $f['key'] ?>[]" value="<?= htmlspecialchars($opt) ?>" id="<?= $chkId ?>" <?= $isChecked ?> <?= $disabled ?> <?= ($req ? 'data-required="true"' : '') ?> data-key="<?= $f['key'] ?>" onchange="updateMultiselectLabel(this)">
                                                        <label class="form-check-label" for="<?= $chkId ?>"><?= htmlspecialchars($opt) ?></label>
                                                    </li>
                                                <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php elseif($f['type'] == 'money'): ?>
                                            <input type="text" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom money-mask" value="<?= htmlspecialchars($val) ?>" placeholder="R$ 0,00" <?= $req ?> <?= $disabled ?>>
                                        <?php elseif($f['type'] == 'number'): ?>
                                            <input type="number" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?> <?= $disabled ?>>
                                        <?php elseif($f['type'] == 'custom'): ?>
                                            <input type="text" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?> <?= $disabled ?> data-mask="<?= htmlspecialchars($f['custom_mask'] ?? '') ?>" data-case="<?= htmlspecialchars($f['custom_case'] ?? '') ?>" data-allowed="<?= htmlspecialchars($f['custom_allowed'] ?? 'all') ?>" oninput="applyCustomMask(this)">
                                        <?php elseif($f['type'] == 'datetime' || $f['type'] == 'datetime-local'): 
                                            // Handle stored datetime conversion for display
                                            $dtVal = $val;
                                            if($val && strpos($val, '/') !== false) {
                                                $parts = explode(' ', $val);
                                                $d = explode('/', $parts[0]);
                                                if(count($d) == 3) {
                                                    $t = isset($parts[1]) ? $parts[1] : '00:00';
                                                    $dtVal = $d[2].'-'.$d[1].'-'.$d[0].'T'.$t;
                                                }
                                            }
                                        ?>
                                            <input type="datetime-local" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" value="<?= htmlspecialchars($dtVal) ?>" <?= $req ?> <?= $disabled ?>>
                                        <?php elseif($f['type'] == 'date'): 
                                            $dVal = $val;
                                            if($val && strpos($val, '/') !== false) {
                                                $parts = explode('/', $val);
                                                if(count($parts) == 3) $dVal = $parts[2].'-'.$parts[1].'-'.$parts[0];
                                            }
                                        ?>
                                            <input type="date" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" value="<?= htmlspecialchars($dVal) ?>" <?= $req ?> <?= $disabled ?>>
                                        <?php else: ?>
                                            <input type="text" name="<?= $f['key'] ?>" id="proc_<?= $f['key'] ?>" class="form-control form-control-custom" value="<?= htmlspecialchars($val) ?>" <?= $req ?> <?= $disabled ?> data-mask="<?= htmlspecialchars($f['custom_mask'] ?? '') ?>" data-case="<?= htmlspecialchars($f['custom_case'] ?? '') ?>" data-allowed="<?= htmlspecialchars($f['custom_allowed'] ?? 'all') ?>" oninput="applyCustomMask(this)">
                                        <?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                    <?php 
                                    // Orphaned Fields Logic (Moved to correct card)
                                    if ($processo) {
                                        $allProcessKeys = array_keys($processo);
                                        $definedKeys = array_column($procFields, 'key');
                                        $definedKeys = array_merge($definedKeys, [$ID_FIELD_KEY, 'Nome_atendente', 'Ultima_Alteracao', 'DATA']);
                                        
                                        $orphanedKeys = array_diff($allProcessKeys, $definedKeys);
                                        if (!empty($orphanedKeys)) {
                                            foreach ($orphanedKeys as $k) {
                                                $val = $processo[$k] ?? '';
                                                if (trim($val) === '') continue; 
                                                echo '<div class="col-md-4">';
                                                echo '<label class="text-muted form-label-custom">' . $k . ' <i class="fas fa-history text-secondary" title="Campo Histórico"></i></label>';
                                                echo '<input type="text" class="form-control form-control-custom" value="' . htmlspecialchars($val) . '" readonly style="background-color: #f8f9fa;">';
                                                echo '</div>';
                                            }
                                        }
                                    }
                                    ?>
                                    <?php if ($isCardOpen): ?>
                                    </div></div>
                                    <?php endif; ?>


                            <?php if($credito): ?>
                            <div id="server_credit_card" class="card card-custom p-4 mb-4 border-warning border-3">
                                <h5 class="text-warning fw-bold border-bottom pb-2"><i class="<?= htmlspecialchars($IDENT_ICON) ?> me-2"></i><?= htmlspecialchars($IDENT_LABEL) ?></h5>
                                <div class="row g-3">
                                    <?php foreach($credito as $k => $v): ?>
                                    <div class="col-md-3"><label class="small text-muted"><?= $k ?></label><div class="fw-bold"><?= $v ?></div></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="d-flex justify-content-between">
                                <div id="div_delete_process">
                                    <?php if($processo): ?>
                                    <?php $delId = function_exists('format_field_value') ? format_field_value($ID_FIELD_KEY, get_value_ci($processo, $ID_FIELD_KEY)) : get_value_ci($processo, $ID_FIELD_KEY); ?>
                                    <button type="button" class="btn btn-outline-danger" onclick="confirmDelete('<?= htmlspecialchars($delId) ?>')">Excluir Processo</button>
                                    <?php endif; ?>
                                </div>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-outline-secondary" onclick="clearForm()">Limpar</button>
                                    <button type="button" class="btn btn-navy" onclick="submitProcessForm(this)">Salvar Dados</button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="tab-registros">
                        <div class="card card-custom p-4 mb-4">
                            <h5 class="text-navy mb-3"><i class="fas fa-clipboard-list me-2"></i>Registros de Processo</h5>
                            <div class="row g-3">
                                <?php 
                                $regFields = $config->getFields('Base_registros_schema');
                                if (!empty($regFields)):
                                    foreach($regFields as $f): 
                                        if (isset($f['deleted']) && $f['deleted']) continue;
                                        if (($f['type'] ?? '') === 'title') {
                                            echo '<div class="col-12"><h6 class="text-navy fw-bold border-bottom pb-2 mt-3">' . htmlspecialchars($f['label']) . '</h6></div>';
                                            continue;
                                        }
                                        $req = ($f['required'] ?? false) ? 'required' : '';
                                        $val = '';
                                        if ($processo && isset($processo[$f['key']])) $val = $processo[$f['key']];
                                ?>
                                <div class="col-md-4">
                                    <label><?= $f['label'] ?></label> <?php if($req): ?><span class="text-danger">*</span><?php endif; ?>
                                    <?php if($f['type'] == 'textarea'): ?>
                                        <textarea name="reg_new_<?= $f['key'] ?>" class="form-control reg-new-field" rows="1" <?= $req ?>><?= htmlspecialchars($val) ?></textarea>
                                    <?php elseif($f['type'] == 'select'): ?>
                                        <select name="reg_new_<?= $f['key'] ?>" class="form-select reg-new-field" <?= $req ?>>
                                            <option value="">...</option>
                                            <?php 
                                                $opts = [];
                                                if(isset($f['options']) && $f['options']) {
                                                    $opts = array_map('trim', explode(',', $f['options']));
                                                }
                                                foreach($opts as $opt): ?>
                                                    <option value="<?= htmlspecialchars($opt) ?>" <?= $val==$opt?'selected':'' ?>><?= htmlspecialchars($opt) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif($f['type'] == 'multiselect'): ?>
                                        <?php 
                                            $opts = [];
                                            if(isset($f['options']) && $f['options']) {
                                                $opts = array_map('trim', explode(',', $f['options']));
                                            }
                                            $selectedValues = [];
                                            if (is_array($val)) $selectedValues = $val;
                                            elseif ($val) $selectedValues = array_map('trim', explode(',', $val));
                                            
                                            $btnLabel = empty($selectedValues) ? 'Selecione...' : implode(', ', $selectedValues);
                                            if (strlen($btnLabel) > 30) $btnLabel = substr($btnLabel, 0, 27) . '...';
                                        ?>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-secondary w-100 text-start dropdown-toggle bg-white form-control-custom" type="button" data-bs-toggle="dropdown" aria-expanded="false" id="btn_ms_reg_<?= $f['key'] ?>">
                                                <?= htmlspecialchars($btnLabel) ?>
                                            </button>
                                            <ul class="dropdown-menu w-100 p-2" style="max-height: 250px; overflow-y: auto;">
                                            <?php foreach($opts as $opt): 
                                                $chkId = 'chk_reg_' . $f['key'] . '_' . md5($opt);
                                                $isChecked = in_array($opt, $selectedValues) ? 'checked' : '';
                                            ?>
                                                <li class="form-check mb-1" onclick="event.stopPropagation()">
                                                    <input class="form-check-input ms-checkbox reg-new-field" type="checkbox" name="reg_new_<?= $f['key'] ?>[]" value="<?= htmlspecialchars($opt) ?>" id="<?= $chkId ?>" <?= $isChecked ?> <?= ($req ? 'data-required="true"' : '') ?> data-key="reg_<?= $f['key'] ?>" onchange="updateMultiselectLabel(this)">
                                                    <label class="form-check-label" for="<?= $chkId ?>"><?= htmlspecialchars($opt) ?></label>
                                                </li>
                                            <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php elseif($f['type'] == 'datetime'): 
                                        $dtVal = $val;
                                        if($val && strpos($val, '/') !== false) {
                                            $parts = explode(' ', $val);
                                            $d = explode('/', $parts[0]);
                                            if(count($d) == 3) {
                                                $t = isset($parts[1]) ? $parts[1] : '00:00';
                                                $dtVal = $d[2].'-'.$d[1].'-'.$d[0].'T'.$t;
                                            }
                                        }
                                    ?>
                                        <input type="datetime-local" name="reg_new_<?= $f['key'] ?>" class="form-control reg-new-field" value="<?= htmlspecialchars($dtVal) ?>" <?= $req ?>>
                                    <?php elseif($f['type'] == 'date'): 
                                        $dVal = $val;
                                        if($val && strpos($val, '/') !== false) {
                                            $parts = explode('/', $val);
                                            if(count($parts) == 3) $dVal = $parts[2].'-'.$parts[1].'-'.$parts[0];
                                        }
                                    ?>
                                        <input type="date" name="reg_new_<?= $f['key'] ?>" class="form-control reg-new-field" value="<?= htmlspecialchars($dVal) ?>" <?= $req ?>>
                                    <?php elseif($f['type'] == 'custom'): ?>
                                        <input type="text" name="reg_new_<?= $f['key'] ?>" class="form-control reg-new-field" value="<?= htmlspecialchars($val) ?>" <?= $req ?> data-mask="<?= htmlspecialchars($f['custom_mask'] ?? '') ?>" data-case="<?= htmlspecialchars($f['custom_case'] ?? '') ?>" data-allowed="<?= htmlspecialchars($f['custom_allowed'] ?? 'all') ?>" oninput="applyCustomMask(this)">
                                    <?php else: ?>
                                        <input type="text" name="reg_new_<?= $f['key'] ?>" class="form-control reg-new-field" value="<?= htmlspecialchars($val) ?>" <?= $req ?> data-mask="<?= htmlspecialchars($f['custom_mask'] ?? '') ?>" data-case="<?= htmlspecialchars($f['custom_case'] ?? '') ?>" data-allowed="<?= htmlspecialchars($f['custom_allowed'] ?? 'all') ?>" oninput="applyCustomMask(this)">
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; else: ?>
                                <div class="col-12 text-muted">Nenhum campo configurado. Vá em Configurações > Campos de Registros.</div>
                                <?php endif; ?>
                                
                                <div class="col-12 text-end mt-3">
                                        <button type="button" class="btn btn-primary" onclick="saveProcessRecord(this)"><i class="fas fa-save me-1"></i> Salvar Registro</button>
                                </div>
                            </div>
                            <hr class="my-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="text-navy mb-0">Histórico de Registros</h5>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteSelectedHistory('registros')"><i class="fas fa-trash me-1"></i> Excluir Selecionados</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped small">
                                    <thead><tr>
                                        <th style="width: 30px;"><input type="checkbox" class="form-check-input" onclick="toggleAllChecks(this, 'registros_del')"></th>
                                        <th>Data</th><th>Usuário</th>
                                        <?php 
                                        $regHeaders = $config->getFields('Base_registros_schema');
                                        foreach($regHeaders as $f) {
                                            if (isset($f['deleted']) && $f['deleted']) continue;
                                            echo '<th>' . htmlspecialchars($f['label']) . '</th>';
                                        }
                                        ?>
                                    </tr></thead>
                                    <tbody id="history_registros_body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-anexos">
                        <div class="card card-custom p-4 mb-4">
                            <h5 class="text-navy mb-3"><i class="fas fa-paperclip me-2"></i>Anexos do Processo</h5>
                            
                            <div class="card mb-3">
                                <div class="card-body">
                                    <div class="row align-items-end">
                                        <div class="col-md-9">
                                            <label class="form-label">Selecionar Arquivo</label>
                                            <input type="file" class="form-control" id="input_anexo_file">
                                        </div>
                                        <div class="col-md-3">
                                            <button class="btn btn-navy w-100" onclick="uploadAttachment()"><i class="fas fa-upload me-2"></i>Enviar</button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="table-responsive">
                                <table class="table table-striped table-hover" id="table_anexos">
                                    <thead>
                                        <tr>
                                            <th>Arquivo</th>
                                            <th style="width: 150px;">Tamanho</th>
                                            <th style="width: 120px;" class="text-center">Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- Populated by JS -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tab-textos">
                        <div class="card card-custom p-4 mb-4">
                            <style>
                                /* Edit Mode Styles */
                                .edit-only { display: none !important; }
                                .edit-mode .edit-only { display: block !important; }
                                .edit-mode .edit-only-flex { display: flex !important; }
                                
                                /* Template List Styles */
                                .bg-navy { background-color: #001f3f !important; color: white !important; }
                                .accordion-button.bg-navy { color: white !important; background-color: #001f3f !important; }
                                .accordion-button.bg-navy::after { filter: invert(1) brightness(200%); }
                                .accordion-button.bg-navy:not(.collapsed) { background-color: #001a35 !important; color: white !important; }
                                .accordion-button.bg-navy:focus { box-shadow: none; border-color: rgba(0,0,0,.125); }
                            </style>
                            <div class="d-flex justify-content-end align-items-center mb-3">
                                <div class="d-flex align-items-center gap-2">
                                     <div class="edit-only edit-only-flex align-items-center gap-2"> 
                                         <button class="btn btn-sm btn-outline-primary" onclick="openListModal()"><i class="fas fa-list me-1"></i> Nova Lista</button>
                                         <button class="btn btn-sm btn-outline-secondary" onclick="openTemplateModal()"><i class="fas fa-plus me-1"></i> Novo Modelo</button>
                                         <span class="vr mx-2"></span>
                                     </div>
                                     <button class="btn btn-sm btn-outline-warning fw-bold shadow-sm" id="btnToggleEdit" onclick="toggleEditMode()"><i class="fas fa-cog me-1"></i> Gerenciar Modelos</button>
                                </div>
                            </div>
                            
                            <div class="row g-3">
                                <div class="col-12">
                                    <!-- Grid Layout for Lists -->
                                    <div id="templates_container" class="accordion row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3" style="min-height: 50px;">
                                        <!-- Populated via JS -->
                                        <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>Carregando modelos...</div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label>Corpo do Texto</label>
                                    <textarea id="tpl_result" class="form-control" rows="8"></textarea>
                                    <button type="button" class="btn btn-sm btn-navy mt-1" onclick="copyToClipboard()"><i class="fas fa-copy"></i> Copiar</button>
                                </div>
                                <div class="col-12 text-end">
                                    <button type="button" class="btn btn-outline-danger me-2" onclick="clearSelection()"><i class="fas fa-eraser me-1"></i> Limpar Seleção</button>
                                    <button type="button" class="btn btn-success" onclick="saveHistory(this)"><i class="fas fa-paper-plane me-1"></i> Registrar Envio</button>
                                </div>
                            </div>
                            <hr class="my-4">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="text-navy mb-0">Histórico de Envios</h5>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteSelectedHistory('envios')"><i class="fas fa-trash me-1"></i> Excluir Selecionados</button>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-striped small">
                                    <thead><tr>
                                        <th style="width: 30px;"><input type="checkbox" class="form-check-input" onclick="toggleAllChecks(this, 'hist_del')"></th>
                                        <th>Data</th><th>Usuário</th><th>Título</th><th>Tema</th>
                                    </tr></thead>
                                    <tbody id="history_table_body">
                                        <?php 
                                        if ($processo) {
                                            $hist = $templates->getHistory(get_value_ci($processo, $ID_FIELD_KEY));
                                            foreach ($hist as $h) {
                                                $data = htmlspecialchars($h['DATA'] ?? '');
                                                $user = htmlspecialchars($h['USUARIO'] ?? '');
                                                $raw = $h['MODELO'] ?? ($h['TEXTO'] ?? ''); // Use MODELO preferably
                                                
                                                $titulos = [];
                                                $temas = [];
                                                
                                                // Handle multiple items separated by semicolon
                                                $parts = explode(';', $raw);
                                                foreach($parts as $part) {
                                                    $part = trim($part);
                                                    if(empty($part)) continue;
                                                    
                                                    // Parse "Lista: X - Tema: Y"
                                                    // Flexible regex for "Lista: ... - Tema: ..."
                                                    if(preg_match('/Lista:\s*(.*?)\s*-\s*Tema:\s*(.*)/i', $part, $m)) {
                                                        $titulos[] = trim($m[1]);
                                                        $temas[] = trim($m[2]);
                                                    } else {
                                                        // Legacy or just theme
                                                        $titulos[] = '-'; // Or leave empty? User asked for "Cobrança" vs "Theme"
                                                        $temas[] = $part;
                                                    }
                                                }
                                                
                                                // Unique titles if multiple from same list?
                                                $colTitulo = htmlspecialchars(implode('; ', array_unique($titulos)));
                                                $colTema = htmlspecialchars(implode('; ', $temas));
                                                
                                                echo "<tr><td><input type='checkbox' name='hist_del[]' class='form-check-input' value='{$h['_id']}'></td><td>{$data}</td><td>{$user}</td><td>{$colTitulo}</td><td>{$colTema}</td></tr>";
                                            }
                                        }
                                        ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
    <div id="page-lembretes" class="page-section" style="<?= $page=='lembretes'?'':'display:none' ?>">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="text-navy mb-0"><i class="fas fa-calendar-check me-2"></i>Lembretes</h3>
            <div>
                <button class="btn btn-sm btn-outline-success me-2" onclick="downloadLembretesExcel(this)"><i class="fas fa-file-excel"></i> Exportar Excel</button>
                <button class="btn btn-sm btn-outline-dark" onclick="clearLembretesFilters()"><i class="fas fa-eraser"></i> Limpar Filtros</button>
            </div>
        </div>
        
        <div class="card card-custom p-4 mb-4">
             <form id="form_lembretes_filter" onsubmit="filterLembretes(event)">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label>Data Lembrete Início</label>
                        <input type="datetime-local" name="fLembreteIni" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label>Data Lembrete Fim</label>
                        <input type="datetime-local" name="fLembreteFim" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label>Busca Global</label>
                        <input type="text" name="fBuscaGlobal" class="form-control" placeholder="Pesquisar...">
                    </div>
                    <div class="col-md-2">
                        <div class="d-flex gap-2">
                            <button class="btn btn-navy btn-sm w-100"><i class="fas fa-search"></i> Filtrar</button>
                        </div>
                    </div>
                </div>
             </form>
        </div>

        <div class="card card-custom p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle table-sm small" id="lembretes_table">
                    <!-- Loaded via AJAX -->
                    <thead><tr><th class="text-center p-4 text-muted">Carregando...</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div id="lembretes_pagination_container" class="mt-3"></div>
        </div>
    </div>

    <div id="page-base" class="page-section" style="<?= $page=='base'?'':'display:none' ?>">
       <h3 class="text-navy mb-4">Gestão de Bases</h3>

       <div class="card card-custom p-3 mb-4">
           <ul class="nav nav-pills nav-fill gap-3" id="base-tab">
                <li class="nav-item">
                    <button class="nav-link active rounded-pill" id="tab-proc" onclick="switchBase('Processos')">Processos</button>
                </li>
                <li class="nav-item">
                    <button class="nav-link rounded-pill" id="tab-cred" onclick="switchBase('Identificacao.json')"><?= htmlspecialchars($IDENT_LABEL) ?></button>
                </li>
           </ul>
       </div>

       <?php
       // Logic for Base View
       $cfBusca = $_GET['cfBusca'] ?? '';
       $cpPagina = $_GET['cpPagina'] ?? 1;
       
       // Check if filter is active
       $isFilter = isset($_GET['cfDataIni']) || isset($_GET['cfDataFim']) || isset($_GET['cfBusca']);
       
       if (!$isFilter) {
           // Default to today
           $cfDataIni = date('Y-m-d');
           $cfDataFim = date('Y-m-d');
       } else {
           $cfDataIni = $_GET['cfDataIni'] ?? '';
           $cfDataFim = $_GET['cfDataFim'] ?? '';
       }

       $cFilters = [];
       if ($cfBusca) $cFilters['global'] = $cfBusca;
       
       if ($cfDataIni || $cfDataFim) {
           $cFilters['callback'] = function($row) use ($cfDataIni, $cfDataFim) {
               $d = $row['DATA'] ?? ($row['DATA_DEPOSITO'] ?? '');
               if (!$d) return false;
               
               $dt = DateTime::createFromFormat('d/m/Y H:i:s', $d);
               if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $d);
               if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $d);
               if (!$dt) return false;
               
               if ($cfDataIni) {
                   $di = DateTime::createFromFormat('Y-m-d\TH:i', $cfDataIni);
                   if (!$di) $di = DateTime::createFromFormat('!Y-m-d', $cfDataIni);
                   if ($dt < $di) return false;
               }
               if ($cfDataFim) {
                   $df = DateTime::createFromFormat('Y-m-d\TH:i', $cfDataFim);
                   if (!$df) {
                       $df = DateTime::createFromFormat('!Y-m-d', $cfDataFim);
                       if ($df) $df->setTime(23, 59, 59);
                   }
                   if ($df && $dt > $df) return false;
               }
               return true;
           };
       }

       $base = 'Processos';
       $cRes = $db->select($base, $cFilters, $cpPagina, 50, 'DATA', true);
       $creditos = $cRes['data'];
       $credTotal = $cRes['total'];
       $credCountStr = str_pad($credTotal, 2, '0', STR_PAD_LEFT);
       ?>
       
       <?php if (isset($showPreview) && $showPreview): ?>
           <style>
               #preview_table_container table th, #preview_table_container table td { font-size: 11px !important; }
           </style>
           <div class="card card-custom p-4 mb-4 border-warning" id="preview_table_container">
                <h5 class="text-warning fw-bold mb-3"><i class="fas fa-eye me-2"></i>Pré-visualização da Importação</h5>
                <div class="alert alert-info small">
                    <i class="fas fa-info-circle me-1"></i> Verifique os dados abaixo antes de confirmar. Registros com datas inválidas não serão importados.
                </div>
                
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0" id="dashboard_table">
                        <thead id="dash_sortable_head">
                            <tr class="bg-light">
                                <th>#</th>
                                <?php 
                                $previewBase = $_SESSION['upload_preview_base'] ?? '';
                                $previewFields = $config->getFields($previewBase);
                                $previewHeaders = [];
                                
                                foreach($previewFields as $f) {
                                    if(!isset($f['deleted']) || !$f['deleted']) {
                                        if (isset($f['type']) && $f['type'] === 'title') continue;
                                        $previewHeaders[] = $f['key'];
                                    }
                                }
                                
                                if (empty($previewHeaders)) {
                                    // Fallback defaults
                                    if (stripos($previewBase, 'client') !== false) {
                                        $previewHeaders = ['Nome', 'CPF'];
                                    } elseif (stripos($previewBase, 'agenc') !== false) {
                                        $previewHeaders = ['AG', 'UF', 'SR', 'NOME SR', 'FILIAL', 'E-MAIL AG', 'E-MAILS SR', 'E-MAILS FILIAL', 'E-MAIL GERENTE'];
                                    } elseif (stripos($previewBase, 'Processos') !== false || stripos($previewBase, 'Base_processos') !== false) {
                                        // Dynamic: Build from process schema
                                        $previewHeaders = array_column(array_filter($config->getFields('Base_processos_schema'), function($f) { return ($f['type'] ?? '') !== 'title' && !($f['deleted'] ?? false); }), 'key');
                                    } else {
                                        $previewHeaders = ['STATUS', 'NUMERO_DEPOSITO', 'DATA_DEPOSITO', 'VALOR_DEPOSITO_PRINCIPAL', 'TEXTO_PAGAMENTO', $IDENT_ID_FIELD, 'CERTIFICADO', 'STATUS_2', 'CPF', 'AG'];
                                    }
                                }
                                
                                foreach($previewHeaders as $h) {
                                    // Use label if available
                                    $lbl = $h;
                                    foreach($previewFields as $f) { if($f['key'] == $h) { $lbl = $f['label']; break; } }
                                    echo '<th>' . htmlspecialchars($lbl) . '</th>';
                                }
                                ?>
                                <th>Validação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($_SESSION['upload_preview'] as $idx => $row): ?>
                            <tr class="<?= isset($row['DATA_ERROR']) ? 'table-danger' : '' ?>">
                                <td><?= $idx + 1 ?></td>
                                <?php foreach($previewHeaders as $h): ?>
                                    <td><?= htmlspecialchars($row[$h] ?? '') ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <?php if(isset($row['DATA_ERROR'])): ?>
                                        <span class="badge bg-danger">Data Inválida</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">OK</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="d-flex justify-content-end gap-2 mt-3">
                    <form method="POST" onsubmit="handleFormSubmit(this)">
                        <input type="hidden" name="acao" value="cancel_upload">
                        <button class="btn btn-outline-secondary">Cancelar</button>
                    </form>
                    <form method="POST" onsubmit="handleFormSubmit(this)">
                        <input type="hidden" name="acao" value="confirm_upload">
                        <button class="btn btn-primary"><i class="fas fa-check me-1"></i> Confirmar Importação</button>
                    </form>
                </div>
           </div>
       <?php endif; ?>

       <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between mb-3">
                <h5 id="updateBaseTitle">Atualizar Base</h5>
                <div class="d-flex gap-2">
                    <button onclick="openPasteModal()" class="btn btn-info btn-sm text-white"><i class="fas fa-paste me-1"></i> Colar Dados</button>
                    <a href="#" onclick="downloadBase(this)" class="btn btn-success btn-sm"><i class="fas fa-file-excel me-1"></i> Baixar</a>
                </div>
            </div>
            <form id="form_limpar_base" method="POST" style="display:none">
                <input type="hidden" name="acao" value="limpar_base">
                <input type="hidden" name="base" id="limpar_base_target">
            </form>
       </div>

       <!-- VISUALIZACAO -->
       <div class="card card-custom p-4 mb-4">
            <h5 class="text-navy mb-3">Visualização</h5>
            <form onsubmit="filterBase(event)" class="row g-3 align-items-end" id="form_base_filter">
                <input type="hidden" name="p" value="base">
                <div class="col-md-2 mb-3">
                    <label class="form-label small mb-1 fw-bold text-muted" style="font-size:0.8rem">Data Início</label>
                    <input type="datetime-local" name="cfDataIni" class="form-control form-control-sm shadow-sm">
                </div>
                <div class="col-md-2 mb-3">
                    <label class="form-label small mb-1 fw-bold text-muted" style="font-size:0.8rem">Data Fim</label>
                    <input type="datetime-local" name="cfDataFim" class="form-control form-control-sm shadow-sm">
                </div>
                <!-- Dynamic Filters based on base_filters (STATUS defaults) -->
                <div id="dynamic_filters_area" style="display:contents">
                <?php
                // Dynamic Filters based on Schema Configuration (show_filter)
                // We use the default base (Processos) for the initial render. 
                $baseSchema = $config->getFields('Base_processos_schema');
                $renderFilters = [];
                foreach ($baseSchema as $f) {
                    $show = (isset($f['show_base_filter']) && $f['show_base_filter']) || (!isset($f['show_base_filter']) && isset($f['show_filter']) && $f['show_filter']);
                    if ($show) {
                        $renderFilters[] = $f['key'];
                    }
                }
                // Fallback removed to allow full user control


                foreach($renderFilters as $bk) {
                     // Find label and type
                     $label = $bk;
                     $fType = 'text';
                     foreach($baseSchema as $f) { 
                         if($f['key'] == $bk) { 
                             $label = $f['label']; 
                             $fType = $f['type'] ?? 'text';
                             break; 
                         } 
                     }
                     
                     $idAttr = '';
                     if (mb_strtoupper($bk) === 'STATUS') $idAttr = 'id="base_fStatus"';
                     if (in_array(mb_strtoupper($bk), ['ATENDENTE', 'NOME_ATENDENTE'])) $idAttr = 'id="base_fAtendente"';
                     
                     // Helper for type
                     
                     echo '<div class="col-md-2 mb-3 me-2">';
                     echo '<label class="form-label small mb-1 fw-bold text-muted" style="font-size:0.8rem">' . htmlspecialchars($label) . '</label>';
                     
                     if (mb_strtoupper($bk) === 'STATUS' || $fType === 'select' || in_array(mb_strtoupper($bk), ['ATENDENTE', 'NOME_ATENDENTE'])) {
                         echo '<select name="f_' . htmlspecialchars($bk) . '" ' . $idAttr . ' class="form-select form-select-sm shadow-sm"></select>';
                     } else {
                                                   $fMask = ''; $fCase = ''; $fAllowed = 'all';
                          foreach ($baseSchema as $fs) {
                              if ($fs['key'] === $bk) {
                                  $fMask = $fs['custom_mask'] ?? '';
                                  $fCase = $fs['custom_case'] ?? '';
                                  $fAllowed = $fs['custom_allowed'] ?? 'all';
                                  break;
                              }
                          }
                          echo '<input type="text" name="f_' . htmlspecialchars($bk) . '" class="form-control form-control-sm shadow-sm" data-mask="' . htmlspecialchars($fMask) . '" data-case="' . htmlspecialchars($fCase) . '" data-allowed="' . htmlspecialchars($fAllowed) . '" oninput="applyCustomMask(this)">';
                     }
                     echo '</div>';
                }
                ?>
                </div>
                

                <div class="col-md-3 mb-3">
                    <label class="form-label small mb-1 fw-bold text-muted" style="font-size:0.8rem">Busca Global</label>
                    <input type="text" name="cfBusca" class="form-control form-control-sm shadow-sm" placeholder="Pesquisar...">
                </div>
                <div class="col-md-2 mb-3">
                    <div class="d-flex gap-2 h-100 align-items-end">
                        <button type="submit" class="btn btn-navy btn-sm shadow-sm flex-grow-1"><i class="fas fa-search me-1"></i> Filtrar</button>
                        <button type="button" onclick="clearBaseFilters()" class="btn btn-outline-secondary btn-sm shadow-sm" title="Limpar"><i class="fas fa-eraser"></i></button>
                    </div>
                </div>
            </form>
       </div>

       <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between mb-3">
                <h5 class="text-navy" id="base_registros_header">Processos (<?= $credCountStr ?>)</h5>
                <div class="d-flex gap-2">
                    <button class="btn btn-navy btn-sm me-2" onclick="editSelectedBase()"><i class="fas fa-edit me-1"></i> Edição Selecionada</button>
                    <button class="btn btn-danger btn-sm" onclick="deleteSelectedBase(this)"><i class="fas fa-trash me-1"></i> Excluir Selecionados</button>
                    <button class="btn btn-success btn-sm" onclick="openBaseModal(null, this)"><i class="fas fa-plus"></i> Adicionar</button>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm small align-middle" id="base_table">
                    <!-- Content loaded via AJAX -->
                    <tbody id="cred_table_body">
                         <tr><td class="text-center p-5">Carregando...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="cred_pagination_container"></div>
       </div>

       <!-- Modal Base Record (Dynamic) -->
       <div class="modal fade" id="modalBase" tabindex="-1">
           <div class="modal-dialog modal-lg">
               <form class="modal-content" id="form_base" onsubmit="event.preventDefault(); saveBase();">
                   <div class="modal-header bg-navy text-white">
                       <h5 class="modal-title">Dados do Registro</h5>
                       <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                   </div>
                   <div class="modal-body">
                       <input type="hidden" name="original_id" id="base_original_id">
                       <input type="hidden" name="base" id="base_target_name">
                       <div class="row g-3" id="modal_base_fields">
                           <!-- JS Injected -->
                       </div>
                   </div>
                   <div class="modal-footer">
                       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                       <button class="btn btn-navy">Salvar</button>
                   </div>
               </form>
           </div>
       </div>

       <!-- Modal Bulk Edit -->
       <div class="modal fade" id="modalBulkEdit" tabindex="-1">
           <div class="modal-dialog modal-lg">
               <form class="modal-content" id="form_bulk_edit" onsubmit="event.preventDefault(); saveBulkBase();">
                   <div class="modal-header bg-navy text-white">
                       <h5 class="modal-title">Edição em Massa</h5>
                       <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                   </div>
                   <div class="modal-body">
                       <div class="alert alert-info small">
                           <i class="fas fa-info-circle me-1"></i> Apenas campos com valores idênticos entre os registros selecionados podem ser editados.
                       </div>
                       <input type="hidden" name="base" id="bulk_base_target">
                       <div class="row g-3" id="modal_bulk_fields">
                           <!-- JS Injected -->
                       </div>
                   </div>
                   <div class="modal-footer">
                       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                       <button class="btn btn-navy">Aplicar Alterações</button>
                   </div>
               </form>
           </div>
       </div>

       <div class="modal fade" id="modalCredit" tabindex="-1">
           <div class="modal-dialog modal-lg">
               <form class="modal-content" id="form_credit" onsubmit="event.preventDefault(); saveCredit();">
                   <div class="modal-header bg-navy text-white">
                       <h5 class="modal-title">Editar Identificação</h5>
                       <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                   </div>
                   <div class="modal-body">
                       <input type="hidden" name="original_port" id="cred_original_port">
                       <div class="row g-3">
                           <div class="col-md-4">
                               <label class="form-label"><?= htmlspecialchars($IDENT_LABEL) ?></label>
                               <input type="text" name="<?= htmlspecialchars($IDENT_ID_FIELD) ?>" id="cred_<?= htmlspecialchars($IDENT_ID_FIELD) ?>" class="form-control" required>
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Status</label>
                               <input type="text" name="STATUS" id="cred_STATUS" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Número Depósito</label>
                               <input type="text" name="NUMERO_DEPOSITO" id="cred_NUMERO_DEPOSITO" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Data Depósito</label>
                               <input type="date" name="DATA_DEPOSITO" id="cred_DATA_DEPOSITO" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Valor Depósito</label>
                               <input type="text" name="VALOR_DEPOSITO_PRINCIPAL" id="cred_VALOR_DEPOSITO_PRINCIPAL" class="form-control money-mask">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Certificado</label>
                               <input type="text" name="CERTIFICADO" id="cred_CERTIFICADO" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Status 2</label>
                               <input type="text" name="STATUS_2" id="cred_STATUS_2" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">CPF</label>
                               <input type="text" name="CPF" id="cred_CPF" class="form-control">
                           </div>
                           <div class="col-md-4">
                               <label class="form-label">Agência</label>
                               <input type="text" name="AG" id="cred_AG" class="form-control">
                           </div>
                           <div class="col-12">
                               <label class="form-label">Texto Pagamento</label>
                               <textarea name="TEXTO_PAGAMENTO" id="cred_TEXTO_PAGAMENTO" class="form-control" rows="2"></textarea>
                           </div>
                       </div>
                   </div>
                   <div class="modal-footer">
                       <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                       <button class="btn btn-navy">Salvar</button>
                   </div>
               </form>
           </div>
       </div>

    </div>
    <div id="page-config" class="page-section" style="<?= $page=='config'?'':'display:none' ?>">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="text-navy mb-0">Configurações de Campos</h3>
        </div>
        <div class="row mb-5">
            <?php foreach(['Base_processos_schema' => 'Processos', 'Base_registros_schema' => 'Campos de Registros', 'Identificacao.json' => $IDENT_LABEL] as $file => $label): ?>
            <div class="col-md-3">
                <div class="card card-custom p-3 h-100">
                    <h5 class="text-navy">
                        <?= $label ?> 
                        <small class="text-muted fs-6">(Arraste para ordenar)</small>
                    </h5>
                    <ul class="list-group list-group-flush mb-3 sortable-list" data-file="<?= $file ?>">
                        <?php foreach($config->getFields($file) as $f): 
                            if(isset($f['deleted']) && $f['deleted']) continue;
                            // Only lock primary identifiers and mandatory system fields
                            $lockedFields = [$ID_FIELD_KEY, 'Nome_atendente', 'DATA', 'STATUS']; // These fields are protected
                            $isLocked = ($file !== 'Base_registros_schema') && in_array($f['key'], $lockedFields);
                            $isTitle = (($f['type'] ?? '') === 'title');
                            $liClass = $isTitle ? 'list-group-item-secondary fw-bold' : '';
                        ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center <?= $liClass ?>" data-key="<?= $f['key'] ?>">
                            <div>
                                <i class="fas fa-grip-vertical text-muted me-2 handle"></i> 
                                <?= $f['label'] ?> 
                                <?php if(!$isTitle): ?>
                                    <small class="text-muted">(<?= $f['type'] ?>)</small> 
                                    <?php if($f['required'] ?? false): ?><span class="text-danger">*</span><?php endif; ?>
                                <?php else: ?>
                                    <small class="text-muted fst-italic">(Título/Seção)</small>
                                <?php endif; ?>
                                <?php if($f['show_reminder'] ?? false): ?>
                                    <span class="badge bg-warning text-dark ms-2" title="Exibido em Lembretes"><i class="fas fa-bell"></i></span>
                                <?php endif; ?>
                                <?php if($f['show_column'] ?? false): ?>
                                    <span class="badge bg-primary ms-1" title="Exibido em Colunas"><i class="fas fa-table"></i></span>
                                <?php endif; ?>
                                <?php if($f['show_filter'] ?? false): ?>
                                    <span class="badge bg-info text-dark ms-1" title="Exibido em Filtros"><i class="fas fa-filter"></i></span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <button class="btn btn-sm btn-link text-info" onclick='editField(<?= json_encode($f, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, "<?= $file ?>")'><i class="fas fa-pen"></i></button>
                                <?php if(!$isLocked): ?>
                                <button class="btn btn-sm btn-link text-danger" onclick="removeField('<?= $file ?>', '<?= $f['key'] ?>')"><i class="fas fa-trash"></i></button>
                                <?php else: ?>
                                <button class="btn btn-sm btn-link text-muted" disabled title="Campo Protegido"><i class="fas fa-lock"></i></button>
                                <?php endif; ?>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="mt-auto d-flex gap-2">
                        <button class="btn btn-sm btn-outline-primary flex-grow-1" onclick="addFieldModal('<?= $file ?>')">Add Campo</button>
                        <button class="btn btn-sm btn-outline-secondary flex-grow-1" onclick="addTitleModal('<?= $file ?>')">Add Título</button>
                        <?php if($file === 'Identificacao.json'): ?>
                            <button class="btn btn-sm btn-outline-warning" onclick="changeIdentIcon()" title="Alterar Ícone"><i class="fas fa-icons"></i></button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <!--
        <h3 class="text-navy mb-4">Modelos de Textos</h3>
        <div class="card card-custom p-4 mb-4">
            <div class="d-flex justify-content-between mb-3">
                <h5 class="text-navy">Modelos Cadastrados</h5>
                <button class="btn btn-navy btn-sm" onclick="modalTemplate()">Novo Modelo</button>
            </div>
            <table class="table table-hover">
                <thead><tr><th>Título</th><th>Ações</th></tr></thead>
                <tbody>
                    <?php foreach($templates->getAll() as $t): ?>
                    <tr>
                        <td><?= $t['titulo'] ?></td>
                        <td>
                            <button class="btn btn-sm btn-link text-info" onclick='editTemplate(<?= json_encode($t) ?>)'><i class="fas fa-pen"></i></button>
                            <button class="btn btn-sm btn-link text-danger" onclick="confirmTemplateDelete('<?= $t['id'] ?>')"><i class="fas fa-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        -->
    </div>
</div>

<div class="modal fade" id="modalProcessList" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-navy text-white">
                <h5 class="modal-title">Processos Encontrados</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Existem processos vinculados a este CPF. Selecione para abrir:</p>
                <div class="list-group" id="process_list_group"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalTemplate" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" onsubmit="event.preventDefault(); submitConfigForm(this, 'ajax_salvar_template')">
            <div class="modal-header"><h5 class="modal-title">Modelo de Texto / Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="acao" value="salvar_template">
                <input type="hidden" name="id_template" id="mt_id">
                <div class="mb-3"><label>Título</label><input class="form-control" name="titulo" id="mt_titulo" required></div>
                <div class="mb-3">
                    <label>Corpo do Texto (Use {<?= htmlspecialchars($ID_FIELD_KEY) ?>}, {Nome}, {STATUS}...)</label>
                    <textarea class="form-control" name="corpo" id="mt_corpo" rows="10" required></textarea>
                </div>
            </div>
            <div class="modal-footer"><button class="btn btn-navy">Salvar</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalField" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" onsubmit="event.preventDefault(); submitConfigForm(this, 'ajax_salvar_campo')">
            <div class="modal-header"><h5 class="modal-title">Configurar Campo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="acao" value="salvar_campo">
                <input type="hidden" name="arquivo_base" id="field_file">
                <input type="hidden" name="old_key" id="field_old_key">
                <div class="mb-3" id="div_field_label"><label>Nome (Label)</label><input class="form-control" name="label" id="field_label" required></div>
                <div class="mb-3" id="div_field_key"><label>Identificação / Chave (Coluna)</label><input class="form-control" name="key" id="field_key" required placeholder="Sem espaços"></div>
                <div class="mb-3" id="div_field_type">
                    <label>Tipo</label>
                    <select name="type" id="field_type" class="form-select" onchange="toggleFieldOptions(this.value)">
                        <option value="text">Texto</option>
                        <option value="number">Número</option>
                        <option value="date">Data</option>
                        <option value="datetime">Data e Hora</option>
                        <option value="money">Moeda</option>
                        <option value="select">Lista</option>
                        <option value="multiselect">Múltipla Escolha (Flag)</option>
                        <option value="textarea">Texto Longo</option>
                        <option value="title">Título/Seção</option>
                        <option value="custom">Personalizável</option>
                    </select>
                </div>
                <div class="mb-3" id="div_options" style="display:none">
                    <label>Opções (separadas por vírgula)</label>
                    <input class="form-control" name="options" id="field_options" placeholder="Ex: Sim, Não, Talvez">
                </div>
                <div id="div_custom_config" style="display:none">
                    <div class="mb-3">
                        <label>Máscara / Formato (Ex: 000.000.000-00, TEXTO-0000)</label>
                        <input class="form-control" name="custom_mask" id="field_custom_mask" placeholder="Use 0 para números, A para letras, * para qualquer">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Caixa do Texto</label>
                            <select name="custom_case" id="field_custom_case" class="form-select">
                                <option value="">Normal</option>
                                <option value="upper">Maiúsculas</option>
                                <option value="lower">Minúsculas</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Permitir</label>
                            <select name="custom_allowed" id="field_custom_allowed" class="form-select">
                                <option value="all">Tudo</option>
                                <option value="numbers">Apenas Números</option>
                                <option value="letters">Apenas Letras</option>
                                <option value="alphanumeric">Alfanumérico</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="form-check mb-3" id="div_field_required">
                    <input class="form-check-input" type="checkbox" name="required" id="field_required">
                    <label class="form-check-label" for="field_required">Campo Obrigatório</label>
                </div>
                <div class="form-check mb-3" id="div_field_is_primary_id">
                    <input class="form-check-input" type="checkbox" name="is_primary_id" id="field_is_primary_id">
                    <label class="form-check-label" for="field_is_primary_id"><strong>ID Principal do Sistema</strong></label>
                </div>
            <hr class="my-3">
            
            <div id="div_config_dashboard">
            <h6 class="text-navy small fw-bold mb-2">Configurações do Dashboard (Processos)</h6>
            <div class="row mb-3">
                <div class="col-6">
                    <div class="form-check" id="div_field_show_dashboard_column">
                        <input class="form-check-input" type="checkbox" name="show_dashboard_column" id="field_show_dashboard_column">
                        <label class="form-check-label" for="field_show_dashboard_column">Exibir Coluna</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check" id="div_field_show_dashboard_filter">
                        <input class="form-check-input" type="checkbox" name="show_dashboard_filter" id="field_show_dashboard_filter">
                        <label class="form-check-label" for="field_show_dashboard_filter">Exibir Filtro</label>
                    </div>
                </div>
            </div>
            </div>

            <div id="div_config_base">
            <h6 class="text-navy small fw-bold mb-2">Configurações da Base (Gestão de Bases)</h6>
            <div class="row mb-3">
                <div class="col-6">
                    <div class="form-check" id="div_field_show_base_column">
                        <input class="form-check-input" type="checkbox" name="show_base_column" id="field_show_base_column">
                        <label class="form-check-label" for="field_show_base_column">Exibir Coluna</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check" id="div_field_show_base_filter">
                        <input class="form-check-input" type="checkbox" name="show_base_filter" id="field_show_base_filter">
                        <label class="form-check-label" for="field_show_base_filter">Exibir Filtro</label>
                    </div>
                </div>
            </div>
            </div><div class="form-check mb-2" id="div_field_show_reminder">
                <input class="form-check-input" type="checkbox" name="show_reminder" id="field_show_reminder">
                <label class="form-check-label" for="field_show_reminder">Exibir em Lembretes</label>
            </div>
            </div>
            <div class="modal-footer"><button class="btn btn-navy">Salvar</button></div>
        </form>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none">
    <input type="hidden" name="acao" value="excluir_processo">
    <input type="hidden" name="id_exclusao" id="del_id">
</form>

<form id="deleteTemplateForm" method="POST" style="display:none">
    <input type="hidden" name="acao" value="excluir_template">
    <input type="hidden" name="id_exclusao" id="del_tpl_id">
</form>

<form id="deleteFieldForm" method="POST" style="display:none">
    <input type="hidden" name="acao" value="remover_campo">
    <input type="hidden" name="arquivo_base" id="del_field_file">
    <input type="hidden" name="key" id="del_field_key">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Global Constants for Identification Icon
    const _g_ident_icon = <?= json_encode($IDENT_ICON) ?>;
    const _g_ident_label = <?= json_encode($IDENT_LABEL) ?>;

    function validateElements(elements) {
        var firstInvalid = null;
        var isValid = true;
        
        elements.forEach(el => {
             el.classList.remove('is-invalid');
             
             var valid = true;
             
             // Standard Validity Check
             if (!el.checkValidity()) {
                 valid = false;
             }
             
             // Custom Data Required Check
             if (valid && el.dataset.required === 'true') {
                 if (el.type === 'checkbox' || el.type === 'radio') {
                     if (!el.checked) {
                         if (el.type === 'radio' || el.name.endsWith('[]')) {
                             // Check group
                             if (!document.querySelector('input[name="'+el.name+'"]:checked')) valid = false;
                         } else {
                             valid = false;
                         }
                     }
                 } else {
                     if (!el.value.trim()) valid = false;
                 }
             }
             
             if (!valid) {
                 el.classList.add('is-invalid');
                 
                 // Visual cue for Custom Multiselect Dropdown
                 var dropdown = el.closest('.dropdown');
                 if (dropdown) {
                     var btn = dropdown.querySelector('.dropdown-toggle');
                     if (btn) btn.classList.add('border-danger');
                 }
                 
                 if(isValid) firstInvalid = el;
                 isValid = false;
                 
                 var eventName = (el.type === 'checkbox' || el.type === 'radio' || el.tagName === 'SELECT') ? 'change' : 'input';
                 el.addEventListener(eventName, function() {
                     el.classList.remove('is-invalid');
                     var dd = el.closest('.dropdown');
                     if (dd) {
                         var b = dd.querySelector('.dropdown-toggle');
                         if (b) b.classList.remove('border-danger');
                     }
                 }, {once: true});
             }
        });
        
        if (firstInvalid) {
            // If hidden inside dropdown, try to scroll to dropdown button
            var container = firstInvalid.closest('.dropdown') || firstInvalid;
            container.scrollIntoView({behavior: 'smooth', block: 'center'});
            if(firstInvalid.offsetParent !== null) {
                firstInvalid.focus();
            }
        }
        
        return isValid;
    }

    const CURRENT_USER = "<?= $_SESSION['nome_completo'] ?? '' ?>";
    const ID_FIELD_KEY = "<?= $ID_FIELD_KEY ?>";
    var currentLoadedPort = null;
    var currentDashboardPage = 1;
    var currentDashSortCol = 'DATA';
    var currentDashSortDir = 'desc';
    var currentBaseSortCol = '';
    var currentBaseSortDir = '';

    function refreshCurrentView() {
        window.location.reload();
    }

    function setDashSort(col) {
        if (currentDashSortCol === col) {
            currentDashSortDir = (currentDashSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            currentDashSortCol = col;
            // Smart Defaults
            if (['DATA', '<?= htmlspecialchars($ID_FIELD_KEY) ?>', 'Ultima_Alteracao'].includes(col)) {
                currentDashSortDir = 'desc';
            } else {
                currentDashSortDir = 'asc';
            }
        }
        filterDashboard(null, currentDashboardPage);
        updateSortIcons('dash_table_head', currentDashSortCol, currentDashSortDir);
    }

    function setBaseSort(col) {
        if (currentBaseSortCol === col) {
            currentBaseSortDir = (currentBaseSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            currentBaseSortCol = col;
            // Smart Defaults
            if (['DATA', 'DATA_DEPOSITO', '<?= htmlspecialchars($ID_FIELD_KEY) ?>'].includes(col)) {
                currentBaseSortDir = 'desc';
            } else {
                currentBaseSortDir = 'asc';
            }
        }
        renderBaseTable(1);
    }

    function updateSortIcons(containerId, sortCol, sortDir) {
        var container = document.getElementById(containerId);
        if (!container) return;
        var headers = container.querySelectorAll('.sortable-header');
        headers.forEach(th => {
            var col = th.getAttribute('data-col');
            var iconContainer = th.querySelector('.sort-icon');
            if (iconContainer) {
                if (col === sortCol) {
                    iconContainer.innerHTML = (sortDir === 'asc') 
                        ? '<i class="fas fa-sort-up text-dark ms-1"></i>' 
                        : '<i class="fas fa-sort-down text-dark ms-1"></i>';
                } else {
                    iconContainer.innerHTML = '<i class="fas fa-sort text-muted ms-1" style="opacity:0.3"></i>';
                }
            }
        });
    }

    function setButtonLoading(btn, isLoading) {
        if (!btn) return;
        if (isLoading) {
            btn.dataset.originalHtml = btn.innerHTML;
            // Fix width to prevent collapse, but only if not already set (to handle multiple calls if needed)
            if (!btn.style.width) {
                 var w = btn.offsetWidth;
                 if (w > 0) btn.style.width = w + 'px';
            }
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        } else {
            btn.disabled = false;
            if (btn.dataset.originalHtml) {
                btn.innerHTML = btn.dataset.originalHtml;
            }
            btn.style.width = '';
        }
    }

    function processPaste(e) {
        e.preventDefault();
        var form = e.target;
        var btn = form.querySelector('button:not([type="button"])'); // Select submit button correctly
        
        if(btn) setButtonLoading(btn, true);
        
        var fd = new FormData(form);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.text())
        .then(html => {
             var parser = new DOMParser();
             var doc = parser.parseFromString(html, 'text/html');
             // Check for preview container
             var previewContainer = doc.getElementById('preview_table_container');
             // Also get the new page-base content to be safe (it contains the preview)
             var newPageBase = doc.getElementById('page-base');
             
             if (previewContainer && newPageBase) {
                 var modalEl = document.getElementById('modalPaste');
                 var modal = bootstrap.Modal.getInstance(modalEl);
                 if (!modal) {
                     modal = new bootstrap.Modal(modalEl);
                 }
                 modal.hide();
                 
                 var basePage = document.getElementById('page-base');
                 basePage.innerHTML = newPageBase.innerHTML;
                 basePage.style.display = ''; // Ensure visible
                 
                 // Re-attach form submit handler for the injected form
                 var newForms = basePage.querySelectorAll('form');
                 newForms.forEach(function(nf) {
                     nf.onsubmit = function(ev) {
                         handleFormSubmit(this);
                     };
                 });
                 
                 if(btn) setButtonLoading(btn, false);
             } else {
                 if(btn) setButtonLoading(btn, false);
                 // Try to find if there was a specific error message in a script tag or alert
                 // But for now, generic error.
                 Swal.fire('Erro', 'Não foi possível interpretar a resposta. Verifique os dados ou se houve erro no processamento.', 'error');
             }
        })
        .catch(err => {
            if(btn) setButtonLoading(btn, false);
            console.error(err);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    function handleFormSubmit(form) {
        var btn = form.querySelector('button[type="submit"]') || form.querySelector('button:not([type="button"])');
        if(btn) setButtonLoading(btn, true);
        showLoading();
    }

    function downloadLembretesExcel(btn) {
        setButtonLoading(btn, true);
        
        var form = document.getElementById('form_lembretes_filter');
        var fd = new FormData(form || undefined);
        var params = new URLSearchParams(fd);
        params.append('acao', 'exportar_lembretes_excel');
        
        fetch('?' + params.toString(), {
            method: 'GET'
        })
        .then(response => {
            if (response.ok) {
                return response.blob().then(blob => {
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    var filename = 'lembretes.xls';
                    var disposition = response.headers.get('Content-Disposition');
                    if (disposition && disposition.indexOf('attachment') !== -1) {
                        var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                        var matches = filenameRegex.exec(disposition);
                        if (matches != null && matches[1]) { 
                            filename = matches[1].replace(/['"]/g, '');
                        }
                    }
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                    setButtonLoading(btn, false);
                });
            } else {
                setButtonLoading(btn, false);
                Swal.fire('Erro', 'Falha ao baixar arquivo.', 'error');
            }
        })
        .catch(error => {
            setButtonLoading(btn, false);
            console.error('Download error:', error);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    function downloadExcel(btn) {
        setButtonLoading(btn, true);
        
        var form = document.getElementById('form_dashboard_filter');
        var fd = new FormData(form);
        var params = new URLSearchParams(fd);
        params.append('acao', 'exportar_excel');
        
        fetch('?' + params.toString(), {
            method: 'GET'
        })
        .then(response => {
            if (response.ok) {
                return response.blob().then(blob => {
                    var url = window.URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    // Try to get filename from header
                    var filename = 'relatorio.xls';
                    var disposition = response.headers.get('Content-Disposition');
                    if (disposition && disposition.indexOf('attachment') !== -1) {
                        var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                        var matches = filenameRegex.exec(disposition);
                        if (matches != null && matches[1]) { 
                            filename = matches[1].replace(/['"]/g, '');
                        }
                    }
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(url);
                    setButtonLoading(btn, false);
                });
            } else {
                setButtonLoading(btn, false);
                Swal.fire('Erro', 'Falha ao baixar arquivo.', 'error');
            }
        })
        .catch(error => {
            setButtonLoading(btn, false);
            console.error('Download error:', error);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    let loadingTimeout;
    function showLoading() { 
        var el = document.getElementById('loadingModal');
        var modal = bootstrap.Modal.getOrCreateInstance(el);
        modal.show();
        
        // Safety timeout (45 seconds)
        clearTimeout(loadingTimeout);
        loadingTimeout = setTimeout(() => {
            hideLoading();
            Swal.fire('Tempo Excedido', 'O processo está demorando muito ou o servidor não respondeu. Verifique se a ação foi concluída.', 'warning');
        }, 450000);
    }

    function hideLoading() {
        clearTimeout(loadingTimeout);
        
        var el = document.getElementById('loadingModal');
        var modal = bootstrap.Modal.getOrCreateInstance(el);
        if (modal) modal.hide();
        
        // Failsafe for stuck backdrops
        setTimeout(() => {
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            
            // Force hide modal element
            if(el) {
                el.classList.remove('show');
                el.style.display = 'none';
                el.setAttribute('aria-hidden', 'true');
                el.removeAttribute('aria-modal');
                el.removeAttribute('role');
            }
        }, 300);
    }
    function confirmClearIdentificacao() {
        Swal.fire({
            title: 'Tem certeza?',
            text: "Esta ação excluirá TODOS os dados da Base de Identificação. Não poderá ser desfeito!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#003366',
            confirmButtonText: 'Sim, limpar tudo!',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                showLoading();
                document.getElementById('limpar_base_target').value = 'Identificacao.json';
                document.getElementById('form_limpar_base').submit();
            }
        });
    }

    function reactivateField(file, key) {
        Swal.fire({
            title: 'Reativar Campo?', 
            text: 'Deseja reativar este campo na configuração?', 
            icon: 'question', 
            showCancelButton: true, 
            confirmButtonText: 'Sim, reativar!'
        }).then((r) => {
            if(r.isConfirmed) {
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_reactivate_field');
                fd.append('file', file);
                fd.append('key', key);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire({title: 'Sucesso', text: 'Campo reativado!', icon: 'success', timer: 1500, showConfirmButton: false});
                        
                        // DOM Manipulation to avoid reload
                        var container = document.querySelector('div[data-field-key="'+key+'"]');
                        if (container) {
                            container.classList.remove('d-none');
                            container.classList.remove('deleted-field-row');
                            
                            container.querySelectorAll('input, select, textarea').forEach(el => {
                                el.disabled = false;
                            });
                            
                            var btn = container.querySelector('button[onclick*="reactivateField"]');
                            if (btn) btn.remove();
                        }
                    } else {
                        Swal.fire('Erro', 'Falha ao reativar campo.', 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }

    function toggleSelectAll(source) {
        var checkboxes = document.querySelectorAll('.credit-checkbox, .base-checkbox');
        for(var i=0; i<checkboxes.length; i++) {
            checkboxes[i].checked = source.checked;
        }
    }

    function deleteSelectedCredits(btn) {
        var checkboxes = document.querySelectorAll('.credit-checkbox:checked');
        var ports = [];
        for(var i=0; i<checkboxes.length; i++) {
            ports.push(checkboxes[i].value);
        }

        if (ports.length === 0) {
            Swal.fire('Atenção', 'Selecione pelo menos um registro para excluir.', 'warning');
            return;
        }

        Swal.fire({
            title: 'Excluir ' + ports.length + ' registros?', 
            text: "Esta ação não pode ser desfeita.", 
            icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#003366', cancelButtonColor: '#d33', confirmButtonText: 'Sim, excluir todos!'
        }).then((result) => {
            if (result.isConfirmed) {
                if(btn) setButtonLoading(btn, true); else showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_credit_bulk');
                for(var i=0; i<ports.length; i++) {
                    fd.append('ports[]', ports[i]);
                }
                
                fetch('', { method: 'POST', body: fd })
                .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
                .then(res => {
                    if(btn) setButtonLoading(btn, false); else hideLoading();
                    
                    if (res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        if(document.getElementById('base_table')) renderBaseTable();
                        else filterCredits();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(err => {
                    if(btn) setButtonLoading(btn, false); else hideLoading();
                    Swal.fire('Erro', 'Falha na comunicação.', 'error');
                });
            }
        });
    }

    function submitPasteData(e) {
        e.preventDefault();
        showLoading();
        var form = document.getElementById('form_paste_data');
        var fd = new FormData(form);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                bootstrap.Modal.getInstance(document.getElementById('modalPaste')).hide();
                var html = '<div class="card card-custom p-4 mb-4 border-warning">' + 
                    '<h5 class="text-warning fw-bold mb-3"><i class="fas fa-eye me-2"></i>Pré-visualização da Importação</h5>' +
                    '<div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i> Verifique os dados abaixo antes de confirmar.</div>' +
                    '<div class="table-responsive" style="max-height: 400px; overflow-y: auto;">' + res.html + '</div>' +
                    '<div class="d-flex justify-content-end gap-2 mt-3">' +
                    '<button class="btn btn-outline-secondary" onclick="cancelUpload()">Cancelar</button>' +
                    '<button class="btn btn-primary" onclick="submitConfirmUpload()"><i class="fas fa-check me-1"></i> Confirmar Importação</button>' +
                    '</div></div>';
                document.getElementById('paste_preview_container').innerHTML = html;
                document.getElementById('paste_preview_container').scrollIntoView();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha ao processar.', 'error'); });
    }

    function submitConfirmUpload() {
        showLoading();
        var fd = new FormData();
        fd.append('acao', 'ajax_confirm_upload');
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                Swal.fire('Sucesso', res.message, 'success');
                document.getElementById('paste_preview_container').innerHTML = '';
                renderBaseTable();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha ao confirmar.', 'error'); });
    }

    function cancelUpload() {
        var fd = new FormData();
        fd.append('acao', 'ajax_cancel_upload');
        fetch('', { method: 'POST', body: fd });
        document.getElementById('paste_preview_container').innerHTML = '';
    }
    function confirmDelete(id) { 
        Swal.fire({
            title: 'Tem certeza?', text: "Deseja excluir este processo?", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#003366', cancelButtonColor: '#d33', confirmButtonText: 'Sim, excluir!'
        }).then((result) => {
            if (result.isConfirmed) { 
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_excluir_processo');
                fd.append('id_exclusao', id);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        if(document.getElementById('page-dashboard').style.display !== 'none') {
                            filterDashboard(null, currentDashboardPage);
                        } else if(document.getElementById('page-detalhes').style.display !== 'none') {
                            goBack();
                        } else if(document.getElementById('page-base').style.display !== 'none') {
                            renderBaseTable(1);
                        }
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }
    function confirmTemplateDelete(id) {
        Swal.fire({
            title: 'Tem certeza?', text: "Deseja excluir este modelo?", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#003366', cancelButtonColor: '#d33', confirmButtonText: 'Sim, excluir!'
        }).then((result) => {
            if (result.isConfirmed) { 
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_excluir_template');
                fd.append('id_exclusao', id);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        refreshConfigView();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }
    function toggleFieldOptions(type) {
        document.getElementById('div_options').style.display = (type == 'select' || type == 'multiselect') ? 'block' : 'none';
        document.getElementById('div_custom_config').style.display = (type == 'custom') ? 'block' : 'none';
    }

    function addFieldModal(file) { 
        document.getElementById('field_file').value = file; 
        document.getElementById('field_old_key').value = ''; 
        document.getElementById('field_label').value = ''; 
        document.getElementById('field_key').value = ''; 
        document.getElementById('field_key').readOnly = false; 
        document.getElementById('field_type').value = 'text'; 
        
        document.getElementById('div_field_key').style.display = 'block';
        document.getElementById('div_field_type').style.display = 'block';
        document.getElementById('div_field_required').style.display = 'block';
        document.getElementById('div_field_is_primary_id').style.display = (file === 'Base_processos_schema') ? 'block' : 'none';
        
        // Reset config sections visibility
        if(document.getElementById('div_config_dashboard')) document.getElementById('div_config_dashboard').style.display = 'block';
        if(document.getElementById('div_config_base')) document.getElementById('div_config_base').style.display = 'block';
        document.getElementById('div_field_show_reminder').style.display = 'block';
        document.querySelector('#modalField .modal-title').innerText = 'Adicionar Campo';
        
        document.getElementById('field_options').value = '';
        document.getElementById('field_custom_mask').value = '';
        document.getElementById('field_custom_case').value = '';
        document.getElementById('field_custom_allowed').value = 'all';
        
        toggleFieldOptions('text');
        
        document.getElementById('field_required').checked = false;
        document.getElementById('field_is_primary_id').checked = false;
        document.getElementById('field_show_reminder').checked = false;
        
        document.getElementById('field_show_dashboard_column').checked = false;
        document.getElementById('field_show_dashboard_filter').checked = false;
        document.getElementById('field_show_base_column').checked = false;
        document.getElementById('field_show_base_filter').checked = false;
        new bootstrap.Modal(document.getElementById('modalField')).show(); 
    }

    function addTitleModal(file) {
        document.getElementById('field_file').value = file;
        document.getElementById('field_old_key').value = '';
        document.getElementById('field_label').value = '';
        
        var key = 'TITLE_' + Date.now();
        document.getElementById('field_key').value = key;
        document.getElementById('field_key').readOnly = false; // Allow renaming 
        
        document.getElementById('field_type').value = 'title';
        
        document.getElementById('div_field_key').style.display = 'none';
        document.getElementById('div_field_type').style.display = 'none';
        document.getElementById('div_field_required').style.display = 'none';
        document.getElementById('div_field_is_primary_id').style.display = 'none';
        // Hide config sections for Title
        if(document.getElementById('div_config_dashboard')) document.getElementById('div_config_dashboard').style.display = 'none';
        if(document.getElementById('div_config_base')) document.getElementById('div_config_base').style.display = 'none';
        document.getElementById('div_field_show_reminder').style.display = 'none';
        toggleFieldOptions('title');
        
        document.querySelector('#modalField .modal-title').innerText = 'Adicionar Título';
        
        new bootstrap.Modal(document.getElementById('modalField')).show();
    }

    function editField(f, file) { 
        document.getElementById('field_file').value = file; 
        document.getElementById('field_old_key').value = f.key; 
        document.getElementById('field_label').value = f.label; 
        document.getElementById('field_key').value = f.key; 
        document.getElementById('field_key').readOnly = false; // Allow renaming 
        document.getElementById('field_type').value = f.type; 
        document.getElementById('field_options').value = f.options || '';
        
        document.getElementById('field_custom_mask').value = f.custom_mask || '';
        document.getElementById('field_custom_case').value = f.custom_case || '';
        document.getElementById('field_custom_allowed').value = f.custom_allowed || 'all';

        var isTitle = (f.type === 'title');
        
        document.getElementById('div_field_key').style.display = isTitle ? 'none' : 'block';
        document.getElementById('div_field_type').style.display = isTitle ? 'none' : 'block';
        document.getElementById('div_field_required').style.display = isTitle ? 'none' : 'block';
        document.getElementById('div_field_is_primary_id').style.display = (file === 'Base_processos_schema' && !isTitle) ? 'block' : 'none';
        // Toggle config sections
        if(document.getElementById('div_config_dashboard')) document.getElementById('div_config_dashboard').style.display = isTitle ? 'none' : 'block';
        if(document.getElementById('div_config_base')) document.getElementById('div_config_base').style.display = isTitle ? 'none' : 'block';
        document.getElementById('div_field_show_reminder').style.display = isTitle ? 'none' : 'block';
        
        toggleFieldOptions(f.type);
        
        document.querySelector('#modalField .modal-title').innerText = isTitle ? 'Editar Título' : 'Configurar Campo';
        
        document.getElementById('field_required').checked = (f.required === true || f.required === "true");
        document.getElementById('field_is_primary_id').checked = (f.key === ID_FIELD_KEY);
        document.getElementById('field_show_reminder').checked = (f.show_reminder === true || f.show_reminder === "true");
        
        // Fallback Logic for Legacy
        var showDashCol = (f.show_dashboard_column !== undefined) ? (f.show_dashboard_column === true || f.show_dashboard_column === "true") : (f.show_column === true || f.show_column === "true");
        var showDashFil = (f.show_dashboard_filter !== undefined) ? (f.show_dashboard_filter === true || f.show_dashboard_filter === "true") : (f.show_filter === true || f.show_filter === "true");
        var showBaseCol = (f.show_base_column !== undefined) ? (f.show_base_column === true || f.show_base_column === "true") : (f.show_column === true || f.show_column === "true");
        var showBaseFil = (f.show_base_filter !== undefined) ? (f.show_base_filter === true || f.show_base_filter === "true") : (f.show_filter === true || f.show_filter === "true");

        document.getElementById('field_show_dashboard_column').checked = showDashCol;
        document.getElementById('field_show_dashboard_filter').checked = showDashFil;
        document.getElementById('field_show_base_column').checked = showBaseCol;
        document.getElementById('field_show_base_filter').checked = showBaseFil;
        new bootstrap.Modal(document.getElementById('modalField')).show(); 
    }
    function removeField(file, key) { 
        Swal.fire({ title: 'Remover Campo?', text: 'Deseja remover este campo?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33', confirmButtonText: 'Sim, remover!' }).then((r) => {
            if(r.isConfirmed) { 
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_remover_campo');
                fd.append('arquivo_base', file);
                fd.append('key', key);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        refreshConfigView();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }

    function refreshConfigView() {
        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_render_config' })
        .then(r => r.json())
        .then(res => {
            if(res.status == 'ok') {
                var container = document.getElementById('page-config');
                if(container) {
                    container.innerHTML = res.html;
                    var elList = document.querySelectorAll('.sortable-list');
                    elList.forEach(function(el) { 
                        new Sortable(el, { 
                            group: { name: 'fields_group', pull: true, put: true },
                            handle: '.handle', 
                            animation: 150, 
                            onEnd: function (evt) { 
                                if (evt.to === evt.from) {
                                    var file = el.getAttribute('data-file'); 
                                    var order = []; 
                                    el.querySelectorAll('li').forEach(li => order.push(li.getAttribute('data-key'))); 
                                    fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_reorder_fields&file=' + file + '&order[]=' + order.join('&order[]=') }); 
                                }
                            },
                            onAdd: function(evt) {
                                var targetFile = evt.to.getAttribute('data-file');
                                var sourceFile = evt.from.getAttribute('data-file');
                                var itemKey = evt.item.getAttribute('data-key');
                                var fd = new FormData();
                                fd.append('acao', 'ajax_copy_field');
                                fd.append('source_file', sourceFile);
                                fd.append('target_file', targetFile);
                                fd.append('key', itemKey);
                                fetch('', { method: 'POST', body: fd })
                                .then(r => r.json())
                                .then(res => {
                                    if(res.status === 'ok') {
                                        Swal.fire({ icon: 'success', title: 'Campo copiado!', showConfirmButton: false, timer: 1000 });
                                        refreshConfigView();
                                    } else {
                                        Swal.fire('Erro', res.message, 'error');
                                        evt.item.remove();
                                    }
                                });
                            }
                        }); 
                    });
                }
            }
        });
    }

    function submitConfigForm(form, action) {
        var btn = form.querySelector('button:not([type="button"])');
        setButtonLoading(btn, true);
        
        var fd = new FormData(form);
        fd.set('acao', action);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                Swal.fire('Sucesso', res.message, 'success');
                var modalEl = form.closest('.modal');
                var modal = bootstrap.Modal.getInstance(modalEl);
                if(modal) modal.hide();
                refreshConfigView();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function clearForm() {
        document.getElementById('form_processo').reset();
        isDirty = false;
    }
    
    // AJAX Functions
    function toggleLoading(btnId, show) {
        var btn = document.getElementById(btnId);
        if (!btn) return;
        var icon = btn.querySelector('.fa-search');
        var spinner = btn.querySelector('.spinner-border');
        if (show) {
            icon.classList.add('d-none');
            spinner.classList.remove('d-none');
            btn.disabled = true;
        } else {
            icon.classList.remove('d-none');
            spinner.classList.add('d-none');
            btn.disabled = false;
        }
    }

    function searchClient() {
        var cpf = document.getElementById('cli_cpf').value;
        if(!cpf) return;
        
        toggleLoading('btn_search_cpf', true);
        
        fetch('', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=ajax_search_client&cpf=' + encodeURIComponent(cpf)
        })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        })
        .then(res => {
            if(res.found) {
                if(document.getElementById('cli_nome')) document.getElementById('cli_nome').value = res.data.Nome;
                for(var k in res.data) {
                    var el = document.querySelector('input[name="client_' + k + '"]');
                    if(el) el.value = res.data[k];
                }
                checkCPFProcesses(cpf); // Chain call
            } else {
                Swal.fire({
                    icon: 'info',
                    title: 'Cliente não encontrado',
                    text: 'Preencha os dados para criar um novo registro.',
                    timer: 3000,
                    showConfirmButton: false
                });
                toggleLoading('btn_search_cpf', false);
            }
        })
        .catch(() => toggleLoading('btn_search_cpf', false));
    }

    function renderProcessList(processes) {
        var list = document.getElementById('process_list_group');
        list.innerHTML = '';
        processes.forEach(p => {
            var item = document.createElement('a');
            item.href = '?p=detalhes&id=' + encodeURIComponent(p.port);
            item.className = 'list-group-item list-group-item-action d-flex justify-content-between align-items-center';
            
            var div = document.createElement('div');
            
            var strong = document.createElement('strong');
            strong.textContent = 'Port: ' + p.port;
            div.appendChild(strong);
            
            div.appendChild(document.createElement('br'));
            
            var small = document.createElement('small');
            small.textContent = (p.data || '');
            div.appendChild(small);
            
            item.appendChild(div);
            
            var span = document.createElement('span');
            var badgeClass = 'bg-secondary';
            
            if (p.source && p.source == 'Identificacao') {
                badgeClass = 'bg-info text-dark';
                var icon = document.createElement('i');
                icon.className = 'fas fa-coins me-1';
                span.appendChild(icon);
            }
            
            span.className = 'badge ' + badgeClass;
            span.appendChild(document.createTextNode(p.status || ''));
            
            item.appendChild(span);
            list.appendChild(item);
        });
        new bootstrap.Modal(document.getElementById('modalProcessList')).show();
    }

    function checkCPFProcesses(cpf) {
        // Assume this is part of client search flow, so we stop loading here
        fetch('', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=ajax_check_cpf_processes&cpf=' + encodeURIComponent(cpf)
        })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        })
        .then(res => {
            toggleLoading('btn_search_cpf', false);
            if(res.found) {
               renderProcessList(res.processes);
            }
        })
        .catch(() => toggleLoading('btn_search_cpf', false));
    }

    function searchAgency() {
        var ag = document.getElementById('ag_code').value;
        if(!ag) return;
        
        toggleLoading('btn_search_ag', true);
        
        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_search_agency&ag=' + encodeURIComponent(ag) })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        }) 
        .then(res => { 
            toggleLoading('btn_search_ag', false);
            
            if(res.found) { 
                var agencyFields = ['UF', 'SR', 'NOME_SR', 'FILIAL', 'E-MAIL_AG', 'E-MAILS_SR', 'E-MAILS_FILIAL', 'E-MAIL_GERENTE'];
                
                agencyFields.forEach(function(k) {
                    var dataKey = Object.keys(res.data).find(dk => dk.toUpperCase() === k.toUpperCase());
                    if (dataKey) {
                        var el = document.getElementById('proc_' + k);
                        if (!el) {
                             el = document.getElementById('proc_' + k.toUpperCase());
                        }
                        
                        if(el) {
                            el.value = res.data[dataKey];
                            el.dispatchEvent(new Event('change'));
                        }
                    }
                });
                
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'success',
                    title: 'Dados da Agência carregados',
                    showConfirmButton: false,
                    timer: 3000
                });
            } else {
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: 'Agência não encontrada na base',
                    showConfirmButton: false,
                    timer: 3000
                });
            }
        })
        .catch(() => toggleLoading('btn_search_ag', false));
    }

    function checkProcess() {
        var val = document.getElementById('proc_port').value;
        if(!val) return;
        
        var urlId = new URLSearchParams(window.location.search).get('id');
        // if(val == urlId) return; // Removed to allow re-search

        toggleLoading('btn_search_port', true);

        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_check_process&port=' + encodeURIComponent(val) })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        }) 
        .then(res => { 
            toggleLoading('btn_search_port', false);
            if(res.found) { 
                Swal.fire({title: 'Processo encontrado!', text: 'Deseja carregar?', showCancelButton: true})
                .then(r=>{ if(r.isConfirmed) { isDirty = false; loadProcess(res.port); } }); 
            } else {
                // If credit data found (whether process exists or not, but here process doesn't exist)
                if (res.credit_data) {
                    var c = res.credit_data;
                    
                    // Show Credit Card Dynamically
                    var cardHtml = '<div class="card card-custom p-4 mb-4 border-warning border-3" id="dyn_credit_card">' + 
                        '<h5 class="text-warning fw-bold border-bottom pb-2"><i class="<?= htmlspecialchars($IDENT_ICON) ?> me-2"></i><?= htmlspecialchars($IDENT_LABEL) ?></h5>' +
                        '<div class="row g-3">';
                    for (var k in c) {
                        cardHtml += '<div class="col-md-3"><label class="small text-muted">' + k + '</label><div class="fw-bold">' + (c[k] || '-') + '</div></div>';
                    }
                    cardHtml += '</div></div>';
                    
                    // Insert after Agência card (which is the 3rd card-custom in form)
                    // Or just find where to insert. Let's look for .card-custom inside #form_processo
                    var cards = document.querySelectorAll('#form_processo .card-custom');
                    if(cards.length > 0) {
                        var lastCard = cards[cards.length - 1]; // Usually Agency or orphaned
                        // Insert after the last card
                        lastCard.insertAdjacentHTML('afterend', cardHtml);
                    }

                    Swal.fire({
                        title: 'Dados na Base de Identificação',
                        text: 'Processo não cadastrado, porém existem dados na Base de Identificação. Deseja realizar o autopreenchimento?',
                        icon: 'info',
                        showCancelButton: true,
                        confirmButtonText: 'Sim, preencher!',
                        cancelButtonText: 'Não'
                    }).then((r) => {
                        if (r.isConfirmed) {
                            // Auto-fill Logic
                            if(document.getElementById('proc_VALOR DA PORTABILIDADE')) document.getElementById('proc_VALOR DA PORTABILIDADE').value = c.VALOR_DEPOSITO_PRINCIPAL || '';
                            if(document.getElementById('proc_VALOR DA PORTABILIDADE')) document.getElementById('proc_VALOR DA PORTABILIDADE').dispatchEvent(new Event('input'));

                            // Support both keys
                            if(document.getElementById('proc_Certificado')) document.getElementById('proc_Certificado').value = c.Certificado || c.CERTIFICADO || '';
                            
                            if (c.CPF) {
                                if(document.getElementById('cli_cpf')) {
                                    document.getElementById('cli_cpf').value = c.CPF;
                                    searchClient();
                                }
                            }
                            
                            if (c.AG) {
                                if(document.getElementById('ag_code')) {
                                    document.getElementById('ag_code').value = c.AG;
                                    searchAgency();
                                }
                            }
                            
                            Swal.fire('Preenchido', 'Dados importados da Base de Identificação.', 'success');
                        }
                    });
                } else {
                     Swal.fire('Novo Processo', 'Número não cadastrado. Preencha os dados.', 'info');
                }
            }
        })
        .catch(() => toggleLoading('btn_search_port', false));
    }

    function checkCert() {
        var val = document.getElementById('proc_cert').value;
        if(!val) return;
        
        toggleLoading('btn_search_cert', true);
        
        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_check_cert&cert=' + encodeURIComponent(val) })
        .then(async r => {
            try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); }
        }) 
        .then(res => { 
            toggleLoading('btn_search_cert', false);
            if(res.found) { 
                Swal.fire({
                    title: 'Certificado Vinculado!',
                    text: `Este certificado já consta em ${res.count} processo(s).`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#003366',
                    cancelButtonColor: '#28a745',
                    confirmButtonText: 'Ver Existentes',
                    cancelButtonText: 'Criar Novo'
                }).then((result) => {
                    if (result.isConfirmed) {
                        renderProcessList(res.processes);
                    }
                });
            } else {
                Swal.fire('Disponível', 'Certificado não encontrado. Pode prosseguir.', 'success');
            }
        })
        .catch(() => toggleLoading('btn_search_cert', false));
    }

    // Persistence Logic
    document.addEventListener("DOMContentLoaded", function() {
        const form = document.getElementById('form_processo');
        if (form) {
            const urlParams = new URLSearchParams(window.location.search);
            const isNew = !urlParams.get('id');
            const storageKey = 'draft_processo';

            // SAFETY: If we are viewing an EXISTING process (Edit Mode), strictly CLEAR the draft.
            // This prevents "leaking" data from a previous "New Process" attempt or confusion if the user navigates back and forth.
            if (!isNew) {
                sessionStorage.removeItem(storageKey);
            }

            // Restore (Only if New)
            if (isNew) {
                const draft = sessionStorage.getItem(storageKey);
                if (draft) {
                    const data = JSON.parse(draft);
                    for (const key in data) {
                        const el = form.elements[key];
                        if (el && (el.type !== 'hidden' || key === 'acao')) {
                             // Handle checkboxes/radios if needed, but simple value works for most
                             if (el.type === 'checkbox' || el.type === 'radio') {
                                 // Simple restore for now
                             } else {
                                 el.value = data[key];
                             }
                        }
                    }
                }
            }

            // Save on change (Only if New)
            form.addEventListener('input', function() {
                if (isNew) { 
                    const data = {};
                    new FormData(form).forEach((value, key) => data[key] = value);
                    sessionStorage.setItem(storageKey, JSON.stringify(data));
                }
            });
            
        }
        
        // Voltar Button Logic - Removed Draft Clearing to preserve state
    });

    function modalTemplate() { document.getElementById('mt_id').value = ''; document.getElementById('mt_titulo').value = ''; document.getElementById('mt_corpo').value = ''; new bootstrap.Modal(document.getElementById('modalTemplate')).show(); }
    function editTemplate(t) { document.getElementById('mt_id').value = t.id; document.getElementById('mt_titulo').value = t.titulo; document.getElementById('mt_corpo').value = t.corpo; new bootstrap.Modal(document.getElementById('modalTemplate')).show(); }

    function generateText() {
        // Collect selected template IDs
        var selectedTpls = [];
        document.querySelectorAll('.tpl-checkbox:checked').forEach(cb => selectedTpls.push(cb.value));
        
        if(selectedTpls.length === 0) {
            // Clear text if none selected
            // document.getElementById('tpl_result').value = ''; 
            return; 
        }

        var data = {};
        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea').forEach(el => {
            if(el.name) {
                var k = el.name.replace('client_', '').replace('agency_', '').replace('reg_new_', '').replace('reg_', '');
                data[k] = el.value;
            }
        });
        
        // Accumulate text
        // Note: This implementation generates text sequentially.
        // To be safe, we clear first? Or append? User said "Multiplas escolhas", usually implies composition.
        // Let's clear and rebuild.
        var finalBody = "";
        var processedCount = 0;

        selectedTpls.forEach(tplId => {
            fetch('', {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'acao=ajax_generate_text&tpl_id=' + tplId + '&data=' + encodeURIComponent(JSON.stringify(data))
            })
            .then(r => r.json())
            .then(res => { 
                if(res.status == 'ok') {
                    finalBody += res.text + "\n\n";
                }
                processedCount++;
                if (processedCount === selectedTpls.length) {
                    document.getElementById('tpl_result').value = finalBody;
                }
            });
        });
    }

    function copyToClipboard() {
        var copyText = document.getElementById("tpl_result");
        copyText.select();
        copyText.setSelectionRange(0, 99999); // For mobile devices
        
        // Try modern API first, fallback to execCommand
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(copyText.value).then(() => {
                Swal.fire('Copiado!', 'Texto copiado para a área de transferência.', 'success');
            }).catch(err => {
                fallbackCopy(copyText);
            });
        } else {
            fallbackCopy(copyText);
        }
    }

    function fallbackCopy(textElement) {
        try {
            document.execCommand('copy');
            Swal.fire('Copiado!', 'Texto copiado para a área de transferência.', 'success');
        } catch (err) {
            Swal.fire('Erro', 'Não foi possível copiar o texto automaticamente.', 'error');
        }
    }

    function saveHistory(btn) {
        var cpf = document.getElementById('cli_cpf') ? document.getElementById('cli_cpf').value : '';
        var nome = document.getElementById('cli_nome') ? document.getElementById('cli_nome').value : '';
        var port = document.getElementById('proc_port') ? document.getElementById('proc_port').value : '';
        
        // Collect Metadata
        var selectedInfo = [];
        var titles = [];
        var themes = [];
        
        document.querySelectorAll('.tpl-checkbox:checked').forEach(cb => {
            let tid = cb.value;
            let t = allTemplates.find(x => x.id == tid);
            if(t) {
                let listName = 'Sem Categoria';
                if(t.list_id) {
                    let l = allLists.find(x => x.id == t.list_id);
                    if(l) listName = l.name;
                }
                selectedInfo.push(`Lista: ${listName} - Tema: ${t.titulo}`);
                titles.push(listName);
                 themes.push(t.titulo);
            } else {
                let label = document.querySelector('label[for="' + cb.id + '"]').innerText;
                selectedInfo.push(label.trim());
                titles.push('-');
                themes.push(label.trim());
            }
        });
        
        var metaString = selectedInfo.join('; ');
        var textToSave = metaString; 
        
        // Prepare UI columns
        var colTitulo = [...new Set(titles)].join('; '); // Unique titles
        var colTema = themes.join('; ');

        setButtonLoading(btn, true);

        fetch('', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=ajax_save_history&cliente='+encodeURIComponent(nome)+'&cpf='+encodeURIComponent(cpf)+'&port='+encodeURIComponent(port)+'&modelo='+encodeURIComponent(metaString)+'&texto='+encodeURIComponent(textToSave)+'&destinatarios='
        })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            setButtonLoading(btn, false);

            if(res.status == 'ok') { 
                Swal.fire('Sucesso', 'Envio Registrado!', 'success');
                
                
                var tbody = document.getElementById('history_table_body');
                var row = document.createElement('tr');
                // Display separate columns with checkbox
                row.innerHTML = '<td><input type="checkbox" name="hist_del[]" class="form-check-input" value="'+res.id+'"></td><td>' + res.data + '</td><td>' + res.usuario + '</td><td>' + colTitulo + '</td><td>' + colTema + '</td>';
                
                if (tbody.firstChild) {
                    tbody.insertBefore(row, tbody.firstChild);
                } else {
                    tbody.appendChild(row);
                }
            }
        })
        .catch(err => {
            setButtonLoading(btn, false);
            console.error(err);
            Swal.fire('Erro', 'Falha ao registrar envio.', 'error');
        });
    }

    function clearSelection() {
        // Uncheck all
        document.querySelectorAll('.tpl-checkbox').forEach(cb => cb.checked = false);
        // Clear Textarea
        document.getElementById('tpl_result').value = '';
        // Close Accordions using Bootstrap API
        document.querySelectorAll('.accordion-collapse').forEach(el => {
            var bsCollapse = bootstrap.Collapse.getInstance(el);
            if(bsCollapse) bsCollapse.hide();
            else el.classList.remove('show');
        });
        // Reset classes/state
        document.querySelectorAll('.accordion-button').forEach(btn => btn.classList.add('collapsed'));
        
        generateText(); // Helper to clear valid state
    }

    function submitProcessForm(btn) {
        var form = document.getElementById('form_processo');
        if (!validateElements(form.querySelectorAll('input, select, textarea'))) return;

        var inputPort = document.getElementById('proc_port') ? document.getElementById('proc_port').value : '';

        var doSubmit = function() {
            setButtonLoading(btn, true);

            var formData = new FormData(form);
            formData.set('acao', 'ajax_salvar_processo');
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
            .then(res => {
                setButtonLoading(btn, false);

                if (res.status == 'ok') {
                    sessionStorage.removeItem('draft_processo');
                    Swal.fire('Sucesso', res.message, 'success');
                    isDirty = false;
                    currentLoadedPort = inputPort;
                    updateEmailListFromForm();
                } else {
                    Swal.fire('Erro', res.message, 'error');
                }
            })
            .catch(err => {
                setButtonLoading(btn, false);
                Swal.fire('Erro', 'Falha na comunicação.', 'error');
            });
        };

        if (inputPort && inputPort !== currentLoadedPort) {
             setButtonLoading(btn, true);
             fetch('', { 
                 method: 'POST', 
                 headers: {'Content-Type': 'application/x-www-form-urlencoded'}, 
                 body: 'acao=ajax_check_process&port=' + encodeURIComponent(inputPort) 
             })
             .then(r => r.json())
             .then(res => {
                 setButtonLoading(btn, false);
                 
                 if (res.found) {
                     Swal.fire({
                        title: 'Processo já cadastrado',
                        text: 'O registro de nº ' + inputPort + ' já está cadastrado. Deseja atualizar ou cancelar?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#003366',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'Atualizar',
                        cancelButtonText: 'Cancelar'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            doSubmit();
                        }
                    });
                 } else {
                     doSubmit();
                 }
             })
             .catch(() => {
                 setButtonLoading(btn, false);
                 Swal.fire('Erro', 'Falha ao verificar duplicidade.', 'error');
             });
        } else {
            doSubmit();
        }
    }
    
    // Dirty Form Logic
    var isDirty = false;
    
    // Sortable
    document.addEventListener("DOMContentLoaded", function() {
        // Auto-fill check on load
        var autoFillCredit = <?= $autoFillData ?? 'null' ?>;
        if (autoFillCredit) {
            Swal.fire({
                title: 'Dados na Base de Identificação',
                text: 'Processo não cadastrado, porém existem dados na Base de Identificação. Deseja realizar o autopreenchimento?',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Sim, preencher!',
                cancelButtonText: 'Não'
            }).then((r) => {
                if (r.isConfirmed) {
                    var c = autoFillCredit;
                    if(document.getElementById('proc_VALOR DA PORTABILIDADE')) document.getElementById('proc_VALOR DA PORTABILIDADE').value = c.VALOR_DEPOSITO_PRINCIPAL || '';
                    if(document.getElementById('proc_VALOR DA PORTABILIDADE')) document.getElementById('proc_VALOR DA PORTABILIDADE').dispatchEvent(new Event('input'));
                    
                    // Support both keys
                    if(document.getElementById('proc_Certificado')) document.getElementById('proc_Certificado').value = c.Certificado || c.CERTIFICADO || '';
                    
                    if (c.CPF) {
                        if(document.getElementById('cli_cpf')) {
                             document.getElementById('cli_cpf').value = c.CPF;
                             searchClient();
                        }
                    }
                    if (c.AG) {
                        if(document.getElementById('ag_code')) {
                             document.getElementById('ag_code').value = c.AG;
                             searchAgency();
                        }
                    }
                }
            });
        }

        var elList = document.querySelectorAll('.sortable-list');
        elList.forEach(function(el) { 
            new Sortable(el, { 
                group: { name: 'fields_group', pull: true, put: true },
                handle: '.handle', 
                animation: 150, 
                onEnd: function (evt) { 
                    if (evt.to === evt.from) {
                        var file = el.getAttribute('data-file'); 
                        var order = []; 
                        el.querySelectorAll('li').forEach(li => order.push(li.getAttribute('data-key'))); 
                        fetch('', { method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: 'acao=ajax_reorder_fields&file=' + file + '&order[]=' + order.join('&order[]=') }); 
                    }
                },
                onAdd: function(evt) {
                    var targetFile = evt.to.getAttribute('data-file');
                    var sourceFile = evt.from.getAttribute('data-file');
                    var itemKey = evt.item.getAttribute('data-key');
                    var fd = new FormData();
                    fd.append('acao', 'ajax_copy_field');
                    fd.append('source_file', sourceFile);
                    fd.append('target_file', targetFile);
                    fd.append('key', itemKey);
                    fetch('', { method: 'POST', body: fd })
                    .then(r => r.json())
                    .then(res => {
                        if(res.status === 'ok') {
                            Swal.fire({ icon: 'success', title: 'Campo copiado!', showConfirmButton: false, timer: 1000 });
                            refreshConfigView();
                        } else {
                            Swal.fire('Erro', res.message, 'error');
                            evt.item.remove();
                        }
                    });
                }
            }); 
        });
        
        // Dirty Form Detection
        var form = document.getElementById('form_processo');
        if (form) {
            form.addEventListener('change', function() { isDirty = true; });
            form.addEventListener('input', function() { isDirty = true; });
            form.addEventListener('submit', function() { isDirty = false; });
        }
        
        // Intercept internal links for styled alert
        document.body.addEventListener('click', function(e) {
            if (!isDirty) return;
            var target = e.target.closest('a');
            if (target && target.getAttribute('href') && !target.getAttribute('href').startsWith('#') && !target.getAttribute('target') && !target.getAttribute('href').startsWith('javascript')) {
                e.preventDefault();
                Swal.fire({
                    title: 'Alterações não salvas',
                    text: "Você tem alterações pendentes. Se sair agora, elas serão perdidas.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#003366',
                    confirmButtonText: 'Sair sem salvar',
                    cancelButtonText: 'Continuar editando'
                }).then((result) => {
                    if (result.isConfirmed) {
                        isDirty = false; // Prevent beforeunload
                        window.location.href = target.href;
                    }
                });
            }
        });

        window.addEventListener('beforeunload', function(e) {
            if (isDirty) {
                var confirmationMessage = 'Você tem alterações não salvas. Deseja realmente sair?';
                e.returnValue = confirmationMessage; // Geeky legacy way
                return confirmationMessage;
            }
        });

        // Money Mask
        const moneyInputs = document.querySelectorAll('.money-mask');
        moneyInputs.forEach(input => {
            input.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');
                value = (value / 100).toFixed(2) + '';
                value = value.replace('.', ',');
                value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
                e.target.value = 'R$ ' + value;
            });
            // Initial format if value exists
            if(input.value && !input.value.includes('R$')) {
                 // Try to parse existing "R$ 1.000,00" or raw "1000.00"
                 // If it's already "R$ ...", leave it. If it's plain, format it.
                 // Actually, if coming from DB, it might be raw string. 
                 // Simple init trigger
                 // let evt = new Event('input'); input.dispatchEvent(evt); 
                 // But wait, the value might be 'R$ 40.149,48' already. 
            }
        });

        // Locking Logic
        const processId = new URLSearchParams(window.location.search).get('id');
        const isLocked = <?= (isset($lockInfo) && $lockInfo && $lockInfo['locked']) ? 'true' : 'false' ?>;
        
        if (processId && !isLocked) {
            startLocking(processId);

            // Release on exit
            window.addEventListener('beforeunload', function() {
                releaseCurrentLock();
            });
        } else if (isLocked) {
            // Disable form inputs
            document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = true);
        }
    });

    var lockInterval;
    var currentLockPort = null;

    function releaseCurrentLock() {
         var promise = Promise.resolve();
         if (currentLockPort) {
             promise = fetch('', {
                 method: 'POST',
                 headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                 body: new URLSearchParams({
                     'acao': 'ajax_release_lock',
                     'port': currentLockPort
                 }),
                 keepalive: true
             }).catch(err => console.error(err));
             currentLockPort = null;
         }
         if (lockInterval) clearInterval(lockInterval);
         return promise;
    }

    function startLocking(port) {
        releaseCurrentLock(); // Release previous if any
        currentLockPort = port;
        
        const acquireLock = () => {
            fetch('', {
                method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'acao=ajax_acquire_lock&port=' + encodeURIComponent(port)
            }).then(r => r.json()).then(res => {
                if (!res.success) {
                    clearInterval(lockInterval);
                    currentLockPort = null;
                    Swal.fire('Bloqueado', 'Este processo acabou de ser bloqueado por ' + res.locked_by, 'error')
                    .then(() => {
                        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = true);
                    });
                }
            });
        };
        acquireLock();
        lockInterval = setInterval(acquireLock, 30000); 
    }

    function openCreditModal(data) {
        document.getElementById('form_credit').reset();
        if (data) {
             var identField = '<?= htmlspecialchars($IDENT_ID_FIELD) ?>';
             document.getElementById('cred_original_port').value = data[identField] || '';
             document.getElementById('cred_' + identField).value = data[identField] || '';
             document.getElementById('cred_STATUS').value = data.STATUS || '';
             document.getElementById('cred_NUMERO_DEPOSITO').value = data.NUMERO_DEPOSITO || '';
             
             // Date Handling: d/m/Y -> YYYY-MM-DD
             if (data.DATA_DEPOSITO) {
                 var parts = data.DATA_DEPOSITO.split('/');
                 if (parts.length == 3) {
                     document.getElementById('cred_DATA_DEPOSITO').value = parts[2] + '-' + parts[1] + '-' + parts[0];
                 } else {
                     document.getElementById('cred_DATA_DEPOSITO').value = '';
                 }
             } else {
                 document.getElementById('cred_DATA_DEPOSITO').value = '';
             }

             document.getElementById('cred_VALOR_DEPOSITO_PRINCIPAL').value = data.VALOR_DEPOSITO_PRINCIPAL || '';
             document.getElementById('cred_TEXTO_PAGAMENTO').value = data.TEXTO_PAGAMENTO || '';
             document.getElementById('cred_CERTIFICADO').value = data.CERTIFICADO || '';
             document.getElementById('cred_STATUS_2').value = data.STATUS_2 || '';
             document.getElementById('cred_CPF').value = data.CPF || '';
             document.getElementById('cred_AG').value = data.AG || '';
        } else {
             document.getElementById('cred_original_port').value = '';
        }
        new bootstrap.Modal(document.getElementById('modalCredit')).show();
    }

    function saveCredit() {
        var form = document.getElementById('form_credit');
        var btn = form.querySelector('.btn-navy');
        if (!validateElements(form.querySelectorAll('input, select, textarea'))) return;
        
        setButtonLoading(btn, true);
        var formData = new FormData(form);
        formData.append('acao', 'ajax_save_credit');
        
        fetch('', { method: 'POST', body: formData })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            setButtonLoading(btn, false);
            
            if (res.status == 'ok') {
                bootstrap.Modal.getInstance(document.getElementById('modalCredit')).hide();
                Swal.fire('Sucesso', res.message, 'success');
                if(document.getElementById('base_table')) renderBaseTable();
                else filterCredits();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(err => {
            setButtonLoading(btn, false);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    function deleteCredit(port) {
        Swal.fire({
            title: 'Tem certeza?', text: "Deseja excluir este registro da Base de Identificação?", icon: 'warning',
            showCancelButton: true, confirmButtonColor: '#003366', cancelButtonColor: '#d33', confirmButtonText: 'Sim, excluir!'
        }).then((result) => {
            if (result.isConfirmed) {
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_credit');
                fd.append('port', port);
                
                fetch('', { method: 'POST', body: fd })
                .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
                .then(res => {
                    hideLoading();
                    
                    if (res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        if(document.getElementById('base_table')) renderBaseTable();
                        else filterCredits();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => {
                    hideLoading();
                    Swal.fire('Erro', 'Falha na comunicação.', 'error');
                });
            }
        });
    }

    function showPage(pageId) {
        if ((pageId === 'base' || pageId === 'config' || pageId === 'config-hub') && !window.isConfigUnlocked) {
            requestConfigAccess();
            return;
        }

        // Release lock if leaving process details
        if (pageId !== 'detalhes') {
             if (typeof releaseCurrentLock === 'function') releaseCurrentLock();
        }

        document.querySelectorAll('.page-section').forEach(el => el.style.display = 'none');
        document.getElementById('page-' + pageId).style.display = 'block';
        
        document.querySelectorAll('.navbar-nav .nav-link').forEach(el => el.classList.remove('active'));
        var link = document.querySelector('a[href*="?p=' + pageId + '"]');
        if(link) link.classList.add('active');
        if (['config-hub', 'base', 'config'].includes(pageId)) {
            var cfgLink = document.getElementById('nav-link-config');
            if(cfgLink) cfgLink.classList.add('active');
        }
        if (pageId === 'dashboard') {
            var dashLink = document.getElementById('nav-link-dashboard');
            if(dashLink) dashLink.classList.add('active');
        }
        
        window.history.pushState(null, '', '?p=' + pageId);

        if (pageId === 'lembretes') {
            filterLembretes();
        }
    }

    function goBack() {
        // Ensure lock is released before switching view to avoid race conditions with dashboard refresh
        var releasePromise = (typeof releaseCurrentLock === 'function') ? releaseCurrentLock() : Promise.resolve();
        
        releasePromise.then(() => {
            showPage('dashboard');
            hideLoading(); // Ensure any stuck loading state is cleared
            
            // Safety: Reset any loading buttons in dashboard table immediately
            document.querySelectorAll('#dash_table_body button, #dash_table_body a.btn').forEach(btn => {
                if ((btn.disabled || btn.innerHTML.includes('spinner-border'))) {
                    btn.disabled = false;
                    if (btn.dataset.originalHtml) {
                        btn.innerHTML = btn.dataset.originalHtml;
                    } else {
                        // Fallback reconstruction: Force "Abrir" if we can't determine original state but it's in the dashboard table
                        btn.innerHTML = '<i class="fas fa-folder-open fa-lg text-warning"></i> Abrir';
                    }
                    btn.style.width = '';
                }
            });

            // Refresh dashboard to reset loading buttons and update data
            filterDashboard(null, currentDashboardPage);
        });
    }

    // Global Search for Serviços tab
    function globalSearchServicos() {
        var val = document.getElementById('global_search_servicos').value.trim();
        if (!val) {
            Swal.fire('Atenção', 'Digite um valor para buscar.', 'warning');
            return;
        }
        isDirty = false;
        loadProcess(val);
    }
    
    // Enter key handler for global search
    document.addEventListener('DOMContentLoaded', function() {
        var searchInput = document.getElementById('global_search_servicos');
        if (searchInput) {
            searchInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    globalSearchServicos();
                }
            });
        }
    });

    function startNewService() {
        currentLoadedPort = null;
        // Clear Attachments
        var tbAnexo = document.querySelector('#table_anexos tbody');
        if(tbAnexo) tbAnexo.innerHTML = '';

        // Re-enable all fields
        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = false);

        document.getElementById('form_processo').reset();
        // Reset deleted fields visibility
        document.querySelectorAll('.deleted-field-row').forEach(el => el.classList.add('d-none'));
        sessionStorage.removeItem('draft_processo');
        
        var cc = document.getElementById('dyn_credit_card');
        if(cc) cc.remove();
        
        var serverCC = document.getElementById('server_credit_card');
        if(serverCC) serverCC.style.display = 'none';
        
        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea').forEach(el => {
            if (el.type == 'hidden' && el.name == 'acao') return; 
            if (el.readOnly) return; 
            
            if (el.type == 'checkbox' || el.type == 'radio') el.checked = false;
            else el.value = '';
        });

        // Clear Registro de Processo inputs (which are outside form_processo)
        document.querySelectorAll('.reg-new-field').forEach(el => {
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.checked = false;
                if (el.classList.contains('ms-checkbox')) {
                    updateMultiselectLabel(el);
                }
            } else {
                el.value = '';
            }
        });

        // Reset Email List
        var emailList = document.getElementById('email_list_ul');
        if(emailList) emailList.innerHTML = '<li class="text-muted small">Nenhum email encontrado na agência.</li>';
        var selList = document.getElementById('selected_emails_list');
        if(selList) selList.innerText = '';

        // Reset Delete Button
        var divDel = document.getElementById('div_delete_process');
        if(divDel) divDel.innerHTML = '';

        // Reset Tab to First
        try {
            var triggerEl = document.querySelector('button[data-bs-target="#tab-dados"]');
            if(triggerEl) bootstrap.Tab.getOrCreateInstance(triggerEl).show();
        } catch(e) { console.error(e); }
        
        Swal.fire('Novo Atendimento', 'Formulário limpo.', 'success');
    }

    function clearDashboardFilters() {
        var form = document.getElementById('form_dashboard_filter');
        if(form) {
            form.querySelectorAll('input, select').forEach(el => {
                if(el.type != 'hidden' && el.type != 'button' && el.type != 'submit') {
                    el.value = '';
                }
            });
            
            // Reset Sort to Default
            currentDashSortCol = 'DATA';
            currentDashSortDir = 'desc';
            updateSortIcons('dash_table_head', 'DATA', 'desc');
            
            filterDashboard(null, 1);
        }
    }

    function clearBaseFilters() {
        var form = document.getElementById('form_base_filter');
        if(form) {
            form.querySelectorAll('input, select').forEach(el => {
                if(el.type != 'hidden' && el.type != 'button' && el.type != 'submit') {
                    el.value = '';
                }
            });
            
            // Reset Sort
            currentBaseSortCol = '';
            currentBaseSortDir = '';
            
            renderBaseTable(1);
        }
    }

    function loadProcessos() {
        filterDashboard(null, currentDashboardPage);
    }

    function filterDashboard(e, page) {
        if(e) e.preventDefault();
        
        var form = document.getElementById('form_dashboard_filter');
        var btn = form.querySelector('button:not([type="button"])');
        setButtonLoading(btn, true);
        
        var fd = new FormData(form);
        fd.append('acao', 'ajax_render_dashboard_table');
        if(page) fd.append('pag', page);
        fd.append('sortCol', currentDashSortCol);
        fd.append('sortDir', currentDashSortDir);
        
        fetch('', { method: 'POST', body: fd })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                if(res.page) currentDashboardPage = res.page;
                document.getElementById('dash_table_body').innerHTML = res.html;
                if(document.getElementById('dash_pagination_container')) {
                    document.getElementById('dash_pagination_container').innerHTML = res.pagination;
                }
                if(res.count !== undefined) {
                     var countStr = res.count < 10 ? '0' + res.count : res.count;
                     var headerEl = document.getElementById('process_list_header');
                     if(headerEl) headerEl.innerHTML = '<i class="fas fa-list me-2"></i>Processos (' + countStr + ')';
                }
            } else {
                Swal.fire('Erro', 'Falha ao filtrar', 'error');
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha na comunicação.', 'error'); });
    }

    function filterCredits(e, page) {
        if(e) e.preventDefault();
        showLoading();
        
        var form = document.getElementById('form_credit_filter');
        var fd = new FormData(form);
        fd.append('acao', 'ajax_render_credit_table');
        if(page) fd.append('cpPagina', page);
        
        fetch('', { method: 'POST', body: fd })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                document.getElementById('cred_table_body').innerHTML = res.html;
                if(document.getElementById('cred_pagination_container')) {
                    document.getElementById('cred_pagination_container').innerHTML = res.pagination;
                }
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha na comunicação.', 'error'); });
    }

    function applyBaseSelection() {
        var years = [];
        document.querySelectorAll('.chk-year:checked').forEach(cb => years.push(cb.value));
        var months = [];
        document.querySelectorAll('.chk-month:checked').forEach(cb => months.push(cb.value));
        
        if (years.length === 0 || months.length === 0) {
            Swal.fire('Atenção', 'Selecione pelo menos um ano e um mês.', 'warning');
            return;
        }

        showLoading();
        var fd = new FormData();
        fd.append('acao', 'ajax_set_base_selection');
        years.forEach(y => fd.append('years[]', y));
        months.forEach(m => fd.append('months[]', m));
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(res.status == 'ok') {
                hideLoading();
                if(document.getElementById('page-dashboard').style.display != 'none') {
                    filterDashboard();
                } else if(document.getElementById('page-base').style.display != 'none') {
                    renderBaseTable();
                } else {
                    window.location.reload();
                }
            } else {
                hideLoading();
                Swal.fire('Erro', 'Falha ao aplicar filtro.', 'error');
            }
        })
        .catch(() => {
            hideLoading();
        });
    }

    function updateIdentIcon(creditData) {
        // Remove existing dynamic icons
        document.querySelectorAll('.ident-icon-js').forEach(el => el.remove());
        
        if (!creditData) return;
        
        var iconHtml = _g_ident_icon || '<i class="fas fa-sack-dollar text-success"></i>';
        var isHtml = iconHtml.trim().startsWith('<');
        
        function createIconSpan() {
            var s = document.createElement('span');
            s.className = 'ident-icon-js ms-2';
            if (isHtml) s.innerHTML = iconHtml;
            else {
                s.className += ' text-success';
                s.innerText = iconHtml;
            }
            s.title = 'Identificado!';
            return s;
        }

        // Target: Labels containing "Identificação" or Configured Label
        var targets = [];
        document.querySelectorAll('label.form-label-custom').forEach(l => {
             var txt = l.textContent.toLowerCase();
             if (txt.includes('identificação') || (_g_ident_label && txt.includes(_g_ident_label.toLowerCase()))) {
                 targets.push(l);
             }
        });
        
        // Target: Section Titles (h5)
        document.querySelectorAll('h5.text-navy').forEach(h => {
             var txt = h.textContent.toLowerCase();
             if (txt.includes('identificação') || (_g_ident_label && txt.includes(_g_ident_label.toLowerCase()))) {
                 targets.push(h);
             }
        });
        
        targets.forEach(t => t.appendChild(createIconSpan()));
    }

    function loadProcess(port, btn) {
        // Re-enable all fields first (fix blocked state persistence)
        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = false);

        if(btn) setButtonLoading(btn, true); else showLoading();
        
        var fd = new FormData();
        fd.append('acao', 'ajax_get_process_full');
        fd.append('port', port);
        
        fetch('', { method: 'POST', body: fd })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            if(btn) setButtonLoading(btn, false); else hideLoading();
            if(res.status == 'ok') {
                currentLoadedPort = port;
                loadAttachments(port);
                updateIdentIcon(res.credit); // Update Icon visibility
                if (res.lock && res.lock.locked) {
                     Swal.fire({
                        icon: 'warning',
                        title: 'Processo Bloqueado',
                        text: 'Este processo está sendo editado por ' + res.lock.by + '. Modo somente leitura.',
                        timer: 5000
                     });
                     releaseCurrentLock();
                } else {
                     startLocking(port);
                }

                var p = res.process;
                
                if(p && p.Ultima_Alteracao) {
                    var el = document.getElementById('timestamp_controle');
                    if(el) el.value = p.Ultima_Alteracao;
                }
                var c = res.client;
                var a = res.agency;
                
                if(res.registros_history) {
                    renderRegistrosHistory(res.registros_history);
                } else {
                    document.getElementById('history_registros_body').innerHTML = '';
                }

                var emailBody = document.getElementById('history_table_body');
                if (emailBody) emailBody.innerHTML = '';
                
                if (res.email_history && res.email_history.length > 0) {
                    res.email_history.forEach(function(h) {
                        var tr = document.createElement('tr');
                        var safeData = (h.DATA || '').replace(/</g, '&lt;');
                        var safeUser = (h.USUARIO || '').replace(/</g, '&lt;');
                        var rawModelo = (h.MODELO || '').replace(/</g, '&lt;');
                        
                        var safeTitulo = '';
                        var safeTema = rawModelo;
                        
                        // Parse "Lista: X - Tema: Y"
                        var match = rawModelo.match(/Lista:\s*(.*?)\s*-\s*Tema:\s*(.*)/);
                        if(match && match.length >= 3) {
                            safeTitulo = match[1];
                            safeTema = match[2];
                        }
                        
                        var idVal = (h._id !== undefined) ? h._id : (h.UID || '');
                        
                        tr.innerHTML = '<td><input type="checkbox" name="hist_del[]" class="form-check-input" value="'+idVal+'"></td><td>' + safeData + '</td><td>' + safeUser + '</td><td>' + safeTitulo + '</td><td>' + safeTema + '</td>';
                        emailBody.appendChild(tr);
                    });
                }

                document.getElementById('form_processo').reset();
                // Explicitly clear Data_Lembrete to prevent persistent values from previous load or HTML default
                var hiddenLembrete = document.querySelector('input[name="Data_Lembrete"]');
                if(hiddenLembrete) hiddenLembrete.value = '';
                
                // Reset deleted fields visibility
                document.querySelectorAll('.deleted-field-row').forEach(el => el.classList.add('d-none'));

                // Reset agency container visibility
                if(document.getElementById('agency_details_container')) document.getElementById('agency_details_container').style.display = 'none';
                
                document.querySelectorAll('.reg-new-field').forEach(el => {
                    var key = el.name.replace('reg_new_', '');
                    var val = '';
                    if(p && p[key]) val = p[key];
                    else if(c && c[key]) val = c[key];
                    else if(a && a[key]) val = a[key];
                    else if(res.credit && res.credit[key]) val = res.credit[key];
                    
                    if (el.type === 'checkbox' || el.type === 'radio') {
                        if (el.name.endsWith('[]')) {
                            var values = val ? String(val).split(',').map(s => s.trim()) : [];
                            el.checked = values.includes(el.value);
                        } else {
                            el.checked = (String(el.value) === String(val));
                        }
                        if (el.classList.contains('ms-checkbox')) {
                            updateMultiselectLabel(el);
                        }
                    } else {
                        if(val) {
                            if (el.type === 'date' && /^\d{2}\/\d{2}\/\d{4}$/.test(val)) {
                                var parts = val.split('/');
                                val = parts[2] + '-' + parts[1] + '-' + parts[0];
                            }
                            el.value = val;
                            if (el.dataset.mask || el.dataset.case || el.dataset.allowed) {
                                el.value = formatCustomValue(el.value, el.dataset.mask, el.dataset.case, el.dataset.allowed);
                            }
                        } else {
                            el.value = '';
                        }
                    }
                });

                if(p) {
                    document.querySelectorAll('#form_processo [name]').forEach(el => {
                        var key = el.name;
                        // Filter for process fields (not prefixed)
                        if (!key.startsWith('client_') && !key.startsWith('agency_') && !key.startsWith('reg_') && key != 'acao') {
                            var lookupKey = key.endsWith('[]') ? key.substring(0, key.length-2) : key;
                            
                            var pKey = Object.keys(p).find(k => k.toLowerCase() === lookupKey.toLowerCase());
                            if (pKey) {
                                var val = p[pKey];
                                
                                // Unhide deleted field if it has value
                                if (val && String(val).trim() !== '') {
                                    var row = el.closest('.deleted-field-row');
                                    if(row) row.classList.remove('d-none');
                                }

                                if (el.type === 'date' && /^\d{2}\/\d{2}\/\d{4}$/.test(val)) {
                                    var parts = val.split('/');
                                    val = parts[2] + '-' + parts[1] + '-' + parts[0];
                                }

                                if (el.type === 'datetime-local' && /^\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}(:\d{2})?$/.test(val)) {
                                    var dtParts = val.split(' ');
                                    var dParts = dtParts[0].split('/');
                                    var timePart = dtParts[1].substring(0, 5);
                                    val = dParts[2] + '-' + dParts[1] + '-' + dParts[0] + 'T' + timePart;
                                }
                                
                                if (el.type === 'checkbox' || el.type === 'radio') {
                                    if (el.name.endsWith('[]')) {
                                        var values = val ? String(val).split(',').map(s => s.trim()) : [];
                                        el.checked = values.includes(el.value);
                                    } else {
                                        el.checked = (String(el.value) == String(val));
                                    }
                                } else {
                                    el.value = val;
                                    if (el.dataset.mask || el.dataset.case || el.dataset.allowed) {
                                        el.value = formatCustomValue(el.value, el.dataset.mask, el.dataset.case, el.dataset.allowed);
                                    }
                                }
                            }
                        }
                    });
                    
                    var portKey = Object.keys(p).find(k => k.toLowerCase() === '<?= strtolower(htmlspecialchars($ID_FIELD_KEY)) ?>');
                    if(document.getElementById('proc_port') && portKey) {
                        var pInput = document.getElementById('proc_port');
                        pInput.value = p[portKey];
                        if (pInput.dataset.mask || pInput.dataset.case || pInput.dataset.allowed) {
                            pInput.value = formatCustomValue(pInput.value, pInput.dataset.mask, pInput.dataset.case, pInput.dataset.allowed);
                        }
                    }

                    // Update Lembrete Status based on loaded process
                    var lInput = document.querySelector('input[name="Data_Lembrete"]');
                    if(lInput) updateLembreteStatus(lInput.value);
                    else updateLembreteStatus(''); // Clear if no field
                }
                
                if(c) {
                    document.querySelectorAll('#form_processo [name^="client_"]').forEach(el => {
                        var key = el.name.replace('client_', '');
                        var cKey = Object.keys(c).find(k => k.toLowerCase() === key.toLowerCase());
                        if (cKey) {
                            el.value = c[cKey];
                        }
                    });
                    
                    var cpfKey = Object.keys(c).find(k => k.toLowerCase() === 'cpf');
                    if(document.getElementById('cli_cpf') && cpfKey) document.getElementById('cli_cpf').value = c[cpfKey];
                    
                    var nomeKey = Object.keys(c).find(k => k.toLowerCase() === 'nome');
                    if(document.getElementById('cli_nome') && nomeKey) document.getElementById('cli_nome').value = c[nomeKey];
                }
                
                if(a) {
                    // Populate agency fields to prevent data loss on save
                    document.querySelectorAll('#form_processo [name^="agency_"]').forEach(el => {
                        var key = el.name.replace('agency_', '');
                        var aKey = Object.keys(a).find(k => k.toLowerCase() === key.toLowerCase());
                        if (aKey) {
                            el.value = a[aKey];
                        }
                    });

                    var agKey = Object.keys(a).find(k => k.toLowerCase() === 'ag');
                    if(document.getElementById('ag_code') && agKey) document.getElementById('ag_code').value = a[agKey];
                } else if (p) {
                    var agKeyP = Object.keys(p).find(k => k.toLowerCase() === 'ag');
                    if(document.getElementById('ag_code') && agKeyP) document.getElementById('ag_code').value = p[agKeyP];
                }

                // Update Email List
                var agencyEmails = [];
                var emailList = document.getElementById('email_list_ul');
                if (emailList) {
                    emailList.innerHTML = '';
                    if(a) {
                        for (var k in a) {
                            if (k.toUpperCase().includes('MAIL') || k.toUpperCase().includes('EMAIL')) {
                                if (a[k]) {
                                    var parts = String(a[k]).split(/[;,]/);
                                    parts.forEach(p => {
                                        p = p.trim();
                                        if(p && p.includes('@')) { 
                                            agencyEmails.push(p);
                                        }
                                    });
                                }
                            }
                        }
                    }
                    
                    agencyEmails = [...new Set(agencyEmails)].sort();
                    
                    if(agencyEmails.length === 0) {
                         emailList.innerHTML = '<li class="text-muted small">Nenhum email encontrado na agência.</li>';
                    } else {
                        agencyEmails.forEach(em => {
                            var id = 'chk_' + Math.random().toString(36).substr(2, 9);
                            var li = document.createElement('li');
                            li.className = 'form-check mb-1';
                            li.onclick = function(e) { e.stopPropagation(); };
                            
                            var checkbox = document.createElement('input');
                            checkbox.className = 'form-check-input email-checkbox';
                            checkbox.type = 'checkbox';
                            checkbox.value = em;
                            checkbox.id = id;
                            
                            var label = document.createElement('label');
                            label.className = 'form-check-label';
                            label.htmlFor = id;
                            label.textContent = em;
                            
                            li.appendChild(checkbox);
                            li.appendChild(label);
                            emailList.appendChild(li);
                        });
                    }
                    // Clear selected list
                    var selList = document.getElementById('selected_emails_list');
                    if(selList) selList.innerText = '';
                }
                
                // Show Delete Button
                var divDel = document.getElementById('div_delete_process');
                if(divDel) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'btn btn-outline-danger';
                    btn.textContent = 'Excluir Processo';
                    btn.onclick = function() { confirmDelete(port); };
                    divDel.innerHTML = '';
                    divDel.appendChild(btn);
                }

                var oldCC = document.getElementById('server_credit_card');
                if(oldCC) oldCC.style.display = 'none';
                
                var dynCC = document.getElementById('dyn_credit_card');
                if(dynCC) dynCC.remove();
                
                if(res.credit) {
                    var cr = res.credit;
                    var cardHtml = '<div class="card card-custom p-4 mb-4 border-warning border-3" id="dyn_credit_card">' + 
                        '<h5 class="text-warning fw-bold border-bottom pb-2"><i class="<?= htmlspecialchars($IDENT_ICON) ?> me-2"></i><?= htmlspecialchars($IDENT_LABEL) ?></h5>' +
                        '<div class="row g-3">';
                    for (var k in cr) {
                        cardHtml += '<div class="col-md-3"><label class="small text-muted">' + k + '</label><div class="fw-bold">' + (cr[k] || '-') + '</div></div>';
                    }
                    cardHtml += '</div></div>';
                    
                    var cards = document.querySelectorAll('#form_processo .card-custom');
                    if(cards.length > 0) {
                        var lastCard = cards[cards.length - 1]; 
                        lastCard.insertAdjacentHTML('afterend', cardHtml);
                    }
                }
                
                showPage('detalhes');

                // Fix URL to include ID so reload works
                var newUrl = '?p=detalhes&id=' + encodeURIComponent(port);
                window.history.replaceState(null, '', newUrl);

                // Reset Tab to First
                try {
                    var triggerEl = document.querySelector('button[data-bs-target="#tab-dados"]');
                    if(triggerEl) bootstrap.Tab.getOrCreateInstance(triggerEl).show();
                } catch(e) { console.error(e); }
                
                if (res.lock && res.lock.locked) {
                    setTimeout(() => {
                        document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea, #form_processo button').forEach(el => el.disabled = true);
                    }, 100);
                }

            } else {
                Swal.fire('Erro', 'Erro ao carregar processo.', 'error');
            }
        })
        .catch(() => { if(btn) setButtonLoading(btn, false); else hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    // --- GENERIC BASE JS ---
    var currentBase = 'Processos';

    function changeIdentIcon() {
        Swal.fire({
            title: 'Alterar Ícone de Identificação',
            html: '<p class="text-muted">Escolha um emoji ou cole o seu:</p>' +
                  '<div class="d-flex justify-content-center gap-2 mb-3" style="font-size:1.5rem; cursor:pointer">' +
                  '<span onclick="document.getElementById(\'swal-input-icon\').value=\'💰\'">💰</span>' +
                  '<span onclick="document.getElementById(\'swal-input-icon\').value=\'💵\'">💵</span>' +
                  '<span onclick="document.getElementById(\'swal-input-icon\').value=\'💎\'">💎</span>' +
                  '<span onclick="document.getElementById(\'swal-input-icon\').value=\'🪙\'">🪙</span>' +
                  '<span onclick="document.getElementById(\'swal-input-icon\').value=\'✅\'">✅</span>' +
                  '</div>' + 
                  '<input id="swal-input-icon" class="swal2-input" placeholder="Cole aqui (ex: 💰)">',
            showCancelButton: true,
            confirmButtonText: 'Salvar',
            preConfirm: () => {
                return document.getElementById('swal-input-icon').value;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                var fd = new FormData();
                fd.append('acao', 'ajax_save_ident_icon');
                fd.append('icon', result.value);
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', 'Ícone atualizado!', 'success');
                        refreshConfigView();
                    }
                });
            }
        });
    }

    function loadBaseFilters(file) {
        return new Promise((resolve, reject) => {
            if(!file) { resolve(); return; }
            
            var fd = new FormData();
            fd.append('acao', 'ajax_render_base_filters');
            fd.append('file', file);
            fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if(res.status == 'ok') {
                    var el = document.getElementById('dynamic_filters_area');
                    if(el) el.innerHTML = res.html;
                }
                resolve();
            })
            .catch(() => resolve());
        });
    }

    function setupColumnDrag() {
        // Base Table
        var baseHead = document.getElementById('base_sortable_head');
        if(baseHead && !baseHead.classList.contains('sortable-initialized')) {
            new Sortable(baseHead.querySelector('tr'), {
                animation: 150,
                onEnd: function (evt) {
                    var order = [];
                    // Use data-col attribute added in renderBaseTable
                    var ths = baseHead.querySelectorAll('th');
                    ths.forEach(th => {
                        var col = th.getAttribute('data-col');
                        if(col) order.push(col);
                    });
                    
                    if(order.length > 0) {
                        var fd = new FormData();
                        fd.append('acao', 'ajax_save_base_order');
                        fd.append('base', currentBase);
                        order.forEach(k => fd.append('order[]', k));
                        fetch('', { method: 'POST', body: fd })
                        .then(r => r.json())
                        .then(res => {
                             if(res.status == 'ok') renderBaseTable(1);
                        });
                    }
                }
            });
            baseHead.classList.add('sortable-initialized');
        }

        // Dashboard Table
        var dashHead = document.getElementById('dash_table_head');
        if(dashHead && !dashHead.classList.contains('sortable-initialized')) {
            new Sortable(dashHead.querySelector('tr'), {
                 animation: 150,
                 onEnd: function (evt) {
                     var order = [];
                     var ths = dashHead.querySelectorAll('th.sortable-header');
                     ths.forEach(th => {
                         var col = th.getAttribute('data-col');
                         if(col) order.push(col);
                     });
                     
                     if(order.length > 0) {
                         var fd = new FormData();
                         fd.append('acao', 'ajax_save_dash_order');
                         order.forEach(k => fd.append('order[]', k));
                         fetch('', { method: 'POST', body: fd })
                         .then(r => r.json())
                         .then(res => {
                              if(res.status == 'ok') loadProcessos(); // Refresh dashboard
                         });
                     }
                 }
            });
            dashHead.classList.add('sortable-initialized');
        }
    }
    
    // Call on load
    document.addEventListener('DOMContentLoaded', setupColumnDrag);

    function switchBase(base) {
        currentBase = base;
        currentBaseSortCol = '';
        currentBaseSortDir = '';
        
        // Update Tabs
        document.querySelectorAll('#base-tab .nav-link').forEach(el => el.classList.remove('active'));
        var tabId = 'tab-cred';
        if(base.includes('agencia')) tabId = 'tab-ag';
        if(base.includes('client')) tabId = 'tab-cli';
        if(base === 'Processos') tabId = 'tab-proc';
        if(document.getElementById(tabId)) document.getElementById(tabId).classList.add('active');
        
        // Update Title and Inputs
        var title = '<?= htmlspecialchars($IDENT_LABEL) ?>';
        if(base.includes('agencia')) title = 'Base de Agências';
        if(base.includes('client')) title = 'Base de Clientes';
        if(base === 'Processos') title = 'Base de Processos';
        document.getElementById('updateBaseTitle').innerText = 'Atualizar ' + title;
        if(document.getElementById('upload_base_name')) document.getElementById('upload_base_name').value = base;
        
        // Map Base to Schema
        var schemaFile = base;
        if(base === 'Processos') schemaFile = 'Base_processos_schema';

        // Load Filters
        loadBaseFilters(schemaFile).then(() => {
            // Toggle Logic if needed for legacy elements
            var processFilters = document.querySelectorAll('.base-process-filter');
            if (base === 'Processos') {
                processFilters.forEach(el => el.style.display = 'block');
            } else {
                processFilters.forEach(el => el.style.display = 'none');
            }
            
            // Populate Options for Dynamic Fields (Status / Atendente / Selects)
            var fd = new FormData();
            fd.append('acao', 'ajax_get_base_filter_options');
            fd.append('base', base);
            
            fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                if(res.status == 'ok' && res.options) {
                     Object.keys(res.options).forEach(function(key) {
                         var selects = document.getElementsByName('f_' + key);
                         if(selects.length > 0) {
                             selects.forEach(function(sel) {
                                  sel.innerHTML = '<option value="">Todos</option>';
                                  res.options[key].forEach(function(opt) {
                                      sel.innerHTML += '<option value="'+opt+'">'+opt+'</option>';
                                  });
                             });
                         }
                     });
                }
            })
            .catch(() => {});
        });

        renderBaseTable(1);
    }

    function renderBaseTable(page, btn) {
        if(!page) page = 1;
        if(btn) setButtonLoading(btn, true);
        else showLoading();
        
        var form = document.getElementById('form_base_filter');
        var fd = new FormData(form);
        fd.append('acao', 'ajax_render_base_table');
        fd.append('base', currentBase);
        fd.append('cpPagina', page);
        if(currentBaseSortCol) {
            fd.append('sortCol', currentBaseSortCol);
            fd.append('sortDir', currentBaseSortDir);
        }
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(btn) setButtonLoading(btn, false);
            else hideLoading();
            
            if(res.status == 'ok') {
                document.getElementById('base_table').innerHTML = res.html;
                if(document.getElementById('cred_pagination_container')) document.getElementById('cred_pagination_container').innerHTML = res.pagination;
                if(res.count !== undefined) {
                     var countStr = res.count; // Usually pre-formatted or just number
                     var headerEl = document.getElementById('base_registros_header');
                     if(headerEl) {
                         // Preserve existing label part (Processos/Registros/etc)
                         var currentLabel = headerEl.innerText.split('(')[0].trim();
                         if(!currentLabel) currentLabel = 'Registros';
                         headerEl.innerHTML = currentLabel + ' (' + countStr + ')';
                     }
                }
                // Re-init Drag and Drop
                setupColumnDrag();
            } else {
                Swal.fire('Erro', res.message || 'Erro ao carregar base', 'error');
            }
        })
        .catch(() => { 
            if(btn) setButtonLoading(btn, false);
            else hideLoading();
            Swal.fire('Erro', 'Falha de comunicação', 'error'); 
        });
    }

    function filterBase(e) {
        e.preventDefault();
        var form = document.getElementById('form_base_filter');
        var btn = form.querySelector('button');
        renderBaseTable(1, btn);
    }

    function openBaseModal(record, btn) {
        setButtonLoading(btn, true);
        // Fetch Schema first
        var fd = new FormData();
        fd.append('acao', 'ajax_get_base_schema');
        fd.append('base', currentBase);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                var fields = res.fields;
                var html = '';
                
                document.getElementById('base_original_id').value = '';
                document.getElementById('base_target_name').value = currentBase;
                
                // Determine PK for original_id
                var pk = fields.length > 0 ? fields[0].key : 'id';
                if(currentBase.includes('cred')) pk = '<?= htmlspecialchars($IDENT_ID_FIELD) ?>';
                if(currentBase.includes('client')) pk = 'CPF';
                if(currentBase.includes('agenc')) pk = 'AG';
                if(currentBase === 'Processos') pk = '<?= htmlspecialchars($ID_FIELD_KEY) ?>';
                
                if(record) {
                    document.getElementById('base_original_id').value = record[pk] || '';
                }

                fields.forEach(f => {
                    var rawVal = record ? (record[f.key] || '') : '';
                    
                    if (f.key.toLowerCase() === 'nome_atendente' && !rawVal && CURRENT_USER) {
                        rawVal = CURRENT_USER;
                    }

                    // Escape for HTML Attribute
                    var safeVal = String(rawVal).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    
                    var disabledAttr = ''; // Permitir edição de todos os campos, incluindo Nome_atendente para transferências
                    
                    var inputType = 'text';
                    if(f.type == 'date') inputType = 'date';
                    if(f.type == 'number') inputType = 'number';
                    
                    var dateVal = rawVal;
                    if(inputType == 'date' && rawVal && rawVal.includes('/')) {
                        var parts = rawVal.split('/');
                        if(parts.length == 3) dateVal = parts[2] + '-' + parts[1] + '-' + parts[0];
                    }
                    if(f.type == 'datetime') {
                        inputType = 'datetime-local';
                        if (rawVal && rawVal.includes('/')) {
                             var parts = rawVal.split(' ');
                             var d = parts[0].split('/');
                             var t = (parts.length > 1) ? parts[1] : '00:00';
                             if(d.length == 3) dateVal = d[2] + '-' + d[1] + '-' + d[0] + 'T' + t;
                        }
                    }
                    var safeDateVal = String(dateVal).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

                    html += '<div class="col-md-6 mb-3">';
                    html += '<label class="form-label">' + f.label + '</label>';
                    
                    if (f.type == 'textarea') {
                        // Textarea content should be escaped for HTML content (not attribute)
                        var safeContent = String(rawVal).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                        html += '<textarea name="' + f.key + '" class="form-control" rows="3">' + safeContent + '</textarea>';
                    } else if (f.type == 'select') {
                        html += '<select name="' + f.key + '" class="form-select">';
                        html += '<option value="">...</option>';
                        if (f.key == 'UF') {
                             ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'].forEach(o => {
                                 var selected = (rawVal == o) ? 'selected' : '';
                                 html += '<option value="'+o+'" '+selected+'>'+o+'</option>';
                             });
                        } else {
                             var opts = [];
                             if (f.options) {
                                 opts = f.options.split(',').map(s => s.trim());
                             }
                             var valFound = false;
                             opts.forEach(o => {
                                 var selected = (rawVal == o) ? 'selected' : '';
                                 html += '<option value="'+o+'" '+selected+'>'+o+'</option>';
                                 if(rawVal == o) valFound = true;
                             });
                             if(rawVal && !valFound && String(rawVal).trim() !== '') {
                                 html += '<option value="'+safeVal+'" selected>'+safeVal+'</option>';
                             }
                        }
                        html += '</select>';
                    } else if (f.type == 'money') {
                         html += '<input type="text" name="' + f.key + '" class="form-control money-mask" value="' + safeVal + '">';
                    } else if (f.type == 'custom') {
                    var mask = f.custom_mask || '';
                    var txtCase = f.custom_case || '';
                    var allowed = f.custom_allowed || 'all';
                    var formattedVal = formatCustomValue(rawVal, mask, txtCase, allowed);
                    var safeVal = formattedVal.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

                    html += '<input type="text" name="' + f.key + '" class="form-control" value="' + safeVal + '" ' + disabledAttr + ' data-mask="' + mask + '" data-case="' + txtCase + '" data-allowed="' + allowed + '" oninput="applyCustomMask(this)">';
                    } else {
                         var useVal = (inputType == 'date') ? safeDateVal : (inputType == 'datetime-local' ? safeDateVal : safeVal);
                         var extraAttrs = '';
                         if (inputType == 'text') {
                             var mask = f.custom_mask || '';
                             var txtCase = f.custom_case || '';
                             var allowed = f.custom_allowed || 'all';
                             var formattedVal = formatCustomValue(rawVal, mask, txtCase, allowed);
                             useVal = formattedVal.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                             extraAttrs = ' data-mask="' + mask + '" data-case="' + txtCase + '" data-allowed="' + allowed + '" oninput="applyCustomMask(this)"';
                         }
                         html += '<input type="' + inputType + '" name="' + f.key + '" class="form-control" value="' + useVal + '" ' + disabledAttr + extraAttrs + '>';
                    }
                    html += '</div>';
                });
                
                document.getElementById('modal_base_fields').innerHTML = html;
                
                document.querySelectorAll('.money-mask').forEach(input => {
                    input.addEventListener('input', function(e) {
                        let value = e.target.value.replace(/\D/g, '');
                        value = (value / 100).toFixed(2) + '';
                        value = value.replace('.', ',');
                        value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
                        e.target.value = 'R$ ' + value;
                    });
                });

                new bootstrap.Modal(document.getElementById('modalBase')).show();
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function saveBase() {
        var form = document.getElementById('form_base');
        var btn = form.querySelector('.btn-navy'); 
        if (!validateElements(form.querySelectorAll('input, select, textarea'))) return;
        
        setButtonLoading(btn, true);
        var fd = new FormData(form);
        fd.append('acao', 'ajax_save_base_record');
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                bootstrap.Modal.getInstance(document.getElementById('modalBase')).hide();
                Swal.fire('Sucesso', res.message, 'success');
                renderBaseTable();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function deleteBaseRecord(id) {
        Swal.fire({
            title: 'Tem certeza?', text: "Excluir registro?", icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Sim'
        }).then((r) => {
            if(r.isConfirmed) {
                showLoading();
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_base_record');
                fd.append('base', currentBase);
                fd.append('id', id);
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Excluído', res.message, 'success');
                        renderBaseTable();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }

    function deleteSelectedBase(btn) {
        var ids = [];
        document.querySelectorAll('.base-checkbox:checked').forEach(cb => ids.push(cb.value));
        
        if(ids.length == 0) { Swal.fire('Aviso', 'Selecione registros.', 'info'); return; }
        
        Swal.fire({
            title: 'Excluir ' + ids.length + ' registros?',
            text: 'Essa ação não tem volta.',
            icon: 'warning',
            showCancelButton: true, confirmButtonText: 'Sim'
        }).then((r) => {
            if(r.isConfirmed) {
                setButtonLoading(btn, true);
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_base_bulk');
                fd.append('base', currentBase);
                ids.forEach(id => fd.append('ids[]', id));
                
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    setButtonLoading(btn, false);
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso', res.message, 'success');
                        renderBaseTable();
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                })
                .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
            }
        });
    }


    function editSelectedBase() {
        var ids = [];
        document.querySelectorAll('.base-checkbox:checked').forEach(cb => ids.push(cb.value));
        
        if(ids.length == 0) { Swal.fire('Aviso', 'Selecione registros.', 'info'); return; }

        showLoading();
        var fd = new FormData();
        fd.append('acao', 'ajax_prepare_bulk_edit');
        fd.append('base', currentBase);
        ids.forEach(id => fd.append('ids[]', id));
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                var fields = res.fields;
                var html = '';
                
                document.getElementById('bulk_base_target').value = currentBase;

                fields.forEach(f => {
                    // Skip certain fields
                    if (f.key.toLowerCase() === 'id') return;
                    if (f.key.toLowerCase() === 'cpf' && currentBase.includes('client')) return; // PK
                    if (f.key.toLowerCase() === 'ag' && currentBase.includes('agenc')) return; // PK
                    if (f.key.toLowerCase() === '<?= strtolower(htmlspecialchars($ID_FIELD_KEY)) ?>') return; // PK

                    var rawVal = f.value;
                    var safeVal = String(rawVal).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
                    
                    var disabled = !f.is_common;
                    
                    var disabledAttr = disabled ? 'disabled' : '';
                    var valDisplay = disabled ? '(Vários valores)' : safeVal;
                    if (disabled) safeVal = ''; // Don't put various values in value attribute, just visual

                    var inputType = 'text';
                    if(f.type == 'date') inputType = 'date';
                    if(f.type == 'number') inputType = 'number';

                    if (inputType == 'date' && !disabled && rawVal && rawVal.includes('/')) {
                        var parts = rawVal.split('/');
                        if(parts.length == 3) safeVal = parts[2] + '-' + parts[1] + '-' + parts[0];
                    }

                    html += '<div class="col-md-6 mb-3">';
                    html += '<label class="form-label">' + f.label + '</label>';
                    
                    if (f.type == 'textarea') {
                         html += '<textarea name="' + f.key + '" class="form-control" rows="3" ' + disabledAttr + (disabled ? ' placeholder="(Vários valores)"' : '') + '>' + safeVal + '</textarea>';
                    } else if (f.type == 'select') {
                        html += '<select name="' + f.key + '" class="form-select" ' + disabledAttr + '>';
                        html += '<option value="">...</option>';
                        if (disabled) {
                             html += '<option value="" selected>(Vários valores)</option>';
                        } else {
                            if (f.key == 'UF') {
                                 ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'].forEach(o => {
                                     var selected = (rawVal == o) ? 'selected' : '';
                                     html += '<option value="'+o+'" '+selected+'>'+o+'</option>';
                                 });
                            } else {
                                 var opts = [];
                                 if (f.options) {
                                     opts = f.options.split(',').map(s => s.trim());
                                 }
                                 var valFound = false;
                                 opts.forEach(o => {
                                     var selected = (rawVal == o) ? 'selected' : '';
                                     html += '<option value="'+o+'" '+selected+'>'+o+'</option>';
                                     if(rawVal == o) valFound = true;
                                 });
                                 if(rawVal && !valFound && String(rawVal).trim() !== '') {
                                     html += '<option value="'+safeVal+'" selected>'+safeVal+'</option>';
                                 }
                            }
                        }
                        html += '</select>';
                    } else if (f.type == 'money') {
                         html += '<input type="text" name="' + f.key + '" class="form-control money-mask" value="' + safeVal + '" ' + disabledAttr + (disabled ? ' placeholder="(Vários valores)"' : '') + '>';
                    } else {
                         html += '<input type="' + inputType + '" name="' + f.key + '" class="form-control" value="' + safeVal + '" ' + disabledAttr + (disabled ? ' placeholder="(Vários valores)"' : '') + '>';
                    }
                    html += '</div>';
                });
                
                document.getElementById('modal_bulk_fields').innerHTML = html;
                
                // Re-init masks
                document.querySelectorAll('#modalBulkEdit .money-mask').forEach(input => {
                    input.addEventListener('input', function(e) {
                        let value = e.target.value.replace(/\D/g, '');
                        value = (value / 100).toFixed(2) + '';
                        value = value.replace('.', ',');
                        value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
                        e.target.value = 'R$ ' + value;
                    });
                });

                new bootstrap.Modal(document.getElementById('modalBulkEdit')).show();
            } else {
                Swal.fire('Erro', 'Erro ao preparar edição.', 'error');
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function saveBulkBase() {
        var form = document.getElementById('form_bulk_edit');
        var btn = form.querySelector('.btn-navy');
        
        var ids = [];
        document.querySelectorAll('.base-checkbox:checked').forEach(cb => ids.push(cb.value));
        
        setButtonLoading(btn, true);
        var fd = new FormData(form);
        fd.append('acao', 'ajax_save_base_bulk');
        ids.forEach(id => fd.append('ids[]', id));
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                bootstrap.Modal.getInstance(document.getElementById('modalBulkEdit')).hide();
                Swal.fire('Sucesso', res.message, 'success');
                renderBaseTable();
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { setButtonLoading(btn, false); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }
    function confirmClearBase() {
        Swal.fire({
            title: 'Limpar Base?',
            text: "Você está prestes a apagar TODOS os dados da base " + currentBase + ".",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Sim, limpar tudo!'
        }).then((r) => {
            if(r.isConfirmed) {
                if(document.getElementById('limpar_base_target')) document.getElementById('limpar_base_target').value = currentBase;
                showLoading();
                document.getElementById('form_limpar_base').submit();
            }
        });
    }

    function downloadBase(btn) {
        if(btn) setButtonLoading(btn, true);
        
        var url = '?acao=download_base&base=' + currentBase;
        
        fetch(url, { method: 'GET' })
        .then(response => {
            if (response.ok) {
                return response.blob().then(blob => {
                    var a = document.createElement('a');
                    var u = window.URL.createObjectURL(blob);
                    a.href = u;
                    // Try to extract filename
                    var filename = "Base_" + currentBase.replace('.json', '') + "_" + new Date().toLocaleDateString('pt-BR').replace(/\//g, '-') + ".xls";
                    var disposition = response.headers.get('Content-Disposition');
                    if (disposition && disposition.indexOf('attachment') !== -1) {
                        var filenameRegex = /filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/;
                        var matches = filenameRegex.exec(disposition);
                        if (matches != null && matches[1]) { 
                            filename = matches[1].replace(/['"]/g, '');
                        }
                    }
                    a.download = filename;
                    document.body.appendChild(a);
                    a.click();
                    a.remove();
                    window.URL.revokeObjectURL(u);
                    if(btn) setButtonLoading(btn, false);
                });
            } else {
                if(btn) setButtonLoading(btn, false);
                Swal.fire('Erro', 'Falha ao baixar arquivo.', 'error');
            }
        })
        .catch(() => { 
            if(btn) setButtonLoading(btn, false); 
            Swal.fire('Erro', 'Falha na comunicação.', 'error'); 
        });
    }
    
    function openPasteModal() {
        document.getElementById('paste_base_target').value = currentBase;
        
        var fd = new FormData();
        fd.append('acao', 'ajax_get_base_schema');
        fd.append('base', currentBase);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.status == 'ok') {
                var headers = [];
                if (res.fields && res.fields.length > 0) {
                    res.fields.forEach(function(f) {
                        if (f.type === 'title') return;
                        headers.push(f.key);
                    });
                } else {
                    // Fallback Defaults
                    if (currentBase.includes('client')) headers = ['Nome', 'CPF'];
                    else if (currentBase.includes('agencia')) headers = ['AG', 'UF', 'SR', 'NOME SR', 'FILIAL', 'E-MAIL AG', 'E-MAILS SR', 'E-MAILS FILIAL', 'E-MAIL GERENTE'];
                    else if (currentBase === 'Processos') headers = <?= json_encode(array_values(array_column(array_filter($config->getFields('Base_processos_schema'), function($f) { return ($f['type'] ?? '') !== 'title' && !($f['deleted'] ?? false); }), 'key'))) ?>;
                    else headers = ['STATUS', 'NUMERO_DEPOSITO', 'DATA_DEPOSITO', 'VALOR_DEPOSITO_PRINCIPAL', 'TEXTO_PAGAMENTO', '<?= htmlspecialchars($IDENT_ID_FIELD) ?>', 'CERTIFICADO', 'STATUS_2', 'CPF', 'AG'];
                }
                
                var msg = "Ordem esperada: " + headers.join(', ');
                document.querySelector('#modalPaste .alert').innerHTML = "Cole aqui as linhas copiadas diretamente do Excel. O sistema detectará automaticamente se há cabeçalho.<br><strong>" + msg + "</strong>";
                new bootstrap.Modal(document.getElementById('modalPaste')).show();
            } else {
                Swal.fire('Erro', 'Não foi possível carregar a estrutura.', 'error');
            }
        })
        .catch(() => { Swal.fire('Erro', 'Falha na comunicação.', 'error'); });
    }



    function toggleAllChecks(source, name) {
        document.querySelectorAll('input[name="'+name+'[]"]').forEach(cb => cb.checked = source.checked);
    }

    function deleteSelectedHistory(type) {
        var name = (type == 'envios') ? 'hist_del' : 'registros_del';
        var action = (type == 'envios') ? 'ajax_delete_history' : 'ajax_delete_process_history';
        
        var checks = document.querySelectorAll('input[name="'+name+'[]"]:checked');
        if(checks.length == 0) {
            Swal.fire('Atenção', 'Selecione pelo menos um registro.', 'warning');
            return;
        }
        
        Swal.fire({
            title: 'Excluir Selecionados?',
            text: "Essa ação não pode ser desfeita.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            confirmButtonText: 'Sim, excluir'
        }).then((result) => {
            if (result.isConfirmed) {
                var ids = Array.from(checks).map(cb => cb.value);
                
                var fd = new FormData();
                fd.append('acao', action);
                fd.append('ids', JSON.stringify(ids));
                
                fetch('', { method: 'POST', body: fd })
                .then(r=>r.json())
                .then(res => {
                    if(res.status == 'ok') {
                         Swal.fire('Excluído!', 'Registros removidos.', 'success');
                         // Remove from DOM without reloading
                         checks.forEach(function(cb) {
                             var tr = cb.closest('tr');
                             if(tr) tr.remove();
                         });
                    } else {
                        Swal.fire('Erro', 'Falha ao excluir.', 'error');
                    }
                });
            }
        })
    }
    
    function renderRegistrosHistory(history) {
        var tbody = document.getElementById('history_registros_body');
        tbody.innerHTML = '';
        
        fetch('', {
            method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'acao=ajax_get_base_schema&base=Base_registros_schema'
        })
        .then(r=>r.json())
        .then(resSchema => {
            if(resSchema.status == 'ok') {
                var fields = resSchema.fields;
                
                history.forEach(row => {
                    var tr = document.createElement('tr');
                    
                    var tdCheck = document.createElement('td');
                    // Add checkbox for registries deletion
                    tdCheck.innerHTML = '<input type="checkbox" name="registros_del[]" class="form-check-input" value="'+ (row._id !== undefined ? row._id : '') + '">';
                    tr.appendChild(tdCheck);

                    var tdData = document.createElement('td');
                    tdData.textContent = row.DATA || '';
                    tr.appendChild(tdData);
                    
                    var tdUser = document.createElement('td');
                    tdUser.textContent = row.USUARIO || '';
                    tr.appendChild(tdUser);
                    
                    fields.forEach(f => {
                        var td = document.createElement('td');
                        var rawVal = row[f.key] || '';
                        td.textContent = formatCustomValue(rawVal, f.custom_mask, f.custom_case, f.custom_allowed);
                        tr.appendChild(td);
                    });
                    
                    tbody.appendChild(tr);
                });
            }
        });
    }

    function saveProcessRecord(btn) {
        var port = document.getElementById('proc_port').value;
        if(!port) { Swal.fire('Erro', '<?= htmlspecialchars($ID_LABEL) ?> não identificado.', 'error'); return; }
        
        var inputs = document.querySelectorAll('.reg-new-field');
        
        // 1. Validate All Inputs (Standard + Custom Multiselect)
        if (!validateElements(inputs)) return;

        // Prepare Data
        var formData = new FormData();
        formData.append('acao', 'ajax_save_process_data_record');
        formData.append('port', port);
        
        inputs.forEach(el => {
            var key = el.name.replace('reg_new_', '');
            if(el.type === 'checkbox') {
                if(el.checked) formData.append(key, el.value);
            } else if(el.type === 'radio') {
                if(el.checked) formData.append(key, el.value);
            } else {
                formData.append(key, el.value);
            }
        });
        
        setButtonLoading(btn, true);
        fetch('', { method: 'POST', body: formData })
        .then(async r => { try { return JSON.parse(await r.text()); } catch(e) { throw new Error('Erro servidor'); } })
        .then(res => {
            setButtonLoading(btn, false);
            if(res.status == 'ok') {
                Swal.fire('Sucesso', 'Registro salvo!', 'success');
                renderRegistrosHistory(res.history);
            } else {
                Swal.fire('Erro', res.message || 'Erro ao salvar', 'error');
            }
        })
        .catch(e => {
            setButtonLoading(btn, false);
            Swal.fire('Erro', 'Falha na comunicação.', 'error');
        });
    }

    function updateMultiselectLabel(checkbox) {
        var container = checkbox.closest('.dropdown');
        var selected = [];
        container.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => selected.push(cb.value));
        
        var label = selected.length ? selected.join(', ') : 'Selecione...';
        if (label.length > 30) label = label.substring(0, 27) + '...';
        
        var btn = container.querySelector('.dropdown-toggle');
        if(btn) btn.innerText = label;
    }

    function updateEmailListFromForm() {
        var agencyEmails = [];
        // Scan inputs for email fields
        document.querySelectorAll('#form_processo [name^="agency_"]').forEach(el => {
            var name = el.name.toUpperCase();
            if (name.includes('MAIL') || name.includes('EMAIL')) {
                var val = el.value;
                if (val) {
                    var parts = val.split(/[;,]/);
                    parts.forEach(p => {
                        p = p.trim();
                        if(p && p.includes('@')) { 
                            agencyEmails.push(p);
                        }
                    });
                }
            }
        });
        
        agencyEmails = [...new Set(agencyEmails)].sort();
        
        var emailList = document.getElementById('email_list_ul');
        if (emailList) {
            emailList.innerHTML = '';
            if(agencyEmails.length === 0) {
                 emailList.innerHTML = '<li class="text-muted small">Nenhum email encontrado na agência.</li>';
            } else {
                agencyEmails.forEach(em => {
                    var id = 'chk_' + Math.random().toString(36).substr(2, 9);
                    var li = document.createElement('li');
                    li.className = 'form-check mb-1';
                    li.onclick = function(e) { e.stopPropagation(); };
                    
                    var checkbox = document.createElement('input');
                    checkbox.className = 'form-check-input email-checkbox';
                    checkbox.type = 'checkbox';
                    checkbox.value = em;
                    checkbox.id = id;
                    
                    var label = document.createElement('label');
                    label.className = 'form-check-label';
                    label.htmlFor = id;
                    label.textContent = em;
                    
                    li.appendChild(checkbox);
                    li.appendChild(label);
                    emailList.appendChild(li);
                });
            }
            // Clear selected list display
            var selList = document.getElementById('selected_emails_list');
            if(selList) selList.innerText = '';
        }
    }

    document.addEventListener("DOMContentLoaded", function() {
        // Gatekeeper: Enforce Password on Load for Restricted Pages
        var restricted = ['base', 'config'];
        var needsAuth = false;
        restricted.forEach(function(pid) {
            var el = document.getElementById('page-' + pid);
            // Check if visible (PHP renders inline style display:block or empty string)
            if (el && el.style.display !== 'none') {
                el.style.display = 'none'; // Hide immediately
                needsAuth = true;
            }
        });
        
        if (needsAuth) {
            setTimeout(function() { requestConfigAccess(); }, 200);
        }

        // Init Base Page if visible or just available
        if(document.getElementById('page-base')) {
            switchBase('Processos');
        }

        // Add Enter key listener for Portability Search
        var procPortInput = document.getElementById('proc_port');
        if(procPortInput) {
            procPortInput.addEventListener('keypress', function(e) {
                if(e.key === 'Enter') {
                    e.preventDefault();
                    checkProcess();
                }
            });
        }
    });
</script>
<script>
    function formatCustomValue(val, mask, txtCase, allowed) {
        if (!val && val !== 0) return '';
        let res = String(val);

        // 1. Case
        if (txtCase === 'upper') res = res.toUpperCase();
        if (txtCase === 'lower') res = res.toLowerCase();

        // 2. Allowed Chars
        let stripRegex = null;
        if (allowed === 'numbers') stripRegex = /[^0-9]/g;
        else if (allowed === 'letters') stripRegex = /[^a-zA-Z]/g;
        else if (allowed === 'alphanumeric') stripRegex = /[^a-zA-Z0-9]/g;

        if (!mask) {
            if (stripRegex) res = res.replace(stripRegex, '');
            return res;
        }

        let stripped = res;
        if (stripRegex) stripped = res.replace(stripRegex, '');
        
        let output = "";
        let rawIdx = 0;
        
        for (let i = 0; i < mask.length; i++) {
            let m = mask[i];
            
            if (m === '0' || m === 'A' || m === '*') {
                while (rawIdx < stripped.length) {
                    let c = stripped[rawIdx++];
                    if (m === '0' && /[0-9]/.test(c)) { output += c; break; }
                    if (m === 'A' && /[a-zA-Z]/.test(c)) { output += c; break; }
                    if (m === '*') { output += c; break; }
                }
            } else {
                output += m;
                if (rawIdx < stripped.length && stripped[rawIdx] === m) {
                    rawIdx++;
                }
            }
        }
        return output;
    }

    function applyCustomMask(el) {
        let mask = el.getAttribute('data-mask');
        let allowed = el.getAttribute('data-allowed');
        let txtCase = el.getAttribute('data-case');
        let val = el.value;

        let output = formatCustomValue(val, mask, txtCase, allowed);
        if (el.value !== output) el.value = output;
    }

    var colFiltersLembretes = {};
    var lembretesDebounce = null;
    var lastFilterCol = null;
    var lembretesSortCol = 'Data_Lembrete';
    var lembretesSortDir = 'asc';
    var lembretesColumnOrder = [];

    function sortLembretes(col) {
        if (lembretesSortCol === col) {
            lembretesSortDir = (lembretesSortDir === 'asc') ? 'desc' : 'asc';
        } else {
            lembretesSortCol = col;
            lembretesSortDir = 'asc';
        }
        filterLembretes(null, 1);
    }

    function clearLembretesFilters() {
        var form = document.getElementById('form_lembretes_filter');
        if(form) form.reset();
        colFiltersLembretes = {};
        lembretesSortCol = 'Data_Lembrete';
        lembretesSortDir = 'asc';
        filterLembretes(null, 1);
    }

    function filterLembretes(e, page) {
        if(e) e.preventDefault();
        
        var form = document.getElementById('form_lembretes_filter');
        var fd = new FormData(form || undefined);
        fd.append('acao', 'ajax_render_lembretes_table');
        if(page) fd.append('pag', page);
        fd.append('colFilters', JSON.stringify(colFiltersLembretes));
        fd.append('sortCol', lembretesSortCol);
        fd.append('sortDir', lembretesSortDir);
        if(lembretesColumnOrder.length > 0) {
            fd.append('columnOrder', JSON.stringify(lembretesColumnOrder));
        }
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if(res.status == 'ok') {
                var container = document.getElementById('lembretes_table');
                if(container) {
                    container.innerHTML = res.html;
                    
                    // Init Sortable on Header
                    var theadRow = container.querySelector('thead tr:first-child');
                    if(theadRow && typeof Sortable !== 'undefined') {
                        new Sortable(theadRow, {
                            animation: 150,
                            onEnd: function (evt) {
                                // Capture new order
                                var newOrder = [];
                                theadRow.querySelectorAll('th').forEach(th => {
                                    var key = th.getAttribute('data-key');
                                    if(key) newOrder.push(key);
                                });
                                lembretesColumnOrder = newOrder;
                                // Refresh table to sync body and filters
                                filterLembretes(null, 1);
                            }
                        });
                    }

                    // Restore focus
                    if (lastFilterCol) {
                         var input = container.querySelector("input[onkeyup*=\"'" + lastFilterCol + "'\"]");
                         if(input) {
                             var len = input.value.length;
                             input.focus();
                             input.setSelectionRange(len, len);
                         }
                    }
                }
                if(document.getElementById('lembretes_pagination_container')) {
                    document.getElementById('lembretes_pagination_container').innerHTML = res.pagination;
                }
            }
        });
    }

    function filterLembretesCol(input, colKey) {
        colFiltersLembretes[colKey] = input.value;
        lastFilterCol = colKey;
        
        clearTimeout(lembretesDebounce);
        lembretesDebounce = setTimeout(function() {
            filterLembretes(null, 1);
        }, 500);
    }

    // Global listener to prevent leading whitespace in inputs
    document.addEventListener('input', function(e) {
        var target = e.target;
        if (target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA')) {
            var type = target.type;
            // Exclude non-text inputs
            if (['checkbox', 'radio', 'file', 'button', 'submit', 'reset', 'image', 'hidden', 'range', 'color'].indexOf(type) !== -1) {
                return;
            }
            
            var val = target.value;
            if (val && val.length > 0 && /^\s/.test(val)) {
                var start = target.selectionStart;
                var end = target.selectionEnd;
                var newVal = val.replace(/^\s+/, '');
                
                if (val !== newVal) {
                    target.value = newVal;
                    // Adjust cursor position
                    if (type !== 'email' && type !== 'number') { 
                        try {
                            var diff = val.length - newVal.length;
                            if (start >= diff) {
                                target.setSelectionRange(start - diff, end - diff);
                            } else {
                                target.setSelectionRange(0, 0);
                            }
                        } catch(err) {
                            // Ignore errors for input types that don't support selection
                        }
                    }
                }
            }
        }
    });
    // --- ANEXOS ---
    function uploadAttachment() {
        var fileInput = document.getElementById('input_anexo_file');
        if(fileInput.files.length === 0) {
            Swal.fire('Erro', 'Selecione um arquivo.', 'error');
            return;
        }
        
        var file = fileInput.files[0];
        var fd = new FormData();
        fd.append('acao', 'ajax_upload_file');
        fd.append('file', file);
        fd.append('port', currentLoadedPort);
        
        showLoading();
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            hideLoading();
            if(res.status == 'ok') {
                Swal.fire('Sucesso', res.message, 'success');
                fileInput.value = '';
                loadAttachments(currentLoadedPort);
            } else {
                Swal.fire('Erro', res.message, 'error');
            }
        })
        .catch(() => { hideLoading(); Swal.fire('Erro', 'Falha de comunicação', 'error'); });
    }

    function loadAttachments(port) {
        var fd = new FormData();
        fd.append('acao', 'ajax_list_attachments');
        fd.append('port', port);
        
        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            var tbody = document.querySelector('#table_anexos tbody');
            if(tbody) {
                tbody.innerHTML = '';
                if(res.status == 'ok' && res.files) {
                    if(res.files.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted">Nenhum arquivo anexado.</td></tr>';
                    } else {
                        res.files.forEach(f => {
                            var tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td><a href="upload/${port}/${f.name}" target="_blank">${f.name}</a></td>
                                <td>${f.size}</td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-primary me-1" onclick="renameAttachment('${f.name}')" title="Renomear"><i class="fas fa-edit"></i></button>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteAttachment('${f.name}')" title="Excluir"><i class="fas fa-trash"></i></button>
                                </td>
                            `;
                            tbody.appendChild(tr);
                        });
                    }
                }
            }
        })
        .catch(console.error);
    }

    function deleteAttachment(file) {
        Swal.fire({
            title: 'Excluir arquivo?',
            text: "Esta ação não pode ser desfeita.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sim, excluir!'
        }).then((result) => {
            if (result.isConfirmed) {
                var fd = new FormData();
                fd.append('acao', 'ajax_delete_attachment');
                fd.append('port', currentLoadedPort);
                fd.append('file', file);
                
                showLoading();
                fetch('', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    hideLoading();
                    if(res.status == 'ok') {
                        Swal.fire('Sucesso!', res.message, 'success');
                        loadAttachments(currentLoadedPort);
                    } else {
                        Swal.fire('Erro', res.message, 'error');
                    }
                });
            }
        });
    }

    function renameAttachment(oldName) {
        Swal.fire({
            title: 'Renomear Arquivo',
            input: 'text',
            inputValue: oldName,
            showCancelButton: true,
            confirmButtonText: 'Salvar',
            showLoaderOnConfirm: true,
            preConfirm: (newName) => {
                if (!newName) { Swal.showValidationMessage('Nome inválido'); return false; }
                var fd = new FormData();
                fd.append('acao', 'ajax_rename_attachment');
                fd.append('port', currentLoadedPort);
                fd.append('old', oldName);
                fd.append('new', newName);
                return fetch('', { method: 'POST', body: fd })
                    .then(response => response.json())
                    .then(data => {
                        if (data.status != 'ok') { throw new Error(data.message) }
                        return data
                    })
                    .catch(error => { Swal.showValidationMessage(`Request failed: ${error}`); });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((result) => {
            if (result.isConfirmed) {
                Swal.fire('Renomeado!', result.value.message, 'success');
                loadAttachments(currentLoadedPort);
            }
        });
    }
</script>

<!-- Modal Manage List -->
<div class="modal fade" id="modalManageList" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-navy text-white">
                <h5 class="modal-title">Gerenciar Lista</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="list_id">
                <div class="mb-3">
                    <label class="form-label">Nome da Lista</label>
                    <input type="text" class="form-control" id="list_name">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="saveList()">Salvar</button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Manage Template -->
<div class="modal fade" id="modalManageTemplate" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-navy text-white">
                <h5 class="modal-title">Gerenciar Modelo</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="tpl_id">
                <div class="mb-3">
                    <label class="form-label">Título</label>
                    <input type="text" class="form-control" id="tpl_title">
                </div>
                <div class="mb-3">
                    <label class="form-label">Lista</label>
                    <select class="form-select" id="tpl_list_select">
                        <!-- Populated JS -->
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Corpo do Texto</label>
                    <textarea class="form-control" id="tpl_body" rows="10"></textarea>
                    <div class="form-text">Variáveis disponíveis: {Nome}, {<?= htmlspecialchars($IDENT_ID_FIELD) ?>}, {<?= htmlspecialchars($ID_FIELD_KEY) ?>}, {DATA}, etc.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" onclick="saveTemplate()">Salvar</button>
            </div>
        </div>
    </div>
</div>

<script>
// Global state
let allTemplates = [];
let allLists = [];
let userOrder = [];
var modalList, modalTemplate;

document.addEventListener('DOMContentLoaded', function() {
    modalList = new bootstrap.Modal(document.getElementById('modalManageList'));
    modalTemplate = new bootstrap.Modal(document.getElementById('modalManageTemplate'));
    loadTemplatesData();
});

function loadTemplatesData() {
    fetch('', {
        method: 'POST', 
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'acao=ajax_get_templates_data'
    })
    .then(r => r.json())
    .then(res => {
        if(res.status == 'ok') {
            allTemplates = res.data.templates;
            allLists = res.data.lists;
            userOrder = res.data.order;
            renderTemplatesUI();
        }
    });
}

// Global Edit Mode State
var isEditMode = false;

function toggleEditMode() {
    isEditMode = !isEditMode;
    // Apply class to a parent container (tab-textos)
    let container = document.getElementById('tab-textos'); 
    let btn = document.getElementById('btnToggleEdit');
    
    if(isEditMode) {
        container.classList.add('edit-mode');
        btn.innerHTML = '<i class="fas fa-check me-1"></i> Concluir Edição';
        btn.classList.remove('btn-outline-warning');
        btn.classList.add('btn-success');
    } else {
        container.classList.remove('edit-mode');
        btn.innerHTML = '<i class="fas fa-cog me-1"></i> Gerenciar Modelos';
        btn.classList.remove('btn-success');
        btn.classList.add('btn-outline-warning');
    }
    
    // Re-render UI to update draggable attributes (DnD should be disabled when not editing)
    renderTemplatesUI();
}

function renderTemplatesUI() {
    const container = document.getElementById('templates_container');
    if(!container) return;
    container.innerHTML = '';
    
    // Check currently checked
    var currentChecked = [];
    document.querySelectorAll('.tpl-checkbox:checked').forEach(cb => currentChecked.push(cb.value));
    
    // Sort lists
    let listMap = {};
    allLists.forEach(l => listMap[l.id] = l);
    
    let sortedLists = [];
    if(Array.isArray(userOrder)) {
        userOrder.forEach(lid => {
            if(listMap[lid]) {
                sortedLists.push(listMap[lid]);
                delete listMap[lid];
            }
        });
    }
    Object.values(listMap).forEach(l => sortedLists.push(l));
    
    sortedLists.forEach((list) => {
        container.appendChild(createListElement(list, null, currentChecked));
    });
    
    // Uncategorized
    let uncategorized = allTemplates.filter(t => !t.list_id || t.list_id === '');
    if(uncategorized.length > 0) {
        let fakeList = {id: 'uncategorized', name: 'Sem Categoria (Geral)'};
        container.appendChild(createListElement(fakeList, uncategorized, currentChecked));
    }
}

    function createListElement(list, forcedTemplates, currentChecked) {
        let tpls = forcedTemplates || allTemplates.filter(t => t.list_id == list.id);
        
        let item = document.createElement('div');
        // Removed mb-2, relied on grid gap
        item.className = 'accordion-item border rounded h-100 shadow-sm'; 
        
        // Draggable only if not uncategorized AND in edit mode
        if(list.id !== 'uncategorized' && isEditMode) {
            item.setAttribute('draggable', 'true');
            item.dataset.id = list.id;
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragover', handleDragOver);
            item.addEventListener('drop', handleDrop);
            item.addEventListener('dragenter', handleDragEnter);
            item.addEventListener('dragleave', handleDragLeave);
            item.addEventListener('dragend', handleDragEnd);
        }
        
        let headerId = 'heading_' + list.id;
        let collapseId = 'collapse_' + list.id;
        
        let editBtn = (list.id !== 'uncategorized') 
            ? `<div class="ms-auto edit-only" style="z-index:10;">
                <button class="btn btn-link btn-sm text-white-50 p-0 me-2" onclick="openEditList('${list.id}', '${list.name}', event)" title="Editar Lista"><i class="fas fa-edit"></i></button>
                <button class="btn btn-link btn-sm text-danger p-0" onclick="deleteList('${list.id}', event)" title="Excluir Lista"><i class="fas fa-trash"></i></button>
            </div>` 
            : '';

         // Navy Header Style
        let html = `
            <h2 class="accordion-header position-relative d-flex align-items-center bg-navy rounded-top" id="${headerId}">
                <button class="accordion-button bg-navy collapsed flex-grow-1 fw-bold text-white shadow-none" type="button" data-bs-toggle="collapse" data-bs-target="#${collapseId}" aria-expanded="false">
                    ${list.name}
                </button>
                <div class="position-absolute end-0 pe-5 d-flex align-items-center">
                    ${editBtn}
                </div>
            </h2>
            <div id="${collapseId}" class="accordion-collapse collapse" aria-labelledby="${headerId}" data-bs-parent="#templates_container">
                <div class="accordion-body bg-white p-2" style="max-height: 300px; overflow-y: auto;">
                    <ul class="list-group list-group-flush">
                        ${tpls.map(t => createTemplateItem(t, currentChecked)).join('')}
                    </ul>
                    ${list.id !== 'uncategorized' ? 
                    `<div class="text-center mt-2 pt-2 border-top edit-only">
                        <button class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="openTemplateModal(null, '${list.id}')"><i class="fas fa-plus me-1"></i> Novo Modelo</button>
                    </div>` : ''}
                </div>
            </div>
        `;
        item.innerHTML = html;
        return item;
    }

function createTemplateItem(t, currentChecked) {
    let checked = currentChecked.includes(t.id) ? 'checked' : '';
    // Escape corpo for safe HTML attribute
    let safeCorpo = (t.corpo || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    return `
    <li class="list-group-item bg-transparent border-bottom p-0">
        <div class="d-flex justify-content-between align-items-center px-3 py-2">
            <div class="form-check flex-grow-1">
                <input class="form-check-input tpl-checkbox" type="checkbox" value="${t.id}" id="tpl_${t.id}" onchange="generateText()" ${checked}>
                <label class="form-check-label" for="tpl_${t.id}">
                    ${t.titulo}
                </label>
            </div>
            <div class="d-flex align-items-center">
                <button class="btn btn-sm text-navy p-0 me-2 btn-preview-tema" onclick="toggleTemplatePreview('${t.id}', event)" title="Visualizar conteúdo">
                    <i class="fas fa-eye"></i>
                </button>
                <div class="edit-only">
                     <button class="btn btn-sm text-secondary p-0 me-2" onclick="openTemplateModal('${t.id}')"><i class="fas fa-pen"></i></button>
                     <button class="btn btn-sm text-danger p-0" onclick="deleteTemplate('${t.id}')"><i class="fas fa-times"></i></button>
                </div>
            </div>
        </div>
        <div class="tpl-preview-area" id="tpl_preview_${t.id}" style="display:none; padding: 8px 16px 12px 40px;">
            <div style="background: #f0f4f8; border-left: 3px solid #001f3f; border-radius: 4px; padding: 10px 12px; font-size: 0.85rem; white-space: pre-wrap; word-wrap: break-word; color: #333; max-height: 200px; overflow-y: auto;" data-corpo="${safeCorpo}"></div>
        </div>
    </li>
    `;
}

function toggleTemplatePreview(templateId, event) {
    event.preventDefault();
    event.stopPropagation();
    
    var previewDiv = document.getElementById('tpl_preview_' + templateId);
    if (!previewDiv) return;
    
    var btn = event.currentTarget;
    var icon = btn.querySelector('i');
    
    if (previewDiv.style.display === 'none') {
        // Populate content from the template data
        var t = allTemplates.find(x => x.id == templateId);
        var contentDiv = previewDiv.querySelector('div');
        if (t && contentDiv) {
            // Generate preview with current process data (replace placeholders)
            var data = {};
            document.querySelectorAll('#form_processo input, #form_processo select, #form_processo textarea').forEach(function(el) {
                if(el.name) {
                    var k = el.name.replace('client_', '').replace('agency_', '').replace('reg_new_', '').replace('reg_', '');
                    data[k] = el.value;
                }
            });
            
            // Replace placeholders in corpo
            var text = t.corpo || '';
            text = text.replace(/\{([^}]+)\}/g, function(match, key) {
                var upperKey = key.trim().toUpperCase();
                for (var dk in data) {
                    if (dk.toUpperCase() === upperKey && data[dk]) {
                        return data[dk];
                    }
                }
                return match; // Keep placeholder if no data
            });
            
            contentDiv.textContent = text;
        }
        previewDiv.style.display = 'block';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
        btn.classList.add('text-primary');
        btn.classList.remove('text-navy');
    } else {
        previewDiv.style.display = 'none';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
        btn.classList.remove('text-primary');
        btn.classList.add('text-navy');
    }
}

// DnD
let dragSrcEl = null;

function handleDragStart(e) {
    dragSrcEl = this;
    e.dataTransfer.effectAllowed = 'move';
    this.style.opacity = '0.4';
}
function handleDragOver(e) {
    if (e.preventDefault) e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    return false;
}
function handleDragEnter(e) {
    this.classList.add('border-primary');
}
function handleDragLeave(e) {
    this.classList.remove('border-primary');
}
function handleDrop(e) {
    if (e.stopPropagation) e.stopPropagation();
    this.classList.remove('border-primary');
    
    if (dragSrcEl != this && this.parentNode === dragSrcEl.parentNode) {
        // Swap logic
        let container = this.parentNode;
        let children = Array.from(container.children);
        let srcIndex = children.indexOf(dragSrcEl);
        let dstIndex = children.indexOf(this);
        
        if (srcIndex < dstIndex) {
            this.after(dragSrcEl);
        } else {
            this.before(dragSrcEl);
        }
        saveListOrder();
    }
    return false;
}
function handleDragEnd(e) {
    this.style.opacity = '1';
    document.querySelectorAll('.accordion-item').forEach(col => {
        col.classList.remove('border-primary');
    });
}

function saveListOrder() {
    let order = [];
    document.querySelectorAll('#templates_container .accordion-item').forEach(el => {
        if(el.dataset.id && el.dataset.id !== 'uncategorized') order.push(el.dataset.id);
    });
    
    var fd = new FormData();
    fd.append('acao', 'ajax_reorder_lists');
    order.forEach(id => fd.append('order[]', id));
    
    fetch('', { method: 'POST', body: fd });
}

// List Management
function openListModal() {
    document.getElementById('list_id').value = '';
    document.getElementById('list_name').value = '';
    modalList.show();
}

function openEditList(id, name, e) {
    if(e) e.stopPropagation(); // prevent accordion toggle
    document.getElementById('list_id').value = id;
    document.getElementById('list_name').value = name;
    modalList.show();
}

function saveList() {
    var id = document.getElementById('list_id').value;
    var name = document.getElementById('list_name').value;
    
    var fd = new FormData();
    fd.append('acao', 'ajax_save_list');
    fd.append('id', id);
    fd.append('name', name);
    
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.status == 'ok') {
            modalList.hide();
            Swal.fire('Sucesso', 'Lista salva.', 'success');
            loadTemplatesData();
        } else {
            Swal.fire('Erro', res.message, 'error');
        }
    });
}

function deleteList(id, e) {
    if(e) e.stopPropagation();
    Swal.fire({
        title: 'Excluir Lista?',
        text: "Os modelos voltarão para 'Sem Categoria'.",
        icon: 'warning',
        showCancelButton: true
    }).then((result) => {
        if (result.isConfirmed) {
            var fd = new FormData();
            fd.append('acao', 'ajax_delete_list');
            fd.append('id', id);
            fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                Swal.fire('Excluído!', '', 'success');
                loadTemplatesData();
            });
        }
    });
}

// Template Management
function openTemplateModal(id = null, listId = null) {
    // Populate list select
    var select = document.getElementById('tpl_list_select');
    select.innerHTML = '<option value="">Sem Categoria</option>';
    allLists.forEach(l => {
        select.innerHTML += `<option value="${l.id}">${l.name}</option>`;
    });
    
    if(id) {
        // Edit mode
        var tpl = allTemplates.find(t => t.id == id);
        if(tpl) {
            document.getElementById('tpl_id').value = tpl.id;
            document.getElementById('tpl_title').value = tpl.titulo;
            document.getElementById('tpl_body').value = tpl.corpo;
            document.getElementById('tpl_list_select').value = tpl.list_id || '';
        }
    } else {
        // New mode
        document.getElementById('tpl_id').value = '';
        document.getElementById('tpl_title').value = '';
        document.getElementById('tpl_body').value = '';
        document.getElementById('tpl_list_select').value = listId || '';
    }
    
    modalTemplate.show();
}

function saveTemplate() {
    var id = document.getElementById('tpl_id').value;
    var title = document.getElementById('tpl_title').value;
    var body = document.getElementById('tpl_body').value;
    var list_id = document.getElementById('tpl_list_select').value;
    
    var fd = new FormData();
    fd.append('acao', 'ajax_save_template');
    fd.append('id', id);
    fd.append('titulo', title);
    fd.append('corpo', body);
    fd.append('list_id', list_id);
    
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.status == 'ok') {
            modalTemplate.hide();
            Swal.fire('Sucesso', 'Modelo salvo.', 'success');
            loadTemplatesData();
        } else {
            Swal.fire('Erro', res.message, 'error');
        }
    });
}

function deleteTemplate(id) {
    Swal.fire({
        title: 'Excluir Modelo?',
        icon: 'warning',
        showCancelButton: true
    }).then((result) => {
        if (result.isConfirmed) {
            var fd = new FormData();
            fd.append('acao', 'ajax_delete_template');
            fd.append('id', id);
            fetch('', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                Swal.fire('Excluído!', '', 'success');
                loadTemplatesData();
            });
        }
    });
}

</script>
<div class="modal fade" id="modalLembrete" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" onsubmit="event.preventDefault(); saveLembrete();">
            <div class="modal-header bg-navy text-white">
                <h5 class="modal-title"><i class="fas fa-bell me-2"></i>Definir Lembrete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info small"><i class="fas fa-info-circle me-1"></i> Defina a data e hora para o alerta.</div>
                <div class="mb-3">
                    <label class="form-label">Data e Hora (Lembrete)</label>
                    <input type="datetime-local" id="lembrete_data" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small text-muted">Data da Última Atualização</label>
                    <input type="datetime-local" id="lembrete_last_update_input" class="form-control form-control-sm">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-danger me-auto" onclick="clearLembrete()">Limpar Lembrete</button>
                <button type="submit" class="btn btn-navy">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
var modalLembrete = new bootstrap.Modal(document.getElementById('modalLembrete'));

function openLembreteModal() {
    var val = '';
    var input = document.querySelector('input[name="Data_Lembrete"]');
    if(input) {
        val = input.value;
    } else {
        val = "<?= $processo ? get_value_ci($processo, 'Data_Lembrete') : '' ?>";
    }
    
    // Convert d/m/Y H:i to YYYY-MM-DDTHH:MM for input type=datetime-local
    if(val && val.indexOf('/') > -1) {
        var parts = val.split(' ');
        var d = parts[0].split('/');
        var t = parts.length > 1 ? parts[1] : '00:00';
        if(d.length === 3) {
            val = d[2] + '-' + d[1] + '-' + d[0] + 'T' + t;
        }
    } else if(val && val.indexOf('T') === -1 && val.indexOf(' ') > -1 && val.indexOf('-') > -1) {
        // Handle Y-m-d H:i
        val = val.replace(' ', 'T');
    }
    
    // Remove seconds if present to fit datetimelocal strictness if needed
    if(val && val.length > 16) {
        val = val.substring(0, 16);
    }
    
    document.getElementById('lembrete_data').value = val;
    
    document.getElementById('lembrete_data').value = val;
    
    // Show Last Update in Modal (Editable)
    var lastUpdateInput = document.getElementById('lembrete_last_update_input');
    var tsControl = document.getElementById('timestamp_controle');
    if(lastUpdateInput) {
        var ts = '';
        if(tsControl && tsControl.value) ts = tsControl.value;
        
        // Convert d/m/Y H:i:s to YYYY-MM-DDTHH:MM
        if(ts && ts.indexOf('/') > -1) {
            var parts = ts.split(' ');
            var d = parts[0].split('/');
            var t = parts.length > 1 ? parts[1] : '00:00';
            // strip seconds for datetime-local
            if(t.length > 5) t = t.substring(0, 5); 
            
            if(d.length === 3) {
                ts = d[2] + '-' + d[1] + '-' + d[0] + 'T' + t;
            }
        }
        lastUpdateInput.value = ts;
    }
    
    modalLembrete.show();
}

function saveLembrete() {
    var val = document.getElementById('lembrete_data').value;
    var lastUpdateVal = document.getElementById('lembrete_last_update_input').value;

    // Get ID dynamically from the form (SPA support)
    var pid = '';
    var inputPort = document.getElementById('proc_port');
    if(inputPort) pid = inputPort.value;
    
    // Fallback to PHP static ID if page loaded directly with ID and not via SPA (though proc_port should be populated)
    if(!pid) pid = '<?= $id ?>';
    
    if(!pid) { Swal.fire('Erro', 'Processo não identificado', 'error'); return; }
    
    // Save Lembrete
    var fd = new FormData();
    fd.append('acao', 'ajax_save_field');
    fd.append('id', pid);
    fd.append('field', 'Data_Lembrete');
    fd.append('value', val);
    
    fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if(res.status == 'ok') {
            // After saving Lembrete, save Ultima Alt if changed or present
            // We save it regardless if it's in the modal
            var fd2 = new FormData();
            fd2.append('acao', 'ajax_save_field');
            fd2.append('id', pid);
            fd2.append('field', 'Ultima_Alteracao');
            fd2.append('value', lastUpdateVal);
            
            fetch('', { method: 'POST', body: fd2 })
            .then(r2 => r2.json())
            .then(res2 => {
                 // Update Form Fields
                var input = document.querySelector('input[name="Data_Lembrete"]');
                if(input) input.value = val;
                
                var tsControl = document.getElementById('timestamp_controle');
                // We need to format lastUpdateVal back to d/m/Y H:i:s for the system consistency
                // But ajax_save_field handles storage. We just need to update view.
                // It's tricky to get the exact formatted string back without backend, 
                // but let's assume success.
                // Ideally reload process data, but let's just update timestamp_controle if needed?
                // Actually, saving Ultima_Alteracao manually might desync Optimistic Lock if we don't update local hidden field.
                // We should probably reload the process or update the hidden inputs carefully.
                // For now, let's just sweet alert.
                
                // Update Icon Visuals immediately
                updateLembreteStatus(val);
                
                modalLembrete.hide();
                Swal.fire({
                    title: 'Sucesso', 
                    text: 'Dados atualizados.', 
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
            });

        } else {
            Swal.fire('Erro', res.message, 'error');
        }
    });
}

function updateLembreteStatus(val) {
    var icons = document.querySelectorAll('.helper-lembrete');
    if(icons.length == 0) return;
    
    var iconClass = 'fa-bell';
    var colorClass = 'text-warning'; // Default empty
    var title = 'Definir Lembrete';
    var isBeat = false;

    if (val && val.trim() !== '') {
        // Parse date. Format expected: d/m/Y H:i
        var parts = val.split(' ');
        if(parts.length >= 2) {
             var dParts = parts[0].split('/');
             var tParts = parts[1].split(':');
             
             if(dParts.length == 3) {
                 // Create Date object (Month is 0-indexed)
                 var lDate = new Date(dParts[2], dParts[1]-1, dParts[0], tParts[0], tParts[1]);
                 var now = new Date();
                 
                 if (lDate < now) {
                     colorClass = 'text-danger';
                     isBeat = true;
                     title = 'Lembrete Vencido: ' + val;
                 } else {
                     colorClass = 'text-success';
                     title = 'Lembrete: ' + val;
                 }
             }
        }
    }

    icons.forEach(i => {
        i.className = 'fas ' + iconClass + ' ' + colorClass + ' helper-lembrete ' + (isBeat ? 'fa-beat-fade' : '');
        i.title = title;
    });
}

function clearLembrete() {
     document.getElementById('lembrete_data').value = '';
     saveLembrete();
}
</script>
<?php
$initAuth = true;
if (file_exists('config_auth.json')) {
    $ac = json_decode(file_get_contents('config_auth.json'), true);
    if(isset($ac['required'])) $initAuth = filter_var($ac['required'], FILTER_VALIDATE_BOOLEAN);
}
?>
<div id="page-config-hub" class="page-section" style="display:none">
    <div class="container text-center mt-5">
        <h3 class="text-navy mb-5"><i class="fas fa-cogs me-2"></i>Painel de Configurações</h3>
        <div class="row justify-content-center g-4">
            <div class="col-md-4">
                <div class="card h-100 shadow-sm hover-shadow" style="cursor:pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'" onclick="showPage('base')">
                    <div class="card-body py-5">
                        <i class="fas fa-database fa-3x text-primary mb-3"></i>
                        <h4 class="card-title text-navy">Gestão de Bases</h4>
                        <p class="card-text text-muted">Gerenciar importação, edição e visualização das bases de dados (Processos, Identificação e outros).</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm hover-shadow" style="cursor:pointer; transition: transform 0.2s;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'" onclick="showPage('config')">
                    <div class="card-body py-5">
                        <i class="fas fa-tools fa-3x text-warning mb-3"></i>
                        <h4 class="card-title text-navy">Configurações de Campos</h4>
                        <p class="card-text text-muted">Personalizar campos, rótulos, tipos de dados e organização dos formulários e tabelas.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card h-100 shadow-sm" style="background-color: #f8f9fa;">
                    <div class="card-body py-5 text-center">
                        <i class="fas fa-user-lock fa-3x text-secondary mb-3"></i>
                        <h4 class="card-title text-navy">Segurança</h4>
                        <div class="form-check form-switch d-flex justify-content-center mt-3">
                            <input class="form-check-input me-2" type="checkbox" id="chkConfigAuth" onchange="toggleConfigAuth(this)" <?php echo $initAuth ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="chkConfigAuth">Exigir Senha</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAuth" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <form class="modal-content" onsubmit="event.preventDefault(); checkAuth();">
            <div class="modal-header bg-navy text-white">
                <h5 class="modal-title"><i class="fas fa-lock me-2"></i>Acesso Restrito</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Senha de Administrador</label>
                    <input type="password" id="auth_pass" class="form-control text-center" placeholder="****" required>
                </div>
                <div id="auth_error" class="text-danger small text-center" style="display:none">Senha incorreta.</div>
            </div>
            <div class="modal-footer justify-content-center">
                <button class="btn btn-navy w-100">Desbloquear</button>
            </div>
        </form>
    </div>
</div>

<script>
    window.isConfigUnlocked = false; // Global flag
    var CONFIG_PASS = '1234';
    var configAuthRequired = <?= $initAuth ? 'true' : 'false' ?>;


    function toggleConfigAuth(e) {
        var req = e.checked ? 'true' : 'false';
        var fd = new FormData();
        fd.append('acao', 'ajax_save_config_auth');
        fd.append('required', req);
        
        fetch('', {method:'POST', body:fd}).then(r=>r.json()).then(res=>{
            if(res.status=='ok') {
                Swal.fire({
                    icon: 'success',
                    title: 'Configuração salva',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 2000
                });
                configAuthRequired = (req === 'true');
            } else {
                Swal.fire('Erro', 'Falha ao salvar.', 'error');
                e.checked = !e.checked; // revert
            }
        });
    }

    function requestConfigAccess() {
        if (!configAuthRequired || window.isConfigUnlocked) {
            window.isConfigUnlocked = true;
            showPage('config-hub');
            return;
        }

        document.getElementById('auth_pass').value = '';
        document.getElementById('auth_error').style.display = 'none';
        var m = new bootstrap.Modal(document.getElementById('modalAuth'));
        m.show();
        setTimeout(() => document.getElementById('auth_pass').focus(), 500);
    }

    function checkAuth() {
        var p = document.getElementById('auth_pass').value;
        if (p === CONFIG_PASS) {
            window.isConfigUnlocked = true;
            var el = document.getElementById('modalAuth');
            var m = bootstrap.Modal.getInstance(el);
            if(m) m.hide();
            showPage('config-hub');
            Swal.fire({
                icon: 'success',
                title: 'Acesso Liberado',
                showConfirmButton: false,
                timer: 1000
            });
        } else {
            document.getElementById('auth_error').style.display = 'block';
            document.getElementById('auth_pass').value = '';
            document.getElementById('auth_pass').focus();
        }
    }
</script>
</body>
