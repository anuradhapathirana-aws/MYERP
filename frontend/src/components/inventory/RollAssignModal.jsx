import { useEffect, useRef, useState } from 'react'
import { AlertTriangle, Weight, X } from 'lucide-react'
import { confirmAction } from '../../utils/alerts'

const INPUT = 'block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500/20'
const LABEL = 'block whitespace-nowrap text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-0.5'

const SHORTCUTS = [
  { keys: ['Enter'], desc: 'No Of Pieces → Starting Roll No → Roll 1 measure' },
  { keys: ['Enter'], desc: 'Roll No → focus Weight, then Weight → focus next Roll No' },
  { keys: ['Tab'],   desc: 'Standard field-to-field navigation' },
]

/** Preserves numeric width (leading zeros) of the starting roll no, e.g. "0001" + 2 -> "0003" */
function computeAutoRollNo(start, index) {
  const trimmed = String(start ?? '').trim()
  if (!trimmed || !/^\d+$/.test(trimmed)) return ''
  const value = parseInt(trimmed, 10) + index
  return String(value).padStart(trimmed.length, '0')
}

export default function RollAssignModal({ item, unit, conversion = null, onApply, onClose }) {
  // Rolls are measured in the item's own UOM — kg, m, yd, pcs… The balance
  // (Σ roll measures = Qty Received) holds for every unit, not just Weight.
  const unitSymbol   = unit?.symbol ?? ''
  const categoryName = unit?.unit_category_name ?? ''
  const measureLabel = categoryName === 'Weight' ? 'Weight' : categoryName === 'Length' ? 'Length' : 'Qty'
  const withUnit     = (n) => `${Number(n).toLocaleString()}${unitSymbol ? ` ${unitSymbol}` : ''}`

  // Rolls are received in this UOM but stocked in the product's — the QR sticker and
  // every later sale speak the stocking UOM, so show what these rolls become.
  const rebased = conversion && !conversion.same && conversion.factor
    ? { factor: conversion.factor, symbol: conversion.baseSymbol }
    : null

  const [rolls, setRolls]                 = useState([])
  const [noOfPieces, setNoOfPieces]       = useState('')
  const [startingRollNo, setStartingRollNo] = useState('')
  const [error, setError]                 = useState('')
  const cellRefs        = useRef({}) // { [index]: { roll, weight } }
  const noOfPiecesRef   = useRef(null)
  const startingRollRef = useRef(null)

  /* Seed from existing rolls on open — pre-existing entries are treated as manually
     fixed (not auto-renumbered) since they represent already-confirmed data. */
  useEffect(() => {
    if (item.rolls?.length) {
      setRolls(
        item.rolls.map((r) => ({
          _key:    Date.now() + Math.random(),
          roll_no: r.roll_no ?? '',
          weight:  r.weight  ?? '',
          isAuto:  false,
        }))
      )
      setNoOfPieces(String(item.rolls.length))
    }
    // Entry starts here — the modal is reached straight from Enter on Qty Received
    noOfPiecesRef.current?.focus()
    noOfPiecesRef.current?.select()
  }, [])

  const setCellRef = (index, field) => (el) => {
    if (!cellRefs.current[index]) cellRefs.current[index] = {}
    cellRefs.current[index][field] = el
  }
  const focusCell = (index, field) => cellRefs.current[index]?.[field]?.focus()

  const setWeight = (idx, value) =>
    setRolls((prev) => prev.map((r, i) => i === idx ? { ...r, weight: value } : r))

  const setRollNo = (idx, value) =>
    setRolls((prev) => prev.map((r, i) => i === idx ? { ...r, roll_no: value, isAuto: false } : r))

  const handleNoOfPiecesChange = async (rawValue) => {
    const sanitized = rawValue.replace(/[^\d]/g, '')
    const newCount  = sanitized === '' ? 0 : parseInt(sanitized, 10)
    const oldCount  = rolls.length

    if (newCount < oldCount) {
      const removed  = rolls.slice(newCount)
      const hasData  = removed.some((r) => r.weight !== '' || r.roll_no !== '')
      if (hasData) {
        const ok = await confirmAction({
          title:   'Remove Rolls?',
          message: `This will remove ${removed.length} roll${removed.length !== 1 ? 's' : ''} that already ${removed.length !== 1 ? 'have' : 'has'} data entered. Continue?`,
          confirmText: 'Yes, Remove',
          confirmColor: '#ef4444',
          icon: 'warning',
        })
        if (!ok) return // leave noOfPieces input showing its previous (controlled) value
      }
      setRolls((prev) => prev.slice(0, newCount))
    } else if (newCount > oldCount) {
      const additions = []
      for (let i = oldCount; i < newCount; i++) {
        additions.push({
          _key:    Date.now() + Math.random() + i,
          roll_no: computeAutoRollNo(startingRollNo, i),
          weight:  '',
          isAuto:  true,
        })
      }
      setRolls((prev) => [...prev, ...additions])
    }

    setNoOfPieces(sanitized)
  }

  const handleStartingRollNoChange = (rawValue) => {
    const sanitized = rawValue.replace(/[^\d]/g, '')
    setStartingRollNo(sanitized)
    setRolls((prev) => prev.map((r, i) => r.isAuto ? { ...r, roll_no: computeAutoRollNo(sanitized, i) } : r))
  }

  const totalWeight = rolls.reduce((s, r) => s + (parseFloat(r.weight) || 0), 0)
  const required    = parseFloat(item.quantity_received) || 0
  const remaining   = +(required - totalWeight).toFixed(4)
  const isBalanced  = Math.abs(remaining) < 0.0001

  const handleApply = () => {
    setError('')

    if (!rolls.length) { setError('Enter No Of Pieces to generate roll cards.'); return }

    const missingRollNo = rolls.some((r) => !r.roll_no.trim())
    if (missingRollNo) { setError('Each roll must have a Roll No.'); return }

    const zeroWeight = rolls.some((r) => !(parseFloat(r.weight) > 0))
    if (zeroWeight) { setError(`Each roll must have a ${measureLabel.toLowerCase()} greater than 0.`); return }

    if (!isBalanced) {
      setError(`Total roll ${measureLabel.toLowerCase()} (${withUnit(totalWeight.toFixed(4))}) must equal quantity received (${withUnit(required.toFixed(4))}).`)
      return
    }

    onApply(rolls.map(({ _key, isAuto: _isAuto, ...rest }) => rest))
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-4xl rounded-xl border border-slate-200 bg-white shadow-2xl flex flex-col max-h-[90vh]">

        {/* Header */}
        <div className="flex items-center justify-between gap-3 border-b border-slate-200 bg-indigo-50 px-4 py-2.5 rounded-t-xl">
          <div className="flex items-center gap-2">
            <Weight size={15} className="text-indigo-600" />
            <div>
              <p className="text-xs font-bold text-indigo-800">Manage Rolls</p>
              <p className="text-[10px] text-slate-500 truncate max-w-64">
                {item.product_code && <span className="font-mono mr-1">{item.product_code}</span>}
                {item.product_name}
              </p>
            </div>
          </div>
          <button onClick={onClose} className="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-colors">
            <X size={14} />
          </button>
        </div>

        {/* Generator controls */}
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 bg-slate-50 px-4 py-2.5">
          {/* Left: balance summary — always shown, in the item's own UOM */}
          <div className="flex items-center gap-3 text-xs">
            <span className="text-slate-500">Required: <span className="font-bold text-slate-700">{withUnit(required)}</span></span>
            <span className="text-indigo-600">Entered: <span className="font-bold">{withUnit(totalWeight)}</span></span>
            <span className={remaining > 0 ? 'text-amber-600' : remaining < 0 ? 'text-red-600' : 'text-emerald-600'}>
              {remaining > 0 ? 'Remaining' : remaining < 0 ? 'Over by' : 'Balanced ✓'}:
              {' '}<span className="font-bold">{withUnit(Math.abs(remaining))}</span>
            </span>
            {rebased && (
              <span className="rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-600">
                Stocked as {(totalWeight * rebased.factor).toLocaleString(undefined, { maximumFractionDigits: 4 })} {rebased.symbol}
              </span>
            )}
          </div>

          {/* Right: generator inputs */}
          <div className="flex items-center gap-4">
            <div className="flex items-center gap-1.5">
              <label className="whitespace-nowrap text-[10px] font-semibold uppercase tracking-wider text-slate-500">No Of Pieces</label>
              <input
                ref={noOfPiecesRef}
                type="number" min="0" step="1" placeholder="0"
                className={INPUT + ' w-16'}
                value={noOfPieces}
                onChange={(e) => handleNoOfPiecesChange(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') { e.preventDefault(); startingRollRef.current?.focus(); startingRollRef.current?.select() }
                }}
              />
            </div>
            <div className="flex items-center gap-1.5">
              <label className="whitespace-nowrap text-[10px] font-semibold uppercase tracking-wider text-slate-500">Starting Roll No</label>
              <input
                ref={startingRollRef}
                type="number" min="0" step="1" placeholder="1001"
                className={INPUT + ' w-20'}
                value={startingRollNo}
                onChange={(e) => handleStartingRollNoChange(e.target.value)}
                onKeyDown={(e) => {
                  // Roll numbers are auto-filled from here, so the first thing left to type is Roll 1's measure
                  if (e.key === 'Enter') { e.preventDefault(); setTimeout(() => focusCell(0, 'weight'), 0) }
                }}
              />
            </div>
          </div>
        </div>

        {/* Roll cards grid */}
        <div className="flex-1 overflow-y-auto px-4 py-3">
          {rolls.length === 0 ? (
            <div className="flex items-center justify-center rounded-lg border-2 border-dashed border-slate-200 py-10 text-xs text-slate-400">
              Enter No Of Pieces above to generate roll cards.
            </div>
          ) : (
            <div className="grid grid-cols-2 gap-2.5 sm:grid-cols-3 md:grid-cols-4">
              {rolls.map((r, idx) => (
                <div key={r._key} className="rounded-lg border border-slate-200 bg-white p-2">
                  <span className="mb-1.5 block text-[10px] font-bold uppercase tracking-wider text-indigo-600">Roll {idx + 1}</span>

                  <div className="grid grid-cols-2 gap-1.5">
                    <div>
                      <label className={LABEL}>Roll No <span className="text-red-500">*</span></label>
                      <input
                        ref={setCellRef(idx, 'roll')}
                        className={INPUT}
                        placeholder="1001"
                        value={r.roll_no}
                        onChange={(e) => setRollNo(idx, e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') { e.preventDefault(); focusCell(idx, 'weight') }
                        }}
                      />
                    </div>

                    <div>
                      <label className={LABEL}>{measureLabel}{unitSymbol && ` (${unitSymbol})`} <span className="text-red-500">*</span></label>
                      <input
                        ref={setCellRef(idx, 'weight')}
                        type="number" min="0" step="0.0001"
                        className={INPUT}
                        placeholder="0"
                        value={r.weight}
                        onChange={(e) => setWeight(idx, e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') { e.preventDefault(); focusCell(idx + 1, 'roll') }
                        }}
                      />
                      {rebased && parseFloat(r.weight) > 0 && (
                        <span className="mt-0.5 block text-[10px] font-medium text-indigo-500">
                          = {(parseFloat(r.weight) * rebased.factor).toLocaleString(undefined, { maximumFractionDigits: 4 })} {rebased.symbol}
                        </span>
                      )}
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Error */}
        {error && (
          <div className="mx-4 mb-2 flex items-center gap-1.5 rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs text-red-700">
            <AlertTriangle size={12} />
            {error}
          </div>
        )}

        {/* Keyboard shortcuts */}
        <div className="mx-4 mb-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
          <p className="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Keyboard Shortcuts</p>
          <div className="flex flex-wrap gap-x-5 gap-y-1">
            {SHORTCUTS.map(({ keys, desc }) => (
              <div key={desc} className="flex items-center gap-1.5">
                <div className="flex items-center gap-0.5">
                  {keys.map((k, i) => (
                    <span key={i} className="inline-flex items-center justify-center rounded border border-slate-300 bg-white px-1 py-px text-[10px] font-mono font-semibold text-slate-600 shadow-sm">
                      {k}
                    </span>
                  ))}
                </div>
                <span className="text-[10px] text-slate-500">{desc}</span>
              </div>
            ))}
          </div>
        </div>

        {/* Footer */}
        <div className="flex items-center justify-between gap-2 border-t border-slate-200 px-4 py-2.5 rounded-b-xl bg-slate-50">
          <span className="text-[10px] text-slate-400">
            {rolls.length} roll{rolls.length !== 1 ? 's' : ''} defined
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              onClick={onClose}
              className="rounded border border-slate-200 px-3 py-1 text-xs font-semibold text-slate-600 hover:bg-slate-100 transition-colors"
            >
              Cancel
            </button>
            <button
              type="button"
              onClick={handleApply}
              className={`rounded px-4 py-1 text-xs font-semibold text-white transition-colors ${isBalanced ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-slate-400 cursor-not-allowed'}`}
            >
              Apply Rolls
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
