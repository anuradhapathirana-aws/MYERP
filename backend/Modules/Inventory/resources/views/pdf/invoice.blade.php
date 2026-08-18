<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $invoice->invoice_type === 'non_tax' ? 'Invoice' : 'Tax Invoice' }} — {{ $invoice->invoice_no }}</title>
<style>
  /*
   * DomPDF-safe layout: every block is a plain collapsed table with explicit
   * widths and heights. Percentage heights, min-height and border-spacing all
   * resolve badly here and previously spilled this document across three pages.
   */
  /*
   * DomPDF takes the page margin from the root frame, so ANY rule that sets a margin
   * on `html` — including a `* { margin: 0 }` reset — silently wipes @page and prints
   * the invoice hard against the paper edge. Reset `body`, never `html` or `*`.
   */
  /*
   * The bottom margin is deliberately oversized to reserve room for the fixed
   * page-footer below (see .page-footer) — dompdf never flows body content into
   * the page margin, so whatever fits in this reserved strip can never be
   * overwritten by the invoice body, no matter how many item rows it has.
   */
  @page { margin: 10mm 10mm 42mm 10mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; }
  table, div, span { margin: 0; padding: 0; }

  body {
    font-family: 'DejaVu Sans', Arial, sans-serif;
    font-size: 8pt;
    color: #111827;
    background: #fff;
  }

  /* ── Title ── */
  .title-wrap { text-align: center; margin-bottom: 10px; }
  .title-box {
    display: inline-block;
    color: #111827;
    padding: 0 0 2px;
    font-size: 13pt;
    font-weight: bold;
    letter-spacing: 3px;
    text-transform: uppercase;
  }

  /* ── Boxed form rows ── */
  .row { width: 100%; border-collapse: collapse; margin-bottom: 5px; }
  .row td.box  { border: 0.8pt solid #1f2937; padding: 5px 7px; vertical-align: top; }
  .row td.gap  { border: 0; width: 9px; }
  .row td.tall { height: 94px; }
  .row td.info { height: 34px; }

  .lbl  { color: #6b7280; font-size: 7.2pt; }
  .val  { font-weight: bold; color: #111827; }
  .line { line-height: 1.5; }

  /* The party boxes lead with the trading name, then drop to the statutory detail. */
  .party-name { font-size: 9pt; font-weight: bold; color: #111827; }
  .party-cap  {
    font-size: 6.5pt; font-weight: bold; color: #6b7280;
    text-transform: uppercase; letter-spacing: 0.6px;
    border-bottom: 0.5pt solid #d1d5db;
    padding-bottom: 2px; margin-bottom: 3px;
  }

  /* ── Goods table ── */
  .items { width: 100%; border-collapse: collapse; }
  .items th {
    background: #eef1f5;
    border: 0.8pt solid #1f2937;
    padding: 5px 5px;
    font-size: 6.8pt;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    text-align: center;
    vertical-align: middle;
  }
  .items td { border: 0.8pt solid #1f2937; padding: 4px 5px; vertical-align: top; height: 17px; }
  .r    { text-align: right; }
  .sub  { font-size: 6.5pt; color: #6b7280; }
  .code { font-size: 7.2pt; color: #374151; }

  /* Color tags listed under a merged product row. */
  .tags { margin-top: 3px; }
  .tag {
    display: inline-block;
    background: #eef1f5;
    border: 0.5pt solid #cbd5e1;
    border-radius: 3px;
    padding: 1px 5px;
    margin: 2px 3px 0 0;
    font-size: 6.3pt;
    color: #374151;
  }

  /* ── Totals ── */
  .totals { width: 100%; border-collapse: collapse; }
  .totals td { border: 0.8pt solid #1f2937; padding: 4px 6px; }
  .totals td.amt { text-align: right; width: 118px; font-weight: bold; }
  .totals tr.grand td { background: #eef1f5; font-weight: bold; font-size: 9pt; }

  /* ── Footer ── */
  .foot { width: 100%; border-collapse: collapse; margin-top: 5px; }
  .foot td { border: 0.8pt solid #1f2937; padding: 5px 7px; }

  /*
   * Pinned inside the reserved bottom margin with `position: fixed` — dompdf renders
   * a fixed-position element at the same spot on every page, which is what makes this
   * a true footer instead of something that drifts with how much content is above it.
   * bottom: -36mm sits comfortably inside the 42mm reserved margin (never touching the
   * body content box) while stopping 6mm short of the physical page edge.
   */
  .page-footer { position: fixed; bottom: -36mm; left: 0; right: 0; width: 100%; }

  /* Blank space above the ruled line — this is where people actually sign. */
  .sig-space { height: 22mm; }

  .sig { width: 100%; border-collapse: collapse; }
  .sig td { width: 50%; padding: 0 14px; text-align: center; vertical-align: bottom; }
  .sig-line { border-top: 0.8pt dotted #1f2937; padding-top: 3px; font-size: 7pt; color: #6b7280; }

  .note { margin-top: 10px; text-align: center; font-size: 6.5pt; color: #9ca3af; }
</style>
</head>
<body>

  @php
    // The supplier is the company that issued the invoice, named on the invoice itself.
    $company  = $invoice->company;
    $customer = $invoice->customer;

    /*
     * Date of Delivery is when the goods actually moved — the delivery order's own
     * delivery_date. A direct/advance invoice has no delivery order, so it falls back
     * to the date the invoice was issued.
     */
    $deliveryDate = $invoice->deliveryOrder?->delivery_date ?? $invoice->issued_at;

    $companyAddress = collect([
        $company?->street_address,
        collect([$company?->city, $company?->state, $company?->postal_zip_code])->filter()->implode(', '),
        $company?->country,
    ])->filter()->implode(', ');

    $customerAddress = collect([
        $customer?->billing_address_line1,
        $customer?->billing_address_line2,
        $customer?->billing_address_line3,
        collect([$customer?->billing_city, $customer?->billing_state_province, $customer?->billing_zip_postal])
            ->filter()->implode(', '),
        $customer?->billing_country,
    ])->filter()->implode(', ');

    /*
     * Totals reconcile to the stored grand_total:
     *   value of supply - discount + transport + VAT = grand_total
     * so every printed row is a real figure, never a re-derived one.
     */
    $valueOfSupply = $invoice->items->sum(fn ($it) => (float) $it->quantity * (float) $it->unit_price);
    $discount      = $invoice->items->sum(fn ($it) => (float) $it->quantity * (float) $it->unit_price * ((float) $it->discount / 100));
    $vat           = $invoice->items->sum(fn ($it) => (float) $it->quantity * (float) $it->unit_price * ((float) $it->tax / 100));
    $transport     = (float) $invoice->transport_charge;

    // "@ 18%" is only truthful when every line carries the same rate; a mixed-rate
    // invoice says so instead of naming a rate that isn't uniform.
    $rates   = $invoice->items->map(fn ($it) => round((float) $it->tax, 2))->unique()->values();
    $vatRate = $rates->count() === 1
        ? '@ ' . rtrim(rtrim(number_format((float) $rates->first(), 2, '.', ''), '0'), '.') . '%'
        : '@ mixed rates';

    $money    = fn ($n) => \Modules\Inventory\Support\Money::number((float) $n);
    $currency = \Modules\Inventory\Support\Money::symbol();
    $words    = \Modules\Inventory\Support\NumberToWords::convert(
        (float) $invoice->grand_total,
        \Modules\Inventory\Support\Money::code(),
    );

    /*
     * Display rows for the goods table. A product sold in several colors prints
     * as one row with the colors listed as tags and quantities summed, rather
     * than repeating the product line per color. Rows only merge when product,
     * quantity unit AND unit price all match — a different UOM or price means
     * the line is priced/measured differently and must stay visibly separate.
     *
     * "Color" is detected the same way Purchase Request/PO/GRN forms already
     * do it elsewhere in this module: by attribute_type_name, not by guessing
     * from the value. Non-color attributes (Width, Size, GSM, ...) keep the
     * original one-row-per-line "Product — Attribute" behaviour untouched.
     */
    $colorTypeIds = \Modules\Inventory\Models\AttributeType::whereRaw(
        'LOWER(attribute_type_name) IN (?, ?)',
        ['color', 'colour'],
    )->pluck('id');

    // Plain array, not a Collection — accumulating into a nested element
    // ($rows[$key]['quantity'] += ...) doesn't reliably mutate through
    // Collection's ArrayAccess, so a plain array keeps the merge logic correct.
    $rows = [];
    foreach ($invoice->items as $item) {
        $isColor = $item->attribute && $colorTypeIds->contains($item->attribute->attribute_type_id);

        if (! $isColor) {
            // Not a color line (or has no attribute at all) — never merges with
            // another line, so key it by the item's own id.
            $rows['item-' . $item->id] = [
                'product'    => $item->product,
                'unit'       => $item->unit,
                'quantity'   => (float) $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'colors'     => [],
                'suffix'     => $item->attribute?->attribute_name,
            ];
            continue;
        }

        $key = 'color-' . $item->product_id . '-' . $item->unit_id . '-' . $item->unit_price;

        if (isset($rows[$key])) {
            $rows[$key]['quantity'] += (float) $item->quantity;
            if (! in_array($item->attribute->attribute_name, $rows[$key]['colors'], true)) {
                $rows[$key]['colors'][] = $item->attribute->attribute_name;
            }
            continue;
        }

        $rows[$key] = [
            'product'    => $item->product,
            'unit'       => $item->unit,
            'quantity'   => (float) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'colors'     => [$item->attribute->attribute_name],
            'suffix'     => null,
        ];
    }
    $displayRows = array_values($rows);

    // Pad short invoices out to the printed form's ruled rows, without ever adding
    // enough height to push the document onto a second page.
    $minRows = max(0, 4 - count($displayRows));

    // A non-tax invoice is titled plainly "Invoice" — its prices already carry
    // the VAT inside, so it must not present itself as a tax invoice.
    $isTaxInvoice = $invoice->invoice_type !== 'non_tax';
    $docTitle     = $isTaxInvoice ? 'Tax Invoice' : 'Invoice';
  @endphp

  {{-- ══ TITLE ══ --}}
  <div class="title-wrap">
    <div class="title-box">{{ $docTitle }}</div>
  </div>

  {{-- ══ DATE / INVOICE NO ══ --}}
  <table class="row">
    <tr>
      <td class="box" style="width:50%;">
        <span class="lbl">Date of Invoice:</span>
        <span class="val">{{ $invoice->invoice_date?->format('d/m/Y') }}</span>
      </td>
      <td class="gap"></td>
      <td class="box" style="width:50%;">
        <span class="lbl">{{ $docTitle }} No.:</span>
        <span class="val">{{ $invoice->invoice_no }}</span>
      </td>
    </tr>
  </table>

  {{-- ══ SUPPLIER / PURCHASER ══ --}}
  <table class="row">
    <tr>
      <td class="box tall" style="width:50%;">
        <div class="party-cap">Supplier</div>
        <div class="line"><span class="lbl">Supplier's Name:</span> <span class="party-name">{{ $company?->company_name }}</span></div>
        @if($isTaxInvoice)
        <div class="line"><span class="lbl">Supplier's TIN:</span> <span class="val">{{ $company?->tax_reg_no }}</span></div>
        @endif
        <div class="line"><span class="lbl">Address:</span> <span class="val">{{ $companyAddress }}</span></div>
        <div class="line"><span class="lbl">Telephone No:</span> <span class="val">{{ $company?->company_mobile }}</span></div>
      </td>
      <td class="gap"></td>
      <td class="box tall" style="width:50%;">
        <div class="party-cap">Purchaser</div>
        <div class="line"><span class="lbl">Purchaser's Name:</span> <span class="party-name">{{ $customer?->customer_name }}</span></div>
        @if($isTaxInvoice)
        <div class="line"><span class="lbl">Purchaser's TIN:</span> <span class="val">{{ $customer?->customer_tin }}</span></div>
        @endif
        <div class="line"><span class="lbl">Address:</span> <span class="val">{{ $customerAddress }}</span></div>
        <div class="line"><span class="lbl">Telephone No:</span> <span class="val">{{ $customer?->customer_land_line ?: $customer?->customer_mobile }}</span></div>
      </td>
    </tr>
  </table>

  {{-- ══ DELIVERY / PLACE OF SUPPLY ══ --}}
  <table class="row">
    <tr>
      <td class="box" style="width:50%;">
        <span class="lbl">Date of Delivery:</span>
        <span class="val">{{ $deliveryDate?->format('d/m/Y') }}</span>
      </td>
      <td class="gap"></td>
      <td class="box" style="width:50%;">
        <span class="lbl">Place of Supply:</span>
        <span class="val">{{ $company?->company_name }}</span>
      </td>
    </tr>
  </table>

  {{-- ══ ADDITIONAL INFORMATION ══ --}}
  <table class="row">
    <tr>
      <td class="box info">
        <span class="lbl">Additional Information if any:</span>
        <span class="val">{{ $invoice->remarks }}</span>
      </td>
    </tr>
  </table>

  {{-- ══ GOODS OR SERVICES ══ --}}
  <table class="items">
    <thead>
      <tr>
        <th style="width:74px;">Reference</th>
        <th>Description of Goods or Services</th>
        <th style="width:70px;">Quantity</th>
        <th style="width:66px;">Unit Price</th>
        <th style="width:96px;">Amount Excluding VAT ({{ $currency }}.)</th>
      </tr>
    </thead>
    <tbody>
      @foreach($displayRows as $row)
        <tr>
          <td class="code">{{ $row['product']?->product_code }}</td>
          <td>
            <span class="val">{{ $row['product']?->name }}</span>
            @if($row['suffix'])
              <span class="sub">— {{ $row['suffix'] }}</span>
            @endif
            @if(count($row['colors']))
              <div class="tags">
                @foreach($row['colors'] as $color)
                  <span class="tag">{{ $color }}</span>
                @endforeach
              </div>
            @endif
          </td>
          <td class="r">
            {{ number_format($row['quantity'], 2) }}
            @if($row['unit']?->name)
              <span class="sub">{{ $row['unit']->name }}</span>
            @endif
          </td>
          <td class="r">{{ $money($row['unit_price']) }}</td>
          <td class="r val">{{ $money($row['quantity'] * $row['unit_price']) }}</td>
        </tr>
      @endforeach

      @for($i = 0; $i < $minRows; $i++)
        <tr>
          <td>&nbsp;</td>
          <td></td>
          <td></td>
          <td></td>
          <td></td>
        </tr>
      @endfor
    </tbody>
  </table>

  {{-- ══ TOTALS ══ --}}
  <table class="totals">
    <tr>
      <td>Total Value of Supply:</td>
      <td class="amt">{{ $money($valueOfSupply) }}</td>
    </tr>
    @if($discount > 0)
    <tr>
      <td>Less: Discount</td>
      <td class="amt">({{ $money($discount) }})</td>
    </tr>
    @endif
    @if($transport > 0)
    <tr>
      <td>Add: Transport Charge</td>
      <td class="amt">{{ $money($transport) }}</td>
    </tr>
    @endif
    <tr>
      <td>VAT Amount (Total Value of Supply {{ $vatRate }}):</td>
      <td class="amt">{{ $money($vat) }}</td>
    </tr>
    <tr class="grand">
      <td>Total Amount including VAT:</td>
      <td class="amt">{{ $currency }} {{ $money($invoice->grand_total) }}</td>
    </tr>
  </table>

  {{-- ══ WORDS / PAYMENT ══ --}}
  <table class="foot">
    <tr>
      <td>
        <span class="lbl">Total Amount in words:</span>
        <span class="val">{{ $words }}</span>
      </td>
    </tr>
    <tr>
      <td>
        <span class="lbl">Mode of Payment:</span>
        <span class="val">{{ $invoice->mode_of_payment?->label() }}</span>
      </td>
    </tr>
  </table>

  <div class="page-footer">
    <div class="sig-space"></div>
    <table class="sig">
      <tr>
        <td><div class="sig-line">Authorised Signature</div></td>
        <td><div class="sig-line">Customer Signature</div></td>
      </tr>
    </table>

    <div class="note">
      This is a computer-generated {{ strtolower($docTitle) }} — {{ $invoice->invoice_no }} — printed {{ now()->format('d/m/Y H:i') }}
    </div>
  </div>

</body>
</html>
