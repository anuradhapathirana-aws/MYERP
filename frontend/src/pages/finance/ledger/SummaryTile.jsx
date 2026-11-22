/** One labelled figure in a ledger summary strip. `strong` highlights the closing balance. */
export default function SummaryTile({ label, value, cls = 'text-slate-800', hint, strong = false }) {
  return (
    <div className={`rounded-lg border px-2 py-1.5 ${strong ? 'border-indigo-200 bg-indigo-50/50' : 'border-slate-200 bg-slate-50'}`}>
      <p className="text-[10px] font-bold uppercase tracking-wider text-slate-500" title={hint}>{label}</p>
      <p className={`text-sm font-bold tabular-nums ${cls}`}>{value}</p>
    </div>
  )
}
