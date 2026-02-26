<?php 
session_start(); 
// Previne erros caso a sessão não esteja definida
$nome_completo = $_SESSION['nome_completo'] ?? 'Usuário Convidado';
?> 
<!DOCTYPE html> 
<html lang="pt-BR"> 
<head> 
    <meta charset="UTF-8"> 
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Ferramenta de Modelos de Texto</title> 
    
    <script src="./Bibliotecas/full.js"></script>      
    <script src="./Bibliotecas/Sortable.js"></script> 
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script> 
     
<style> 
    /* ======================================= */ 
    /* 🔵 NOVO: ESTILO DA NAVBAR (Adicionado sem afetar o resto) */ 
    /* ======================================= */ 
    .navbar-superior-fixa {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 60px;
        background-color: #003366; /* Azul Marinho */
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0 25px;
        box-sizing: border-box;
        z-index: 10000;
        box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        font-family: Arial, sans-serif;
    }

    .navbar-superior-fixa .titulo-nav {
        color: #fff;
        font-size: 18px;
        font-weight: bold;
    }

    .navbar-superior-fixa .usuario-nav {
        color: #fff;
        font-size: 14px;
        font-weight: 600;
    }

    /* ======================================= */ 
    /* 🎨 SEUS ESTILOS ORIGINAIS (INTOCADOS) */ 
    /* ======================================= */ 
    :root {
        --primary-color: #1e293b;
        --secondary-color: #f4f6f8;
        --text-color: #333;
        --success-color: #28a745;
        --danger-color: #dc3545;
        --warning-color: #ffc107;
        --info-color: #17a2b8;
        --light-gray: #e9ecef;
        --border-color: #ced4da;
        --card-shadow: 0 4px 6px rgba(0,0,0,0.05);
        --font-family: Arial, sans-serif; 
    }

    body { 
        font-family: var(--font-family); 
        margin: 0; 
        padding: 20px; 
        /* Adicionei padding-top extra para o conteúdo não ficar embaixo da barra fixa */
        padding-top: 80px; 
        background: #f2f2f2;  
        color: var(--text-color); 
    } 
    
    .texto { 
        font-size: 28px; 
        font-weight: 600; 
        text-align: center;  
        margin-bottom: 25px; 
        color: var(--primary-color); 
        letter-spacing: 0.5px;
    } 
    
    .texto2 { 
        font-size: 14px; 
        font-weight: 600; 
        text-align: center;  
        margin-bottom: 20px; 
        color: #555; 
    } 

    .texto3 { 
        font-size: 24px; 
        font-weight: 600; 
        text-align: center;  
        margin-bottom: 20px; 
        color: #000000;
    } 
    
.containerdevolucao3 { 
        background-color: #ffffff; 
        width: 100%;  
        max-width: 1450px;  
        margin: 20px auto;  
        padding: 30px; 
        border: 1px solid #e1e4e8; /* Borda leve para definição */
        border-radius: 12px; /* Cantos mais suaves */
        
        /* 💡 SOMBRA EM DESTAQUE */
        box-shadow: 0 10px 25px rgba(0,0,0,0.1), 0 4px 10px rgba(0,0,0,0.05);
        
        text-align: left;  
        box-sizing: border-box; 
        transition: transform 0.3s ease, box-shadow 0.3s ease; /* Suaviza a interação */
    } 

    /* ======================================= */
    /* 🎯 PADRONIZAÇÃO DE BOTÕES (Seu estilo original) */
    /* ======================================= */

    .button, 
    .selectFormButtons button, 
    .closeBtn, 
    .saveBtn, 
    .deleteBtn, 
    .clearBtn {
        padding: 10px 20px;
        font-size: 14px;
        font-weight: 600;
        font-family: var(--font-family);
        color: #fff;
        
        border: none;
        border-radius: 12px; /* Mantendo bem arredondado */
        cursor: pointer;
        
        box-shadow: none; 
        
        transition: background-color 0.2s, transform 0.1s; 
        
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    /* --- Estados de Interação --- */
    .button:hover, 
    .selectFormButtons button:hover, 
    .closeBtn:hover, 
    .saveBtn:hover, 
    .deleteBtn:hover, 
    .clearBtn:hover {
        filter: brightness(1.1); 
        transform: translateY(-1px); 
    }

    .button:active, 
    .selectFormButtons button:active, 
    .closeBtn:active, 
    .saveBtn:active, 
    .deleteBtn:active, 
    .clearBtn:active {
        transform: scale(0.96); 
        filter: brightness(0.9); 
    }

    /* --- Cores Específicas --- */
    .button { background-color: #005ca9; }
    .saveBtn { background-color: var(--success-color, #28a745); } 
    .deleteBtn { background-color: var(--danger-color, #dc3545); } 
    .clearBtn { background-color: var(--warning-color, #ffc107); color: #333; } 
    .closeBtn { background-color: #6c757d; } 

    .button-group, .selectFormButtons { 
        display: flex; 
        flex-wrap: wrap; 
        gap: 10px; 
        justify-content: center; 
        align-items: center; 
        margin: 15px 0; 
    }

    /* ======================================= */ 
    /* 📦 INPUTS E SELECTS */ 
    /* ======================================= */ 
    .select-group { 
        display: flex; 
        flex-direction: column; 
        margin-bottom: 15px; 
        width: 100%; 
    } 
    
    .select-group label { font-weight: 600; margin-bottom: 6px; font-size: 13px; color: #444; } 
    .select-group select { 
        padding: 10px; border-radius: 4px; border: 1px solid var(--border-color); 
        font-size: 14px; background-color: #fff; cursor: pointer; 
        transition: border-color 0.2s;
    } 
    .select-group select:focus { border-color: var(--primary-color); outline: none; }

    /* Estilos para o dropdown com checkbox */
    .dropdown-check-list {
        display: inline-block;
        width: 100%;
    }
    .dropdown-check-list .anchor {
        position: relative;
        cursor: pointer;
        display: inline-block;
        padding: 10px;
        border: 1px solid var(--border-color);
        width: 100%;
        box-sizing: border-box;
        border-radius: 4px;
        background-color: #fff;
        font-size: 14px;
    }
    .dropdown-check-list .anchor:after {
        position: absolute;
        content: "";
        border-left: 2px solid black;
        border-top: 2px solid black;
        padding: 5px;
        right: 15px;
        top: 25%;
        transform: rotate(-135deg);
    }
    .dropdown-check-list .anchor:active:after {
        right: 15px;
        top: 30%;
    }
    .dropdown-check-list ul.items {
        padding: 2px;
        display: none;
        margin: 0;
        border: 1px solid #ccc;
        border-top: none;
        background: #fff;
        max-height: 200px;
        overflow-y: auto;
        border-radius: 0 0 5px 5px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }
    .dropdown-check-list ul.items li {
        list-style: none;
        padding: 8px 10px;
        border-bottom: 1px solid #eee;
    }
    .dropdown-check-list ul.items li:last-child { border-bottom: none; }
    .dropdown-check-list ul.items li:hover { background-color: #f1f1f1; }
    .dropdown-check-list.visible .anchor { color: #0094ff; border-radius: 5px 5px 0 0; }
    .dropdown-check-list.visible .items { display: block; }
    
    #sf-controls { 
        display: flex; align-items: center; justify-content: flex-start; 
        width: 100%; margin-top: 20px; position: relative; 
    } 
    
    .middle-buttons { 
        display: flex; gap: 10px; 
        position: absolute; left: 50%; transform: translateX(-50%); 
    } 
    
    #sf-controls .select-group { width: auto; min-width: 250px; margin-bottom: 0; } 
    #saudacaoFechamentoSelect { width: auto; min-width: 200px; max-width: 400px; padding: 5px 8px; font-size: 14px; background-color: #e6f7ff; } 
    
    #searchInput { 
        width: 100%; max-width: 600px; padding: 12px 15px; font-size: 14px; 
        border-radius: 30px; border: 1px solid var(--border-color); margin: 0 auto 20px auto; 
        display: block; box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        transition: border-color 0.2s, box-shadow 0.2s;
    }
    #searchInput:focus { border-color: var(--primary-color); outline: none; box-shadow: 0 2px 8px rgba(0, 92, 169, 0.2); }
    
    /* ======================================= */ 
    /* ⚡ GRID DE SEÇÕES */ 
    /* ======================================= */ 
    #sectionsContainer { 
        display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; 
    } 
    @media (max-width: 900px) { #sectionsContainer { grid-template-columns: 1fr; } } 
    
    /* ======================================= */ 
    /* 🛠️ ACORDEÃO E EDIÇÃO */ 
    /* ======================================= */ 
    .management-bar { 
        background: #fff3cd; border: 1px solid #ffeeba; color: #856404; 
        padding: 12px 20px; margin-bottom: 15px; border-radius: 6px; 
        font-weight: 600; display: flex; justify-content: space-between; align-items: center; 
    } 
    
    .section-header { 
        background-color: var(--primary-color); color: white; padding: 15px 20px; 
        cursor: pointer; font-weight: 600; user-select: none; 
        border-radius: 6px 6px 0 0; display: flex; justify-content: space-between; align-items: center; 
        transition: background-color 0.2s;
    } 
    .section-header:hover { background-color: #334155; } 
    
    .tema-header { 
        background-color: #f8f9fa; padding: 12px 18px; border-top: 1px solid var(--border-color); 
        cursor: pointer; font-weight: 600; display: flex; align-items: center; 
        font-size: 14px; position: relative; color: #495057;
    } 
    .tema-header:hover { background-color: #e9ecef; } 
    
    .icon { transition: transform 0.2s; display: inline-block; font-size: 12px; } 
    .expanded .icon { transform: rotate(90deg); } 
    
    .subitems, .tema-content { display: none; background: #fff; border: 1px solid var(--border-color); border-top: none; } 
    .subitems { border-radius: 0 0 6px 6px; overflow: hidden; } 
    .tema-content { padding: 0; } 
    
    .model-item { 
        padding: 0; width: 100%; box-sizing: border-box; 
        border-bottom: 1px solid #f1f1f1; display: flex; align-items: flex-start; 
    } 
    .model-item:hover { background-color: #fafafa; } 
    
    .model-text, .model-text-display, .model-text-manage { 
        word-wrap: break-word; width: 100%; padding: 12px 15px; 
        box-sizing: border-box; font-size: 13px; color: #555; line-height: 1.5;
    } 
    .model-text { display: block; width: 98%; cursor: pointer; } 
    .model-text-manage { display: none; min-height: 50px; border: 1px solid #007bff; resize: vertical; } 
    
    .drag-handle { 
        cursor: grab; margin-right: 10px; color: rgba(0,0,0,0.3); font-size: 1.2em; display: none; padding: 5px; 
    } 
    .drag-handle.visible { display: inline-block; } 
    
    .management-actions { display: none; gap: 5px; margin-left: auto; } 
    .management-actions.visible { display: flex; } 
    
    .editable-input { display: none; width: auto; flex-grow: 1; padding: 5px; } 
    .editing .editable-display { display: none; } 
    .editing .editable-input, .editing .model-text-manage { display: block; } 
    
    /* ======================================= */ 
    /* 📜 TABELAS E HISTÓRICO */ 
    /* ======================================= */ 
    #historyTable { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 15px; border: 1px solid var(--border-color); border-radius: 6px; overflow: hidden; } 
    #historyTable th, #historyTable td { border-bottom: 1px solid var(--border-color); border-right: 1px solid var(--border-color); padding: 12px 15px; text-align: left; min-width: 120px; font-size: 12px; } 
    #historyTable th { background-color: var(--secondary-color); font-weight: 600; color: #495057; position: sticky; top: 0; z-index: 10; border-top: none; } 
    #historyTable th:first-child { border-top-left-radius: 6px; }
    #historyTable th:last-child { border-top-right-radius: 6px; border-right: none; }
    #historyTable td:last-child { border-right: none; }
    #historyTable tr:last-child td { border-bottom: none; }
    #historyTable tbody tr:nth-child(even) { background-color: #fbfbfb; } 
    #historyTable tbody tr:hover { background-color: #f1f3f5; }
    
    .history-controls { text-align: center; margin-bottom: 10px; } 
    .history-controls input[type="date"] { padding: 8px; border-radius: 5px; border: 1px solid #ccc; margin: 0 5px; } 
    
    /* ======================================= */ 
    /* 🖼️ MODAIS */ 
    /* ======================================= */ 
    .modal-overlay { 
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
        background: rgba(0, 0, 0, 0.6); z-index: 999; display: none; backdrop-filter: blur(2px);
    } 
    .modal { 
        position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
        display: none; justify-content: center; align-items: center; z-index: 1000; 
    } 
    .modalContent { 
        background: #fff; border-radius: 8px; padding: 30px; 
        max-width: 700px; width: 95%; max-height: 90vh; 
        overflow-y: auto; box-shadow: 0 15px 40px rgba(0,0,0,0.2); 
        display: flex; flex-direction: column; gap: 20px; 
        animation: fadeIn 0.3s ease-out;
    } 
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }
    .modal-footer { text-align: right; margin-top: 20px; border-top: 1px solid var(--border-color); padding-top: 20px; } 
    
    /* Dinâmicos */ 
    #dynamicInputsContainer { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; text-align: left; } 
    #dynamicInputsContainer input, #dynamicInputsContainer select { 
        width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 4px; box-sizing: border-box; font-size: 14px;
        transition: border-color 0.2s;
    } 
    #dynamicInputsContainer input:focus, #dynamicInputsContainer select:focus { border-color: var(--primary-color); outline: none; }
    
    textarea#resultText { 
        width: 96%; min-height: 200px; padding: 20px; 
        border: 1px solid var(--border-color); border-radius: 6px; margin-top: 20px; 
        resize: vertical; font-family: var(--font-family); font-size: 14px; line-height: 1.6;
        transition: border-color 0.2s;
    }
    textarea#resultText:focus { border-color: var(--primary-color); outline: none; }
    
    .user-info-top { text-align: right; font-weight: 600; font-size: 14px; margin-bottom: 15px; color: #000; } 
    
    /* SF Items */ 
    .sf-item { padding: 12px 15px; cursor: pointer; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; transition: background-color 0.2s; } 
    .sf-item:hover { background-color: #f8f9fa; } 
    .sf-item.selected { background-color: #e7f1ff !important; border-left: 4px solid var(--primary-color); color: var(--primary-color); font-weight: 600; } 

    /* SweetAlert2 Customization to match theme */
    div:where(.swal2-container) button:where(.swal2-styled).swal2-confirm {
        background-color: var(--primary-color) !important;
        border-radius: 4px !important;
        font-family: var(--font-family) !important;
    }
    div:where(.swal2-container) button:where(.swal2-styled).swal2-cancel {
        background-color: #6c757d !important;
        border-radius: 4px !important;
        font-family: var(--font-family) !important;
    }
    div:where(.swal2-container) .swal2-modal {
        border-radius: 8px !important;
        font-family: var(--font-family) !important;
    }

/* --- CORREÇÃO DE DUPLICIDADE NA EDIÇÃO --- */

/* 1. Por padrão, o textarea (caixa de edição) deve ficar oculto */
.model-item .model-text-manage {
    display: none;
    width: 100%;        /* Opcional: para ocupar a largura toda */
    resize: vertical;   /* Opcional: permite redimensionar altura */
}

/* 2. Quando o JS adiciona a classe 'editing' no pai: */

/* Mostra a caixa de edição */
.model-item.editing .model-text-manage {
    display: block !important;
}

/* Esconde o texto fixo (o span) para evitar a duplicidade */
.model-item.editing .model-text-display {
    display: none !important;
}

</style> 


</head> 
<body> 

<div class="navbar-superior-fixa">
    <div class="titulo-nav">
        📑 PREV TRANSFERÊNCIA PF
    </div>
    <div class="usuario-nav">
        👤 <?php echo $nome_completo; ?>
    </div>
</div>

<div id="modalOverlay" class="modal-overlay"></div>
 

 
<!-- OVERLAY GERAL -->
<div id="modalOverlay" class="modal-overlay"></div> 
 
<!-- MODAL: Gerenciamento de Selects -->
<div id="selectManagerModal" class="modal"> 
    <div class="modalContent"> 
        <h3>Gerenciar Campos Dinâmicos (Selects)</h3>
        <p>Use <b>{CHAVE}</b> no texto para criar selects automaticamente.</p> 
        <div style="display: flex; gap: 20px; flex-wrap: wrap;"> 
            <div style="flex: 1; border-right: 1px solid #ddd; padding-right: 15px;"> 
                <h4>Lista de Campos</h4> 
                <div id="selectFieldsList" style="max-height: 200px; overflow-y: auto; border: 1px solid #eee;"></div> 
            </div> 
            <div style="flex: 1;"> 
                <h4>Editar / Criar</h4> 
                <input type="hidden" id="editingSelectOriginalKey"> 
                <label>Palavra-Chave:</label> 
                <input type="text" id="selectKeyInput" placeholder="Ex: DOCUMENTO" style="width:100%; margin-bottom:10px;"> 
                <label>Opções (separadas por vírgula):</label> 
                <textarea id="selectOptionsInput" rows="3" style="width:100%;" placeholder="Op1, Op2, Op3"></textarea> 
                <div class="selectFormButtons"> 
                    <button class="saveBtn" onclick="saveSelectField()">💾 Salvar</button> 
                    <button class="deleteBtn" onclick="deleteSelectField()">🗑️</button> 
                    <button class="clearBtn" onclick="clearSelectForm()">🧹</button> 
                </div> 
            </div> 
        </div> 
        <div class="modal-footer"> 
            <button class="closeBtn" onclick="closeSelectManagerModal()">Fechar</button> 
        </div> 
    </div> 
</div> 
 
<!-- MODAL: Atualização Rápida -->
<div id="quickImportModal" class="modal"> 
    <div class="modalContent"> 
        <h2>🔁 Atualização Rápida</h2> 
        <label>Título Destino:</label>
        <div style="display:flex; gap:5px;">
            <select id="importTargetTitulo" style="flex:1;" onchange="updateImportTemaSelect()"></select>
            <button class="deleteBtn" onclick="deleteTituloModal()">🗑️</button>
        </div>
        <label>Tema Destino:</label>
        <div style="display:flex; gap:5px;">
            <select id="importTargetTema" style="flex:1;" onchange="updateTextareaOnTemaChange()"></select>
            <button class="deleteBtn" onclick="deleteTemaModal()">🗑️</button>
        </div>
        <label>Novo (Opcional):</label>
        <input type="text" id="importDelimiter" placeholder="Ex: Título - Tema" style="width:100%;">
        <textarea id="importTextarea" placeholder="Cole os textos aqui (use -- para separar modelos)..." style="min-height:100px; width:100%;"></textarea> 
        <div class="modal-footer"> 
            <button class="button" onclick="closeQuickImportModal()">Cancelar</button> 
            <button class="saveBtn" onclick="processQuickImport()">Confirmar</button> 
        </div> 
    </div> 
</div> 
 
<!-- MODAL: Colunas -->
<div id="columnModal" class="modal"> 
    <div class="modalContent"> 
        <h2>🛠 Configurar Colunas</h2> 
        <ul id="columnList" style="max-height:40vh; overflow-y:auto; padding:10px; border:1px solid #eee;"></ul> 
        <div class="modal-footer"> 
            <button class="button" onclick="closeColumnModal()">Cancelar</button> 
            <button class="saveBtn" onclick="saveColumnSelection()">Salvar</button> 
        </div> 
    </div> 
</div> 
 
<!-- MODAL: Restaurar TXT -->
<div id="restoreTXTModal" class="modal"> 
    <div class="modalContent"> 
        <h2>📂 Restaurar via TXT</h2> 
        <p>Selecione um arquivo .txt (Backup). 🚨 Isso substituirá os dados atuais.</p> 
        <input type="file" id="fileInputTXT" accept=".txt"> 
        <div class="modal-footer"> 
            <button class="button" onclick="closeRestoreTXTModal()">Cancelar</button> 
            <button class="deleteBtn" style="background-color:#FF4500;" onclick="handleRestoreTXT()">Restaurar</button> 
        </div> 
    </div> 
</div> 
 
<!-- MODAL: Saudação/Fechamento -->
<div id="sfManagementModal" class="modal"> 
    <div class="modalContent"> 
        <label>Título:</label> 
        <input type="text" id="saudacaoFechamentoName" placeholder="Ex: Padrão" style="width:100%;"> 
        <label>Saudação:</label> 
        <textarea id="saudacaoText" style="width:100%; height:60px;"></textarea> 
        <label>Fechamento:</label> 
        <textarea id="fechamentoText" style="width:100%; height:60px;"></textarea> 
        <div class="button-group"> 
            <button class="saveBtn" onclick="addOrUpdateSaudacaoFechamento()">Salvar</button> 
            <button class="deleteBtn" onclick="deleteSelectedSF()">Excluir</button> 
            <button class="closeBtn" onclick="closeSFManagementModal()">Fechar</button> 
        </div> 
        <div id="sfListContainer" style="max-height:200px; overflow-y:auto; border:1px solid #ccc; margin-top:10px;"></div> 
    </div> 
</div> 

<!-- MODAL: Fundos -->
<div id="fundosModal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100%; height:100%; background:rgba(0,0,0,0.8);">
    <div style="background:#fff; margin:5vh auto; width:95%; max-width:650px; border-radius:12px; height: 90vh; display: flex; flex-direction: column;">
        <div style="padding: 20px; border-bottom: 1px solid #eee;">
            <div style="display: flex; justify-content: space-between;">
                <h3 style="margin:0; color:#005ca9;">Selecionar Fundos</h3>
                <button onclick="fecharModalFundos()" style="background:none; border:none; font-size:24px; cursor:pointer;">&times;</button>
            </div>
            <div style="margin-top:10px;">
                <label>1. Escolha a Faixa:</label>
                <div style="display:flex; gap:10px; margin-top:5px;">
                     <select id="modalFaixaSelect" style="flex:1; padding:8px;"></select>
                     <button id="btnCopiarFaixa" onclick="copiarFaixaSelecionada()" class="button" style="display:none; padding:5px 10px;">📋 Copiar Faixa</button>
                </div>
            </div>
            <div id="modalCodes" style="background:#f0f7ff; padding:10px; margin-top:10px; border-radius:5px; display:flex; justify-content: space-around;">
                 <div style="text-align:center;"><small>PGBL</small><br><strong id="codePGBL">—</strong></div>
                 <div style="text-align:center;"><small>VGBL</small><br><strong id="codeVGBL">—</strong></div>
            </div>
        </div>
        <div style="flex: 1; overflow-y: auto; padding: 20px; background: #fafafa;">
            <label style="font-weight:bold;">2. Selecione os Fundos:</label>
            <div id="listaFundosCheckbox" style="background:#fff; border:1px solid #eee; margin-top:5px;"></div>
        </div>
        <div style="padding: 15px; text-align: right; border-top: 1px solid #eee;">
            <button onclick="fecharModalFundos()" class="button" style="background:#ccc; color:#333;">Cancelar</button>
            <button onclick="confirmarSelecaoFundos()" class="button">Inserir</button>
        </div>
    </div>
</div>
 
    <!-- CONTEÚDO PRINCIPAL -->
    <div class="containerdevolucao3"> 
        <div class="button-group"> 
            <button class="button" style="background-color: #FF4500;" onclick="openQuickImportModal()">🔁 Edição Rápida</button>                 
            <button id="toggleManagementBtn" class="button" style="background-color: #FF4500;" onclick="toggleManagementMode(true)">🛠️ Gerenciar Modelos</button> 
            <button class="button" style="background-color: #FF4500;" onclick="openSelectManagerModal()">🛠️ Campos Seleção</button> 
            <button class="button" style="background-color: #FF4500;" onclick="openSFManagementModal()">🛠️ Tipo de Fechamento</button>
            <button class="button" style="background-color: #FF4500;" onclick="saveToServer()">♻️ Atualizar na Rede</button>          
        </div> 
 
        <div class="button-group"> 
            <button id="toggleAllBtn" onclick="toggleAll('expand')" class="button">Abrir Todas</button> 
            <button id="collapseAllBtn" onclick="toggleAll('collapse')" class="button" style="display:none;">Recolher Todas</button> 
            <button class="button" onclick="downloadBackupTXT()">📄 Salvar Local</button> 
            <button class="button" onclick="openRestoreTXTModal()">📂 Restaurar Local</button>                 
            <button class="button" style="background-color:#008000;" onclick="restoreFromServer()">🔄 Restaurar Rede</button>     
        </div> 
 
        <br>
        
        <div style="margin-bottom: 20px;">  
            <input type="text" id="searchInput" oninput="filterSections()" placeholder="🔎 Buscar texto..." /> 
             
            <div id="managementBar" class="management-bar" style="display: none;"> 
                <span>Modo de Gerenciamento: Arraste (::), edite ou exclua.</span> 
                <div> 
                     <button class="saveBtn" onclick="addNewTitulo()">➕ Título</button> 
                     <button id="toggleManagementBtnExit" class="button" style="background-color: #000;" onclick="toggleManagementMode(false)">💾 Salvar e Sair</button> 
                </div> 
            </div> 
             
            <div id="sectionsContainer"></div> 
        </div> 
    </div> 
     
    <div class="containerdevolucao3"> 
        <div id="dynamicInputsContainer"></div> 
         
        <div class="button-group"> 
            <button id="clearBtn" class="button">👥 Novo Atendimento</button> 
            <button class="button" onclick="limparCamposDinamicosExcetoData()">🧹 Limpar Campos</button> 
        </div> 
         
        <div id="sf-controls"> 
           <div class="select-group"> 
               <label for="saudacaoFechamentoSelect">Tipo de Fechamento:</label> 
               <select id="saudacaoFechamentoSelect" onchange="generateText()"></select> 
           </div> 

 
           <div class="middle-buttons"> 
               <button id="btnProcedente" class="saveBtn" onclick="handleAction('Procedente')">✅ Procedente</button> 
               <button id="btnImprocedente" class="deleteBtn" onclick="handleAction('Improcedente')">❌ Improcedente</button> 
               <button id="btnParcialProcedente" class="clearBtn" onclick="handleAction('Parcialmente Procedente')">◑ Parcialmente Procedente</button> 
           </div> 
       </div> 
       <br> 
       <textarea id="resultText" placeholder="O texto gerado aparecerá aqui..."></textarea> 
    </div> 
 
    <div class="containerdevolucao3"> 
        <h1 class="texto">📋 Anotações</h1> 
        <textarea id="notesdevolução" style="min-height: 100px; resize: vertical; width: 100%; padding: 15px; border: 1px solid #ccc; border-radius: 8px; box-sizing: border-box;"></textarea> 
        <div class="button-group"> 
            <button id="clearNotesdevolução" class="button">🧹 Limpar</button> 
            <button id="copyNotesdevolução" class="button">📋 Copiar</button> 
        </div> 
    </div> 
     
    <div class="containerdevolucao3"> 
        <h1 class="texto">📚 Histórico de Atendimentos</h1> 
        <div class="button-group">                 
            <button class="button" onclick="visualizarHistory()">👁️ Visualizar</button> 
            <button class="button" onclick="downloadHistory()">📥 Baixar TXT</button> 
            <button class="button" onclick="downloadHistoryAsExcel()">📥 Baixar Excel</button>                 
        </div> 
 
        <div class="history-controls"> 
            <div class="button-group"> 
                <label>Período:</label> 
                <input type="date" id="startDate" onchange="filterByDate()"> 
                <label>até</label> 
                <input type="date" id="endDate" onchange="filterByDate()"> 
                <button class="button" onclick="filterByDate()">🔎</button> 
                <button class="button" onclick="resetFilter()">🔄</button> 
                <button class="button" onclick="openColumnModal()">🛠️ Colunas</button> 
            </div> 
             
            <div class="button-group">  
                <select id="filterMode" onchange="globalFilter()"> 
                    <option value="includes" selected>Contém</option> 
                    <option value="exact">Exata</option> 
                    <option value="starts">Começa com</option> 
                </select> 
                <input type="text" id="globalSearch" placeholder="🔍 Buscar no histórico..." oninput="globalFilter()"> 
            </div> 
        </div> 
 
        <div class="button-group"> 
            <button id="btnLeft" class="button" disabled style="opacity: 0.5;">◀️</button> 
            <button id="btnRight" class="button">▶️</button> 
        </div> 
 
        <div id="scrollContainer" style="overflow-x: auto; max-width: 100%; border: 1px solid #ccc; margin-top: 10px;"> 
            <table id="historyTable"> 
                <thead><tr></tr></thead> 
                <tbody></tbody> 
            </table> 
        </div> 
    </div> 
 
    <script> 
        /* ======================================= */ 
        /* 💡 CONFIGURAÇÃO GLOBAL E DADOS INICIAIS */ 
        /* ======================================= */ 
        
        // Definições de colunas do sistema para o histórico (REMOVIDO 'select' para corrigir visualização vazia)
        const systemColumns = [ 
          { id: 'data', name: 'Data', visible: true, type: 'text' }, 
          { id: 'sfSelecionado', name: 'Tipo/Fechamento', visible: true, type: 'text' }, 
          { id: 'Número da Ocorrência', name: 'Número da Ocorrência', visible: true, type: 'text' }, 
          { id: 'usuarioLogado', name: 'Usuário Logado', visible: true, type: 'text' }, 
          { id: 'categoria', name: 'Categoria', visible: true, type: 'text' }, 
          { id: 'titulos', name: 'Título(s)', visible: true, type: 'text' },  
          { id: 'temas', name: 'Tema(s)', visible: true, type: 'text' },  
        ];

        // Declaração de Variáveis Globais para evitar erros de escopo
        let modelosData = []; 
        let historicoData = []; 
        let saudacoesFechamentosData = [];
        let selectFieldsData = [];
        let visibleColumns = []; 
        
        // Estado da aplicação
        let selectedSFId = null; 
        let camposDinamicos = {}; 
        let textosSelecionados = new Set();  
        let isManagementMode = false;  
        let draggedIndex = null; 
        let sortableInstances = [];  
        let expandedState = {}; 
        let inputAlvoAtual = null;
        let sortableColumnInstance;

        // Dados estáticos de fundos
        const FUND_DATA = [ 
            { "faixa": "— Ocultar Lista de Fundos —", "pgbl": "—", "vgbl": "—", "fundos": [] }, 
            { "faixa": "de R$ 35,00 a R$ 9.999,99", "pgbl": "1324", "vgbl": "1323", "fundos": ["CAIXA FIC PREV 250 RF", "CAIXA FIC PREV 250 RF PÓS FIXADO", "CAIXA FIC PREV 250 RF MODERADO", "CAIXA FIC PREV 250 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 250 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 250 INFLAÇÃO ATIVA", "CAIXA FIC PREV 250 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 250 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 250 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 250 MULTI RV 15", "CAIXA FIC PREV 250 MULTI RV 30", "CAIXA FIC PREV 250 MULTI RV 49", "CAIXA FIC PREV 200 MULTI RV 70", "CAIXA FIC PREV 250 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "de R$ 10.000,00 a R$ 49.999,99", "pgbl": "1323", "vgbl": "1312", "fundos": ["CAIXA FIC PREV 200 RF", "CAIXA FIC PREV 200 RF PÓS FIXADO", "CAIXA FIC PREV 200 RF MODERADO", "CAIXA FIC PREV 200 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 200 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 250 INFLAÇÃO ATIVA", "CAIXA FIC PREV 195 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 195 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 250 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 200 MULTI RV 15", "CAIXA FIC PREV 200 MULTI RV 30", "CAIXA FIC PREV 200 MULTI RV 49", "CAIXA FIC PREV 200 MULTI RV 70", "CAIXA FIC PREV 250 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "de R$ 50.000,00 a R$ 99.999,99", "pgbl": "1322", "vgbl": "1311", "fundos": ["CAIXA FIC PREV 180 RF", "CAIXA FIC PREV 180 RF PÓS FIXADO", "CAIXA FIC PREV 200 RF MODERADO", "CAIXA FIC PREV 200 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 200 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 250 INFLAÇÃO ATIVA", "CAIXA FIC PREV 195 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 195 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 250 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 200 MULTI RV 15", "CAIXA FIC PREV 200 MULTI RV 30", "CAIXA FIC PREV 200 MULTI RV 49", "CAIXA FIC PREV 200 MULTI RV 70", "CAIXA FIC PREV 250 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "de R$ 100.000,00 á R$ 174.999,99", "pgbl": "1321", "vgbl": "1310", "fundos": ["CAIXA FIC PREV 150 RF", "CAIXA FIC PREV 150 RF PÓS FIXADO", "CAIXA FIC PREV 150 RF MODERADO", "CAIXA FIC PREV 150 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 150 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 150 INFLAÇÃO ATIVA", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 200 MULTI RV 15", "CAIXA FIC PREV 200 MULTI RV 30", "CAIXA FIC PREV 200 MULTI RV 49", "CAIXA FIC PREV 200 MULTI RV 70", "CAIXA FIC PREV 200 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "de R$ 175.000,00 á R$ 249.999,99", "pgbl": "1320", "vgbl": "1309", "fundos": ["CAIXA FIC PREV 130 RF", "CAIXA FIC PREV 130 RF PÓS FIXADO", "CAIXA FIC PREV 150 RF MODERADO", "CAIXA FIC PREV 150 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 150 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 150 INFLAÇÃO ATIVA", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 200 MULTI RV 15", "CAIXA FIC PREV 200 MULTI RV 30", "CAIXA FIC PREV 200 MULTI RV 49", "CAIXA FIC PREV 200 MULTI RV 70", "CAIXA FIC PREV 200 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "de R$ 250.000,00 á R$ 499.999,99", "pgbl": "1319", "vgbl": "1308", "fundos": ["CAIXA FIC PREV 100 RF", "CAIXA FIC PREV 100 RF PÓS FIXADO", "CAIXA FIC PREV 125 RF MODERADO", "CAIXA FIC PREV 125 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 125 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 125 INFLAÇÃO ATIVA", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 150 MULTI RV 15", "CAIXA FIC PREV 150 MULTI RV 30", "CAIXA FIC PREV 150 MULTI RV 49", "CAIXA FIC PREV 150 MULTI RV 70", "CAIXA FIC PREV 150 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "de R$ 500.000,00 á R$ 999.999,99", "pgbl": "1318", "vgbl": "1307", "fundos": ["CAIXA FIC PREV 70 RF", "CAIXA FIC PREV 70 RF PÓS FIXADO", "CAIXA FIC PREV 80 RF MODERADO", "CAIXA FIC PREV 80 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 80 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 80 INFLAÇÃO ATIVA", "CAIXA FIC PREV 100 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 100 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 100 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 125 MULTI RV 15", "CAIXA FIC PREV 125 MULTI RV 30", "CAIXA FIC PREV 125 MULTI RV 49", "CAIXA FIC PREV 125 MULTI RV 70", "CAIXA FIC PREV 125 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "de R$ 1.000.000,00 á R$ 2.999.999,99", "pgbl": "1317", "vgbl": "1306", "fundos": ["CAIXA FIC PREV 60 RF", "CAIXA FIC PREV 60 RF PÓS FIXADO", "CAIXA FIC PREV 70 RF MODERADO", "CAIXA FIC PREV 80 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 80 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 80 INFLAÇÃO ATIVA", "CAIXA FIC PREV 100 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 100 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 100 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 125 MULTI RV 15", "CAIXA FIC PREV 125 MULTI RV 30", "CAIXA FIC PREV 125 MULTI RV 49", "CAIXA FIC PREV 125 MULTI RV 70", "CAIXA FIC PREV 125 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "de R$ 3.000.000,00 à R$ 5.999.999,99", "pgbl": "1316", "vgbl": "1305", "fundos": ["CAIXA FIC PREV 50 RF", "CAIXA FIC PREV 50 RF PÓS FIXADO", "CAIXA FIC PREV 60 RF MODERADO", "CAIXA FIC PREV 80 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 60 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 80 INFLAÇÃO ATIVA", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 125 MULTI RV 15", "CAIXA FIC PREV 125 MULTI RV 30", "CAIXA FIC PREV 125 MULTI RV 49", "CAIXA FIC PREV 125 MULTI RV 70", "CAIXA FIC PREV 125 RV 70 LIVRE QUANTITATIVO", "CAIXA FIC PREV 50 VERTICE NTNB 2028 RENDA FIXA", "CAIXA FIC PREV 50 VERTICE NTNB 2030 RENDA FIXA"] }, 
            { "faixa": "de R$ 6.000.000,00 à R$ 9.999.999,99", "pgbl": "1315", "vgbl": "1304", "fundos": ["CAIXA FIC PREV 40 RF", "CAIXA FIC PREV 40 RF PÓS FIXADO", "CAIXA FIC PREV 80 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 60 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 80 INFLAÇÃO ATIVA", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 125 MULTI RV 15", "CAIXA FIC PREV 125 MULTI RV 30", "CAIXA FIC PREV 125 MULTI RV 49", "CAIXA FIC PREV 125 MULTI RV 70", "CAIXA FIC PREV 125 RV 70 LIVRE QUANTITATIVO", "CAIXA FIC PREV 50 VERTICE NTNB 2028 RENDA FIXA", "CAIXA FIC PREV 50 VERTICE NTNB 2030 RENDA FIXA"] }, 
            { "faixa": "acima de R$ 10.000.000,00", "pgbl": "1314", "vgbl": "1303", "fundos": ["CAIXA FIC PREV 30 RF", "CAIXA FIC PREV 30 RF PÓS FIXADO", "CAIXA FIC PREV 80 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 60 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 80 INFLAÇÃO ATIVA", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 125 MULTI RV 15", "CAIXA FIC PREV 125 MULTI RV 30", "CAIXA FIC PREV 125 MULTI RV 49", "CAIXA FIC PREV 125 MULTI RV 70", "CAIXA FIC PREV 125 RV 70 LIVRE QUANTITATIVO", "CAIXA FIC PREV 30 VERTICE NTNB 2028 RENDA FIXA", "CAIXA FIC PREV 30 VERTICE NTNB 2030 RENDA FIXA"] }, 
            { "faixa": "FEDERAL PREV", "pgbl": "1314", "vgbl": "N/A", "fundos": ["CAIXA FIC PREV 30 RF", "CAIXA FIC PREV 30 RF PÓS FIXADO", "CAIXA FIC PREV 80 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 60 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 80 INFLAÇÃO ATIVA", "CAIXA FIC PREV 60 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 125 MULTI RV 15", "CAIXA FIC PREV 125 MULTI RV 30", "CAIXA FIC PREV 125 MULTI RV 49"] }, 
            { "faixa": "CONVÊNIO SERVIDOR PÚBLICO", "pgbl": "1319", "vgbl": "1308", "fundos": ["CAIXA FIC PREV 100 RF", "CAIXA FIC PREV 100 RF PÓS FIXADO", "CAIXA FIC PREV 125 RF MODERADO", "CAIXA FIC PREV 125 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 125 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 125 INFLAÇÃO ATIVA", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 125 MULTI RV 15", "CAIXA FIC PREV 125 MULTI RV 30", "CAIXA FIC PREV 125 MULTI RV 49", "CAIXA FIC PREV 125 MULTI RV 70", "CAIXA FIC PREV 125 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "CONVÊNIO ECONOMIÁRIO 100 RF", "pgbl": "N/A", "vgbl": "1383", "fundos": ["CAIXA FIC PREV 100 RF", "CAIXA FIC PREV 100 RF PÓS FIXADO", "CAIXA FIC PREV 125 RF MODERADO", "CAIXA FIC PREV 125 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 125 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 125 INFLAÇÃO ATIVA", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 115 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 150 MULTI RV 15", "CAIXA FIC PREV 150 MULTI RV 30", "CAIXA FIC PREV 150 MULTI RV 49", "CAIXA FIC PREV 150 MULTI RV 70", "CAIXA FIC PREV 150 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "CONVÊNIO ECONOMIÁRIO 130 RF", "pgbl": "1358", "vgbl": "1357", "fundos": ["CAIXA FIC PREV 130 RF", "CAIXA FIC PREV 130 RF PÓS FIXADO", "CAIXA FIC PREV 150 RF MODERADO", "CAIXA FIC PREV 150 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 150 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 150 INFLAÇÃO ATIVA", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 145 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 200 MULTI RV 15", "CAIXA FIC PREV 200 MULTI RV 30", "CAIXA FIC PREV 200 MULTI RV 49", "CAIXA FIC PREV 200 MULTI RV 70", "CAIXA FIC PREV 200 RV 70 LIVRE QUANTITATIVO"] }, 
            { "faixa": "CONVÊNIO ECONOMIÁRIO 30 RF", "pgbl": "1314", "vgbl": "1303", "fundos": ["CAIXA FIC PREV 30 RF", "CAIXA FIC PREV 30 RF PÓS FIXADO", "CAIXA FIC PREV 40 RF MODERADO", "CAIXA FIC PREV 80 RF ÍNDICE DE PREÇOS", "CAIXA FIC PREV 60 RF CRÉDITO PRIVADO", "CAIXA FIC PREV 80 INFLAÇÃO ATIVA", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE MODERADO", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE CONSERVADOR", "CAIXA FIC PREV 80 MM ESTRATÉGIA LIVRE ARROJADO", "CAIXA FIC PREV 125 MULTI RV 15", "CAIXA FIC PREV 125 MULTI RV 30", "CAIXA FIC PREV 125 MULTI RV 49", "CAIXA FIC PREV 125 MULTI RV 70", "CAIXA FIC PREV 125 RV 70 LIVRE QUANTITATIVO", "CAIXA FIC PREV 30 VERTICE NTNB 2028 RENDA FIXA", "CAIXA FIC PREV 30 VERTICE NTNB 2030 RENDA FIXA"] }, 
            { "faixa": "AGENDA FUTURO ACIMA de R$ 50.000,00", "pgbl": "1285", "vgbl": "1286", "fundos": ["CAIXA FIC PREV 125 MM AGENDA FUTURO"] }, 
            { "faixa": "CRÉDITO PRIVADO PLUS ACIMA de R$ 500.000,00", "pgbl": "1276", "vgbl": "1277", "fundos": ["CAIXA FIC PREV PRIVATE 80 RF CRÉDITO PRIVADO PLUS"] }, 
            { "faixa": "MULTIGESTOR ACIMA de R$ 50.000,00", "pgbl": "1278", "vgbl": "1279", "fundos": ["CAIXA FIC PREV 100 MM MULTIGESTOR MULTIESTRATÉGIA", "CAIXA FIC PREV 60 MULTIGESTOR CRÉDITO PRIVADO PREMIUM"] }
        ]; 

        // Dados padrão para inicialização
        const defaultSaudacoesFechamentos = [ 
            { id: 1, nome: 'Selecionar', saudacao: '', fechamento: '' }, 
            { id: 2, nome: 'Padrão', saudacao: 'Analisamos sua solicitação:', fechamento: 'Espero ter ajudado, mas qualquer dúvida que você tiver, poderá entrar em contato novamente.' }, 
            { id: 3, nome: 'Complemento', saudacao: 'Analisamos sua solicitação:', fechamento: 'Ficamos no aguardo do retorno com as informações solicitadas para darmos continuidade ao atendimento.' }, 
            { id: 4, nome: 'E-mail', saudacao: 'Prezado(a) {Nome},', fechamento: 'Atenciosamente,\n{Assinatura}' } 
        ]; 
        
        const defaultModelosData = [ 
            { titulo: 'Tema Principal', temas: [{ tema: 'Subtema', modelos: [{ id: Date.now() + 1, texto: `Seu protocolo é {Protocolo}. Para continuarmos com a solicitação {Status} em {Data Atual}, precisamos do documento {Documento}.` }] }] }, 
            { titulo: 'E-mail', temas: [{ tema: 'E-mail', modelos: [{ id: Date.now() + 2, texto: `Estamos entrando em contato referente ao protocolo {Protocolo}. Para darmos continuidade ao atendimento iniciado em {Data Atual}, solicitamos o envio do documento {Documento}.\n\nAgradecemos a atenção e permanecemos à disposição.` }] }] } 
        ]; 
        
        const defaultSelectFields = [ 
            { key: "STATUS", options: ["Selecionar", "aberta", "concluída"] }, 
            { key: "DOCUMENTO", options: ["Selecionar", "RG", "CPF", "CNH"] } 
        ]; 

        // Inicialização de Dados
        try { 
            const loadedModelos = JSON.parse(localStorage.getItem('modelosTextoGeral')); 
            modelosData = (loadedModelos && loadedModelos.length > 0) ? loadedModelos : defaultModelosData; 
            
            const loadedSF = JSON.parse(localStorage.getItem('saudacoesFechamentosData')); 
            saudacoesFechamentosData = (loadedSF && loadedSF.length > 0) ? loadedSF : defaultSaudacoesFechamentos; 
            
            const loadedSelect = JSON.parse(localStorage.getItem('selectFieldsData'));
            selectFieldsData = (loadedSelect && Array.isArray(loadedSelect)) ? loadedSelect : defaultSelectFields;

            // Compatibilidade com histórico anterior
            if(historicoData && historicoData.length > 0){
                 historicoData = historicoData.map(item => { 
                    item.titulos = item.titulos || (item.modelosSelecionados ? item.modelosSelecionados.map(m => m.titulo).join('; ') : 'N/A'); 
                    item.temas = item.temas || (item.modelosSelecionados ? item.modelosSelecionados.map(m => m.tema).join('; ') : 'N/A'); 
                    delete item.saudacaoFechamentoId; 
                    delete item.saudacaoFechamentoNome; 
                    return item; 
                });
            }
        } catch (e) { 
            console.error("Erro ao carregar localStorage:", e); 
            modelosData = defaultModelosData; 
            saudacoesFechamentosData = defaultSaudacoesFechamentos; 
            selectFieldsData = defaultSelectFields;
            historicoData = []; 
        } 
        
        /* ======================================= */ 
        /* 1. FUNÇÕES GERAIS E PERSISTÊNCIA */ 
        /* ======================================= */ 
        function saveLocalData() { 
            localStorage.setItem('modelosTextoGeral', JSON.stringify(modelosData)); 
            localStorage.setItem('saudacoesFechamentosData', JSON.stringify(saudacoesFechamentosData)); 
            localStorage.setItem('selectFieldsData', JSON.stringify(selectFieldsData)); 
            if (visibleColumns.length > 0) { 
                localStorage.setItem('historicoColumnsGeral', JSON.stringify(visibleColumns)); 
            } 
        } 
        
        function safeHTML(text) { 
            const div = document.createElement('div'); 
            div.textContent = text.trim();  
            return div.innerHTML; 
        } 
        
        function escapeRegExp(string) { 
            return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); 
        } 

        function extractModeloName(modeloText) { 
             if (!modeloText) return 'Modelo Sem Nome'; 
             let name = modeloText.trim().split('\n')[0].substring(0, 50).trim(); 
             if (name.includes(':') && name.indexOf(':') > 0) name = name.split(':')[0].trim(); 
             else if (name.endsWith(':')) name = name.slice(0, -1).trim(); 
             return name || 'Modelo Sem Nome'; 
        } 
        
        function findModeloAndIndexes(id) { 
            const modelId = Number(id); 
            for (let tIndex = 0; tIndex < modelosData.length; tIndex++) { 
                const tituloData = modelosData[tIndex]; 
                for (let temaIndex = 0; temaIndex < tituloData.temas.length; temaIndex++) { 
                    const temaData = tituloData.temas[temaIndex]; 
                    const modeloIndex = temaData.modelos.findIndex(m => Number(m.id) === modelId); 
                    if (modeloIndex !== -1) return { tIndex, temaIndex, modeloIndex, titulo: tituloData.titulo, tema: temaData.tema, modelo: temaData.modelos[modeloIndex] }; 
                } 
            } 
            return null; 
        } 

        /* ======================================= */ 
        /* 2. RENDERIZAÇÃO E ACORDEÃO */ 
        /* ======================================= */ 
        function saveExpandedState() { 
            expandedState = {}; 
            document.querySelectorAll('.section-header.expanded, .tema-header.expanded').forEach(header => { 
                let id = ''; 
                if (header.classList.contains('section-header')) id = `t-${header.parentElement.dataset.index}`; 
                else if (header.classList.contains('tema-header')) { 
                    const parent = header.closest('.tema-item'); 
                    id = `tema-${parent.dataset.tIndex}-${parent.dataset.temaIndex}`; 
                } 
                if (id) expandedState[id] = true; 
            }); 
        } 
        
        function restoreExpandedState() { 
            document.querySelectorAll('.section').forEach(section => { 
                const tIndex = section.dataset.index; 
                const sectionId = `t-${tIndex}`; 
                const header = section.querySelector('.section-header'); 
                const content = section.querySelector('.subitems'); 
                
                const shouldExpand = (expandedState[sectionId] && !isManagementMode && !document.getElementById('searchInput').value) 
                                   || (isManagementMode && expandedState[sectionId]);
                
                if (header && content) {
                    if(shouldExpand) { header.classList.add('expanded'); content.style.display = 'block'; }
                    else { header.classList.remove('expanded'); content.style.display = 'none'; }
                }

                section.querySelectorAll('.tema-item').forEach(temaItem => { 
                    const temaId = `tema-${temaItem.dataset.tIndex}-${temaItem.dataset.temaIndex}`; 
                    const tHeader = temaItem.querySelector('.tema-header'); 
                    const tContent = temaItem.querySelector('.tema-content'); 
                    
                    const shouldExpandTema = (expandedState[temaId] && !isManagementMode && !document.getElementById('searchInput').value) 
                                           || (isManagementMode && expandedState[temaId]);

                    if (tHeader && tContent) {
                        if(shouldExpandTema) { tHeader.classList.add('expanded'); tContent.style.display = 'block'; }
                        else { tHeader.classList.remove('expanded'); tContent.style.display = 'none'; }
                    }
                }); 
            }); 
        } 

        function renderSections() { 
            sortableInstances.forEach(s => s.destroy()); 
            sortableInstances = []; 
             
            const container = document.getElementById('sectionsContainer'); 
            container.innerHTML = ''; 
            const manageModeClass = isManagementMode ? 'visible' : ''; 
            const managementTextDisplay = isManagementMode ? 'inline-block' : 'none'; 

            modelosData.forEach((tituloData, tituloIndex) => { 
                let temasHtml = ''; 
                tituloData.temas.forEach((temaData, temaIndex) => { 
                    let modelosHtml = temaData.modelos.map(modelo => { 
                        const isChecked = textosSelecionados.has(Number(modelo.id)); 
                        const modelName = extractModeloName(modelo.texto); 
                        return ` 
                            <div class="model-item ${isManagementMode ? 'draggable' : ''}" data-id="${modelo.id}" data-titulo="${tituloData.titulo}" data-tema="${temaData.tema}" ondblclick="${isManagementMode ? `editModeloInline(event, ${modelo.id})` : ''}"> 
                                <span class="drag-handle ${manageModeClass}">::</span> 
                                <input type="checkbox" id="model-${modelo.id}" onchange="toggleModelSelection('${modelo.id}', this.checked)" ${isChecked ? 'checked' : ''} ${isManagementMode ? 'disabled' : ''}/> 
                                <label for="model-${modelo.id}" class="model-text" title="${modelName}"> 
                                    <textarea id="modelText-${modelo.id}" class="model-text-manage" data-id="${modelo.id}" onblur="saveModelText(${modelo.id})" onkeydown="handleTextareaKeydown(event, ${modelo.id})">${modelo.texto.trim()}</textarea> 
                                    <span class="model-text-display">${safeHTML(modelo.texto.trim())}</span> 
                                </label> 
                                <div class="management-actions ${manageModeClass}"> 
                                    <button class="button" onclick="editModeloInline(event, ${modelo.id})">✏️</button> 
                                    <button class="deleteBtn" onclick="deleteModelo(${modelo.id})">🗑️</button> 
                                </div> 
                            </div>`; 
                    }).join(''); 

                    const allModelsSelected = temaData.modelos.length > 0 && temaData.modelos.every(m => textosSelecionados.has(Number(m.id))); 
                    temasHtml += ` 
                    <div class="tema-item ${isManagementMode ? 'draggable' : ''}" data-t-index="${tituloIndex}" data-tema-index="${temaIndex}"> 
                        <div class="tema-header" onclick="if(!document.querySelector('.editing') && event.target.tagName !== 'INPUT') toggleAccordion(this)" ondblclick="${isManagementMode ? `editTemaInline(event, ${tituloIndex}, ${temaIndex})` : ''}"> 
                            <span class="drag-handle ${manageModeClass}">☷</span> 
                            <input type="checkbox" id="selectAllTema-${tituloIndex}-${temaIndex}" onchange="toggleAllModelsInTema(${tituloIndex}, ${temaIndex}, this.checked)" ${allModelsSelected ? 'checked' : ''} ${isManagementMode ? 'disabled' : ''} style="margin-right: 10px; display: ${isManagementMode ? 'none' : 'inline-block'};" /> 
                            <input type="text" id="temaInput-${tituloIndex}-${temaIndex}" class="editable-input" value="${temaData.tema}" onblur="saveTemaName(this, ${tituloIndex}, ${temaIndex})" onkeydown="handleInputKeydown(event)"/> 
                            <span class="editable-display">${temaData.tema} (${temaData.modelos.length})</span> 
                            <div class="management-actions ${manageModeClass}"> 
                                <button class="saveBtn" style="padding: 2px 8px;" onclick="addNewModelo(${tituloIndex}, ${temaIndex})">➕</button> 
                                <button class="deleteBtn" style="padding: 2px 8px;" onclick="deleteTema(${tituloIndex}, ${temaIndex})">🗑️</button> 
                            </div> 
                            <span class="icon" style="margin-left:auto; display: ${managementTextDisplay === 'inline-block' ? 'none' : 'inline-block'};">▶</span> 
                        </div> 
                        <div class="tema-content" id="modelContainer-${tituloIndex}-${temaIndex}">${modelosHtml}</div> 
                    </div>`; 
                }); 
                
                const sectionHtml = ` 
                    <div class="section ${isManagementMode ? 'draggable' : ''}" data-index="${tituloIndex}"> 
                        <div class="section-header" onclick="if(!document.querySelector('.editing')) toggleAccordion(this)" ondblclick="${isManagementMode ? `editTituloInline(event, ${tituloIndex})` : ''}"> 
                            <span class="drag-handle ${manageModeClass}">☰</span> 
                            <input type="text" id="tituloInput-${tituloIndex}" class="editable-input" value="${tituloData.titulo}" onblur="saveTituloName(this, ${tituloIndex})" onkeydown="handleInputKeydown(event)"/> 
                            <span class="editable-display" style="color: white;">${tituloData.titulo}</span> 
                            <div class="management-actions ${manageModeClass}"> 
                                <button class="saveBtn" style="padding: 2px 8px;" onclick="addNewTema(${tituloIndex})">➕</button> 
                                <button class="deleteBtn" style="padding: 2px 8px;" onclick="deleteTitulo(${tituloIndex})">🗑️</button> 
                            </div> 
                            <span class="icon" style="margin-left:auto; display: ${managementTextDisplay === 'inline-block' ? 'none' : 'inline-block'};">▶</span> 
                        </div> 
                        <div class="subitems" id="temaContainer-${tituloIndex}">${temasHtml}</div> 
                    </div>`; 
                container.innerHTML += sectionHtml; 
            }); 
            
            detectarCamposDinamicos(); 
            if (isManagementMode) initializeSortables(); 
            restoreExpandedState(); 
        } 

        function renderSaudacaoFechamentoSelect() { 
            const select = document.getElementById('saudacaoFechamentoSelect'); 
            if (!select) return; 
            const currentValue = select.value; 
            select.innerHTML = ''; 
            saudacoesFechamentosData.forEach(sf => { 
                const option = document.createElement('option'); 
                option.value = sf.id; 
                option.textContent = sf.nome; 
                select.appendChild(option); 
            }); 
            if (currentValue && saudacoesFechamentosData.some(sf => sf.id == currentValue)) select.value = currentValue; 
            else if (saudacoesFechamentosData.length > 0) select.value = saudacoesFechamentosData[0].id; 
            detectarCamposDinamicos(); 
            generateText(); 
        } 

        function initializeSortables() { 
            if (!window.sortableInstances) window.sortableInstances = []; 
            
            const tituloContainer = document.getElementById('sectionsContainer'); 
            if (tituloContainer) { 
                window.sortableInstances.push(new Sortable(tituloContainer, { 
                    animation: 150, handle: '.section-header .drag-handle', draggable: '.section', 
                    onEnd: function(evt) { 
                        const [movedItem] = modelosData.splice(evt.oldIndex, 1); 
                        modelosData.splice(evt.newIndex, 0, movedItem); 
                        saveAndRerender(true); 
                    } 
                })); 
            } 

            modelosData.forEach((tituloData, tIndex) => { 
                const temaContainerEl = document.getElementById(`temaContainer-${tIndex}`); 
                if (temaContainerEl) { 
                    window.sortableInstances.push(new Sortable(temaContainerEl, { 
                        animation: 150, handle: '.tema-header .drag-handle', draggable: '.tema-item', group: 'sharedTemas', 
                        onEnd: function(evt) { 
                            const toTIndex = parseInt(evt.to.closest('.section').dataset.index); 
                            const [movedTema] = modelosData[tIndex].temas.splice(evt.oldIndex, 1); 
                            modelosData[toTIndex].temas.splice(evt.newIndex, 0, movedTema); 
                            saveAndRerender(true); 
                        } 
                    })); 
                } 
                
                tituloData.temas.forEach((temaData, temaIndex) => { 
                    const modelListEl = document.getElementById(`modelContainer-${tIndex}-${temaIndex}`); 
                    if (modelListEl) { 
                        window.sortableInstances.push(new Sortable(modelListEl, { 
                            animation: 150, handle: '.model-item .drag-handle', draggable: '.model-item', group: 'sharedModels', 
                            onEnd: function(evt) { 
                                const movedId = parseFloat(evt.item.dataset.id); 
                                const originalData = findModeloAndIndexes(movedId); 
                                if (!originalData) return; 
                                const [movedItem] = modelosData[originalData.tIndex].temas[originalData.temaIndex].modelos.splice(originalData.modeloIndex, 1); 
                                const parts = evt.to.id.split('-'); 
                                modelosData[parseInt(parts[1])].temas[parseInt(parts[2])].modelos.splice(evt.newIndex, 0, movedItem); 
                                saveAndRerender(true); 
                            } 
                        })); 
                    } 
                }); 
            }); 
        } 

        function saveAndRerender(keepManagementMode = false) { 
             saveExpandedState(); 
             saveLocalData(); 
             const tempState = isManagementMode; 
             isManagementMode = keepManagementMode; 
             renderSections(); 
             isManagementMode = tempState; 
             renderSaudacaoFechamentoSelect(); 
             updateVisibleColumnsFromHistory(); 
             renderHistorico(); 
        } 

        function toggleManagementMode(activate) { 
            isManagementMode = activate; 
            const btn = document.getElementById('toggleManagementBtn'); 
            const btnExit = document.getElementById('toggleManagementBtnExit'); 
            const searchInput = document.getElementById('searchInput'); 
            const managementBar = document.getElementById('managementBar'); 
            
            if (isManagementMode) { 
                btn.style.display = 'none'; btnExit.style.display = 'inline-block'; 
                managementBar.style.display = 'flex'; searchInput.disabled = true; 
                document.getElementById('searchInput').value = ''; filterSections(); 
            } else { 
                saveLocalData(); 
                document.getElementById('searchInput').value = ''; 
                btn.style.display = 'inline-block'; btnExit.style.display = 'none'; 
                managementBar.style.display = 'none'; searchInput.disabled = false; 
                expandedState = {}; toggleAll('collapse'); 
            } 
            saveAndRerender(isManagementMode); 
        } 

        // Funções de Edição Inline (Título/Tema/Modelo)
        function editTituloInline(event, tIndex) { 
            if (!isManagementMode) return; event.stopPropagation(); 
            event.currentTarget.classList.add('editing'); 
            const input = document.getElementById(`tituloInput-${tIndex}`); 
            input.focus(); input.select(); 
        } 
        function saveTituloName(element, tIndex) { 
            modelosData[tIndex].titulo = element.value.trim() || 'Título Sem Nome'; 
            element.closest('.section-header').classList.remove('editing'); 
            saveAndRerender(true); 
        } 
        function editTemaInline(event, tIndex, temaIndex) { 
            if (!isManagementMode) return; event.stopPropagation(); 
            event.currentTarget.classList.add('editing'); 
            const input = document.getElementById(`temaInput-${tIndex}-${temaIndex}`); 
            input.focus(); input.select(); 
        } 
        function saveTemaName(element, tIndex, temaIndex) { 
             modelosData[tIndex].temas[temaIndex].tema = element.value.trim() || 'Tema Sem Nome'; 
             element.closest('.tema-header').classList.remove('editing'); 
             saveAndRerender(true); 
        } 
        function editModeloInline(event, id) { 
             if (!isManagementMode) return; event.stopPropagation(); event.preventDefault(); 
             const item = document.querySelector(`.model-item[data-id="${id}"]`); 
             if (item && !item.classList.contains('editing')) { 
                item.classList.add('editing'); item.classList.remove('draggable'); 
                const textarea = document.getElementById(`modelText-${id}`); 
                textarea.style.height = textarea.scrollHeight + "px"; 
                textarea.focus(); 
             } 
        } 
        function saveModelText(id) { 
            const data = findModeloAndIndexes(id); 
            if (!data) return; 
            data.modelo.texto = document.getElementById(`modelText-${id}`).value.trim(); 
            const item = document.querySelector(`.model-item[data-id="${id}"]`); 
            if (item) { item.classList.remove('editing'); item.classList.add('draggable'); } 
            saveAndRerender(true); 
        } 
        function handleTextareaKeydown(event, id) { if (event.ctrlKey && event.key === 'Enter') { event.preventDefault(); document.getElementById(`modelText-${id}`).blur(); } } 
        function handleInputKeydown(event) { if (event.key === 'Enter') { event.preventDefault(); event.target.blur(); } } 

        // Adicionar/Remover
        function addNewTitulo() { modelosData.unshift({ titulo: 'Novo Título', temas: [{ tema: 'Novo Tema', modelos: [] }] }); saveAndRerender(true); } 
        function deleteTitulo(tIndex) { 
            Swal.fire({
                title: 'Excluir Título?',
                text: "Esta ação não pode ser desfeita.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sim, excluir',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    modelosData.splice(tIndex, 1); 
                    saveAndRerender(true);
                    showCopyAlert('Título excluído!', 'success');
                }
            });
        } 
        function addNewTema(tIndex) { modelosData[tIndex].temas.push({ tema: 'Novo Tema', modelos: [] }); expandedState[`t-${tIndex}`] = true; saveAndRerender(true); } 
        function deleteTema(tIndex, temaIndex) { 
            Swal.fire({
                title: 'Excluir Tema?',
                text: "Esta ação não pode ser desfeita.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sim, excluir',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    modelosData[tIndex].temas.splice(temaIndex, 1); 
                    saveAndRerender(true); 
                    showCopyAlert('Tema excluído!', 'success');
                }
            });
        } 
        function addNewModelo(tIndex, temaIndex) { modelosData[tIndex].temas[temaIndex].modelos.push({ id: Date.now() + Math.random(), texto: 'Texto: {Campo}' }); expandedState[`tema-${tIndex}-${temaIndex}`] = true; saveAndRerender(true); } 
        function deleteModelo(id) { 
            const data = findModeloAndIndexes(id); 
            if(!data) return;
            Swal.fire({
                title: 'Excluir Modelo?',
                text: "Esta ação não pode ser desfeita.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sim, excluir',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    modelosData[data.tIndex].temas[data.temaIndex].modelos.splice(data.modeloIndex, 1); 
                    textosSelecionados.delete(Number(id)); 
                    saveAndRerender(true); 
                    showCopyAlert('Modelo excluído!', 'success');
                }
            });
        } 

        function toggleAccordion(header) { 
            const content = header.nextElementSibling; 
            header.classList.toggle('expanded'); 
            content.style.display = content.style.display === "block" ? "none" : "block"; 
            saveExpandedState(); 
        } 
        function toggleAll(action) { 
            document.querySelectorAll('.section-header, .tema-header').forEach(header => { 
                const content = header.nextElementSibling; 
                if (action === 'expand') { header.classList.add('expanded'); content.style.display = 'block'; } 
                else { header.classList.remove('expanded'); content.style.display = 'none'; } 
            }); 
            saveExpandedState(); 
            document.getElementById('toggleAllBtn').style.display = action === 'expand' ? 'none' : 'inline-block'; 
            document.getElementById('collapseAllBtn').style.display = action === 'expand' ? 'inline-block' : 'none'; 
        } 
        
        function filterSections() { 
            if (isManagementMode) { document.getElementById('searchInput').value = ''; return; } 
            const termo = document.getElementById('searchInput').value.toLowerCase().trim(); 
            const modelos = document.querySelectorAll('.model-item'); 
            if (termo.length < 2) { 
                modelos.forEach(m => { m.style.display = 'flex'; m.querySelector('.model-text-display').innerHTML = safeHTML(findModeloAndIndexes(m.dataset.id)?.modelo?.texto || ''); }); 
                document.querySelectorAll('.tema-item, .section').forEach(el => el.style.display = 'block'); 
                restoreExpandedState(); return; 
            } 
            const regex = new RegExp(escapeRegExp(termo), 'gi'); 
            modelos.forEach(modelItem => { 
                const data = findModeloAndIndexes(modelItem.dataset.id)?.modelo; 
                const texto = data ? data.texto : ''; 
                const match = `${modelItem.dataset.titulo} ${modelItem.dataset.tema} ${texto}`.toLowerCase().includes(termo); 
                modelItem.style.display = match ? 'flex' : 'none'; 
                if (match) modelItem.querySelector('.model-text-display').innerHTML = texto.replace(regex, m => `<mark>${m}</mark>`); 
            }); 
            document.querySelectorAll('.tema-item').forEach(tema => { 
                const visible = tema.querySelectorAll('.model-item[style*="display: flex"]').length > 0 || tema.textContent.toLowerCase().includes(termo); 
                tema.style.display = visible ? 'block' : 'none'; 
                if (visible) { tema.querySelector('.tema-content').style.display = 'block'; tema.querySelector('.tema-header').classList.remove('expanded'); } 
            }); 
            document.querySelectorAll('.section').forEach(sec => { 
                const visible = sec.querySelectorAll('.tema-item[style*="display: block"]').length > 0 || sec.textContent.toLowerCase().includes(termo); 
                sec.style.display = visible ? 'block' : 'none'; 
                if (visible) { sec.querySelector('.subitems').style.display = 'block'; sec.querySelector('.section-header').classList.remove('expanded'); } 
            }); 
        } 

        // Seleção e Geração
        function toggleModelSelection(id, isChecked) { 
            const idNumber = Number(id); 
            if (isChecked) textosSelecionados.add(idNumber); else textosSelecionados.delete(idNumber); 
            const data = findModeloAndIndexes(idNumber); 
            if (data) { 
                const cb = document.getElementById(`selectAllTema-${data.tIndex}-${data.temaIndex}`); 
                if (cb) cb.checked = modelosData[data.tIndex].temas[data.temaIndex].modelos.every(m => textosSelecionados.has(Number(m.id))); 
            } 
            detectarCamposDinamicos(); generateText(); 
        } 
        function toggleAllModelsInTema(tIndex, temaIndex, isChecked) { 
            modelosData[tIndex].temas[temaIndex].modelos.forEach(m => { 
                const id = Number(m.id); 
                const cb = document.getElementById(`model-${id}`); 
                if (isChecked) { textosSelecionados.add(id); if(cb) cb.checked = true; } 
                else { textosSelecionados.delete(id); if(cb) cb.checked = false; } 
            }); 
            detectarCamposDinamicos(); generateText(); 
        } 

        /* ======================================= */ 
        /* 3. CAMPOS DINÂMICOS */ 
        /* ======================================= */ 
function detectarCamposDinamicos() {
    const container = document.getElementById('dynamicInputsContainer');
    container.innerHTML = '';
    const camposRequeridos = new Set(["Número da Ocorrência"]);


    Array.from(document.querySelectorAll('.model-item input:checked'))
        .map(cb => findModeloAndIndexes(cb.id.replace('model-', ''))?.modelo)
        .filter(m => m)
        .forEach(m => { (m.texto.match(/\{([^}]+)\}/g) || []).forEach(match => camposRequeridos.add(match.slice(1, -1).trim())); });

    const sfId = document.getElementById('saudacaoFechamentoSelect')?.value;
    if (sfId) {
        const sf = saudacoesFechamentosData.find(s => s.id == sfId);
        if (sf) (`${sf.saudacao} ${sf.fechamento}`.match(/\{([^}]+)\}/g) || []).forEach(match => camposRequeridos.add(match.slice(1, -1).trim()));
    }

    // --- FUNÇÃO AUXILIAR PARA FORMATAR (Inserida aqui) ---
    const formatMoney = (v) => {
        if (!v) return '';
        v = v.toString().replace(/\D/g, '');
        if (v === '') return '';
        return (parseFloat(v) / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2 });
    };

    [...camposRequeridos].sort().forEach(campo => {
        const id = "campo_" + campo.replace(/\s+/g, '_');
        
        // --- LÓGICA DE DETECÇÃO (Inserida aqui) ---
        const isData = campo.replace(/\s+/g, '').toLowerCase() === 'dataatual';
        const isValor = /valor(es)?/i.test(campo);

        // Preenche Data Atual ou Recupera Valor Salvo
        let valor = camposDinamicos[campo] || (isData ? new Date().toLocaleDateString("pt-BR") : '');
        
        // Se for campo monetário e tiver valor, já formata na inicialização
        if (isValor && valor) valor = formatMoney(valor);

        const selectField = selectFieldsData.find(f => f.key.toUpperCase() === campo.toUpperCase());
        
        let html = '';
        if (selectField) {
            const selectedVals = valor ? valor.split(', ') : [];
            const optionsHtml = selectField.options.map(o => {
                const isChecked = selectedVals.includes(o) ? 'checked' : '';
                return `<li><label style="display:block; cursor:pointer;"><input type="checkbox" value="${o}" ${isChecked} onchange="updateDropdownValue('${id}', '${campo}')" style="width:auto; margin-right:8px;"> ${o}</label></li>`;
            }).join('');
            
            const displayVal = valor || 'Selecione...';

            html = `<div class="select-group" data-campo="${campo}">
                        <label>${campo}:</label>
                        <div id="dropdown_${id}" class="dropdown-check-list" tabindex="100">
                            <span class="anchor" onclick="toggleDropdown('${id}')">${displayVal}</span>
                            <ul class="items">
                                ${optionsHtml}
                            </ul>
                            <input type="hidden" id="${id}" value="${valor}" data-campo-original="${campo}">
                        </div>
                    </div>`; 
        } else { 
            const isFundo = ["fundo de destino", "fundo origem", "fundo incorreto", "fundo de investimento"].some(t => campo.toLowerCase().includes(t)); 
            const btn = campo === "Número da Ocorrência" ? `<span onclick="buscarEPreencherOcorrencia()" style="cursor:pointer;margin-left:5px;">🔍</span>` : 
                        (isFundo ? `<span onclick="abrirModalFundos('${id}', '${campo}')" style="cursor:pointer;margin-left:5px;">📋</span>` : ''); 
            
            html = `<div class="select-group" data-campo="${campo}"><label>${campo}:${btn}</label><input type="text" id="${id}" value="${valor}" data-campo-original="${campo}" oninput="updateCamposDinamicos('${campo}', '${id}')"/></div>`; 
        } 
        
        container.insertAdjacentHTML('beforeend', html); 
        
        // --- APLICAR MÁSCARA NO INPUT CRIADO (Inserida aqui) ---
        if (isValor && !selectField) {
            const el = document.getElementById(id);
            // Sobrescreve o oninput padrão para garantir a formatação antes de atualizar o estado
            el.oninput = function() {
                this.value = formatMoney(this.value);
                updateCamposDinamicos(campo, id);
            };
        }

        camposDinamicos[campo] = valor; 
    }); 
    generateText(); 

            
            // Fecha dropdowns ao clicar fora
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.dropdown-check-list')) {
                    document.querySelectorAll('.dropdown-check-list').forEach(d => d.classList.remove('visible'));
                }
            }, {once:true}); // Adiciona uma vez por renderização ou gerencia globalmente (melhor global)
        } 

        // Gerenciador global de cliques para fechar dropdowns (apenas 1 listener)
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown-check-list')) {
                document.querySelectorAll('.dropdown-check-list').forEach(d => d.classList.remove('visible'));
            }
        });

        function toggleDropdown(id) {
            const el = document.getElementById('dropdown_' + id);
            // Fecha outros
            document.querySelectorAll('.dropdown-check-list').forEach(d => {
                if(d !== el) d.classList.remove('visible');
            });
            el.classList.toggle('visible');
        }

        function updateDropdownValue(inputId, campo) {
            const container = document.getElementById('dropdown_' + inputId);
            const checkboxes = container.querySelectorAll('input[type="checkbox"]:checked');
            const vals = Array.from(checkboxes).map(c => c.value).join(', ');
            
            // Atualiza input hidden e display
            const input = document.getElementById(inputId);
            input.value = vals;
            container.querySelector('.anchor').textContent = vals || 'Selecione...';
            
            camposDinamicos[campo] = vals;
            generateText();
        }

        function updateCamposDinamicos(campo, id) { 
            const el = document.getElementById(id); 
            camposDinamicos[campo] = el.value; 
            generateText(); 
        } 
        
        function limparCamposDinamicosExcetoData() { 
            document.querySelectorAll('#dynamicInputsContainer input').forEach(input => { if (input.id !== 'dataAtual') input.value = ''; }); 
            Object.keys(camposDinamicos).forEach(key => { if (key !== 'dataAtual') camposDinamicos[key] = ''; }); 
            detectarCamposDinamicos(); 
        } 

        function generateText() { 
            const sfId = document.getElementById('saudacaoFechamentoSelect')?.value; 
            const sf = saudacoesFechamentosData.find(s => s.id == sfId); 
            
            const modelos = Array.from(document.querySelectorAll('.model-item input:checked')) 
                .map(cb => findModeloAndIndexes(cb.id.replace('model-', ''))?.modelo).filter(m => m); 

            const apply = (txt) => { 
                if (!txt) return ''; 
                let res = txt; 
                for (const k in camposDinamicos) res = res.replace(new RegExp(`\\{\\s*${escapeRegExp(k)}\\s*\\}`, 'g'), camposDinamicos[k] || '_______'); 
                return res; 
            }; 

            const parts = []; 
            if (sf?.saudacao) parts.push(apply(sf.saudacao)); 
            if (modelos.length) parts.push(modelos.map(m => apply(m.texto)).join('\n\n')); 
            if (sf?.fechamento) parts.push(apply(sf.fechamento)); 
            
            document.getElementById('resultText').value = parts.join('\n\n'); 
        } 


function clearAll() {
// Limpa seleção de textos
textosSelecionados.clear();

// Limpa checkboxes
document.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);

// Limpa textarea principal e notas
document.getElementById('resultText').value = '';
document.getElementById('notesdevolução').value = '';

// Limpa campos dinâmicos, exceto data
limparCamposDinamicosExcetoData();

// Reseta seleção de Tipo/Fechamento
const selectSF = document.getElementById('saudacaoFechamentoSelect');
if (selectSF && saudacoesFechamentosData.length > 0) {
    selectSF.value = saudacoesFechamentosData[0].id;
}

// Limpa campos de busca
const searchInput = document.getElementById('searchInput');
if (searchInput) searchInput.value = '';

// Fecha todas as abas abertas
for (const key in expandedState) {
    if (expandedState.hasOwnProperty(key)) {
        expandedState[key] = false;
    }
}

// Re-renderiza as seções para refletir todas as alterações
renderSections();

// Reseta filtro de busca e restaura estado das seções
filterSections();
}

document.getElementById('clearBtn').onclick = clearAll;


        // Anotações
        document.getElementById("clearNotesdevolução").onclick = () => document.getElementById("notesdevolução").value = ""; 
        document.getElementById("copyNotesdevolução").onclick = () => { 
            const el = document.getElementById("notesdevolução"); 
            const text = el.value.trim();
            if(!text) return showCopyAlert("⚠️ Nada para copiar.", 'warning'); 
            
            if(navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(() => showCopyAlert("✅ Anotações copiadas!")).catch(() => fallbackCopy(el));
            } else {
                fallbackCopy(el);
            }
        }; 

        // Selects Dinâmicos (Modal)
        function openSelectManagerModal() { 
            const list = document.getElementById('selectFieldsList'); list.innerHTML = ''; 
            selectFieldsData.forEach((field, i) => { 
                const div = document.createElement('div'); div.className = 'sf-item'; div.textContent = field.key; 
                div.onclick = () => { 
                    document.querySelectorAll('.sf-item').forEach(e => e.classList.remove('selected')); div.classList.add('selected'); 
                    document.getElementById('editingSelectOriginalKey').value = i; 
                    document.getElementById('selectKeyInput').value = field.key; 
                    document.getElementById('selectOptionsInput').value = field.options.join(', '); 
                }; 
                list.appendChild(div); 
            }); 
            document.getElementById('selectManagerModal').style.display = 'flex'; 
        } 
        function saveSelectField() { 
            const key = document.getElementById('selectKeyInput').value.trim().toUpperCase(); 
            const opts = document.getElementById('selectOptionsInput').value.split(',').map(s=>s.trim()).filter(s=>s); 
            const idx = document.getElementById('editingSelectOriginalKey').value; 
            if (!key || !opts.length) return showCopyAlert('❌ Preencha os campos.', 'warning'); 
            if (idx === '') selectFieldsData.push({ key, options: opts }); else selectFieldsData[idx] = { key, options: opts }; 
            saveLocalData(); openSelectManagerModal(); showCopyAlert('✅ Salvo!'); 
        } 
        function deleteSelectField() { 
            const idx = document.getElementById('editingSelectOriginalKey').value; 
            if (idx !== '') {
                Swal.fire({
                    title: 'Excluir Campo?',
                    text: "Esta ação não pode ser desfeita.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Sim, excluir',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if(result.isConfirmed) {
                        selectFieldsData.splice(idx, 1); 
                        saveLocalData(); 
                        openSelectManagerModal();
                        showCopyAlert('Campo excluído!', 'success');
                    }
                });
            }
        } 
        function clearSelectForm() { document.getElementById('editingSelectOriginalKey').value=''; document.getElementById('selectKeyInput').value=''; document.getElementById('selectOptionsInput').value=''; } 
        function closeSelectManagerModal() { document.getElementById('selectManagerModal').style.display='none'; } 

        /* ======================================= */ 
        /* 4. MODAL DE FUNDOS */ 
        /* ======================================= */ 
        function abrirModalFundos(inputId, campoNome) { 
            inputAlvoAtual = { id: inputId, campo: campoNome }; 
            const select = document.getElementById('modalFaixaSelect'); 
            select.innerHTML = '<option value="">-- Selecione --</option>'; 
            FUND_DATA.forEach((d, i) => { if (i>0) select.add(new Option(d.faixa, i)); }); 
            document.getElementById('listaFundosCheckbox').innerHTML = ''; 
            document.getElementById('fundosModal').style.display = 'block'; 
            select.onchange = (e) => { 
                if (!e.target.value) return; 
                const d = FUND_DATA[e.target.value]; 
                document.getElementById('codePGBL').textContent = d.pgbl; 
                document.getElementById('codeVGBL').textContent = d.vgbl; 
                document.getElementById('btnCopiarFaixa').style.display = 'inline-block'; 
                document.getElementById('listaFundosCheckbox').innerHTML = d.fundos.map(f => `<label style="padding:10px; display:block; border-bottom:1px solid #eee;"><input type="checkbox" value="${f}"> ${f}</label>`).join(''); 
            }; 
        } 
        function copiarFaixaSelecionada() { 
            const txt = document.getElementById('modalFaixaSelect').options[document.getElementById('modalFaixaSelect').selectedIndex].text; 
            if(navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(txt).then(() => showCopyAlert('✅ Faixa copiada!')).catch(() => showCopyAlert('Erro ao copiar', 'error'));
            } else {
                // Fallback simples se necessário, mas o original tinha alert simples
                // Vamos usar um textarea temporário
                const temp = document.createElement("textarea");
                temp.value = txt; document.body.appendChild(temp); temp.select(); document.execCommand("copy"); document.body.removeChild(temp);
                showCopyAlert('✅ Faixa copiada!');
            }
        } 
        function confirmarSelecaoFundos() { 
            const sels = Array.from(document.querySelectorAll('#listaFundosCheckbox input:checked')).map(c => c.value); 
            if (sels.length) { 
                document.getElementById(inputAlvoAtual.id).value = sels.join(', '); 
                updateCamposDinamicos(inputAlvoAtual.campo, inputAlvoAtual.id); 
            } 
            fecharModalFundos(); 
        } 
        function fecharModalFundos() { document.getElementById('fundosModal').style.display = 'none'; } 

        /* ======================================= */ 
        /* 5. GESTÃO SF (SAUDAÇÃO/FECHAMENTO) */ 
        /* ======================================= */ 
        function openSFManagementModal() { 
            const container = document.getElementById('sfListContainer'); container.innerHTML = ''; 
            saudacoesFechamentosData.forEach((sf, idx) => { 
                const div = document.createElement('div'); div.className = 'sf-item'; div.textContent = sf.nome; div.dataset.index = idx; div.draggable = true; 
                div.onclick = () => { 
                    selectedSFId = sf.id; document.getElementById('saudacaoFechamentoName').value = sf.nome; 
                    document.getElementById('saudacaoText').value = sf.saudacao; document.getElementById('fechamentoText').value = sf.fechamento; 
                }; 
                div.ondragstart = (e) => e.dataTransfer.setData('idx', idx); 
                div.ondragover = (e) => e.preventDefault(); 
                div.ondrop = (e) => { 
                    const from = e.dataTransfer.getData('idx'); const to = idx; 
                    const item = saudacoesFechamentosData.splice(from, 1)[0]; 
                    saudacoesFechamentosData.splice(to, 0, item); 
                    saveLocalData(); openSFManagementModal(); renderSaudacaoFechamentoSelect(); 
                }; 
                container.appendChild(div); 
            }); 
            document.getElementById('sfManagementModal').style.display = 'flex'; 
        } 


function addOrUpdateSaudacaoFechamento() {
    const nome = document.getElementById('saudacaoFechamentoName').value;
    
    if (!selectedSFId) {
        saudacoesFechamentosData.push({
            id: Date.now(),
            nome,
            saudacao: document.getElementById('saudacaoText').value,
            fechamento: document.getElementById('fechamentoText').value
        });
    } else {
        const sf = saudacoesFechamentosData.find(s => s.id == selectedSFId);
        if (sf) {
            sf.nome = nome;
            sf.saudacao = document.getElementById('saudacaoText').value;
            sf.fechamento = document.getElementById('fechamentoText').value;
        }
    }
    
    saveLocalData();
    openSFManagementModal();
    renderSaudacaoFechamentoSelect();
    
    // --- ALERTA DE CONFIRMAÇÃO ---
    showCopyAlert("Salvo com sucesso!");
}

        function deleteSelectedSF() { 
            if (selectedSFId) {
                Swal.fire({
                    title: 'Excluir Saudação/Fechamento?',
                    text: "Esta ação não pode ser desfeita.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Sim, excluir',
                    cancelButtonText: 'Cancelar'
                }).then((result) => {
                    if(result.isConfirmed) {
                        saudacoesFechamentosData = saudacoesFechamentosData.filter(s => s.id != selectedSFId); 
                        saveLocalData(); 
                        openSFManagementModal(); 
                        renderSaudacaoFechamentoSelect(); 
                        selectedSFId = null;
                        showCopyAlert('Item excluído!', 'success');
                    }
                });
            }
        } 
        function closeSFManagementModal() { document.getElementById('sfManagementModal').style.display = 'none'; } 

        /* ======================================= */ 
        /* 6. HISTÓRICO E EXPORTAÇÃO */ 
        /* ======================================= */ 
        function handleAction(categoria) {
            // Validação explícita antes de qualquer ação
            const ocorrencia = camposDinamicos["Número da Ocorrência"];
            if (!ocorrencia || ocorrencia.trim() === '') {
                Swal.fire({
                    icon: 'warning',
                    title: 'Atenção',
                    text: 'O campo Número da Ocorrência é obrigatório para registrar.'
                });
                return; // Interrompe tudo
            }

            // Se validou, tenta salvar
            saveToHistory(categoria)
                .then(success => {
                    if (success) {
                        copyText(); // Só copia se o processo de validação/salvamento passou
                    }
                });
        }

        function saveToHistory(categoria, confirmado = false) { 
            return new Promise((resolve) => {
                const sfId = document.getElementById('saudacaoFechamentoSelect').value; 
                const ocorrencia = camposDinamicos["Número da Ocorrência"]; 
                
                // Validação redundante por segurança
                if (!ocorrencia) { 
                    resolve(false); return; 
                }
                if (!sfId) {
                     showCopyAlert('⚠️ Selecione o Tipo de Fechamento.', 'warning');
                     resolve(false); return;
                }
                
                const sfNome = saudacoesFechamentosData.find(s => s.id == sfId)?.nome || 'N/A'; 
                const modelos = Array.from(document.querySelectorAll('.model-item input:checked')).map(cb => { 
                    const d = findModeloAndIndexes(cb.id.replace('model-', '')); 
                    return { id: cb.id.replace('model-', ''), titulo: d.titulo, tema: d.tema }; 
                }); 

                const uniqueTitulos = [...new Set(modelos.map(m => m.titulo))].join('; ');
                const uniqueTemas = [...new Set(modelos.map(m => m.tema))].join('; ');

                const registro = { 
                    id: ocorrencia, 
                    data: new Date().toISOString(), categoria, sfId, sfSelecionado: sfNome, 
                    modelosIds: modelos.map(m => m.id), 
                    titulos: uniqueTitulos, 
                    temas: uniqueTemas, 
                    confirmado: confirmado,
                    campos: { ...camposDinamicos, usuarioLogado: <?php echo json_encode($nome_completo); ?> } 
                }; 

                fetch('salvar_historico.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(registro) }) 
                    .then(r => r.json())
                    .then(d => { 
                        if(d.status === 'blocked') {
                            Swal.fire({ icon: 'error', title: 'Acesso Negado', text: d.message });
                            resolve(false);
                        } else if (d.status === 'needs_confirmation') {
                            Swal.fire({
                                title: 'Atualizar Registro?', text: d.message, icon: 'question', showCancelButton: true, confirmButtonText: 'Sim, atualizar!'
                            }).then((result) => { 
                                if (result.isConfirmed) {
                                    saveToHistory(categoria, true).then(resolve); 
                                } else {
                                    resolve(false);
                                }
                            });
                        } else if(d.status === 'success') { 
                            historicoData = historicoData.filter(reg => reg.id != ocorrencia); 
                            historicoData.push(registro); 
                            saveLocalData(); renderHistorico(); 
                            showCopyAlert('✅ Salvo no servidor!'); 
                            resolve(true);
                        } else {
                            // Erro genérico do servidor
                            resolve(true); // Assume salvo localmente abaixo, ou trata erro
                        }
                    }) 
                    .catch(() => {
                        // Fallback local
                        historicoData = historicoData.filter(reg => reg.id != ocorrencia);
                        historicoData.push(registro);
                        saveLocalData(); renderHistorico();
                        showCopyAlert('⚠️ Salvo localmente. Erro na rede.', 'warning');
                        resolve(true);
                    }); 
            });
        } 

        function getVisibleColumnsWithData(data) {
            return visibleColumns.filter(col => {
                if (col.id === 'select' || col.id === 'data') return true; // Keep 'data' and 'select' (for checkbox logic if implemented) but user asked to remove 'Seleção' column visual
                if (!col.visible) return false;
                return data.some(item => {
                    if (['categoria', 'titulos', 'temas', 'sfSelecionado'].includes(col.id)) return item[col.id] && item[col.id].trim() !== '';
                    else if (item.campos && item.campos[col.id]) return item.campos[col.id].trim() !== '';
                    return false;
                });
            });
        }

function renderHistorico() { 
    const tbody = document.querySelector('#historyTable tbody'); 
    const thead = document.querySelector('#historyTable thead tr'); 
    tbody.innerHTML = ''; thead.innerHTML = ''; 
    
    updateVisibleColumnsFromHistory(); 
    
    // Agrupamento para exibição
    // Nota: Mantemos o sort aqui para garantir que o registro base do agrupamento seja o mais recente
    const filteredData = applyFilters(historicoData).sort((a,b) => new Date(b.data) - new Date(a.data));
    
    const historicoPorId = {};
    filteredData.forEach(reg => {
        const id = reg.id;
        if (!historicoPorId[id]) historicoPorId[id] = { ...reg, titulos: [], temas: [] };
        
        // Deduplicate logic
        const tList = reg.titulos.split('; ').map(s=>s.trim());
        tList.forEach(t => { if(!historicoPorId[id].titulos.includes(t)) historicoPorId[id].titulos.push(t); });
        
        const tmList = reg.temas.split('; ').map(s=>s.trim());
        tmList.forEach(t => { if(!historicoPorId[id].temas.includes(t)) historicoPorId[id].temas.push(t); });
    });

    let dataAgrupada = Object.values(historicoPorId).map(reg => ({
        ...reg, 
        titulos: reg.titulos.join('; '), 
        temas: reg.temas.join('; ') 
    }));

    // --- CORREÇÃO AQUI ---
    // Ordenamos a lista final processada para garantir que o mais recente fique no topo
    dataAgrupada.sort((a, b) => new Date(b.data) - new Date(a.data));
    // ---------------------

    // Filter out 'select' column if present
    const cols = getVisibleColumnsWithData(dataAgrupada).filter(c => c.id !== 'select'); 
    cols.forEach(c => thead.innerHTML += `<th>${c.name}</th>`); 
    
    dataAgrupada.forEach(item => { 
        let tr = document.createElement('tr'); 
        cols.forEach(c => { 
            let val = ''; 
            if (c.id === 'data') val = new Date(item.data).toLocaleString('pt-BR'); 
            else if (['categoria','titulos','temas','sfSelecionado'].includes(c.id)) val = item[c.id] || ''; 
            else val = item.campos?.[c.id] || ''; 
            tr.innerHTML += `<td>${val}</td>`; 
        }); 
        tbody.appendChild(tr); 
    }); 
}
        function applyFilters(data) { 
            const start = document.getElementById('startDate').value; 
            const end = document.getElementById('endDate').value; 
            const searchInput = document.getElementById('globalSearch');
            const search = searchInput ? searchInput.value.toLowerCase().trim() : '';
            const filterMode = document.getElementById('filterMode').value;
            
            return data.filter(item => { 
                // Filtro de Data
                const d = new Date(item.data); 
                // Ajuste para garantir que a comparação de data considere o timezone local corretamente ou apenas a parte da data
                // Assumindo input YYYY-MM-DD
                const dataItemStr = item.data.split('T')[0]; // Pega YYYY-MM-DD do ISO
                
                const dateOk = (!start || dataItemStr >= start) && (!end || dataItemStr <= end); 
                
                if (!dateOk) return false;

                // Filtro de Texto Global
                if (!search) return true;

                // Achata o objeto em uma string única para busca
                // Inclui campos dinâmicos, títulos, temas, etc.
                const valuesToCheck = [
                    item.id,
                    item.categoria,
                    item.sfSelecionado,
                    item.titulos,
                    item.temas,
                    // Adiciona valores dos campos dinâmicos
                    ...Object.values(item.campos || {})
                ].filter(Boolean).map(v => String(v).toLowerCase());

                // Função auxiliar de comparação baseada no modo
                const checkMatch = (val) => {
                    switch (filterMode) {
                        case 'exact': return val === search;
                        case 'starts': return val.startsWith(search);
                        default: return val.includes(search); // 'includes'
                    }
                };

                return valuesToCheck.some(checkMatch);
            }); 
        } 
        
        function updateVisibleColumnsFromHistory() { 
            const dynamic = new Set(); 
            historicoData.forEach(h => Object.keys(h.campos||{}).forEach(k => dynamic.add(k))); 
            
            // Mescla colunas do sistema e dinâmicas
            const currentIds = visibleColumns.map(c => c.id); 
            systemColumns.forEach(c => { 
                if(!currentIds.includes(c.id) && c.id !== 'select') visibleColumns.push(c); 
            }); 
            dynamic.forEach(k => { if(!currentIds.includes(k) && !systemColumns.some(sc=>sc.id===k)) visibleColumns.push({ id: k, name: k, visible: true }); }); 
            
            // Remove 'select' if present
            visibleColumns = visibleColumns.filter(c => c.id !== 'select');
        } 

        function openColumnModal() { 
            const list = document.getElementById('columnList'); list.innerHTML = ''; 
            visibleColumns.forEach(c => { 
                const li = document.createElement('li'); 
                li.innerHTML = `<label><input type="checkbox" data-id="${c.id}" ${c.visible?'checked':''} ${c.id==='data'?'disabled':''}> ${c.name}</label>`; 
                list.appendChild(li); 
            }); 
            document.getElementById('columnModal').style.display = 'flex'; 
        } 
        function saveColumnSelection() { 
            document.querySelectorAll('#columnList input').forEach(cb => { 
                const col = visibleColumns.find(c => c.id === cb.dataset.id); 
                if(col) col.visible = cb.checked; 
            }); 
            saveLocalData(); renderHistorico(); closeColumnModal(); 
        } 
        function closeColumnModal() { document.getElementById('columnModal').style.display = 'none'; } 

function downloadBackupTXT() { 
            let txtContent = ""; 
            
            // Iterar sobre Títulos
            modelosData.forEach(t => { 
                // Iterar sobre Temas
                t.temas.forEach(tm => { 
                    txtContent += `[${t.titulo} - ${tm.tema}]\n`; 
                    
                    // Iterar sobre Modelos (Textos)
                    tm.modelos.forEach((m, i) => { 
                        txtContent += m.texto.trim(); 
                        
                        // Se houver mais modelos neste tema, usa separador simples
                        if(i < tm.modelos.length - 1) {
                            txtContent += "\n--\n"; 
                        } else {
                            txtContent += "\n"; // Fim do último texto
                        }
                    }); 
                    
                    // Fim do Tema (Separador de Tema)
                    txtContent += "---\n\n"; 
                }); 
            }); 
            
            // Seção de Saudações
            txtContent += "[SAUDACOES_FECHAMENTOS]\n\n"; 
            saudacoesFechamentosData.forEach(i => { 
                txtContent += `${i.nome.trim()}\n|SAUDACAO| ${i.saudacao.replace(/\n/g,"\\n")}\n|FECHAMENTO| ${i.fechamento.replace(/\n/g,"\\n")}\n\n`; 
            }); 
            txtContent += "---\n"; // Fim da seção
            
            // Seção de Selects
            txtContent += "\n[SELECTS_DINAMICOS]\n\n"; 
            selectFieldsData.forEach(i => { 
                txtContent += `${i.key}\n|OPCOES| ${i.options.join(', ')}\n\n`; 
            }); 
            txtContent += "---\n"; // Fim da seção
            
            const a = document.createElement("a"); 
            a.href = URL.createObjectURL(new Blob([txtContent], { type: "text/plain" })); 
            a.download = "backup_completo.txt"; 
            a.click(); 
        }

        function copyText() { 
            const el = document.getElementById('resultText'); 
            if(navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(el.value).then(() => showCopyAlert('✅ Texto copiado!')).catch(() => fallbackCopy(el));
            } else { fallbackCopy(el); }
        } 
        
        function fallbackCopy(el) {
            el.select(); document.execCommand('copy'); showCopyAlert('✅ Texto copiado!');
        }

        function showCopyAlert(msg, icon = 'success') { 
            Swal.fire({ toast: true, position: 'top-end', icon: icon, title: msg, showConfirmButton: false, timer: 3000 }); 
        } 
        
        // Redefine Alert do Navegador
        window.alert = function(msg) { Swal.fire(msg); }; 

        // ======================================= 
        // 7. FUNÇÕES QUE FALTAVAM (Adicionadas Agora) 
        // ======================================= 

        // --- Importação Rápida --- 
        function openQuickImportModal() { 
            const modal = document.getElementById('quickImportModal'); 
            const selectTitulo = document.getElementById('importTargetTitulo'); 
            
            document.getElementById('importTextarea').value = ''; 
            document.getElementById('importDelimiter').value = ''; 
            
            selectTitulo.innerHTML = '<option value="">(Selecione ou crie novo)</option>'; 
            modelosData.forEach((t, tIndex) => { 
                const option = document.createElement('option'); 
                option.value = tIndex; 
                option.textContent = t.titulo; 
                selectTitulo.appendChild(option); 
            }); 
            
            updateImportTemaSelect(); 
            modal.style.display = 'flex'; 
        } 

        function closeQuickImportModal() { 
            document.getElementById('quickImportModal').style.display = 'none'; 
        } 

        function updateImportTemaSelect() { 
            const selectTitulo = document.getElementById('importTargetTitulo'); 
            const selectTema = document.getElementById('importTargetTema'); 
            
            selectTema.innerHTML = '<option value="">(Selecione ou crie novo)</option>'; 
            document.getElementById('importTextarea').value = ''; 
            
            if (selectTitulo.value !== "") { 
                const tIndex = parseInt(selectTitulo.value); 
                const tituloData = modelosData[tIndex]; 
                if (tituloData && tituloData.temas) { 
                    tituloData.temas.forEach((tema, temaIndex) => { 
                        const option = document.createElement('option'); 
                        option.value = `${tIndex}:${temaIndex}`; 
                        option.textContent = tema.tema; 
                        selectTema.appendChild(option); 
                    }); 
                } 
            } 
        } 

        function updateTextareaOnTemaChange() { 
            const selectTema = document.getElementById('importTargetTema'); 
            const textarea = document.getElementById('importTextarea'); 
            
            if (!selectTema.value) { 
                textarea.value = ''; 
                return; 
            } 
            
            const [tIndex, temaIndex] = selectTema.value.split(':').map(Number); 
            const modelos = modelosData[tIndex].temas[temaIndex].modelos; 
            textarea.value = modelos.map(m => m.texto).join('\n--\n'); 
        } 

        function deleteTituloModal() { 
            const tIndex = document.getElementById('importTargetTitulo').value; 
            if (tIndex === "") return showCopyAlert("Selecione um título.", 'warning'); 
            
            Swal.fire({
                title: 'Excluir Título e Conteúdo?',
                text: "Esta ação não pode ser desfeita.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sim, excluir',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if(result.isConfirmed) {
                    modelosData.splice(tIndex, 1); 
                    saveAndRerender(isManagementMode); 
                    openQuickImportModal(); 
                    showCopyAlert('Excluído com sucesso!', 'success');
                }
            });
        } 

        function deleteTemaModal() { 
            const val = document.getElementById('importTargetTema').value; 
            if (!val) return showCopyAlert("Selecione um tema.", 'warning'); 
            
            Swal.fire({
                title: 'Excluir Tema e Modelos?',
                text: "Esta ação não pode ser desfeita.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sim, excluir',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if(result.isConfirmed) {
                    const [tIndex, temaIndex] = val.split(':').map(Number); 
                    modelosData[tIndex].temas.splice(temaIndex, 1); 
                    saveAndRerender(isManagementMode); 
                    updateImportTemaSelect(); 
                    showCopyAlert('Excluído com sucesso!', 'success');
                }
            });
        } 

        function processQuickImport() { 
            const selectTitulo = document.getElementById('importTargetTitulo'); 
            const selectTema = document.getElementById('importTargetTema'); 
            const createField = document.getElementById('importDelimiter').value.trim(); 
            const textarea = document.getElementById('importTextarea').value; 
            
            if (!textarea) return showCopyAlert("Cole algum texto.", 'warning'); 
            
            let tIndex = null; 
            let temaIndex = null; 
            
            if (createField.includes('-')) { 
                const [titulo, tema] = createField.split('-').map(x => x.trim()); 
                if (!titulo || !tema) return showCopyAlert("Use: Título - Tema", 'warning'); 
                modelosData.push({ titulo, temas: [{ tema, modelos: [] }] }); 
                tIndex = modelosData.length - 1; 
                temaIndex = 0; 
            } else if (createField && selectTitulo.value !== "") { 
                tIndex = parseInt(selectTitulo.value); 
                modelosData[tIndex].temas.push({ tema: createField, modelos: [] }); 
                temaIndex = modelosData[tIndex].temas.length - 1; 
            } else { 
                if (!selectTitulo.value || !selectTema.value) return showCopyAlert("Selecione ou crie novos.", 'warning'); 
                const [t, tm] = selectTema.value.split(':'); 
                tIndex = parseInt(t); 
                temaIndex = parseInt(tm); 
            } 
            
            const modelos = textarea.replace(/\r/g, "").split("\n--\n").map(txt => ({ id: Date.now() + Math.random(), texto: txt.trim() })).filter(m => m.texto); 
            
            if (modelos.length === 0) return showCopyAlert("Nenhum texto válido.", 'warning'); 
            
            modelosData[tIndex].temas[temaIndex].modelos = modelos; 
            expandedState[`t-${tIndex}`] = true; 
            
            saveAndRerender(isManagementMode); 
            closeQuickImportModal(); 
            showCopyAlert(`✔ ${modelos.length} modelos salvos.`); 
        } 

        // --- Restaurar TXT Local --- 
        function openRestoreTXTModal() { 
            document.getElementById("fileInputTXT").value = ""; 
            document.getElementById("restoreTXTModal").style.display = "flex"; 
        } 

        function closeRestoreTXTModal() { 
            document.getElementById("restoreTXTModal").style.display = "none"; 
        } 









function downloadBackupTXT() {
    let txtContent = "";

    // --- SEÇÃO 1: MODELOS ---
    modelosData.forEach(t => {
        t.temas.forEach(tm => {
            // Cabeçalho do Tema
            txtContent += `[${t.titulo} - ${tm.tema}]\n`;
            
            // Textos do Tema
            tm.modelos.forEach((m, i) => {
                txtContent += m.texto.trim(); // Remove espaços extras nas pontas
                
                // Se não for o último texto, usa separador simples (--)
                // Se for o último, pulará linha para o fechamento
                if (i < tm.modelos.length - 1) {
                    txtContent += "\n--\n";
                } else {
                    txtContent += "\n";
                }
            });
            
            // Fim do Tema (---)
            txtContent += "---\n\n";
        });
    });

    // --- SEÇÃO 2: SAUDAÇÕES ---
    txtContent += "[SAUDACOES_FECHAMENTOS]\n\n";
    saudacoesFechamentosData.forEach(i => {
        txtContent += `${i.nome.trim()}\n|SAUDACAO| ${i.saudacao.replace(/\n/g, "\\n")}\n|FECHAMENTO| ${i.fechamento.replace(/\n/g, "\\n")}\n\n`;
    });
    txtContent += "---\n";

    // --- SEÇÃO 3: SELECTS ---
    txtContent += "\n[SELECTS_DINAMICOS]\n\n";
    selectFieldsData.forEach(i => {
        txtContent += `${i.key}\n|OPCOES| ${i.options.join(', ')}\n\n`;
    });
    txtContent += "---\n";

    // Download do arquivo
    const a = document.createElement("a");
    a.href = URL.createObjectURL(new Blob([txtContent], { type: "text/plain" }));
    a.download = "backup_completo.txt";
    a.click();
}


function handleRestoreTXT() {
    const fileInput = document.getElementById("fileInputTXT");
    const file = fileInput.files[0];
    
    if (!file) return showCopyAlert('Selecione um arquivo.', 'warning');

    Swal.fire({
        title: 'Restaurar de Arquivo?',
        text: "Isso APAGARÁ os dados atuais e substituirá pelos do arquivo. Continuar?",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sim, restaurar',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            const reader = new FileReader();
            reader.onload = function(e) {
                try {
                    restoreFromTXT(e.target.result);
                    saveAndRerender(); // Salva no localStorage e Atualiza a tela
                    closeRestoreTXTModal();
                    fileInput.value = ""; // Limpa o input
                    showCopyAlert('✅ Restaurado com sucesso!');
                } catch (err) {
                    console.error(err);
                    showCopyAlert('Erro ao processar o arquivo.', 'error');
                }
            };
            reader.readAsText(file);
        }
    });
}

function restoreFromTXT(text) {
    // Normaliza quebras de linha e cria array
    const linhas = text.replace(/\r/g, '').split("\n");
    
    let novosModelos = [];
    let novasSF = [];
    let novosSelects = [];
    
    let section = "MODELS"; // Seção inicial padrão
    
    // Variáveis temporárias para construção dos objetos
    let tituloAtual = "";
    let temaAtual = "";
    let modelosDoTema = []; // Lista de textos processados do tema atual
    let bufferTexto = [];   // Linhas do texto sendo lido agora
    
    let sfTemp = null;
    let selTemp = null;

    // --- Função auxiliar para salvar o que está na memória ---
    const salvarContextoAtual = () => {
        // 1. Se tem texto no buffer, joga para a lista de modelos do tema
        if (bufferTexto.length > 0) {
            modelosDoTema.push(bufferTexto.join("\n").trim());
            bufferTexto = [];
        }

        // 2. Se temos um Título e Tema definidos e modelos capturados
        if (tituloAtual && temaAtual && modelosDoTema.length > 0) {
            // Procura se o título já existe
            let tObj = novosModelos.find(x => x.titulo === tituloAtual);
            if (!tObj) {
                tObj = { titulo: tituloAtual, temas: [] };
                novosModelos.push(tObj);
            }
            
            // Adiciona o tema e seus modelos
            tObj.temas.push({
                tema: temaAtual,
                modelos: modelosDoTema.map(txt => ({
                    id: Date.now() + Math.random(), // Gera ID único
                    texto: txt
                }))
            });
        }
        
        // Limpa lista de modelos, mas mantem titulo/tema caso venha mais texto (embora o fluxo normal limpe depois)
        modelosDoTema = []; 
    };

    // --- Loop linha a linha ---
    for (let i = 0; i < linhas.length; i++) {
        let line = linhas[i].trimEnd(); // Remove espaços à direita, preserva indentação à esquerda

        // Detector de Seções
        if (line === "[SAUDACOES_FECHAMENTOS]") {
            salvarContextoAtual(); // Salva o que estava pendente nos modelos
            section = "SF";
            continue;
        }
        if (line === "[SELECTS_DINAMICOS]") {
            section = "SELECTS";
            continue;
        }

        // === PROCESSANDO MODELOS ===
        if (section === "MODELS") {
            
            // FIM DE TEMA (---)
            if (line === "---") {
                salvarContextoAtual();
                tituloAtual = ""; 
                temaAtual = "";
                continue;
            }

            // SEPARADOR DE TEXTO (--)
            if (line === "--") {
                if (bufferTexto.length > 0) {
                    modelosDoTema.push(bufferTexto.join("\n").trim());
                    bufferTexto = [];
                }
                continue;
            }

            // CABEÇALHO [Titulo - Tema]
            if (line.startsWith("[") && line.endsWith("]") && line.includes(" - ")) {
                salvarContextoAtual(); // Garante salvamento anterior
                const content = line.slice(1, -1); // Remove [ ]
                const parts = content.split(" - ");
                tituloAtual = parts[0].trim();
                temaAtual = parts.slice(1).join(" - ").trim(); // Join caso o tema tenha hifens
                continue;
            }

            // CONTEÚDO (Só adiciona se estiver dentro de um contexto válido)
            if (tituloAtual && temaAtual) {
                bufferTexto.push(line);
            }
        }
        
        // === PROCESSANDO SAUDAÇÕES ===
        else if (section === "SF") {
            if (line === "---" || line === "") continue;

            if (!line.startsWith("|")) {
                if (sfTemp) { sfTemp.id = Date.now() + Math.random(); novasSF.push(sfTemp); }
                sfTemp = { nome: line, saudacao: "", fechamento: "" };
            } 
            else if (line.startsWith("|SAUDACAO|")) {
                sfTemp.saudacao = line.replace("|SAUDACAO|", "").trim().replace(/\\n/g, "\n");
            } 
            else if (line.startsWith("|FECHAMENTO|")) {
                sfTemp.fechamento = line.replace("|FECHAMENTO|", "").trim().replace(/\\n/g, "\n");
            }
        }

        // === PROCESSANDO SELECTS ===
        else if (section === "SELECTS") {
            if (line === "---" || line === "") continue;

            if (!line.startsWith("|")) {
                if (selTemp) novosSelects.push(selTemp);
                selTemp = { key: line, options: [] };
            } 
            else if (line.startsWith("|OPCOES|")) {
                selTemp.options = line.replace("|OPCOES|", "").split(',').map(s => s.trim());
            }
        }
    }

    // Salva pendências finais (último item do arquivo)
    if (section === "MODELS") salvarContextoAtual();
    if (sfTemp) { sfTemp.id = Date.now() + Math.random(); novasSF.push(sfTemp); }
    if (selTemp) novosSelects.push(selTemp);

    // Atualiza variáveis globais apenas se encontrou dados
    if (novosModelos.length > 0) modelosData = combinarModelosPorTitulo(novosModelos);
    if (novasSF.length > 0) saudacoesFechamentosData = novasSF;
    if (novosSelects.length > 0) selectFieldsData = novosSelects;
}

// Função auxiliar necessária para agrupar titulos repetidos (caso existam no txt)
function combinarModelosPorTitulo(lista) {
    const mapa = {};
    lista.forEach(i => {
        if (!mapa[i.titulo]) mapa[i.titulo] = { titulo: i.titulo, temas: [] };
        
        i.temas.forEach(nt => {
            let ext = mapa[i.titulo].temas.find(t => t.tema === nt.tema);
            if (!ext) {
                ext = { tema: nt.tema, modelos: [] };
                mapa[i.titulo].temas.push(ext);
            }
            // Evita duplicatas exatas de texto no mesmo tema
            nt.modelos.forEach(nm => {
                if (!ext.modelos.some(m => m.texto.trim() === nm.texto.trim())) {
                    ext.modelos.push(nm);
                }
            });
        });
    });
    return Object.values(mapa);
}


function saveToServer() {
    Swal.fire({
        title: 'Atualizar Backup',
        text: "Digite a chave de segurança para sobrescrever o arquivo no servidor:",
        input: 'password', // Tipo password para ocultar os caracteres
        inputAttributes: {
            autocapitalize: 'off',
            autocorrect: 'off'
        },
        showCancelButton: true,
        confirmButtonText: 'Confirmar Atualização',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#dc3545',
        showLoaderOnConfirm: true,
        preConfirm: (chave) => {
            if (!chave) {
                Swal.showValidationMessage('Você precisa digitar a chave!');
                return false;
            }
            
            // 1. Gera o conteúdo TXT (Sua lógica existente)
            let txtContent = "";
            modelosData.forEach(tituloObj => {
                tituloObj.temas.forEach(temaObj => {
                    txtContent += `[${tituloObj.titulo} - ${temaObj.tema}]\n`;
                    temaObj.modelos.forEach((m, idx) => {
                        txtContent += m.texto.trim() + "\n";
                        if (idx < temaObj.modelos.length - 1) txtContent += "--\n";
                    });
                    txtContent += "---\n\n";
                });
            });

            txtContent += "[SAUDACOES_FECHAMENTOS]\n\n";
            saudacoesFechamentosData.forEach(item => {
                txtContent += `${item.nome.trim()}\n`;
                txtContent += `|SAUDACAO| ${item.saudacao.replace(/\n/g, "\\n").trim()}\n`;
                txtContent += `|FECHAMENTO| ${item.fechamento.replace(/\n/g, "\\n").trim()}\n\n`;
            });
            txtContent += "---\n\n";

            txtContent += "[SELECTS_DINAMICOS]\n\n";
            if (typeof selectFieldsData !== 'undefined' && selectFieldsData.length > 0) {
                selectFieldsData.forEach(item => {
                    txtContent += `${item.key.trim()}\n`;
                    txtContent += `|OPCOES| ${item.options.join(', ').trim()}\n\n`;
                });
            }
            txtContent += "---\n";

            // 2. Envia para o servidor
            return fetch('servidor_modelo_texto.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    key: chave,
                    content: txtContent
                })
            })
            .then(async response => {
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || "Erro ao salvar");
                return result;
            })
            .catch(error => {
                Swal.showValidationMessage(`Falha: ${error.message}`);
            });
        },
        allowOutsideClick: () => !Swal.isLoading()
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                icon: 'success',
                title: 'Sucesso!',
                text: '🚀 O modelo do servidor foi atualizado com êxito.',
                timer: 2000,
                showConfirmButton: false
            });
        }
    });
}


function restoreFromServer() {
    Swal.fire({
        title: 'Você tem certeza?',
        text: "ATENÇÃO: A restauração irá apagar TODOS os seus modelos de texto e pares Tipo/Fechamento atuais e substituí-los pelo conteúdo do servidor!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'Sim, restaurar tudo!',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            // Exibe um alerta de carregamento enquanto o fetch acontece
            Swal.fire({
                title: 'Restaurando...',
                text: 'Buscando dados no servidor',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });

            fetch('servidor_modelo_texto.php')
                .then(response => response.json())
                .then(result => {
                    if (result.error) throw new Error(result.error);
                    
                    // Suas funções originais (mantidas conforme solicitado)
                    restoreFromTXT(result.data);
                    saveAndRerender();
                    
                    // Alerta de sucesso bonito
                    Swal.fire({
                        icon: 'success',
                        title: 'Restaurado!',
                        text: 'Restauração via servidor concluída com sucesso.',
                        timer: 2500,
                        showConfirmButton: false
                    });
                    
                    closeRestoreTXTModal();
                })
                .catch(err => {
                    // Alerta de erro bonito
                    Swal.fire({
                        icon: 'error',
                        title: 'Erro na Restauração',
                        text: err.message
                    });
                    console.error(err);
                });
        } else {
            // Se o usuário cancelar, apenas fecha o modal original
            closeRestoreTXTModal();
        }
    });
}






        // --- Extras --- 
        function buscarEPreencherOcorrencia() { 
            const id = camposDinamicos["Número da Ocorrência"]; 
            if(!id) return showCopyAlert("Digite o número primeiro.", 'warning'); 
            const reg = historicoData.find(h => h.campos["Número da Ocorrência"] == id || h.id == id); 
            if(reg) { 
                camposDinamicos = {...reg.campos}; 
                detectarCamposDinamicos(); 
                showCopyAlert("Dados carregados!"); 
            } else { 
                showCopyAlert("Não encontrado.", 'error'); 
            } 
        } 

        function downloadHistory() { 
            let txt = visibleColumns.map(c=>c.name).join('\t') + '\n'; 
            applyFilters(historicoData).forEach(h => { 
                txt += visibleColumns.map(c => { 
                    if(c.id==='data') return new Date(h.data).toLocaleString(); 
                    if(h.campos && h.campos[c.id]) return h.campos[c.id]; 
                    return h[c.id] || ''; 
                }).join('\t') + '\n'; 
            }); 
            const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([txt])); a.download = "historico.txt"; a.click(); 
        } 

        function downloadHistoryAsExcel() { 
            const filteredData = applyFilters(historicoData);
            if (filteredData.length === 0) return showCopyAlert("Não há dados.", 'warning');

            const historicoPorId = {};
            filteredData.forEach(reg => {
                const id = reg.id;
                if (!historicoPorId[id]) historicoPorId[id] = { ...reg, titulos: [], temas: [] };
                if (reg.titulos && !historicoPorId[id].titulos.includes(reg.titulos)) historicoPorId[id].titulos.push(reg.titulos);
                if (reg.temas && !historicoPorId[id].temas.includes(reg.temas)) historicoPorId[id].temas.push(reg.temas);
            });
            const dataAgrupada = Object.values(historicoPorId).map(reg => ({
                ...reg, 
                titulos: Array.isArray(reg.titulos) ? reg.titulos.join('; ') : reg.titulos, 
                temas: Array.isArray(reg.temas) ? reg.temas.join('; ') : reg.temas 
            })).sort((a,b)=> new Date(b.data)-new Date(a.data));

            const visCols = getVisibleColumnsWithData(dataAgrupada).filter(col => col.id !== 'select');
            
            const wsData = [];
            wsData.push(visCols.map(col => col.name));

            dataAgrupada.forEach(item => {
                const row = visCols.map(col => {
                    if (col.id === 'data') return new Date(item.data).toLocaleString('pt-BR');
                    if (['categoria', 'titulos', 'temas', 'sfSelecionado'].includes(col.id)) return item[col.id] || '';
                    if (item.campos && item.campos[col.id]) return item.campos[col.id];
                    return '';
                });
                wsData.push(row);
            });

            if (typeof XLSX === 'undefined') return showCopyAlert("Biblioteca XLSX não encontrada.", 'error'); 

            const ws = XLSX.utils.aoa_to_sheet(wsData);
            const wb = XLSX.utils.book_new();
            XLSX.utils.book_append_sheet(wb, ws, "Historico");
            XLSX.writeFile(wb, "historico.xlsx");
        }
        
        function visualizarHistory() { 
            const filteredData = applyFilters(historicoData);
            if (filteredData.length === 0) return showCopyAlert("Não há dados.", 'warning');

            const historicoPorId = {};
            filteredData.forEach(reg => {
                const id = reg.id;
                if (!historicoPorId[id]) historicoPorId[id] = { ...reg, titulos: [], temas: [] };
                if (reg.titulos && !historicoPorId[id].titulos.includes(reg.titulos)) historicoPorId[id].titulos.push(reg.titulos);
                if (reg.temas && !historicoPorId[id].temas.includes(reg.temas)) historicoPorId[id].temas.push(reg.temas);
            });
            const dataAgrupada = Object.values(historicoPorId).map(reg => ({
                ...reg, 
                titulos: Array.isArray(reg.titulos) ? reg.titulos.join('; ') : reg.titulos, 
                temas: Array.isArray(reg.temas) ? reg.temas.join('; ') : reg.temas 
            })).sort((a,b)=> new Date(b.data)-new Date(a.data));

            const visCols = getVisibleColumnsWithData(dataAgrupada).filter(col => col.id !== 'select');
            
            let content = visCols.map(col => col.name).join('\t') + '\n';
            dataAgrupada.forEach(item => {
                const row = visCols.map(col => {
                    if (col.id === 'data') return new Date(item.data).toLocaleString('pt-BR');
                    if (['categoria', 'titulos', 'temas', 'sfSelecionado'].includes(col.id)) return item[col.id] || '';
                    if (item.campos && item.campos[col.id]) return item.campos[col.id].replace(/\s+/g, ' ').trim();
                    return '';
                }).join('\t');
                content += row + '\n';
            });

            const win = window.open("", "_blank");
            win.document.write(`<pre>${content}</pre>`);
            win.document.close();
        } 

        // Carregar histórico
        async function carregarHistorico() { 
            try { 
                const response = await fetch('Leitura_do_historico_atendimento.php'); 
                if (!response.ok) throw new Error('Erro na rede');
                historicoData = await response.json(); 
            } catch (e) { 
                console.error('Erro ao carregar histórico:', e); 
                historicoData = []; // fallback 
            } 
        }

        // Inicialização
        window.onload = async function() { 
            try { 
                await carregarHistorico(); 
                
                // Define data padrão para hoje
                const today = new Date();
                const localDate = today.toLocaleDateString('pt-BR').split('/').reverse().join('-'); // Formato YYYY-MM-DD
                document.getElementById('startDate').value = localDate;
                document.getElementById('endDate').value = localDate;

                renderSections(); 
                renderSaudacaoFechamentoSelect(); 
                renderHistorico(); 
                toggleAll('collapse'); 
                
                setupScrollButtons(); // Inicializa botões de scroll
            } catch (e) { console.error(e); showCopyAlert("Erro ao iniciar.", 'error'); } 
        }; 

        // Funções de Filtro e Busca
        function filterByDate() {
            renderHistorico();
        }

        function resetFilter() {
            const today = new Date();
            const localDate = today.toLocaleDateString('pt-BR').split('/').reverse().join('-');
            document.getElementById('startDate').value = localDate;
            document.getElementById('endDate').value = localDate;
            document.getElementById('globalSearch').value = '';
            document.getElementById('filterMode').value = 'includes';
            renderHistorico();
        }

        function globalFilter() {
            renderHistorico();
        }

        // Lógica dos Botões de Scroll
        function setupScrollButtons() {
            const container = document.getElementById('scrollContainer');
            const btnLeft = document.getElementById('btnLeft');
            const btnRight = document.getElementById('btnRight');

            if(!container || !btnLeft || !btnRight) return;

            // Função para atualizar estado dos botões
            const updateButtons = () => {
                const tolerance = 2; // Margem de erro para browsers com zoom
                const maxScrollLeft = container.scrollWidth - container.clientWidth;
                
                // Se não há o que rolar, desabilita ambos
                if (maxScrollLeft <= 0) {
                    btnLeft.disabled = true;
                    btnLeft.style.opacity = 0.5;
                    btnRight.disabled = true;
                    btnRight.style.opacity = 0.5;
                    return;
                }

                // Esquerda
                if (container.scrollLeft <= tolerance) {
                    btnLeft.disabled = true;
                    btnLeft.style.opacity = 0.5;
                } else {
                    btnLeft.disabled = false;
                    btnLeft.style.opacity = 1;
                }

                // Direita
                if (container.scrollLeft >= maxScrollLeft - tolerance) {
                    btnRight.disabled = true;
                    btnRight.style.opacity = 0.5;
                } else {
                    btnRight.disabled = false;
                    btnRight.style.opacity = 1;
                }
            };

            // Event Listeners para Clique
            btnLeft.onclick = () => {
                container.scrollBy({ left: -200, behavior: 'smooth' });
            };

            btnRight.onclick = () => {
                container.scrollBy({ left: 200, behavior: 'smooth' });
            };

            // Listener de scroll para atualizar botões em tempo real
            container.addEventListener('scroll', updateButtons);
            
            // Listener de resize da janela
            window.addEventListener('resize', updateButtons);

            // Chamada inicial
            updateButtons();
            
            // Re-checar após renderização da tabela (caso dados mudem)
            const observer = new MutationObserver(updateButtons);
            observer.observe(document.querySelector('#historyTable'), { childList: true, subtree: true });
        }
    </script> 
</body> 
</html>