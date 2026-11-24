<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Profit &amp; Loss Account</title>
@include('finance::pdf.partials.ledger_styles')
<style>
  body { font-size: 8pt; }
  .pl { width: 100%; border-collapse: collapse; table-layout: fixed; margin-top: 4px; }
  .pl th { padding: 3px 4px; font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.4px; background: #f1f5f9; color: #475569; border-bottom: 0.8pt solid #1f2937; text-align: right; }
  .pl th.lft { text-align: left; }
  .pl th.ctr { text-align: center; border-bottom: 0.4pt solid #cbd5e1; }
  .pl .hd-sub { text-transform: none; letter-spacing: 0; font-weight: normal; color: #64748b; }
  .pl td { padding: 2.2px 4px; vertical-align: top; }
  .pl td.n { text-align: right; white-space: nowrap; }
  .pl td.cmp { color: #475569; }
  .pl .sec td { font-weight: bold; padding-top: 6px; color: #111827; }
  .pl .ln td.d { padding-left: 16px; }
  .pl .ln td.d2 { padding-left: 28px; }
  .pl .ul { border-bottom: 0.8pt solid #1f2937; }
  .pl .tot td { font-weight: bold; }
  .pl .key td { font-weight: bold; background: #f8fafc; border-top: 0.8pt solid #94a3b8; }
  .pl .key td.n { border-bottom: 0.8pt solid #1f2937; }
  .pl .final td { font-weight: bold; background: #eef2ff; border-top: 0.9pt solid #1f2937; }
  .pl .final td.n { border-bottom: 2.4pt double #1f2937; }
  .code { font-size: 6.3pt; color: #64748b; }
  .tag { font-size: 6pt; color: #92400e; }
  .notes { margin-top: 10px; }
  .notes div { font-size: 6.8pt; color: #475569; margin-top: 2px; }
</style>
</head>
<body>
@php
  $dec  = \App\Support\Money\Amount::decimals();
  $cmp  = $report['comparative']['available'];
  $date = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d-m-Y') : '';
  // Accounting style: negatives in brackets, blank for "not shown".
  $m    = fn ($v) => $v === null ? '' : ($v < 0 ? '(' . number_format(abs($v), $dec) . ')' : number_format($v, $dec));
  $cols = $cmp ? 5 : 3;
@endphp

  <table class="head">
    <tr>
      <td>
        <div class="co-name">{{ $header['company_name'] }}</div>
        <div class="co-sub">Profit &amp; Loss Account</div>
      </td>
    </tr>
  </table>

  <table class="meta">
    <tr>
      <td style="width:30%"><div class="lbl">Period</div>
        <div class="val">{{ $date($report['period']['from']) }} &rarr; {{ $date($report['period']['to']) }}</div></td>
      <td style="width:30%"><div class="lbl">Comparative</div>
        <div class="val">
          @if($cmp){{ $date($report['comparative']['from']) }} &rarr; {{ $date($report['comparative']['to']) }}
          @elseif($report['comparative']['enabled'])Not available @else — @endif
        </div></td>
      <td style="width:40%"><div class="lbl">Generated</div><div>{{ $header['generated_by'] }} · {{ $header['generated_at'] }}</div>
        @if($header['location_name'])<div>Location: {{ $header['location_name'] }}</div>@endif
      </td>
    </tr>
  </table>

  @foreach($report['warnings'] as $w)
    <div class="warn">{{ $w['message'] }}</div>
  @endforeach

  <table class="pl">
    <colgroup>
      <col style="width:{{ $cmp ? '40%' : '56%' }}">
      <col style="width:{{ $cmp ? '15%' : '22%' }}"><col style="width:{{ $cmp ? '15%' : '22%' }}">
      @if($cmp)<col style="width:15%"><col style="width:15%">@endif
    </colgroup>
    <tr>
      <th class="lft" rowspan="2" style="vertical-align:bottom">Particulars</th>
      <th colspan="2" class="ctr">Current Period<br><span class="hd-sub">{{ $date($report['period']['from']) }} – {{ $date($report['period']['to']) }}</span></th>
      @if($cmp)<th colspan="2" class="ctr">Comparative Period<br><span class="hd-sub">{{ $date($report['comparative']['from']) }} – {{ $date($report['comparative']['to']) }}</span></th>@endif
    </tr>
    <tr>
      <th>Details</th><th>Amount</th>
      @if($cmp)<th>Details</th><th>Amount</th>@endif
    </tr>

    {{-- One detail line (inner column) --}}
    @php
      $detail = function (array $l, string $cls = 'd') use ($m, $cmp) {
          $h = '<tr class="ln"><td class="' . $cls . '">' . e($l['name'])
             . (isset($l['code']) ? ' <span class="code">' . e($l['code']) . '</span>' : '') . '</td>'
             . '<td class="n">' . $m($l['amount']) . '</td><td></td>';
          if ($cmp) {
              $h .= '<td class="n cmp">' . $m($l['compare']) . '</td><td></td>';
          }
          return $h . '</tr>';
      };
      // A total in the outer column; $ul underlines the inner column above it.
      $total = function (string $label, array $t, string $cls = 'tot') use ($m, $cmp) {
          // The label spans the empty detail column, so long results stay on one line.
          $h = '<tr class="' . $cls . '"><td colspan="2">' . $label . '</td><td class="n">' . $m($t['amount']) . '</td>';
          if ($cmp) {
              $h .= '<td></td><td class="n cmp">' . $m($t['compare']) . '</td>';
          }
          return $h . '</tr>';
      };
      $heading = fn (string $label) => '<tr class="sec"><td colspan="' . ($cmp ? 5 : 3) . '">' . $label . '</td></tr>';
    @endphp

    {{-- Revenue --}}
    {!! $heading('Revenue') !!}
    @foreach($report['revenue']['lines'] as $l) {!! $detail($l) !!} @endforeach
    {!! $total('Total Revenue', $report['revenue']['total']) !!}

    {{-- Cost of sales --}}
    @php $cos = $report['cost_of_sales']; @endphp
    {!! $heading('Cost of Sales') !!}
    {!! $detail(['name' => 'Opening Stock' . ($cos['opening_stock']['overridden'] ? ' (entered)' : ''), 'amount' => $cos['opening_stock']['amount'], 'compare' => $cos['opening_stock']['compare']]) !!}
    @foreach($cos['purchases']['lines'] as $l) {!! $detail($l) !!} @endforeach
    @foreach($cos['direct_costs']['lines'] as $l) {!! $detail($l) !!} @endforeach
    {!! $detail(['name' => '(−) Closing Stock' . ($cos['closing_stock']['overridden'] ? ' (entered)' : ''), 'amount' => -$cos['closing_stock']['amount'], 'compare' => $cos['closing_stock']['compare'] === null ? null : -$cos['closing_stock']['compare']]) !!}
    {!! $total('Cost of Goods Sold', $cos['total']) !!}

    {!! $total('Gross Profit', $report['gross_profit'], 'key') !!}

    {{-- Other income --}}
    @if(count($report['other_income']['lines']))
      {!! $heading('Other Income') !!}
      @foreach($report['other_income']['lines'] as $l) {!! $detail($l) !!} @endforeach
      {!! $total('Total Other Income', $report['other_income']['total']) !!}
    @endif

    {{-- Overheads --}}
    {!! $heading('Overhead Costs') !!}
    @foreach($report['overheads']['groups'] as $g)
      <tr class="ln"><td class="d" colspan="{{ $cols }}"><strong>{{ $g['label'] }}</strong></td></tr>
      @foreach($g['lines'] as $l) {!! $detail($l, 'd2') !!} @endforeach
      {!! $detail(['name' => 'Total ' . $g['label'], 'amount' => $g['total']['amount'], 'compare' => $g['total']['compare']]) !!}
    @endforeach
    {!! $total('Total Overheads', $report['overheads']['total']) !!}

    {!! $total('Net Profit / (Loss) before Taxation', $report['net_before_tax'], 'key') !!}

    {!! $detail(['name' => '(−) Taxation', 'amount' => $report['taxation']['total']['amount'], 'compare' => $report['taxation']['total']['compare']]) !!}
    @foreach($report['taxation']['lines'] as $l) {!! $detail($l, 'd2') !!} @endforeach
    {!! $total('Net Profit / (Loss) after Taxation', $report['net_after_tax'], 'key') !!}

    {!! $detail(['name' => '(−) Provision for Reserves', 'amount' => $report['reserves']['total']['amount'], 'compare' => $report['reserves']['total']['compare']]) !!}
    @foreach($report['reserves']['lines'] as $l) {!! $detail($l, 'd2') !!} @endforeach

    {!! $total('Net Profit / (Loss) for the Period', $report['net_for_period'], 'final') !!}
  </table>

  <div class="notes">
    <div>Opening stock = value at the end of {{ $date($cos['opening_stock']['as_at']) }} (the day before the period starts); closing stock = value at the end of {{ $date($cos['closing_stock']['as_at']) }}.
      @if($cos['opening_stock']['overridden'] || $cos['closing_stock']['overridden']) Figures marked "(entered)" were typed in by the user. @endif</div>
    <div>Stock basis: {{ $report['stock_source']['basis'] }}</div>
    <div>Details lists each item (account or stock value); Amount is the section total or result. Figures in brackets are negative or deducted.</div>
    <div>All other figures are posted journals (draft journals excluded).</div>
  </div>
</body>
</html>
