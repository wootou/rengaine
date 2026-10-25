<?php
// Dernier état connu du lecteur de chaque compte (FUT-02), envoyé par le lecteur tant qu'un lien de
// télécommande est valable. L'appareil qui joue est celui qui reçoit les commandes des invités :
// un autre appareil en pause ne le lui reprend pas tant que le premier donne des nouvelles.

declare(strict_types=1);

namespace Musique\Playback;

use Musique\Api\Format;
use Musique\Db\Database;

final class PlayerStates
{
    /** Sans nouvelles depuis plus longtemps, le lecteur est considéré comme éteint (il en donne toutes les 30 s). */
    public const ONLINE_SECONDS = 75;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array{deviceKey: string, trackId: ?int, playing: bool, positionMs: int, queueVersion: int, volume?: ?int} $r
     * @return bool true si ce rapport a été retenu (l'appareil est celui qui reçoit les commandes)
     */
    public function report(int $userId, array $r): bool
    {
        return $this->db->transaction(function () use ($userId, $r): bool {
            $cur = $this->db->one(
                'SELECT device_key, playing, TIMESTAMPDIFF(SECOND, reported_at, CURRENT_TIMESTAMP(3)) AS age FROM player_states WHERE user_id = ? FOR UPDATE',
                [$userId]
            );
            if ($cur !== null && $cur['device_key'] !== $r['deviceKey'] && !$r['playing']
                && (bool) $cur['playing'] && (int) $cur['age'] <= self::ONLINE_SECONDS) {
                return false; // un autre appareil joue : celui-ci, en pause, ne prend pas la main
            }
            $this->db->run(
                'INSERT INTO player_states (user_id, device_key, track_id, playing, position_ms, volume, queue_version, reported_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP(3))
                 ON DUPLICATE KEY UPDATE device_key = VALUES(device_key), track_id = VALUES(track_id), playing = VALUES(playing),
                    position_ms = VALUES(position_ms), volume = VALUES(volume), queue_version = VALUES(queue_version), reported_at = VALUES(reported_at)',
                [$userId, $r['deviceKey'], $r['trackId'], (int) $r['playing'], $r['positionMs'], $r['volume'] ?? null, $r['queueVersion']]
            );
            return true;
        });
    }

    /** @return array{online: bool, playing: bool, trackId: ?int, positionMs: int, volume: ?int, reportedAt: ?string, deviceKey: ?string} */
    public function get(int $userId): array
    {
        $row = $this->db->one(
            'SELECT device_key, track_id, playing, position_ms, volume, reported_at, TIMESTAMPDIFF(SECOND, reported_at, CURRENT_TIMESTAMP(3)) AS age
               FROM player_states WHERE user_id = ?', [$userId]
        );
        if ($row === null) {
            return ['online' => false, 'playing' => false, 'trackId' => null, 'positionMs' => 0, 'volume' => null, 'reportedAt' => null, 'deviceKey' => null];
        }
        $online = (int) $row['age'] <= self::ONLINE_SECONDS;
        return [
            'online' => $online,
            'playing' => $online && (bool) $row['playing'],
            'trackId' => Format::int($row['track_id']),
            'positionMs' => (int) $row['position_ms'],
            'volume' => Format::int($row['volume']),
            'reportedAt' => Format::date($row['reported_at']),
            'deviceKey' => $online ? (string) $row['device_key'] : null,
        ];
    }
}
