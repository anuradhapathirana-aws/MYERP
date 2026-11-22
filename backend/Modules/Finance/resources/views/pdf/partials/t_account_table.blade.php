{{--
  One balanced T-account: title bar, both sides, double-ruled totals, the
  closing reopened below the rule, and the summary strip.

  Expects the shape AccountTransactionsService::tAccount() returns:
  $account, $debit, $credit, $total, $reopen, $summary.
--}}
@php
  $money = fn ($n) => number_format((float) $n, 2);
  $date  = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d-m-Y') : '';
  $bal   = fn ($n) => abs((float) $n) < 0.005 ? '0.00' : number_format(abs((float) $n), 2) . ((float) $n > 0 ? ' Dr' : ' Cr');
  $count = max(count($debit), count($credit));
@endphp

<table class="titlebar">
  <tr>
    <td class="side">Dr</td>
    <td class="name">{{ $account['ledger_account_name'] }} Account <span class="code">({{ $account['code'] }})</span></td>
    <td class="side r">Cr</td>
  </tr>
</table>

<table class="t">
  <colgroup>
    <col style="width:8.5%"><col style="width:22%"><col style="width:8.5%"><col style="width:11%">
    <col style="width:8.5%"><col style="width:22%"><col style="width:8.5%"><col style="width:11%">
  </colgroup>
  <tr>
    <th class="dr">Date</th><th class="dr">Particulars</th><th class="dr">Ref</th><th class="dr r">Amount</th>
    <th class="cr mid">Date</th><th class="cr">Particulars</th><th class="cr">Ref</th><th class="cr r">Amount</th>
  </tr>

  @for($i = 0; $i < $count; $i++)
    @php $d = $debit[$i] ?? null; $c = $credit[$i] ?? null; @endphp
    <tr>
      @foreach([['dr', $d, ''], ['cr', $c, 'mid']] as [$side, $e, $mid])
        @if($e)
          <td class="{{ $side }} {{ $mid }} nw">{{ $date($e['date']) }}</td>
          <td class="{{ $side }} {{ $e['is_balance'] ? 'bal-cell' : '' }}">
            {{ $e['particulars'] }}
            @foreach($e['accounts'] as $a)<div class="acc">&middot; {{ $a['name'] }}</div>@endforeach
            @if($e['narration'])<div class="sub">{{ \Illuminate\Support\Str::limit((string) $e['narration'], 60) }}</div>@endif
          </td>
          <td class="{{ $side }} nw">{{ $e['ref'] }}@if($e['document'] && ! $e['is_balance'])<div class="sub">{{ $e['document'] }}</div>@endif</td>
          <td class="{{ $side }} amt {{ $e['is_balance'] ? 'bal-cell' : '' }}">{{ $money($e['amount']) }}</td>
        @else
          <td class="{{ $side }} {{ $mid }}"></td><td class="{{ $side }}"></td><td class="{{ $side }}"></td><td class="{{ $side }}"></td>
        @endif
      @endforeach
    </tr>
  @endfor

  @if($count === 0)
    <tr><td colspan="4" class="dr sub">No entries.</td><td colspan="4" class="cr mid sub">No entries.</td></tr>
  @endif

  <tr class="tot">
    <td colspan="3" class="r">Total</td><td class="r nw">{{ $money($total) }}</td>
    <td colspan="3" class="r mid">Total</td><td class="r nw">{{ $money($total) }}</td>
  </tr>

  @if($reopen)
    <tr class="reopen">
      @if($reopen['side'] === 'debit')
        <td class="nw">{{ $date($reopen['date']) }}</td><td>{{ $reopen['particulars'] }}</td><td></td><td class="r nw">{{ $money($reopen['amount']) }}</td>
        <td class="mid"></td><td></td><td></td><td></td>
      @else
        <td></td><td></td><td></td><td></td>
        <td class="mid nw">{{ $date($reopen['date']) }}</td><td>{{ $reopen['particulars'] }}</td><td></td><td class="r nw">{{ $money($reopen['amount']) }}</td>
      @endif
    </tr>
  @endif
</table>

<table class="sum">
  <tr>
    <td><div class="lbl">Opening (b/d)</div><div class="val">{{ $bal($summary['opening']) }}</div></td>
    <td><div class="lbl">Period Debits</div><div class="val">{{ $money($summary['period_debit']) }}</div></td>
    <td><div class="lbl">Period Credits</div><div class="val">{{ $money($summary['period_credit']) }}</div></td>
    <td><div class="lbl">Closing (c/d)</div><div class="val">{{ $bal($summary['closing']) }}</div></td>
  </tr>
</table>
