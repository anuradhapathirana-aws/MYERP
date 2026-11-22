<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\AmountPrecision;
use Illuminate\Foundation\Http\FormRequest;

/** System Settings > Number Format: the Amount decimals setting. */
class AmountPrecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // the route's super_admin middleware guards it
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'decimals' => ['required', 'integer', 'between:' . AmountPrecision::MIN . ',' . AmountPrecision::MAX],
        ];
    }
}
