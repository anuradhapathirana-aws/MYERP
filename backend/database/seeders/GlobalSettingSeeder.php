<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\GlobalSetting;
use Illuminate\Database\Seeder;

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

        foreach ($defaults as $key => $value) {
            GlobalSetting::updateOrCreate(
                ['key'   => $key],
                ['value' => json_encode($value)],
            );
        }
    }
}
