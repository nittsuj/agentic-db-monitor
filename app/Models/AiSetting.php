<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Ai\AiProviderConfig;
use Database\Factories\AiSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AiSetting extends Model
{
    /** @use HasFactory<AiSettingFactory> */
    use HasFactory;

    use HasUuids;

    protected $fillable = [
        'provider',
        'model',
        'base_url',
        'api_key',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public static function configured(): bool
    {
        return static::where('is_active', true)->exists();
    }

    /**
     * Each provider keeps its own row (and key); exactly one row is active and
     * that is the provider the chat, benchmark and settings page use.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            static::whereKeyNot($this->getKey())->where('is_active', true)->update(['is_active' => false]);

            if (! $this->is_active) {
                $this->forceFill(['is_active' => true])->save();
            }
        });
    }

    /**
     * Whether this provider can be used as is: it has a key, or needs none.
     */
    public function isUsable(): bool
    {
        return ! AiProviderConfig::apiKeyRequired((string) $this->provider)
            || AiProviderConfig::apiKey($this) !== '';
    }
}
