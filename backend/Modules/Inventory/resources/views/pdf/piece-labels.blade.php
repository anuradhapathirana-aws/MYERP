<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Piece Labels — {{ $grn->grn_no }}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 7pt; color: #1e293b; }

  .page-title { font-size: 10pt; font-weight: 700; color: #4f46e5; margin: 4px 0 8px; }

  .label-table { width: 100%; border-collapse: collapse; }
  .label-cell { width: 33.33%; border: 1px dashed #cbd5e1; padding: 6px; text-align: center; vertical-align: middle; }
  .qr-img { width: 90px; height: 90px; }
  .piece-code { font-family: 'DejaVu Sans Mono', monospace; font-size: 6.5pt; font-weight: 700; color: #1e293b; margin-top: 3px; }
  .product-name { font-size: 6pt; color: #475569; margin-top: 1px; }
  /* Tells the operator which sticker on the roll this one supersedes. */
  .replaces { font-family: 'DejaVu Sans Mono', monospace; font-size: 5.5pt; font-weight: 700; color: #b45309; margin-top: 2px; }
</style>
</head>
<body>
  <div class="page-title">GRN {{ $grn->grn_no }} — Piece Labels</div>

  <table class="label-table">
    @foreach($labels->chunk(3) as $row)
    <tr>
      @foreach($row as $label)
      <td class="label-cell">
        <img class="qr-img" src="{{ $label['qr_data_uri'] }}">
        <div class="piece-code">{{ $label['piece_code'] }}</div>
        <div class="product-name">{{ $label['product']->name ?? '' }}</div>
        @if($label['roll_no'])
        <div class="product-name">Roll: {{ $label['roll_no'] }}</div>
        @endif
        @if($label['weight'] !== null)
        <div class="product-name">Wt: {{ number_format((float) $label['weight'], 2) }} {{ $label['uom'] }}</div>
        @endif
        @if($label['batch_no'])
        <div class="product-name">Batch: {{ $label['batch_no'] }}</div>
        @endif
        @if(!empty($label['replaces']))
        <div class="replaces">&#8631; REPLACES {{ $label['replaces'] }}</div>
        @endif
      </td>
      @endforeach
      @for($i = $row->count(); $i < 3; $i++)
      <td class="label-cell"></td>
      @endfor
    </tr>
    @endforeach
  </table>
</body>
</html>
