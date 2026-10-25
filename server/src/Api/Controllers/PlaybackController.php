<?php
// Lecture continue intelligente (LEC-03) : POST /playback/next.

declare(strict_types=1);

namespace Musique\Api\Controllers;

use Musique\Account\UserSettings;
use Musique\Api\Context;
use Musique\Catalog\Moods;
use Musique\Catalog\Serializer;
use Musique\Http\Input;
use Musique\Http\Response;
use Musique\Library\Genres;
use Musique\Playback\Continuous;

final class PlaybackController
{
    public function __construct(private readonly Context $ctx)
    {
    }

    public function nextTracks(array $params): Response
    {
        $uid = (int) $this->ctx->user()->userId;
        $in = new Input($this->ctx->request->json());
        $count = (int) ($in->int('count', 1, 50, false) ?? 10);
        $current = $in->int('currentTrackId', 1, PHP_INT_MAX, false, true);
        $recent = $in->intList('recentTrackIds', 200, false) ?? [];

        // Règles du compte, puis celles que le client remplace pour cette demande
        $raw = $this->ctx->db->value('SELECT settings FROM user_settings WHERE user_id = ?', [$uid]);
        $settings = UserSettings::withDefaults($raw === null ? [] : (array) json_decode((string) $raw, true));
        $rules = $settings['continuous'];
        if (($o = $in->object('overrides', false)) !== null) {
            if ($o->has('genres')) {
                $rules['genres'] = $o->intList('genres', 500);
            }
            if ($o->has('harmonicMixing')) {
                $rules['harmonicMixing'] = $o->bool('harmonicMixing');
            }
            if ($o->has('bpmTolerancePercent')) {
                $rules['bpmTolerancePercent'] = $o->int('bpmTolerancePercent', 0, 50, true, true);
            }
            if ($o->has('energyTrend')) {
                $rules['energyTrend'] = $o->enum('energyTrend', ['any', 'steady', 'rising', 'falling']);
            }
            if ($o->has('mood')) {
                $rules['mood'] = $o->enum('mood', array_keys(Moods::ALL));
            }
            foreach (['yearFrom', 'yearTo'] as $k) {
                if ($o->has($k)) {
                    $rules[$k] = $o->int($k, 0, 9999);
                }
            }
            if ($o->has('collection')) {
                $rules['collection'] = $o->int('collection', 1);
            }
        }
        // Un genre parent comprend ses sous-genres (CAT-08)
        $genres = new Genres($this->ctx->db);
        $rules['genres'] = $genres->withDescendants(array_map('intval', (array) $rules['genres']));
        $rules['excludedGenres'] = $genres->withDescendants(array_map('intval', (array) $rules['excludedGenres']));

        $ids = (new Continuous($this->ctx->db))->next($uid, $current, array_map('intval', $recent), $count, $rules);
        return Response::json(['items' => (new Serializer($this->ctx->db, $uid))->tracks($ids)]);
    }
}
