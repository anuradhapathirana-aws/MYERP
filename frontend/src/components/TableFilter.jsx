import { ChevronDown, ChevronUp, Filter, Search, X } from 'lucide-react'

export default function TableFilter({ open, onToggle, onApply, onClear, activeCount = 0, children }) {
  const handleKeyDown = (e) => {
    if (e.key === 'Enter') onApply()
  }

  return (
    <div className="mt-2 rounded-xl border border-slate-200 bg-white shadow-sm">
      {/* ── toggle bar — entire row is clickable ── */}
      <div
        className="flex cursor-pointer select-none items-center justify-between px-3 py-2 transition-colors hover:bg-slate-50"
        onClick={onToggle}
      >
        <div className="flex items-center gap-1.5 text-xs font-semibold text-slate-600">
          <Filter size={13} className={activeCount > 0 ? 'text-indigo-500' : 'text-slate-400'} />
          <span>Filters</span>
          {activeCount > 0 && (
            <span className="rounded-full bg-indigo-600 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
              {activeCount}
            </span>
          )}
        </div>
        <div className="flex items-center gap-2">
          {activeCount > 0 && (
            <span
              onClick={(e) => { e.stopPropagation(); onClear(); }}
              className="flex cursor-pointer items-center gap-1 text-[11px] font-medium text-slate-400 transition-colors hover:text-red-500"
            >
              <X size={11} />
              Clear all
            </span>
          )}
          {open ? (
            <ChevronUp size={12} className="text-slate-400" />
          ) : (
            <ChevronDown size={12} className="text-slate-400" />
          )}
        </div>
      </div>

      {/* ── collapsible body ── */}
      {open && (
        <div className="border-t border-slate-100 px-3 pb-3 pt-2.5" onKeyDown={handleKeyDown}>
          <div className="grid grid-cols-2 items-end gap-x-3 gap-y-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            {children}
            {/* Action buttons inline at end of filter row */}
            <div className="flex items-center gap-1.5 pb-0.5">
              <button
                type="button"
                onClick={onApply}
                className="flex items-center gap-1 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition-colors hover:bg-indigo-700 active:bg-indigo-800"
              >
                <Search size={12} />
                Apply Filter
              </button>
              <button
                type="button"
                onClick={onClear}
                className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-50"
              >
                Clear
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

export function FilterField({ label, children }) {
  return (
    <div className="flex flex-col gap-0.5">
      <label className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
        {label}
      </label>
      {children}
    </div>
  )
}
