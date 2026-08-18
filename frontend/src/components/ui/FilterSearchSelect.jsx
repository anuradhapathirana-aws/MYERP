import { useEffect, useRef, useState } from 'react'
import { ChevronDown, Search, X } from 'lucide-react'

/**
 * Compact searchable dropdown for filter panels.
 * options: [{ value, label, group? }] — when any option carries a `group`,
 *          the pad renders category headers (a simple tree).
 * wide: opens a wider option pad for long labels (e.g. product names).
 */
export default function FilterSearchSelect({
  value,
  onChange,
  options = [],
  placeholder = 'All',
  wide = false,
}) {
  const [open, setOpen]   = useState(false)
  const [query, setQuery] = useState('')
  const containerRef      = useRef(null)
  const searchRef         = useRef(null)

  const selected = options.find((o) => String(o.value) === String(value))

  const q = query.trim().toLowerCase()
  const filtered = q
    ? options.filter((o) => o.label.toLowerCase().includes(q) || (o.group || '').toLowerCase().includes(q))
    : options

  // Group filtered options by their `group` label, preserving first-seen order
  const hasGroups = options.some((o) => o.group)
  const grouped = []
  if (hasGroups) {
    const byGroup = new Map()
    for (const o of filtered) {
      const g = o.group || 'Other'
      if (!byGroup.has(g)) {
        const items = []
        byGroup.set(g, items)
        grouped.push({ group: g, items })
      }
      byGroup.get(g).push(o)
    }
  }

  const optionBtn = (o) => (
    <button
      key={o.value}
      type="button"
      onClick={() => select(o.value)}
      title={o.label}
      className={`w-full truncate px-3 py-1.5 text-left text-xs transition-colors ${
        String(value) === String(o.value)
          ? 'bg-indigo-50 font-semibold text-indigo-700'
          : 'text-slate-700 hover:bg-slate-50'
      }`}
    >
      {o.label}
    </button>
  )

  useEffect(() => {
    if (!open) { setQuery(''); return }
    const handler = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', handler)
    setTimeout(() => searchRef.current?.focus(), 40)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const select = (val) => { onChange(val); setOpen(false) }

  const clear = (e) => { e.stopPropagation(); onChange('') }

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className="flex w-full items-center justify-between gap-1 rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-left text-xs outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15"
      >
        <span className={`truncate ${selected ? 'text-slate-800' : 'text-slate-400'}`}>
          {selected?.label ?? placeholder}
        </span>
        <div className="flex shrink-0 items-center gap-0.5">
          {value && (
            <span onClick={clear} className="cursor-pointer text-slate-400 hover:text-slate-600">
              <X size={10} />
            </span>
          )}
          <ChevronDown
            size={11}
            className={`text-slate-400 transition-transform duration-150 ${open ? 'rotate-180' : ''}`}
          />
        </div>
      </button>

      {open && (
        <div className={`absolute left-0 z-50 mt-1 w-full rounded-lg border border-slate-200 bg-white shadow-lg ${wide ? 'min-w-105 max-w-[90vw]' : 'min-w-52'}`}>
          {/* search input */}
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
          </div>

          {/* option list */}
          <div className="max-h-48 overflow-y-auto py-1">
            <button
              type="button"
              onClick={() => select('')}
              className={`w-full px-3 py-1.5 text-left text-xs transition-colors ${
                !value ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-slate-500 hover:bg-slate-50'
              }`}
            >
              {placeholder}
            </button>

            {filtered.length === 0 ? (
              <p className="px-3 py-2 text-xs italic text-slate-400">No results found</p>
            ) : hasGroups ? (
              grouped.map((grp) => (
                <div key={grp.group}>
                  <div className="sticky top-0 bg-slate-50 px-3 py-1 text-[9px] font-bold uppercase tracking-wider text-slate-400">
                    {grp.group}
                  </div>
                  {grp.items.map(optionBtn)}
                </div>
              ))
            ) : (
              filtered.map(optionBtn)
            )}
          </div>
        </div>
      )}
    </div>
  )
}
