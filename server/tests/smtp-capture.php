<?php
// Faux serveur SMTP des tests : accepte les mails et écrit chacun dans un fichier JSON
// (expéditeur, destinataire, authentification, message brut). Une session à la fois.
//   php smtp-capture.php <port> <dossier> [--auth utilisateur:motdepasse] [--refuse-rcpt adresse]

declare(strict_types=1);

$port = (int) ($argv[1] ?? 0);
$dir = (string) ($argv[2] ?? '');
$opts = array_slice($argv, 3);
$auth = ($i = array_search('--auth', $opts, true)) !== false ? (string) $opts[$i + 1] : null;
$refuse = ($i = array_search('--refuse-rcpt', $opts, true)) !== false ? (string) $opts[$i + 1] : null;
if ($port === 0 || !is_dir($dir)) {
    fwrite(STDERR, "usage : smtp-capture.php <port> <dossier>\n");
    exit(2);
}
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr) ?: exit(1);
$n = 0;
while ($c = @stream_socket_accept($server, -1)) {
    $say = static fn (string $l) => fwrite($c, $l . "\r\n");
    $say('220 capture ESMTP');
    $mail = ['from' => null, 'to' => [], 'auth' => null, 'data' => ''];
    while (($line = fgets($c)) !== false) {
        $line = rtrim($line, "\r\n");
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') {
            fwrite($c, "250-capture\r\n" . ($auth !== null ? "250-AUTH PLAIN LOGIN\r\n" : '') . "250 8BITMIME\r\n");
        } elseif ($cmd === 'AUTH') {
            $given = base64_decode(substr($line, 11));
            [, $u, $p] = array_pad(explode("\0", $given), 3, '');
            $mail['auth'] = $u;
            $say("$u:$p" === $auth ? '235 ok' : '535 refusé');
        } elseif ($cmd === 'MAIL') {
            $mail['from'] = trim(substr($line, 10), '<>');
            $say('250 ok');
        } elseif ($cmd === 'RCPT') {
            $to = trim(substr($line, 8), '<>');
            if ($to === $refuse) {
                $say('550 boîte inconnue');
            } else {
                $mail['to'][] = $to;
                $say('250 ok');
            }
        } elseif ($cmd === 'DATA') {
            $say('354 envoyez');
            $data = '';
            while (($l = fgets($c)) !== false && $l !== ".\r\n") {
                $data .= str_starts_with($l, '..') ? substr($l, 1) : $l;
            }
            $mail['data'] = $data;
            file_put_contents(sprintf('%s/%04d.json', $dir, ++$n), json_encode($mail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $say('250 enregistré');
        } elseif ($cmd === 'QUIT') {
            $say('221 au revoir');
            break;
        } else {
            $say('250 ok');
        }
    }
    fclose($c);
}
