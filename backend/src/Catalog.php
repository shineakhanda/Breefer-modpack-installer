<?php

namespace Breefer\ModpackInstaller;

use Breefer\ModpackInstaller\Support\Plugin;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Normalised modpack catalog across Modrinth, CurseForge and Feed The Beast.
 * Pack:    {provider,id,title,description,icon,author,downloads,updated,loaders,game_versions,url}
 * Version: {id,name,type,game_versions,loaders,date,java}
 */
final class Catalog
{
    public const CF_CLASS_MODPACKS = 4471;

    public static function providers(): array
    {
        $list = [];
        foreach (['modrinth', 'curseforge', 'ftb'] as $p) {
            if (! Plugin::bool('enable_'.$p, true)) {
                continue;
            }
            if ($p === 'curseforge' && self::cfKey() === '') {
                continue;
            }
            $list[] = $p;
        }

        return $list;
    }

    public static function cfKey(): string
    {
        return trim((string) Plugin::setting('curseforge_api_key', ''));
    }

    private static function http(string $base): PendingRequest
    {
        return Http::withUserAgent(Plugin::userAgent())->baseUrl($base)->acceptJson()->timeout(15)->retry(2, 250, throw: false);
    }

    private static function cf(): PendingRequest
    {
        return self::http('https://api.curseforge.com/v1/')->withHeaders(['x-api-key' => self::cfKey()]);
    }

    /** @return array{items: array<int, array<string, mixed>>, total: int} */
    public static function search(string $provider, string $query, ?string $loader, ?string $version, string $sort, int $page, int $perPage): array
    {
        return match ($provider) {
            'curseforge' => self::searchCurseForge($query, $loader, $version, $sort, $page, $perPage),
            'ftb' => self::searchFtb($query, $loader, $version, $page, $perPage),
            default => self::searchModrinth($query, $loader, $version, $sort, $page, $perPage),
        };
    }

    /** @return array<int, array<string, mixed>> */
    public static function versions(string $provider, string $id): array
    {
        return match ($provider) {
            'curseforge' => self::cfVersions($id),
            'ftb' => self::ftbVersions($id),
            default => self::mrVersions($id),
        };
    }

    /* ------------------------------------------------------------ Modrinth */

    private static function searchModrinth(string $query, ?string $loader, ?string $version, string $sort, int $page, int $perPage): array
    {
        $facets = [['project_type:modpack'], ['server_side:required', 'server_side:optional']];
        if ($loader) {
            $facets[] = ['categories:'.$loader];
        }
        if ($version) {
            $facets[] = ['versions:'.$version];
        }
        $params = [
            'query' => $query,
            'facets' => json_encode($facets),
            'index' => ['relevance' => 'relevance', 'downloads' => 'downloads', 'updated' => 'updated', 'newest' => 'newest'][$sort] ?? 'relevance',
            'offset' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ];

        $data = Cache::remember('breefer:mp:mr:'.md5(json_encode($params)), 300, function () use ($params) {
            $r = self::http('https://api.modrinth.com/v2/')->get('search', $params);
            if (! $r->successful()) {
                throw new \RuntimeException('Modrinth search failed.');
            }

            return $r->json();
        });

        return [
            'items' => array_map(fn ($h) => [
                'provider' => 'modrinth',
                'id' => $h['project_id'],
                'title' => $h['title'],
                'description' => $h['description'] ?? '',
                'icon' => $h['icon_url'] ?: null,
                'author' => $h['author'] ?? null,
                'downloads' => (int) ($h['downloads'] ?? 0),
                'updated' => $h['date_modified'] ?? null,
                'loaders' => array_values(array_intersect($h['categories'] ?? [], ['fabric', 'forge', 'neoforge', 'quilt'])),
                'game_versions' => array_slice(array_reverse($h['versions'] ?? []), 0, 3),
                'url' => 'https://modrinth.com/modpack/'.$h['slug'],
            ], $data['hits'] ?? []),
            'total' => (int) ($data['total_hits'] ?? 0),
        ];
    }

    private static function mrVersions(string $id): array
    {
        $r = self::http('https://api.modrinth.com/v2/')->get('project/'.rawurlencode($id).'/version');
        if (! $r->successful()) {
            throw new \RuntimeException('Could not load versions from Modrinth.');
        }

        return array_map(fn ($v) => [
            'id' => $v['id'],
            'name' => $v['name'] ?? $v['version_number'],
            'type' => $v['version_type'] ?? 'release',
            'game_versions' => $v['game_versions'] ?? [],
            'loaders' => $v['loaders'] ?? [],
            'date' => $v['date_published'] ?? null,
            'java' => null,
        ], $r->json() ?? []);
    }

    /* ---------------------------------------------------------- CurseForge */

    private static function searchCurseForge(string $query, ?string $loader, ?string $version, string $sort, int $page, int $perPage): array
    {
        $params = [
            'gameId' => 432,
            'classId' => self::CF_CLASS_MODPACKS,
            'searchFilter' => $query,
            'sortField' => ['relevance' => 2, 'downloads' => 6, 'updated' => 3, 'newest' => 11][$sort] ?? 2,
            'sortOrder' => 'desc',
            'index' => min(9950, ($page - 1) * $perPage),
            'pageSize' => $perPage,
        ];
        if ($version) {
            $params['gameVersion'] = $version;
        }
        if ($loader && ($type = ['forge' => 1, 'fabric' => 4, 'quilt' => 5, 'neoforge' => 6][$loader] ?? null)) {
            $params['modLoaderType'] = $type;
        }

        $data = Cache::remember('breefer:mp:cf:'.md5(json_encode($params)), 300, function () use ($params) {
            $r = self::cf()->get('mods/search', $params);
            if (! $r->successful()) {
                throw new \RuntimeException('CurseForge search failed (HTTP '.$r->status().'). Check the API key.');
            }

            return $r->json();
        });

        return [
            'items' => array_map(function ($m) {
                $latest = $m['latestFilesIndexes'] ?? [];

                return [
                    'provider' => 'curseforge',
                    'id' => (string) $m['id'],
                    'title' => $m['name'],
                    'description' => $m['summary'] ?? '',
                    'icon' => $m['logo']['thumbnailUrl'] ?? null,
                    'author' => $m['authors'][0]['name'] ?? null,
                    'downloads' => (int) ($m['downloadCount'] ?? 0),
                    'updated' => $m['dateModified'] ?? null,
                    'loaders' => array_values(array_unique(array_filter(array_map(fn ($l) => [1 => 'forge', 4 => 'fabric', 5 => 'quilt', 6 => 'neoforge'][$l['modLoader'] ?? 0] ?? null, $latest)))),
                    'game_versions' => array_values(array_slice(array_unique(array_map(fn ($l) => $l['gameVersion'], $latest)), 0, 3)),
                    'url' => $m['links']['websiteUrl'] ?? null,
                ];
            }, $data['data'] ?? []),
            'total' => min(10000, (int) ($data['pagination']['totalCount'] ?? 0)),
        ];
    }

    private static function cfVersions(string $id): array
    {
        $r = self::cf()->get('mods/'.(int) $id.'/files', ['pageSize' => 50]);
        if (! $r->successful()) {
            throw new \RuntimeException('Could not load files from CurseForge.');
        }

        return array_map(function ($f) {
            $game = array_values(array_filter($f['gameVersions'] ?? [], fn ($v) => preg_match('/^\d+\.\d+/', $v)));
            $loaders = array_values(array_map('strtolower', array_filter($f['gameVersions'] ?? [], fn ($v) => in_array(strtolower($v), ['forge', 'fabric', 'quilt', 'neoforge'], true))));

            return [
                'id' => (string) $f['id'],
                'name' => $f['displayName'] ?? $f['fileName'],
                'type' => [1 => 'release', 2 => 'beta', 3 => 'alpha'][$f['releaseType'] ?? 1] ?? 'release',
                'game_versions' => $game,
                'loaders' => $loaders,
                'date' => $f['fileDate'] ?? null,
                'java' => null,
                'server_pack' => ! empty($f['serverPackFileId']),
            ];
        }, array_values(array_filter($r->json('data', []), fn ($f) => ($f['isServerPack'] ?? false) === false)));
    }

    /* ----------------------------------------------------------------- FTB */

    private static function ftbPack(int $id): ?array
    {
        return Cache::remember('breefer:mp:ftb:pack:'.$id, 3600, function () use ($id) {
            $r = self::http('https://api.feed-the-beast.com/v1/modpacks/public/modpack/')->get((string) $id);

            return $r->successful() && ($r->json('status') === 'success') ? $r->json() : null;
        });
    }

    private static function searchFtb(string $query, ?string $loader, ?string $version, int $page, int $perPage): array
    {
        $ids = Cache::remember('breefer:mp:ftb:ids:'.md5($query), 600, function () use ($query) {
            $r = $query === ''
                ? self::http('https://api.feed-the-beast.com/v1/modpacks/public/modpack/')->get('all')
                : self::http('https://api.feed-the-beast.com/v1/modpacks/public/modpack/')->get('search/50', ['term' => $query]);

            return $r->successful() ? array_values(array_filter($r->json('packs', []), fn ($id) => (int) $id !== 81)) : [];
        });

        // Warm the per-pack cache concurrently; FTB has no bulk endpoint.
        $missing = array_values(array_filter($ids, fn ($id) => ! Cache::has('breefer:mp:ftb:pack:'.(int) $id)));
        foreach (array_chunk($missing, 16) as $chunk) {
            try {
                $responses = Http::pool(fn ($pool) => array_map(
                    fn ($id) => $pool->as((string) $id)->withUserAgent(Plugin::userAgent())->acceptJson()->timeout(15)
                        ->get('https://api.feed-the-beast.com/v1/modpacks/public/modpack/'.(int) $id),
                    $chunk
                ));
                foreach ($responses as $id => $response) {
                    if ($response instanceof \Illuminate\Http\Client\Response && $response->successful() && $response->json('status') === 'success') {
                        Cache::put('breefer:mp:ftb:pack:'.(int) $id, $response->json(), 3600);
                    }
                }
            } catch (\Throwable) {
                // fall back to sequential lookups below
            }
        }

        $items = [];
        foreach ($ids as $id) {
            $pack = self::ftbPack((int) $id);
            if (! $pack) {
                continue;
            }
            $latest = end($pack['versions']) ?: [];
            $targets = collect($latest['targets'] ?? []);
            $mc = $targets->firstWhere('type', 'game')['version'] ?? null;
            $ml = $targets->firstWhere('type', 'modloader')['name'] ?? null;
            if (($version && $mc !== $version) || ($loader && $ml !== $loader)) {
                continue;
            }
            $art = collect($pack['art'] ?? [])->firstWhere('type', 'square');
            $items[] = [
                'provider' => 'ftb',
                'id' => (string) $pack['id'],
                'title' => $pack['name'],
                'description' => $pack['synopsis'] ?? '',
                'icon' => $art['url'] ?? null,
                'author' => $pack['authors'][0]['name'] ?? 'FTB',
                'downloads' => (int) ($pack['installs'] ?? 0),
                'updated' => isset($pack['updated']) ? date(DATE_ATOM, (int) $pack['updated']) : null,
                'loaders' => $ml ? [$ml] : [],
                'game_versions' => $mc ? [$mc] : [],
                'url' => 'https://www.feed-the-beast.com/modpacks/'.$pack['id'].'-'.($pack['slug'] ?? ''),
                '_plays' => (int) ($pack['plays_14d'] ?? 0),
            ];
        }

        if ($query === '') {
            usort($items, fn ($a, $b) => $b['_plays'] <=> $a['_plays']);
        }
        $total = count($items);
        $items = array_map(function ($i) {
            unset($i['_plays']);

            return $i;
        }, array_slice($items, ($page - 1) * $perPage, $perPage));

        return ['items' => $items, 'total' => $total];
    }

    private static function ftbVersions(string $id): array
    {
        $pack = self::ftbPack((int) $id);
        if (! $pack) {
            throw new \RuntimeException('FTB pack not found.');
        }

        return array_reverse(array_map(function ($v) {
            $targets = collect($v['targets'] ?? []);
            $java = $targets->firstWhere('type', 'runtime')['version'] ?? null;

            return [
                'id' => (string) $v['id'],
                'name' => $v['name'],
                'type' => $v['type'] ?? 'release',
                'game_versions' => array_filter([$targets->firstWhere('type', 'game')['version'] ?? null]),
                'loaders' => array_filter([$targets->firstWhere('type', 'modloader')['name'] ?? null]),
                'date' => isset($v['updated']) ? date(DATE_ATOM, (int) $v['updated']) : null,
                'java' => $java ? (int) $java : null,
                'memory' => $v['specs']['recommended'] ?? null,
            ];
        }, array_values(array_filter($pack['versions'] ?? [], fn ($v) => empty($v['private'])))));
    }
}
