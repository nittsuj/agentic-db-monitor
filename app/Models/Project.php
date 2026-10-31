<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DbDriver;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'name',
        'driver',
        'host',
        'port',
        'database',
        'username',
        'password',
        'options',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'driver' => DbDriver::class,
            'port' => 'integer',
            'password' => 'encrypted',
            'options' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Another profile with the same connection details, so it points at the
     * same database. Behind an SSH tunnel the host is resolved by the bastion,
     * so profiles behind different bastions are different databases.
     * $ignoreId leaves out the profile being edited.
     *
     * @param  array<string, mixed>|null  $options  projects.options of the profile being saved
     */
    public static function findDuplicate(
        DbDriver $driver,
        string $host,
        int $port,
        string $database,
        string $username,
        ?array $options = [],
        ?string $ignoreId = null,
    ): ?self {
        $bastion = static::sshIdentity($options);

        return static::query()
            ->where('driver', $driver)
            ->where('host', $host)
            ->where('port', $port)
            ->where('database', $database)
            ->where('username', $username)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get()
            ->first(fn (self $project): bool => static::sshIdentity($project->options) === $bastion);
    }

    /**
     * The bastion part of a profile's identity: SSH host, port and username
     * when SSH is enabled, empty otherwise. Must match the expression in the
     * projects_connection_unique index.
     *
     * @param  array<string, mixed>|null  $options
     */
    public static function sshIdentity(?array $options): string
    {
        $ssh = $options['ssh'] ?? null;

        if (! is_array($ssh) || ($ssh['enabled'] ?? null) !== true) {
            return '';
        }

        return ($ssh['host'] ?? '').':'.($ssh['port'] ?? '').':'.($ssh['username'] ?? '');
    }

    /**
     * @return HasMany<MetricSample, $this>
     */
    public function metricSamples(): HasMany
    {
        return $this->hasMany(MetricSample::class);
    }

    /**
     * @return HasMany<Threshold, $this>
     */
    public function thresholds(): HasMany
    {
        return $this->hasMany(Threshold::class);
    }

    /**
     * @return HasMany<PendingAction, $this>
     */
    public function pendingActions(): HasMany
    {
        return $this->hasMany(PendingAction::class);
    }

    /**
     * @return HasMany<ActionLog, $this>
     */
    public function actionLogs(): HasMany
    {
        return $this->hasMany(ActionLog::class);
    }
}
