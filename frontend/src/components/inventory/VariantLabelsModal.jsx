import { useEffect, useState } from 'react'
import { QrCode, X } from 'lucide-react'
import { VARIANT_LABEL_BATCH, downloadGrnVariantLabelsPdf, getGrnVariantLabelLines } from '../../api/goodsReceivedNotes'
import { showError, showSuccess } from '../../utils/alerts'

/**
 * Print QR stickers for the finished goods a GRN received: one label per unit
 * of each variant line by default — the count is editable (0 = skip the line).
 */
export default function VariantLabelsModal({ grnId, grnNo, onClose }) {
  const [lines, setLines]       = useState(null)
  const [counts, setCounts]     = useState({})
  const [printing, setPrinting] = useState(false)
  const [progress, setProgress] = useState('')

  useEffect(() => {
    getGrnVariantLabelLines(grnId)
      .then((rows) => {
        setLines(rows)
        setCounts(Object.fromEntries(rows.map((r) => [r.grn_item_id, String(r.default_count)])))
      })
      .catch(() => { showError('Could not load the GRN variant lines.'); onClose() })
  }, [grnId, onClose])

  useEffect(() => {
    const onKey = (e) => { if (e.key === 'Escape') onClose() }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  const total   = Object.values(counts).reduce((s, v) => s + (parseInt(v, 10) || 0), 0)
  const batches = Math.ceil(total / VARIANT_LABEL_BATCH)

  const print = async () => {
    if (total <= 0) { showError('Enter at least one label to print.'); return }
    setPrinting(true)
    try {
      const payload = Object.fromEntries(Object.entries(counts).map(([k, v]) => [k, parseInt(v, 10) || 0]))
      // Large prints arrive as several files of up to VARIANT_LABEL_BATCH labels each.
      for (let b = 0; b < batches; b++) {
        setProgress(batches > 1 ? `File ${b + 1} of ${batches}…` : '')
        const blob = await downloadGrnVariantLabelsPdf(grnId, payload, b * VARIANT_LABEL_BATCH)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = batches > 1
          ? `GRN_${grnNo || grnId}_Variant_Labels_${b + 1}of${batches}.pdf`
          : `GRN_${grnNo || grnId}_Variant_Labels.pdf`
        a.click()
        URL.revokeObjectURL(url)
      }
      showSuccess(`${total.toLocaleString()} label(s) generated${batches > 1 ? ` in ${batches} files` : ''}.`)
      onClose()
    } catch (err) {
      // Blob error responses carry the JSON message inside the blob
      let message = 'Failed to generate variant labels.'
      try { message = JSON.parse(await err?.response?.data?.text?.())?.message ?? message } catch { /* keep default */ }
      showError(message)
    } finally {
      setPrinting(false)
      setProgress('')
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-xl rounded-lg bg-white shadow-xl">
        <div className="flex items-center justify-between border-b border-teal-100 bg-teal-50 px-3 py-2 text-teal-700">
          <div className="flex items-center gap-1.5">
            <QrCode size={13} />
            <h2 className="text-xs font-bold">Variant Labels — {grnNo}</h2>
          </div>
          <button type="button" onClick={onClose} className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600">
            <X size={14} />
          </button>
        </div>

        <div className="max-h-[60vh] overflow-auto p-2">
          {lines === null ? (
            <p className="py-6 text-center text-xs text-slate-400">Loading…</p>
          ) : (
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-slate-100 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                <tr>
                  <th className="px-2 py-1 text-left">Product / Variant</th>
                  <th className="w-20 px-2 py-1 text-right">Received</th>
                  <th className="w-24 px-2 py-1 text-right">Labels</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {lines.map((l) => (
                  <tr key={l.grn_item_id}>
                    <td className="px-2 py-1">
                      <div className="font-medium text-slate-700">{l.product_name}</div>
                      <div className="text-[10px] text-slate-500">{l.variant_label} · <span className="font-mono">{l.sku}</span></div>
                    </td>
                    <td className="px-2 py-1 text-right text-slate-500">{Number(l.quantity).toLocaleString()}</td>
                    <td className="px-2 py-1">
                      <input
                        type="number"
                        min="0"
                        step="1"
                        value={counts[l.grn_item_id] ?? ''}
                        onChange={(e) => setCounts((c) => ({ ...c, [l.grn_item_id]: e.target.value }))}
                        className="block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-1.5 py-0.5 text-right text-xs outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15"
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <div className="flex items-center justify-between border-t border-slate-100 px-3 py-1.5">
          <span className="text-[10px] text-slate-500">
            Total: <strong>{total.toLocaleString()}</strong> label(s)
            {batches > 1 && <> · prints as <strong>{batches}</strong> PDF files of up to {VARIANT_LABEL_BATCH.toLocaleString()}</>}
          </span>
          <div className="flex gap-1.5">
            <button type="button" onClick={onClose} className="rounded px-3 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
            <button
              type="button"
              onClick={print}
              disabled={printing || lines === null}
              className="flex items-center gap-1 rounded bg-teal-600 px-3 py-1 text-xs font-semibold text-white hover:bg-teal-700 disabled:opacity-60"
            >
              <QrCode size={12} /> {printing ? (progress || 'Generating…') : 'Print Labels'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}
