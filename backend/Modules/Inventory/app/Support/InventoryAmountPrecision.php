<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

use App\Support\Money\TableAmountPrecisionUsage;

/**
 * Inventory's documents and money columns, for the Amount decimals lock and
 * the precision audit (see App\Contracts\AmountPrecisionUsage).
 *
 * Deliberately NOT listed, because they keep their own precision:
 *   - unit prices and unit costs (unit_price, selling_price, cost_floor ...),
 *   - quantities, weights, conversion factors,
 *   - discount / tax PERCENTAGES and margins,
 *   - costing per-item working figures (landed_unit_cost, margin_amount ...),
 *     which are calculation steps, not amounts anyone is billed.
 */
final class InventoryAmountPrecision extends TableAmountPrecisionUsage
{
    protected function documents(): array
    {
        return [
            'inv_purchase_orders'        => ['purchase order', 'purchase orders'],
            'inv_goods_received_notes'   => ['GRN', 'GRNs'],
            'inv_costings'               => ['costing', 'costings'],
            'inv_proforma_invoices'      => ['proforma invoice', 'proforma invoices'],
            'inv_sales_orders'           => ['sales order', 'sales orders'],
            'inv_invoices'               => ['invoice', 'invoices'],
            'inv_customer_receipts'      => ['customer receipt', 'customer receipts'],
            'inv_supplier_payments'      => ['supplier payment', 'supplier payments'],
            'inv_customer_returns'       => ['customer return', 'customer returns'],
            'inv_supplier_returns'       => ['supplier return', 'supplier returns'],
            'inv_cheque_returns'         => ['cheque return', 'cheque returns'],
        ];
    }

    public function moneyColumns(): array
    {
        return [
            'inv_purchase_orders'              => ['subtotal', 'grand_total'],
            'inv_purchase_order_items'         => ['line_total'],
            'inv_goods_received_notes'         => ['total_amount'],
            'inv_goods_received_note_items'    => ['tax_amount', 'recoverable_tax', 'line_total'],
            'inv_grn_item_taxes'               => ['base', 'amount'],
            'inv_costings'                     => ['material_cost', 'total_additional_expenses', 'raw_material_cost', 'total_landed_cost', 'value_addition_amount', 'fob_cif_cost', 'sscl_amount', 'gross_fob_cif_value', 'vat_amount', 'total_price_with_vat'],
            'inv_costing_expenses'             => ['amount'],
            'inv_costing_bill_charges'         => ['amount'],
            'inv_costing_grns'                 => ['grn_total'],
            'inv_proforma_invoices'            => ['subtotal', 'transport_charge', 'grand_total'],
            'inv_proforma_invoice_items'       => ['line_total'],
            'inv_sales_orders'                 => ['subtotal', 'transport_charge', 'grand_total'],
            'inv_sales_order_items'            => ['line_total'],
            'inv_invoices'                     => ['subtotal', 'transport_charge', 'grand_total'],
            'inv_invoice_items'                => ['line_total'],
            'inv_customer_receipts'            => ['advance_amount', 'gross_amount', 'discount_amount', 'setoff_amount', 'net_amount'],
            'inv_customer_receipt_allocations' => ['invoice_amount', 'outstanding_before', 'discount', 'receipt_amount'],
            'inv_customer_receipt_setoffs'     => ['amount'],
            'inv_customer_receipt_settlements' => ['amount'],
            'inv_customer_credit_notes'        => ['amount', 'remaining_balance'],
            'inv_supplier_payments'            => ['advance_amount', 'gross_amount', 'discount_amount', 'setoff_amount', 'net_amount'],
            'inv_supplier_payment_allocations' => ['grn_amount', 'outstanding_before', 'discount', 'payment_amount'],
            'inv_supplier_payment_setoffs'     => ['amount'],
            'inv_supplier_payment_settlements' => ['amount'],
            'inv_supplier_credit_notes'        => ['amount', 'remaining_balance'],
            'inv_customer_returns'             => ['total_amount', 'applied_to_invoice'],
            'inv_customer_return_items'        => ['line_total'],
            'inv_supplier_returns'             => ['total_amount', 'applied_to_grn'],
            'inv_supplier_return_items'        => ['line_total'],
            'inv_cheque_returns'               => ['amount'],
            'inv_cheque_return_allocations'    => ['amount'],
        ];
    }
}
