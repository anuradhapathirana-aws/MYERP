import { useEffect, useMemo, useRef, useState } from 'react'
import { Check, ChevronDown, Search, X } from 'lucide-react'

/** Rows rendered at once; typing narrows the rest. Keeps a chart of thousands of accounts snappy. */
const RENDER_LIMIT = 300

/**
 * Compact searchable MULTI-select for filter panels; the many-values sibling
 * of FilterSearchSelect, with the same look.
 *
 * options:  [{ value, label, group?, muted? }]. `group` renders category
 *           headers; `muted` greys an option out (e.g. an inactive account)
 *           while keeping it selectable.
 * value:    array of selected values (compared as strings).
 * onChange: receives the new array.
 * wide:     a wider pad for long labels.
 */
export default function MultiSearchSelect({
  value = [],
  onChange,
  options = [],
  placeholder = 'All',
  wide = false,
}) {
  const [open, setOpen]   = useState(false)
  const [query, setQuery] = useState('')
  const containerRef      = useRef(null)
  const searchRef         = useRef(null)

  const selected = useMemo(() => new Set(value.map(String)), [value])

  const q        = query.trim().toLowerCase()
  const filtered = useMemo(
    () => (q
      ? options.filter((o) => o.label.toLowerCase().includes(q) || (o.group || '').toLowerCase().includes(q))
      : options),
    [options, q],
  )
  // A group header goes above an option only where the group changes, so a
  // search keeps the options in their original order.
  const shown = filtered.slice(0, RENDER_LIMIT).map((o, i, list) => ({
    ...o,
    header: o.group && o.group !== list[i - 1]?.group ? o.group : null,
  }))

  useEffect(() => {
    if (!open) return undefined
    const onDown = (e) => { if (!containerRef.current?.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', onDown)
    const t = setTimeout(() => searchRef.current?.focus(), 40)
    return () => { document.removeEventListener('mousedown', onDown); clearTimeout(t) }
  }, [open])

  const close  = () => { setOpen(false); setQuery('') }
  const toggle = (v) => {
    const key = String(v)
    onChange(selected.has(key) ? value.filter((x) => String(x) !== key) : [...value, v])
  }
  // Adds every option matching the search (not only the rendered ones).
  const selectFiltered = () => {
    const add = filtered.map((o) => o.value).filter((v) => !selected.has(String(v)))
    onChange([...value, ...add])
  }
  const clear = (e) => { e?.stopPropagation(); onChange([]) }

  // None: the placeholder. One: its label. Several: a count badge + "selected".
  const label = value.length === 0
    ? placeholder
    : value.length === 1 ? (options.find((o) => selected.has(String(o.value)))?.label ?? '1 selected') : 'selected'

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        onClick={() => (open ? close() : setOpen(true))}
        onKeyDown={(e) => { if (e.key === 'Escape') close() }}
        title={value.length > 1 ? options.filter((o) => selected.has(String(o.value))).map((o) => o.label).join('\n') : undefined}
        className="flex w-full items-center justify-between gap-1 rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-left text-xs outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15"
      >
        <span className={`truncate ${value.length ? 'text-slate-800' : 'text-slate-400'}`}>
          {value.length > 1 && (
            <span className="mr-1 rounded-full bg-indigo-600 px-1.5 text-[10px] font-bold text-white">{value.length}</span>
          )}
          {label}
        </span>
        <div className="flex shrink-0 items-center gap-0.5">
          {value.length > 0 && (
            <span onClick={clear} title="Clear selection" className="cursor-pointer text-slate-400 hover:text-red-600">
              <X size={10} />
            </span>
          )}
          <ChevronDown size={11} className={`text-slate-400 transition-transform duration-150 ${open ? 'rotate-180' : ''}`} />
        </div>
      </button>

      {open && (
        <div
          className={`absolute left-0 z-50 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg ${wide ? 'min-w-105 max-w-[90vw]' : 'min-w-52'}`}
          onKeyDown={(e) => { if (e.key === 'Escape') close() }}
        >
          <div className="border-b border-slate-100 p-1.5">
            <div className="flex items-center gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5">
              <Search size={11} className="shrink-0 text-slate-400" />
              <input
                ref={searchRef}
                type="text"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search…"
                className="w-full bg-transparent text-xs text-slate-700 outline-none placeholder-slate-400"
              />
              {query && (
                <button type="button" onClick={() => setQuery('')} className="text-slate-400 hover:text-slate-600">
                  <X size={10} />
                </button>
              )}
            </div>
            <div className="mt-1 flex items-center justify-between text-[10px] font-semibold">
              <button type="button" onClick={selectFiltered} disabled={filtered.length === 0} className="text-indigo-600 hover:text-indigo-800 disabled:opacity-40">
                Select {q ? 'matching' : 'all'} ({filtered.length})
              </button>
              <button type="button" onClick={clear} disabled={value.length === 0} className="text-red-600 hover:text-red-800 disabled:opacity-40">
                Clear ({value.length})
              </button>
            </div>
          </div>

          <div className="max-h-60 overflow-y-auto py-1">
            {shown.length === 0 && <p className="px-3 py-2 text-xs italic text-slate-400">No results found</p>}

            {shown.map((o) => {
              const isOn = selected.has(String(o.value))

              return (
                <div key={o.value}>
                  {o.header && (
                    <div className="sticky top-0 bg-slate-50 px-3 py-1 text-[9px] font-bold uppercase tracking-wider text-slate-400">
                      {o.header}
                    </div>
                  )}
                  <button
                    type="button"
                    onClick={() => toggle(o.value)}
                    title={o.label}
                    className={`flex w-full items-center gap-2 px-3 py-1 text-left text-xs transition-colors ${
                      isOn ? 'bg-indigo-50 font-semibold text-indigo-700' : o.muted ? 'text-slate-400 hover:bg-slate-50' : 'text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    <span className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border ${isOn ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-300 bg-white'}`}>
                      {isOn && <Check size={10} strokeWidth={3} />}
                    </span>
                    <span className="truncate">{o.label}</span>
                  </button>
                </div>
              )
            })}

            {filtered.length > shown.length && (
              <p className="px-3 py-1.5 text-[10px] italic text-slate-400">
                {filtered.length - shown.length} more — type to narrow the list.
              </p>
            )}
          </div>
        </div>
      )}
    </div>
  )
}
