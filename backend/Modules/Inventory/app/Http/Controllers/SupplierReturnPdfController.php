<?php

declare(strict_types=1);

namespace Modules\Inventory\Http\Controllers;

use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Modules\Inventory\Enums\SupplierReturnStatus;
use Modules\Inventory\Models\SupplierReturn;
use Modules\Inventory\Services\SupplierReturnService;

class SupplierReturnPdfController extends Controller
{
    public function __construct(private readonly SupplierReturnService $service)
    {
        $this->middleware('permission:view_supplier_returns');
    }

    public function download(SupplierReturn $supplierReturn): Response
    {
        // A draft is still editable — its figures aren't final, so it has no printable note.
        if ($supplierReturn->status !== SupplierReturnStatus::Confirmed) {
            abort(422, 'Only confirmed returns can be printed.');
        }

        $return = $this->service->find($supplierReturn->id);

        // Single-tenant deployment — the letterhead is the deployment's own company record
        $company = Company::query()->orderBy('id')->first();

        $pdf = Pdf::loadView('inventory::pdf.supplier_return', [
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

        return $pdf->download("PRN_{$return->return_no}.pdf");
    }
}
