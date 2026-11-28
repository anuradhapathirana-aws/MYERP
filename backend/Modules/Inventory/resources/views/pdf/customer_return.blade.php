<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Credit Note — {{ $return->return_no }}</title>
<style>
  /*
   * A4 portrait, laid out as the client's TAX CREDITNOTE form: a ruled frame around
   * the page, centred letterhead, customer block left, document references right, a
   * QTY / ITEM NAME / C.PRICE / AMOUNT grid, and Approved By / Checked By lines.
   * The frame and signature strip are fixed, so they repeat on every page; the bottom
   * margin keeps item rows from running underneath the signatures.
   */
  @page { margin: 12mm 14mm 42mm 14mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #000; }
  table, div, span, p { margin: 0; padding: 0; }

  .frame { position: fixed; top: -6mm; bottom: -36mm; left: -6mm; right: -6mm; border: 1.4pt solid #000; }

  .head { text-align: center; padding-top: 2mm; }
  .doc-title { font-size: 12pt; font-weight: bold; letter-spacing: 0.5px; margin-bottom: 4mm; }
  .co-name { font-size: 14pt; font-weight: bold; margin-bottom: 1mm; }
  .co-line { font-size: 10pt; line-height: 1.5; }

  .parties { width: 100%; border-collapse: collapse; margin-top: 10mm; }
  .parties td { vertical-align: top; font-size: 10pt; line-height: 1.55; }
  .refs { margin-top: 4mm; }

  .items { width: 100%; border-collapse: collapse; margin-top: 12mm; }
  .items th { border: 0.9pt solid #000; padding: 3px 6px; font-size: 10pt; font-weight: normal; }
  .items td { border-left: 0.9pt solid #000; border-right: 0.9pt solid #000; border-bottom: 0.9pt solid #000; padding: 3px 6px; font-size: 10pt; vertical-align: top; }
  .items .rolls { font-size: 7.5pt; color: #374151; margin-top: 1px; }
  .items .total td.v { font-weight: bold; }
  .items td.tail { height: 22mm; border-top: none; }
  .items td.open { border-bottom: none; }
  .r { text-align: right; }
  .c { text-align: center; }
  .nw { white-space: nowrap; }

  .page-footer { position: fixed; bottom: -28mm; left: 0; right: 0; width: 100%; }
  .sig { width: 100%; border-collapse: collapse; }
  .sig td { width: 50%; padding: 0 14mm; text-align: center; vertical-align: bottom; }
  .sig-line { border-top: 0.9pt solid #000; padding-top: 4px; font-size: 10pt; }
</style>
</head>
<body>

  @php
    $money    = fn ($n) => \Modules\Inventory\Support\Money::number((float) $n);
    $qty      = fn ($n) => \Modules\Inventory\Support\Quantity::format((float) $n);
    $currency = \Modules\Inventory\Support\Money::symbol();
    $date     = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d/m/Y') : '';

    $customer = $return->customer;
    $invoice  = $return->invoice;

    $companyAddress = collect([
        $company?->street_address,
        collect([$company?->city, $company?->state, $company?->postal_zip_code])->filter()->implode(', '),
        $company?->country,
    ])->filter()->implode(', ');

    // One address part per line, as the form prints the customer block.
    $customerLines = collect([
        $customer?->billing_address_line1,
        $customer?->billing_address_line2,
        $customer?->billing_address_line3,
        collect([$customer?->billing_city, $customer?->billing_state_province, $customer?->billing_zip_postal])->filter()->implode(', '),
        $customer?->billing_country,
    ])->filter()->values();

    /*
     * A Non-Tax invoice's prices already carry the VAT inside (its lines have tax 0),
     * so its note is a plain CREDIT NOTE with no VAT row, just as its invoice has none.
     */
    $isTaxReturn = $return->items->contains(fn ($it) => (float) $it->tax > 0);

    /*
     * The form has no discount row, so C.PRICE and AMOUNT are printed after the
     * invoice's line discount. For a Tax return, Sub Total is the net value of
     * goods and VAT is whatever is left of the stored total, so the three printed
     * figures always add up to total_amount, rounding included. A Non-Tax line's
     * amount is its stored line_total.
     */
    $lines = $return->items->map(function ($it) use ($isTaxReturn) {
        $net    = (float) $it->unit_price * (1 - (float) $it->discount / 100);
        $amount = $isTaxReturn ? round((float) $it->quantity * $net, 2) : (float) $it->line_total;

        return ['item' => $it, 'price' => $net, 'amount' => $amount];
    });

    $subTotal = round($lines->sum('amount'), 2);
    $total    = (float) $return->total_amount;
    $vat      = round($total - $subTotal, 2);

    // "VAT 18%" is only truthful when every line carries the same rate.
    $rates   = $return->items->map(fn ($it) => round((float) $it->tax, 2))->unique()->values();
    $vatRate = $rates->count() === 1
        ? rtrim(rtrim(number_format((float) $rates->first(), 2, '.', ''), '0'), '.') . '%'
        : '(mixed rates)';

    // The form is a ruled grid: short notes still show a few empty ruled rows.
    $blankRows = max(0, 4 - $lines->count());
  @endphp

  <div class="frame"></div>

  {{-- ══ LETTERHEAD ══ --}}
  <div class="head">
    <div class="doc-title">{{ $isTaxReturn ? 'TAX CREDITNOTE' : 'CREDIT NOTE' }}</div>
    <div class="co-name">{{ $company?->company_name ?? config('app.name') }}</div>
    @if($companyAddress)<div class="co-line">{{ $companyAddress }}</div>@endif
    @if($company?->company_mobile)<div class="co-line">TEL :- {{ $company->company_mobile }}</div>@endif
    @if($company?->tax_reg_no)<div class="co-line">VAT REGISTRATION NO:- {{ $company->tax_reg_no }}</div>@endif
  </div>

  {{-- ══ CUSTOMER (left) · DOCUMENT REFERENCES (right) ══ --}}
  <table class="parties">
    <tr>
      <td style="width:50%;">
        <div>{{ $customer?->customer_name ?? '—' }}</div>
        @foreach($customerLines as $line)
          <div>{{ $line }}</div>
        @endforeach
        @if($customer?->customer_tin)<div>VAT REGISTRATION NO:- {{ $customer->customer_tin }}</div>@endif
      </td>
      <td style="width:50%;"></td>
    </tr>
    <tr>
      <td></td>
      <td class="nw">
        <div class="refs">INVOICE DATE:- {{ $date($invoice?->invoice_date) }}</div>
        <div>CREDIT NOTE NO:- {{ $return->return_no }}</div>
        <div>RESPECTIVE {{ $isTaxReturn ? 'TAX ' : '' }}INVOICE NO:- {{ $invoice?->invoice_no ?? '—' }}</div>
        <div>RESPECTIVE {{ $isTaxReturn ? 'TAX ' : '' }}INVOICE DATE:- {{ $date($return->return_date) }}</div>
      </td>
    </tr>
  </table>

  {{-- ══ RETURNED ITEMS ══ --}}
  <table class="items">
    <tr>
      <th class="c nw" style="width:80px;">QTY</th>
      <th style="text-align:left;">ITEM NAME</th>
      <th class="c nw" style="width:100px;">C.PRICE</th>
      <th class="c nw" style="width:120px;">AMOUNT({{ strtoupper($currency) }})</th>
    </tr>
    @foreach($lines as $line)
      @php $item = $line['item']; @endphp
      <tr>
        <td class="nw">{{ $qty($item->quantity) }} {{ $item->unit?->symbol ?: $item->unit?->name }}</td>
        <td>
          {{ $item->product?->name }}
          @if($item->variant?->variant_label ?? $item->attribute?->attribute_name) ({{ $item->variant?->variant_label ?? $item->attribute->attribute_name }})@endif
          @if($item->pieces->isNotEmpty())
            <div class="rolls">Rolls: {{ $item->pieces->map(fn ($p) => $p->piece_code . ' (' . $qty($p->quantity) . ')')->implode(', ') }}</div>
          @endif
        </td>
        <td class="nw">{{ $money($line['price']) }}</td>
        <td class="r nw">{{ $money($line['amount']) }}</td>
      </tr>
    @endforeach
    @for($i = 0; $i < $blankRows; $i++)
      <tr><td>&nbsp;</td><td></td><td></td><td></td></tr>
    @endfor

    <tr>
      <td></td><td></td>
      <td class="nw">SUB TOTAL</td>
      <td class="r nw">{{ $money($subTotal) }}</td>
    </tr>
    @if($isTaxReturn)
      <tr>
        <td colspan="2" class="open" style="border-right:none;"></td>
        <td class="nw">VAT {{ $vatRate }}</td>
        <td class="r nw">{{ $money($vat) }}</td>
      </tr>
    @endif
    <tr class="total">
      <td colspan="2" class="open" style="border-right:none;"></td>
      <td></td>
      <td class="r nw v">{{ $money($total) }}</td>
    </tr>
    <tr><td colspan="4" class="tail"></td></tr>
  </table>

  {{-- ══ SIGNATURES ══ --}}
  <div class="page-footer">
    <table class="sig">
      <tr>
        <td><div class="sig-line">APPROVED BY</div></td>
        <td><div class="sig-line">CHECKED BY</div></td>
      </tr>
    </table>
  </div>

</body>
</html>
