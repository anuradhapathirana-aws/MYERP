<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $account['ledger_account_name'] }} Account</title>
<style>
  /* A4 portrait. DomPDF-safe: tables only, no flex/grid, borders in pt. */
  @page { margin: 10mm 9mm 12mm 9mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 7.5pt; color: #111827; }
  table, div, span, p { margin: 0; padding: 0; }

  .head { width: 100%; border-collapse: collapse; border-bottom: 1.2pt solid #1f2937; }
  .co-name { font-size: 13pt; font-weight: bold; }
  .co-sub  { font-size: 7.5pt; color: #6b7280; padding-bottom: 4px; }

  .meta { width: 100%; border-collapse: collapse; margin: 6px 0 8px; }
  .meta td { border: 0.8pt solid #cbd5e1; padding: 3px 6px; vertical-align: top; }
  .lbl { color: #6b7280; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.5px; }
  .val { font-weight: bold; }

  /* The account title bar, Dr on the left, Cr on the right */
  .titlebar { width: 100%; border-collapse: collapse; }
  .titlebar td { padding: 5px 8px; color: #ffffff; background: #312e81; }
  .titlebar .side { width: 8%; font-size: 11pt; font-weight: bold; }
  .titlebar .name { text-align: center; font-size: 11pt; font-weight: bold; letter-spacing: 1px; }
  .titlebar .code { font-size: 7.5pt; font-weight: normal; color: #c7d2fe; letter-spacing: 0; }

  .t { width: 100%; border-collapse: collapse; table-layout: fixed; }
  .t th { padding: 3px 4px; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.4px; text-align: left; border-bottom: 0.8pt solid #1f2937; }
  .t th.dr { background: #eef2ff; color: #3730a3; }
  .t th.cr { background: #fffbeb; color: #92400e; }
  .t td { padding: 2.5px 4px; vertical-align: top; border-bottom: 0.4pt solid #e5e7eb; }
  .t td.dr { background: #fafbff; }
  .t td.cr { background: #fffdf7; }
  .t td.mid, .t th.mid { border-left: 1.4pt solid #1f2937; }
  .r { text-align: right; }
  .nw { white-space: nowrap; }
  .amt { text-align: right; white-space: nowrap; font-weight: bold; }
  .bal td { font-weight: bold; font-style: italic; color: #334155; }
  .bal-cell { font-weight: bold; font-style: italic; color: #334155; }
  .sub { font-size: 6.3pt; color: #6b7280; }
  .acc { font-size: 6.3pt; color: #475569; }

  .tot td { border-top: 0.9pt solid #1f2937; border-bottom: 2.4pt double #1f2937; font-weight: bold; padding: 3px 4px; background: #f1f5f9; }
  .reopen td { padding: 3px 4px; font-weight: bold; font-style: italic; color: #1e3a8a; border-bottom: 0; }

  .sum { width: 100%; border-collapse: collapse; margin-top: 10px; }
  .sum td { border: 0.8pt solid #cbd5e1; padding: 3px 6px; }
  .note { font-size: 6.5pt; color: #6b7280; margin-top: 6px; }
  .warn { font-size: 7.5pt; font-weight: bold; color: #b45309; margin: 4px 0; }
</style>
</head>
<body>

  @php
    $money = fn ($n) => number_format((float) $n, 2);
    $date  = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d-m-Y') : '';
    $bal   = fn ($n) => abs((float) $n) < 0.005 ? '0.00' : number_format(abs((float) $n), 2) . ((float) $n > 0 ? ' Dr' : ' Cr');
    $count = max(count($debit), count($credit));
  @endphp

  <table class="head">
    <tr>
      <td>
        <div class="co-name">{{ $header['company_name'] }}</div>
        <div class="co-sub">General Ledger · T-Account</div>
      </td>
    </tr>
  </table>

  <table class="meta">
    <tr>
      <td style="width:26%"><div class="lbl">Period</div>
        <div class="val">{{ $period['from'] ? $date($period['from']) : 'Beginning' }} &rarr; {{ $period['to'] ? $date($period['to']) : 'Today' }}</div></td>
      <td style="width:28%"><div class="lbl">Control Account</div><div class="val">{{ $account['control_account_name'] }}</div></td>
      <td style="width:18%"><div class="lbl">Type / Normal</div>
        <div class="val">{{ $account['account_type_label'] }} · {{ ucfirst((string) $account['normal_balance']) }}</div></td>
      <td style="width:28%"><div class="lbl">Generated</div><div>{{ $header['generated_by'] }} · {{ $header['generated_at'] }}</div>
        @if($header['location_name'])<div>Location: {{ $header['location_name'] }}</div>@endif
      </td>
    </tr>
  </table>

  @if($truncated)
    <div class="warn">Only the first {{ \Modules\Finance\Services\AccountTransactionsService::MAX_STATEMENT_ROWS }} entries are shown. Narrow the dates for a complete T-account.</div>
  @endif

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

  <div class="note">
    How to read: left side = Debit, right side = Credit. "To" / "By" name the other account in the same voucher, not where the money went
    &mdash; e.g. "To Cash in Hand" on this account means this account was debited and Cash in Hand was credited.
    "Sundries" means several other accounts, listed underneath.
    Balance c/d is placed on the smaller side so both sides agree, and is brought down as Balance b/d for the next period.
    Posted and reversed journals are both included: a reversal and its original cancel each other.
  </div>

</body>
</html>
