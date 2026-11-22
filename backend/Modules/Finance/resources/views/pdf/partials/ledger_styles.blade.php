{{-- Shared by every printed ledger (T-account, Ledger Details). DomPDF-safe: tables only, no flex/grid, borders in pt. --}}
<style>
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

  /* Ledger (list) layout */
  .acct-head { width: 100%; border-collapse: collapse; margin-top: 10px; }
  .acct-head td { padding: 4px 6px; background: #312e81; color: #ffffff; }
  .acct-head .code { font-size: 7.5pt; color: #c7d2fe; }
  .acct-head .info { font-size: 6.8pt; color: #e0e7ff; text-align: right; }
  .l { width: 100%; border-collapse: collapse; }
  .l th { padding: 3px 4px; font-size: 6.3pt; text-transform: uppercase; letter-spacing: 0.4px; text-align: left; background: #f1f5f9; color: #475569; border-bottom: 0.8pt solid #1f2937; }
  .l td { padding: 2.5px 4px; vertical-align: top; border-bottom: 0.4pt solid #e5e7eb; }
  .l tr.bf td, .l tr.cf td { background: #f8fafc; font-weight: bold; font-style: italic; color: #334155; }
  .l tr.cf td { border-top: 0.9pt solid #1f2937; border-bottom: 2.4pt double #1f2937; }
  .l tr.pt td { font-weight: bold; background: #f1f5f9; border-top: 0.8pt solid #94a3b8; }
  .dr-c { color: #3730a3; }
  .cr-c { color: #92400e; }
  .rev { font-size: 5.8pt; color: #64748b; border: 0.5pt solid #cbd5e1; padding: 0 2px; }

  .grand { width: 100%; border-collapse: collapse; margin-top: 12px; }
  .grand th { padding: 3px 6px; font-size: 6.5pt; text-transform: uppercase; background: #1f2937; color: #ffffff; text-align: right; }
  .grand th.lft { text-align: left; }
  .grand td { padding: 4px 6px; border: 0.8pt solid #cbd5e1; text-align: right; font-weight: bold; }
  .page-break { page-break-after: always; }
</style>
