<?php

namespace Breefer\ModpackInstaller\Support;

use App\Events\Server\Installed;
use App\Models\Egg;
use App\Models\EggVariable;
use App\Models\Nest;
use App\Models\Server;
use App\Repositories\Agent\DaemonServerRepository;
use App\Services\Eggs\Sharing\EggImporterService;
use App\Services\Nests\NestCreationService;
use App\Services\Servers\ReinstallServerService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Runs a one-off install script on a server by temporarily switching it to an
 * internal "runner" egg and reinstalling. When the agent reports the install as
 * finished (App\Events\Server\Installed) the server is restored to its original
 * egg, nest, startup command and image, and the runner variables are wiped.
 */
final class EggSwap
{
    public const NEST_NAME = 'Breefer (internal)';

    public static function table(): string
    {
        // e.g. breefer-modpack-installer -> breefer_modpack_installer_jobs
        return str_replace('-', '_', Plugin::ID).'_jobs';
    }

    public static function ensureSchema(): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $table = self::table();
        // 1.0.0 pre-release builds used "breefer_<id>_jobs"; keep their running jobs restorable.
        $legacy = 'breefer_'.$table;
        $hasLegacy = Schema::hasTable($legacy);

        if (Schema::hasTable($table)) {
            if ($hasLegacy) {
                foreach (DB::table($legacy)->orderBy('id')->get() as $row) {
                    $data = (array) $row;
                    unset($data['id']);
                    DB::table($table)->insert($data);
                }
                Schema::drop($legacy);
            }

            return;
        }

        if ($hasLegacy) {
            Schema::rename($legacy, $table);

            return;
        }

        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('server_id')->index();
            $t->unsignedInteger('user_id')->nullable();
            $t->unsignedInteger('original_egg_id');
            $t->unsignedInteger('original_nest_id');
            $t->text('original_startup')->nullable();
            $t->string('original_image')->nullable();
            $t->unsignedInteger('runner_egg_id');
            $t->string('status', 20)->default('running');
            $t->text('meta')->nullable();
            $t->timestamps();
        });
    }

    /**
     * Creates or refreshes the internal runner egg from an egg JSON definition.
     */
    public static function ensureEgg(string $eggJsonPath): Egg
    {
        $definition = json_decode((string) file_get_contents($eggJsonPath), true, 512, JSON_THROW_ON_ERROR);
        $author = (string) $definition['author'];

        // "@file:install.sh" keeps the install script in its own file next to the egg JSON.
        $script = (string) ($definition['scripts']['installation']['script'] ?? '');
        if (str_starts_with($script, '@file:')) {
            $file = dirname($eggJsonPath).DIRECTORY_SEPARATOR.basename(substr($script, 6));
            $definition['scripts']['installation']['script'] = str_replace("\r\n", "\n", (string) file_get_contents($file));
        }

        /** @var Egg|null $egg */
        $egg = Egg::query()->where('author', $author)->first();

        if ($egg === null) {
            $nest = Nest::query()->where('name', self::NEST_NAME)->first();
            if ($nest === null) {
                $nest = app(NestCreationService::class)->handle([
                    'name' => self::NEST_NAME,
                    'description' => 'Internal eggs used by Breefer extensions. Do not assign servers to these eggs manually.',
                ], 'breefer@breefer.internal');
            }

            $tmp = tempnam(sys_get_temp_dir(), 'brf-egg-');
            file_put_contents($tmp, json_encode($definition));
            try {
                $egg = app(EggImporterService::class)->handle(new UploadedFile($tmp, 'egg.json', 'application/json', null, true), $nest->id);
            } finally {
                @unlink($tmp);
            }

            return $egg;
        }

        // Keep the install script, container and variables in sync with the bundled definition.
        $install = $definition['scripts']['installation'] ?? [];
        $egg->forceFill([
            'script_install' => $install['script'] ?? $egg->script_install,
            'script_container' => $install['container'] ?? $egg->script_container,
            'script_entry' => $install['entrypoint'] ?? $egg->script_entry,
            'copy_script_from' => null,
        ])->save();

        foreach ($definition['variables'] ?? [] as $variable) {
            $exists = EggVariable::query()->where('egg_id', $egg->id)->where('env_variable', $variable['env_variable'])->exists();
            if (! $exists) {
                EggVariable::query()->forceCreate([
                    'egg_id' => $egg->id,
                    'name' => $variable['name'],
                    'description' => $variable['description'] ?? '',
                    'env_variable' => $variable['env_variable'],
                    'default_value' => $variable['default_value'] ?? '',
                    'user_viewable' => false,
                    'user_editable' => false,
                    'is_secret' => (bool) ($variable['is_secret'] ?? false),
                    'rules' => $variable['rules'] ?? 'nullable|string',
                ]);
            }
        }

        return $egg->refresh();
    }

    public static function activeJob(Server $server): ?object
    {
        self::ensureSchema();

        return DB::table(self::table())->where('server_id', $server->id)->where('status', 'running')->latest('id')->first();
    }

    /**
     * Switch the server to the runner egg, store env values and trigger a reinstall.
     *
     * @param  array<string, string>  $environment
     * @param  array<string, mixed>  $meta
     */
    public static function start(Server $server, Egg $runner, array $environment, ?int $userId, array $meta = []): int
    {
        self::ensureSchema();

        if (self::activeJob($server) !== null || $server->egg_id === $runner->id) {
            throw new \RuntimeException('Another Breefer job is already running on this server. Wait for it to finish or cancel it first.');
        }

        $jobId = DB::transaction(function () use ($server, $runner, $environment, $userId, $meta) {
            $jobId = DB::table(self::table())->insertGetId([
                'server_id' => $server->id,
                'user_id' => $userId,
                'original_egg_id' => $server->egg_id,
                'original_nest_id' => $server->nest_id,
                'original_startup' => $server->startup,
                'original_image' => $server->image,
                'runner_egg_id' => $runner->id,
                'status' => 'running',
                'meta' => json_encode($meta),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $server->forceFill(['egg_id' => $runner->id, 'nest_id' => $runner->nest_id])->save();

            foreach ($runner->variables()->get() as $variable) {
                DB::table('server_variables')->updateOrInsert(
                    ['server_id' => $server->id, 'variable_id' => $variable->id],
                    [
                        'variable_value' => (string) ($environment[$variable->env_variable] ?? $variable->default_value ?? ''),
                        'updated_at' => now(),
                    ]
                );
            }

            return $jobId;
        });

        try {
            $after = app(ReinstallServerService::class)->handle($server->refresh());
            Log::info('[Breefer] Runner reinstall requested.', ['server' => $server->uuid, 'job' => $jobId, 'egg' => $runner->id, 'status' => $after->status ?? null]);
        } catch (\Throwable $exception) {
            Log::error('[Breefer] Runner reinstall failed to start.', ['server' => $server->uuid, 'job' => $jobId, 'error' => $exception->getMessage()]);
            self::restore($server->refresh(), 'failed');

            throw $exception;
        }

        return $jobId;
    }

    /**
     * Optional hook run after a successful restore, before the agent is synced
     * (the Modpack Installer uses it to pick the right Java image).
     *
     * @var null|\Closure(Server, object): void
     */
    public static ?\Closure $afterRestore = null;

    /** Restore the server to its original egg. Returns true when a job was restored. */
    public static function restore(Server $server, string $status = 'completed'): bool
    {
        self::ensureSchema();
        $job = self::activeJob($server);
        if ($job === null) {
            return false;
        }

        DB::transaction(function () use ($server, $job, $status) {
            $server->forceFill([
                'egg_id' => $job->original_egg_id,
                'nest_id' => $job->original_nest_id,
                'startup' => $job->original_startup ?? $server->startup,
                'image' => $job->original_image ?? $server->image,
            ])->save();

            $runnerVariableIds = EggVariable::query()->where('egg_id', $job->runner_egg_id)->pluck('id');
            DB::table('server_variables')->where('server_id', $server->id)->whereIn('variable_id', $runnerVariableIds)->delete();

            DB::table(self::table())->where('id', $job->id)->update(['status' => $status, 'updated_at' => now()]);
        });

        if ($status === 'completed' && self::$afterRestore !== null) {
            try {
                (self::$afterRestore)($server->refresh(), $job);
            } catch (\Throwable $exception) {
                Log::warning('[Breefer] After-restore hook failed.', ['server' => $server->uuid, 'error' => $exception->getMessage()]);
            }
        }

        try {
            app(DaemonServerRepository::class)->setServer($server->refresh())->sync();
        } catch (\Throwable $exception) {
            Log::warning('[Breefer] Could not sync server after restoring egg.', ['server' => $server->uuid, 'error' => $exception->getMessage()]);
        }

        return true;
    }

    /**
     * Safety net for a missed Installed event: if the panel has recorded an install
     * result after the job started, restore the server now.
     */
    public static function reconcile(Server $server): void
    {
        $job = self::activeJob($server);
        if ($job === null) {
            return;
        }
        $server->refresh();

        if (in_array($server->status, [Server::STATUS_INSTALL_FAILED, Server::STATUS_REINSTALL_FAILED], true)) {
            Log::warning('[Breefer] Runner install failed; restoring server.', ['server' => $server->uuid, 'job' => $job->id]);
            self::restore($server, 'failed');

            return;
        }

        $installedAt = $server->installed_at ? strtotime((string) $server->installed_at) : 0;
        if ($server->isInstalled() && $installedAt >= strtotime((string) $job->created_at)) {
            Log::info('[Breefer] Install finished without a restore; reconciling.', ['server' => $server->uuid, 'job' => $job->id]);
            self::restore($server, 'completed');
        }
    }

    /** @return array<string, mixed> Admin-only troubleshooting snapshot. */
    public static function diagnostics(?Server $server, string $eggJsonPath): array
    {
        self::ensureSchema();
        $author = (string) (json_decode((string) @file_get_contents($eggJsonPath), true)['author'] ?? '');
        $egg = Egg::query()->where('author', $author)->first();

        $out = [
            'extension' => Plugin::ID,
            'listeners_for_installed' => count(app('events')->getListeners(Installed::class)),
            'table' => self::table(),
            'runner_egg' => $egg ? [
                'id' => $egg->id,
                'nest_id' => $egg->nest_id,
                'script_container' => $egg->script_container,
                'script_entry' => $egg->script_entry,
                'copy_script_from' => $egg->copy_script_from,
                'script_install_length' => strlen((string) $egg->script_install),
                'copy_script_install_length' => strlen((string) $egg->copy_script_install),
                'script_first_line' => strtok((string) $egg->script_install, "\n"),
            ] : null,
            'server' => null,
            'jobs' => [],
            'log' => [],
        ];

        if ($server) {
            $out['server'] = [
                'id' => $server->id,
                'uuid' => $server->uuid,
                'egg_id' => $server->egg_id,
                'status' => $server->status,
                'skip_scripts' => (bool) $server->skip_scripts,
                'is_reinstalling' => $server->is_reinstalling ?? null,
                'installed_at' => (string) $server->installed_at,
                'updated_at' => (string) $server->updated_at,
            ];
            $out['jobs'] = DB::table(self::table())->where('server_id', $server->id)->latest('id')->limit(10)
                ->get(['id', 'status', 'original_egg_id', 'runner_egg_id', 'created_at', 'updated_at'])->all();
        }

        $logs = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($logs, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        if ($logs) {
            $fh = @fopen($logs[0], 'r');
            if ($fh) {
                $size = filesize($logs[0]);
                fseek($fh, max(0, $size - 512 * 1024));
                $lines = preg_split('/\r?\n/', (string) stream_get_contents($fh)) ?: [];
                fclose($fh);
                $match = array_values(array_filter($lines, fn ($l) => preg_match('/Breefer|\.ERROR|install|reinstall/i', $l)));
                $out['log_file'] = basename($logs[0]);
                $out['log'] = array_map(fn ($l) => mb_substr($l, 0, 500), array_slice($match, -40));
            }
        }

        return $out;
    }

    /** @return array<int, object> */
    public static function history(Server $server, int $limit = 5): array
    {
        self::ensureSchema();

        return DB::table(self::table())->where('server_id', $server->id)->latest('id')->limit($limit)->get()->all();
    }

    /** Registers the Installed listener. Called from the boot hook. */
    public static function listen(): void
    {
        app('events')->listen(Installed::class, function (Installed $event) {
            try {
                if (! Schema::hasTable(self::table())) {
                    return;
                }
                $server = $event->server;
                // Older panel releases have no $successful on the event; fall back to the stored status.
                $successful = property_exists($event, 'successful')
                    ? (bool) $event->successful
                    : ! in_array($server->refresh()->status, [Server::STATUS_INSTALL_FAILED, Server::STATUS_REINSTALL_FAILED], true);
                Log::info('[Breefer] Installed event received.', ['server' => $server->uuid, 'successful' => $successful, 'extension' => Plugin::ID]);
                if (self::activeJob($server) !== null) {
                    self::restore($server, $successful ? 'completed' : 'failed');
                }
            } catch (\Throwable $exception) {
                Log::error('[Breefer] Failed to restore server after install.', ['error' => $exception->getMessage()]);
            }
        });
    }
}
