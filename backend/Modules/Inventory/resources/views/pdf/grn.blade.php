<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>GRN — {{ $grn->grn_no }}</title>
<style>
  /*
   * The bottom margin is oversized to reserve room for the fixed page-footer
   * below (see .page-footer) — dompdf never flows body content into the page
   * margin, so the signature block can never be overwritten no matter how many
   * item rows the document has. Top/left/right stay at 0 since `.page`'s own
   * padding already provides that visual margin.
   */
  @page { margin: 0 0 50mm 0; }

  /*
   * A `margin: 0` on the universal selector matches <html> too, and dompdf reads
   * its page margin from the root frame — so it would silently wipe out the
   * @page rule above and print everything hard against the paper edge (and,
   * worse, break the fixed-position math for .page-footer below). Reset margin
   * on the actual elements that need it, never on `*`.
   */
  * { box-sizing: border-box; padding: 0; }
  body, table, div, span { margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8.5pt; color: #1e293b; background: #fff; }

  /* ── Page layout ── */
  .page { padding: 20px 24px 14px; }

  /* ── Header ── */
  .header { width: 100%; margin-bottom: 10px; }
  .header table { width: 100%; }
  .company-name { font-size: 12pt; font-weight: 700; color: #111827; }
  .company-sub  { font-size: 7.5pt; color: #475569; margin-top: 1px; line-height: 1.4; }
  .doc-title    { font-size: 15pt; font-weight: 700; color: #111827; text-align: left; letter-spacing: 0.2px; white-space: nowrap; }

  /* ── Doc meta (GRN No / PO No, under the title) ── */
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

  /*
   * Pinned inside the reserved bottom margin with `position: fixed` — dompdf
   * renders a fixed-position element at the same spot on every page, so the
   * signature block sits at a true, consistent footer position instead of
   * drifting with however much content is above it. bottom: -44mm sits
   * comfortably inside the 50mm reserved margin (never touching the body
   * content box) while stopping 6mm short of the physical page edge.
   */
  .page-footer { position: fixed; bottom: -44mm; left: 0; right: 0; width: 100%; padding: 0 24px; }

  /* Blank space above the ruled line — this is where people actually sign. */
  .sig-space { height: 22mm; }

  /* ── Signatures ── */
  .sig-table { width: 100%; border-collapse: collapse; }
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
    $company  = $grn->location?->company;
    $store    = $grn->store;

    $gross       = $grn->items->sum(fn ($it) => (float) $it->quantity_received * (float) $it->unit_price);
    $discount    = $grn->items->sum(fn ($it) => (float) $it->quantity_received * (float) $it->unit_price * ((float) $it->discount / 100));
    $net         = (float) $grn->total_amount;
    $discountPct = $gross > 0 ? round($discount / $gross * 100, 2) : 0;
    $totalQty    = $grn->items->sum(fn ($it) => (float) $it->quantity_received);

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
          <div class="doc-title">GOODS RECEIVED NOTE</div>
          <table class="doc-meta">
            <tr>
              <td class="meta-label" style="padding-left:0;">GRN No :</td>
              <td class="meta-value">{{ $grn->grn_no }}</td>
              <td class="meta-label">Date :</td>
              <td class="meta-value" style="padding-right:0;">{{ $grn->grn_date?->format('Y-m-d') }}</td>
            </tr>
            @if($grn->purchaseOrder)
            <tr>
              <td class="meta-label" style="padding-left:0;">PO No :</td>
              <td class="meta-value">{{ $grn->purchaseOrder->po_no }}</td>
              <td class="meta-label">Date :</td>
              <td class="meta-value" style="padding-right:0;">{{ $grn->purchaseOrder->order_date?->format('Y-m-d') }}</td>
            </tr>
            @endif
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
          @if($grn->supplier)
          <div class="party-line party-name">{{ $grn->supplier->supplier_name }}</div>
          @php
            $vendorAddress = collect([
              $grn->supplier->bil_address_line_1,
              $grn->supplier->bil_address_line_2,
              $grn->supplier->bil_address_line_3,
            ])->filter()->implode(', ');
            $vendorCityLine = collect([
              $grn->supplier->bil_city,
              $grn->supplier->bil_postal_code,
            ])->filter()->implode(', ');
          @endphp
          @if($vendorAddress)<div class="party-line">{{ $vendorAddress }}</div>@endif
          @if($vendorCityLine)<div class="party-line">{{ $vendorCityLine }}</div>@endif
          @if($grn->supplier->mobile ?? $grn->supplier->land_line ?? null)
          <div class="party-line">{{ $grn->supplier->mobile ?: $grn->supplier->land_line }}</div>
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
        @foreach($grn->items as $item)
        <tr>
          <td class="mono">{{ $item->product?->product_code ?? '—' }}</td>
          <td class="bold">{{ $item->product?->name ?? '—' }}</td>
          <td class="ta-c">{{ $item->attribute?->attribute_name ?? '—' }}</td>
          <td class="ta-r">
            {{ number_format((float) $item->quantity_received, 2) }}
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
  @if($grn->remarks)
  <div class="note-box">
    <div class="note-label">Note</div>
    {{ $grn->remarks }}
  </div>
  @endif

</div>

{{-- Must be a direct child of <body>, NOT nested inside .page — dompdf only
     positions `position: fixed` reliably relative to the true page box when
     the element sits at the top level; nested inside another block it can
     end up positioned against that block's box instead, or not render. --}}
<div class="page-footer">
  <div class="sig-space"></div>
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
        <div class="sig-name">{{ $grn->receivedBy?->name }}</div>
        <div class="sig-line">Prepared By</div>
      </td>
    </tr>
  </table>

  <div class="footer">
    Printed {{ now()->format('l, d M Y h:i A') }}{{ $grn->receivedBy ? ' | ' . $grn->receivedBy->name : '' }}
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
