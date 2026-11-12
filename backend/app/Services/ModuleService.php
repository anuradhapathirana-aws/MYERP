<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GlobalSetting;
use App\Support\Modules\ModuleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads and switches the installation's modules (global settings module.{key}).
 *
 * This is the single source of truth: the API gate and the menu both follow it.
 * Every switch is audited, and switching off keeps all data.
 */
class ModuleService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @return list<string> The keys of the modules switched on, in catalog order. */
    public function enabledKeys(): array
    {
        return array_values(array_filter(
            ModuleCatalog::keys(),
            fn (string $key): bool => $this->settings->isEnabled("module.{$key}"),
        ));
    }

    /**
     * Every module with its state and what data it already holds.
     *
     * @return list<array{key: string, label: string, description: string, enabled: bool, usage: list<array{label: string, count: int}>}>
     */
    public function all(): array
    {
        $rows = [];

        foreach (ModuleCatalog::all() as $key => $module) {
            $rows[] = [
                'key'         => $key,
                'label'       => $module['label'],
                'description' => $module['description'],
                'enabled'     => $this->settings->isEnabled("module.{$key}"),
                'usage'       => $this->usage($module['usage']),
            ];
        }

        return $rows;
    }

    /**
     * Switch one module on or off. A no-op when it is already in that state.
     *
     * @return array{key: string, label: string, description: string, enabled: bool, usage: list<array{label: string, count: int}>}
     */
    public function setEnabled(string $key, bool $enabled): array
    {
        $before = $this->settings->isEnabled("module.{$key}");

        if ($before !== $enabled) {
            DB::transaction(function () use ($key, $enabled, $before): void {
                $this->settings->set("module.{$key}", $enabled);

                $setting = GlobalSetting::where('key', "module.{$key}")->firstOrFail();

                $this->audit->record(
                    'global_setting',
                    (int) $setting->id,
                    $enabled ? 'module_enabled' : 'module_disabled',
                    ['module' => $key, 'enabled' => $before],
                    ['module' => $key, 'enabled' => $enabled],
                );
            });
        }

        return collect($this->all())->firstWhere('key', $key);
    }

    /**
     * @param  array<string, string> $tables table => label
     * @return list<array{label: string, count: int}>
     */
    private function usage(array $tables): array
    {
        $usage = [];

        foreach ($tables as $table => $label) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $query = DB::table($table);
            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            $count = $query->count();
            if ($count > 0) {
                $usage[] = ['label' => $label, 'count' => $count];
            }
        }

        return $usage;
    }
}
