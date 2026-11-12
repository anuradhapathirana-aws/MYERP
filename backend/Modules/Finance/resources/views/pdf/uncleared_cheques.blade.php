<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Uncleared Cheques — {{ $header['as_at'] }}</title>
<style>
  /* A4 landscape, black-on-white, the same DomPDF-safe rules as the Bank Reconciliation Statement. */
  @page { margin: 10mm 10mm 12mm 10mm; }

  * { box-sizing: border-box; }
  body { margin: 0; padding: 0; font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 8pt; color: #111827; }
  table, div, span, p { margin: 0; padding: 0; }

  .head { width: 100%; border-collapse: collapse; border-bottom: 1.2pt solid #1f2937; }
  .co-name { font-size: 14pt; font-weight: bold; }
  .co-sub  { font-size: 8pt; color: #6b7280; padding-bottom: 5px; }

  .title { text-align: center; font-size: 12pt; font-weight: bold; letter-spacing: 2px; text-transform: uppercase; margin: 8px 0 6px; }

  .meta { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
  .meta td { border: 0.8pt solid #1f2937; padding: 3px 6px; vertical-align: top; }
  .lbl { color: #6b7280; font-size: 7pt; text-transform: uppercase; letter-spacing: 0.5px; }
  .val { font-weight: bold; }

  h3 { font-size: 8.5pt; text-transform: uppercase; letter-spacing: 0.5px; margin: 8px 0 3px; }
  .items { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
  .items th { border: 0.8pt solid #1f2937; padding: 2px 4px; font-size: 7pt; text-transform: uppercase; text-align: left; background: #f3f4f6; }
  .items td { border: 0.8pt solid #1f2937; padding: 2px 4px; font-size: 7.5pt; }
  .items tr.sum td { font-weight: bold; background: #f9fafb; }
  .r { text-align: right; }
  .nw { white-space: nowrap; }
  .flag { font-size: 6.5pt; font-weight: bold; color: #b91c1c; }
  .empty { color: #9ca3af; font-style: italic; }
  .note { font-size: 7pt; color: #6b7280; margin-top: 6px; }
  .warn { font-size: 8pt; font-weight: bold; color: #b45309; margin: 4px 0; }
</style>
</head>
<body>

  @php
    $money = fn ($n) => number_format((float) $n, 2);
    $date  = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d-m-Y') : '';
    $rows  = collect($rows);
  @endphp

  <table class="head">
    <tr>
      <td>
        <div class="co-name">{{ $header['company_name'] }}</div>
        @if($header['company_address'])<div class="co-sub">{{ $header['company_address'] }}</div>@endif
      </td>
    </tr>
  </table>

  <div class="title">Uncleared Cheques</div>

  <table class="meta">
    <tr>
      <td style="width:18%"><div class="lbl">As at</div><div class="val">{{ $date($header['as_at']) }}</div></td>
      <td style="width:34%"><div class="lbl">Bank Account</div><div class="val">{{ $header['bank_account'] }}</div></td>
      <td style="width:18%"><div class="lbl">Type</div><div class="val">{{ $header['type_label'] }}</div></td>
      <td style="width:30%"><div class="lbl">Generated</div><div>{{ $header['generated_by'] }} · {{ $header['generated_at'] }}</div>
        @if($header['search'])<div>Search: {{ $header['search'] }}</div>@endif
      </td>
    </tr>
  </table>

  @if($truncated)
    <div class="warn">Only the first {{ \Modules\Finance\Services\UnclearedChequesReportService::MAX_ROWS }} cheques are listed. Narrow the filters for a complete report.</div>
  @endif

  <h3>Summary by bank account</h3>
  <table class="items">
    <tr>
      <th>Bank Account</th><th style="width:16%">Bank / A/C No</th>
      <th class="r" style="width:9%">Issued (No)</th><th class="r" style="width:13%">Issued, not presented</th>
      <th class="r" style="width:9%">Deposited (No)</th><th class="r" style="width:13%">Deposited, not cleared</th>
    </tr>
    @forelse($banks as $b)
      <tr>
        <td>{{ $b['bank_account'] }}</td>
        <td>{{ collect([$b['bank_name'], $b['bank_account_no']])->filter()->implode(' · ') }}</td>
        <td class="r">{{ $b['issued_count'] }}</td><td class="r nw">{{ $money($b['issued_total']) }}</td>
        <td class="r">{{ $b['received_count'] }}</td><td class="r nw">{{ $money($b['received_total']) }}</td>
      </tr>
    @empty
      <tr><td colspan="6" class="empty">No uncleared cheques.</td></tr>
    @endforelse
    @if(count($banks) > 1)
      <tr class="sum">
        <td colspan="2" class="r">Total</td>
        <td class="r">{{ $summary['issued_count'] }}</td><td class="r nw">{{ $money($summary['issued_total']) }}</td>
        <td class="r">{{ $summary['received_count'] }}</td><td class="r nw">{{ $money($summary['received_total']) }}</td>
      </tr>
    @endif
  </table>

  @foreach([
      ['issued', 'Issued, not presented'],
      ['received', 'Deposited, not cleared'],
  ] as [$type, $heading])
    @php $group = $rows->where('type', $type); @endphp
    @if($header['type'] === 'all' || $header['type'] === $type)
      <h3>{{ $heading }} ({{ $group->count() }})</h3>
      <table class="items">
        <tr>
          <th style="width:15%">Bank Account</th><th style="width:8%">Cheque No</th><th style="width:7%">Chq Date</th>
          <th style="width:9%">Journal</th><th style="width:7%">Jnl Date</th><th style="width:9%">Document</th>
          <th>Party</th><th style="width:5%" class="r">Days</th><th style="width:7%">Flags</th><th class="r" style="width:10%">Amount</th>
        </tr>
        @forelse($group as $r)
          <tr>
            <td>{{ \Illuminate\Support\Str::limit((string) $r['bank_account'], 28) }}</td>
            <td class="nw">{{ $r['cheque_no'] }}</td>
            <td class="nw">{{ $date($r['cheque_date']) }}</td>
            <td class="nw">{{ $r['journal_no'] }}</td>
            <td class="nw">{{ $date($r['journal_date']) }}</td>
            <td class="nw">{{ $r['document_no'] }}</td>
            <td>{{ \Illuminate\Support\Str::limit((string) ($r['party_name'] ?? $r['description']), 45) }}</td>
            <td class="r">{{ $r['days_outstanding'] }}</td>
            <td class="flag">{{ implode(' ', array_filter([$r['is_stale'] ? 'STALE' : null, $r['is_post_dated'] ? 'PDC' : null])) }}</td>
            <td class="r nw">{{ $money($r['amount']) }}</td>
          </tr>
        @empty
          <tr><td colspan="10" class="empty">None.</td></tr>
        @endforelse
        @if($group->count())
          <tr class="sum">
            <td colspan="9" class="r">Total</td>
            <td class="r nw">{{ $money($type === 'issued' ? $summary['issued_total'] : $summary['received_total']) }}</td>
          </tr>
        @endif
      </table>
    @endif
  @endforeach

  <div class="note">
    A cheque is uncleared until a posted bank reconciliation, with a statement date on or before the As at date, marks it Realized or Returned.
    Days = days since it was entered in the books. STALE = cheque date more than {{ $header['stale_months'] }} months before the As at date.
    PDC = post-dated (cheque date after the As at date). Only entries carrying a cheque number are listed.
  </div>

</body>
</html>
