<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Receipt — {{ $receipt->receipt_no }}</title>
<style>
  /*
   * Cost-effective print: A5 portrait, single page, black-on-white with light
   * grey accents only — no fills that eat toner. Same DomPDF-safe rules as the
   * invoice: collapsed tables, explicit widths, reset `body` never `html`/`*`.
   */
  @page { margin: 8mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8pt; color: #111827; }
  table, div, span, p { margin: 0; padding: 0; }

  .head { width: 100%; border-collapse: collapse; border-bottom: 1pt solid #1f2937; padding-bottom: 4px; }
  .co-name { font-size: 11pt; font-weight: bold; }
  .co-sub  { font-size: 6.8pt; color: #6b7280; }

  .title { text-align: center; font-size: 10.5pt; font-weight: bold; letter-spacing: 2.5px; text-transform: uppercase; margin: 7px 0 6px; }

  .meta { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .meta td { border: 0.7pt solid #1f2937; padding: 3px 6px; }
  .lbl { color: #6b7280; font-size: 6.6pt; text-transform: uppercase; letter-spacing: 0.4px; }
  .val { font-weight: bold; }

  .items { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .items th { border: 0.7pt solid #1f2937; padding: 3px 5px; font-size: 6.6pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.3px; text-align: left; }
  .items td { border: 0.7pt solid #1f2937; padding: 3px 5px; }
  .r { text-align: right; }
  .nw { white-space: nowrap; }
  .items .sec td { font-size: 6.6pt; font-weight: bold; color: #374151; text-transform: uppercase; letter-spacing: 0.4px; border-bottom: none; padding-top: 4px; }

  .totals { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .totals td { padding: 2px 6px; }
  .totals td.k { text-align: right; color: #6b7280; font-size: 7pt; }
  .totals td.v { text-align: right; width: 100px; font-weight: bold; border-bottom: 0.5pt solid #d1d5db; }
  .totals tr.grand td { font-size: 9pt; font-weight: bold; border-top: 1pt solid #1f2937; border-bottom: none; padding-top: 3px; }

  .words { border: 0.7pt solid #1f2937; padding: 4px 6px; margin-bottom: 6px; font-size: 7.2pt; }

  .sig { width: 100%; border-collapse: collapse; margin-top: 100px; }
  .sig td { width: 50%; padding: 0 12px; text-align: center; vertical-align: bottom; }
  .sig-line { border-top: 0.7pt solid #1f2937; padding-top: 2px; font-size: 6.8pt; color: #6b7280; }

  .note { margin-top: 8px; text-align: center; font-size: 6.2pt; color: #9ca3af; }
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
      <td style="padding-bottom:4px;">
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
        @if(!empty($customer['customer_code'])) <span style="color:#6b7280; font-size:7pt;">({{ $customer['customer_code'] }})</span>@endif
      </td>
    </tr>
  </table>

  {{-- ══ WHAT WAS SETTLED ══ --}}
  @if($receipt->is_advance)
    <table class="items">
      <tr><th>Description</th><th class="r" style="width:110px;">Amount ({{ $currency }})</th></tr>
      <tr>
        <td>Advance received on account</td>
        <td class="r">{{ $money($receipt->advance_amount) }}</td>
      </tr>
    </table>
  @else
    <table class="items">
      <tr>
        <th>Invoice No</th>
        <th class="nw" style="width:74px;">Date</th>
        <th class="r" style="width:76px;">Outstanding</th>
        <th class="r" style="width:62px;">Discount</th>
        <th class="r" style="width:80px;">Received</th>
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
    <tr><th>Payment Mode</th><th>Details</th><th class="r" style="width:100px;">Amount ({{ $currency }})</th></tr>
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
    <div style="font-size:7pt; color:#374151; margin-bottom:6px;"><span class="lbl">Remark:</span> {{ $receipt->receipt_remark }}</div>
  @endif

  {{-- ══ SIGNATURES ══ --}}
  <table class="sig">
    <tr>
      <td><div class="sig-line">Received By (Signature &amp; Date)</div></td>
      <td><div class="sig-line">Customer Signature</div></td>
    </tr>
  </table>

  <div class="note">Computer-generated receipt — valid without a company seal. Confirmed {{ $receipt->confirmed_at?->format('Y-m-d H:i') }}.</div>

</body>
</html>
