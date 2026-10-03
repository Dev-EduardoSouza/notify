<?php

declare(strict_types=1);

const STATE_DIR = __DIR__ . '/state';
const FIXTURE = __DIR__ . '/fixtures/notion.json';

if (!is_dir(STATE_DIR)) {
    mkdir(STATE_DIR, 0775, true);
}

/** @return array<string, mixed> */
function state(string $name): array
{
    $file = STATE_DIR . "/{$name}.json";
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

    return is_array($data) ? $data : [];
}

function saveState(string $name, array $data): void
{
    file_put_contents(STATE_DIR . "/{$name}.json", json_encode($data, JSON_PRETTY_PRINT));
}

function reply(int $status, array $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $name => $value) {
        header("{$name}: {$value}");
    }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function record(string $service, string $path, ?array $body): void
{
    $line = json_encode([
        'at' => gmdate('c'),
        'service' => $service,
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => $path,
        'notion_version' => $_SERVER['HTTP_NOTION_VERSION'] ?? null,
        'body' => $body,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    file_put_contents(STATE_DIR . '/calls.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$input = json_decode((string) file_get_contents('php://input'), true);
$input = is_array($input) ? $input : null;

if (preg_match('#^/bot([^/]+)/(\w+)$#', $path, $m) === 1) {
    record('telegram', '/bot***/' . $m[2], $input);

    $scenario = state('telegram');
    $counter = state('telegram-counter');
    $call = (int) ($counter['calls'] ?? 0) + 1;
    saveState('telegram-counter', ['calls' => $call]);

    $mode = $scenario['mode'] ?? 'ok';
    $failPart = (int) ($scenario['fail_on_call'] ?? 0);

    if ($mode === 'fail_on_call' && $call !== $failPart) {
        $mode = 'ok';
    } elseif ($mode === 'fail_on_call') {
        $mode = (string) ($scenario['fail_with'] ?? '500');
    }

    switch ($mode) {
        case '429':
            $retry = (int) ($scenario['retry_after'] ?? 30);
            reply(429, [
                'ok' => false,
                'error_code' => 429,
                'description' => "Too Many Requests: retry after {$retry}",
                'parameters' => ['retry_after' => $retry],
            ]);
            return true;
        case '500':
        case '502':
            reply((int) $mode, ['ok' => false, 'error_code' => (int) $mode, 'description' => 'Internal Server Error']);
            return true;
        case '400':
            reply(400, ['ok' => false, 'error_code' => 400, 'description' => "Bad Request: can't parse entities"]);
            return true;
        case '401':
            reply(401, ['ok' => false, 'error_code' => 401, 'description' => 'Unauthorized']);
            return true;
    }

    reply(200, ['ok' => true, 'result' => [
        'message_id' => $call,
        'date' => time(),
        'chat' => ['id' => $input['chat_id'] ?? null, 'type' => 'private'],
        'text' => $input['text'] ?? '',
    ]]);
    return true;
}

if (preg_match('#^/v1/blocks/([^/]+)/children$#', $path, $m) === 1) {
    record('notion', $path . (isset($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''), null);

    $scenario = state('notion');
    $mode = $scenario['mode'] ?? 'ok';
    if ($mode === '401') {
        reply(401, ['object' => 'error', 'status' => 401, 'code' => 'unauthorized', 'message' => 'API token is invalid.']);
        return true;
    }
    if ($mode === '500') {
        reply(500, ['object' => 'error', 'status' => 500, 'code' => 'internal_server_error', 'message' => 'Unexpected error.']);
        return true;
    }

    $fixtureFile = is_string($scenario['fixture'] ?? null) ? __DIR__ . '/fixtures/' . basename($scenario['fixture']) : FIXTURE;
    $fixture = json_decode((string) file_get_contents($fixtureFile), true);
    $children = $fixture['children'][$m[1]] ?? null;
    if (!is_array($children)) {
        reply(404, ['object' => 'error', 'status' => 404, 'code' => 'object_not_found', 'message' => 'Could not find block.']);
        return true;
    }

    $children = array_map('resolveTimes', $children);
    $size = (int) ($scenario['page_size'] ?? ($_GET['page_size'] ?? 100));
    $size = max(1, min($size, (int) ($_GET['page_size'] ?? 100)));
    $offset = (int) ($_GET['start_cursor'] ?? 0);
    $page = array_slice($children, $offset, $size);
    $next = $offset + $size < count($children) ? (string) ($offset + $size) : null;

    reply(200, ['object' => 'list', 'results' => $page, 'next_cursor' => $next, 'has_more' => $next !== null]);
    return true;
}

reply(404, ['error' => 'stub: unknown route', 'path' => $path]);
return true;

/**
 * @param array<string, mixed> $block
 * @return array<string, mixed>
 */
function resolveTimes(array $block): array
{
    foreach (['created_time', 'last_edited_time'] as $key) {
        $value = $block[$key] ?? null;
        if (!is_string($value) || preg_match('/^(bahia|utc)-(today|yesterday)@(\d\d:\d\d)$/', $value, $m) !== 1) {
            continue;
        }
        $day = new DateTimeImmutable($m[2] === 'today' ? 'today' : 'yesterday', new DateTimeZone('America/Bahia'));
        $zone = new DateTimeZone($m[1] === 'bahia' ? 'America/Bahia' : 'UTC');
        $time = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $m[3], $zone);
        $block[$key] = $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
    }

    return $block;
}
