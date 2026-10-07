<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Enums\CustomerReturnStatus;
use App\Models\Company;
use Modules\Inventory\Models\CustomerReturn;
use Modules\Inventory\Services\CustomerReturnService;

class CustomerReturnPdfController extends Controller
{
    public function __construct(private readonly CustomerReturnService $service)
    {
        $this->middleware('permission:view_customer_returns');
    }

    public function download(CustomerReturn $customerReturn): Response
    {
        // A draft is still editable — its figures aren't final, so it has no printable note.
        if ($customerReturn->status !== CustomerReturnStatus::Confirmed) {
            abort(422, 'Only confirmed returns can be printed.');
        }

        $return = $this->service->find($customerReturn->id);

        // Single-tenant deployment — the letterhead is the deployment's own company record
        $company = Company::query()->orderBy('id')->first();

        $pdf = Pdf::loadView('inventory::pdf.customer_return', [
            'return'  => $return,
            'company' => $company,
        ])
            ->setPaper('a4', 'portrait')
            ->setOptions([
                'defaultFont'          => 'DejaVu Sans',
                'isHtml5ParserEnabled' => true,
                'isPhpEnabled'         => false,
                'dpi'                  => 150,
            ]);

        return $pdf->download("SRN_{$return->return_no}.pdf");
    }
}
