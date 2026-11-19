<?php

namespace Breefer\ModpackInstaller\Support;

use App\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin-only troubleshooting endpoint:
 * GET /admin/extensions/{id}/diagnostics?server=<uuidShort|uuid>[&reconcile=1]
 */
class DiagnosticsController
{
    public function show(Request $request): JsonResponse
    {
        $id = (string) $request->query('server', '');
        $server = $id === '' ? null : Server::query()->where('uuidShort', $id)->orWhere('uuid', $id)->first();

        if ($server && $request->boolean('reconcile')) {
            EggSwap::reconcile($server);
            $server->refresh();
        }

        $out = EggSwap::diagnostics($server, Plugin::path('backend/data/egg-runner.json'));

        $pagesDir = app_path('Filament/Pages');
        $stub = $pagesDir.DIRECTORY_SEPARATOR.AdminNav::className().'.php';
        $out['admin_nav'] = [
            'stub' => $stub,
            'stub_exists' => is_file($stub),
            'pages_dir_writable' => is_writable($pagesDir),
            'pages_dir_owner' => function_exists('posix_getpwuid') ? (posix_getpwuid((int) @fileowner($pagesDir))['name'] ?? null) : null,
            'php_user' => function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? null) : null,
            'filament_cache_files' => array_map('basename', glob(base_path('bootstrap/cache/filament/*/*.php')) ?: []),
            'breefer_stubs' => array_map('basename', glob($pagesDir.DIRECTORY_SEPARATOR.'Breefer*.php') ?: []),
        ];

        return new JsonResponse($out, 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
