<?php
// Faux fournisseur d'IA pour les essais d'IA-04 (php -S … fake-llm.php) : rejoue un scénario sans
// rien coûter. Chemin : /<scénario>-<album>/v1/messages (Anthropic) ou /<scénario>-<album>/chat/completions
// (compatible OpenAI). Scénarios : ok (deux outils en parallèle, puis un lot et une erreur, puis le
// compte rendu), loop (outils sans fin), deny (clé refusée). Chaque requête est notée dans FAKE_LLM_LOG.

declare(strict_types=1);

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];
file_put_contents((string) getenv('FAKE_LLM_LOG'), json_encode([
    'path' => $path,
    'key' => $_SERVER['HTTP_X_API_KEY'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? null,
    'beta' => $_SERVER['HTTP_ANTHROPIC_BETA'] ?? null,
    'body' => $body,
]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
preg_match('#^/(\w+)-(\d+)/(v1/messages|chat/completions)$#', $path, $m) || exit(http_response_code(404));
[, $scenario, $album, $api] = $m;
$anthropic = $api === 'v1/messages';

if ($scenario === 'deny') {
    http_response_code(401);
    exit(json_encode(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']]));
}
// Tours déjà faits = réponses de l'assistant dans la conversation
$turn = count(array_filter($body['messages'] ?? [], static fn ($x) => ($x['role'] ?? '') === 'assistant'));
$calls = match (true) {
    $scenario === 'loop' => [['list_genres', []]],
    $turn === 0 => [['search_library', ['q' => 'album']], ['list_genres', (object) []]],
    $turn === 1 => [
        ['propose_changes', ['title' => 'Année d’un album', 'changes' => [['entityType' => 'album', 'entityId' => (int) $album, 'field' => 'year', 'value' => 1999, 'reason' => 'Essai']]]],
        ['get_album', ['id' => 999999]],
    ],
    default => [],
};
$text = $calls === [] ? 'Fini : un lot proposé, un album introuvable laissé de côté.' : 'Je regarde.';
if ($anthropic) {
    $content = [['type' => 'thinking', 'thinking' => '', 'signature' => "sig-$turn"], ['type' => 'text', 'text' => $text]];
    foreach ($calls as $i => [$name, $input]) {
        $content[] = ['type' => 'tool_use', 'id' => "toolu_{$turn}_$i", 'name' => $name, 'input' => $input];
    }
    echo json_encode(['id' => "msg_$turn", 'type' => 'message', 'role' => 'assistant', 'content' => $content,
        'stop_reason' => $calls === [] ? 'end_turn' : 'tool_use', 'usage' => ['input_tokens' => 100, 'output_tokens' => 10]]);
} else {
    $msg = ['role' => 'assistant', 'content' => $text];
    if ($calls !== []) {
        $msg['tool_calls'] = array_map(static fn ($c, $i) => ['id' => "call_{$turn}_$i", 'type' => 'function',
            'function' => ['name' => $c[0], 'arguments' => json_encode($c[1])]], $calls, array_keys($calls));
    }
    echo json_encode(['choices' => [['index' => 0, 'message' => $msg, 'finish_reason' => $calls === [] ? 'stop' : 'tool_calls']],
        'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]]);
}
