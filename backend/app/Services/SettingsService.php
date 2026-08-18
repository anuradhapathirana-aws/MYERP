<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;

class SettingsService
{
    private const PREFIX = 'global_settings.';

    /**
     * Retrieve a setting by key, decoded from JSON.
     * The result is cached forever until the key is explicitly updated or forgotten.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(
            self::PREFIX . $key,
            function () use ($key, $default): mixed {
                $setting = GlobalSetting::where('key', $key)->first();

                return $setting !== null
                    ? json_decode($setting->value, associative: true)
                    : $default;
            },
        );
    }

    /**
     * Persist a setting and immediately invalidate its cache entry.
     */
    public function set(string $key, mixed $value): void
    {
        GlobalSetting::updateOrCreate(
            ['key'   => $key],
            ['value' => json_encode($value)],
        );

        Cache::forget(self::PREFIX . $key);
    }

    /**
     * Check if a boolean-style setting is enabled.
     * Convenience wrapper: get('module.inventory') === true.
     */
    public function isEnabled(string $key): bool
    {
        return (bool) $this->get($key, false);
    }

    /**
     * Flush the cache for a key without touching the database value.
     * Useful when the DB was written by another process.
     */
    public function forget(string $key): void
    {
        Cache::forget(self::PREFIX . $key);
    }
}
