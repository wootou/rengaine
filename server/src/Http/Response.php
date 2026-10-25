<?php
// Réponse HTTP : corps JSON, vide, ou flux (audio, images) écrit morceau par morceau.

declare(strict_types=1);

namespace Musique\Http;

final class Response
{
    /** @var array<string, string> */
    public array $headers = [];
    /** @var list<string> */
    public array $cookies = [];

    /** @param ?\Closure(): void $stream écrit le corps elle-même (echo + flush) */
    public function __construct(
        public int $status = 200,
        public ?string $body = null,
        public ?\Closure $stream = null,
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        $r = new self($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
        $r->headers['Content-Type'] = 'application/json; charset=utf-8';
        return $r;
    }

    public static function noContent(): self
    {
        return new self(204);
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function cookie(string $setCookie): self
    {
        $this->cookies[] = $setCookie;
        return $this;
    }

    /** Envoi au client (SAPI web). */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header_remove('X-Powered-By');
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }
            if ($this->stream === null && $this->body !== null && !isset($this->headers['Content-Length'])) {
                header('Content-Length: ' . strlen($this->body));
            }
            foreach ($this->cookies as $c) {
                header("Set-Cookie: $c", false);
            }
        }
        if ($this->stream !== null) {
            ($this->stream)();
        } elseif ($this->body !== null) {
            echo $this->body;
        }
    }
}
