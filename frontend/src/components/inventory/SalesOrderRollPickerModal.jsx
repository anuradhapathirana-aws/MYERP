import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Boxes, RefreshCw, Search, Wand2, X } from 'lucide-react'
import { getAvailablePieces } from '../../api/salesOrders'
import Money from '../ui/Money'

/** Weights and quantities only — money goes through <Money />. */
const fmt = (n, d = 2) => Number(n).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d })

/**
 * Roll picker for manual (non-QR) sales order lines.
 * Lists the product's in-stock rolls; selected rolls are allocated to the
 * order exactly like scanned ones. Auto-pick ticks rolls FIFO until the
 * entered target quantity is covered.
 *
 * Every quantity shown here is in the product's STOCKING UOM — the unit roll weights
 * are sealed in at GRN confirm. It is named on screen rather than assumed, because it
 * varies by product (fabric in metres, yarn in kg) and the sales line may well be in a
 * different unit again (yards).
 */
export default function SalesOrderRollPickerModal({
  product,            // { id, name, product_code }
  initialPieces,      // rolls already on this line: [{piece_code, weight, roll_no, grn_unit_price, attribute_id, color}] — pre-checked
  initialAttributeId, // colour chosen on the line — opens the picker pre-filtered to that colour
  disabledCodes,      // roll codes on OTHER lines of this order (greyed out)
  onApply,            // (pieces: [{piece_code, weight, roll_no, grn_unit_price, attribute_id, color}]) => void
  onClose,
}) {
  const [selected, setSelected]         = useState(() => new Set((initialPieces ?? []).map((p) => p.piece_code)))
  const [filter, setFilter]             = useState('')
  const [colorFilter, setColorFilter]   = useState(initialAttributeId != null && initialAttributeId !== '' ? String(initialAttributeId) : '')
  const [target, setTarget]             = useState('')
  const [colorError, setColorError]     = useState('')

  const { data, isLoading, isError } = useQuery({
    queryKey: ['so-available-pieces', product.id],
    queryFn:  () => getAvailablePieces(product.id),
    staleTime: 0,
  })

  // The unit every number in this modal is expressed in.
  const uom = data?.base_uom ?? ''

  const disabled = useMemo(() => new Set(disabledCodes ?? []), [disabledCodes])

  // When editing a saved order, this line's rolls are 'allocated' server-side and
  // absent from the available list — merge them in so they stay visible/pickable.
  const rolls = useMemo(() => {
    const fetched = data?.pieces ?? []
    const extra = (initialPieces ?? [])
      .filter((p) => !fetched.some((r) => r.piece_code === p.piece_code))
      .map((p) => ({ ...p, grn_no: null, store: null, location: null }))
    return [...extra, ...fetched]
  }, [data, initialPieces])

  // Colour filter is keyed by attribute_id (stable), labelled by name
  const colorOptions = useMemo(() => {
    const map = new Map()
    rolls.forEach((p) => {
      if (p.attribute_id != null) map.set(String(p.attribute_id), p.color || `Colour #${p.attribute_id}`)
    })
    return [...map.entries()].map(([id, name]) => ({ id, name }))
  }, [rolls])

  const matchesColor = (p) => !colorFilter || String(p.attribute_id ?? '') === colorFilter

  const visible = rolls.filter((p) => {
    if (!matchesColor(p)) return false
    if (!filter.trim()) return true
    return (p.roll_no ?? '').toLowerCase().includes(filter.toLowerCase()) ||
      p.piece_code.toLowerCase().includes(filter.toLowerCase())
  })

  const selectedRolls  = rolls.filter((p) => selected.has(p.piece_code))
  const selectedWeight = selectedRolls.reduce((s, p) => s + (parseFloat(p.weight) || 0), 0)

  const toggle = (code) => {
    if (disabled.has(code)) return
    setColorError('')
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(code)) next.delete(code)
      else next.add(code)
      return next
    })
  }

  const autoPick = () => {
    const goal = parseFloat(target)
    if (!(goal > 0)) return
    setSelected((prev) => {
      const next = new Set(prev)
      let total = rolls
        .filter((p) => next.has(p.piece_code))
        .reduce((s, p) => s + (parseFloat(p.weight) || 0), 0)
      for (const p of rolls) { // FIFO — list is already oldest-first
        if (total >= goal) break
        if (next.has(p.piece_code) || disabled.has(p.piece_code)) continue
        if (!matchesColor(p)) continue
        next.add(p.piece_code)
        total += parseFloat(p.weight) || 0
      }
      return next
    })
  }

  const distinctPrices  = new Set(selectedRolls.map((p) => Number(p.grn_unit_price ?? 0)))
  const distinctSelling = new Set(selectedRolls.map((p) => Number(p.selling_price ?? 0)))
  const listPrice       = data?.selling_price ?? null // price-list fallback (per product)

  const handleApply = () => {
    const distinctColors = new Set(selectedRolls.map((p) => p.attribute_id ?? null))
    if (distinctColors.size > 1) {
      setColorError('Select rolls of one colour per line — apply this colour first, then pick the other colour on a new line.')
      return
    }
    onApply(selectedRolls.map((p) => ({
      piece_code:     p.piece_code,
      weight:         p.weight,
      roll_no:        p.roll_no ?? '',
      grn_unit_price: p.grn_unit_price,
      selling_price:  p.selling_price ?? null,
      price_source:   p.price_source ?? null,
      attribute_id:   p.attribute_id ?? null,
      color:          p.color ?? '',
    })))
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl border border-slate-200 bg-white shadow-2xl">

        {/* Header */}
        <div className="flex items-center justify-between rounded-t-xl border-b border-indigo-100 bg-indigo-50 px-4 py-2">
          <div className="flex items-center gap-2">
            <Boxes size={14} className="text-indigo-600" />
            <div>
              <h2 className="text-xs font-bold text-indigo-700">Select Rolls — {product.name}</h2>
              <p className="text-[10px] text-indigo-400">
                <span className="font-mono">{product.product_code}</span>
                {data && <> · {data.count} roll{data.count !== 1 ? 's' : ''} in stock · {fmt(data.total_weight)} {uom} total</>}
                {listPrice != null && (
                  <span className="ml-1.5 rounded bg-emerald-100 px-1.5 py-px font-bold text-emerald-700 tabular-nums" title="Product price-list price — rolls from costed shipments show their own costing price per row">
                    List @ <Money value={listPrice} />{uom && `/${uom}`}
                  </span>
                )}
              </p>
              {/* Say the unit once, plainly: every weight and price below is per the
                  product's stocking UOM, which is what the costing prices in. */}
              {uom && (
                <p className="mt-px text-[9px] text-indigo-400/80">
                  Weights and prices are per <span className="font-bold text-indigo-500">{uom}</span> — the stocking UOM.
                  Selling = each roll&rsquo;s confirmed-costing <span className="font-semibold">Selling / Stocking UOM</span> price.
                </p>
              )}
            </div>
          </div>
          <button type="button" onClick={onClose} className="rounded p-1 text-indigo-400 hover:bg-indigo-100 hover:text-indigo-600 transition-colors">
            <X size={14} />
          </button>
        </div>

        {/* Toolbar: filter + auto-pick */}
        <div className="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-2">
          <div className="flex items-center gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5">
            <Search size={11} className="shrink-0 text-slate-400" />
            <input
              type="text"
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
              placeholder="Filter by roll no or piece code…"
              className="w-48 bg-transparent text-xs text-slate-700 outline-none placeholder-slate-400"
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
          <div className="ml-auto flex items-center gap-1.5">
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Auto-pick</span>
            <input
              type="number" min="0" step="0.01"
              placeholder={uom ? `Target ${uom}` : 'Target'}
              value={target}
              onChange={(e) => setTarget(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); autoPick() } }}
              className="block w-24 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white"
            />
            {uom && <span className="text-[10px] font-semibold text-slate-400">{uom}</span>}
            <button
              type="button"
              onClick={autoPick}
              title="Tick the oldest rolls (FIFO) until the target weight is covered"
              className="flex items-center gap-1 rounded border border-indigo-200 bg-indigo-50 px-2 py-0.5 text-[10px] font-bold text-indigo-600 hover:bg-indigo-100 transition-colors"
            >
              <Wand2 size={10} /> Pick
            </button>
          </div>
        </div>

        {/* Roll list */}
        <div className="min-h-32 flex-1 overflow-y-auto">
          {isLoading && (
            <div className="flex items-center justify-center gap-2 py-10 text-xs text-slate-400">
              <RefreshCw size={12} className="animate-spin" /> Loading rolls…
            </div>
          )}
          {isError && <div className="py-10 text-center text-xs text-red-500">Failed to load available rolls.</div>}
          {!isLoading && !isError && rolls.length === 0 && (
            <div className="py-10 text-center text-xs text-slate-400">No rolls in stock for this product.</div>
          )}
          {!isLoading && !isError && rolls.length > 0 && (
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-slate-50">
                <tr className="border-b border-slate-200 text-left">
                  <th className="w-8 px-3 py-1.5"></th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Roll No</th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Piece Code</th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Colour</th>
                  <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">
                    Weight{uom && <span className="ml-0.5 font-semibold normal-case text-indigo-500">({uom})</span>}
                  </th>
                  {/* Both prices are per the stocking UOM — the sales line may quote a
                      different unit, so say which one these are in. */}
                  <th className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">
                    GRN Cost{uom && <span className="ml-0.5 font-semibold normal-case text-indigo-500">(/{uom})</span>}
                  </th>
                  <th
                    className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500"
                    title={`The roll's confirmed-costing "Selling / Stocking UOM" price${uom ? ` — per ${uom}` : ''}. This is what the sales order line is priced at; it is re-expressed if the line sells in another unit.`}
                  >
                    Selling{uom && <span className="ml-0.5 font-semibold normal-case text-indigo-500">(/{uom})</span>}
                  </th>
                  <th
                    className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500"
                    title={`The roll's costing Before-Tax price (VAT excluded)${uom ? ` — per ${uom}` : ''}. Tax invoices bill this price and add the VAT per line; rolls without a confirmed costing have none.`}
                  >
                    Non-Tax{uom && <span className="ml-0.5 font-semibold normal-case text-indigo-500">(/{uom})</span>}
                  </th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">GRN</th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Store / Location</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {visible.map((p) => {
                  const isDisabled = disabled.has(p.piece_code)
                  const isChecked  = selected.has(p.piece_code)
                  return (
                    <tr
                      key={p.piece_code}
                      onClick={() => toggle(p.piece_code)}
                      className={`transition-colors ${isDisabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer hover:bg-indigo-50/40'} ${isChecked ? 'bg-indigo-50/60' : ''}`}
                    >
                      <td className="px-3 py-1">
                        <span className={`flex h-3.5 w-3.5 items-center justify-center rounded border-2 transition-all ${isChecked ? 'border-indigo-500 bg-indigo-500' : 'border-slate-300 bg-white'}`}>
                          {isChecked && (
                            <svg className="h-2 w-2 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3.5}>
                              <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                          )}
                        </span>
                      </td>
                      <td className="px-2 py-1 font-medium text-slate-700">{p.roll_no || '—'}</td>
                      <td className="px-2 py-1 font-mono text-slate-500">{p.piece_code}</td>
                      <td className="px-2 py-1 text-slate-600">{p.color || <span className="italic text-slate-300">—</span>}</td>
                      <td className="px-2 py-1 text-right tabular-nums text-slate-600">{fmt(p.weight, 4)}</td>
                      <td className="px-2 py-1 text-right text-slate-600">{p.grn_unit_price ? <Money value={p.grn_unit_price} /> : '—'}</td>
                      <td className={`px-2 py-1 text-right tabular-nums ${p.selling_price != null && Number(p.grn_unit_price) > Number(p.selling_price) ? 'font-semibold text-amber-600' : 'text-emerald-700'}`}
                          title={p.selling_price != null && Number(p.grn_unit_price) > Number(p.selling_price)
                            ? 'GRN cost is above this roll\'s selling price — selling at this price makes a loss'
                            : p.price_source === 'costing' ? `Confirmed costing — "Selling / Stocking UOM"${uom ? ` (per ${uom})` : ''} for this roll's shipment` : p.price_source === 'price_list' ? 'Price-list fallback — this roll\'s shipment has no confirmed costing' : undefined}>
                        {p.selling_price != null ? <Money value={p.selling_price} /> : '—'}
                        {/* Old stock at old price, new at new — say which shipment set this. */}
                        {p.selling_price != null && p.price_source && (
                          <div className={`text-[9px] font-semibold normal-case ${p.price_source === 'costing' ? 'text-emerald-500' : 'text-slate-400'}`}>
                            {p.price_source === 'costing' ? 'costing' : 'list price'}
                          </div>
                        )}
                      </td>
                      <td
                        className="px-2 py-1 text-right tabular-nums text-sky-700"
                        title={p.before_tax_price != null
                          ? 'Costing Before-Tax price (VAT excluded) — Tax invoices bill this and add VAT per line'
                          : 'No confirmed costing for this roll — no before-tax price'}
                      >
                        {p.before_tax_price != null ? <Money value={p.before_tax_price} /> : <span className="text-slate-300">—</span>}
                      </td>
                      <td className="px-2 py-1 font-mono text-[10px] text-slate-400">{p.grn_no || '—'}</td>
                      <td className="px-2 py-1 text-slate-500">
                        {[p.store, p.location].filter(Boolean).join(' / ') || '—'}
                        {isDisabled && <span className="ml-1 text-[9px] font-semibold text-amber-500">on this order</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          )}
        </div>

        {/* Footer */}
        {colorError && (
          <div className="border-t border-red-100 bg-red-50 px-4 py-1.5 text-xs font-medium text-red-600">{colorError}</div>
        )}
        <div className="flex items-center justify-between gap-2 rounded-b-xl border-t border-slate-100 bg-slate-50/60 px-4 py-2">
          <div className="flex items-center gap-3 text-xs">
            <span className="text-slate-500">
              Selected: <span className="font-bold text-slate-700">{selectedRolls.length} roll{selectedRolls.length !== 1 ? 's' : ''}</span>
            </span>
            <span
              className="rounded bg-indigo-100 px-2 py-0.5 font-bold text-indigo-700 tabular-nums"
              title="Total the selected rolls hold, in the product's stocking UOM. The sales line may sell less than this — the last roll is cut at dispatch."
            >
              {fmt(selectedWeight)} {uom}
            </span>
            {distinctSelling.size > 1 && (
              <span className="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700" title="Old stock sells at its old price, new stock at its new price">
                {distinctSelling.size} selling prices — will split into {distinctSelling.size} lines
              </span>
            )}
            {distinctSelling.size <= 1 && distinctPrices.size > 1 && (
              <span className="rounded bg-slate-100 px-2 py-0.5 text-[10px] font-semibold text-slate-500" title="Cost stays tracked per roll — the line sells at one price">
                {distinctPrices.size} different GRN costs on this line
              </span>
            )}
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
