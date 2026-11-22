<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Ledger Details</title>
@include('finance::pdf.partials.ledger_styles')
</head>
<body>

  @php
    $money      = fn ($n) => number_format((float) $n, 2);
    $date       = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d-m-Y') : '';
    $isTAccount = $view === \Modules\Finance\Services\AccountTransactionsService::VIEW_T_ACCOUNT;
  @endphp

  <table class="head">
    <tr>
      <td>
        <div class="co-name">{{ $header['company_name'] }}</div>
        <div class="co-sub">General Ledger · Ledger Details{{ $isTAccount ? ' (T-Accounts)' : '' }}</div>
      </td>
    </tr>
  </table>

  <table class="meta">
    <tr>
      <td style="width:30%"><div class="lbl">Period</div>
        <div class="val">{{ $period['from'] ? $date($period['from']) : 'Beginning' }} &rarr; {{ $period['to'] ? $date($period['to']) : 'Today' }}</div></td>
      <td style="width:20%"><div class="lbl">Accounts</div><div class="val">{{ $totals['account_count'] }}</div></td>
      <td style="width:20%"><div class="lbl">Lines in period</div><div class="val">{{ $totals['line_count'] }}</div></td>
      <td style="width:30%"><div class="lbl">Generated</div><div>{{ $header['generated_by'] }} · {{ $header['generated_at'] }}</div>
        @if($header['location_name'])<div>Location: {{ $header['location_name'] }}</div>@endif
      </td>
    </tr>
  </table>

  @if($accounts_truncated)
    <div class="warn">Only the first {{ $max_accounts }} accounts are printed. The grand totals below still cover all {{ $totals['account_count'] }}.</div>
  @endif
  @if($truncated)
    <div class="warn">The report reached its limit of {{ $max_rows }} lines; later accounts show balances only. Narrow the dates or the accounts for a complete print.</div>
  @endif

  @forelse($sections as $section)
    @if($isTAccount)
      <div style="margin-top: 10px;">
        @include('finance::pdf.partials.t_account_table', $section)
      </div>
    @else
      @include('finance::pdf.partials.ledger_list_table', [...$section, 'period' => $period])
    @endif
  @empty
    <div class="warn">No accounts match this selection.</div>
  @endforelse

  {{-- Grand totals over every selected account. Each Dr/Cr pair is on the side the balance lands. --}}
  <table class="grand">
    <tr>
      <th class="lft">Grand Total</th>
      <th>Opening Dr</th><th>Opening Cr</th>
      <th>Period Dr</th><th>Period Cr</th>
      <th>Closing Dr</th><th>Closing Cr</th>
    </tr>
    <tr>
      <td style="text-align:left">{{ $totals['account_count'] }} account(s)</td>
      <td>{{ $money($totals['opening_debit']) }}</td><td>{{ $money($totals['opening_credit']) }}</td>
      <td>{{ $money($totals['period_debit']) }}</td><td>{{ $money($totals['period_credit']) }}</td>
      <td>{{ $money($totals['closing_debit']) }}</td><td>{{ $money($totals['closing_credit']) }}</td>
    </tr>
  </table>

  @if($isTAccount)
    @include('finance::pdf.partials.t_account_note')
  @else
    <div class="note">
      Balance b/f is everything before the From date; Balance c/f is b/f plus the period's movement.
      Posted and reversed journals are both included: a reversal and its original cancel each other.
    </div>
  @endif

</body>
</html>
