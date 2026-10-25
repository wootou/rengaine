<?php
// Fournisseur Anthropic (API Messages, appels HTTP directs : pas de Composer sur ce serveur).
// Les blocs de réponse sont renvoyés tels quels au tour suivant (réflexion comprise), et tous les
// résultats d'outils d'un tour partent dans un seul message.

declare(strict_types=1);

namespace Musique\Ai;

final class Anthropic implements Provider
{
    public const DEFAULT_MODEL = 'claude-opus-5-5';
    /** Modèles qui acceptent le repli côté serveur (`fallbacks: "default"`) quand une réponse est refusée. */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-opus-5', 'claude-fable-5-1', 'claude-sonnet-5-5'];

    private string $system = '';
    /** @var list<array<string, mixed>> */
    private array $tools = [];
    /** @var list<array<string, mixed>> */
    private array $messages = [];

    public function __construct(
        private readonly string $key,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly string $effort = 'high',
        private readonly string $url = 'https://api.anthropic.com',
    ) {
    }

    public function label(): string
    {
        return 'anthropic / ' . $this->model;
    }

    public function start(string $system, string $task, array $tools): void
    {
        $this->system = $system;
        $this->tools = array_map(static fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['inputSchema']], $tools);
        $this->messages = [['role' => 'user', 'content' => $task]];
    }

    public function next(): array
    {
        $body = [
            'model' => $this->model,
            'max_tokens' => 16000,
            'system' => $this->system,
            'tools' => $this->tools,
            'messages' => $this->messages,
            'output_config' => ['effort' => $this->effort],
        ];
        $headers = ['x-api-key' => $this->key, 'anthropic-version' => '2026-10-21'];
        if (in_array($this->model, self::FALLBACK_MODELS, true) && str_starts_with($this->url, 'https://api.anthropic.com')) {
            $body['fallbacks'] = 'default';
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
        }
        $r = Http::post(rtrim($this->url, '/') . '/v1/messages', $headers, $body);
        $content = is_array($r['content'] ?? null) ? $r['content'] : [];
        $this->messages[] = ['role' => 'assistant', 'content' => $content];

        $text = '';
        $calls = [];
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) $block['text'];
            } elseif (($block['type'] ?? '') === 'tool_use') {
                $calls[] = ['id' => (string) $block['id'], 'name' => (string) $block['name'], 'args' => is_array($block['input'] ?? null) ? $block['input'] : null];
            }
        }
        $stop = match ($r['stop_reason'] ?? '') {
            'tool_use' => 'tools',
            'max_tokens' => 'truncated',
            'refusal' => 'refused',
            'pause_turn' => 'again',
            default => 'done',
        };
        // 'again' (pause_turn) : la conversation, réponse partielle comprise, repart telle quelle au tour suivant
        if ($stop === 'tools' && $calls === []) {
            $stop = 'done';
        }
        return [
            'text' => trim($text),
            'calls' => $stop === 'tools' ? $calls : [],
            'stop' => $stop,
            'usage' => ['in' => (int) ($r['usage']['input_tokens'] ?? 0), 'out' => (int) ($r['usage']['output_tokens'] ?? 0)],
        ];
    }

    public function answer(array $results): void
    {
        $this->messages[] = ['role' => 'user', 'content' => array_map(static fn ($r) => [
            'type' => 'tool_result', 'tool_use_id' => $r['id'], 'content' => $r['text'], 'is_error' => $r['error'],
        ], $results)];
    }
}
