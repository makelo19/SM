<?php
// SM - SISTEMA MODULAR - VERSÃƒO COMPLETA
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

// ConfiguraÃ§Ãµes
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
$config->ensureField('Base_processos_schema', ['key'=>'ID', 'label'=>'ID', 'type'=>'text', 'required'=>true]);
$config->ensureField('Base_processos_schema', ['key'=>'Nome_atendente', 'label'=>'Nome Atendente', 'type'=>'text', 'required'=>true]);
$config->ensureField('Base_processos_schema', ['key'=>'DATA', 'label'=>'Data/Hora', 'type'=>'datetime', 'required'=>true]);
$config->ensureField('Base_processos_schema', ['key'=>'STATUS', 'label'=>'STATUS', 'type'=>'select', 'options'=>'EM ANDAMENTO, CONCLUÃDO, CANCELADO, ASSINADO', 'required'=>true]);

// Base de Contatos de Email
$config->ensureField('Base_contatos_email.json', ['key'=>'Nome', 'label'=>'Nome do Contato', 'type'=>'text', 'required'=>true]);
$config->ensureField('Base_contatos_email.json', ['key'=>'E-mail', 'label'=>'E-mail(s)', 'type'=>'text', 'required'=>true]);

// Ensure Data_Ultima_Cobranca exists in Processos
// $config->ensureField('Base_processos_schema', ['key'=>'Data_Ultima_Cobranca', 'label'=>'Data da Ãšltima CobranÃ§a', 'type'=>'date']);
// Ensure Ultima_Alteracao exists and is manual
// $config->ensureField('Base_processos_schema', ['key'=>'Ultima_Alteracao', 'label'=>'Data da Ãšltima AtualizaÃ§Ã£o', 'type'=>'datetime-local']);
// ID Label should be managed via Config UI or Settings defaults. No auto-update here to prevent locking.
// Ensure Data_Lembrete exists
// $config->ensureField('Base_processos_schema', ['key'=>'Data_Lembrete', 'label'=>'Data e Hora (Lembrete)', 'type'=>'datetime-local']);

$templates = new Templates($IDENT_ID_FIELD, $db, $indexer, $ID_FIELD_KEY);
$greetings = new Greetings();
$emailTemplates = new EmailTemplates();
$lockManager = new LockManager('dados');
$uploadDir = __DIR__ . '/uploads/';

// Run one-time migration of separate files into consolidated mes.json
$db->migrateToConsolidated($IDENT_ID_FIELD);

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
// AJAX HANDLERS
// ===================================================================================
if (isset($_POST['acao']) && strpos($_POST['acao'], 'ajax_') === 0) {
    header('Content-Type: application/json');
    
    // Security Check
    if (!isset($_SESSION['logado']) || !$_SESSION['logado']) {
        if ($_POST['acao'] !== 'ajax_login') {
            ob_clean();
            echo json_encode(['status' => 'error', 'message' => 'Sessão expirada. Por favor, faça login novamente.', 'redirect' => 'login']);
            exit;
        }
    }

    $act = $_POST['acao'];
    
    if ($act == 'ajax_get_init') {
        ob_clean(); header('Content-Type: application/json');
        $procFields = $config->getFields('Base_processos_schema');
        $regFields = $config->getFields('Base_registros_schema');
        echo json_encode([
            'status' => 'ok',
            'SYSTEM_NAME' => $SYSTEM_NAME,
            'CURRENCY_SYMBOL' => $CURRENCY_SYMBOL,
            'CURRENCY_ICON' => $CURRENCY_ICON,
            'IDENT_LABEL' => $IDENT_LABEL,
            'IDENT_ICON' => $IDENT_ICON,
            'IDENT_ID_FIELD' => $IDENT_ID_FIELD,
            'ID_FIELD_KEY' => $ID_FIELD_KEY,
            'ID_LABEL' => $ID_LABEL,
            'user' => $_SESSION['nome_completo'] ?? '',
            'logado' => $_SESSION['logado'] ?? false,
            'selected_years' => $_SESSION['selected_years'] ?? [date('Y')],
            'selected_months' => $_SESSION['selected_months'] ?? [(int)date('n')],
            'procFields' => $procFields,
            'regFields' => $regFields
        ]);
        exit;
    }

    if ($act == 'ajax_login') {
        $usuario = trim($_POST['usuario'] ?? '');
        if ($usuario) {
            $_SESSION['logado'] = true;
            $_SESSION['nome_completo'] = $usuario;
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'ok']);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Nome invÃ¡lido']);
        }
        exit;
    }
    
    if ($act == 'ajax_logout') {
        session_destroy();
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok']);
        exit;
    }
    
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
                // User said "Caso a AgÃªncia seja localizada em outro cadastro vÃ¡lido".
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
                $results[] = ['port' => $rPort, 'data' => get_value_ci($r, 'DATA_DEPOSITO') ?: 'N/A', 'status' => get_value_ci($r, 'STATUS') ?: 'IdentificaÃ§Ã£o', 'source' => 'Identificacao'];
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

    if ($act == 'ajax_save_lembrete') {
        $id = $_POST['id'] ?? '';
        $dataLembrete = $_POST['data_lembrete'] ?? '';
        $ultimaAlteracao = $_POST['ultima_alteracao'] ?? '';

        if (!$id) {
             echo json_encode(['status' => 'error', 'message' => 'ID nÃ£o fornecido']);
             exit;
        }

        // Format Date Lembrete
        if (!empty($dataLembrete)) {
             $dt = DateTime::createFromFormat('Y-m-d\TH:i', $dataLembrete);
             if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dataLembrete);
             if ($dt) $dataLembrete = $dt->format('d/m/Y H:i:s');
        }

        // Format Ultima Alteracao
        if (!empty($ultimaAlteracao)) {
             $dt = DateTime::createFromFormat('Y-m-d\TH:i', $ultimaAlteracao);
             if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $ultimaAlteracao); 
             if ($dt) $ultimaAlteracao = $dt->format('d/m/Y H:i:s');
        }

        // 1. Try Indexer (Instant Optimization)
        $foundFile = $indexer->get($id);
        
        if (!$foundFile) {
            // Fallback (Slow Search)
            $files = $db->getAllProcessFiles();
            $foundFile = $db->findFileForRecord($files, $ID_FIELD_KEY, $id);
            if ($foundFile) $indexer->set($id, $foundFile); // Update index
        }

        if ($foundFile) {
            $updates = [];
            $updates['Data_Lembrete'] = $dataLembrete;
            if($ultimaAlteracao) $updates['Ultima_Alteracao'] = $ultimaAlteracao;

            $db->update($foundFile, $ID_FIELD_KEY, $id, $updates);
            
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'message' => 'Lembrete salvo com sucesso!', 'ultima_alteracao' => $ultimaAlteracao]);
            exit;
        }

        echo json_encode(['status' => 'error', 'message' => 'Processo nÃ£o encontrado.']);
        exit;
    }

    if ($act == 'ajax_save_field') {
        $id = $_POST['id'] ?? '';
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';

        if (!$id || !$field) {
            echo json_encode(['status' => 'error', 'message' => 'ParÃ¢metros invÃ¡lidos']);
            exit;
        }
        
        // Fix for Data_Lembrete format to match system standard (d/m/Y H:i:s)
        // because the input type=datetime-local sends Y-m-dTH:i
        if (($field === 'Data_Lembrete' || $field === 'Ultima_Alteracao') && !empty($value)) {
             $dt = DateTime::createFromFormat('Y-m-d\TH:i', $value);
             if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value); // Fallback
             if ($dt) {
                 $value = $dt->format('d/m/Y H:i:s');
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
        
        echo json_encode(['status' => 'error', 'message' => 'Processo nÃ£o encontrado']);
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
                echo json_encode(['status'=>'ok', 'message'=>'Registros excluÃ­dos.']);
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


    // --- GREETINGS HANDLERS ---
    if ($act == 'ajax_get_greetings') {
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'data'=>$greetings->getAll(), 'defaults'=>$greetings->getDefaults()]);
        exit;
    }
    
    if ($act == 'ajax_save_greeting') {
        $id = $_POST['id'] ?? '';
        $title = $_POST['titulo'] ?? '';
        $body = $_POST['corpo'] ?? '';
        $type = $_POST['type'] ?? 'greeting';
        
        ob_clean(); header('Content-Type: application/json');
        if (!$title) { echo json_encode(['status'=>'error', 'message'=>'TÃ­tulo obrigatÃ³rio.']); exit; }
        
        $greetings->save($id, $title, $body, $type);
        echo json_encode(['status'=>'ok', 'message'=>'Salvo com sucesso.']);
        exit;
    }
    
    if ($act == 'ajax_delete_greeting') {
        $id = $_POST['id'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        $greetings->delete($id);
        echo json_encode(['status'=>'ok', 'message'=>'ExcluÃ­do com sucesso.']);
        exit;
    }
    
    if ($act == 'ajax_set_default_greeting') {
        $id = $_POST['id'] ?? '';
        $type = $_POST['type'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        $greetings->setDefault($id, $type);
        echo json_encode(['status'=>'ok', 'message'=>'PadrÃ£o definido.']);
        exit;
    }
    
    if ($act == 'ajax_generate_greeting_text') {
        $id = $_POST['id'] ?? '';
        $dataRaw = $_POST['data'] ?? '[]';
        $formData = json_decode($dataRaw, true);
        
        ob_clean(); header('Content-Type: application/json');
        $text = $greetings->generate($id, $formData);
        echo json_encode(['status' => 'ok', 'text' => $text]);
        exit;
    }

    // 3. GERAÃ‡ÃƒO DE TEXTO
    if ($act == 'ajax_generate_email_text') {
        $tplId = $_POST['tpl_id'];
        $formData = json_decode($_POST['data'], true); 
        
        $allTemplates = $emailTemplates->getAll();
        $textoSubject = '';
        $textoBase = '';
        foreach($allTemplates as $t) {
            if($t['id'] == $tplId) {
                $textoSubject = current(explode(';', $t['titulo'])); // Use first segment as actual base title string
                $textoSubject = $t['titulo']; 
                $textoBase = $t['corpo'];
                break;
            }
        }
        
        ob_clean(); header('Content-Type: application/json');
        if ($textoBase || $textoSubject) {
            $normalizedData = [];
            foreach ($formData as $key => $val) {
                $upperKey = mb_strtoupper($key, 'UTF-8');
                if (isset($normalizedData[$upperKey])) {
                    if (trim((string)$normalizedData[$upperKey]) === '' && trim((string)$val) !== '') {
                        // overwrite
                    } else {
                        continue;
                    }
                }
                if (is_string($val)) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                        $dt = DateTime::createFromFormat('Y-m-d', $val);
                        if ($dt) $val = $dt->format('d/m/Y');
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $val)) {
                        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                        if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                        if ($dt) $val = $dt->format('d/m/Y H:i');
                    }
                }
                if (trim((string)$val) === '') {
                    $val = '_______';
                }
                $normalizedData[$upperKey] = $val;
            }

            $callback = function($matches) use ($normalizedData) {
                $original = $matches[0];
                $key = trim($matches[1]);
                $upperKey = mb_strtoupper($key, 'UTF-8');
                if (isset($normalizedData[$upperKey])) return $normalizedData[$upperKey];
                return $original;
            };

            $textoBase = preg_replace_callback('/\{([^}]+)\}/', $callback, $textoBase);
            $textoSubject = preg_replace_callback('/\{([^}]+)\}/', $callback, $textoSubject);
            
            echo json_encode(['status' => 'ok', 'text' => $textoBase, 'subject' => $textoSubject]);
        } else {
            echo json_encode(['status' => 'error', 'text' => 'Modelo nÃ£o encontrado.']);
        }
        exit;
    }

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
                if (is_string($val)) {
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
                        $dt = DateTime::createFromFormat('Y-m-d', $val);
                        if ($dt) $val = $dt->format('d/m/Y');
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $val)) {
                        $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                        if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                        if ($dt) $val = $dt->format('d/m/Y H:i');
                    }
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
            echo json_encode(['status' => 'error', 'text' => 'Modelo nÃ£o encontrado.']);
        }
        exit;
    }

    // 4. Salvar HistÃ³rico
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
                 'Nome_atendente' => $_SESSION['nome_completo']
             ]);
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'data' => date('d/m/Y H:i'), 'usuario' => $_SESSION['nome_completo'], 'id' => $id]);
        exit;
    }

    if ($act == 'ajax_delete_history') {
        $ids = $_POST['ids'] ?? [];
        $port = $_POST['port'] ?? '';
        if (!is_array($ids)) $ids = json_decode($ids, true);
        if ($templates->deleteHistoryItems($ids, $port)) {
            echo json_encode(['status' => 'ok']);
        } else {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }

    if ($act == 'ajax_delete_process_history') {
        $ids = $_POST['ids'] ?? [];
        $port = $_POST['port'] ?? '';
        if (!is_array($ids)) $ids = json_decode($ids, true);
        
        if (!empty($port) && !empty($ids)) {
            // Delete from consolidated _registros_processo inside the process record
            $file = $indexer->get($port);
            if (!$file) {
                $years = $_SESSION['selected_years'] ?? [date('Y')];
                $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                $files = $db->getProcessFiles($years, $months);
                $file = $db->findFileForRecord($files, $ID_FIELD_KEY, $port);
            }
            if ($file && $db->deleteProcessSubDataItems($file, $ID_FIELD_KEY, $port, '_registros_processo', $ids)) {
                echo json_encode(['status' => 'ok']);
            } else {
                echo json_encode(['status' => 'error']);
            }
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Missing port or ids']);
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
            echo json_encode(['status'=>'error', 'message'=>htmlspecialchars($IDENT_LABEL) . ' Ã© obrigatÃ³ria.']);
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
                     echo json_encode(['status'=>'error', 'message'=>'Nova ' . htmlspecialchars($IDENT_LABEL) . ' jÃ¡ existe na base.']);
                     exit;
                 }
             }
             $res = $db->update('Identificacao.json', $IDENT_ID_FIELD, $originalPort, $data);
             $msg = "Registro atualizado com sucesso!";
        } else {
             // Inserting
             $exists = $db->find('Identificacao.json', $IDENT_ID_FIELD, $port);
             if ($exists) {
                 echo json_encode(['status'=>'error', 'message'=>htmlspecialchars($IDENT_LABEL) . ' jÃ¡ existe.']);
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
        
        // Fix: Support singular 'port' (legacy/JS compatibility)
        if (empty($ports) && isset($_POST['port'])) {
            $ports = [$_POST['port']];
        }

        ob_clean(); header('Content-Type: application/json');
        if (!empty($ports)) { // Check if $ports is not empty
            if ($db->deleteMany('Identificacao.json', $IDENT_ID_FIELD, $ports)) { // Changed from delete to deleteMany
                // Cleanup Uploads for each port
                foreach ($ports as $port) {
                    $uDir = 'upload/' . $port;
                    if(is_dir($uDir)) deleteDirectory($uDir);
                }
                echo json_encode(['status'=>'ok', 'message'=>'Registro(s) excluÃ­do(s).']); // Updated message for multiple deletions
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Identificador invÃ¡lido.']);
        }
        exit;
    }

    // --- ATTACHMENT HANDLERS ---
    if ($act == 'ajax_upload_file') {
        $port = $_POST['port'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        if (!$port) { echo json_encode(['status'=>'error', 'message'=>'ID do processo invÃ¡lido.']); exit; }
        
        if (!isset($_FILES['file'])) {
            echo json_encode(['status'=>'error', 'message'=>'Nenhum arquivo enviado.']); exit;
        }
        
        $res = AttachmentManager::resolveDir($port, $db, $indexer, true);
        $targetDir = $res['dir'];
        
        if (!$targetDir) {
             echo json_encode(['status'=>'error', 'message'=>'Falha ao criar diretÃ³rio de anexos.']); 
             exit;
        }
        
        // Ensure trailing slash
        if (substr($targetDir, -1) !== '/') $targetDir .= '/';
        
        $filesInfo = $_FILES['file'];
        $isMulti = is_array($filesInfo['name']);
        
        $fileCount = $isMulti ? count($filesInfo['name']) : 1;
        $successCount = 0;
        
        for ($i = 0; $i < $fileCount; $i++) {
            $name = $isMulti ? $filesInfo['name'][$i] : $filesInfo['name'];
            $tmpName = $isMulti ? $filesInfo['tmp_name'][$i] : $filesInfo['tmp_name'];
            $error = $isMulti ? $filesInfo['error'][$i] : $filesInfo['error'];
            
            if ($error != UPLOAD_ERR_OK || empty($name)) continue;
            
            $fileName = basename($name);
            // Sanitize
            $fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName);
            
            $targetFile = $targetDir . $fileName;
            
            if (move_uploaded_file($tmpName, $targetFile)) {
                $successCount++;
            }
        }
        
        if ($successCount > 0) {
            // Update _anexos_ref if needed
            if ($res['updated'] && $res['file']) {
                $db->update($res['file'], $ID_FIELD_KEY, $port, ['_anexos_ref' => $res['ref']]);
            }
            echo json_encode(['status'=>'ok', 'message'=> $successCount . ' arquivo(s) anexado(s) com sucesso.']);
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Falha ao salvar ou arquivo(s) com erro.']);
        }
        exit;
    }

    if ($act == 'ajax_list_attachments') {
        $port = $_POST['port'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        if (!$port) { echo json_encode(['status'=>'error', 'message'=>'ID invÃ¡lido.']); exit; }
        
        $res = AttachmentManager::resolveDir($port, $db, $indexer, false);
        $targetDir = $res['dir'] ? $res['dir'] : '';
        if ($targetDir && substr($targetDir, -1) !== '/') $targetDir .= '/';
        
        $files = [];
        
        if ($targetDir && is_dir($targetDir)) {
            foreach (scandir($targetDir) as $file) {
                 if ($file == '.' || $file == '..') continue;
                 $path = $targetDir . $file;
                 $size = filesize($path);
                 $sizeStr = ($size > 1048576) ? round($size/1048576, 2) . ' MB' : round($size/1024, 2) . ' KB';
                 
                 $files[] = [
                     'name' => $file,
                     'url'  => $path,
                     'size' => $sizeStr
                 ];
            }
        }
        
        echo json_encode(['status'=>'ok', 'files'=>$files]);
        exit;
    }

    // ZIP Download Endpoint
    if ($act == 'ajax_download_zip') {
        $port = $_POST['port'] ?? '';
        $filesParam = $_POST['files'] ?? '';
        
        if (!$port) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'ID do processo invÃ¡lido.']);
            exit;
        }

        $res = AttachmentManager::resolveDir($port, $db, $indexer, false);
        $targetDir = $res['dir'];
        if ($targetDir && substr($targetDir, -1) !== '/') $targetDir .= '/';
        
        if (!$targetDir || !is_dir($targetDir)) {
             $targetDir = null; // No files
        }

        $filesToZip = [];

        if (!empty($filesParam) && $targetDir) {
            $filesParamArray = json_decode($filesParam, true);
            if (is_array($filesParamArray)) {
                foreach ($filesParamArray as $f) {
                    $f = basename($f); // avoid dir traversal
                    $path = $targetDir . $f;
                    if (file_exists($path) && is_file($path)) {
                        $filesToZip[] = $path;
                    }
                }
            }
        } else {
            // All files
            if (is_dir($targetDir)) {
                foreach (scandir($targetDir) as $f) {
                    if ($f == '.' || $f == '..') continue;
                    $path = $targetDir . $f;
                    if (is_file($path)) {
                        $filesToZip[] = $path;
                    }
                }
            }
        }

        if (empty($filesToZip)) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Nenhum arquivo encontrado para download.']);
            exit;
        }

        $zipPath = sys_get_temp_dir() . '/anexos_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $port) . '_' . time() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            foreach ($filesToZip as $f) {
                // Ensure name inside zip has PORT for traceability if missing natively
                $baseName = basename($f);
                if (strpos($baseName, $port . '_') !== 0 && strpos($baseName, $port . '-') !== 0) {
                    $zipName = $port . '_' . $baseName;
                } else {
                    $zipName = $baseName;
                }
                $zip->addFile($f, $zipName);
            }
            $zip->close();

            if (file_exists($zipPath)) {
                if (ob_get_level()) ob_end_clean();
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="Anexos_' . $port . '.zip"');
                header('Content-Length: ' . filesize($zipPath));
                readfile($zipPath);
                unlink($zipPath);
                exit;
            }
        }
        
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'error', 'message'=>'Falha ao criar arquivo ZIP.']);
        exit;
    }

    if ($act == 'ajax_delete_attachment') {
        $port = $_POST['port'] ?? '';
        $file = $_POST['file'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        if (!$port || !$file) { echo json_encode(['status'=>'error', 'message'=>'Dados invÃ¡lidos.']); exit; }
        
        $res = AttachmentManager::resolveDir($port, $db, $indexer, false);
        $targetDir = $res['dir'];
        if (!$targetDir) {
             echo json_encode(['status'=>'error', 'message'=>'DiretÃ³rio nÃ£o encontrado.']);
             exit;
        }
        if (substr($targetDir, -1) !== '/') $targetDir .= '/';
        
        $file = basename($file);
        $targetFile = $targetDir . $file;
        
        if (file_exists($targetFile)) {
            if (unlink($targetFile)) {
                echo json_encode(['status'=>'ok', 'message'=>'Arquivo excluÃ­do.']);
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao excluir arquivo.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Arquivo nÃ£o encontrado.']);
        }
        exit;
    }

    if ($act == 'ajax_rename_attachment') {
        $port = $_POST['port'] ?? '';
        $oldName = $_POST['old'] ?? '';
        $newName = $_POST['new'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        if (!$port || !$oldName || !$newName) { echo json_encode(['status'=>'error', 'message'=>'Dados invÃ¡lidos.']); exit; }
        
        $oldName = basename($oldName);
        $newName = basename($newName);
        // Sanitize new name
        $newName = preg_replace('/[^A-Za-z0-9._-]/', '_', $newName);
        
        $res = AttachmentManager::resolveDir($port, $db, $indexer, false);
        $dir = $res['dir'];
        if (!$dir) {
             echo json_encode(['status'=>'error', 'message'=>'DiretÃ³rio nÃ£o encontrado.']);
             exit;
        }
        if (substr($dir, -1) !== '/') $dir .= '/';
        
        if (file_exists($dir . $oldName)) {
            if (rename($dir . $oldName, $dir . $newName)) {
                echo json_encode(['status'=>'ok', 'message'=>'Arquivo renomeado.']);
            } else {
                echo json_encode(['status'=>'error', 'message'=>'Erro ao renomear.']);
            }
        } else {
            echo json_encode(['status'=>'error', 'message'=>'Arquivo original nÃ£o encontrado.']);
        }
        exit;
    }

    // --- TEMPLATE LISTS HANDLERS ---
    if ($act == 'ajax_save_list') {
        $id = $_POST['id'] ?? '';
        $name = $_POST['name'] ?? '';
        $user = $_SESSION['nome_completo'] ?? ($_SESSION['usuario'] ?? 'Unknown');
        ob_clean(); header('Content-Type: application/json');
        
        if (!$name) { echo json_encode(['status'=>'error', 'message'=>'Nome obrigatÃ³rio.']); exit; }
        
        $templates->saveList($id, $name, $user);
        echo json_encode(['status'=>'ok', 'message'=>'Lista salva.']);
        exit;
    }
    
    if ($act == 'ajax_delete_list') {
        $id = $_POST['id'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        if (!$id) { echo json_encode(['status'=>'error', 'message'=>'ID invÃ¡lido.']); exit; }
        
        $templates->deleteList($id);
        echo json_encode(['status'=>'ok', 'message'=>'Lista excluÃ­da.']);
        exit;
    }

    if ($act == 'ajax_reorder_lists') {
        $order = $_POST['order'] ?? [];
        $user = $_SESSION['nome_completo'] ?? ($_SESSION['usuario'] ?? 'Unknown');
        
        ob_clean(); header('Content-Type: application/json');
        if (!is_array($order)) { echo json_encode(['status'=>'error', 'message'=>'Dados invÃ¡lidos.']); exit; }
        
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
        if (!$title) { echo json_encode(['status'=>'error', 'message'=>'TÃ­tulo obrigatÃ³rio.']); exit; }
        
        $templates->save($id, $title, $body, $list_id);
        echo json_encode(['status'=>'ok', 'message'=>'Modelo salvo.']);
        exit;
    }

    if ($act == 'ajax_delete_template') {
        $id = $_POST['id'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        $templates->delete($id);
        echo json_encode(['status'=>'ok', 'message'=>'Modelo excluÃ­do.']);
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

    // --- EMAIL TEMPLATE LISTS HANDLERS ---
    if ($act == 'ajax_save_email_list') {
        $id = $_POST['id'] ?? '';
        $name = $_POST['name'] ?? '';
        $user = $_SESSION['nome_completo'] ?? ($_SESSION['usuario'] ?? 'Unknown');
        ob_clean(); header('Content-Type: application/json');
        
        if (!$name) { echo json_encode(['status'=>'error', 'message'=>'Nome obrigatÃ³rio.']); exit; }
        
        $emailTemplates->saveList($id, $name, $user);
        echo json_encode(['status'=>'ok', 'message'=>'Lista salva.']);
        exit;
    }
    
    if ($act == 'ajax_delete_email_list') {
        $id = $_POST['id'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        if (!$id) { echo json_encode(['status'=>'error', 'message'=>'ID invÃ¡lido.']); exit; }
        
        $emailTemplates->deleteList($id);
        echo json_encode(['status'=>'ok', 'message'=>'Lista excluÃ­da.']);
        exit;
    }

    if ($act == 'ajax_reorder_email_lists') {
        $order = $_POST['order'] ?? [];
        $user = $_SESSION['nome_completo'] ?? ($_SESSION['usuario'] ?? 'Unknown');
        
        ob_clean(); header('Content-Type: application/json');
        if (!is_array($order)) { echo json_encode(['status'=>'error', 'message'=>'Dados invÃ¡lidos.']); exit; }
        
        $emailTemplates->saveOrder($user, $order);
        echo json_encode(['status'=>'ok', 'message'=>'Ordem salva.']);
        exit;
    }

    if ($act == 'ajax_save_email_template') {
        $id = $_POST['id'] ?? '';
        $title = $_POST['titulo'] ?? '';
        $body = $_POST['corpo'] ?? '';
        $list_id = $_POST['list_id'] ?? null;
        
        ob_clean(); header('Content-Type: application/json');
        if (!$title) { echo json_encode(['status'=>'error', 'message'=>'TÃ­tulo obrigatÃ³rio.']); exit; }
        
        $emailTemplates->save($id, $title, $body, $list_id);
        echo json_encode(['status'=>'ok', 'message'=>'Modelo salvo.']);
        exit;
    }

    if ($act == 'ajax_delete_email_template') {
        $id = $_POST['id'] ?? '';
        ob_clean(); header('Content-Type: application/json');
        
        $emailTemplates->delete($id);
        echo json_encode(['status'=>'ok', 'message'=>'Modelo excluÃ­do.']);
        exit;
    }
    
    if ($act == 'ajax_get_email_templates_data') {
         $user = $_SESSION['nome_completo'] ?? ($_SESSION['usuario'] ?? 'Unknown');
         ob_clean(); header('Content-Type: application/json');
         
         $data = [
             'templates' => $emailTemplates->getAll(),
             'lists' => $emailTemplates->getLists(),
             'order' => $emailTemplates->getOrder($user)
         ];
         echo json_encode(['status'=>'ok', 'data'=>$data]);
         exit;
    }

    if ($act == 'ajax_save_process_data_record') {
        $port = $_POST['port'] ?? '';
        $uid = $_POST['uid'] ?? '';
        $usuario = $_SESSION['nome_completo'];
        $data = date('d/m/Y H:i');
        
        $fields = $config->getFields('Base_registros_schema');
        $errors = [];
        
        $isUpdate = !empty($uid);
        $record = [];
        if (!$isUpdate) {
            $record['DATA'] = $data;
            $record['USUARIO'] = $usuario;
            $record['UID'] = uniqid();
        }
        $record[$IDENT_ID_FIELD] = $port;
        
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
                if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                if ($dtObj) $val = $dtObj->format('d/m/Y');
            }
            if ($f['type'] == 'datetime' && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                if (!$dtObj) {
                     $dtObj = DateTime::createFromFormat('Y-m-d', $val);
                     if ($dtObj) $dtObj->setTime(0,0);
                }
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
                        $errors[] = "Campo obrigatÃ³rio nÃ£o preenchido: " . ($f['label'] ?: $f['key']);
                    }
                }
            }
        }
        
        if (!empty($errors)) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => implode("<br>", $errors)]);
            exit;
        }
        
        // Find the process file for this port
        $processFile = $indexer->get($port);
        if (!$processFile) {
             $years = $_SESSION['selected_years'] ?? [date('Y')];
             $months = $_SESSION['selected_months'] ?? [(int)date('n')];
             $files = $db->getProcessFiles($years, $months);
             $processFile = $db->findFileForRecord($files, $ID_FIELD_KEY, $port);
        }
        
        if (!$processFile) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Processo nÃ£o encontrado.']);
            exit;
        }
        
        $success = false;
        
        if ($isUpdate) {
             // Update existing record in _registros_processo
             $success = $db->updateProcessSubDataItem($processFile, $ID_FIELD_KEY, $port, '_registros_processo', $uid, $record);
        } else {
             // Append new record to _registros_processo
             $success = $db->appendProcessSubData($processFile, $ID_FIELD_KEY, $port, '_registros_processo', $record);
        }

        if ($success) {
            // Update Parent Timestamp
            $db->update($processFile, $ID_FIELD_KEY, $port, ['Ultima_Alteracao' => date('d/m/Y H:i:s'), 'Nome_atendente' => $_SESSION['nome_completo']]);

            // Retrieve updated registros from consolidated data
            $registros = $db->getProcessSubData($processFile, $ID_FIELD_KEY, $port, '_registros_processo');
            $registros = array_reverse($registros);
            
            // Re-calc IDs for display
            foreach($registros as &$reg) {
                $reg['_id'] = isset($reg['UID']) ? $reg['UID'] : md5(json_encode($reg));
            }

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
        $portCheck = $_POST[str_replace(' ', '_', $ID_FIELD_KEY)] ?? ($_POST[$ID_FIELD_KEY] ?? '');
        $tsCheck = $_POST['timestamp_controle'] ?? null;
        if($portCheck && $tsCheck !== null) {
            $fCheck = $indexer->get($portCheck);
            if($fCheck) {
                 $currCheck = $db->find($fCheck, $ID_FIELD_KEY, $portCheck);
                 if($currCheck && isset($currCheck['Ultima_Alteracao']) && $currCheck['Ultima_Alteracao'] !== $tsCheck) {
                     ob_clean(); header('Content-Type: application/json');
                     echo json_encode(['status'=>'error', 'message'=>'Este registro foi modificado por outro usuÃ¡rio em '.$currCheck['Ultima_Alteracao'].'. Por favor, recarregue a pÃ¡gina.']);
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
                if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                if ($dtObj) {
                    $val = $dtObj->format('d/m/Y');
                }
            }
            if (($f['type'] == 'datetime' || $f['type'] == 'datetime-local') && !empty($val)) {
                $dtObj = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                if (!$dtObj) $dtObj = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                if (!$dtObj) {
                     $dtObj = DateTime::createFromFormat('Y-m-d', $val);
                     if ($dtObj) $dtObj->setTime(0,0);
                }
                
                if ($dtObj) $val = $dtObj->format('d/m/Y H:i');
            }
            $data[$key] = $val;
            
            // Validation
            if ($f['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                $errors[] = "O campo " . ($f['label'] ?: $key) . " deve conter apenas nÃºmeros.";
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
                            $errors[] = "Campo obrigatÃ³rio do processo nÃ£o preenchido: " . ($f['label'] ?: $key);
                        }
                    }
                }
        }

        $cpf = $_POST['CPF'] ?? '';
        $port = $_POST[str_replace(' ', '_', $ID_FIELD_KEY)] ?? ($_POST[$ID_FIELD_KEY] ?? '');

        if (!$port) {
            echo json_encode(['status'=>'error', 'message'=>"Erro: " . $ID_LABEL . " Ã© obrigatÃ³rio."]);
            exit;
        } 

        // Lock Check
        $lockInfo = $lockManager->checkLock($port, $_SESSION['nome_completo']);
        if ($lockInfo['locked']) {
            echo json_encode(['status'=>'error', 'message'=>"Este processo estÃ¡ bloqueado por {$lockInfo['by']} e nÃ£o pode ser salvo."]);
            exit;
        }
        
        $data['Nome_atendente'] = $_SESSION['nome_completo'] ?? 'Desconhecido';
        $data['Ultima_Alteracao'] = date('d/m/Y H:i:s');
        // DATA is never auto-filled; it must be set manually by the user

        // Client Validation (Removed as Base_clientes is deprecated)
        // if ($cpf) { ... }
        
        // Agency Validation (Removed as Base_agencias is deprecated)
        // if ($ag) { ... }

        if (!empty($errors)) {
             echo json_encode(['status'=>'error', 'message'=>implode("<br>", array_unique($errors))]);
             exit;
        }

        // Determine correct storage file based on date
        $dt = DateTime::createFromFormat('d/m/Y H:i:s', $data['DATA']);
        if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $data['DATA']);
        if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $data['DATA']);
        if (!$dt) $dt = new DateTime();
        $targetFile = $db->ensurePeriodStructure($dt->format('Y'), $dt->format('n'));

        // Check for ID Rename
        $originalPort = $_POST['original_id'] ?? '';
        $renamed = false;

        if ($originalPort && $originalPort !== $port) {
             $oldFile = $indexer->get($originalPort);
             if ($oldFile) {
                 $oldData = $db->find($oldFile, $ID_FIELD_KEY, $originalPort);
                 if ($oldData) {
                     // 1. Merge Data (Old + New Input)
                     $fullData = array_merge($oldData, $data);
                     $fullData[$ID_FIELD_KEY] = $port;
                     
                     // 2. Clear Internal Links (Do not migrate)
                     // Ensure new record starts fresh without linking to old attachments or history
                     if(isset($fullData['_anexos_ref'])) unset($fullData['_anexos_ref']);
                     if(isset($fullData['_historico_envios'])) unset($fullData['_historico_envios']);
                     if(isset($fullData['_registros_processo'])) unset($fullData['_registros_processo']);
                     
                     // 3. Insert New Record (Clone)
                     $db->delete($targetFile, $ID_FIELD_KEY, $port); // Ensure target clear
                     $db->insert($targetFile, $fullData);
                     $indexer->set($port, $targetFile);
                     
                     // Note: We DO NOT delete the old record nor move its attachments.
                     // The old ID remains active and linked to its original data.
                     
                     $msg = "Novo registro criado a partir da ID original. (Imutabilidade preservada)";
                     $renamed = true;
                 }
             }
        }

        if (!$renamed) {
            // Check for existing record via Index
            $foundFile = $indexer->get($port);

            if ($foundFile) {
                // Check if date changed such that it belongs to a different file
                if ($foundFile !== $targetFile) {
                    // Move record: Delete from old, Insert into new
                    $oldData = $db->find($foundFile, $ID_FIELD_KEY, $port);
                    $fullData = $oldData ? array_merge($oldData, $data) : $data;

                    // Ensure no duplication in target file (Double Check)
                    $db->delete($targetFile, $ID_FIELD_KEY, $port);

                    $db->delete($foundFile, $ID_FIELD_KEY, $port);
                    $db->insert($targetFile, $fullData);
                    $indexer->set($port, $targetFile); // Update Index
                    $msg = "Processo atualizado e movido para o perÃ­odo correto!";
                } else {
                    $db->update($foundFile, $ID_FIELD_KEY, $port, $data);
                    $msg = "Processo atualizado com sucesso!";
                }
            } else {
                // Ensure no duplication in target file (Double Check)
                $db->delete($targetFile, $ID_FIELD_KEY, $port);

                $db->insert($targetFile, $data);
                $indexer->set($port, $targetFile); // Add to Index
                $msg = "Processo criado com sucesso!";
            }
        }

        // Updates to Base_clientes/Base_agencias removed as requested (all data in Processos)
        
        echo json_encode(['status'=>'ok', 'message'=>$msg, 'new_timestamp'=>$data['Ultima_Alteracao']]);
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
        $fComIdentificador = $_POST['fComIdentificador'] ?? '';
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
        
        $checkDate = function($row) use ($fDataIni, $fDataFim, $fMes, $fAno, $visFilters, $fieldMap, $fieldConfigMap, $fAtendente) {
            // Apply Dynamic Filters first (Type Aware)
            foreach($visFilters as $vk) {
                if ($vk === $ID_FIELD_KEY) continue;
                $postKey = 'f_' . str_replace([' ', '.'], '_', $vk);
                $valReq = $_POST[$postKey] ?? ($_POST['f_' . $vk] ?? '');

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
            }

            // Atendente Check (Fixed)
            if (!empty($fAtendente)) {
                 $rAtt = get_value_ci($row, 'Nome_atendente');
                 if (mb_strtoupper(trim((string)$rAtt), 'UTF-8') !== mb_strtoupper(trim((string)$fAtendente), 'UTF-8')) return false;
            }

            if (!empty($_POST['f_STATUS'])) {
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

        if ($fComIdentificador) {
            $originalCallbackA = $filters['callback'] ?? null;
            $allIdentificacoesA = $db->select('Identificacao.json', [], 1, 999999);
            $identIdsMapA = [];
            if (!empty($allIdentificacoesA['data'])) {
                foreach ($allIdentificacoesA['data'] as $i) {
                    $idValA = get_value_ci($i, $IDENT_ID_FIELD);
                    if ($idValA !== null && $idValA !== '') {
                        $identIdsMapA[(string)$idValA] = true;
                        $identIdsMapA[mb_strtoupper(trim((string)$idValA), 'UTF-8')] = true;
                    }
                }
            }
            $filters['callback'] = function($row) use ($originalCallbackA, $identIdsMapA, $ID_FIELD_KEY) {
                if ($originalCallbackA && !$originalCallbackA($row)) return false;
                $pIdA = get_value_ci($row, $ID_FIELD_KEY);
                if ($pIdA === null || $pIdA === '') return false;
                
                $pIdStrA = (string)$pIdA;
                if (isset($identIdsMapA[$pIdStrA])) return true;
                
                $pIdNormA = mb_strtoupper(trim($pIdStrA), 'UTF-8');
                return isset($identIdsMapA[$pIdNormA]);
            };
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
                    // CORREÃ‡ÃƒO: Tenta buscar no Mapa de Clientes, se nÃ£o achar, usa o Nome salvo no prÃ³prio processo
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
                    
                    // OrdenaÃ§Ã£o secundÃ¡ria: Ultima_Alteracao DESC (Mais recente primeiro)
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
        if(!isset($schemaCols['Nome_atendente'])) $schemaCols['Nome_atendente'] = ['key'=>'Nome_atendente', 'label'=>'Nome Atendente'];
        if(!isset($schemaCols['DATA'])) $schemaCols['DATA'] = ['key'=>'DATA', 'label'=>'Data/Hora'];
        if(!isset($schemaCols['STATUS'])) $schemaCols['STATUS'] = ['key'=>'STATUS', 'label'=>'STATUS'];
        
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
             $fixedKeys = [$ID_FIELD_KEY, 'Nome_atendente', 'DATA', 'STATUS'];
             $sorted = [];
             $indexed = [];
             foreach($dashColumns as $c) $indexed[$c['key']] = $c;
             foreach($fixedKeys as $k) { if(isset($indexed[$k])) { $sorted[] = $indexed[$k]; unset($indexed[$k]); } }
             foreach($indexed as $c) $sorted[] = $c;
             $dashColumns = $sorted;
        }

        $dataList = [];
        foreach($processos as $proc) {
            $port = get_value_ci($proc, $ID_FIELD_KEY);
            $proc['_cred'] = isset($creditoMap[$port]);
            $proc['_lock'] = $lockManager->checkLock($port, '');
            foreach($dashColumns as $col) {
                $colKey = $col['key'];
                $val = get_value_ci($proc, $colKey) ?: '';
                $proc[$colKey . '_formatted'] = format_field_value($colKey, $val);
            }
            $dataList[] = $proc;
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'data' => $dataList, 'columns' => $dashColumns, 'page' => $res['page'], 'pages' => $res['pages'], 'count' => $res['total'], 'IDENT_ICON' => $IDENT_ICON]);
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
            ['key'=>'Ultima_Alteracao', 'label'=>'Ultima AtualizaÃ§Ã£o', 'source'=>'process'],
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
            // Load Records from consolidated _registros_processo inside process records
            $ports = array_column($rows, $ID_FIELD_KEY);
            if(!empty($ports)) {
                // For each process, read the _registros_processo sub-data
                foreach ($targetFiles as $tf) {
                    $tfPath = $db->getPath($tf);
                    if (!file_exists($tfPath)) continue;
                    $tfRows = $db->readJSON($tfPath);
                    foreach ($tfRows as $tfRow) {
                        $p = get_value_ci($tfRow, $ID_FIELD_KEY);
                        if (!in_array($p, $ports)) continue;
                        $regs = isset($tfRow['_registros_processo']) && is_array($tfRow['_registros_processo']) ? $tfRow['_registros_processo'] : [];
                        foreach ($regs as $rec) {
                            $currDate = get_value_ci($rec, 'DATA');
                            if(!isset($recordMap[$p])) {
                                $recordMap[$p] = $rec;
                            } else {
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
        $dataList = [];
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
            $dlDisplay = $dtDL ? $dtDL->format('d/m/Y H:i:s') : $dlVal;
            $isBeat = ($dtDL && $dtDL < new DateTime());
            
            $proc['_dlDisplay'] = $dlDisplay;
            $proc['_isBeat'] = $isBeat;
            $proc['_port'] = $port;
            
            foreach($displayColumns as $f) {
                $key = $f['key'];
                if ($key !== 'Ultima_Alteracao' && $key !== 'Data_Lembrete') {
                    $val = '';
                    if ($f['source'] == 'process') $val = get_value_ci($proc, $f['key']);
                    else $val = get_value_ci($rec, $f['key']);
                    $proc[$key . '_formatted'] = format_field_value($f['key'], $val);
                }
            }
            $dataList[] = $proc;
        }
        
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'data' => $dataList, 'columns' => $displayColumns, 'page' => $pPagina, 'pages' => $pages, 'sortCol' => $sortCol, 'sortDir' => $sortDir, 'colFilters' => $colFilters]);
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

        $dataList = [];
        foreach($creditos as $c) {
            $port = get_value_ci($c, $IDENT_ID_FIELD);
            $c['_port'] = $port;
            $dataList[] = $c;
        }
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status' => 'ok', 'data' => $dataList, 'page' => $cRes['page'], 'pages' => $cRes['pages'], 'count' => $cRes['total'], 'IDENT_ID_FIELD' => $IDENT_ID_FIELD]);
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
        // Read from consolidated _registros_processo inside the process record
        if ($process) {
            $currentFile = $file ?: ($indexer->get($port) ?: null);
            if ($currentFile) {
                $regs = $db->getProcessSubData($currentFile, $ID_FIELD_KEY, $port, '_registros_processo');
                if (is_array($regs)) {
                    foreach ($regs as $row) {
                        $row['_id'] = isset($row['UID']) ? $row['UID'] : md5(json_encode($row));
                        $registrosHistory[] = $row;
                    }
                }
            }
        }
        $registrosHistory = array_reverse($registrosHistory);

        $emailHistory = $templates->getHistory($port);

        $lockInfo = $lockManager->checkLock($port, $_SESSION['nome_completo']);

        // Strip internal keys from process for frontend display
        $processDisplay = $process ? Database::stripInternalKeys($process) : null;

        ob_clean(); header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok', 
            'process' => $processDisplay, 
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
                echo json_encode(['status'=>'error', 'message'=>"Erro CrÃ­tico: " . $e->getMessage()]);
            }
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>"Erro: SessÃ£o de upload expirada."]);
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
                    $headers = ['Status', 'NÃºmero DepÃ³sito', 'Data DepÃ³sito', 'Valor DepÃ³sito Principal', 'Texto Pagamento', $IDENT_LABEL, 'Certificado', 'Status 2', 'CPF', 'AG'];
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
                echo json_encode(['status'=>'error', 'message'=>"Erro: Nenhum dado vÃ¡lido identificado."]);
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
                echo '<th>ValidaÃ§Ã£o</th></tr></thead><tbody>';
                foreach($validatedData as $idx => $row) {
                    $err = isset($row['DATA_ERROR']) ? 'table-danger' : '';
                    echo '<tr class="'.$err.'"><td>'.($idx + 1).'</td>';
                    foreach($previewHeaders as $h) {
                        echo '<td>' . htmlspecialchars($row[$h] ?? '') . '</td>';
                    }
                    echo '<td>';
                    if(isset($row['DATA_ERROR'])) echo '<span class="badge bg-danger">Data InvÃ¡lida</span>';
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
            echo json_encode(['status'=>'error', 'message'=>'ConteÃºdo vazio.']);
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

    if ($act == 'ajax_get_base_data_json') {
        $base = $_POST['base'] ?? '';
        if (!$base) { echo json_encode(['status'=>'error']); exit; }
        
        $res = $db->select($base, [], 1, 1000, 'Nome', false); 
        ob_clean(); header('Content-Type: application/json');
        echo json_encode(['status'=>'ok', 'data'=>$res['data']]);
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
             $postKey = 'f_' . str_replace([' ', '.'], '_', $bk);
             $val = $_POST[$postKey] ?? ($_POST['f_'.$bk] ?? '');
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
        if (stripos($base, 'contatos') !== false) {
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
            if (isset($f['deleted']) && $f['deleted']) continue;
            if (isset($f['type']) && $f['type'] === 'title') continue;
            
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

        $dataList = [];
        foreach($rows as $r) {
            $rowObj = [];
            $rowObj['_id'] = get_value_ci($r, $pk);
            $rowObj['_raw'] = $r;
            $rowObj['_lock'] = ($base === 'Processos') ? $lockManager->checkLock(get_value_ci($r, $ID_FIELD_KEY), '') : null;
            
            foreach($confFields as $f) {
                $val = get_value_ci($r, $f['key']);
                $rowObj[$f['key']] = $val;
                $rowObj[$f['key'].'_formatted'] = format_field_value($f['key'], $val);
            }
            $dataList[] = $rowObj;
        }

        ob_clean(); header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok', 
            'data' => $dataList, 
            'columns' => $confFields, 
            'page' => $res['page'], 
            'pages' => $res['pages'], 
            'count' => $res['total'],
            'base' => $base,
            'pk' => $pk
        ]);
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
            if(isset($_POST[$key]) || isset($_POST[str_replace([' ', '.'], '_', $key)])) {
                $val = isset($_POST[$key]) ? $_POST[$key] : $_POST[str_replace([' ', '.'], '_', $key)];
                
                if ($f['type'] == 'date' && !empty($val)) {
                    $valDate = explode('T', $val)[0];
                    $valDate = explode(' ', $valDate)[0];
                    $dt = DateTime::createFromFormat('Y-m-d', $valDate);
                    if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $valDate);
                    if ($dt) {
                        $val = $dt->format('d/m/Y');
                    }
                }
                if (($f['type'] == 'datetime' || $f['type'] == 'datetime-local') && !empty($val)) {
                    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                    if (!$dt) {
                         $dt = DateTime::createFromFormat('Y-m-d', explode('T', $val)[0]);
                         if ($dt) $dt->setTime(0,0);
                    }
                    if ($dt) $val = $dt->format('d/m/Y H:i');
                }
                $data[$key] = $val;
                
                // Validation
                if ($f['type'] === 'number' && $val !== '' && !is_numeric($val)) {
                    $errors[] = "O campo " . ($f['label'] ?: $key) . " deve conter apenas nÃºmeros.";
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
                            $errors[] = "Campo obrigatÃ³rio nÃ£o preenchido: " . ($f['label'] ?: $key);
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
        if (empty($headers)) {
             // Fallback to Config Schema for new bases
             foreach($confFields as $f) {
                 if (($f['type'] ?? '') !== 'title') $headers[] = $f['key'];
             }
        }

        $pk = $headers[0] ?? 'id';
        if (stripos($base, 'cred') !== false) $pk = $IDENT_ID_FIELD;
        if (stripos($base, 'client') !== false) $pk = 'CPF';
        if (stripos($base, 'agenc') !== false) $pk = 'AG';
        if ($base === 'Processos') $pk = $ID_FIELD_KEY;

        $newId = get_value_ci($data, $pk);
        if (!$newId) {
             ob_clean(); header('Content-Type: application/json');
             echo json_encode(['status'=>'error', 'message'=>'Identificador ('.$pk.') Ã© obrigatÃ³rio.']);
             exit;
        }

        if ($base === 'Processos') {
             // 1. Bloqueio de Duplicidade de ID
             if ((empty($originalId) || $newId != $originalId) && $indexer->get($newId)) {
                 ob_clean(); header('Content-Type: application/json');
                 echo json_encode(['status'=>'error', 'message'=>'JÃ¡ existe um processo com este ID ('.$newId.'). Rule: Duplicidade nÃ£o permitida.']);
                 exit;
             }

             $data['Ultima_Alteracao'] = date('d/m/Y H:i:s');
             // 2. AtribuiÃ§Ã£o do Nome_atendente (Manual ou AutomÃ¡tica)
             if (!isset($data['Nome_atendente']) || empty($data['Nome_atendente'])) {
                 $data['Nome_atendente'] = $_SESSION['nome_completo'];
             }
             
             $dt = DateTime::createFromFormat('d/m/Y H:i:s', $data['DATA'] ?? '');
             if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i', $data['DATA'] ?? '');
             if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $data['DATA'] ?? '');
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
                         // Preserve old data (including consolidated sub-data)
                         $oldData = $db->find($oldFile, $pk, $originalId);
                         $fullData = $oldData ? array_merge($oldData, $data) : $data;

                         $db->delete($oldFile, $pk, $originalId);
                         $db->insert($targetFile, $fullData);

                         // Update Indexer
                         if ($originalId != $newId) {
                             // Update _anexos_ref and rename upload folder
                             $oldUploadDir = 'upload/' . $originalId;
                             $newUploadDir = 'upload/' . $newId;
                             if (is_dir($oldUploadDir) && !is_dir($newUploadDir)) {
                                 rename($oldUploadDir, $newUploadDir);
                             }
                             $db->update($targetFile, $pk, $newId, ['_anexos_ref' => $newUploadDir]);
                             
                             $indexer->delete($originalId);
                         }
                         $indexer->set($newId, $targetFile);

                         $msg = "Processo atualizado e movido para o perÃ­odo correto!";
                     } else {
                         $db->update($oldFile, $pk, $originalId, $data);
                         
                         // Update Indexer if ID changed
                         if ($originalId != $newId) {
                             // Update _anexos_ref and rename upload folder
                             $oldUploadDir = 'upload/' . $originalId;
                             $newUploadDir = 'upload/' . $newId;
                             if (is_dir($oldUploadDir) && !is_dir($newUploadDir)) {
                                 rename($oldUploadDir, $newUploadDir);
                             }
                             $db->update($oldFile, $pk, $newId, ['_anexos_ref' => $newUploadDir]);
                             
                             $indexer->delete($originalId);
                             $indexer->set($newId, $oldFile);
                         }
                         $msg = "Processo atualizado!";
                     }
                     $res = true;
                 } else {
                     $res = false;
                     $msg = "Processo original nÃ£o encontrado na seleÃ§Ã£o atual.";
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
                     echo json_encode(['status'=>'error', 'message'=>'Novo ID jÃ¡ existe.']);
                     exit;
                 }
             }
             $res = $db->update($base, $pk, $originalId, $data);
             $msg = "Registro atualizado!";
        } else {
             if ($db->find($base, $pk, $newId)) {
                 ob_clean(); header('Content-Type: application/json');
                 echo json_encode(['status'=>'error', 'message'=>'Registro jÃ¡ existe.']);
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
                 $record = $db->find($oldFile, $pk, $id);
                 $res = $db->delete($oldFile, $pk, $id);
                 if ($res) {
                     $indexer->delete($id);
                     
                     if ($record && !empty($record['_anexos_ref'])) {
                         $refDir = $record['_anexos_ref'];
                         $cleanDir = str_replace('..', '', $refDir);
                         if (strpos($cleanDir, 'upload/') === 0 && is_dir($cleanDir)) {
                             deleteDirectory($cleanDir);
                         }
                     }

                     $safeId = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $id);
                     if ($safeId) {
                         $uDir = 'upload/' . $safeId;
                         if (is_dir($uDir)) deleteDirectory($uDir);
                     }
                 }
             }
        } else {
             $res = $db->delete($base, $pk, $id);
        }

        if($res) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'ok', 'message'=>'ExcluÃ­do.']);
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
                     $record = $db->find($oldFile, $pk, $id);

                     if ($db->delete($oldFile, $pk, $id)) {
                         $indexer->delete($id);

                         if ($record && !empty($record['_anexos_ref'])) {
                             $refDir = $record['_anexos_ref'];
                             $cleanDir = str_replace('..', '', $refDir);
                             if (strpos($cleanDir, 'upload/') === 0 && is_dir($cleanDir)) {
                                 deleteDirectory($cleanDir);
                             }
                         }

                         $safeId = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $id);
                         if ($safeId) {
                             $uDir = 'upload/' . $safeId;
                             if (is_dir($uDir)) deleteDirectory($uDir);
                         }
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
            echo json_encode(['status'=>'ok', 'message'=>'Registros excluÃ­dos.']);
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
                // Adiciona o usuÃ¡rio logado se nÃ£o estiver na lista
                if (isset($_SESSION['nome_completo'])) {
                    $opts[] = $_SESSION['nome_completo'];
                }
                $f['options'] = implode(',', array_unique(array_filter($opts)));
                // No bulk edit, permitimos mudar mesmo se nÃ£o for comum entre os selecionados
                $f['is_common'] = true; 
            }

            // Allow Date Edit (Always enabled for Bulk Edit)
            if (mb_strtoupper($key) === 'DATA') {
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
            if(isset($_POST[$key]) || isset($_POST[str_replace([' ', '.'], '_', $key)])) {
                $val = isset($_POST[$key]) ? $_POST[$key] : $_POST[str_replace([' ', '.'], '_', $key)];
                if ($f['type'] == 'date' && !empty($val)) {
                    $valDate = explode('T', $val)[0];
                    $valDate = explode(' ', $valDate)[0];
                    $dt = DateTime::createFromFormat('Y-m-d', $valDate);
                    if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $valDate);
                    if ($dt) {
                        $val = $dt->format('d/m/Y');
                    }
                }
                if (($f['type'] == 'datetime' || $f['type'] == 'datetime-local') && !empty($val)) {
                    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $val);
                    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i:s', $val);
                    if (!$dt) {
                         $dt = DateTime::createFromFormat('Y-m-d', explode('T', $val)[0]);
                         if ($dt) $dt->setTime(0,0);
                    }
                    if ($dt) $val = $dt->format('d/m/Y H:i');
                }
                $data[$key] = $val;
            }
        }
        
        if (empty($data)) {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Nenhuma informaÃ§Ã£o para atualizar.']);
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
            // Se o usuÃ¡rio selecionou um atendente no bulk edit, usa ele.
            // Caso contrÃ¡rio, atribui ao usuÃ¡rio atual que estÃ¡ realizando a alteraÃ§Ã£o.
            if (!isset($data['Nome_atendente']) || empty($data['Nome_atendente'])) {
                $data['Nome_atendente'] = $_SESSION['nome_completo'] ?? 'Desconhecido';
            }
            
            $targetFile = null;
            if (isset($data['DATA']) && !empty($data['DATA'])) {
                $dt = DateTime::createFromFormat('d/m/Y H:i', $data['DATA']);
                if (!$dt) $dt = DateTime::createFromFormat('d/m/Y H:i:s', $data['DATA']);
                if (!$dt) $dt = DateTime::createFromFormat('d/m/Y', $data['DATA']);
                
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
        
        // Enforce required for fixed fields
        if ($file === 'Base_processos_schema') {
             $protected = [$ID_FIELD_KEY, 'DATA', 'STATUS', 'NOME_ATENDENTE'];
             $protected = array_map(function($k){ return mb_strtoupper($k, 'UTF-8'); }, $protected);
             $checkKey = mb_strtoupper($oldKey ? $oldKey : $_POST['key'], 'UTF-8');
             
             if (in_array($checkKey, $protected)) {
                 $required = true;
             }
        }

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

            // General Field Rename (Processos or other Bases)
            if ($key !== $oldKey) {
                if ($file === 'Base_processos_schema') {
                    $allFiles = $db->getAllProcessFiles();
                    foreach($allFiles as $relPath) {
                        $db->renameFieldKey($relPath, $oldKey, $key);
                    }
                } else {
                    $db->renameFieldKey($file, $oldKey, $key);
                }
            }
            
            // Check if we renamed the field
            if ($oldKey === $ID_FIELD_KEY) {
                // Determine if we actually changed the ID field (either renamed it or switched to another)
                $newIdKey = $key;
                
                // Update Settings global configuration
                $settings->set('id_field_key', $newIdKey);
                $settings->set('id_label', $fieldData['label']);
                
                // SYNC IDENTIFICACAO SETTINGS: ID must be shared.
                $settings->set('identification_id_field', $newIdKey);
                
                // DATA MIGRATION: 
                // Case 1: Renamed the current ID field (oldKey was ID, and we changed key)
                
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
                    echo json_encode(['status'=>'error', 'message'=>"Erro: O campo '$key' jÃ¡ existe!"]);
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
             echo json_encode(['status'=>'error', 'message'=>'Campo origem nÃ£o encontrado.']);
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
        echo json_encode(['status'=>'ok', 'message'=>'Modelo excluÃ­do!']);
        exit;
    }

    if ($act == 'ajax_excluir_processo') {
        $port = $_POST['id_exclusao'];
        if ($port) {
            $file = $indexer->get($port);
            if ($file) {
                $record = $db->find($file, $ID_FIELD_KEY, $port);
                $db->delete($file, $ID_FIELD_KEY, $port);
                $indexer->delete($port);
                
                if ($record && !empty($record['_anexos_ref'])) {
                    $refDir = $record['_anexos_ref'];
                    $cleanDir = str_replace('..', '', $refDir);
                    if (strpos($cleanDir, 'upload/') === 0 && is_dir($cleanDir)) {
                        deleteDirectory($cleanDir);
                    }
                }

                $safePort = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $port);
                if ($safePort) {
                    $uDir = 'upload/' . $safePort;
                    if (is_dir($uDir)) deleteDirectory($uDir);
                }
                $msg = "Processo excluÃ­do com sucesso.";
            } else {
                // Fallback (e.g. legacy check)
                $years = $_SESSION['selected_years'] ?? [date('Y')];
                $months = $_SESSION['selected_months'] ?? [(int)date('n')];
                $files = $db->getProcessFiles($years, $months);
                foreach ($files as $f) {
                    $record = $db->find($f, $ID_FIELD_KEY, $port);
                    if ($db->delete($f, $ID_FIELD_KEY, $port)) {
                         // Found by brute force
                         if ($record && !empty($record['_anexos_ref'])) {
                             $refDir = $record['_anexos_ref'];
                             $cleanDir = str_replace('..', '', $refDir);
                             if (strpos($cleanDir, 'upload/') === 0 && is_dir($cleanDir)) {
                                 deleteDirectory($cleanDir);
                             }
                         }

                         $safePort = preg_replace('/[^a-zA-Z0-9_\-\.]/', '', $port);
                         if ($safePort) {
                             $uDir = 'upload/' . $safePort;
                             if (is_dir($uDir)) deleteDirectory($uDir);
                         }
                         break;
                    }
                }
                $msg = "Processo excluÃ­do (se encontrado).";
            }
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'ok', 'message'=>$msg]);
        } else {
            ob_clean(); header('Content-Type: application/json');
            echo json_encode(['status'=>'error', 'message'=>'Identificador invÃ¡lido.']);
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

         
         $filtersData = [];
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
             $filtersData[] = ['key' => $bk, 'label' => $label, 'type' => $fType];
         }
         ob_clean();
         header('Content-Type: application/json');
         echo json_encode(['status'=>'ok', 'filters'=>$filtersData]);
         exit;
    }


    
    exit;
    exit;
}

// EXPORT TEMPLATES
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




