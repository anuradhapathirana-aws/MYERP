import { useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Check, ChevronDown, Search, X } from 'lucide-react'
import { getAllAttributes } from '../../api/attributes'
import { getAllAttributeTypes } from '../../api/attributeTypes'
import { FilterField } from '../TableFilter'

const ITEM_TYPES = [
  { value: '',         label: 'All items' },
  { value: 'roll',     label: 'Fabric (Roll)' },
  { value: 'quantity', label: 'Finished Good (Qty)' },
]

const BTN_CLS = 'flex w-full items-center justify-between gap-1 rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-left text-xs outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'

/**
 * Item Type + Attribute Values filters shared by the stock and sales reports.
 * The draft keeps `item_type` ('' | 'roll' | 'quantity') and `attribute_values`
 * (comma-separated attribute ids — every one must match: Size = M AND Design = Floral).
 */
export default function ReportVariantFilters({ draft, setDraft, showItemType = true }) {
  return (
    <>
      {showItemType && <FilterField label="Item Type">
        <select
          value={draft.item_type ?? ''}
          onChange={(e) => setDraft((d) => ({ ...d, item_type: e.target.value }))}
          className={`${BTN_CLS} cursor-pointer`}
        >
          {ITEM_TYPES.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
        </select>
      </FilterField>}
      <FilterField label="Attributes">
        <AttributeValuesSelect
          value={draft.attribute_values ?? ''}
          onChange={(v) => setDraft((d) => ({ ...d, attribute_values: v }))}
        />
      </FilterField>
    </>
  )
}

/** Multi-select of attribute values grouped by type ("Size: M", "Design: Floral"). */
function AttributeValuesSelect({ value, onChange }) {
  const [open, setOpen]   = useState(false)
  const [query, setQuery] = useState('')
  const ref = useRef(null)

  const { data: attributes = [] } = useQuery({ queryKey: ['attributes-all'], queryFn: getAllAttributes, staleTime: Infinity })
  const { data: types = [] }      = useQuery({ queryKey: ['attribute-types-all'], queryFn: getAllAttributeTypes, staleTime: Infinity })

  const typeName = new Map(types.map((t) => [String(t.id), t.attribute_type_name]))
  const selected = value ? value.split(',').filter(Boolean) : []
  const options  = attributes.map((a) => ({
    id:    String(a.id),
    label: `${typeName.get(String(a.attribute_type_id)) ?? 'Attribute'}: ${a.attribute_name}`,
  }))
  const q        = query.trim().toLowerCase()
  const filtered = q ? options.filter((o) => o.label.toLowerCase().includes(q)) : options

  useEffect(() => {
    if (!open) return undefined
    const handler = (e) => { if (ref.current && !ref.current.contains(e.target)) setOpen(false) }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const toggle = (id) => {
    const next = selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]
    onChange(next.join(','))
  }

  const labels = options.filter((o) => selected.includes(o.id)).map((o) => o.label)

  return (
    <div className="relative min-w-52" ref={ref}>
      <button type="button" onClick={() => { setQuery(''); setOpen((v) => !v) }} className={BTN_CLS}>
        <span className={`truncate ${labels.length ? 'text-slate-800' : 'text-slate-400'}`} title={labels.join(', ')}>
          {labels.length ? labels.join(', ') : 'All attributes'}
        </span>
        <span className="flex shrink-0 items-center gap-0.5">
          {labels.length > 0 && (
            <span onClick={(e) => { e.stopPropagation(); onChange('') }} className="cursor-pointer text-slate-400 hover:text-slate-600">
              <X size={10} />
            </span>
          )}
          <ChevronDown size={11} className={`text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`} />
        </span>
      </button>

      {open && (
        <div className="absolute left-0 z-50 mt-1 w-full min-w-60 rounded-lg border border-slate-200 bg-white shadow-lg">
          <div className="border-b border-slate-100 p-1.5">
            <div className="flex items-center gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5">
              <Search size={11} className="shrink-0 text-slate-400" />
              <input
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search e.g. Size: M…"
                className="w-full bg-transparent text-xs text-slate-700 outline-none placeholder-slate-400"
              />
            </div>
          </div>
          <div className="max-h-56 overflow-y-auto py-1">
            {filtered.length === 0 && <p className="px-3 py-2 text-xs italic text-slate-400">No results found</p>}
            {filtered.map((o) => {
              const checked = selected.includes(o.id)
              return (
                <button key={o.id} type="button" onClick={() => toggle(o.id)} className="flex w-full items-center gap-2 px-2.5 py-1 text-left hover:bg-slate-50">
                  <span className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border ${checked ? 'border-indigo-600 bg-indigo-600' : 'border-slate-300 bg-white'}`}>
                    {checked && <Check size={10} className="text-white" strokeWidth={3} />}
                  </span>
                  <span className="truncate text-xs text-slate-700">{o.label}</span>
                </button>
              )
            })}
          </div>
          <p className="border-t border-slate-100 px-2.5 py-1 text-[10px] text-slate-400">Every selected value must match.</p>
        </div>
      )}
    </div>
  )
}
