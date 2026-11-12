<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Bank Reconciliation — {{ $recon->recon_no }}</title>
<style>
  /* A4 portrait, black-on-white, the same DomPDF-safe rules as the Bank Deposit slip. */
  @page { margin: 12mm 12mm 34mm 12mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 9pt; color: #111827; }
  table, div, span, p { margin: 0; padding: 0; }

  .head { width: 100%; border-collapse: collapse; border-bottom: 1.2pt solid #1f2937; }
  .co-name { font-size: 15pt; font-weight: bold; }
  .co-sub  { font-size: 8.5pt; color: #6b7280; padding-bottom: 6px; }

  .title { text-align: center; font-size: 13pt; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin: 9px 0 8px; }
  .draft { text-align: center; font-size: 8.5pt; font-weight: bold; color: #b45309; letter-spacing: 1px; margin: -4px 0 8px; }

  .meta { width: 100%; border-collapse: collapse; margin-bottom: 9px; }
  .meta td { border: 0.8pt solid #1f2937; padding: 4px 7px; vertical-align: top; }
  .lbl { color: #6b7280; font-size: 7.5pt; text-transform: uppercase; letter-spacing: 0.5px; }
  .val { font-weight: bold; }

  .summary { width: 62%; margin-left: 38%; border-collapse: collapse; margin-bottom: 10px; }
  .summary td { padding: 3px 8px; }
  .summary td.k { color: #374151; }
  .summary td.v { text-align: right; width: 130px; font-weight: bold; border-bottom: 0.6pt solid #d1d5db; }
  .summary tr.total td { border-top: 1.2pt solid #1f2937; border-bottom: none; font-size: 10pt; }
  .summary tr.diff td { color: #b91c1c; }

  h3 { font-size: 9pt; text-transform: uppercase; letter-spacing: 0.5px; margin: 8px 0 3px; }
  .items { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
  .items th { border: 0.8pt solid #1f2937; padding: 3px 5px; font-size: 7.5pt; text-transform: uppercase; text-align: left; background: #f3f4f6; }
  .items td { border: 0.8pt solid #1f2937; padding: 3px 5px; font-size: 8pt; }
  .items tr.sum td { font-weight: bold; }
  .r { text-align: right; }
  .nw { white-space: nowrap; }
  .empty { color: #9ca3af; font-style: italic; }

  .page-footer { position: fixed; bottom: -28mm; left: 0; right: 0; width: 100%; }
  .sig { width: 100%; border-collapse: collapse; }
  .sig td { width: 33%; padding: 0 12px; text-align: center; vertical-align: bottom; height: 18mm; }
  .sig-line { border-top: 0.8pt solid #1f2937; padding-top: 3px; font-size: 8pt; color: #6b7280; }
</style>
</head>
<body>

  @php
    $money   = fn ($n) => number_format((float) $n, 2);
    $bank    = $recon->bankAccount;
    $isDraft = $recon->status === \Modules\Finance\Enums\BankReconciliationStatus::Draft;
    $isVoid  = $recon->status === \Modules\Finance\Enums\BankReconciliationStatus::Voided;

    $transitTotal     = $inTransit->sum(fn ($l) => (float) $l['amount']);
    $unpresentedTotal = $unpresented->sum(fn ($l) => (float) $l['amount']);

    // statement + open book items (net) - entries booked after the statement
    // date that the bank already shows + the opening gap = book balance.
    $adjustedBank     = (float) $recon->statement_balance + $openNet - $bookedAfter + $openingGap;
    $proofDifference  = round($adjustedBank - $bookBalance, 2);

    $companyAddress = collect([
        $company?->street_address,
        collect([$company?->city, $company?->state, $company?->postal_zip_code])->filter()->implode(', '),
        $company?->country,
    ])->filter()->implode(', ');
  @endphp

  <table class="head">
    <tr>
      <td>
        <div class="co-name">{{ $company?->company_name ?? config('app.name') }}</div>
        @if($companyAddress)<div class="co-sub">{{ $companyAddress }}</div>@endif
      </td>
    </tr>
  </table>

  <div class="title">Bank Reconciliation Statement</div>
  @if($isDraft)<div class="draft">DRAFT — NOT YET POSTED</div>@endif
  @if($isVoid)<div class="draft">UNDONE — {{ $recon->void_reason }}</div>@endif

  <table class="meta">
    <tr>
      <td style="width:25%"><div class="lbl">Reconciliation No</div><div class="val">{{ $recon->recon_no }}</div></td>
      <td style="width:45%"><div class="lbl">Bank Account</div>
        <div class="val">{{ $bank?->code }} - {{ $bank?->ledger_account_name }}</div>
        <div>{{ collect([$bank?->bank?->bank_name, $bank?->bankBranch?->branch_name, $bank?->bank_account_no ? 'A/C ' . $bank->bank_account_no : null])->filter()->implode(' · ') }}</div>
      </td>
      <td style="width:30%"><div class="lbl">Statement Date</div>
        <div class="val">{{ $recon->statement_date?->format('d-m-Y') }}</div>
        @if($recon->date_from)<div>Period from {{ $recon->date_from->format('d-m-Y') }}</div>@endif
      </td>
    </tr>
  </table>

  <table class="summary">
    <tr><td class="k">Balance as per bank statement</td><td class="v">{{ $money($recon->statement_balance) }}</td></tr>
    @if($openTruncated)
      <tr><td class="k">Add: uncleared entries, net (too many to list in full)</td><td class="v">{{ $money($openNet) }}</td></tr>
    @else
      <tr><td class="k">Add: deposits in transit ({{ $inTransit->count() }})</td><td class="v">{{ $money($transitTotal) }}</td></tr>
      <tr><td class="k">Less: unpresented cheques and payments ({{ $unpresented->count() }})</td><td class="v">({{ $money($unpresentedTotal) }})</td></tr>
    @endif
    @if(abs($bookedAfter) >= 0.01)
      <tr><td class="k">Less: entries the bank shows that the books dated after {{ $recon->statement_date?->format('d-m-Y') }}</td><td class="v">{{ $money(-$bookedAfter) }}</td></tr>
    @endif
    @if(abs($openingGap) >= 0.01)
      <tr><td class="k">Add: book entries before the start date{{ $cutoff ? ' (' . \Carbon\Carbon::parse($cutoff)->format('d-m-Y') . ')' : '' }} not in the opening statement balance</td><td class="v">{{ $money($openingGap) }}</td></tr>
    @endif
    <tr class="total"><td class="k">Adjusted bank balance</td><td class="v">{{ $money($adjustedBank) }}</td></tr>
    <tr><td class="k">Balance as per books on {{ $recon->statement_date?->format('d-m-Y') }}</td><td class="v">{{ $money($bookBalance) }}</td></tr>
    @if(abs($proofDifference) >= 0.01)
      <tr class="diff"><td class="k">Unexplained difference</td><td class="v">{{ $money($proofDifference) }}</td></tr>
    @endif
  </table>

  <table class="summary">
    <tr><td class="k">Opening statement balance</td><td class="v">{{ $money($recon->opening_balance) }}</td></tr>
    <tr><td class="k">Add: deposits realized</td><td class="v">{{ $money($recon->cleared_deposits) }}</td></tr>
    <tr><td class="k">Less: withdrawals realized{{ $returnedLater->count() ? ' (incl. cheques returned after clearing)' : '' }}</td><td class="v">({{ $money($recon->cleared_withdrawals) }})</td></tr>
    <tr class="total"><td class="k">Closing balance</td><td class="v">{{ $money($recon->closing_balance) }}</td></tr>
    <tr @class(['diff' => abs((float) $recon->difference) >= 0.01])><td class="k">Variance to statement</td><td class="v">{{ $money($recon->difference) }}</td></tr>
  </table>

  @if($openTruncated)
    <div class="draft">Only the oldest {{ number_format($unpresented->count() + $inTransit->count()) }} uncleared entries are listed below; the totals above cover all of them.</div>
  @endif

  @foreach([
      ['Unpresented cheques and payments', $unpresented],
      ['Deposits in transit', $inTransit],
  ] as [$heading, $rows])
    <h3>{{ $heading }}</h3>
    <table class="items">
      <tr>
        <th style="width:15%">Journal</th><th style="width:12%">Date</th><th style="width:13%">Cheque / Ref</th>
        <th>Description</th><th class="r" style="width:16%">Amount</th>
      </tr>
      @forelse($rows as $l)
        <tr>
          <td class="nw">{{ $l['journal_no'] }}</td>
          <td class="nw">{{ \Carbon\Carbon::parse($l['journal_date'])->format('d-m-Y') }}</td>
          <td class="nw">{{ $l['instrument_no'] }}</td>
          <td>{{ \Illuminate\Support\Str::limit((string) $l['description'], 70) }}</td>
          <td class="r nw">{{ $money($l['amount']) }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="empty">None.</td></tr>
      @endforelse
      @if($rows->count())
        <tr class="sum"><td colspan="4" class="r">Total</td><td class="r nw">{{ $money($rows->sum(fn ($l) => (float) $l['amount'])) }}</td></tr>
      @endif
    </table>
  @endforeach

  <h3>Entries realized on this statement</h3>
  <table class="items">
    <tr>
      <th style="width:15%">Journal</th><th style="width:12%">Date</th><th style="width:13%">Cheque / Ref</th>
      <th>Description</th><th class="r" style="width:13%">Deposit</th><th class="r" style="width:13%">Withdrawal</th>
    </tr>
    @forelse($realized as $l)
      <tr>
        <td class="nw">{{ $l->journal_no }}</td>
        <td class="nw">{{ $l->journal_date?->format('d-m-Y') }}</td>
        <td class="nw">{{ $l->instrument_no }}</td>
        <td>{{ \Illuminate\Support\Str::limit((string) $l->description, 60) }}</td>
        <td class="r nw">{{ $l->direction === 'deposit' ? $money($l->amount) : '' }}</td>
        <td class="r nw">{{ $l->direction === 'withdrawal' ? $money($l->amount) : '' }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="empty">None.</td></tr>
    @endforelse
  </table>

  @if($returned->count())
    <h3>Returned cheques</h3>
    <table class="items">
      <tr><th style="width:15%">Journal</th><th style="width:13%">Cheque</th><th style="width:11%">Returned</th><th>Description</th><th style="width:15%">Bounce Entry</th><th class="r" style="width:14%">Amount</th></tr>
      @foreach($returned as $l)
        <tr>
          <td class="nw">{{ $l->journal_no }}</td>
          <td class="nw">{{ $l->instrument_no }}</td>
          <td class="nw">{{ $l->cleared_date?->format('d-m-Y') }}</td>
          <td>{{ \Illuminate\Support\Str::limit((string) $l->description, 55) }}</td>
          <td class="nw">{{ $l->return_journal_no }}</td>
          <td class="r nw">{{ $money($l->amount) }}</td>
        </tr>
      @endforeach
    </table>
  @endif

  @if($returnedLater->count())
    <h3>Cheques returned after an earlier statement cleared them</h3>
    <table class="items">
      <tr><th style="width:15%">Journal</th><th style="width:13%">Cheque</th><th style="width:11%">Returned</th><th>Description</th><th style="width:15%">Bounce Entry</th><th class="r" style="width:14%">Amount</th></tr>
      @foreach($returnedLater as $l)
        <tr>
          <td class="nw">{{ $l->journal_no }}</td>
          <td class="nw">{{ $l->instrument_no }}</td>
          <td class="nw">{{ $l->cleared_date?->format('d-m-Y') }}</td>
          <td>{{ \Illuminate\Support\Str::limit((string) $l->description, 55) }}</td>
          <td class="nw">{{ $l->return_journal_no }}</td>
          <td class="r nw">{{ $l->direction === 'deposit' ? '(' . $money($l->amount) . ')' : $money($l->amount) }}</td>
        </tr>
      @endforeach
    </table>
  @endif

  <div class="page-footer">
    <table class="sig">
      <tr>
        <td><div class="sig-line">Prepared by{{ $recon->creator?->name ? ' — ' . $recon->creator->name : '' }}</div></td>
        <td><div class="sig-line">Checked by{{ $recon->poster?->name ? ' — ' . $recon->poster->name : '' }}</div></td>
        <td><div class="sig-line">Approved by</div></td>
      </tr>
    </table>
  </div>

</body>
</html>
