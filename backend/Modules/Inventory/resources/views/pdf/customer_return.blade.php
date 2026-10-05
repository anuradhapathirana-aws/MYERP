<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Sales Return — {{ $return->return_no }}</title>
<style>
  /*
   * A4 portrait, black-on-white with light grey accents only — same DomPDF-safe rules
   * and type scale as the customer receipt. The bottom margin reserves room for the
   * fixed signature strip so return lines can never run underneath it.
   */
  @page { margin: 12mm 12mm 56mm 12mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #111827; }
  table, div, span, p { margin: 0; padding: 0; }

  .head { width: 100%; border-collapse: collapse; border-bottom: 1.2pt solid #1f2937; padding-bottom: 6px; }
  .co-name { font-size: 15.5pt; font-weight: bold; }
  .co-sub  { font-size: 9.5pt; color: #6b7280; }

  .title { text-align: center; font-size: 15pt; font-weight: bold; letter-spacing: 3.5px; text-transform: uppercase; margin: 10px 0 9px; }

  .meta { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .meta td { border: 0.8pt solid #1f2937; padding: 5px 9px; }
  .lbl { color: #6b7280; font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.5px; }
  .val { font-weight: bold; }

  .items { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .items th { border: 0.8pt solid #1f2937; padding: 4px 6px; font-size: 8.5pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; text-align: left; }
  .items td { border: 0.8pt solid #1f2937; padding: 4px 6px; font-size: 9.5pt; }
  .items .rolls td { font-size: 8.5pt; color: #4b5563; border-top: none; }
  .r { text-align: right; }
  .nw { white-space: nowrap; }

  .totals { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .totals td { padding: 3px 9px; }
  .totals td.k { text-align: right; color: #6b7280; font-size: 9.5pt; }
  .totals td.v { text-align: right; width: 145px; font-weight: bold; border-bottom: 0.6pt solid #d1d5db; }
  .totals tr.grand td { font-size: 12pt; font-weight: bold; border-top: 1.2pt solid #1f2937; border-bottom: none; padding-top: 4px; }

  .words { border: 0.8pt solid #1f2937; padding: 6px 9px; margin-bottom: 9px; font-size: 9.5pt; }

  .page-footer { position: fixed; bottom: -50mm; left: 0; right: 0; width: 100%; }
  .declaration { font-size: 9.5pt; color: #374151; line-height: 1.4; }
  .sig-space { height: 18mm; }

  .sig { width: 100%; border-collapse: collapse; }
  .sig td { width: 50%; padding: 0 18px; text-align: center; vertical-align: bottom; }
  .sig-line { border-top: 0.8pt solid #1f2937; padding-top: 3px; font-size: 9pt; color: #6b7280; }

  .note { margin-top: 12px; text-align: center; font-size: 8.5pt; color: #9ca3af; }
</style>
</head>
<body>

  @php
    $money    = fn ($n) => \Modules\Inventory\Support\Money::number((float) $n);
    $qty      = fn ($n) => \Modules\Inventory\Support\Quantity::format((float) $n);
    $currency = \Modules\Inventory\Support\Money::symbol();
    $words    = \Modules\Inventory\Support\NumberToWords::convert((float) $return->total_amount, \Modules\Inventory\Support\Money::code());

    $companyAddress = collect([
        $company?->street_address,
        collect([$company?->city, $company?->state, $company?->postal_zip_code])->filter()->implode(', '),
        $company?->country,
    ])->filter()->implode(', ');

    $companyContact = collect([
        $company?->company_mobile ? 'Tel: '.$company->company_mobile : null,
        $company?->company_email,
        $company?->company_email_2,
    ])->filter()->implode(' | ');

    $creditNote = $return->creditNote;
  @endphp

  {{-- ══ LETTERHEAD ══ --}}
  <table class="head">
    <tr>
      <td style="padding-bottom:6px;">
        <div class="co-name">{{ $company?->company_name ?? config('app.name') }}</div>
        @if($companyAddress)<div class="co-sub">{{ $companyAddress }}</div>@endif
        @if($companyContact)<div class="co-sub">{{ $companyContact }}</div>@endif
      </td>
    </tr>
  </table>

  <div class="title">Sales Return Note</div>

  {{-- ══ META ══ --}}
  <table class="meta">
    <tr>
      <td style="width:25%;"><span class="lbl">Return No</span><br><span class="val">{{ $return->return_no }}</span></td>
      <td style="width:25%;"><span class="lbl">Return Date</span><br><span class="val">{{ $return->return_date?->format('Y-m-d') }}</span></td>
      <td style="width:25%;"><span class="lbl">Invoice No</span><br><span class="val">{{ $return->invoice?->invoice_no ?? '—' }}</span></td>
      <td style="width:25%;"><span class="lbl">Delivery Order</span><br><span class="val">{{ $return->deliveryOrder?->do_no ?? '—' }}</span></td>
    </tr>
    <tr>
      <td colspan="4">
        <span class="lbl">Customer</span><br>
        <span class="val">{{ $return->customer?->customer_name ?? '—' }}</span>
        @if($return->customer?->customer_code) <span style="color:#6b7280; font-size:9.5pt;">({{ $return->customer->customer_code }})</span>@endif
      </td>
    </tr>
  </table>

  {{-- ══ RETURNED ITEMS ══ --}}
  <table class="items">
    <tr>
      <th style="width:22px;">#</th>
      <th>Item</th>
      <th class="r nw" style="width:70px;">Qty</th>
      <th class="r nw" style="width:72px;">Price</th>
      <th class="nw" style="width:78px;">Reason</th>
      <th class="nw" style="width:58px;">Cond.</th>
      <th class="nw" style="width:80px;">Store</th>
      <th class="r nw" style="width:88px;">Amount</th>
    </tr>
    @foreach($return->items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>
          {{ $item->product?->product_code }} — {{ $item->product?->name }}
          @if($item->attribute?->attribute_name) <span style="color:#6b7280;">({{ $item->attribute->attribute_name }})</span>@endif
        </td>
        <td class="r nw">{{ $qty($item->quantity) }} {{ $item->unit?->symbol ?: $item->unit?->name }}</td>
        <td class="r nw">{{ $money($item->unit_price) }}</td>
        <td class="nw">{{ $item->reason->label() }}</td>
        <td class="nw">{{ $item->condition->label() }}</td>
        <td class="nw">{{ $item->store?->store_name }}</td>
        <td class="r nw">{{ $money($item->line_total) }}</td>
      </tr>
      @if($item->pieces->isNotEmpty())
        <tr class="rolls">
          <td></td>
          <td colspan="7">
            Rolls: {{ $item->pieces->map(fn ($p) => $p->piece_code . ' (' . $qty($p->quantity) . ')' . ($p->restoredPiece && $p->restoredPiece->piece_code !== $p->piece_code ? ' → ' . $p->restoredPiece->piece_code : ''))->implode(', ') }}
          </td>
        </tr>
      @endif
    @endforeach
  </table>

  {{-- ══ TOTALS ══ --}}
  <table class="totals">
    <tr><td class="k">Credited to Invoice</td><td class="v">{{ $money($return->applied_to_invoice) }}</td></tr>
    @if($creditNote)
      <tr><td class="k">Credit Note {{ $creditNote->credit_note_no }}</td><td class="v">{{ $money($creditNote->amount) }}</td></tr>
    @endif
    <tr class="grand"><td class="k" style="color:#111827;">Total Return Value ({{ $currency }})</td><td class="v">{{ $money($return->total_amount) }}</td></tr>
  </table>

  <div class="words"><span class="lbl">Amount in words:</span> {{ $words }}</div>

  @if($return->remarks)
    <div style="font-size:9.5pt; color:#374151; margin-bottom:9px;"><span class="lbl">Remarks:</span> {{ $return->remarks }}</div>
  @endif

  {{-- ══ SIGNATURES ══ --}}
  <div class="page-footer">
    <div class="declaration">Goods listed above were received back from the customer. The return value will be credited to the customer account against the invoice shown.</div>
    <div class="sig-space"></div>
    <table class="sig">
      <tr>
        <td><div class="sig-line">Received By (Signature &amp; Date)</div></td>
        <td><div class="sig-line">Customer Signature</div></td>
      </tr>
    </table>

    <div class="note">Computer-generated sales return note. Confirmed {{ $return->confirmed_at?->format('Y-m-d H:i') }}.</div>
  </div>

</body>
</html>
