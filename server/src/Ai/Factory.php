<?php
// Fournisseur d'IA de la réorganisation lancée depuis l'admin (IA-04), lu dans la section [ia] de
// rengaine.ini. Sans section (ou sans fournisseur), la fonction est absente. La clé reste dans ce
// fichier : elle n'est ni en base, ni dans l'API, ni dans les journaux.
//
//   [ia]
//   fournisseur = anthropic          ; ou openai (tout service compatible : OpenAI, Mistral, Ollama…)
//   cle = …                          ; facultative pour un serveur local
//   modele = claude-opus-5-5         ; obligatoire pour openai
//   url = https://api.openai.com/v1  ; obligatoire pour openai (ex. http://<machine>:11434/v1 pour Ollama)
//   effort = high                    ; anthropic seulement : low, medium, high, xhigh, max
//   tours_max = 40                   ; échanges au plus avec le modèle par tâche

declare(strict_types=1);

namespace Musique\Ai;

use Musique\Config;

final class Factory
{
    public static function fromConfig(Config $config): ?Provider
    {
        $kind = $config->has('ia', 'fournisseur') ? strtolower(trim($config->string('ia', 'fournisseur'))) : '';
        $key = $config->has('ia', 'cle') ? trim($config->string('ia', 'cle')) : '';
        $model = $config->has('ia', 'modele') ? trim($config->string('ia', 'modele')) : '';
        $url = $config->has('ia', 'url') ? trim($config->string('ia', 'url')) : '';
        return match ($kind) {
            'anthropic' => $key === '' ? null : new Anthropic(
                $key,
                $model === '' ? Anthropic::DEFAULT_MODEL : $model,
                in_array($e = $config->has('ia', 'effort') ? $config->string('ia', 'effort') : 'high', ['low', 'medium', 'high', 'xhigh', 'max'], true) ? $e : 'high',
                $url === '' ? 'https://api.anthropic.com' : $url,
            ),
            'openai' => $model === '' || $url === '' ? null : new OpenAiCompatible($url, $model, $key),
            default => null,
        };
    }

    /** Ce que l'admin peut voir : fournisseur et modèle, jamais la clé. @return array{provider: string, model: string}|null */
    public static function describe(Config $config): ?array
    {
        $p = self::fromConfig($config);
        if ($p === null) {
            return null;
        }
        [$provider, $model] = explode(' / ', $p->label(), 2);
        return ['provider' => $provider, 'model' => $model];
    }
}
