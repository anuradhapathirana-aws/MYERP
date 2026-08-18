import { useEffect, useState } from 'react'
import { AlertTriangle, PackageSearch, Plus, RefreshCw, Trash2, X } from 'lucide-react'
import { getNextBatchNo } from '../../api/batches'

const INPUT = 'block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-1 focus:ring-indigo-500/20'
const SELECT = 'block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white cursor-pointer'
const LABEL = 'block text-[10px] font-semibold uppercase tracking-wider text-slate-500 mb-0.5'

const STATUS_OPTS = [
  { value: 'active',     label: 'Active' },
  { value: 'quarantine', label: 'Quarantine' },
  { value: 'on_hold',    label: 'On Hold' },
]

const STATUS_BADGE = {
  active:     'bg-emerald-50 text-emerald-700 border-emerald-200',
  quarantine: 'bg-amber-50 text-amber-700 border-amber-200',
  on_hold:    'bg-slate-100 text-slate-600 border-slate-300',
}

function emptyBatch() {
  return {
    _key:               Date.now() + Math.random(),
    batch_no:           '',
    quantity:           '',
    mfg_date:           '',
    expiry_date:        '',
    supplier_batch_no:  '',
    status:             'active',
    notes:              '',
  }
}

export default function BatchAssignModal({ item, onApply, onClose }) {
  const [batches,      setBatches]      = useState([])
  const [loadingNos,   setLoadingNos]   = useState({})
  const [error,        setError]        = useState('')

  /* Seed from existing assignments on open */
  useEffect(() => {
    if (item.batch_assignments?.length) {
      setBatches(
        item.batch_assignments.map((a) => ({
          _key:              a.id ?? Date.now() + Math.random(),
          batch_no:          a.batch_no          ?? '',
          quantity:          a.quantity           ?? '',
          mfg_date:          a.mfg_date           ?? '',
          expiry_date:       a.expiry_date        ?? '',
          supplier_batch_no: a.supplier_batch_no  ?? '',
          status:            a.status             ?? 'active',
          notes:             a.notes              ?? '',
        }))
      )
    } else {
      setBatches([emptyBatch()])
    }
  }, [])

  const set = (idx, field, val) =>
    setBatches((prev) => prev.map((b, i) => i === idx ? { ...b, [field]: val } : b))

  const addRow = () => setBatches((prev) => [...prev, emptyBatch()])

  const removeRow = (idx) => setBatches((prev) => prev.filter((_, i) => i !== idx))

  const autoGenBatchNo = async (idx) => {
    setLoadingNos((prev) => ({ ...prev, [idx]: true }))
    try {
      const no = await getNextBatchNo(item.product_id)
      set(idx, 'batch_no', no)
    } catch {
      // silently ignore — user can type manually
    } finally {
      setLoadingNos((prev) => ({ ...prev, [idx]: false }))
    }
  }

  const totalAssigned = batches.reduce((s, b) => s + (parseFloat(b.quantity) || 0), 0)
  const required      = parseFloat(item.quantity_received) || 0
  const remaining     = +(required - totalAssigned).toFixed(4)
  const isBalanced    = Math.abs(remaining) < 0.0001

  const handleApply = () => {
    setError('')

    // Validate all batch_no filled
    const missing = batches.some((b) => !b.batch_no.trim())
    if (missing) { setError('Each batch must have a Batch Number.'); return }

    // Validate qty > 0 per row
    const zeroQty = batches.some((b) => !(parseFloat(b.quantity) > 0))
    if (zeroQty) { setError('Each batch must have a quantity greater than 0.'); return }

    // Validate total = received qty
    if (!isBalanced) {
      setError(`Assigned qty (${totalAssigned.toFixed(4)}) must equal received qty (${required.toFixed(4)}).`)
      return
    }

    onApply(batches.map(({ _key, ...rest }) => rest))
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <div className="w-full max-w-3xl rounded-xl border border-slate-200 bg-white shadow-2xl flex flex-col max-h-[90vh]">

        {/* Header */}
        <div className="flex items-center justify-between gap-3 border-b border-slate-200 bg-indigo-50 px-4 py-2.5 rounded-t-xl">
          <div className="flex items-center gap-2">
            <PackageSearch size={15} className="text-indigo-600" />
            <div>
              <p className="text-xs font-bold text-indigo-800">Assign Batches</p>
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

        {/* Qty summary bar */}
        <div className="flex items-center gap-4 border-b border-slate-100 bg-slate-50 px-4 py-1.5 text-xs">
          <span className="text-slate-500">Required: <span className="font-bold text-slate-700">{required.toLocaleString()}</span></span>
          <span className="text-indigo-600">Assigned: <span className="font-bold">{totalAssigned.toLocaleString()}</span></span>
          <span className={remaining > 0 ? 'text-amber-600' : remaining < 0 ? 'text-red-600' : 'text-emerald-600'}>
            {remaining > 0 ? 'Remaining' : remaining < 0 ? 'Over by' : 'Balanced ✓'}:
            {' '}<span className="font-bold">{Math.abs(remaining).toLocaleString()}</span>
          </span>
        </div>

        {/* Batch rows */}
        <div className="flex-1 overflow-y-auto px-4 py-2 space-y-2">
          {batches.map((b, idx) => (
            <div key={b._key} className="rounded-lg border border-slate-200 bg-white p-2.5 relative">
              <div className="flex items-center justify-between mb-2">
                <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-600">
                  Batch {idx + 1}
                  {b.status && (
                    <span className={`ml-2 inline-flex items-center rounded border px-1.5 py-0.5 text-[10px] font-medium ${STATUS_BADGE[b.status] ?? 'bg-slate-100 text-slate-600 border-slate-200'}`}>
                      {STATUS_OPTS.find((s) => s.value === b.status)?.label ?? b.status}
                    </span>
                  )}
                </span>
                {batches.length > 1 && (
                  <button
                    type="button"
                    onClick={() => removeRow(idx)}
                    className="rounded p-0.5 text-red-400 hover:bg-red-50 hover:text-red-600 transition-colors"
                  >
                    <Trash2 size={12} />
                  </button>
                )}
              </div>

              <div className="grid grid-cols-2 gap-x-3 gap-y-1.5 md:grid-cols-3 lg:grid-cols-4">
                {/* Batch No */}
                <div className="col-span-2 md:col-span-1">
                  <label className={LABEL}>Batch No <span className="text-red-500">*</span></label>
                  <div className="flex gap-1">
                    <input
                      className={INPUT}
                      placeholder="e.g. BAT-202406-0001"
                      value={b.batch_no}
                      onChange={(e) => set(idx, 'batch_no', e.target.value)}
                    />
                    <button
                      type="button"
                      title="Auto-generate"
                      onClick={() => autoGenBatchNo(idx)}
                      disabled={loadingNos[idx]}
                      className="shrink-0 rounded border border-slate-200 bg-slate-100 px-1.5 text-slate-500 hover:bg-indigo-50 hover:text-indigo-600 transition-colors disabled:opacity-50"
                    >
                      <RefreshCw size={11} className={loadingNos[idx] ? 'animate-spin' : ''} />
                    </button>
                  </div>
                </div>

                {/* Quantity */}
                <div>
                  <label className={LABEL}>Quantity <span className="text-red-500">*</span></label>
                  <input
                    type="number" min="0" step="0.0001"
                    className={INPUT}
                    placeholder="0"
                    value={b.quantity}
                    onChange={(e) => set(idx, 'quantity', e.target.value)}
                  />
                </div>

                {/* Status */}
                <div>
                  <label className={LABEL}>Status</label>
                  <select className={SELECT} value={b.status} onChange={(e) => set(idx, 'status', e.target.value)}>
                    {STATUS_OPTS.map((s) => (
                      <option key={s.value} value={s.value}>{s.label}</option>
                    ))}
                  </select>
                </div>

                {/* Supplier Batch No */}
                <div>
                  <label className={LABEL}>Supplier Batch No</label>
                  <input
                    className={INPUT}
                    placeholder="Supplier's lot no."
                    value={b.supplier_batch_no}
                    onChange={(e) => set(idx, 'supplier_batch_no', e.target.value)}
                  />
                </div>

                {/* Mfg Date */}
                <div>
                  <label className={LABEL}>Mfg Date</label>
                  <input
                    type="date"
                    className={INPUT}
                    value={b.mfg_date}
                    onChange={(e) => set(idx, 'mfg_date', e.target.value)}
                  />
                </div>

                {/* Expiry Date */}
                <div>
                  <label className={LABEL}>Expiry Date</label>
                  <input
                    type="date"
                    className={INPUT}
                    value={b.expiry_date}
                    onChange={(e) => set(idx, 'expiry_date', e.target.value)}
                  />
                </div>

                {/* Notes */}
                <div className="col-span-2 md:col-span-2">
                  <label className={LABEL}>Notes</label>
                  <input
                    className={INPUT}
                    placeholder="Optional remarks"
                    value={b.notes}
                    onChange={(e) => set(idx, 'notes', e.target.value)}
                  />
                </div>
              </div>
            </div>
          ))}

          <button
            type="button"
            onClick={addRow}
            className="flex w-full items-center justify-center gap-1.5 rounded-lg border-2 border-dashed border-slate-200 py-2 text-xs font-semibold text-slate-400 hover:border-indigo-300 hover:text-indigo-500 transition-colors"
          >
            <Plus size={12} /> Add Another Batch
          </button>
        </div>

        {/* Error */}
        {error && (
          <div className="mx-4 mb-2 flex items-center gap-1.5 rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs text-red-700">
            <AlertTriangle size={12} />
            {error}
          </div>
        )}

        {/* Footer */}
        <div className="flex items-center justify-between gap-2 border-t border-slate-200 px-4 py-2.5 rounded-b-xl bg-slate-50">
          <span className="text-[10px] text-slate-400">
            {batches.length} batch{batches.length !== 1 ? 'es' : ''} defined
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
              Apply Batches
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
