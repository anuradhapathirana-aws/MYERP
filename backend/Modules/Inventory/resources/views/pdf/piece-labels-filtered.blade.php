<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
<title>Piece Labels</title>
<style>
  /* One PDF page = one physical row of the 2-up sticker roll:
     [38mm sticker][3mm gap][38mm sticker] = 79mm x 25mm.
     The TSC driver (stock 79x25mm, gap sensing) advances one row per page.

     Everything is absolutely positioned inside a fixed-height row container:
     dompdf's table layout ignores column widths at this page size and
     paginates on unclipped flow height, which shifted every subsequent
     sticker. Out-of-flow children keep the row at exactly one page, and
     overflow:hidden stops long text bleeding into the neighbouring sticker. */
  @page { margin: 0; }
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DejaVu Sans', sans-serif; color: #000; line-height: 1.15; }

  .row { position: relative; width: 79mm; height: 24.6mm; overflow: hidden; }

  .qr { position: absolute; top: 1mm; width: 18mm; height: 18mm; }
  /* Left-aligned to the QR's left edge; nowrap so long codes never wrap
     onto the product-name line below. */
  .piece-code {
    position: absolute; top: 19.6mm; width: 36mm; text-align: left; white-space: nowrap;
    font-family: 'DejaVu Sans Mono', monospace; font-size: 4.5pt; font-weight: 700;
  }
  /* Colour / Roll / Weight / Batch are the values read at the cutting table,
     so they carry the sticker's largest, heaviest type. Boxed to the QR's
     height: at 6pt a wrapped line would otherwise push the stack down into
     the piece-code band below. */
  .txt {
    position: absolute; top: 1mm; width: 17.2mm; height: 18mm; overflow: hidden;
    font-size: 6pt; font-weight: 700;
  }
  /* Product name runs along the sticker's bottom edge, under the QR row. */
  .name { position: absolute; top: 21.9mm; width: 36mm; text-align: left; font-size: 4.8pt; font-weight: 700; }
  .line { margin-top: 0.6mm; white-space: nowrap; }
  /* Tells the operator which sticker on the roll this one supersedes.
     Plain "REPL" text: DejaVu Sans Mono has no glyph for the &#8631; arrow. */
  .replaces { margin-top: 0.9mm; font-family: 'DejaVu Sans Mono', monospace; font-size: 4.2pt; font-weight: 700; }
</style>
</head>
<body>
{{-- One page per piece, the same label on BOTH stickers of the row: every
     product roll wears two identical QR tags, so the pair prints together. --}}
@foreach($labels as $label)
  <div class="row">
    @foreach([0, 41] as $x) {{-- sticker 2 starts after 38mm sticker + 3mm gap --}}
    <img class="qr" style="left: {{ $x + 1 }}mm;" src="{{ $label['qr_data_uri'] }}">
    <div class="piece-code" style="left: {{ $x + 1 }}mm;">{{ \Illuminate\Support\Str::limit($label['piece_code'], 38, '') }}</div>
    <div class="name" style="left: {{ $x + 1 }}mm;">{{ \Illuminate\Support\Str::limit($label['product_name'] ?? '', 28) }}</div>
    <div class="txt" style="left: {{ $x + 20.3 }}mm;">
      {{-- Truncated to what 6pt bold fits across 17.2mm — roughly 14 characters
           including the prefix — so no line clips mid-glyph. --}}
      @if($label['color'])
      <div class="line" style="margin-top: 0;">{{ \Illuminate\Support\Str::limit($label['color'], 13) }}</div>
      @endif
      @if($label['roll_no'])
      <div class="line">Roll: {{ \Illuminate\Support\Str::limit($label['roll_no'], 8, '') }}</div>
      @endif
      @if($label['weight'] !== null)
      <div class="line">Wt: {{ number_format((float) $label['weight'], 2) }} {{ $label['uom'] }}</div>
      @endif
      @if($label['batch_no'])
      <div class="line">B: {{ \Illuminate\Support\Str::limit($label['batch_no'], 9, '') }}</div>
      @endif
      @if(!empty($label['replaces']))
      <div class="replaces">REPL {{ $label['replaces'] }}</div>
      @endif
    </div>
    @endforeach
  </div>
  @if(!$loop->last)
  <div style="page-break-after: always;"></div>
  @endif
@endforeach
</body>
</html>
