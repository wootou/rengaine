<?php
// Un modèle de langage qui sait appeler des outils (IA-04). Chaque fournisseur garde sa propre forme
// de conversation ; la boucle (Organizer) ne voit que des appels d'outils et des résultats.

declare(strict_types=1);

namespace Musique\Ai;

interface Provider
{
    /** « anthropic / claude-opus-5-5 » : pour les journaux et l'admin. */
    public function label(): string;

    /**
     * Commence une conversation.
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}> $tools
     */
    public function start(string $system, string $task, array $tools): void;

    /**
     * Tour suivant du modèle.
     * @return array{text: string, calls: list<array{id: string, name: string, args: array<string, mixed>|null}>,
     *   stop: string, usage: array{in: int, out: int}}
     *   stop : tools (des outils à lancer), done, truncated (réponse coupée), refused, again (relancer tel quel)
     */
    public function next(): array;

    /**
     * Résultats des outils demandés au tour précédent, tous ensemble.
     * @param list<array{id: string, text: string, error: bool}> $results
     */
    public function answer(array $results): void;
}
