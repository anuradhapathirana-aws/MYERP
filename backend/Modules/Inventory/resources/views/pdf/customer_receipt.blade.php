<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Receipt — {{ $receipt->receipt_no }}</title>
<style>
  /*
   * A4 portrait, single page, black-on-white with light grey accents only — no
   * fills that eat toner. Same DomPDF-safe rules as the invoice: collapsed
   * tables, explicit widths, reset `body` never `html`/`*`.
   *
   * This receipt was previously drawn on A5. The downloaded file was correct,
   * but browsers print a PDF onto the printer's own paper (A4 here), so an A5
   * page arrived in the print preview boxed inside an A4 sheet instead of
   * filling it. The sheet is now A4 and every type size and spacing step is
   * scaled up ~1.4x — the A5 → A4 linear ratio — so the receipt fills the page
   * it is printed on exactly as it filled the A5 download.
   *
   * The bottom page margin is deliberately oversized to reserve room for the
   * fixed .page-footer below — dompdf never flows body content into the page
   * margin, so the signature strip can never be overwritten by receipt rows.
   */
  @page { margin: 12mm 12mm 46mm 12mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 11pt; color: #111827; }
  table, div, span, p { margin: 0; padding: 0; }

  .head { width: 100%; border-collapse: collapse; border-bottom: 1.2pt solid #1f2937; padding-bottom: 6px; }
  .co-name { font-size: 15.5pt; font-weight: bold; }
  .co-sub  { font-size: 9.5pt; color: #6b7280; }

  .title { text-align: center; font-size: 15pt; font-weight: bold; letter-spacing: 3.5px; text-transform: uppercase; margin: 10px 0 9px; }

  .meta { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .meta td { border: 0.8pt solid #1f2937; padding: 5px 9px; }
  .lbl { color: #6b7280; font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5px; }
  .val { font-weight: bold; }

  .items { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .items th { border: 0.8pt solid #1f2937; padding: 5px 7px; font-size: 9pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; text-align: left; }
  .items td { border: 0.8pt solid #1f2937; padding: 5px 7px; }
  .r { text-align: right; }
  .nw { white-space: nowrap; }
  .items .sec td { font-size: 9pt; font-weight: bold; color: #374151; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: none; padding-top: 6px; }

  .totals { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .totals td { padding: 3px 9px; }
  .totals td.k { text-align: right; color: #6b7280; font-size: 9.5pt; }
  .totals td.v { text-align: right; width: 145px; font-weight: bold; border-bottom: 0.6pt solid #d1d5db; }
  .totals tr.grand td { font-size: 12.5pt; font-weight: bold; border-top: 1.2pt solid #1f2937; border-bottom: none; padding-top: 4px; }

  .words { border: 0.8pt solid #1f2937; padding: 6px 9px; margin-bottom: 9px; font-size: 10pt; }

  /* Pinned into the reserved bottom margin so the signatures always sit on the
     foot of the sheet, whatever the row count above. */
  .page-footer { position: fixed; bottom: -40mm; left: 0; right: 0; width: 100%; }
  .sig-space { height: 20mm; }

  .sig { width: 100%; border-collapse: collapse; }
  .sig td { width: 50%; padding: 0 18px; text-align: center; vertical-align: bottom; }
  .sig-line { border-top: 0.8pt solid #1f2937; padding-top: 3px; font-size: 9pt; color: #6b7280; }

  .note { margin-top: 12px; text-align: center; font-size: 8.5pt; color: #9ca3af; }
</style>
</head>
<body>

  @php
    $money    = fn ($n) => \Modules\Inventory\Support\Money::number((float) $n);
    $currency = \Modules\Inventory\Support\Money::symbol();

    // The amount this receipt settles — an advance has no allocations, so its own figure.
    $settled = $receipt->is_advance ? (float) $receipt->advance_amount : (float) $receipt->gross_amount;
    $words   = \Modules\Inventory\Support\NumberToWords::convert($settled, \Modules\Inventory\Support\Money::code());

    $companyAddress = collect([
        $company?->street_address,
        collect([$company?->city, $company?->state, $company?->postal_zip_code])->filter()->implode(', '),
        $company?->country,
    ])->filter()->implode(', ');

    $customer = $receipt->customer;

    $companyContact = collect([
        $company?->company_mobile ? 'Tel: '.$company->company_mobile : null,
        $company?->company_email,
        $company?->company_email_2,
    ])->filter()->implode(' | ');
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

  <div class="title">Official Receipt</div>

  {{-- ══ META ══ --}}
  <table class="meta">
    <tr>
      <td style="width:34%;"><span class="lbl">Receipt No</span><br><span class="val">{{ $receipt->receipt_no }}</span></td>
      <td style="width:33%;"><span class="lbl">Date</span><br><span class="val">{{ $receipt->receipt_date?->format('Y-m-d') }}</span></td>
      <td style="width:33%;"><span class="lbl">Reference</span><br><span class="val">{{ $receipt->reference_no ?: '—' }}</span></td>
    </tr>
    <tr>
      <td colspan="3">
        <span class="lbl">Received From</span><br>
        <span class="val">{{ $customer['name'] ?? '—' }}</span>
        @if(!empty($customer['customer_code'])) <span style="color:#6b7280; font-size:9.5pt;">({{ $customer['customer_code'] }})</span>@endif
      </td>
    </tr>
  </table>

  {{-- ══ WHAT WAS SETTLED ══ --}}
  @if($receipt->is_advance)
    <table class="items">
      <tr><th>Description</th><th class="r" style="width:158px;">Amount ({{ $currency }})</th></tr>
      <tr>
        <td>Advance received on account</td>
        <td class="r">{{ $money($receipt->advance_amount) }}</td>
      </tr>
    </table>
  @else
    <table class="items">
      <tr>
        <th>Invoice No</th>
        <th class="nw" style="width:106px;">Date</th>
        <th class="r" style="width:110px;">Outstanding</th>
        <th class="r" style="width:90px;">Discount</th>
        <th class="r" style="width:115px;">Received</th>
      </tr>
      @foreach($receipt->allocations as $a)
        <tr>
          <td>{{ $a->invoice_no ?? ('#' . $a->reference_id) }}</td>
          <td class="nw">{{ $a->invoice_date?->format('Y-m-d') }}</td>
          <td class="r">{{ $money($a->outstanding_before) }}</td>
          <td class="r">{{ (float) $a->discount > 0 ? $money($a->discount) : '—' }}</td>
          <td class="r">{{ $money($a->receipt_amount) }}</td>
        </tr>
      @endforeach
    </table>
  @endif

  {{-- ══ HOW IT WAS PAID ══ --}}
  <table class="items">
    <tr><th>Payment Mode</th><th>Details</th><th class="r" style="width:145px;">Amount ({{ $currency }})</th></tr>
    @foreach($receipt->settlements as $s)
      @php
        $detail = collect([
            $s->bank_name,
            $s->reference_no ? ('No. ' . $s->reference_no) : null,
            $s->instrument_date?->format('Y-m-d'),
        ])->filter()->implode(' / ');
      @endphp
      <tr>
        <td>{{ $s->payment_mode_name }}</td>
        <td>{{ $detail ?: '—' }}</td>
        <td class="r">{{ $money($s->amount) }}</td>
      </tr>
    @endforeach
    @foreach($receipt->setoffs as $so)
      <tr>
        <td>Set Off — {{ $so->setoff_type->label() }}</td>
        <td>{{ $so->creditNote?->credit_note_no ?: '—' }}</td>
        <td class="r">{{ $money($so->amount) }}</td>
      </tr>
    @endforeach
  </table>

  {{-- ══ TOTALS ══ --}}
  <table class="totals">
    @if(!$receipt->is_advance && (float) $receipt->discount_amount > 0)
      <tr><td class="k">Discount</td><td class="v">{{ $money($receipt->discount_amount) }}</td></tr>
    @endif
    @if((float) $receipt->setoff_amount > 0)
      <tr><td class="k">Set Off</td><td class="v">{{ $money($receipt->setoff_amount) }}</td></tr>
    @endif
    <tr><td class="k">Received</td><td class="v">{{ $money($receipt->net_amount) }}</td></tr>
    <tr class="grand"><td class="k" style="color:#111827;">Total Settled ({{ $currency }})</td><td class="v">{{ $money($settled) }}</td></tr>
  </table>

  <div class="words"><span class="lbl">Amount in words:</span> {{ $words }}</div>

  @if($receipt->receipt_remark)
    <div style="font-size:9.5pt; color:#374151; margin-bottom:9px;"><span class="lbl">Remark:</span> {{ $receipt->receipt_remark }}</div>
  @endif

  {{-- ══ SIGNATURES ══ --}}
  <div class="page-footer">
    <div class="sig-space"></div>
    <table class="sig">
      <tr>
        <td><div class="sig-line">Received By (Signature &amp; Date)</div></td>
        <td><div class="sig-line">Customer Signature</div></td>
      </tr>
    </table>

    <div class="note">Computer-generated receipt — valid without a company seal. Confirmed {{ $receipt->confirmed_at?->format('Y-m-d H:i') }}.</div>
  </div>

</body>
</html>
