<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\SupplierMasterRequest;

final class SupplierMasterData
{
    public function __construct(
        public readonly string $supplierName,
        public readonly ?string $referenceNo,
        public readonly ?string $supplierType,
        public readonly ?string $checkWriterName,
        public readonly ?string $mobile,
        public readonly ?string $landLine,
        public readonly ?string $email,
        public readonly ?string $wechat,
        public readonly ?string $whatsapp,
        public readonly ?string $fax,
        public readonly ?string $website,
        public readonly ?string $bilAddressLine1,
        public readonly ?string $bilAddressLine2,
        public readonly ?string $bilAddressLine3,
        public readonly ?string $bilCity,
        public readonly ?string $bilPostalCode,
        public readonly ?string $bilCountry,
        public readonly ?string $bilStateProvince,
        public readonly ?string $taxType,
        public readonly ?string $taxNo,
        public readonly ?string $taxRegisNo,
        public readonly ?float $creditLimit,
        public readonly ?int $creditPeriod,
        public readonly ?float $privilegesDiscount,
        public readonly ?string $bankName,
        public readonly ?string $bankBranch,
        public readonly ?string $bankAccHolderName,
        public readonly ?string $bankAccNo,
        public readonly ?string $contactPersonName,
        public readonly ?string $contactPersonDesignation,
        public readonly ?string $contactPersonMobile,
        public readonly ?string $contactPersonEmail,
        public readonly ?string $contactPersonFax,
    ) {}

    public static function fromRequest(SupplierMasterRequest $request): self
    {
        return new self(
            supplierName:             $request->validated('supplier_name'),
            referenceNo:              $request->validated('reference_no'),
            supplierType:             $request->validated('supplier_type'),
            checkWriterName:          $request->validated('check_writer_name'),
            mobile:                   $request->validated('mobile'),
            landLine:                 $request->validated('land_line'),
            email:                    $request->validated('email'),
            wechat:                   $request->validated('wechat'),
            whatsapp:                 $request->validated('whatsapp'),
            fax:                      $request->validated('fax'),
            website:                  $request->validated('website'),
            bilAddressLine1:          $request->validated('bil_address_line_1'),
            bilAddressLine2:          $request->validated('bil_address_line_2'),
            bilAddressLine3:          $request->validated('bil_address_line_3'),
            bilCity:                  $request->validated('bil_city'),
            bilPostalCode:            $request->validated('bil_postal_code'),
            bilCountry:               $request->validated('bil_country'),
            bilStateProvince:         $request->validated('bil_state_province'),
            taxType:                  $request->validated('tax_type'),
            taxNo:                    $request->validated('tax_no'),
            taxRegisNo:               $request->validated('tax_regis_no'),
            creditLimit:              $request->validated('credit_limit'),
            creditPeriod:             $request->validated('credit_period'),
            privilegesDiscount:       $request->validated('privileges_discount'),
            bankName:                 $request->validated('bank_name'),
            bankBranch:               $request->validated('bank_branch'),
            bankAccHolderName:        $request->validated('bank_acc_holder_name'),
            bankAccNo:                $request->validated('bank_acc_no'),
            contactPersonName:        $request->validated('contact_person_name'),
            contactPersonDesignation: $request->validated('contact_person_designation'),
            contactPersonMobile:      $request->validated('contact_person_mobile'),
            contactPersonEmail:       $request->validated('contact_person_email'),
            contactPersonFax:         $request->validated('contact_person_fax'),
        );
    }
}
