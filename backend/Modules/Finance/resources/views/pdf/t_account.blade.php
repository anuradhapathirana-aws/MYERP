<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>{{ $account['ledger_account_name'] }} Account</title>
@include('finance::pdf.partials.ledger_styles')
</head>
<body>

  @php
    $date = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d-m-Y') : '';
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

  @include('finance::pdf.partials.t_account_table')

  @include('finance::pdf.partials.t_account_note')

</body>
</html>
