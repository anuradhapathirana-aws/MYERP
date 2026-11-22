import { Columns2, List } from 'lucide-react'

const VIEWS = [
  ['list', 'List', List],
  ['t_account', 'T-Account', Columns2],
]

/** List | T-Account segmented switch (Alt+T is wired by the screen using it). */
export default function LedgerViewSwitch({ view, onChange }) {
  return (
    <div className="inline-flex rounded-lg border-2 border-slate-200 bg-slate-50 p-0.5 text-xs font-semibold" role="tablist">
      {VIEWS.map(([v, label, Icon]) => (
        <button
          key={v}
          type="button"
          role="tab"
          aria-selected={view === v}
          onClick={() => onChange(v)}
          title={`${label} view (Alt+T)`}
          className={`inline-flex items-center gap-1 rounded-md px-2 py-1 transition-colors ${
            view === v ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-white'
          }`}
        >
          <Icon size={13} /> {label}
        </button>
      ))}
    </div>
  )
}
