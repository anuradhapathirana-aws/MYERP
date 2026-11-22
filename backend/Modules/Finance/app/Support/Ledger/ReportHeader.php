<?php

declare(strict_types=1);

namespace Modules\Finance\Support\Ledger;

use App\Models\Company;
use App\Models\Location;

/**
 * The letterhead every printed ledger report shares: who it is for, where,
 * who printed it and when. Company and Location are core models, so this
 * works on a Finance-only installation too.
 */
final class ReportHeader
{
    /**
     * @param  array<string, mixed> $filters company_id, location_id
     * @return array{company_name: string, location_name: ?string, generated_by: ?string, generated_at: string}
     */
    public static function for(array $filters): array
    {
        $company  = ! empty($filters['company_id']) ? Company::query()->find((int) $filters['company_id']) : null;
        $location = ! empty($filters['location_id']) ? Location::query()->find((int) $filters['location_id']) : null;

        return [
            'company_name'  => (string) ($company?->company_name ?? config('app.name')),
            'location_name' => $location?->location_name,
            'generated_by'  => auth()->user()?->name,
            'generated_at'  => now()->format('d-m-Y H:i'),
        ];
    }

    /**
     * @param  array<string, mixed> $filters date_from, date_to
     * @return array{from: ?string, to: ?string}
     */
    public static function period(array $filters): array
    {
        return ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null];
    }
}
