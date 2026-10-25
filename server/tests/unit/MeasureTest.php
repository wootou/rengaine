<?php
// Lecture de la sortie de ffmpeg (ebur128 + silencedetect) et volume d'album.

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Audio\Measure;

final class MeasureTest extends TestCase
{
    /** Sortie réelle de ffmpeg 8 : 1 s de silence, 2 s de son, 1,5 s de silence. */
    private const OUTPUT = <<<TXT
[silencedetect @ 0x767164002080] silence_start: 0
[silencedetect @ 0x767164002080] silence_end: 1.000023 | silence_duration: 1.000023
[silencedetect @ 0x767164002080] silence_start: 3
[Parsed_ebur128_0 @ 0x767164001bc0] Summary:

  Integrated loudness:
    I:         -10.3 LUFS
    Threshold: -20.5 LUFS

  Loudness range:
    LRA:         1.0 LU
    Threshold: -31.7 LUFS
    LRA low:   -12.4 LUFS
    LRA high:  -11.5 LUFS

  True peak:
    Peak:       -6.0 dBFS
[silencedetect @ 0x767164002080] silence_end: 4.5 | silence_duration: 1.5
TXT;

    public function testVolumeCreteEtSilences(): void
    {
        $r = Measure::parse(self::OUTPUT, 4500);
        $this->assertSame(-10.3, $r['loudness_lufs']);
        $this->assertSame(1.0, $r['loudness_range_lu']);
        $this->assertSame(-6.0, $r['true_peak_dbtp']);
        $this->assertSame(1000, $r['audio_start_ms']);
        $this->assertSame(3000, $r['audio_end_ms']);
        $this->assertSame(false, $r['silent']);
    }

    public function testSilenceFinalSansFinSignalee(): void
    {
        // Anciennes versions de ffmpeg : pas de silence_end à la fin du fichier
        $out = str_replace('silence_end: 4.5 | silence_duration: 1.5', '', self::OUTPUT);
        $r = Measure::parse($out, 4500);
        $this->assertSame(3000, $r['audio_end_ms']);
    }

    public function testSilenceAuMilieuIgnore(): void
    {
        $out = "silence_start: 60\nsilence_end: 70 | silence_duration: 10\n[x] Summary:\n I: -9.0 LUFS\n LRA: 3.0 LU\n Peak: -0.5 dBFS\n";
        $r = Measure::parse($out, 180000);
        $this->assertSame(0, $r['audio_start_ms']);
        $this->assertSame(180000, $r['audio_end_ms']);
    }

    public function testFichierMuet(): void
    {
        $out = "silence_start: 0\n[x] Summary:\n I: -70.0 LUFS\n LRA: 0.0 LU\n Peak: -inf dBFS\nsilence_end: 5 | silence_duration: 5\n";
        $r = Measure::parse($out, 5000);
        $this->assertSame(true, $r['silent']);
        $this->assertNull($r['loudness_lufs']);
        $this->assertNull($r['true_peak_dbtp']);
        $this->assertSame(0, $r['audio_start_ms']);
        $this->assertSame(5000, $r['audio_end_ms']);
    }

    public function testFormeDOnde(): void
    {
        // Un niveau par tranche (ametadata), « -inf » pour une tranche muette
        $out = "[Parsed_ametadata_3 @ 0x1] frame:0    pts:0       pts_time:0\n"
            . "[Parsed_ametadata_3 @ 0x1] lavfi.astats.Overall.RMS_level=-inf\n"
            . "[Parsed_ametadata_3 @ 0x1] lavfi.astats.Overall.RMS_level=-14.051665\n"
            . "[Parsed_ametadata_3 @ 0x1] lavfi.astats.Overall.RMS_level=0.2\n"
            . "[Parsed_ametadata_3 @ 0x1] lavfi.astats.Overall.RMS_level=-80\n" . self::OUTPUT;
        $r = Measure::parse($out, 4500);
        $this->assertSame([0, 199, 255, 0], array_values(unpack('C*', (string) $r['waveform'])));
        $this->assertSame([null, -14.0, 0.0, null], Measure::waveLevels((string) $r['waveform']));
        $this->assertNull(Measure::parse(self::OUTPUT, 4500)['waveform'], 'pas de niveaux : pas de forme d’onde');
        $many = str_repeat("lavfi.astats.Overall.RMS_level=-20\n", Measure::WAVE_MAX_POINTS + 1);
        $this->assertNull(Measure::parseWave($many), 'trop de tranches : durée de l’index fausse');

        // Tranches : environ 800 par piste, 50 ms au moins
        $this->assertSame(16538, Measure::waveWindow(300000, 44100));
        $this->assertSame(2205, Measure::waveWindow(4500, 44100));
        $this->assertSame(2400, Measure::waveWindow(0, 48000));
        $this->assertSame(375.0, Measure::waveStepMs(300000, 48000));
        $this->assertTrue(str_contains(implode(' ', Measure::command('/x.flac', 300000, 48000)), 'asetnsamples=n=18000:p=1'), 'taille des tranches dans la commande');
    }

    public function testTramesIllisibles(): void
    {
        $meta = static fn (float $t): string => "[Parsed_ametadata_3 @ 0x1] frame:1    pts:1       pts_time:$t\n[Parsed_ametadata_3 @ 0x1] lavfi.astats.Overall.RMS_level=-20\n";
        $out = "[mp3 @ 0x9] Estimating duration from bitrate, this may be inaccurate\n"
            . $meta(34.9)
            . "[flac @ 0x5] invalid subframe padding\n"
            . $meta(35.2)
            . "[flac @ 0x5] decode_frame() failed\n"
            . "[aist#0:0/flac @ 0x6] [dec:flac @ 0x7] Decoding error: Invalid data found when processing input\n"
            . $meta(128.3)
            . "[aist#0:0/flac @ 0x6] [dec:flac @ 0x7] Decoding error: Invalid data found when processing input\n"
            . $meta(192.9) // dans la dernière seconde et demie : fin coupée, ignorée
            . "[mp3float @ 0x8] Header missing\n"
            . "[aist#0:0/mp3 @ 0x6] [dec:mp3float @ 0x7] Error submitting packet to decoder: Invalid data found when processing input\n";
        $this->assertSame(['decode_errors' => 2, 'decode_detail' => 'à 0:35 et 2:08 : flac : invalid subframe padding'], Measure::decodeErrors($out, 193600));
        $r = Measure::parse($out . self::OUTPUT, 193600);
        $this->assertSame(2, $r['decode_errors']);
        // Sans raison du décodeur juste avant : celle de la ligne d'erreur
        $bare = $meta(10.0) . "[aist#0:0/mp3 @ 0x6] [dec:mp3float @ 0x7] Error submitting packet to decoder: Invalid data\n";
        $this->assertSame(['decode_errors' => 1, 'decode_detail' => 'à 0:10 : mp3float : Error submitting packet to decoder: Invalid data'], Measure::decodeErrors($bare, 200000));
        $this->assertSame(['decode_errors' => 0, 'decode_detail' => null], Measure::decodeErrors(self::OUTPUT, 4500));
    }

    public function testSortieSansResume(): void
    {
        $this->assertThrows(\RuntimeException::class, fn () => Measure::parse('Invalid data found', 1000));
    }

    public function testVolumeAlbumPondereParLaDuree(): void
    {
        $this->assertSame(-10.0, Measure::albumLoudness([['lufs' => -10.0, 'ms' => 1000], ['lufs' => -10.0, 'ms' => 3000]]));
        // Même énergie : une piste deux fois plus longue compte deux fois plus
        $mixed = Measure::albumLoudness([['lufs' => -8.0, 'ms' => 2000], ['lufs' => -20.0, 'ms' => 1000]]);
        $this->assertTrue($mixed > -10.0 && $mixed < -9.0, "attendu entre -10 et -9, obtenu $mixed");
        $this->assertNull(Measure::albumLoudness([]));
    }
}
