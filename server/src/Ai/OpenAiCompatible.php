<?php
// Fournisseur « compatible OpenAI » (POST {url}/chat/completions avec outils) : OpenAI, Mistral, et
// les modèles locaux servis par Ollama, LM Studio ou vLLM (la bibliothèque ne quitte alors pas le réseau).

declare(strict_types=1);

namespace Musique\Ai;

final class OpenAiCompatible implements Provider
{
    /** @var list<array<string, mixed>> */
    private array $tools = [];
    /** @var list<array<string, mixed>> */
    private array $messages = [];

    public function __construct(
        private readonly string $url,
        private readonly string $model,
        private readonly string $key = '',
    ) {
    }

    public function label(): string
    {
        return 'openai / ' . $this->model;
    }

    public function start(string $system, string $task, array $tools): void
    {
        $this->tools = array_map(static fn ($t) => ['type' => 'function', 'function' => [
            'name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['inputSchema'],
        ]], $tools);
        $this->messages = [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $task]];
    }

    public function next(): array
    {
        $headers = $this->key === '' ? [] : ['Authorization' => 'Bearer ' . $this->key];
        $r = Http::post(rtrim($this->url, '/') . '/chat/completions', $headers, [
            'model' => $this->model, 'messages' => $this->messages, 'tools' => $this->tools,
        ]);
        $choice = $r['choices'][0] ?? null;
        $msg = is_array($choice['message'] ?? null) ? $choice['message'] : null;
        if ($msg === null) {
            throw new AiException('Réponse sans message');
        }
        $calls = [];
        foreach ((array) ($msg['tool_calls'] ?? []) as $c) {
            $args = json_decode((string) ($c['function']['arguments'] ?? ''), true);
            $calls[] = ['id' => (string) ($c['id'] ?? ''), 'name' => (string) ($c['function']['name'] ?? ''), 'args' => is_array($args) ? $args : ($args === null && trim((string) ($c['function']['arguments'] ?? '')) === '' ? [] : null)];
        }
        // On garde le message tel quel (certains serveurs y tiennent), sans champs vides gênants
        $keep = ['role' => 'assistant', 'content' => $msg['content'] ?? ''];
        if ($calls !== []) {
            $keep['tool_calls'] = $msg['tool_calls'];
        }
        $this->messages[] = $keep;
        // Des modèles locaux répondent « stop » tout en demandant des outils : les appels font foi
        $stop = $calls !== [] ? 'tools' : match ($choice['finish_reason'] ?? '') {
            'length' => 'truncated',
            'content_filter' => 'refused',
            default => 'done',
        };
        return [
            'text' => trim((string) ($msg['content'] ?? '')),
            'calls' => $calls,
            'stop' => $stop,
            'usage' => ['in' => (int) ($r['usage']['prompt_tokens'] ?? 0), 'out' => (int) ($r['usage']['completion_tokens'] ?? 0)],
        ];
    }

    public function answer(array $results): void
    {
        foreach ($results as $res) {
            $this->messages[] = ['role' => 'tool', 'tool_call_id' => $res['id'], 'content' => $res['text']];
        }
    }
}
