<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Finance\Services\UnclearedChequesReportService;

/** Filters of the Uncleared Cheques report; the same for screen, PDF and CSV. */
class UnclearedChequesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller's permission middleware guards the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'as_at'           => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'company_id'      => ['nullable', 'integer', 'min:1'],
            'bank_account_id' => ['nullable', 'integer', 'min:1'],
            'type'            => ['nullable', Rule::in([
                UnclearedChequesReportService::TYPE_ALL,
                UnclearedChequesReportService::TYPE_ISSUED,
                UnclearedChequesReportService::TYPE_RECEIVED,
            ])],
            'search'          => ['nullable', 'string', 'max:50'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'as_at.required'        => 'Choose the As at date.',
            'as_at.before_or_equal' => 'The As at date cannot be in the future.',
        ];
    }

    /** @return array{as_at:string, company_id:?int, bank_account_id:?int, type:string, search:?string} */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'as_at'           => $v['as_at'],
            'company_id'      => isset($v['company_id']) ? (int) $v['company_id'] : null,
            'bank_account_id' => isset($v['bank_account_id']) ? (int) $v['bank_account_id'] : null,
            'type'            => $v['type'] ?? UnclearedChequesReportService::TYPE_ALL,
            'search'          => $v['search'] ?? null,
        ];
    }
}
