<?php
// Réglages de l'utilisateur (CPT-04), partagés entre le site et l'appli : valeurs par défaut et
// validation selon le schéma `UserSettings` du contrat. Les champs inconnus sont gardés tels quels
// (un client plus récent ne perd pas ses réglages à cause d'un plus ancien).

declare(strict_types=1);

namespace Musique\Account;

use Musique\Http\ApiError;
use Musique\Http\Input;

final class UserSettings
{
    public const MAX_BYTES = 65536;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'playback' => [
                'normalization' => 'auto',
                'targetLufs' => -16,
                'preventClipping' => true,
                'crossfadeMs' => 0,
                'crossfadeWithinAlbum' => false,
                'gapless' => true,
                'beatSync' => true,
                'streamQuality' => ['wifi' => 'original', 'mobile' => 'original'],
            ],
            'continuous' => [
                'enabled' => false,
                'genres' => [],
                'excludedGenres' => [],
                'favoritesWeight' => 0.3,
                'harmonicMixing' => false,
                'bpmTolerancePercent' => null,
                'energyTrend' => 'any',
                'noRepeatHours' => 24,
                'artistSpacing' => 3,
            ],
            'interface' => ['theme' => 'auto', 'background' => true],
            // Mode infini activé par défaut, comme sur l'ancien site
            'infinite' => ['enabled' => true],
            // Dernier tirage de la page « Aléatoire » (vide = tous les genres)
            'random' => ['genreIds' => [], 'excludedAlbumIds' => [], 'count' => 100],
        ];
    }

    /**
     * Complète des réglages enregistrés avec les valeurs par défaut des champs apparus depuis.
     * @param array<string, mixed> $stored
     * @return array<string, mixed>
     */
    public static function withDefaults(array $stored): array
    {
        return self::merge(self::defaults(), $stored);
    }

    private static function merge(array $defaults, array $values): array
    {
        foreach ($defaults as $k => $v) {
            if (!array_key_exists($k, $values)) {
                $values[$k] = $v;
            } elseif (is_array($v) && $v !== [] && !array_is_list($v) && is_array($values[$k])) {
                $values[$k] = self::merge($v, $values[$k]);
            }
        }
        return $values;
    }

    /**
     * Valide un document complet (PUT). Renvoie le document tel qu'il sera enregistré.
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function validate(array $data): array
    {
        if (strlen((string) json_encode($data)) > self::MAX_BYTES) {
            throw ApiError::badRequest('Réglages trop volumineux (64 Ko au plus)');
        }
        // Réglages apparus après coup : un client antérieur ne les envoie pas, ils gardent leur valeur
        foreach (['infinite', 'random'] as $k) {
            if (!array_key_exists($k, $data)) {
                $data[$k] = self::defaults()[$k];
            }
        }
        $in = new Input($data);
        $p = $in->object('playback');
        $p->enum('normalization', ['off', 'track', 'album', 'auto']);
        $p->number('targetLufs', -23, -8);
        $p->bool('preventClipping');
        $p->int('crossfadeMs', 0, 12000);
        $p->bool('crossfadeWithinAlbum');
        $p->bool('gapless');
        $p->bool('beatSync');
        $q = $p->object('streamQuality');
        $q->enum('wifi', ['original', 'high', 'medium', 'low']);
        $q->enum('mobile', ['original', 'high', 'medium', 'low']);

        $c = $in->object('continuous');
        $c->bool('enabled');
        $c->intList('genres', 500);
        $c->intList('excludedGenres', 500);
        $c->number('favoritesWeight', 0, 1);
        $c->bool('harmonicMixing');
        $c->int('bpmTolerancePercent', 0, 50, true, true);
        $c->enum('energyTrend', ['any', 'steady', 'rising', 'falling']);
        $c->int('noRepeatHours', 0, 720);
        $c->int('artistSpacing', 0, 20);

        $ui = $in->object('interface');
        $ui->enum('theme', ['auto', 'light', 'dark']);
        $ui->bool('background', false);
        // Client antérieur aux images de fond : la valeur par défaut
        $data['interface']['background'] ??= true;
        $in->object('infinite')->bool('enabled');
        $r = $in->object('random');
        $r->intList('genreIds', 500);
        $r->intList('excludedAlbumIds', 500);
        $r->int('count', 1, 200);
        return $data;
    }
}
