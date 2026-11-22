<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use App\Models\User;
use App\Services\CompanyAccessService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The filters every ledger report shares: the period, company and location.
 * Each report adds its own rules on top through extraRules() / extraFilters(),
 * so a new report never repeats the date validation.
 *
 * Company access lives here, so no ledger report can forget it:
 *   - asking for a company the user may not see is refused (403);
 *   - filters() carries `allowed_company_ids`, which JournalLineQuery applies
 *     to every line the report reads.
 * The permission itself (view_finance_reports) is the controller's middleware.
 */
abstract class LedgerFilterRequest extends FormRequest
{
    public function authorize(CompanyAccessService $access): bool
    {
        $company = $this->input('company_id');

        // A malformed id is validation's job (422), not an access question.
        if ($company === null || $company === '' || ! is_numeric($company)) {
            return true;
        }

        return $access->canAccess($this->reportUser(), (int) $company);
    }

    /** @return array<string, mixed> */
    final public function rules(): array
    {
        return [
            'date_from'   => ['nullable', 'date_format:Y-m-d'],
            'date_to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'company_id'  => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'page'        => ['nullable', 'integer', 'min:1'],
            ...$this->extraRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => 'The To date cannot be before the From date.',
        ];
    }

    /**
     * The validated filters, normalised for the services.
     *
     * @return array<string, mixed>
     */
    final public function filters(): array
    {
        $v = $this->validated();

        return [
            'date_from'           => $v['date_from'] ?? null,
            'date_to'             => $v['date_to'] ?? null,
            'company_id'          => isset($v['company_id']) ? (int) $v['company_id'] : null,
            'location_id'         => isset($v['location_id']) ? (int) $v['location_id'] : null,
            // Null = unrestricted (super admin, or no companies assigned).
            'allowed_company_ids' => $this->allowedCompanyIds(),
            ...$this->extraFilters($v),
        ];
    }

    /** @return list<int>|null */
    public function allowedCompanyIds(): ?array
    {
        return app(CompanyAccessService::class)->allowedCompanyIds($this->reportUser());
    }

    private function reportUser(): ?User
    {
        $user = $this->user();

        return $user instanceof User ? $user : null;
    }

    public function page(): int
    {
        return (int) ($this->validated()['page'] ?? 1);
    }

    /** @return array<string, mixed> */
    protected function extraRules(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed> $validated
     * @return array<string, mixed>
     */
    protected function extraFilters(array $validated): array
    {
        return [];
    }
}
