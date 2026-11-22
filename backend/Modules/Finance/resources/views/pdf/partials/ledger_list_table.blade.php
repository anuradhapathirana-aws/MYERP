{{--
  One account as a running-balance ledger: header bar, Balance b/f, each line,
  period total, Balance c/f.

  Expects the shape AccountTransactionsService::statement() returns:
  $account, $summary, $rows, $truncated -- plus $period from the report.
--}}
@php
  $money = fn ($n) => number_format((float) $n, 2);
  $date  = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d-m-Y') : '';
  $bal   = fn ($n) => abs((float) $n) < 0.005 ? '0.00' : number_format(abs((float) $n), 2) . ((float) $n > 0 ? ' Dr' : ' Cr');
  $side  = fn ($n) => (float) $n > 0.004 ? 'dr-c' : ((float) $n < -0.004 ? 'cr-c' : '');
@endphp

<table class="acct-head">
  <tr>
    <td style="width:55%">
      <b>{{ $account['ledger_account_name'] }}</b> <span class="code">({{ $account['code'] }})</span>
    </td>
    <td class="info">
      {{ $account['control_account_name'] }} · {{ $account['account_type_label'] }} · Normal {{ ucfirst((string) $account['normal_balance']) }}
    </td>
  </tr>
</table>

<table class="l">
  <colgroup>
    <col style="width:7%"><col style="width:9%"><col style="width:12%"><col style="width:22%">
    <col style="width:12%"><col style="width:14%"><col style="width:8%"><col style="width:8%"><col style="width:8%">
  </colgroup>
  <tr>
    <th>Date</th><th>Journal No</th><th>Source Document</th><th>Narration / Description</th>
    <th>Party</th><th>Contra Account</th><th class="r">Debit</th><th class="r">Credit</th><th class="r">Balance</th>
  </tr>

  <tr class="bf">
    <td class="nw">{{ $date($period['from']) }}</td>
    <td colspan="7">Opening Balance (b/f)</td>
    <td class="r nw {{ $side($summary['opening']) }}">{{ $bal($summary['opening']) }}</td>
  </tr>

  @forelse($rows as $r)
    @php $text = trim(implode(' — ', array_filter([$r['narration'], $r['description']]))); @endphp
    <tr>
      <td class="nw">{{ $date($r['journal_date']) }}</td>
      <td class="nw">{{ $r['journal_no'] }}@if($r['is_reversed']) <span class="rev">REV</span>@endif</td>
      <td>{{ $r['source_label'] }}@if($r['document_no'])<div class="sub">{{ $r['document_no'] }}</div>@endif</td>
      <td>{{ \Illuminate\Support\Str::limit($text, 90) }}</td>
      <td>{{ $r['party_name'] }}</td>
      <td>{{ $r['contra']['label'] }}</td>
      <td class="r nw">{{ $r['debit'] > 0 ? $money($r['debit']) : '' }}</td>
      <td class="r nw">{{ $r['credit'] > 0 ? $money($r['credit']) : '' }}</td>
      <td class="r nw {{ $side($r['balance']) }}">{{ $bal($r['balance']) }}</td>
    </tr>
  @empty
    @unless($truncated)
      <tr><td colspan="9" class="sub">No transactions in this period.</td></tr>
    @endunless
  @endforelse

  @if($truncated)
    <tr><td colspan="9" class="warn">Lines stop here: the report reached its line limit. Narrow the dates or the accounts for the rest.</td></tr>
  @endif

  <tr class="pt">
    <td colspan="6" class="r">Period total</td>
    <td class="r nw">{{ $money($summary['period_debit']) }}</td>
    <td class="r nw">{{ $money($summary['period_credit']) }}</td>
    <td></td>
  </tr>
  <tr class="cf">
    <td class="nw">{{ $date($period['to']) }}</td>
    <td colspan="7">Closing Balance (c/f)</td>
    <td class="r nw {{ $side($summary['closing']) }}">{{ $bal($summary['closing']) }}</td>
  </tr>
</table>
