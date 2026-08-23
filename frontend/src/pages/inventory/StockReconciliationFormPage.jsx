import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { AlertTriangle, Boxes, ClipboardEdit, RefreshCw, Save, Scale, Send, X } from 'lucide-react'
import {
  approveStockReconciliation,
  createStockReconciliation,
  getStockReconciliation,
  rejectStockReconciliation,
  submitStockReconciliation,
  updateStockReconciliation,
} from '../../api/stockReconciliations'
import { getAllProducts, getProduct } from '../../api/products'
import { getAllStores } from '../../api/stores'
import { getAllLocations } from '../../api/locations'
import { getProductStock } from '../../api/stock'
import Breadcrumb from '../../components/Breadcrumb'
import StockReconciliationRollPickerModal from '../../components/inventory/StockReconciliationRollPickerModal'
import { confirmAction, confirmWithReason, showError, showSuccess } from '../../utils/alerts'
import {
  INPUT_CLS, INPUT_ERR_CLS, SELECT_CLS, TEXTAREA_CLS, LABEL_CLS,
} from '../../utils/fieldStyles'

const STATUS_STYLES = {
  draft:             'bg-slate-100 text-slate-600',
  pending_approval:  'bg-amber-100 text-amber-700',
  approved:          'bg-green-100 text-green-700',
  rejected:          'bg-red-100 text-red-700',
}

const fmt = (n, d = 4) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d })

export default function StockReconciliationFormPage() {
  const { id } = useParams()
  const isEdit = Boolean(id)
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const [mode, setMode] = useState('rolls') // 'qty' | 'rolls' — rolls is the only mode currently enabled
  const [pickerOpen, setPickerOpen] = useState(false)
  const [errors, setErrors] = useState({})

  const [form, setForm] = useState({
    product_id: '',
    store_id: '',
    location_id: '',
    unit_id: '',
    counted_qty: '',
    source_type: '',
    source_id: '',
    also_adjust_po: false,
    reason: '',
    remarks: '',
  })
  const [pieces, setPieces] = useState([]) // [{grn_item_piece_id, piece_code, roll_no, old_weight, new_weight}]

  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['stock-reconciliation', id],
    queryFn:  () => getStockReconciliation(id).then((r) => r.data),
    enabled:  isEdit,
  })

  const { data: products } = useQuery({ queryKey: ['all-products'], queryFn: getAllProducts })
  const { data: stores }   = useQuery({ queryKey: ['all-stores'],   queryFn: getAllStores })
  const { data: locations } = useQuery({ queryKey: ['all-locations'], queryFn: () => getAllLocations().then((r) => r.data ?? r) })

  const productSummary = useMemo(
    () => products?.find((p) => p.id === Number(form.product_id)),
    [products, form.product_id],
  )

  // The lightweight "all products" list has no base_unit_symbol — fetch the full
  // record once a product is picked, since every quantity on this page is
  // meaningless without naming the unit it's denominated in.
  const { data: productDetail } = useQuery({
    queryKey: ['product-detail', form.product_id],
    queryFn:  () => getProduct(form.product_id).then((r) => r.data),
    enabled:  Boolean(form.product_id),
  })

  const selectedProduct = productSummary ? { ...productSummary, ...productDetail } : productDetail

  const { data: stockInfo } = useQuery({
    queryKey: ['product-stock', form.product_id, form.store_id],
    queryFn:  () => getProductStock(form.product_id, form.store_id),
    enabled:  Boolean(form.product_id) && Boolean(form.store_id) && !isEdit,
  })

  const systemQtyLive = useMemo(() => {
    if (isEdit) return existing?.system_qty_base ?? null
    if (!stockInfo) return null
    if (!form.location_id) return stockInfo.total_stock
    const row = stockInfo.breakdown?.find((b) => String(b.location_id) === String(form.location_id))
    return row ? Number(row.current_stock) : 0
  }, [isEdit, existing, stockInfo, form.location_id])

  // Load an existing draft/submitted reconciliation into form state
  useEffect(() => {
    if (!existing) return
    setForm({
      product_id: existing.product_id ?? '',
      store_id: existing.store_id ?? '',
      location_id: existing.location_id ?? '',
      unit_id: existing.unit_id ?? '',
      counted_qty: existing.pieces?.length ? '' : existing.counted_qty_base ?? '',
      source_type: existing.source_type ?? '',
      source_id: existing.source_id ?? '',
      also_adjust_po: Boolean(existing.also_adjust_po),
      reason: existing.reason ?? '',
      remarks: existing.remarks ?? '',
    })
    if (existing.pieces?.length) {
      setMode('rolls')
      setPieces(existing.pieces.map((p) => ({
        grn_item_piece_id: p.grn_item_piece_id,
        piece_code: p.piece_code,
        roll_no: p.roll_no,
        old_weight: p.old_weight,
        new_weight: p.new_weight,
        attribute_id: existing.attribute_id ?? null,
        color: p.color ?? existing.attribute?.name ?? '',
      })))
    } else {
      setMode('qty')
    }
  }, [existing])

  const isDraft = !isEdit || existing?.status === 'draft'

  const saveMutation = useMutation({
    mutationFn: (payload) => isEdit ? updateStockReconciliation(id, payload) : createStockReconciliation(payload),
    onSuccess: (res) => {
      showSuccess(isEdit ? 'Reconciliation updated.' : 'Reconciliation created as draft.')
      queryClient.invalidateQueries({ queryKey: ['stock-reconciliations'] })
      if (!isEdit) navigate(`/inventory/stock-reconciliations/${res.data.id}/edit`)
      else queryClient.invalidateQueries({ queryKey: ['stock-reconciliation', id] })
    },
    onError: (err) => {
      const resp = err?.response?.data
      if (resp?.errors) setErrors(resp.errors)
      showError(resp?.message || 'Failed to save reconciliation.')
    },
  })

  const submitMutation = useMutation({
    mutationFn: () => submitStockReconciliation(id),
    onSuccess: () => {
      showSuccess('Submitted for approval.')
      queryClient.invalidateQueries({ queryKey: ['stock-reconciliation', id] })
      queryClient.invalidateQueries({ queryKey: ['stock-reconciliations'] })
    },
    onError: (err) => showError(err?.response?.data?.message || 'Submit failed.'),
  })

  const approveMutation = useMutation({
    mutationFn: () => approveStockReconciliation(id),
    onSuccess: () => {
      showSuccess('Approved — stock updated.')
      queryClient.invalidateQueries({ queryKey: ['stock-reconciliation', id] })
      queryClient.invalidateQueries({ queryKey: ['stock-reconciliations'] })
    },
    onError: (err) => showError(err?.response?.data?.message || 'Approval failed.'),
  })

  const rejectMutation = useMutation({
    mutationFn: (reason) => rejectStockReconciliation(id, reason),
    onSuccess: () => {
      showSuccess('Reconciliation rejected.')
      queryClient.invalidateQueries({ queryKey: ['stock-reconciliation', id] })
      queryClient.invalidateQueries({ queryKey: ['stock-reconciliations'] })
    },
    onError: (err) => showError(err?.response?.data?.message || 'Rejection failed.'),
  })

  const setField = (name, value) => setForm((f) => ({ ...f, [name]: value }))

  const handleSave = () => {
    const payload = {
      product_id: form.product_id || null,
      store_id: form.store_id || null,
      location_id: form.location_id || null,
      unit_id: form.unit_id || null,
      reason: form.reason,
      remarks: form.remarks || null,
      source_type: form.source_type || null,
      source_id: form.source_type ? (form.source_id || null) : null,
      also_adjust_po: form.source_type === 'grn' ? form.also_adjust_po : false,
    }
    if (mode === 'rolls') {
      payload.pieces = pieces.map((p) => ({ grn_item_piece_id: p.grn_item_piece_id, new_weight: p.new_weight }))
    } else {
      payload.counted_qty = form.counted_qty
    }
    setErrors({})
    saveMutation.mutate(payload)
  }

  const handleSubmit = async () => {
    const ok = await confirmAction({
      title: 'Submit for approval?',
      message: 'A user with approval rights will need to review and approve this before it changes stock.',
      confirmText: 'Yes, Submit',
    })
    if (ok) submitMutation.mutate()
  }

  const handleApprove = async () => {
    const drift = existing?.live_system_qty_base != null && existing?.system_qty_base != null
      && Math.abs(Number(existing.live_system_qty_base) - Number(existing.system_qty_base)) > 0.0001
    const ok = await confirmAction({
      title: `Approve ${existing?.reconciliation_no}?`,
      message: drift
        ? `Heads up: the live system quantity (${fmt(existing.live_system_qty_base)}) has moved since this was drafted (was ${fmt(existing.system_qty_base)}). Approving will still post correctly against the current figure. Continue?`
        : 'This will post the correction to the stock ledger and cannot be undone by rejecting afterwards.',
      confirmText: 'Yes, Approve',
    })
    if (ok) approveMutation.mutate()
  }

  const handleReject = async () => {
    const reason = await confirmWithReason({
      title: `Reject ${existing?.reconciliation_no}?`,
      inputLabel: 'Reason for rejection',
      inputPlaceholder: 'Enter rejection reason…',
      confirmText: 'Reject',
    })
    if (reason !== null) rejectMutation.mutate(reason)
  }

  const removePiece = (pieceId) => setPieces((prev) => prev.filter((p) => p.grn_item_piece_id !== pieceId))

  const rollsNetVariance = pieces.reduce((s, p) => s + ((parseFloat(p.new_weight) || 0) - (parseFloat(p.old_weight) || 0)), 0)
  const qtyVariance = form.counted_qty !== '' && systemQtyLive != null
    ? (parseFloat(form.counted_qty) || 0) - Number(systemQtyLive)
    : null

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Stock Reconciliations', to: '/inventory/stock-reconciliations' },
    { label: isEdit ? (existing?.reconciliation_no ?? 'Loading…') : 'New Reconciliation' },
  ]

  if (isEdit && loadingExisting) {
    return <div className="flex items-center justify-center py-20 text-sm text-slate-400"><RefreshCw size={14} className="mr-2 animate-spin" /> Loading…</div>
  }

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">
            {isEdit ? existing?.reconciliation_no : 'New Stock Reconciliation'}
          </h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {isEdit && existing && (
          <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold ${STATUS_STYLES[existing.status] ?? 'bg-slate-100 text-slate-500'}`}>
            {existing.status_label}
          </span>
        )}
      </div>

      {existing?.status === 'rejected' && existing.rejection_reason && (
        <div className="mt-3 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs text-red-700">
          <AlertTriangle size={14} className="mt-px shrink-0" />
          <div><span className="font-bold">Rejected:</span> {existing.rejection_reason}</div>
        </div>
      )}

      <div className="mt-3 grid grid-cols-1 gap-3 lg:grid-cols-2">
        {/* ── Left: Basic Information ───────────────────────────── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
            <ClipboardEdit size={13} />
            <h2 className="text-xs font-bold">Basic Information</h2>
          </div>
          <div className="grid grid-cols-1 gap-3 p-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <label className={LABEL_CLS}>Product *</label>
              <select
                className={errors.product_id ? INPUT_ERR_CLS : SELECT_CLS}
                value={form.product_id}
                disabled={!isDraft}
                onChange={(e) => setField('product_id', e.target.value)}
              >
                <option value="">Select product…</option>
                {(products ?? []).map((p) => (
                  <option key={p.id} value={p.id}>{p.name} ({p.product_code})</option>
                ))}
              </select>
              {errors.product_id && <p className="mt-0.5 text-[10px] text-red-500">{errors.product_id[0]}</p>}
            </div>

            <div>
              <label className={LABEL_CLS}>Store *</label>
              <select
                className={errors.store_id ? INPUT_ERR_CLS : SELECT_CLS}
                value={form.store_id}
                disabled={!isDraft}
                onChange={(e) => setField('store_id', e.target.value)}
              >
                <option value="">Select store…</option>
                {(stores ?? []).map((s) => (
                  <option key={s.id} value={s.id}>{s.store_name}</option>
                ))}
              </select>
              {errors.store_id && <p className="mt-0.5 text-[10px] text-red-500">{errors.store_id[0]}</p>}
            </div>

            <div>
              <label className={LABEL_CLS}>Location *</label>
              <select
                className={errors.location_id ? INPUT_ERR_CLS : SELECT_CLS}
                value={form.location_id}
                disabled={!isDraft}
                onChange={(e) => setField('location_id', e.target.value)}
              >
                <option value="">Select location…</option>
                {(locations ?? []).map((l) => (
                  <option key={l.id} value={l.id}>{l.name ?? l.location_name}</option>
                ))}
              </select>
              {errors.location_id && <p className="mt-0.5 text-[10px] text-red-500">{errors.location_id[0]}</p>}
            </div>

            <div className="sm:col-span-2">
              <label className={LABEL_CLS}>Reason *</label>
              <input
                className={errors.reason ? INPUT_ERR_CLS : INPUT_CLS}
                placeholder="e.g. Roll weighed wrong at receiving (GRN-2026-0042)"
                value={form.reason}
                disabled={!isDraft}
                onChange={(e) => setField('reason', e.target.value)}
              />
              {errors.reason && <p className="mt-0.5 text-[10px] text-red-500">{errors.reason[0]}</p>}
            </div>

            <div className="sm:col-span-2">
              <label className={LABEL_CLS}>Remarks</label>
              <textarea
                rows={2}
                className={TEXTAREA_CLS}
                value={form.remarks}
                disabled={!isDraft}
                onChange={(e) => setField('remarks', e.target.value)}
              />
            </div>

            <div>
              <label className={LABEL_CLS}>Related Document</label>
              <select
                className={SELECT_CLS}
                value={form.source_type}
                disabled={!isDraft}
                onChange={(e) => setField('source_type', e.target.value)}
              >
                <option value="">None</option>
                <option value="grn">GRN</option>
              </select>
            </div>

            {form.source_type === 'grn' && (
              <div>
                <label className={LABEL_CLS}>GRN ID *</label>
                <input
                  type="number" min="1"
                  className={INPUT_CLS}
                  value={form.source_id}
                  disabled={!isDraft}
                  onChange={(e) => setField('source_id', e.target.value)}
                />
              </div>
            )}

            {form.source_type === 'grn' && (
              <div className="flex items-center gap-2 sm:col-span-2">
                <input
                  type="checkbox"
                  id="also_adjust_po"
                  checked={form.also_adjust_po}
                  disabled={!isDraft}
                  onChange={(e) => setField('also_adjust_po', e.target.checked)}
                  className="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"
                />
                <label htmlFor="also_adjust_po" className="text-xs text-slate-600">
                  Also correct the linked Purchase Order's received quantity by the same amount
                </label>
              </div>
            )}
          </div>
        </div>

        {/* ── Right: Correction Detail ──────────────────────────── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
            <Scale size={13} />
            <h2 className="text-xs font-bold">Correction Detail</h2>
          </div>
          <div className="p-3">
            {isDraft && (
              <div className="mb-3 flex rounded-lg border border-slate-200 bg-slate-50 p-0.5 text-xs font-semibold">
                <button
                  type="button"
                  onClick={() => setMode('rolls')}
                  className={`flex-1 rounded-md px-2 py-1 transition-colors ${mode === 'rolls' ? 'bg-white text-indigo-700 shadow-sm' : 'text-slate-500'}`}
                >
                  Correct Specific Roll(s)
                </button>
                <button
                  type="button"
                  disabled
                  title="Coming soon"
                  className="flex flex-1 cursor-not-allowed items-center justify-center gap-1.5 rounded-md px-2 py-1 text-slate-300"
                >
                  Correct Total Quantity
                  <span className="rounded bg-slate-200 px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide text-slate-400">Soon</span>
                </button>
              </div>
            )}

            <div className="mb-3 flex items-center justify-between rounded-md bg-slate-50 px-3 py-2 text-xs">
              <span className="font-semibold text-slate-500">System Qty (live)</span>
              <span className="font-bold tabular-nums text-slate-700">
                {systemQtyLive != null ? fmt(systemQtyLive) : '—'} {selectedProduct?.base_unit_symbol ?? ''}
              </span>
            </div>

            {isEdit && existing?.status === 'pending_approval' && existing.live_system_qty_base != null
              && Math.abs(Number(existing.live_system_qty_base) - Number(existing.system_qty_base)) > 0.0001 && (
              <div className="mb-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-[11px] text-amber-700">
                <AlertTriangle size={13} className="mt-px shrink-0" />
                <span>Stock has moved since this was drafted (was {fmt(existing.system_qty_base)}, now {fmt(existing.live_system_qty_base)}). Approval will still recompute correctly against the current figure.</span>
              </div>
            )}

            {mode === 'qty' ? (
              <div>
                <label className={LABEL_CLS}>Counted / Actual Quantity *</label>
                <input
                  type="number" step="0.0001" min="0"
                  className={errors.counted_qty ? INPUT_ERR_CLS : INPUT_CLS}
                  value={form.counted_qty}
                  disabled={!isDraft}
                  onChange={(e) => setField('counted_qty', e.target.value)}
                />
                {errors.counted_qty && <p className="mt-0.5 text-[10px] text-red-500">{errors.counted_qty[0]}</p>}
                {qtyVariance != null && (
                  <p className={`mt-1 text-xs font-semibold ${qtyVariance === 0 ? 'text-slate-400' : qtyVariance > 0 ? 'text-emerald-600' : 'text-red-600'}`}>
                    Variance: {qtyVariance > 0 ? '+' : ''}{fmt(qtyVariance)} {selectedProduct?.base_unit_symbol ?? ''}
                  </p>
                )}
              </div>
            ) : (
              <div>
                <div className="mb-2 flex items-center justify-between">
                  <span className="text-xs font-semibold text-slate-500">{pieces.length} roll{pieces.length !== 1 ? 's' : ''} selected</span>
                  {isDraft && (
                    <button
                      type="button"
                      onClick={() => setPickerOpen(true)}
                      disabled={!form.product_id}
                      className="flex items-center gap-1 rounded border border-indigo-200 bg-indigo-50 px-2 py-1 text-[11px] font-bold text-indigo-600 hover:bg-indigo-100 disabled:cursor-not-allowed disabled:opacity-40"
                    >
                      <Boxes size={11} /> Select Rolls
                    </button>
                  )}
                </div>
                {pieces.length === 0 ? (
                  <div className="rounded-md border border-dashed border-slate-200 py-6 text-center text-xs text-slate-400">
                    {form.product_id ? 'No rolls selected yet.' : 'Select a product first.'}
                  </div>
                ) : (
                  <table className="w-full text-xs">
                    <thead>
                      <tr className="border-b border-slate-200 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">
                        <th className="py-1">Roll</th>
                        <th className="py-1">Colour</th>
                        <th className="py-1 text-right">Old</th>
                        <th className="py-1 text-right">New</th>
                        {isDraft && <th className="w-6"></th>}
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {pieces.map((p) => (
                        <tr key={p.grn_item_piece_id}>
                          <td className="py-1 font-mono text-slate-600">{p.piece_code || p.roll_no}</td>
                          <td className="py-1 text-slate-600">{p.color || <span className="italic text-slate-300">—</span>}</td>
                          <td className="py-1 text-right tabular-nums text-slate-500">{fmt(p.old_weight)}</td>
                          <td className="py-1 text-right tabular-nums font-semibold text-slate-700">{fmt(p.new_weight)}</td>
                          {isDraft && (
                            <td className="py-1 text-right">
                              <button type="button" onClick={() => removePiece(p.grn_item_piece_id)} className="text-slate-300 hover:text-red-500">
                                <X size={12} />
                              </button>
                            </td>
                          )}
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
                {pieces.length > 0 && (
                  <p className={`mt-2 text-xs font-semibold ${rollsNetVariance === 0 ? 'text-slate-400' : rollsNetVariance > 0 ? 'text-emerald-600' : 'text-red-600'}`}>
                    Net variance: {rollsNetVariance > 0 ? '+' : ''}{fmt(rollsNetVariance)} {selectedProduct?.base_unit_symbol ?? ''}
                  </p>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* ── Footer actions ─────────────────────────────────────── */}
      <div className="mt-3 flex items-center justify-end gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm">
        {isDraft && (
          <button
            type="button"
            onClick={handleSave}
            disabled={saveMutation.isPending}
            className="flex items-center gap-1.5 rounded-lg bg-slate-700 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-slate-800 disabled:opacity-50"
          >
            <Save size={13} /> Save Draft
          </button>
        )}
        {isEdit && existing?.status === 'draft' && (
          <button
            type="button"
            onClick={handleSubmit}
            disabled={submitMutation.isPending}
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-indigo-700 disabled:opacity-50"
          >
            <Send size={13} /> Submit for Approval
          </button>
        )}
        {isEdit && existing?.status === 'pending_approval' && (
          <>
            <button
              type="button"
              onClick={handleReject}
              disabled={rejectMutation.isPending}
              className="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-bold text-red-600 transition-colors hover:bg-red-100 disabled:opacity-50"
            >
              Reject
            </button>
            <button
              type="button"
              onClick={handleApprove}
              disabled={approveMutation.isPending}
              className="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-bold text-white transition-colors hover:bg-green-700 disabled:opacity-50"
            >
              Approve
            </button>
          </>
        )}
      </div>

      {pickerOpen && selectedProduct && (
        <StockReconciliationRollPickerModal
          product={selectedProduct}
          baseUnitSymbol={selectedProduct.base_unit_symbol}
          initialPieces={pieces}
          onApply={(rows) => { setPieces(rows); setPickerOpen(false) }}
          onClose={() => setPickerOpen(false)}
        />
      )}
    </div>
  )
}
