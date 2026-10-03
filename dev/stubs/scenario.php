<?php

declare(strict_types=1);

$dir = __DIR__ . '/state';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

$target = $argv[1] ?? '';

if ($target === 'reset') {
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    echo "stubs reset\n";
    exit(0);
}

if ($target === 'calls') {
    echo is_file($dir . '/calls.jsonl') ? file_get_contents($dir . '/calls.jsonl') : '';
    exit(0);
}

if (!in_array($target, ['telegram', 'notion'], true) || !isset($argv[2])) {
    fwrite(STDERR, "usage: scenario.php reset|calls|telegram <mode> [k=v...]|notion <mode> [k=v...]\n");
    exit(1);
}

$scenario = ['mode' => $argv[2]];
foreach (array_slice($argv, 3) as $pair) {
    [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
    $scenario[$key] = ctype_digit($value) ? (int) $value : $value;
}

file_put_contents("{$dir}/{$target}.json", json_encode($scenario, JSON_PRETTY_PRINT));
if ($target === 'telegram') {
    @unlink("{$dir}/telegram-counter.json");
}
echo "{$target}: " . json_encode($scenario) . "\n";
