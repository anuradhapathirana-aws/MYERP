import { useEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { ChevronDown, Layers } from 'lucide-react'

/**
 * Compact searchable variant picker for document lines. Type part of the
 * label or SKU ("red m", "TSH-RED"), arrow keys to move, Enter to pick.
 *
 * options: [{ id, variant_label, sku, stock? }]
 * onChange(variant) receives the whole option (or null when cleared).
 */
export default function VariantSelect({
  options = [], value, onChange, onNext, onBlur, cellRef,
  disabled = false, error = false, placeholder = 'Select variant', showStock = false,
}) {
  const [open, setOpen]       = useState(false)
  const [query, setQuery]     = useState('')
  const [highlight, setHighlight] = useState(0)
  const [pos, setPos]         = useState({ top: 0, left: 0, width: 240 })
  const btnRef       = useRef(null)
  const containerRef = useRef(null)
  const menuRef      = useRef(null)

  const selected = options.find((o) => String(o.id) === String(value))
  const terms    = query.trim().toLowerCase().split(/\s+/).filter(Boolean)
  const filtered = options.filter((o) => {
    const hay = `${o.variant_label} ${o.sku}`.toLowerCase()
    return terms.every((t) => hay.includes(t))
  })

  const openMenu = () => {
    if (disabled) return
    const idx = options.findIndex((o) => String(o.id) === String(value))
    setHighlight(idx >= 0 ? idx : 0)
    setQuery('')
    setOpen(true)
  }

  useEffect(() => {
    if (!open) return
    const place = () => {
      if (!btnRef.current) return
      const r = btnRef.current.getBoundingClientRect()
      setPos({ top: r.bottom + 2, left: r.left, width: Math.max(r.width, 240) })
    }
    place()
    window.addEventListener('scroll', place, true)
    window.addEventListener('resize', place)
    return () => {
      window.removeEventListener('scroll', place, true)
      window.removeEventListener('resize', place)
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const handler = (e) => {
      if (!containerRef.current?.contains(e.target) && !menuRef.current?.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const pick = (o) => {
    onChange(o)
    setOpen(false)
    setTimeout(() => onNext?.(), 0)
  }

  const onKeyDown = (e) => {
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      if (!open) { openMenu(); return }
      setHighlight((i) => Math.min(i + 1, filtered.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setHighlight((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (open && filtered[highlight]) pick(filtered[highlight])
      else { setOpen(false); onNext?.() }
    } else if (e.key === 'Escape') {
      e.preventDefault()
      setOpen(false)
    }
  }

  const btnCls = disabled
    ? 'border-slate-200 bg-slate-100 text-slate-400 cursor-not-allowed'
    : error
      ? 'border-red-400 bg-red-50 text-slate-800 focus:border-red-500 focus:bg-white cursor-pointer'
      : 'border-teal-300 bg-teal-50/40 text-slate-800 focus:border-indigo-500 focus:bg-white cursor-pointer'

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        ref={(el) => { btnRef.current = el; cellRef?.(el) }}
        disabled={disabled}
        // Keyboard (Tab / Enter chain) opens the pad on focus. A mouse click toggles it in
        // onMouseDown and suppresses the focus — otherwise focus opens it and the click
        // that follows immediately closes it again.
        onFocus={openMenu}
        onMouseDown={(e) => {
          if (disabled) return
          e.preventDefault()
          if (open) setOpen(false)
          else openMenu()
        }}
        onKeyDown={onKeyDown}
        onBlur={onBlur}
        title={selected ? `${selected.variant_label} · ${selected.sku}` : undefined}
        className={`flex w-full items-center justify-between gap-1 rounded border px-1.5 py-0.5 text-left text-xs outline-none transition-all ${btnCls}`}
      >
        <span className={`flex min-w-0 items-center gap-1 truncate ${selected ? '' : 'text-slate-400'}`}>
          <Layers size={10} className="shrink-0 text-teal-600" />
          <span className="truncate">{selected ? selected.variant_label : placeholder}</span>
        </span>
        <ChevronDown size={10} className="shrink-0 text-slate-400" />
      </button>

      {open && !disabled && createPortal(
        <div
          ref={menuRef}
          style={{ position: 'fixed', top: pos.top, left: pos.left, width: pos.width, zIndex: 9999 }}
          className="rounded border border-slate-200 bg-white shadow-xl"
        >
          <div className="border-b border-slate-100 p-1">
            <input
              autoFocus
              value={query}
              onChange={(e) => { setQuery(e.target.value); setHighlight(0) }}
              onKeyDown={onKeyDown}
              placeholder="Type colour / size / SKU…"
              className="block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs outline-none focus:border-indigo-500 focus:bg-white"
            />
          </div>
          <div className="max-h-56 overflow-y-auto">
            {filtered.length === 0 && <div className="px-2 py-1.5 text-xs italic text-slate-400">No matching variant</div>}
            {filtered.map((o, i) => (
              <button
                key={o.id}
                type="button"
                onMouseDown={(e) => { e.preventDefault(); pick(o) }}
                onMouseEnter={() => setHighlight(i)}
                className={`flex w-full items-center justify-between gap-2 px-2 py-1 text-left text-xs ${
                  i === highlight ? 'bg-indigo-100 text-indigo-900' : 'text-slate-700 hover:bg-slate-50'
                }`}
              >
                <span className="min-w-0">
                  <span className="block truncate font-medium">{o.variant_label || 'Default'}</span>
                  <span className="block truncate font-mono text-[10px] text-slate-400">{o.sku}</span>
                </span>
                {showStock && (
                  <span className={`shrink-0 rounded px-1 text-[10px] font-semibold ${
                    Number(o.stock) > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-600'
                  }`}>
                    {Number(o.stock || 0).toLocaleString()}
                  </span>
                )}
              </button>
            ))}
          </div>
        </div>,
        document.body,
      )}
    </div>
  )
}
