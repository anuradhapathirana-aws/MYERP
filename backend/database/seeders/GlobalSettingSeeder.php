<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlobalSetting;
use App\Services\SettingsService;
use Illuminate\Database\Seeder;

/**
 * Seeds the DEFAULT value of each global setting, once.
 *
 * ── firstOrCreate, never updateOrCreate ─────────────────────────────────────
 * deploy.sh runs this on every deployment. With updateOrCreate it rewrote the
 * stored value each time, so every deploy silently switched module.finance
 * back OFF on a client who had bought it -- and the only symptom would be the
 * Finance menu vanishing after an unrelated release.
 *
 * A defaults seeder must establish a value when none exists and then never
 * touch it again. Module entitlements are a deliberate decision made per
 * client through Settings, so they are exactly the thing a deploy must not
 * overwrite.
 */
class GlobalSettingSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'module.inventory' => true,
            'module.finance'   => false,
            'module.hr'        => false,
            'module.crm'       => false,
        ];

        $settings = app(SettingsService::class);

        foreach ($defaults as $key => $value) {
            GlobalSetting::firstOrCreate(
                ['key'   => $key],
                ['value' => json_encode($value)],
            );

            // Writing the row directly bypasses SettingsService::set(), which
            // is the only thing that invalidates the cache -- and settings are
            // cached with rememberForever.
            //
            // Without this the cache can outlive the truth indefinitely, and
            // the symptom is brutal to diagnose: the database says the Finance
            // module is enabled, every Finance request 403s with "The
            // [finance] module is not enabled on this installation", and
            // nothing in the logs or the settings screen disagrees.
            $settings->forget($key);
        }
    }
}
