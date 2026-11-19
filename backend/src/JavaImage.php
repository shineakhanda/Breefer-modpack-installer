<?php

namespace Breefer\ModpackInstaller;

use App\Models\Server;
use App\Repositories\Agent\DaemonFileRepository;
use Breefer\ModpackInstaller\Support\Plugin;
use Illuminate\Support\Facades\Log;

/**
 * Picks the docker image whose Java version matches the installed modpack's Minecraft version.
 */
final class JavaImage
{
    /** Java version required by a Minecraft version ("1.21.1", "1.16.5", "26.1"). */
    public static function forMinecraft(string $mc): ?int
    {
        if (preg_match('/^(\d+)\.(\d+)(?:\.(\d+))?/', trim($mc), $m) !== 1) {
            return null;
        }
        [$major, $minor, $patch] = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
        if ($major >= 26) {
            return 25; // year-based releases
        }
        if ($major !== 1) {
            return null;
        }

        return match (true) {
            $minor > 20 || ($minor === 20 && $patch >= 5) => 21,
            $minor >= 18 => 17,
            $minor === 17 => 16,
            default => 8,
        };
    }

    public static function apply(Server $server): void
    {
        if (! Plugin::bool('auto_java', true)) {
            return;
        }

        try {
            $raw = app(DaemonFileRepository::class)->setServer($server)->getContent('.breefer-modpack.json', 64 * 1024);
        } catch (\Throwable) {
            return;
        }
        $info = json_decode($raw, true);
        $java = self::forMinecraft((string) ($info['minecraft'] ?? ''));
        if ($java === null) {
            return;
        }

        $server->loadMissing('egg');
        $images = array_values((array) ($server->egg->docker_images ?? []));
        $byJava = [];
        foreach ($images as $image) {
            $tag = (string) (explode(':', (string) $image)[1] ?? '');
            if (preg_match('/(?:java|jdk|jre)[_\-]?(\d+)/i', $tag, $m) === 1) {
                $byJava[(int) $m[1]] ??= (string) $image;
            }
        }
        if (! $byJava) {
            return;
        }
        ksort($byJava);
        // Exact match, otherwise the closest newer Java, otherwise the newest available.
        $pick = $byJava[$java] ?? null;
        if ($pick === null) {
            foreach ($byJava as $version => $image) {
                if ($version > $java) {
                    $pick = $image;
                    break;
                }
            }
        }
        $pick ??= end($byJava);

        if ($pick !== $server->image) {
            $server->forceFill(['image' => $pick])->save();
            Log::info('[Breefer] Switched Java image for the installed modpack.', ['server' => $server->uuid, 'minecraft' => $info['minecraft'] ?? null, 'image' => $pick]);
        }
    }
}
