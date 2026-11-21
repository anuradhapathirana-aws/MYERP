<?php

declare(strict_types=1);

namespace Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Filters of the Trial Balance drill-down. The date, company and location
 * filters are the Trial Balance's own, passed through so the popup agrees
 * with the row that was clicked; search and source_type are the popup's.
 */
class AccountTransactionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the controller's permission middleware guards the route
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date_from'   => ['nullable', 'date_format:Y-m-d'],
            'date_to'     => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'company_id'  => ['nullable', 'integer', 'min:1'],
            'location_id' => ['nullable', 'integer', 'min:1'],
            'search'      => ['nullable', 'string', 'max:100'],
            'source_type' => ['nullable', 'string', 'max:40', 'regex:/^[a-z_]+$/'],
            'page'        => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_to.after_or_equal' => 'The To date cannot be before the From date.',
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        $v = $this->validated();

        return [
            'date_from'   => $v['date_from'] ?? null,
            'date_to'     => $v['date_to'] ?? null,
            'company_id'  => isset($v['company_id']) ? (int) $v['company_id'] : null,
            'location_id' => isset($v['location_id']) ? (int) $v['location_id'] : null,
            'search'      => isset($v['search']) ? trim($v['search']) : null,
            'source_type' => $v['source_type'] ?? null,
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated()['page'] ?? 1);
    }
}
