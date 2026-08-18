<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\CompanyRequest;

final class CompanyData
{
    public function __construct(
        public readonly string  $company_type,
        public readonly string  $company_name,
        public readonly ?string $registration_no,
        public readonly ?string $tax_reg_no,
        public readonly string  $street_address,
        public readonly ?string $city,
        public readonly ?string $country,
        public readonly ?string $state,
        public readonly ?string $postal_zip_code,
        public readonly ?string $company_email,
        public readonly ?string $company_email_2,
        public readonly ?string $company_mobile,
        public readonly int     $industry_id,
    ) {}

    public static function fromRequest(CompanyRequest $request): self
    {
        return new self(
            company_type:     trim($request->input('company_type')),
            company_name:     trim($request->input('company_name')),
            registration_no:  $request->filled('registration_no')  ? trim($request->input('registration_no'))  : null,
            tax_reg_no:       $request->filled('tax_reg_no')        ? trim($request->input('tax_reg_no'))        : null,
            street_address:   trim($request->input('street_address')),
            city:             $request->filled('city')              ? trim($request->input('city'))              : null,
            country:          $request->filled('country')           ? trim($request->input('country'))           : null,
            state:            $request->filled('state')             ? trim($request->input('state'))             : null,
            postal_zip_code:  $request->filled('postal_zip_code')   ? trim($request->input('postal_zip_code'))   : null,
            company_email:    $request->filled('company_email')     ? trim($request->input('company_email'))     : null,
            company_email_2:  $request->filled('company_email_2')   ? trim($request->input('company_email_2'))   : null,
            company_mobile:   $request->filled('company_mobile')    ? trim($request->input('company_mobile'))    : null,
            industry_id:      (int) $request->input('industry_id'),
        );
    }
}
