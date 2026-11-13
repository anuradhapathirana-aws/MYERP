<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SupportContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route is already behind the super_admin middleware.
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company'        => ['nullable', 'string', 'max:150'],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'phone'          => ['nullable', 'string', 'max:50'],
            'whatsapp'       => ['nullable', 'string', 'max:50'],
            'email'          => ['nullable', 'email', 'max:150'],
            'website'        => ['nullable', 'url', 'max:200'],
            'hours'          => ['nullable', 'string', 'max:150'],
            'notes'          => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.email'  => 'Enter a valid email address, e.g. support@company.lk.',
            'website.url'  => 'Enter the full website address, starting with https://.',
            '*.max'        => 'This is too long (maximum :max characters).',
        ];
    }
}
