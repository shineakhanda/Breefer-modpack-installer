<?php

namespace Breefer\ModpackInstaller\Http;

use App\Models\Permission;
use App\Models\Server;
use Breefer\ModpackInstaller\Catalog;
use Breefer\ModpackInstaller\Support\BaseController;
use Breefer\ModpackInstaller\Support\EggSwap;
use Breefer\ModpackInstaller\Support\Plugin;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModpackController extends BaseController
{
    public function status(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, Permission::ACTION_FILE_READ);

        EggSwap::reconcile($server);
        $job = EggSwap::activeJob($server);
        $history = array_map(fn ($j) => [
            'id' => $j->id,
            'status' => $j->status,
            'meta' => json_decode((string) $j->meta, true),
            'created_at' => $j->created_at,
            'updated_at' => $j->updated_at,
        ], EggSwap::history($server, 5));

        return new JsonResponse([
            'providers' => Catalog::providers(),
            'installing' => $job !== null,
            'server_status' => $server->status,
            'history' => $history,
        ]);
    }

    public function search(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, Permission::ACTION_FILE_READ);
        $data = $request->validate([
            'provider' => 'required|in:modrinth,curseforge,ftb',
            'query' => 'nullable|string|max:100',
            'loader' => 'nullable|in:fabric,forge,neoforge,quilt',
            'version' => 'nullable|string|max:20',
            'sort' => 'nullable|in:relevance,downloads,updated,newest',
            'page' => 'nullable|integer|min:1|max:500',
        ]);
        if (! in_array($data['provider'], Catalog::providers(), true)) {
            return $this->fail('That provider is not enabled.', 403);
        }

        $perPage = 18;
        $page = (int) ($data['page'] ?? 1);
        try {
            $result = Catalog::search($data['provider'], (string) ($data['query'] ?? ''), $data['loader'] ?? null, $data['version'] ?? null, $data['sort'] ?? 'relevance', $page, $perPage);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), 502);
        }

        return new JsonResponse($result + ['page' => $page, 'pages' => max(1, (int) ceil($result['total'] / $perPage))]);
    }

    public function versions(Request $request, Server $server, string $provider, string $pack): JsonResponse
    {
        $this->authorizeServer($request, $server, Permission::ACTION_FILE_READ);
        if (! in_array($provider, Catalog::providers(), true)) {
            return $this->fail('That provider is not enabled.', 403);
        }

        try {
            return new JsonResponse(['versions' => array_slice(Catalog::versions($provider, $pack), 0, 60)]);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), 502);
        }
    }

    public function install(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, [Permission::ACTION_FILE_CREATE, Permission::ACTION_FILE_DELETE, Permission::ACTION_SETTINGS_REINSTALL]);
        $data = $request->validate([
            'provider' => 'required|in:modrinth,curseforge,ftb',
            'pack_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'version_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'delete_files' => 'required|boolean',
            'accept_eula' => 'required|boolean',
            'title' => 'nullable|string|max:191',
            'version_name' => 'nullable|string|max:191',
        ]);

        if (! in_array($data['provider'], Catalog::providers(), true)) {
            return $this->fail('That provider is not enabled.', 403);
        }
        if (! $server->isInstalled()) {
            return $this->fail('The server is still installing. Wait until it has finished.', 409);
        }

        try {
            $runner = EggSwap::ensureEgg(Plugin::path('backend/data/egg-runner.json'));

            try {
                $this->power($server)->send('kill');
            } catch (\Throwable) {
                // already offline
            }

            EggSwap::start($server, $runner, [
                'MODPACK_PROVIDER' => $data['provider'],
                'MODPACK_ID' => $data['pack_id'],
                'MODPACK_VERSION_ID' => $data['version_id'],
                'DELETE_SERVER_FILES' => $data['delete_files'] ? 'true' : 'false',
                'ACCEPT_EULA' => $data['accept_eula'] ? 'true' : 'false',
                'CURSEFORGE_API_KEY' => $data['provider'] === 'curseforge' ? Catalog::cfKey() : '',
                'MCJARS_URL' => (string) Plugin::setting('mcjars_url', 'https://versions.mcjars.app'),
            ], $request->user()->id, [
                'provider' => $data['provider'],
                'pack_id' => $data['pack_id'],
                'version_id' => $data['version_id'],
                'title' => $data['title'] ?? null,
                'version' => $data['version_name'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            return $this->fail($exception->getMessage(), 409);
        }

        $this->activity('server:breefer.modpack.install', [
            'provider' => $data['provider'], 'pack' => $data['title'] ?? $data['pack_id'], 'version' => $data['version_name'] ?? $data['version_id'], 'delete_files' => (bool) $data['delete_files'],
        ]);

        return $this->ok(['message' => 'Modpack installation started. Follow the progress in the console.']);
    }

    public function restore(Request $request, Server $server): JsonResponse
    {
        $this->authorizeServer($request, $server, Permission::ACTION_SETTINGS_REINSTALL);

        if (! EggSwap::restore($server, 'cancelled')) {
            return $this->fail('There is no modpack installation in progress.', 404);
        }

        $this->activity('server:breefer.modpack.restore');

        return $this->ok();
    }
}
