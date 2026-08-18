<?php

namespace Modules\Inventory\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CompaniesSeeder extends Seeder
{
    public function run(): void
    {
        $industryId = DB::table('inv_industries')->where('name', 'Textile & Fabrics')->value('id');

        $companies = [
            [
                'company_type'    => 'Manufacturer',
                'company_name'    => 'SilkRoute Fabrics (Pvt) Ltd',
                'registration_no' => 'PV/00012345',
                'tax_reg_no'      => 'VAT-TF-112233',
                'street_address'  => 'No. 45, Industrial Zone, Katunayake',
                'city'            => 'Katunayake',
                'country'         => 'Sri Lanka',
                'state'           => 'Western Province',
                'postal_zip_code' => '11450',
                'company_email'   => 'info@silkroute.lk',
                'company_mobile'  => '+94 11 2345678',
                'industry_id'     => $industryId,
            ],
            [
                'company_type'    => 'Subsidiary',
                'company_name'    => 'SilkRoute Trading Division',
                'registration_no' => 'PV/00012346',
                'tax_reg_no'      => 'VAT-TD-112244',
                'street_address'  => 'No. 12, Baseline Road, Colombo 9',
                'city'            => 'Colombo',
                'country'         => 'Sri Lanka',
                'state'           => 'Western Province',
                'postal_zip_code' => '00900',
                'company_email'   => 'trading@silkroute.lk',
                'company_mobile'  => '+94 11 2345699',
                'industry_id'     => $industryId,
            ],
        ];

        foreach ($companies as $company) {
            DB::table('inv_companies')->updateOrInsert(
                ['registration_no' => $company['registration_no']],
                array_merge($company, [
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
            );
        }
    }
}
