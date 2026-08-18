<?php

declare(strict_types=1);

namespace Modules\Inventory\DTOs;

use Modules\Inventory\Http\Requests\CustomerRequest;

final class CustomerData
{
    public function __construct(
        public readonly ?string $referenceNo,
        public readonly string  $title,
        public readonly string  $customerType,
        public readonly string  $customerName,
        public readonly string  $nicPassportDrivingLicence,
        public readonly ?string $attachments,
        public readonly ?string $brNo,
        public readonly ?string $customerTin,
        public readonly string  $customerMobile,
        public readonly ?string $customerLandLine,
        public readonly ?string $customerEmail,
        public readonly ?string $customerFax,
        public readonly string  $billingAddressLine1,
        public readonly ?string $billingAddressLine2,
        public readonly ?string $billingAddressLine3,
        public readonly ?string $billingCity,
        public readonly ?string $billingZipPostal,
        public readonly ?string $billingStateProvince,
        public readonly ?string $billingCountry,
        public readonly ?string $shippingAddressLine1,
        public readonly ?string $shippingAddressLine2,
        public readonly ?string $shippingAddressLine3,
        public readonly ?string $shippingCity,
        public readonly ?string $shippingZipPostal,
        public readonly ?string $shippingStateProvince,
        public readonly ?string $shippingCountry,
        public readonly ?string $saleManager,
        public readonly ?string $salesExecutive,
        public readonly ?string $salesPerson,
    ) {}

    public static function fromRequest(CustomerRequest $request): self
    {
        return new self(
            referenceNo:               $request->validated('reference_no'),
            title:                     $request->validated('title'),
            customerType:              $request->validated('customer_type'),
            customerName:              $request->validated('customer_name'),
            nicPassportDrivingLicence: $request->validated('nic_passport_driving_licence'),
            attachments:               $request->validated('attachments'),
            brNo:                      $request->validated('br_no'),
            customerTin:               $request->validated('customer_tin'),
            customerMobile:            $request->validated('customer_mobile'),
            customerLandLine:          $request->validated('customer_land_line'),
            customerEmail:             $request->validated('customer_email'),
            customerFax:               $request->validated('customer_fax'),
            billingAddressLine1:       $request->validated('billing_address_line1'),
            billingAddressLine2:       $request->validated('billing_address_line2'),
            billingAddressLine3:       $request->validated('billing_address_line3'),
            billingCity:               $request->validated('billing_city'),
            billingZipPostal:          $request->validated('billing_zip_postal'),
            billingStateProvince:      $request->validated('billing_state_province'),
            billingCountry:            $request->validated('billing_country'),
            shippingAddressLine1:      $request->validated('shipping_address_line1'),
            shippingAddressLine2:      $request->validated('shipping_address_line2'),
            shippingAddressLine3:      $request->validated('shipping_address_line3'),
            shippingCity:              $request->validated('shipping_city'),
            shippingZipPostal:         $request->validated('shipping_zip_postal'),
            shippingStateProvince:     $request->validated('shipping_state_province'),
            shippingCountry:           $request->validated('shipping_country'),
            saleManager:               $request->validated('sale_manager'),
            salesExecutive:            $request->validated('sales_executive'),
            salesPerson:               $request->validated('sales_person'),
        );
    }
}
