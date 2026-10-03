<?php
// Roda via cron (segunda e quinta, 11:55).
// Lê a subpágina mais recente criada hoje dentro de "Posts LinkedIn" no Notion
// e envia o texto para o Telegram.

declare(strict_types=1);

date_default_timezone_set('America/Bahia');

$cfg = require __DIR__ . '/config.php';

function http(string $method, string $url, array $headers = [], ?array $json = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($json !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException("Falha de rede: $err");
    }
    $data = json_decode($body, true);
    // A URL fica fora da mensagem de erro para não vazar o token do bot nos logs.
    if ($status >= 400 || !is_array($data)) {
        throw new RuntimeException("HTTP $status: " . substr((string) $body, 0, 300));
    }
    return $data;
}

function notion(array $cfg, string $path): array
{
    return http('GET', 'https://api.notion.com/v1/' . $path, [
        'Authorization: Bearer ' . $cfg['notion_token'],
        'Notion-Version: 2022-06-28',
    ]);
}

function telegram(array $cfg, string $texto): void
{
    // Limite do Telegram: 4096 caracteres por mensagem.
    foreach (mb_str_split($texto, 3500) as $parte) {
        http('POST', 'https://api.telegram.org/bot' . $cfg['telegram_token'] . '/sendMessage', [], [
            'chat_id' => $cfg['telegram_chat_id'],
            'text' => $parte,
            'disable_web_page_preview' => true,
        ]);
    }
}

function filhos(array $cfg, string $blockId): array
{
    $todos = [];
    $cursor = null;
    do {
        $path = "blocks/$blockId/children?page_size=100";
        if ($cursor !== null) {
            $path .= '&start_cursor=' . urlencode($cursor);
        }
        $res = notion($cfg, $path);
        $todos = array_merge($todos, $res['results'] ?? []);
        $cursor = !empty($res['has_more']) ? ($res['next_cursor'] ?? null) : null;
    } while ($cursor !== null);
    return $todos;
}

function textoDoBloco(array $bloco): string
{
    $tipo = $bloco['type'] ?? '';
    $suportados = [
        'paragraph', 'heading_1', 'heading_2', 'heading_3',
        'bulleted_list_item', 'numbered_list_item', 'quote', 'callout', 'to_do',
    ];
    if (!in_array($tipo, $suportados, true)) {
        return '';
    }
    $partes = $bloco[$tipo]['rich_text'] ?? [];
    $texto = implode('', array_map(fn($p) => $p['plain_text'] ?? '', $partes));
    if ($texto === '') {
        return '';
    }
    if ($tipo === 'bulleted_list_item' || $tipo === 'numbered_list_item') {
        return '• ' . $texto;
    }
    return $texto;
}

try {
    $hoje = date('Y-m-d');

    $paginas = array_values(array_filter(
        filhos($cfg, $cfg['notion_parent_page_id']),
        fn($b) => ($b['type'] ?? '') === 'child_page'
            && date('Y-m-d', strtotime($b['created_time'])) === $hoje
    ));

    if (!$paginas) {
        telegram($cfg, 'Hoje não encontrei rascunho novo em "Posts LinkedIn". Confira a tarefa em Scheduled no Cowork.');
        exit(0);
    }

    usort($paginas, fn($a, $b) => strcmp($b['created_time'], $a['created_time']));
    $pagina = $paginas[0];

    $titulo = $pagina['child_page']['title'] ?? 'Rascunhos de LinkedIn';
    $linhas = array_filter(
        array_map('textoDoBloco', filhos($cfg, $pagina['id'])),
        fn($l) => $l !== ''
    );
    $url = 'https://www.notion.so/' . str_replace('-', '', $pagina['id']);

    telegram($cfg, $titulo . "\n\n" . implode("\n\n", $linhas) . "\n\n" . $url);
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
