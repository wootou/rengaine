<?php
// Bibliothèque de démonstration entièrement fictive (captures d'écran, essais, serveur de démo) :
// commande `rengaine demo:generer <dossier>`.
//
// Artistes, albums, titres et pochettes sont inventés ; la musique est synthétisée par ffmpeg. Trois
// profils d'énergie, réglés sur la mesure de l'analyse (volume, attaques par seconde, brillance) pour que
// chaque ambiance ait des pistes : calme (nappe douce), moyen (arpèges, sans batterie), énergique
// (batterie, charleston, son fort et brillant). Aucune œuvre existante n'est utilisée : rien à créditer.
// Les albums sont écrits dans <dossier>/inbox/ ; le rangement du dépôt les classe ensuite. Il faut ffmpeg
// et ImageMagick (convert). Relancer ne refait que ce qui manque.

declare(strict_types=1);

namespace Musique\Demo;

final class LibraryGenerator
{
    /** artiste, album, année, genre, format (mp3, flac, both), palette, style de pochette, énergie 0-1, tempo, titres */
    private const ALBUMS = [
        ['Les Nuits Ocres', 'Boulevard des Lucioles', 1978, 'Chanson française', 'mp3', ['#e8a33d', '#5b2a1c'], 'soleil', 0.45, 96,
            ['Boulevard des Lucioles', 'La Dame du Quai', 'Un Été à Collioure', 'Pluie de Juillet', 'Les Rues de Sète', 'Valse pour Odile', 'Minuit Passé', 'Retour au Port']],
        ['Ondine Kovač Trio', 'Midnight at Saint-Roch', 1962, 'Jazz', 'flac', ['#1f3b5c', '#d9c7a1'], 'cercles', 0.45, 118,
            ['Saint-Roch Blues', 'Blue Lantern', 'Cobblestone Waltz', 'Late Train', 'Velvet Steps', 'Rue Oberkampf', 'Last Call']],
        ['Velvet Harbour', 'Low Tide Radio', 1984, 'Soul', 'mp3', ['#8e2c48', '#f2b880'], 'bandes', 0.65, 102,
            ['Low Tide Radio', 'Silver Coast', 'Call Me Sunday', 'Harbour Lights', 'Slow Burn', 'Ocean Avenue', 'Golden Hour', 'Goodnight Pier']],
        ['Riviera 77', 'Highway Postcards', 1989, 'Rock', 'mp3', ['#ff5f6d', '#2b1055'], 'horizon', 0.85, 128,
            ['Highway Postcards', 'Neon Motel', 'Desert Run', 'Chrome Hearts', 'Midnight Exit', 'Sunset Strip', 'Rear View']],
        ['Orchestre de Chambre de Vaurelle', 'Quatre Saisons du Sud', 2014, 'Classique', 'flac', ['#f4ecd8', '#6b4f2a'], 'vagues', 0.2, 72,
            ['Printemps : Allegro', 'Printemps : Largo', 'Été : Presto', 'Été : Adagio', 'Automne : Allegro', 'Automne : Andante', 'Hiver : Largo', 'Hiver : Finale']],
        ['Marin Ferrand', 'Cartes postales', 2021, 'Folk', 'mp3', ['#7fb069', '#fff8e7'], 'collines', 0.3, 88,
            ['Cartes postales', 'Le Phare', 'Sous les Tilleuls', 'La Route de Briançon', 'Ma Petite Ville', 'Les Hirondelles', 'Avant l’Hiver']],
        ['Neon Cassis', 'Tropical Static', 2022, 'Électro', 'flac', ['#00d2ff', '#ff2e93'], 'grille', 0.9, 124,
            ['Tropical Static', 'Palm Circuit', 'Laser Lagoon', 'Night Ferry', 'Coral Bass', 'Afterglow', 'Sunrise Protocol']],
        ['Kimi Asano', 'Paper Lanterns', 2023, 'Ambient', 'mp3', ['#2e2a47', '#f6c177'], 'lanternes', 0.1, 64,
            ['Paper Lanterns', 'Fog over Kamo', 'Quiet Garden', 'Moss', 'Evening Bell', 'Still Water']],
        ['Les Frères Caillou', 'Soleil de minuit', 2017, 'Reggae', 'mp3', ['#2f9e44', '#ffd43b'], 'soleil', 0.6, 76,
            ['Soleil de minuit', 'Riddim des Calanques', 'Île Bleue', 'Doucement', 'Marché du Samedi', 'Sur la Plage', 'Lune Rousse']],
        ['MC Phosphore', 'Lumière noire', 2021, 'Hip-hop', 'mp3', ['#111111', '#b6ff3b'], 'bandes', 0.75, 90,
            ['Lumière noire', 'Quartier Nord', 'Encre et Bitume', 'Dernier Métro', 'Néons', 'Écho', 'Phosphore']],
        ['Aurore Delmas', 'Les heures claires', 2024, 'Pop', 'both', ['#ffb3c6', '#7b2cbf'], 'cercles', 0.7, 112,
            ['Les heures claires', 'Mille Fenêtres', 'Cœur Atlantique', 'Dimanche', 'Lumière Douce', 'On Se Retrouve', 'Jusqu’au Matin', 'Envolée']],
        ['Stellar Drift Ensemble', 'Orbital Lullabies', 2015, 'Musique de film', 'mp3', ['#0b1d51', '#8aa1ff'], 'orbites', 0.4, 80,
            ['Opening Titles', 'Orbital Lullaby', 'The Long Night', 'Aurora Station', 'Drifting', 'Homecoming', 'End Credits']],
        ['Grand Large', 'Marée haute', 2020, 'Rock', 'mp3', ['#023e8a', '#ade8f4'], 'vagues', 0.85, 136,
            ['Marée haute', 'Cap au Nord', 'Les Mouettes', 'Tempête', 'Port d’Attache', 'Vent Debout', 'Dernier Quart']],
    ];
    /** Une compilation : chaque piste a son artiste (rangée dans Compilations/) */
    private const COMPILATION = ['Été indien', 2022, 'Pop', ['#f77f00', '#fcbf49'], 'soleil', 0.65, 104, [
        ['Aurore Delmas', 'Été indien'], ['Les Frères Caillou', 'Plage Abandonnée'], ['Marin Ferrand', 'Septembre'],
        ['Velvet Harbour', 'Indian Summer'], ['Neon Cassis', 'Heatwave'], ['Les Nuits Ocres', 'Dernier Bain'],
    ]];
    private const PROGRESSIONS = [[0, 5, 3, 4], [0, 4, 5, 3], [0, 3, 4, 4], [5, 3, 0, 4]];
    private const MAJOR = [0, 2, 4, 5, 7, 9, 11];
    private const MINOR = [0, 2, 3, 5, 7, 8, 10];

    /** @param callable(string): void $say */
    public function __construct(private readonly string $dir, private readonly int $processes, private $say)
    {
    }

    /** Produit ce qui manque ; renvoie le nombre de pistes créées. */
    public function run(): int
    {
        $inbox = rtrim($this->dir, '/') . '/inbox';
        $jobs = [];
        foreach (self::ALBUMS as $n => [$artist, $album, $year, $genre, $fmt, $palette, $style, $energy, $bpm, $titles]) {
            mt_srand(1000 + $n);
            $folder = "$inbox/$artist - $album";
            @mkdir($folder, 0775, true);
            $this->cover("$folder/cover.jpg", $album, $artist, $palette, $style, $n);
            $minor = mt_rand(0, 9) < 4;
            foreach ($titles as $i => $title) {
                $seconds = mt_rand(150, 275);
                $e = max(0.05, min(1.0, $energy + mt_rand(-12, 12) / 100));
                $tags = ['title' => $title, 'artist' => $artist, 'album_artist' => $artist, 'album' => $album, 'date' => (string) $year,
                    'genre' => $genre, 'track' => ($i + 1) . '/' . count($titles)];
                $formats = $fmt === 'both' ? ['mp3', 'flac'] : [$fmt];
                foreach ($formats as $f) {
                    $sub = count($formats) === 1 ? $folder : "$folder/$f";
                    @mkdir($sub, 0775, true);
                    $file = sprintf('%s/%02d - %s.%s', $sub, $i + 1, strtr($title, ['/' => '-', ':' => ' -']), $f);
                    $jobs[] = [$file, $tags, 10_000 + $n * 100 + $i, $e, $bpm, $minor, $seconds, $f];
                }
            }
        }
        [$name, $year, $genre, $palette, $style, $energy, $bpm, $tracks] = self::COMPILATION;
        $folder = "$inbox/Various Artists - $name";
        @mkdir($folder, 0775, true);
        $this->cover("$folder/cover.jpg", $name, 'Compilation', $palette, $style, 99);
        foreach ($tracks as $i => [$artist, $title]) {
            mt_srand(20_000 + $i);
            $tags = ['title' => $title, 'artist' => $artist, 'album_artist' => 'Various Artists', 'album' => $name, 'compilation' => '1',
                'date' => (string) $year, 'genre' => $genre, 'track' => ($i + 1) . '/' . count($tracks)];
            $jobs[] = [sprintf('%s/%02d - %s - %s.mp3', $folder, $i + 1, $artist, $title), $tags, 20_000 + $i,
                $energy + mt_rand(-10, 10) / 100, $bpm, false, mt_rand(160, 240), 'mp3'];
        }
        $todo = array_values(array_filter($jobs, static fn ($j) => !is_file($j[0]) || filesize($j[0]) === 0));
        ($this->say)(count($jobs) . ' pistes, ' . count($todo) . ' à produire');
        $this->renderAll($todo);
        return count($todo);
    }

    /** Lance les conversions ffmpeg par lots de N processus. @param list<array> $jobs */
    private function renderAll(array $jobs): void
    {
        $running = [];
        $done = 0;
        while ($jobs !== [] || $running !== []) {
            while ($jobs !== [] && count($running) < max(1, $this->processes)) {
                $job = array_shift($jobs);
                $tmp = $job[0] . '.part.' . $job[7];
                $proc = proc_open($this->command($tmp, ...array_slice($job, 1)), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
                $running[] = [$proc, $pipes[2], $tmp, $job[0]];
            }
            foreach ($running as $k => [$proc, $err, $tmp, $dest]) {
                if (proc_get_status($proc)['running']) {
                    continue;
                }
                $msg = stream_get_contents($err);
                $code = proc_close($proc);
                if ($code !== 0) {
                    throw new \RuntimeException("ffmpeg : $dest : " . trim((string) $msg));
                }
                rename($tmp, $dest);
                unset($running[$k]);
                if (++$done % 10 === 0) {
                    ($this->say)("$done pistes");
                }
            }
            usleep(50_000);
        }
    }

    /** Commande ffmpeg d'une piste. @param array<string, string> $tags @return list<string> */
    private function command(string $out, array $tags, int $seed, float $energy, float $bpm, bool $minor, int $seconds, string $fmt): array
    {
        mt_srand($seed);
        $expr = $this->expression($energy, $bpm * mt_rand(97, 103) / 100, $minor);
        $fade = min(6, $seconds / 10);
        // Dynamique selon l'énergie : l'analyse mesure le volume moyen, une piste énergique doit être tassée et forte
        $dynamics = $energy >= 0.6 ? 'acompressor=threshold=0.03:ratio=10:attack=3:release=50:makeup=10,volume=9dB,alimiter=limit=0.93:attack=1:release=20'
            : ($energy >= 0.35 ? 'acompressor=threshold=0.05:ratio=4:attack=10:release=120:makeup=4,volume=5dB,alimiter=limit=0.93' : 'volume=-4dB,alimiter=limit=0.9');
        $cmd = ['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', "aevalsrc='$expr':s=22050:d=$seconds",
            '-af', "afade=t=in:d=1.5,afade=t=out:st=" . ($seconds - $fade) . ":d=$fade,$dynamics,aformat=channel_layouts=stereo"];
        foreach ($tags as $k => $v) {
            array_push($cmd, '-metadata', "$k=$v");
        }
        array_push($cmd, ...($fmt === 'flac' ? ['-c:a', 'flac', '-ar', '44100', '-f', 'flac'] : ['-c:a', 'libmp3lame', '-b:a', '160k', '-ar', '44100', '-f', 'mp3']));
        $cmd[] = $out;
        return $cmd;
    }

    /** Expression aevalsrc : nappe, basse, arpège, et batterie selon l'énergie. */
    private function expression(float $energy, float $bpm, bool $minor): string
    {
        $root = 110 * 2 ** (mt_rand(0, 11) / 12);
        $prog = self::PROGRESSIONS[mt_rand(0, 3)];
        $scale = $minor ? self::MINOR : self::MAJOR;
        $chords = array_map(static function (int $deg) use ($root, $scale): array {
            return array_map(static fn ($i) => $root * 2 ** (($scale[($deg + $i) % 7] + 12 * intdiv($deg + $i, 7)) / 12), [0, 2, 4]);
        }, $prog);
        $b = 60 / $bpm;
        $bar = 4 * $b;
        $sel = static fn (array $v): string => sprintf('if(lt(mod(t,%1$.4f),%2$.4f),%4$.2f,if(lt(mod(t,%1$.4f),%3$.4f),%5$.2f,if(lt(mod(t,%1$.4f),%6$.4f),%7$.2f,%8$.2f)))',
            4 * $bar, $bar, 2 * $bar, $v[0], $v[1], 3 * $bar, $v[2], $v[3]);
        $f = static fn (float $x): string => sprintf('%.4f', $x);
        $parts = [];
        // Nappe : trois notes de l'accord, avec un léger trémolo
        $pad = [];
        foreach ([0, 1, 2] as $i) {
            $pad[] = sprintf('%.3f*sin(2*PI*%s*t)', 0.22 / ($i + 1), $sel(array_map(static fn ($c) => $c[$i] * 2, $chords)));
        }
        $parts[] = '(' . implode('+', $pad) . sprintf(')*(0.75+0.25*sin(2*PI*%.2f*t))', 0.1 + $energy * 0.4);
        // Basse sur chaque temps
        $parts[] = sprintf('%.2f*sin(2*PI*%s*t)*exp(-%.1f*mod(t,%s))', 0.15 + $energy * 0.2, $sel(array_map(static fn ($c) => $c[0] / 2, $chords)), 2 + $energy * 6, $f($b));
        if ($energy >= 0.35) {
            // Arpège : une note de l'accord par demi-temps (des attaques, et du brillant)
            $step = $b / 2;
            $note = [];
            foreach ([0, 1, 2] as $i) {
                $note[$i] = $sel(array_map(static fn ($c) => $c[$i] * 4, $chords));
            }
            $arp = sprintf('if(lt(mod(floor(t/%1$s),3),1),%2$s,if(lt(mod(floor(t/%1$s),3),2),%3$s,%4$s))', $f($step), $note[0], $note[1], $note[2]);
            $parts[] = sprintf('%.2f*sin(2*PI*%s*t)*exp(-9*mod(t,%s))', 0.12 + $energy * 0.1, $arp, $f($step));
        }
        if ($energy >= 0.6) {
            // Grosse caisse sur chaque temps, charleston en doubles croches, caisse claire sur 2 et 4
            $parts[] = sprintf('0.8*sin(2*PI*(45+90*exp(-25*mod(t,%1$s)))*mod(t,%1$s))*exp(-12*mod(t,%1$s))', $f($b));
            $parts[] = sprintf('%.2f*(random(0)*2-1)*exp(-90*mod(t,%s))', 0.12 + ($energy - 0.6) * 0.3, $f($b / 4));
            $parts[] = sprintf('0.35*(random(1)*2-1)*exp(-22*mod(t+%s,%s))', $f($b), $f(2 * $b));
        }
        return implode('+', $parts);
    }

    /** Pochette abstraite 1200×1200, titre et artiste en bas. @param array{0: string, 1: string} $palette */
    private function cover(string $path, string $title, string $artist, array $palette, string $style, int $seed): void
    {
        if (is_file($path)) {
            return;
        }
        mt_srand($seed);
        [$c1, $c2] = $palette;
        $draws = [];
        switch ($style) {
            case 'soleil':
                $draws[] = "fill $c2 circle 600,560 600," . (560 - mt_rand(220, 300));
                for ($i = 0; $i < 12; $i += 2) {
                    $draws[] = "stroke $c2 stroke-width 10 fill none line 0," . (780 + 26 * $i) . ' 1200,' . (780 + 26 * $i);
                }
                break;
            case 'cercles':
                for ($i = 0; $i < 9; $i++) {
                    $draws[] = sprintf('stroke %s stroke-width %d fill none circle %d,%d %d,%d', $c2, mt_rand(6, 18), mt_rand(200, 1000), mt_rand(200, 900), mt_rand(200, 1000), mt_rand(200, 900));
                }
                break;
            case 'bandes':
                for ($x = 200; $x < 1900; $x += 260) {
                    $draws[] = sprintf('fill %s polygon %d,0 %d,0 %d,1200 %d,1200', $c2, $x, $x + 90, $x - 500, $x - 590);
                }
                break;
            case 'horizon':
                for ($i = 0; $i < 14; $i++) {
                    $y = 620 + intdiv(18 * $i * $i, 4);
                    $draws[] = sprintf('fill %s rectangle 0,%d 1200,%d', $c2, $y, $y + 10 + $i * 2);
                }
                $draws[] = "fill $c1 circle 600,520 600,330";
                break;
            case 'vagues':
            case 'collines':
                $rows = $style === 'vagues' ? 10 : 4;
                for ($i = 0; $i < $rows; $i++) {
                    $y = $style === 'vagues' ? 260 + $i * 70 : 520 + $i * 120;
                    $pts = [];
                    for ($x = 0; $x <= 1200; $x += 40) {
                        $pts[] = $x . ',' . ($y + (int) round(($style === 'vagues' ? 40 * sin($x / 120 + $i) : 60 * sin($x / (180 + 40 * $i) + $i * 1.7))));
                    }
                    $draws[] = $style === 'vagues'
                        ? "stroke $c2 stroke-width 8 fill none polyline " . implode(' ', $pts)
                        : 'fill ' . ($i % 2 ? $c2 : $c1) . ' fill-opacity 0.55 polygon ' . implode(' ', $pts) . ' 1200,1200 0,1200';
                }
                break;
            case 'grille':
                for ($x = 0; $x <= 1200; $x += 100) {
                    $draws[] = "stroke $c2 stroke-width 4 line $x,0 $x,1200";
                    $draws[] = "stroke $c2 stroke-width 4 line 0,$x 1200,$x";
                }
                for ($i = 0; $i < 10; $i++) {
                    $x = mt_rand(0, 11) * 100;
                    $y = mt_rand(0, 11) * 100;
                    $draws[] = sprintf('fill %s rectangle %d,%d %d,%d', $c2, $x, $y, $x + 100, $y + 100);
                }
                break;
            case 'lanternes':
                for ($i = 0; $i < 7; $i++) {
                    $x = mt_rand(80, 1000);
                    $y = mt_rand(80, 760);
                    $draws[] = sprintf('fill %s roundrectangle %d,%d %d,%d 40,40', $c2, $x, $y, $x + 110, $y + 160);
                }
                break;
            case 'orbites':
                for ($r = 120; $r < 640; $r += 70) {
                    $draws[] = sprintf('stroke %s stroke-width 3 fill none ellipse 600,560 %d,%d 0,360', $c2, $r, (int) ($r * 0.45));
                }
                $draws[] = "fill $c2 circle 600,560 600,500";
                break;
        }
        $cmd = ['convert', '-size', '1200x1200', "plasma:$c1-$c1", '-blur', '0x40', '-fill', $c1, '-colorize', '35%'];
        foreach ($draws as $d) {
            array_push($cmd, '-draw', $d);
        }
        array_push($cmd, '-blur', '0x1.2', '-fill', '#00000088', '-draw', 'rectangle 0,1010 1200,1200',
            '-font', 'DejaVu-Sans-Bold', '-pointsize', '58', '-fill', '#ffffff', '-gravity', 'southwest', '-annotate', '+60+98', $title,
            '-font', 'DejaVu-Sans', '-pointsize', '38', '-fill', '#ffffffcc', '-annotate', '+60+44', $artist, '-quality', '88', $path);
        $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']], $pipes);
        $err = stream_get_contents($pipes[2]);
        if (proc_close($proc) !== 0) {
            throw new \RuntimeException("convert : $path : " . trim((string) $err));
        }
    }
}
