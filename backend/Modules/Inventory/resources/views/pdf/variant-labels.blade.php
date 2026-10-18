<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Variant Labels — {{ $grn->grn_no }}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 7pt; color: #1e293b; }

  .page-title { font-size: 10pt; font-weight: 700; color: #0f766e; margin: 4px 0 8px; }

  .label-table { width: 100%; border-collapse: collapse; }
  .label-cell { width: 25%; border: 1px dashed #cbd5e1; padding: 5px; text-align: center; vertical-align: middle; }
  .qr-img { width: 80px; height: 80px; }
  .product-name { font-size: 6.5pt; font-weight: 700; color: #1e293b; margin-top: 2px; }
  .variant { font-size: 6.5pt; color: #475569; margin-top: 1px; }
</style>
</head>
<body>
  <div class="page-title">GRN {{ $grn->grn_no }} — Variant Labels</div>

  <table class="label-table">
    @foreach($labels->chunk(4) as $row)
    <tr>
      @foreach($row as $label)
      <td class="label-cell">
        <img class="qr-img" src="{{ $label['qr_data_uri'] }}">
        <div class="product-name">{{ $label['product_name'] }}</div>
        <div class="variant">{{ $label['variant_label'] }}</div>
      </td>
      @endforeach
      @for($i = $row->count(); $i < 4; $i++)
      <td class="label-cell"></td>
      @endfor
    </tr>
    @endforeach
  </table>
</body>
</html>
