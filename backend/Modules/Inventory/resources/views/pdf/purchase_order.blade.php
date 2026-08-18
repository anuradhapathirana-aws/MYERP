<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>PO — {{ $po->po_no }}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.5pt; color: #1e293b; background: #fff; }

  /* ── Page layout ── */
  .page { padding: 20px 24px 14px; }

  /* ── Header ── */
  .header { width: 100%; margin-bottom: 10px; }
  .header table { width: 100%; }
  .company-name { font-size: 12pt; font-weight: 700; color: #111827; }
  .company-sub  { font-size: 7.5pt; color: #475569; margin-top: 1px; line-height: 1.4; }
  .doc-title    { font-size: 15pt; font-weight: 700; color: #111827; text-align: left; letter-spacing: 0.2px; white-space: nowrap; }

  /* ── Doc meta (PO No / Date, under the title) ── */
  .doc-meta { margin-top: 6px; border-collapse: collapse; }
  .doc-meta td { font-size: 7.8pt; padding: 2px 0; white-space: nowrap; text-align: left; }
  .meta-label { color: #475569; padding-left: 4px; }
  .meta-value { font-weight: 700; color: #111827; padding-left: 3px; }
  .ta-r { text-align: right; }
  .ta-c { text-align: center; }

  /* ── Vendor / Deliver To ── */
  .party-row { width: 100%; margin-bottom: 10px; }
  .party-row table { width: 100%; }
  .party-title { font-size: 8pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: #111827; border-bottom: 1px solid #111827; padding-bottom: 2px; margin-bottom: 4px; }
  .party-line { font-size: 8pt; color: #334155; line-height: 1.5; }
  .party-name { font-weight: 700; color: #111827; }

  /* ── Items table ── */
  .items-wrap { margin-bottom: 4px; }
  .items-table { width: 100%; border-collapse: collapse; }
  .items-table th { background: #e2e8f0; color: #111827; font-size: 7.3pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; padding: 5px 4px; border-bottom: 1px solid #cbd5e1; text-align: left; }
  .items-table td { font-size: 8pt; padding: 4px; border-bottom: 1px solid #f1f5f9; vertical-align: top; color: #1e293b; }
  .items-table tfoot td { border-top: 1px solid #cbd5e1; border-bottom: none; font-weight: 700; padding-top: 5px; }
  .unit  { font-size: 6.8pt; color: #64748b; }
  .mono  { font-family: 'DejaVu Sans Mono', monospace; font-size: 7.5pt; color: #475569; }
  .bold  { font-weight: 700; }
  .muted { color: #94a3b8; }

  /* ── Totals ── */
  .totals-wrap { width: 100%; margin-top: 6px; margin-bottom: 10px; }
  .totals-wrap table { width: 100%; }
  .amount-words { font-size: 8pt; font-weight: 700; color: #111827; }
  .totals-box { width: 220px; float: right; border-collapse: collapse; }
  .totals-box td { font-size: 8.5pt; padding: 2.5px 4px; }
  .totals-box .totals-sep td { border-top: 1.5px solid #111827; font-size: 10pt; font-weight: 700; padding-top: 5px; }

  /* ── Note ── */
  .note-box { border: 1px solid #cbd5e1; border-radius: 3px; padding: 8px 10px; margin-top: 6px; min-height: 46px; width: 60%; }
  .note-label { font-size: 6.8pt; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; }

  /* ── Signatures ── */
  .sig-table { width: 100%; border-collapse: collapse; margin-top: 40px; }
  .sig-table td { text-align: center; padding: 0 10px; vertical-align: bottom; }
  .sig-name { font-size: 7.5pt; color: #334155; margin-bottom: 3px; min-height: 10px; }
  .sig-line { border-top: 1px dotted #94a3b8; padding-top: 3px; font-size: 7.5pt; color: #475569; }

  /* ── Footer ── */
  .footer { border-top: 1px solid #e2e8f0; margin-top: 16px; padding-top: 8px; font-size: 6.8pt; color: #94a3b8; text-align: center; }
  .footer-company { font-size: 7.5pt; font-weight: 700; color: #475569; }
</style>
</head>
<body>
<div class="page">

  @php
    $company = $po->location?->company;
    $store   = $po->store;

    $gross       = $po->items->sum(fn ($it) => (float) $it->quantity_ordered * (float) $it->unit_price);
    $discount    = $po->items->sum(fn ($it) => (float) $it->quantity_ordered * (float) $it->unit_price * ((float) $it->discount / 100));
    $net         = (float) $po->grand_total;
    $discountPct = $gross > 0 ? round($discount / $gross * 100, 2) : 0;
    $totalQty    = $po->items->sum(fn ($it) => (float) $it->quantity_ordered);

    $amountInWords = \Modules\Inventory\Support\NumberToWords::convert($net, \Modules\Inventory\Support\Money::code());

    // Cells carry the bare number; the column header says which currency it is.
    $money = fn ($n) => \Modules\Inventory\Support\Money::number((float) $n);
  @endphp

  {{-- ══ HEADER ══════════════════════════════════════════════ --}}
  <div class="header">
    <table>
      <tr>
        <td style="width:55%; vertical-align:top;">
          @if($company)
          <div class="company-name">{{ $company->company_name }}</div>
          <div class="company-sub">
            {{ collect([$company->street_address, $company->city, $company->state, $company->postal_zip_code])->filter()->implode(', ') }}
          </div>
          @if($company->company_email)
          <div class="company-sub">{{ $company->company_email }}</div>
          @endif
        @endif
        </td>
        <td style="width:45%; vertical-align:top;">
          <div class="doc-title">PURCHASE ORDER</div>
          <table class="doc-meta">
            <tr>
              <td class="meta-label" style="padding-left:0;">PO No :</td>
              <td class="meta-value">{{ $po->po_no }}</td>
              <td class="meta-label">Date :</td>
              <td class="meta-value" style="padding-right:0;">{{ $po->order_date?->format('Y-m-d') }}</td>
            </tr>
            <tr>
              <td class="meta-label" style="padding-left:0;">Location :</td>
              <td class="meta-value">{{ $po->location?->location_name ?? '—' }}</td>
              <td class="meta-label">Exp. Date :</td>
              <td class="meta-value" style="padding-right:0;">{{ $po->expected_delivery_date?->format('Y-m-d') ?? '—' }}</td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </div>

  {{-- ══ VENDOR / DELIVER TO ═════════════════════════════════ --}}
  <div class="party-row">
    <table>
      <tr>
        {{-- Vendor --}}
        <td style="width:55%; vertical-align:top;">
          <div class="party-title">Vendor</div>
          @if($po->supplier)
          <div class="party-line party-name">{{ $po->supplier->supplier_name }}</div>
          @if($po->billing_address)<div class="party-line">{{ $po->billing_address }}</div>@endif
          @if($po->contact_person_name)
          <div class="party-line">{{ $po->contact_person_name }}{{ $po->contact_person_phone ? ' — ' . $po->contact_person_phone : '' }}</div>
          @endif
          @else
          <div class="party-line muted">No supplier linked.</div>
          @endif
        </td>
        {{-- Deliver To --}}
        <td style="width:45%; vertical-align:top;">
          <div class="party-title">Deliver To</div>
          @if($store)
          <div class="party-line party-name">{{ $store->store_name }}</div>
          @php
            $storeAddress = collect([$store->address_line_1, $store->address_line_2])->filter()->implode(', ');
            $storeCityLine = collect([$store->city, $store->state, $store->postal_code])->filter()->implode(', ');
          @endphp
          @if($storeAddress)<div class="party-line">{{ $storeAddress }}</div>@endif
          @if($storeCityLine)<div class="party-line">{{ $storeCityLine }}</div>@endif
          @if($store->phone)<div class="party-line">{{ $store->phone }}</div>@endif
          @else
          <div class="party-line muted">No store linked.</div>
          @endif
        </td>
      </tr>
    </table>
  </div>

  {{-- ══ ITEMS TABLE ════════════════════════════════════════ --}}
  <div class="items-wrap">
    <table class="items-table">
      <thead>
        <tr>
          <th style="width:85px;">Code</th>
          <th>Description</th>
          <th class="ta-c" style="width:55px;">Color</th>
          <th class="ta-r" style="width:70px;">Quantity</th>
          <th class="ta-r" style="width:78px;">{{ \Modules\Inventory\Support\Money::label('Unit Price') }}</th>
          <th class="ta-r" style="width:80px;">{{ \Modules\Inventory\Support\Money::label('Amount') }}</th>
        </tr>
      </thead>
      <tbody>
        @foreach($po->items as $item)
        <tr>
          <td class="mono">{{ $item->product?->product_code ?? '—' }}</td>
          <td class="bold">{{ $item->product?->name ?? '—' }}</td>
          <td class="ta-c">{{ $item->attribute?->attribute_name ?? '—' }}</td>
          <td class="ta-r">
            {{ number_format((float) $item->quantity_ordered, 2) }}
            <span class="unit">{{ $item->unit?->symbol ?? $item->unit?->name }}</span>
          </td>
          <td class="ta-r">{{ $money($item->unit_price) }}</td>
          <td class="ta-r bold">{{ $money($item->line_total) }}</td>
        </tr>
        @endforeach
      </tbody>
      <tfoot>
        <tr>
          <td></td>
          <td></td>
          <td></td>
          <td class="ta-r">{{ number_format($totalQty, 2) }}</td>
          <td></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

  {{-- ══ TOTALS ══════════════════════════════════════════════ --}}
  <div class="totals-wrap">
    <table class="totals-box">
      <tr>
        <td class="meta-label">Subtotal</td>
        <td class="ta-r">{{ $money($gross) }}</td>
      </tr>
      @if($discount > 0)
      <tr>
        <td class="meta-label">Discount {{ rtrim(rtrim(number_format($discountPct, 2), '0'), '.') }}%</td>
        <td class="ta-r">- {{ $money($discount) }}</td>
      </tr>
      @endif
      <tr class="totals-sep">
        <td>Total</td>
        <td class="ta-r">{{ \Modules\Inventory\Support\Money::format($net) }}</td>
      </tr>
    </table>
  </div>

  <div class="amount-words">{{ $amountInWords }}</div>

  {{-- ══ NOTE ════════════════════════════════════════════════ --}}
  @if($po->remarks)
  <div class="note-box">
    <div class="note-label">Note</div>
    {{ $po->remarks }}
  </div>
  @endif

  {{-- ══ SIGNATURES ══════════════════════════════════════════ --}}
  <table class="sig-table">
    <tr>
      <td style="width:33%;">
        <div class="sig-name">&nbsp;</div>
        <div class="sig-line">Checked By</div>
      </td>
      <td style="width:33%;">
        <div class="sig-name">&nbsp;</div>
        <div class="sig-line">Approved By</div>
      </td>
      <td style="width:34%;">
        <div class="sig-name">{{ $po->createdBy?->name }}</div>
        <div class="sig-line">Prepared By</div>
      </td>
    </tr>
  </table>

  {{-- ══ FOOTER ══════════════════════════════════════════════ --}}
  <div class="footer">
    Printed {{ now()->format('l, d M Y h:i A') }}{{ $po->createdBy ? ' | ' . $po->createdBy->name : '' }}
    @if($company)
    <div class="footer-company" style="margin-top:4px;">{{ $company->company_name }}</div>
    <div>
      {{ collect([$company->street_address, $company->city, $company->state, $company->postal_zip_code])->filter()->implode(', ') }}
    </div>
    @if($company->company_mobile)
    <div>Contact : {{ $company->company_mobile }}</div>
    @endif
    @endif
  </div>

</div>
</body>
</html>
