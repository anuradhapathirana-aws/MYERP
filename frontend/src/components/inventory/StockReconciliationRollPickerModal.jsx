import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Boxes, RefreshCw, Search, X } from 'lucide-react'
import { getAvailableRollsForReconciliation } from '../../api/stockReconciliations'

const fmt = (n, d = 4) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d })

/**
 * Pick one or more of a product's in-stock rolls to correct, and type each one's
 * true weight. Only rolls still 'in_stock' are ever listed or selectable — a roll
 * already reserved for a sale cannot be corrected here (the approval step re-checks
 * this again server-side, since status can change while a reconciliation is pending).
 */
export default function StockReconciliationRollPickerModal({
  product,          // { id, name, product_code }
  baseUnitSymbol,
  initialPieces,    // rows already on this reconciliation: [{grn_item_piece_id, piece_code, roll_no, old_weight, new_weight, attribute_id, color}]
  onApply,          // (rows: [{grn_item_piece_id, piece_code, roll_no, old_weight, new_weight, attribute_id, color}]) => void
  onClose,
}) {
  const [filter, setFilter] = useState('')
  const [colorFilter, setColorFilter] = useState('')
  const [colorError, setColorError] = useState('')
  const [rows, setRows] = useState(() => new Map((initialPieces ?? []).map((p) => [p.grn_item_piece_id, p])))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sr-available-rolls', product.id],
    queryFn:  () => getAvailableRollsForReconciliation(product.id),
    staleTime: 0,
  })

  const rolls = useMemo(() => {
    const fetched = data ?? []
    const extra = (initialPieces ?? [])
      .filter((p) => !fetched.some((r) => r.id === p.grn_item_piece_id))
      .map((p) => ({ id: p.grn_item_piece_id, piece_code: p.piece_code, roll_no: p.roll_no, weight: p.old_weight, attribute_id: p.attribute_id ?? null, color: p.color ?? '', grn_no: null }))
    return [...extra, ...fetched]
  }, [data, initialPieces])

  // Colour filter is keyed by attribute_id (stable), labelled by name — same pattern
  // as the Sales Order roll picker.
  const colorOptions = useMemo(() => {
    const map = new Map()
    rolls.forEach((r) => {
      if (r.attribute_id != null) map.set(String(r.attribute_id), r.color || `Colour #${r.attribute_id}`)
    })
    return [...map.entries()].map(([id, name]) => ({ id, name }))
  }, [rolls])

  const visible = rolls.filter((r) => {
    if (colorFilter && String(r.attribute_id ?? '') !== colorFilter) return false
    if (!filter.trim()) return true
    return (r.roll_no ?? '').toLowerCase().includes(filter.toLowerCase()) ||
      (r.piece_code ?? '').toLowerCase().includes(filter.toLowerCase())
  })

  const toggle = (roll) => {
    setColorError('')
    setRows((prev) => {
      const next = new Map(prev)
      if (next.has(roll.id)) {
        next.delete(roll.id)
      } else {
        next.set(roll.id, {
          grn_item_piece_id: roll.id,
          piece_code: roll.piece_code,
          roll_no: roll.roll_no,
          old_weight: roll.weight,
          new_weight: roll.weight,
          attribute_id: roll.attribute_id ?? null,
          color: roll.color ?? '',
        })
      }
      return next
    })
  }

  const setNewWeight = (id, value) => {
    setRows((prev) => {
      const next = new Map(prev)
      const row = next.get(id)
      if (row) next.set(id, { ...row, new_weight: value })
      return next
    })
  }

  const selected = [...rows.values()]
  const netVariance = selected.reduce((s, r) => s + ((parseFloat(r.new_weight) || 0) - (parseFloat(r.old_weight) || 0)), 0)

  const handleApply = () => {
    const distinctColors = new Set(selected.map((r) => r.attribute_id ?? null))
    if (distinctColors.size > 1) {
      setColorError('Selected rolls are of more than one colour — correct one colour per reconciliation. Apply this colour first, then raise a separate reconciliation for the other colour.')
      return
    }
    onApply(selected)
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-xl border border-slate-200 bg-white shadow-2xl">

        <div className="flex items-center justify-between rounded-t-xl border-b border-indigo-100 bg-indigo-50 px-4 py-2">
          <div className="flex items-center gap-2">
            <Boxes size={14} className="text-indigo-600" />
            <div>
              <h2 className="text-xs font-bold text-indigo-700">Select Rolls to Correct — {product.name}</h2>
              <p className="text-[10px] text-indigo-400">
                <span className="font-mono">{product.product_code}</span>
                {' '}· Weights are per {baseUnitSymbol || 'the stocking UOM'} — the unit rolls are sealed in at GRN confirm.
              </p>
            </div>
          </div>
          <button type="button" onClick={onClose} className="rounded p-1 text-indigo-400 hover:bg-indigo-100 hover:text-indigo-600 transition-colors">
            <X size={14} />
          </button>
        </div>

        <div className="flex items-center gap-2 border-b border-slate-100 px-4 py-2">
          <div className="flex items-center gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5">
            <Search size={11} className="shrink-0 text-slate-400" />
            <input
              type="text"
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              placeholder="Filter by roll no or piece code…"
              className="w-56 bg-transparent text-xs text-slate-700 outline-none placeholder-slate-400"
            />
          </div>
          {colorOptions.length > 0 && (
            <select
              value={colorFilter}
              onChange={(e) => setColorFilter(e.target.value)}
              className={`rounded border px-1.5 py-0.5 text-xs outline-none transition-all focus:border-indigo-400 cursor-pointer ${colorFilter ? 'border-indigo-300 bg-indigo-50 font-semibold text-indigo-700' : 'border-slate-200 bg-slate-50 text-slate-700'}`}
            >
              <option value="">All colours</option>
              {colorOptions.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          )}
        </div>

        <div className="min-h-32 flex-1 overflow-y-auto">
          {isLoading && (
            <div className="flex items-center justify-center gap-2 py-10 text-xs text-slate-400">
              <RefreshCw size={12} className="animate-spin" /> Loading rolls…
            </div>
          )}
          {isError && <div className="py-10 text-center text-xs text-red-500">Failed to load available rolls.</div>}
          {!isLoading && !isError && rolls.length === 0 && (
            <div className="py-10 text-center text-xs text-slate-400">No in-stock rolls for this product.</div>
          )}
          {!isLoading && !isError && rolls.length > 0 && (
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-slate-50">
                <tr className="border-b border-slate-200 text-left">
                  <th className="w-8 px-3 py-1.5"></th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Roll No</th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Piece Code</th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Colour</th>
                  <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Current Weight</th>
                  <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Corrected Weight</th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">GRN</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {visible.map((roll) => {
                  const row = rows.get(roll.id)
                  const isChecked = Boolean(row)
                  return (
                    <tr key={roll.id} className={`transition-colors ${isChecked ? 'bg-indigo-50/60' : ''}`}>
                      <td className="cursor-pointer px-3 py-1" onClick={() => toggle(roll)}>
                        <span className={`flex h-3.5 w-3.5 items-center justify-center rounded border-2 transition-all ${isChecked ? 'border-indigo-500 bg-indigo-500' : 'border-slate-300 bg-white'}`}>
                          {isChecked && (
                            <svg className="h-2 w-2 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3.5}>
                              <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                          )}
                        </span>
                      </td>
                      <td className="cursor-pointer px-2 py-1 font-medium text-slate-700" onClick={() => toggle(roll)}>{roll.roll_no || '—'}</td>
                      <td className="cursor-pointer px-2 py-1 font-mono text-slate-500" onClick={() => toggle(roll)}>{roll.piece_code}</td>
                      <td className="cursor-pointer px-2 py-1 text-slate-600" onClick={() => toggle(roll)}>{roll.color || <span className="italic text-slate-300">—</span>}</td>
                      <td className="px-2 py-1 text-right tabular-nums text-slate-500">{fmt(roll.weight)}</td>
                      <td className="px-2 py-1 text-right">
                        {isChecked ? (
                          <input
                            type="number" min="0" step="0.0001"
                            value={row.new_weight}
                            onChange={(e) => setNewWeight(roll.id, e.target.value)}
                            className="w-24 rounded border-2 border-indigo-200 bg-white px-1.5 py-0.5 text-right text-xs text-slate-800 outline-none focus:border-indigo-500"
                          />
                        ) : <span className="text-slate-300">—</span>}
                      </td>
                      <td className="px-2 py-1 font-mono text-[10px] text-slate-400">{roll.grn_no || '—'}</td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          )}
        </div>

        {colorError && (
          <div className="border-t border-red-100 bg-red-50 px-4 py-1.5 text-xs font-medium text-red-600">{colorError}</div>
        )}
        <div className="flex items-center justify-between gap-2 rounded-b-xl border-t border-slate-100 bg-slate-50/60 px-4 py-2">
          <div className="flex items-center gap-3 text-xs">
            <span className="text-slate-500">
              Selected: <span className="font-bold text-slate-700">{selected.length} roll{selected.length !== 1 ? 's' : ''}</span>
            </span>
            <span className={`rounded px-2 py-0.5 font-bold tabular-nums ${netVariance === 0 ? 'bg-slate-100 text-slate-500' : netVariance > 0 ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}`}>
              Net variance: {netVariance > 0 ? '+' : ''}{fmt(netVariance)} {baseUnitSymbol}
            </span>
          </div>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={onClose}
              className="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50 active:scale-95"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleApply}
              className="rounded bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-indigo-700 active:scale-95"
            >
              Apply Selection
            </button>
          </div>
        </div>

      </div>
    </div>
  )
}
