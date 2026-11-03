<?php
// Artiste lu dans le titre (« Queen - Bohemian Rhapsody (Official Video) ») pour les pistes sans aucun
// artiste connu, souvent des rips de vidéos : une suggestion de titre et de crédit par piste, à vérifier
// puis à proposer (title + artistCredit). Rien n'est modifié ici.
//   - découpage au premier séparateur « - », « – », « — », « • », « | » entouré d'espaces ;
//   - nettoyage des mentions de vidéo (« (Official Video) », « [Clip officiel] », « (Lyrics) »…), des
//     débits (« -320k », « (320 kbps) ») et d'un numéro de piste en tête (« 03. ») ;
//   - inversion : si la partie droite est un artiste connu de la bibliothèque et pas la gauche
//     (« Another One Bites the Dust - Queen »), les deux sont échangées (inverted = true).

declare(strict_types=1);

namespace Musique\Library;

use Musique\Db\Database;

final class TitleCredits
{
    private const SEPARATOR = '/\s+[-–—•|]\s+/u';
    private const NOISE = '/\s*[(\[](?:[^)\]]*\b(?:official|officiel(?:le)?|clip|lyrics?|paroles|audio|video|vidéo|visuali[sz]er|hd|hq|4k|remaster(?:ed)?\s+\d{4}|\d{2,3}\s*kbps)\b[^)\]]*)[)\]]/iu';

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @return list<array{trackId: int, albumId: int, title: string, suggestedTitle: string, suggestedArtist: string, inverted: bool, knownArtist: bool}>
     */
    public function suggestions(?int $albumId = null): array
    {
        $known = [];
        foreach ($this->db->all('SELECT name FROM artists') as $r) {
            $known[self::key((string) $r['name'])] = true;
        }
        $sql = "SELECT t.id, t.album_id, t.title FROM tracks t JOIN albums a ON a.id = t.album_id
                 WHERE t.missing_since IS NULL AND t.artist_credit IS NULL AND a.album_artist_id IS NULL
                   AND NOT EXISTS (SELECT 1 FROM track_artists ta WHERE ta.track_id = t.id AND ta.role = 'main')";
        $args = [];
        if ($albumId !== null) {
            $sql .= ' AND t.album_id = ?';
            $args[] = $albumId;
        }
        $out = [];
        foreach ($this->db->all($sql . ' ORDER BY t.album_id, t.disc_number, t.track_number, t.id', $args) as $r) {
            $s = self::split((string) $r['title']);
            if ($s === null) {
                continue;
            }
            [$artist, $title] = $s;
            $inverted = false;
            if (!isset($known[self::key($artist)]) && isset($known[self::key($title)])) {
                [$artist, $title] = [$title, $artist];
                $inverted = true;
            }
            $out[] = [
                'trackId' => (int) $r['id'], 'albumId' => (int) $r['album_id'], 'title' => (string) $r['title'],
                'suggestedTitle' => $title, 'suggestedArtist' => $artist, 'inverted' => $inverted,
                'knownArtist' => isset($known[self::key($artist)]),
            ];
        }
        return $out;
    }

    /** « 03. Queen - Bohemian Rhapsody (Official Video) -320k » → [« Queen », « Bohemian Rhapsody »]. @return array{0: string, 1: string}|null */
    public static function split(string $raw): ?array
    {
        $s = Text::entities($raw);
        $s = preg_replace(self::NOISE, '', $s) ?? $s;
        $s = preg_replace('/\s*[-_]\s*\d{2,3}\s*k(?:bps)?$/iu', '', $s) ?? $s; // « -320k »
        // Numéro de piste en tête (« 03. », « 03 ») ; pas « 50 Cent », ni « 3 Doors Down »
        $s = preg_replace('/^\s*(?:\d{1,3}\s*[.)_]|0\d)\s*(?=\S)/u', '', $s) ?? $s;
        $parts = preg_split(self::SEPARATOR, trim($s), 2);
        if ($parts === false || count($parts) !== 2) {
            return null;
        }
        // Pas trim() : il couperait octet par octet les caractères multi-octets (« – »)
        $edge = static fn (string $x) => Text::clean(preg_replace('/^[\s\-–—•|_]+|[\s\-–—•|_]+$/u', '', $x) ?? $x) ?? '';
        [$artist, $title] = [$edge($parts[0]), $edge($parts[1])];
        return $artist === '' || $title === '' ? null : [$artist, $title];
    }

    private static function key(string $s): string
    {
        return str_replace(' ', '', Text::key($s));
    }
}
