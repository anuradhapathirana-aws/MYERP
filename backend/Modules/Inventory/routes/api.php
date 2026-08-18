<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Inventory\Http\Controllers\AttributeController;
use Modules\Inventory\Http\Controllers\AttributeTypeController;
use Modules\Inventory\Http\Controllers\CategoryController;
use Modules\Inventory\Http\Controllers\CompanyController;
use Modules\Inventory\Http\Controllers\CustomerAttachmentController;
use Modules\Inventory\Http\Controllers\CustomerController;
use Modules\Inventory\Http\Controllers\IndustryController;
use Modules\Inventory\Http\Controllers\LocationController;
use Modules\Inventory\Http\Controllers\ProductController;
use Modules\Inventory\Http\Controllers\SalesChannelController;
use Modules\Inventory\Http\Controllers\StoreController;
use Modules\Inventory\Http\Controllers\StoreTypeController;
use Modules\Inventory\Http\Controllers\SupplierMasterController;
use Modules\Inventory\Http\Controllers\DriverController;
use Modules\Inventory\Http\Controllers\VehicleController;
use Modules\Inventory\Http\Controllers\UnitCategoryController;
use Modules\Inventory\Http\Controllers\UnitConversionController;
use Modules\Inventory\Http\Controllers\UnitTypeController;
use Modules\Inventory\Http\Controllers\PurchaseRequestController;
use Modules\Inventory\Http\Controllers\PurchaseOrderController;
use Modules\Inventory\Http\Controllers\PurchaseOrderPdfController;
use Modules\Inventory\Http\Controllers\BatchController;
use Modules\Inventory\Http\Controllers\GoodsReceivedNoteController;
use Modules\Inventory\Http\Controllers\GrnAttachmentController;
use Modules\Inventory\Http\Controllers\SupplierAttachmentController;
use Modules\Inventory\Http\Controllers\GrnPdfController;
use Modules\Inventory\Http\Controllers\GrnItemPieceController;
use Modules\Inventory\Http\Controllers\GrnPieceLabelPdfController;
use Modules\Inventory\Http\Controllers\PieceLabelController;
use Modules\Inventory\Http\Controllers\StockController;
use Modules\Inventory\Http\Controllers\StockReferenceTypeController;
use Modules\Inventory\Http\Controllers\BinCardExportController;
use Modules\Inventory\Http\Controllers\StockMovementSummaryExportController;
use Modules\Inventory\Http\Controllers\SalesByItemSummaryExportController;
use Modules\Inventory\Http\Controllers\SalesByItemDetailSummaryExportController;
use Modules\Inventory\Http\Controllers\SalesByCustomerDetailsExportController;
use Modules\Inventory\Http\Controllers\SupplierWiseGrnDetailsExportController;
use Modules\Inventory\Http\Controllers\OutstandingSummaryExportController;
use Modules\Inventory\Http\Controllers\SalesSummaryExportController;
use Modules\Inventory\Http\Controllers\CostingController;
use Modules\Inventory\Http\Controllers\CostingExpenseTypeController;
use Modules\Inventory\Http\Controllers\ReportController;
use Modules\Inventory\Http\Controllers\SupplierPaymentController;
use Modules\Inventory\Http\Controllers\SupplierCreditNoteController;
use Modules\Inventory\Http\Controllers\CustomerReceiptController;
use Modules\Inventory\Http\Controllers\CustomerReceiptPdfController;
use Modules\Inventory\Http\Controllers\CustomerCreditNoteController;
use Modules\Inventory\Http\Controllers\PaymentModeController;
use Modules\Inventory\Http\Controllers\SalesOrderController;
use Modules\Inventory\Http\Controllers\DeliveryOrderController;
use Modules\Inventory\Http\Controllers\DeliveryOrderPdfController;
use Modules\Inventory\Http\Controllers\InvoiceController;
use Modules\Inventory\Http\Controllers\InvoicePdfController;

Route::middleware(['auth:sanctum', 'module:inventory'])->prefix('v1')->group(function (): void {
    // Named routes before apiResource so static segments are not swallowed by {unit_category}
    Route::get('unit-categories/all', [UnitCategoryController::class, 'all'])
        ->name('inventory.unit-categories.all');
    Route::post('unit-categories/bulk', [UnitCategoryController::class, 'bulkStore'])
        ->name('inventory.unit-categories.bulk-store');
    Route::post('unit-categories/{unitCategory}/set-default', [UnitCategoryController::class, 'setDefault'])
        ->name('inventory.unit-categories.set-default');
    Route::post('unit-categories/{unitCategory}/clear-default', [UnitCategoryController::class, 'clearDefault'])
        ->name('inventory.unit-categories.clear-default');

    Route::apiResource('unit-categories', UnitCategoryController::class)
        ->names('inventory.unit-categories');

    Route::get('unit-types/all', [UnitTypeController::class, 'all'])
        ->name('inventory.unit-types.all');

    Route::apiResource('unit-types', UnitTypeController::class)
        ->names('inventory.unit-types');

    // Named routes before apiResource so they are not swallowed by {unit_conversion}
    Route::get('unit-conversions/by-category/{categoryId}', [UnitConversionController::class, 'byCategory'])
        ->name('inventory.unit-conversions.by-category');

    Route::post('unit-conversions/save-rates', [UnitConversionController::class, 'saveRates'])
        ->name('inventory.unit-conversions.save-rates');

    // Products — per-action permission middleware applied in ProductController constructor
    Route::get('products/all', [ProductController::class, 'all'])
        ->name('inventory.products.all');
    Route::get('products/next-code', [ProductController::class, 'nextProductCode'])
        ->name('inventory.products.next-code');

    Route::apiResource('products', ProductController::class)
        ->names('inventory.products');

    Route::get('supplier-masters/all', [SupplierMasterController::class, 'all'])
        ->name('inventory.supplier-masters.all');

    Route::get('supplier-masters/next-code', [SupplierMasterController::class, 'nextSupplierCode'])
        ->name('inventory.supplier-masters.next-code');

    Route::apiResource('supplier-masters', SupplierMasterController::class)
        ->names('inventory.supplier-masters');

    Route::get('sales-channels/all', [SalesChannelController::class, 'all'])
        ->name('inventory.sales-channels.all');

    Route::apiResource('sales-channels', SalesChannelController::class)
        ->names('inventory.sales-channels');

    Route::get('industries/all', [IndustryController::class, 'all'])
        ->name('inventory.industries.all');

    Route::apiResource('industries', IndustryController::class)
        ->names('inventory.industries');

    Route::get('companies/all', [CompanyController::class, 'all'])
        ->name('inventory.companies.all');

    Route::apiResource('companies', CompanyController::class)
        ->names('inventory.companies');

    Route::get('locations/all', [LocationController::class, 'all'])
        ->name('inventory.locations.all');

    Route::apiResource('locations', LocationController::class)
        ->names('inventory.locations');

    Route::get('categories/all', [CategoryController::class, 'all'])
        ->name('inventory.categories.all');

    Route::apiResource('categories', CategoryController::class)
        ->names('inventory.categories');

    Route::get('attribute-types/all', [AttributeTypeController::class, 'all'])
        ->name('inventory.attribute-types.all');

    Route::apiResource('attribute-types', AttributeTypeController::class)
        ->names('inventory.attribute-types');

    Route::get('attributes/all', [AttributeController::class, 'all'])
        ->name('inventory.attributes.all');

    Route::apiResource('attributes', AttributeController::class)
        ->names('inventory.attributes');

    Route::get('customer-masters/all', [CustomerController::class, 'all'])
        ->name('inventory.customer-masters.all');

    Route::get('customer-masters/next-code', [CustomerController::class, 'nextCustomerCode'])
        ->name('inventory.customer-masters.next-code');

    Route::apiResource('customer-masters', CustomerController::class)
        ->names('inventory.customer-masters');

    Route::get('customer-masters/{customer_master}/attachments', [CustomerAttachmentController::class, 'index'])
        ->name('inventory.customer-masters.attachments.index');

    Route::post('customer-masters/{customer_master}/attachments', [CustomerAttachmentController::class, 'store'])
        ->name('inventory.customer-masters.attachments.store');

    Route::delete('customer-masters/{customer_master}/attachments/{attachment}', [CustomerAttachmentController::class, 'destroy'])
        ->name('inventory.customer-masters.attachments.destroy');

    Route::get('store-types/all', [StoreTypeController::class, 'all'])
        ->name('inventory.store-types.all');

    Route::apiResource('store-types', StoreTypeController::class)
        ->names('inventory.store-types');

    Route::get('stores/all', [StoreController::class, 'all'])
        ->name('inventory.stores.all');

    Route::apiResource('stores', StoreController::class)
        ->names('inventory.stores');

    Route::get('drivers/all', [DriverController::class, 'all'])
        ->name('inventory.drivers.all');

    Route::apiResource('drivers', DriverController::class)
        ->names('inventory.drivers');

    Route::get('vehicle-masters/all', [VehicleController::class, 'all'])
        ->name('inventory.vehicle-masters.all');

    Route::apiResource('vehicle-masters', VehicleController::class)
        ->names('inventory.vehicle-masters');

    // ── Stock Queries ─────────────────────────────────────────────────────────
    Route::get('stock/product', [StockController::class, 'productStock'])
        ->name('inventory.stock.product');
    Route::get('stock/by-store', [StockController::class, 'byStore'])
        ->name('inventory.stock.by-store');
    Route::get('stock-reference-types/all', [StockReferenceTypeController::class, 'all'])
        ->name('inventory.stock-reference-types.all');

    // ── Purchasing ────────────────────────────────────────────────────────────

    // Purchase Requests
    Route::get('purchase-requests/next-reference-no', [PurchaseRequestController::class, 'nextReferenceNo'])
        ->name('inventory.purchase-requests.next-reference-no');
    Route::post('purchase-requests/{purchase_request}/approve', [PurchaseRequestController::class, 'approve'])
        ->name('inventory.purchase-requests.approve');
    Route::post('purchase-requests/{purchase_request}/reject', [PurchaseRequestController::class, 'reject'])
        ->name('inventory.purchase-requests.reject');
    Route::post('purchase-requests/{purchase_request}/cancel', [PurchaseRequestController::class, 'cancel'])
        ->name('inventory.purchase-requests.cancel');
    Route::apiResource('purchase-requests', PurchaseRequestController::class)
        ->names('inventory.purchase-requests');

    // Purchase Orders
    Route::get('purchase-orders/next-po-no', [PurchaseOrderController::class, 'nextPoNo'])
        ->name('inventory.purchase-orders.next-po-no');
    Route::get('purchase-orders/from-pr/{prId}', [PurchaseOrderController::class, 'loadFromPR'])
        ->name('inventory.purchase-orders.from-pr');
    Route::patch('purchase-orders/{purchase_order}/status', [PurchaseOrderController::class, 'updateStatus'])
        ->name('inventory.purchase-orders.update-status');
    Route::get('purchase-orders/{purchase_order}/pdf', [PurchaseOrderPdfController::class, 'download'])
        ->name('inventory.purchase-orders.pdf');
    Route::apiResource('purchase-orders', PurchaseOrderController::class)
        ->names('inventory.purchase-orders');

    // Batches
    Route::get('batches/next-batch-no', [BatchController::class, 'nextBatchNo'])
        ->name('inventory.batches.next-batch-no');
    Route::patch('batches/{batch}/status', [BatchController::class, 'updateStatus'])
        ->name('inventory.batches.update-status');
    Route::get('batches/{id}', [BatchController::class, 'show'])
        ->name('inventory.batches.show');
    Route::get('batches', [BatchController::class, 'index'])
        ->name('inventory.batches.index');

    // ── Costings ──────────────────────────────────────────────────────────────
    Route::get('costings/next-document-no', [CostingController::class, 'nextDocumentNo'])
        ->name('inventory.costings.next-document-no');
    Route::get('costings/next-reference-no', [CostingController::class, 'nextReferenceNo'])
        ->name('inventory.costings.next-reference-no');
    Route::get('costings/supplier-grns/{supplierId}', [CostingController::class, 'supplierGrns'])
        ->name('inventory.costings.supplier-grns');
    Route::post('costings/calculate-preview', [CostingController::class, 'calculatePreview'])
        ->name('inventory.costings.calculate-preview');
    Route::post('costings/{costing}/confirm', [CostingController::class, 'confirm'])
        ->name('inventory.costings.confirm');
    Route::apiResource('costings', CostingController::class)
        ->names('inventory.costings');

    // Costing Expense Types
    Route::apiResource('costing-expense-types', CostingExpenseTypeController::class)
        ->names('inventory.costing-expense-types');

    // Goods Received Notes
    Route::get('goods-received-notes/next-grn-no', [GoodsReceivedNoteController::class, 'nextGrnNo'])
        ->name('inventory.grns.next-grn-no');
    Route::get('goods-received-notes/last', [GoodsReceivedNoteController::class, 'lastGrn'])
        ->name('inventory.grns.last');
    Route::get('goods-received-notes/check-shipping-code', [GoodsReceivedNoteController::class, 'checkShippingCode'])
        ->name('inventory.grns.check-shipping-code');
    Route::get('goods-received-notes/last-product-prices', [GoodsReceivedNoteController::class, 'lastProductPrices'])
        ->name('inventory.grns.last-product-prices');
    Route::get('goods-received-notes/all', [GoodsReceivedNoteController::class, 'all'])
        ->name('inventory.grns.all');
    Route::get('goods-received-notes/po-items-multi', [GoodsReceivedNoteController::class, 'poOutstandingItemsMultiple'])
        ->name('inventory.grns.po-items-multi');
    Route::get('goods-received-notes/po-items/{poId}', [GoodsReceivedNoteController::class, 'poOutstandingItems'])
        ->name('inventory.grns.po-items');
    Route::post('goods-received-notes/{goods_received_note}/confirm', [GoodsReceivedNoteController::class, 'confirm'])
        ->name('inventory.grns.confirm');
    Route::get('goods-received-notes/{goods_received_note}/pdf', [GrnPdfController::class, 'download'])
        ->name('inventory.grns.pdf');
    Route::get('goods-received-notes/{goods_received_note}/piece-labels/pdf', [GrnPieceLabelPdfController::class, 'download'])
        ->name('inventory.grns.piece-labels.pdf');
    Route::apiResource('goods-received-notes', GoodsReceivedNoteController::class)
        ->names('inventory.grns');

    // ── Piece Labels (cross-GRN QR label printing) ───────────────────────────
    Route::get('piece-labels', [PieceLabelController::class, 'index'])
        ->name('inventory.piece-labels.index');
    Route::get('piece-labels/shipping-codes', [PieceLabelController::class, 'shippingCodes'])
        ->name('inventory.piece-labels.shipping-codes');
    Route::get('piece-labels/grn-nos', [PieceLabelController::class, 'grnNos'])
        ->name('inventory.piece-labels.grn-nos');
    Route::get('piece-labels/pdf', [PieceLabelController::class, 'pdf'])
        ->name('inventory.piece-labels.pdf');

    // ── Supplier Payments ────────────────────────────────────────────────────
    Route::get('supplier-payments/next-payment-no', [SupplierPaymentController::class, 'nextPaymentNo'])
        ->name('inventory.supplier-payments.next-payment-no');
    Route::get('supplier-payments/outstanding-grns/{supplierId}', [SupplierPaymentController::class, 'outstandingGrns'])
        ->name('inventory.supplier-payments.outstanding-grns');
    Route::get('supplier-payments/open-credit-notes', [SupplierPaymentController::class, 'openCreditNotes'])
        ->name('inventory.supplier-payments.open-credit-notes');
    Route::post('supplier-payments/{supplier_payment}/confirm', [SupplierPaymentController::class, 'confirm'])
        ->name('inventory.supplier-payments.confirm');
    Route::apiResource('supplier-payments', SupplierPaymentController::class)
        ->names('inventory.supplier-payments');

    Route::apiResource('supplier-credit-notes', SupplierCreditNoteController::class)
        ->only(['index', 'show'])
        ->names('inventory.supplier-credit-notes');

    // ── Customer Receipts ────────────────────────────────────────────────────
    Route::get('customer-receipts/next-receipt-no', [CustomerReceiptController::class, 'nextReceiptNo'])
        ->name('inventory.customer-receipts.next-receipt-no');
    Route::get('customer-receipts/outstanding-invoices/{customerId}', [CustomerReceiptController::class, 'outstandingInvoices'])
        ->name('inventory.customer-receipts.outstanding-invoices');
    Route::get('customer-receipts/open-credit-notes', [CustomerReceiptController::class, 'openCreditNotes'])
        ->name('inventory.customer-receipts.open-credit-notes');
    Route::post('customer-receipts/{customer_receipt}/confirm', [CustomerReceiptController::class, 'confirm'])
        ->name('inventory.customer-receipts.confirm');
    Route::get('customer-receipts/{customer_receipt}/pdf', [CustomerReceiptPdfController::class, 'download'])
        ->name('inventory.customer-receipts.pdf');
    Route::apiResource('customer-receipts', CustomerReceiptController::class)
        ->names('inventory.customer-receipts');

    Route::apiResource('customer-credit-notes', CustomerCreditNoteController::class)
        ->only(['index', 'show'])
        ->names('inventory.customer-credit-notes');

    // ── Payment Modes (master) ───────────────────────────────────────────────
    Route::get('payment-modes/all', [PaymentModeController::class, 'all'])
        ->name('inventory.payment-modes.all');
    Route::apiResource('payment-modes', PaymentModeController::class)
        ->names('inventory.payment-modes');

    // ── Sales Orders ─────────────────────────────────────────────────────────
    Route::get('sales-orders/next-so-no', [SalesOrderController::class, 'nextSoNo'])
        ->name('inventory.sales-orders.next-so-no');
    Route::get('sales-orders/order-sources', [SalesOrderController::class, 'orderSources'])
        ->name('inventory.sales-orders.order-sources');
    Route::get('sales-orders/scan-piece/{pieceCode}', [SalesOrderController::class, 'scanPiece'])
        ->name('inventory.sales-orders.scan-piece');
    Route::get('sales-orders/product-price/{productId}', [SalesOrderController::class, 'productPrice'])
        ->name('inventory.sales-orders.product-price');
    Route::get('sales-orders/available-pieces/{productId}', [SalesOrderController::class, 'availablePieces'])
        ->name('inventory.sales-orders.available-pieces');
    Route::patch('sales-orders/{sales_order}/status', [SalesOrderController::class, 'updateStatus'])
        ->name('inventory.sales-orders.update-status');
    Route::apiResource('sales-orders', SalesOrderController::class)
        ->names('inventory.sales-orders');

    // ── Delivery Orders ──────────────────────────────────────────────────────
    Route::get('delivery-orders/next-do-no', [DeliveryOrderController::class, 'nextDoNo'])
        ->name('inventory.delivery-orders.next-do-no');
    Route::get('delivery-orders/from-so/{soId}', [DeliveryOrderController::class, 'fromSalesOrder'])
        ->name('inventory.delivery-orders.from-so');
    Route::patch('delivery-orders/{delivery_order}/status', [DeliveryOrderController::class, 'updateStatus'])
        ->name('inventory.delivery-orders.update-status');
    Route::get('delivery-orders/{delivery_order}/pdf', [DeliveryOrderPdfController::class, 'download'])
        ->name('inventory.delivery-orders.pdf');
    Route::apiResource('delivery-orders', DeliveryOrderController::class)
        ->names('inventory.delivery-orders');

    // ── Invoices ─────────────────────────────────────────────────────────────
    Route::get('invoices/next-invoice-no', [InvoiceController::class, 'nextInvoiceNo'])
        ->name('inventory.invoices.next-invoice-no');
    Route::get('invoices/billing-source/so/{soId}', [InvoiceController::class, 'billingSourceForSo'])
        ->name('inventory.invoices.billing-source-so');
    Route::get('invoices/billing-source/do/{doId}', [InvoiceController::class, 'billingSourceForDo'])
        ->name('inventory.invoices.billing-source-do');
    Route::patch('invoices/{invoice}/status', [InvoiceController::class, 'updateStatus'])
        ->name('inventory.invoices.update-status');
    Route::get('invoices/{invoice}/pdf', [InvoicePdfController::class, 'download'])
        ->name('inventory.invoices.pdf');
    Route::get('invoices/{invoice}/payment-history', [InvoiceController::class, 'paymentHistory'])
        ->name('inventory.invoices.payment-history');
    Route::apiResource('invoices', InvoiceController::class)
        ->names('inventory.invoices');

    // ── GRN Piece QR scan resolve ────────────────────────────────────────────
    Route::get('pieces/{pieceCode}', [GrnItemPieceController::class, 'show'])
        ->name('inventory.pieces.show');

    // ── Reports ───────────────────────────────────────────────────────────────
    Route::prefix('reports/inventory')->name('inventory.reports.')->group(function (): void {
        Route::get('stock-levels',      [ReportController::class, 'stockLevels'])->name('stock-levels');
        Route::get('stock-movements',   [ReportController::class, 'stockMovements'])->name('stock-movements');
        Route::get('low-stock',         [ReportController::class, 'lowStock'])->name('low-stock');
        Route::get('stock-valuation',   [ReportController::class, 'stockValuation'])->name('stock-valuation');
        Route::get('batch-expiry',      [ReportController::class, 'batchExpiry'])->name('batch-expiry');
        Route::get('purchase-requests', [ReportController::class, 'purchaseRequests'])->name('purchase-requests');
        Route::get('purchase-orders',   [ReportController::class, 'purchaseOrders'])->name('purchase-orders');
        Route::get('outstanding-pos',   [ReportController::class, 'outstandingPOs'])->name('outstanding-pos');
        Route::get('grn',               [ReportController::class, 'grn'])->name('grn');
        Route::get('supplier-summary',  [ReportController::class, 'supplierSummary'])->name('supplier-summary');
        Route::get('landed-costs',      [ReportController::class, 'landedCosts'])->name('landed-costs');
        Route::get('bin-card',          [ReportController::class, 'binCard'])->name('bin-card');
        Route::get('bin-card/pdf',      [BinCardExportController::class, 'pdf'])->name('bin-card.pdf');
        Route::get('bin-card/csv',      [BinCardExportController::class, 'csv'])->name('bin-card.csv');
        Route::get('movement-summary',     [ReportController::class, 'stockMovementSummary'])->name('movement-summary');
        Route::get('movement-summary/pdf', [StockMovementSummaryExportController::class, 'pdf'])->name('movement-summary.pdf');
        Route::get('movement-summary/csv', [StockMovementSummaryExportController::class, 'csv'])->name('movement-summary.csv');
        Route::get('sales-by-item-summary',     [ReportController::class, 'salesByItemSummary'])->name('sales-by-item-summary');
        Route::get('sales-by-item-summary/pdf', [SalesByItemSummaryExportController::class, 'pdf'])->name('sales-by-item-summary.pdf');
        Route::get('sales-by-item-summary/csv', [SalesByItemSummaryExportController::class, 'csv'])->name('sales-by-item-summary.csv');
        Route::get('sales-by-item-detail-summary',     [ReportController::class, 'salesByItemDetailSummary'])->name('sales-by-item-detail-summary');
        Route::get('sales-by-item-detail-summary/pdf', [SalesByItemDetailSummaryExportController::class, 'pdf'])->name('sales-by-item-detail-summary.pdf');
        Route::get('sales-by-item-detail-summary/csv', [SalesByItemDetailSummaryExportController::class, 'csv'])->name('sales-by-item-detail-summary.csv');
        Route::get('sales-by-customer-details',     [ReportController::class, 'salesByCustomerDetails'])->name('sales-by-customer-details');
        Route::get('sales-by-customer-details/pdf', [SalesByCustomerDetailsExportController::class, 'pdf'])->name('sales-by-customer-details.pdf');
        Route::get('sales-by-customer-details/csv', [SalesByCustomerDetailsExportController::class, 'csv'])->name('sales-by-customer-details.csv');
        Route::get('supplier-wise-grn-details',     [ReportController::class, 'supplierWiseGrnDetails'])->name('supplier-wise-grn-details');
        Route::get('supplier-wise-grn-details/pdf', [SupplierWiseGrnDetailsExportController::class, 'pdf'])->name('supplier-wise-grn-details.pdf');
        Route::get('supplier-wise-grn-details/csv', [SupplierWiseGrnDetailsExportController::class, 'csv'])->name('supplier-wise-grn-details.csv');
        Route::get('item-search', [ReportController::class, 'itemSearch'])->name('item-search');
        Route::get('outstanding-summary',     [ReportController::class, 'outstandingSummary'])->name('outstanding-summary');
        Route::get('sales-summary',     [ReportController::class, 'salesSummary'])->name('sales-summary');
        Route::get('sales-summary/pdf', [SalesSummaryExportController::class, 'pdf'])->name('sales-summary.pdf');
        Route::get('sales-summary/csv', [SalesSummaryExportController::class, 'csv'])->name('sales-summary.csv');
        Route::get('outstanding-summary/pdf', [OutstandingSummaryExportController::class, 'pdf'])->name('outstanding-summary.pdf');
        Route::get('outstanding-summary/csv', [OutstandingSummaryExportController::class, 'csv'])->name('outstanding-summary.csv');
    });

    // GRN Attachments
    Route::get('goods-received-notes/{goods_received_note}/attachments', [GrnAttachmentController::class, 'index'])
        ->name('inventory.grns.attachments.index');
    Route::post('goods-received-notes/{goods_received_note}/attachments', [GrnAttachmentController::class, 'store'])
        ->name('inventory.grns.attachments.store');
    Route::delete('goods-received-notes/{goods_received_note}/attachments/{attachment}', [GrnAttachmentController::class, 'destroy'])
        ->name('inventory.grns.attachments.destroy');

    // Supplier Attachments
    Route::get('supplier-masters/{supplier_master}/attachments', [SupplierAttachmentController::class, 'index'])
        ->name('inventory.supplier-masters.attachments.index');
    Route::post('supplier-masters/{supplier_master}/attachments', [SupplierAttachmentController::class, 'store'])
        ->name('inventory.supplier-masters.attachments.store');
    Route::delete('supplier-masters/{supplier_master}/attachments/{attachment}', [SupplierAttachmentController::class, 'destroy'])
        ->name('inventory.supplier-masters.attachments.destroy');
});
