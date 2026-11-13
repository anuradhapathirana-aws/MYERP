<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\GlobalSetting;
use Illuminate\Support\Facades\DB;

/**
 * The IT support company's contact details, shown to every user in the
 * User Manual's "Need help?" card. Stored as one JSON global setting so each
 * client deployment can show its own support contact; only a super admin
 * changes it (System Settings).
 */
class SupportContactService
{
    public const SETTING_KEY = 'support.contact';

    /** The fields a support contact holds, in display order. */
    public const FIELDS = ['company', 'contact_person', 'phone', 'whatsapp', 'email', 'website', 'hours', 'notes'];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, string|null> Every field, null when not yet set. */
    public function get(): array
    {
        $stored = $this->settings->get(self::SETTING_KEY, []);
        $stored = is_array($stored) ? $stored : [];

        $contact = [];
        foreach (self::FIELDS as $field) {
            $value = $stored[$field] ?? null;
            $contact[$field] = is_string($value) && trim($value) !== '' ? $value : null;
        }

        return $contact;
    }

    /**
     * Replace the contact details and audit the change.
     *
     * @param  array<string, mixed> $input validated input
     * @return array<string, string|null>
     */
    public function update(array $input): array
    {
        $before = $this->get();

        $after = [];
        foreach (self::FIELDS as $field) {
            $value = isset($input[$field]) ? trim((string) $input[$field]) : '';
            $after[$field] = $value === '' ? null : $value;
        }

        if ($before !== $after) {
            DB::transaction(function () use ($before, $after): void {
                $this->settings->set(self::SETTING_KEY, $after);

                $setting = GlobalSetting::where('key', self::SETTING_KEY)->firstOrFail();

                $this->audit->record(
                    'global_setting',
                    (int) $setting->id,
                    'support_contact_updated',
                    $before,
                    $after,
                );
            });
        }

        return $this->get();
    }
}
