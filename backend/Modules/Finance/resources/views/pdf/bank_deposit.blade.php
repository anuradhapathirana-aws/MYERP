<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Bank Deposit — {{ $deposit->deposit_no }}</title>
<style>
  /*
   * A4 portrait, black-on-white, the same DomPDF-safe rules as the Customer
   * Receipt slip: collapsed tables, explicit widths, reset `body` never
   * `html`/`*`. The bottom margin reserves room for the fixed signature strip,
   * so a long cheque list can never run over it.
   */
  @page { margin: 12mm 12mm 46mm 12mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #111827; }
  table, div, span, p { margin: 0; padding: 0; }

  .head { width: 100%; border-collapse: collapse; border-bottom: 1.2pt solid #1f2937; padding-bottom: 6px; }
  .co-name { font-size: 15.5pt; font-weight: bold; }
  .co-sub  { font-size: 9pt; color: #6b7280; }

  .title { text-align: center; font-size: 14pt; font-weight: bold; letter-spacing: 3px; text-transform: uppercase; margin: 10px 0 9px; }
  .draft { text-align: center; font-size: 9pt; font-weight: bold; color: #b45309; letter-spacing: 1px; margin: -4px 0 8px; }

  .meta { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .meta td { border: 0.8pt solid #1f2937; padding: 5px 8px; vertical-align: top; }
  .lbl { color: #6b7280; font-size: 8pt; text-transform: uppercase; letter-spacing: 0.5px; }
  .val { font-weight: bold; }

  .items { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .items th { border: 0.8pt solid #1f2937; padding: 4px 6px; font-size: 8pt; font-weight: bold; text-transform: uppercase; letter-spacing: 0.4px; text-align: left; }
  .items td { border: 0.8pt solid #1f2937; padding: 4px 6px; font-size: 9pt; }
  .items tr.sum td { font-weight: bold; }
  .r { text-align: right; }
  .nw { white-space: nowrap; }

  .totals { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .totals td { padding: 3px 8px; }
  .totals td.k { text-align: right; color: #6b7280; font-size: 9.5pt; }
  .totals td.v { text-align: right; width: 145px; font-weight: bold; border-bottom: 0.6pt solid #d1d5db; }
  .totals tr.grand td { font-size: 12pt; font-weight: bold; border-top: 1.2pt solid #1f2937; border-bottom: none; padding-top: 4px; }

  .page-footer { position: fixed; bottom: -40mm; left: 0; right: 0; width: 100%; }
  .sig-space { height: 20mm; }
  .sig { width: 100%; border-collapse: collapse; }
  .sig td { width: 33%; padding: 0 12px; text-align: center; vertical-align: bottom; }
  .sig-line { border-top: 0.8pt solid #1f2937; padding-top: 3px; font-size: 8.5pt; color: #6b7280; }
  .note { margin-top: 12px; text-align: center; font-size: 8pt; color: #9ca3af; }
</style>
</head>
<body>

  @php
    $money   = fn ($n) => number_format((float) $n, 2);
    $bank    = $deposit->bankAccount;
    $cheques = $deposit->lines->where('line_type', \Modules\Finance\Models\BankDepositLine::TYPE_CHEQUE)->values();
    $isDraft = $deposit->status === \Modules\Finance\Enums\BankDepositStatus::Draft;

    $companyAddress = collect([
        $company?->street_address,
        collect([$company?->city, $company?->state, $company?->postal_zip_code])->filter()->implode(', '),
        $company?->country,
    ])->filter()->implode(', ');
  @endphp

  {{-- ══ LETTERHEAD ══ --}}
  <table class="head">
    <tr>
      <td style="padding-bottom:6px;">
        <div class="co-name">{{ $company?->company_name ?? config('app.name') }}</div>
        @if($companyAddress)<div class="co-sub">{{ $companyAddress }}</div>@endif
      </td>
    </tr>
  </table>

  <div class="title">Bank Deposit Slip</div>
  @if($isDraft)<div class="draft">DRAFT — NOT YET CONFIRMED AS BANKED</div>@endif

  {{-- ══ META ══ --}}
  <table class="meta">
    <tr>
      <td style="width:34%;"><span class="lbl">Deposit No</span><br><span class="val">{{ $deposit->deposit_no }}</span></td>
      <td style="width:33%;"><span class="lbl">Date</span><br><span class="val">{{ $deposit->deposit_date?->format('Y-m-d') }}</span></td>
      <td style="width:33%;"><span class="lbl">Bank Slip No</span><br><span class="val">{{ $deposit->slip_no ?: '—' }}</span></td>
    </tr>
    <tr>
      <td colspan="2">
        <span class="lbl">Deposit Into</span><br>
        <span class="val">{{ $bank?->bank?->bank_name ?? $bank?->ledger_account_name }}</span>
        @if($bank?->bankBranch) <span style="color:#6b7280; font-size:9pt;">— {{ $bank->bankBranch->branch_name }}</span>@endif
      </td>
      <td><span class="lbl">Account No</span><br><span class="val">{{ $bank?->bank_account_no ?: '—' }}</span></td>
    </tr>
  </table>

  {{-- ══ CHEQUES ══ --}}
  @if($cheques->isNotEmpty())
    <table class="items">
      <tr>
        <th style="width:26px;">#</th>
        <th class="nw" style="width:80px;">Cheque No</th>
        <th class="nw" style="width:80px;">Cheque Date</th>
        <th>Drawee Bank</th>
        <th>Customer</th>
        <th class="r" style="width:105px;">Amount ({{ $deposit->currency }})</th>
      </tr>
      @foreach($cheques as $i => $line)
        <tr>
          <td>{{ $i + 1 }}</td>
          <td class="nw">{{ $line->cheque_no }}</td>
          <td class="nw">{{ $line->cheque_date?->format('Y-m-d') }}</td>
          <td>{{ $line->drawee_bank ?: '—' }}</td>
          <td>{{ $line->customer_name ?: '—' }}</td>
          <td class="r">{{ $money($line->amount) }}</td>
        </tr>
      @endforeach
      <tr class="sum">
        <td colspan="5" class="r">{{ $cheques->count() }} cheque(s)</td>
        <td class="r">{{ $money($deposit->cheque_total) }}</td>
      </tr>
    </table>
  @endif

  {{-- ══ TOTALS ══ --}}
  <table class="totals">
    <tr><td class="k">Cash</td><td class="v">{{ $money($deposit->cash_total) }}</td></tr>
    <tr><td class="k">Cheques</td><td class="v">{{ $money($deposit->cheque_total) }}</td></tr>
    <tr class="grand"><td class="k" style="color:#111827;">Total Deposit ({{ $deposit->currency }})</td><td class="v">{{ $money($deposit->total_amount) }}</td></tr>
  </table>

  @if($deposit->description)
    <div style="font-size:9pt; color:#374151; margin-bottom:9px;"><span class="lbl">Remark:</span> {{ $deposit->description }}</div>
  @endif

  {{-- ══ SIGNATURES ══ --}}
  <div class="page-footer">
    <div class="sig-space"></div>
    <table class="sig">
      <tr>
        <td><div class="sig-line">Prepared By</div></td>
        <td><div class="sig-line">Deposited By</div></td>
        <td><div class="sig-line">Bank Stamp &amp; Signature</div></td>
      </tr>
    </table>
    <div class="note">Computer-generated deposit slip{{ $deposit->confirmed_at ? ' — confirmed ' . $deposit->confirmed_at->format('Y-m-d H:i') : '' }}.</div>
  </div>

</body>
</html>
