import { useState } from 'react'
import { ChevronDown } from 'lucide-react'

/** Card with a clickable title bar that shows/hides its body — collapsed by default */
export default function CollapsibleCard({ title, defaultOpen = false, className = '', children }) {
  const [open, setOpen] = useState(defaultOpen)

  return (
    <div className={`rounded-xl border border-slate-200 bg-white shadow-sm ${className}`}>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className="flex w-full items-center justify-between px-4 py-2 text-left"
        aria-expanded={open}
      >
        <span className="text-xs font-bold uppercase tracking-wider text-slate-600">{title}</span>
        <ChevronDown
          size={14}
          className={`shrink-0 text-slate-400 transition-transform duration-200 ${open ? 'rotate-180' : ''}`}
        />
      </button>
      {open && <div className="border-t border-slate-100 px-4 py-2.5">{children}</div>}
    </div>
  )
}
