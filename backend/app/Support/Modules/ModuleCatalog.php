<?php

declare(strict_types=1);

namespace App\Support\Modules;

/**
 * The sellable modules a super_admin can switch on or off for this installation.
 *
 * The ONE place a module is enabled is its global setting (module.{key}); the
 * route gate (EnsureModuleIsEnabled) and the menu both read it. Who sees which
 * screens inside an enabled module is decided by roles and permissions.
 *
 * usage lists tables (by name only, no cross-module class use) whose rows show
 * the module already holds data, for the warning shown before switching it off.
 * Switching off never deletes anything.
 */
final class ModuleCatalog
{
    /**
     * @return array<string, array{label: string, description: string, usage: array<string, string>}>
     */
    public static function all(): array
    {
        return [
            'inventory' => [
                'label'       => 'Inventory',
                'description' => 'Purchasing, GRN, stock, sales orders, delivery, invoices, receipts and payments.',
                'usage'       => [
                    'inv_invoices'             => 'invoices',
                    'inv_goods_received_notes' => 'GRNs',
                ],
            ],
            'finance' => [
                'label'       => 'Finance',
                'description' => 'Chart of accounts, journals, bills, bill payments, bank deposits and bank reconciliation.',
                'usage'       => [
                    'fin_journal_entries' => 'journal entries',
                    'fin_bills'           => 'bills',
                ],
            ],
        ];
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
