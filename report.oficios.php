<?php
// 1) Carrega o core do GLPI (Session, Html, DB, etc)
include('../inc/includes.php');

// 2) Verifica permissão de acesso a relatórios
Session::checkRight('reports', READ);

// Se for download do Excel, precisa ser antes de qualquer output
if (isset($_POST['download'])) {
    global $DB;

    $begin = $_POST['date1'] . ' 00:00:00';
    $end   = $_POST['date2'] . ' 23:59:59';

    // Consulta base
    $sql = "SELECT t.id, t.date, t.name, t.status, t.content
            FROM `glpi_tickets` t
            WHERE t.date BETWEEN '{$begin}' AND '{$end}'
            AND t.is_deleted = 0
            AND t.itilcategories_id IN (55, 56)";
    
    // Adiciona busca em todos os campos se fornecido
    if (!empty($_POST['search_term'])) {
        $search = $DB->escape($_POST['search_term']);
        $sql .= " AND (
            t.id LIKE '%{$search}%'
            OR t.name LIKE '%{$search}%'
            OR t.content LIKE '%{$search}%'
            OR t.date LIKE '%{$search}%'
        )";
    }
    
    $sql .= " ORDER BY t.date DESC";

    $result = $DB->query($sql);
    $tickets = [];
    while ($row = $result->fetch_assoc()) {
        $tickets[] = $row;
    }

    // Configuração do cabeçalho para CSV
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="relatorio_financeiro.csv"');
    header("Cache-Control: no-store, no-cache");
    
    // BOM UTF-8 para Excel reconhecer caracteres especiais
    echo chr(239) . chr(187) . chr(191);
    
    // Cabeçalhos das colunas
    $headers = [
        'ID', 'DATA', 'TÍTULO', 'STATUS', 'CONTRATO', 'SERVIÇO', 
        'FAVORECIDO', 'CPF/CNPJ', 'BANCO', 'AGÊNCIA', 'CONTA', 'OPERAÇÃO',
        'PIX', 'VALOR', 'DESCRIÇÃO'
    ];

    // Função para formatar células do CSV
    $formatCell = function($value) {
        // Remove quebras de linha e tabs
        $value = str_replace(["\r", "\n", "\t"], ' ', $value);
        // Limpa espaços múltiplos
        $value = preg_replace('/\s+/', ' ', $value);
        // Garante que valores com ponto-e-vírgula ou aspas sejam tratados corretamente
        if (strpos($value, ';') !== false || strpos($value, '"') !== false) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    };

    // Escreve os cabeçalhos
    echo implode(';', array_map($formatCell, $headers)) . "\r\n";

    // Escreve os dados
    foreach ($tickets as $ticket) {
        $data = extractDataFromContent($ticket['content']);
        
        $row = [
            $ticket['id'],
            date('d/m/Y H:i', strtotime($ticket['date'])),
            $ticket['name'],
            Ticket::getStatus($ticket['status']),
            $data['CONTRATO'],
            $data['SERVIÇOS'],
            $data['FAVORECIDO'],
            $data['CPF/CNPJ'],
            $data['BANCO'],
            $data['AGÊNCIA'],
            $data['CONTA'],
            $data['OPERAÇÃO'],
            $data['PIX'],
            $data['VALOR A SER PAGO'],
            $data['DESCRIÇÃO DO DESPESA']
        ];
        
        echo implode(';', array_map($formatCell, $row)) . "\r\n";
    }
    exit;
}

// 3) Cabeçalho padrão
Html::header(
    __('Relatório de Ofícios'),
    $_SERVER['PHP_SELF'],
    'tools',
    'report'
);

// Função para extrair dados do conteúdo HTML
function extractDataFromContent($content) {
    $data = [
        'CONTRATO' => '',
        'SERVIÇOS' => '',
        'FAVORECIDO' => '',
        'CPF/CNPJ' => '',
        'BANCO' => '',
        'AGÊNCIA' => '',
        'CONTA' => '',
        'OPERAÇÃO' => '',
        'PIX' => '',
        'VALOR A SER PAGO' => '',
        'DESCRIÇÃO DO DESPESA' => ''
    ];

    // Decodifica entidades HTML duas vezes
    $content = html_entity_decode(html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    
    // Cria um novo documento DOM
    $dom = new DOMDocument();
    
    // Suprime warnings do DOMDocument ao parsear HTML malformado
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($content, 'HTML-ENTITIES', 'UTF-8'), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    
    // Cria um novo XPath
    $xpath = new DOMXPath($dom);
    
    // Função auxiliar para extrair valor
    $getValue = function($node) {
        if (!$node) return '';
        // Preserva quebras de linha substituindo <br> e </p><p> por \n
        $html = $node->ownerDocument->saveHTML($node);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>\s*<p>/i', "\n", $html);
        // Remove todas as outras tags HTML
        $text = strip_tags($html);
        // Limpa espaços extras mas preserva quebras de linha
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\s*\n\s*/', "\n", $text);
        return trim($text);
    };

    // Função auxiliar para limpar o label
    $cleanLabel = function($label) {
        // Remove números, parênteses e ":" do início do label
        $label = preg_replace('/^\d+\)\s*/', '', $label);
        $label = rtrim($label, ' :');
        return $label;
    };
    
    // Função auxiliar para mapear labels específicos
    $mapLabel = function($label) {
        $label = strtoupper(trim($label));
        $mappings = [
            'SERVIÇO PRESTADO AO CONTRATO' => 'CONTRATO',
            'SERVIÇOS' => 'SERVIÇOS',
            'DESCRIÇÃO' => 'DESCRIÇÃO DO DESPESA'
        ];
        return isset($mappings[$label]) ? $mappings[$label] : $label;
    };

    // Procura em divs com b/strong
    $divs = $xpath->query('//div[.//b or .//strong]');
    foreach ($divs as $div) {
        $bold = $xpath->query('.//b|.//strong', $div)->item(0);
        if ($bold) {
            $label = $getValue($bold);
            $label = $cleanLabel($label);
            $label = $mapLabel($label);
            
            // Pega o texto completo da div
            $fullText = $getValue($div);
            // Remove o texto do label para obter o valor
            $value = trim(str_replace($getValue($bold), '', $fullText));
            
            // Atualiza o array de dados se o label existir
            if (array_key_exists($label, $data)) {
                $data[$label] = $value;
            }
        }
    }

    // Procura especificamente pela descrição em parágrafos
    $descriptionDivs = $xpath->query('//div[contains(.//b/text(), "DESCRIÇÃO") or contains(.//strong/text(), "DESCRIÇÃO")]');
    if ($descriptionDivs->length > 0) {
        $descriptionDiv = $descriptionDivs->item(0);
        // Pega todos os parágrafos após o label
        $paragraphs = $xpath->query('.//p', $descriptionDiv);
        $description = '';
        foreach ($paragraphs as $p) {
            if ($description !== '') {
                $description .= "\n";
            }
            $description .= trim($getValue($p));
        }
        if ($description) {
            $data['DESCRIÇÃO DO DESPESA'] = $description;
        }
    }

    return $data;
}

// 4) Datas padrão (do início do mês até hoje)
if (empty($_POST['date1']) && empty($_POST['date2'])) {
    $_POST['date1'] = date('Y-m-01');
    $_POST['date2'] = date('Y-m-d');
}

// 5) Se o usuário trocou a ordem, inverte
if (
    !empty($_POST['date1'])
    && !empty($_POST['date2'])
    && strcmp($_POST['date2'], $_POST['date1']) < 0
) {
    list($_POST['date1'], $_POST['date2']) = [$_POST['date2'], $_POST['date1']];
}

// 6) Formulário de período
echo "<div class='center'><form method='post' action='".$_SERVER['PHP_SELF']."'>";
    echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);

    echo "<table class='tab_cadre'>";
    echo "<tr class='tab_bg_2'>";
        echo "<td class='right'>".__('Data Início')."</td><td>";
            Html::showDateField('date1', ['value' => $_POST['date1']]);
        echo "</td>";
        echo "<td class='right'>".__('Data Fim')."</td><td>";
            Html::showDateField('date2', ['value' => $_POST['date2']]);
        echo "</td>";
        echo "<td class='center'>";
            echo Html::submit(
                __('Gerar relatório'),
                ['name' => 'submit', 'class' => 'btn btn-primary']
            );
        echo "</td>";
    echo "</tr>";
    echo "</table>";
Html::closeForm();
echo "</div>";

// 7) Se clicou em Mostrar relatório
if (isset($_POST['submit']) || isset($_POST['download'])) {
    global $DB;

    $begin = $_POST['date1'] . ' 00:00:00';
    $end   = $_POST['date2'] . ' 23:59:59';

    // Define a ordem padrão como DESC se não especificado
    $order = isset($_POST['order']) && $_POST['order'] === 'ASC' ? 'ASC' : 'DESC';

    // Consulta base
    $sql = "SELECT t.id, t.date, t.name, t.status, t.content
            FROM `glpi_tickets` t
            WHERE t.date BETWEEN '{$begin}' AND '{$end}'
            AND t.is_deleted = 0
            AND t.itilcategories_id IN (55, 56)";
    
    // Adiciona busca em todos os campos se fornecido
    if (!empty($_POST['search_term'])) {
        $search = $DB->escape($_POST['search_term']);
        $sql .= " AND (
            t.id LIKE '%{$search}%'
            OR t.name LIKE '%{$search}%'
            OR t.content LIKE '%{$search}%'
            OR t.date LIKE '%{$search}%'
        )";
    }
    
    $sql .= " ORDER BY t.id " . $order;

    $result = $DB->query($sql);
    
    if ($result === false) {
        echo "<div class='center'>";
        echo "<p class='red'>" . __('Erro ao executar a consulta.') . "</p>";
        echo "</div>";
    } else {
        $tickets = [];
        while ($row = $result->fetch_assoc()) {
            $tickets[] = $row;
        }

        // Mostra os controles de busca e download se houver resultados
        if (!empty($tickets)) {
            echo "<div style='margin: 10px 0; display: flex; justify-content: space-between; align-items: center; margin-top: 40px; margin-bottom: 20px;'>";
            
            // Campo de busca e contador à esquerda
            echo "<div style='display: flex; align-items: center;'>";
            echo "<form method='post' action='".$_SERVER['PHP_SELF']."' style='display: flex; align-items: center;'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('date1', ['value' => $_POST['date1']]);
            echo Html::hidden('date2', ['value' => $_POST['date2']]);
            echo Html::hidden('order', ['value' => $order]);
            echo "<input type='text' name='search_term' value='" . (isset($_POST['search_term']) ? htmlspecialchars($_POST['search_term']) : '') . "' 
                  class='form-control' placeholder='Buscar em todos os campos...' style='width: 300px; margin-right: 10px; '>";
            echo Html::submit(
                __('Buscar'),
                ['name' => 'submit', 'class' => 'btn btn-primary']
            );
            echo "</form>";
            // Contador de chamados
            echo "<div style='margin-left: 20px;'>Total de chamados retornados: <strong>" . count($tickets) . "</strong></div>";
            echo "</div>";
            
            // Botão Excel à direita
            echo "<div>";
            echo "<form method='post' action='".$_SERVER['PHP_SELF']."' style='display: inline;'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('date1', ['value' => $_POST['date1']]);
            echo Html::hidden('date2', ['value' => $_POST['date2']]);
            if (isset($_POST['search_term'])) {
                echo Html::hidden('search_term', ['value' => $_POST['search_term']]);
            }
            echo Html::submit(
                __('Download Excel'),
                ['name' => 'download', 'class' => 'btn btn-success']
            );
            echo "</form>";
            echo "</div>";
            
            echo "</div>";
        }

        if (empty($tickets)) {
            echo "<div class='center'>";
            echo "<p class='red'>" . __('Nenhum ticket encontrado no período selecionado.') . "</p>";
            echo "</div>";
        } else {
            // Se for visualização, mostra na tela
            echo "<div class='center spaced'>";
            echo "<div style='overflow-x:auto;'>";
            echo "<table class='tab_cadre_fixehov'>";
            echo "<tr class='noHover'>";
            
            // Link para ordenar o ID
            $newOrder = $order === 'ASC' ? 'DESC' : 'ASC';
            echo "<th class='left-align'>";
            echo "<form method='post' action='".$_SERVER['PHP_SELF']."' style='display:inline;'>";
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo Html::hidden('date1', ['value' => $_POST['date1']]);
            echo Html::hidden('date2', ['value' => $_POST['date2']]);
            if (isset($_POST['search_term'])) {
                echo Html::hidden('search_term', ['value' => $_POST['search_term']]);
            }
            echo Html::hidden('order', ['value' => $newOrder]);
            echo "<button type='submit' name='submit' class='btn btn-link p-0 text-decoration-none'>";
            echo "ID ";
            // Mostra a seta indicando a ordem atual
            echo $order === 'ASC' ? '↑' : '↓';
            echo "</button>";
            echo "</form>";
            echo "</th>";

            echo "<th class='center-align'>DATA</th>";
            echo "<th class='center-align'>TÍTULO</th>";
            echo "<th class='center-align'>STATUS</th>";
            echo "<th class='center-align'>CONTRATO</th>";
            echo "<th class='center-align'>SERVIÇO</th>";
            echo "<th class='center-align'>FAVORECIDO</th>";
            echo "<th class='center-align'>CPF/CNPJ</th>";
            echo "<th class='center-align'>BANCO</th>";
            echo "<th class='center-align'>AGÊNCIA</th>";
            echo "<th class='center-align'>CONTA</th>";
            echo "<th class='center-align'>OPERAÇÃO</th>";
            echo "<th class='center-align'>PIX</th>";
            echo "<th class='center-align'>VALOR</th>";
            echo "<th class='left-align'>DESCRIÇÃO</th>";
            echo "</tr>";

            foreach ($tickets as $ticket) {
                $data = extractDataFromContent($ticket['content']);
                echo "<tr class='tab_bg_2'>";
                echo "<td class='left-align'>" . $ticket['id'] . "</td>";
                echo "<td class='center-align'>" . date('d/m/Y H:i', strtotime($ticket['date'])) . "</td>";
                echo "<td class='center-align'>" . $ticket['name'] . "</td>";
                echo "<td class='center-align'>" . Ticket::getStatus($ticket['status']) . "</td>";
                echo "<td class='center-align'>" . $data['CONTRATO'] . "</td>";
                echo "<td class='center-align'>" . $data['SERVIÇOS'] . "</td>";
                echo "<td class='center-align'>" . $data['FAVORECIDO'] . "</td>";
                echo "<td class='center-align'>" . $data['CPF/CNPJ'] . "</td>";
                echo "<td class='center-align'>" . $data['BANCO'] . "</td>";
                echo "<td class='center-align'>" . $data['AGÊNCIA'] . "</td>";
                echo "<td class='center-align'>" . $data['CONTA'] . "</td>";
                echo "<td class='center-align'>" . $data['OPERAÇÃO'] . "</td>";
                echo "<td class='center-align'>" . $data['PIX'] . "</td>";
                echo "<td class='center-align'>" . $data['VALOR A SER PAGO'] . "</td>";
                echo "<td class='left-align'>" . $data['DESCRIÇÃO DO DESPESA'] . "</td>";
                echo "</tr>";
            }
            echo "</table>";
            echo "</div>";
            echo "</div>";
        }
    }
}

// Adiciona estilo para ajustar o tamanho da fonte e alinhamentos
echo "<style>
    .tab_cadre_fixehov td, .tab_cadre_fixehov th {
        font-size: 12px !important;
        padding: 5px !important;
    }
    
    /* Alinhamento centralizado para colunas específicas */
    .tab_cadre_fixehov td.center-align,
    .tab_cadre_fixehov th.center-align {
        text-align: center !important;
    }
    
    /* Alinhamento à esquerda para ID e Descrição */
    .tab_cadre_fixehov td.left-align,
    .tab_cadre_fixehov th.left-align {
        text-align: left !important;
    }
</style>";

// 8) Rodapé padrão
Html::footer();
