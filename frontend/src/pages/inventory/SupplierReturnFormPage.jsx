import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, FileText, Layers, PackageOpen, Printer, RefreshCw, Save } from 'lucide-react'
import {
  confirmSupplierReturn,
  createSupplierReturn,
  downloadSupplierReturnPdf,
  getNextSupplierReturnNo,
  getReturnableGrnItems,
  getReturnableGrns,
  getSupplierReturn,
  updateSupplierReturn,
} from '../../api/supplierReturns'
import { getAllSuppliers } from '../../api/suppliers'
import { getAllStores } from '../../api/stores'
import Breadcrumb from '../../components/Breadcrumb'
import Money from '../../components/ui/Money'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import { usePermissions } from '../../hooks/usePermissions'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'
import { INPUT_CLS, INPUT_DISABLED_CLS, LABEL_CLS, inputCls, selectCls } from '../../utils/fieldStyles'

const EMPTY_ARRAY = []

const REASONS = [
  { value: 'damaged',       label: 'Damaged' },
  { value: 'wrong_item',    label: 'Wrong Item' },
  { value: 'excess',        label: 'Excess' },
  { value: 'quality_issue', label: 'Quality Issue' },
  { value: 'other',         label: 'Other' },
]

// Same tolerance the backend allows for a typed (4 dp) quantity.
const QTY_TOLERANCE = 0.00005

const CELL_INPUT_CLS = 'block w-full rounded border-2 border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white disabled:cursor-not-allowed disabled:opacity-50'
const CELL_INPUT_ERR_CLS = CELL_INPUT_CLS.replace('border-slate-200 bg-slate-50', 'border-red-300 bg-red-50/40')
const TH_CLS = 'px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500'
const KBD_CLS = 'ml-1 rounded border border-white/40 bg-white/20 px-1 text-[9px] font-semibold'

function SectionHeader({ icon: Icon, title, colorClass, right }) {
  return (
    <div className={`flex items-center justify-between gap-1.5 px-3 py-2 border-b ${colorClass}`}>
      <div className="flex items-center gap-1.5">
        {Icon && <Icon size={13} />}
        <h2 className="text-xs font-bold">{title}</h2>
      </div>
      {right}
    </div>
  )
}

/** Quantities are not money — up to 4 dp, trailing zeros trimmed. */
function fmtQty(n) {
  return Number(n || 0).toLocaleString(undefined, { maximumFractionDigits: 4 })
}

const round2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100

function lineQty(line) {
  return line.is_scanned
    ? line.pieces.filter((p) => p.checked).reduce((sum, p) => sum + (parseFloat(p.quantity) || 0), 0)
    : parseFloat(line.quantity) || 0
}

/** What the GRN charged for this much of the line — the same pro-rata the backend debits. */
function lineValue(line) {
  return line.received_qty > 0 ? round2((line.line_total * lineQty(line)) / line.received_qty) : 0
}

/** Build editable rows from the returnable lines, re-applying a saved draft's choices. */
function buildLines(items, savedItems) {
  const savedByItem = new Map((savedItems ?? []).map((s) => [s.grn_item_id, s]))

  return items.map((it) => {
    const saved        = savedByItem.get(it.grn_item_id)
    const savedByPiece = new Map((saved?.pieces ?? []).map((p) => [p.piece_id, p]))

    return {
      ...it,
      selected: Boolean(saved),
      quantity: saved && !it.is_scanned ? String(saved.quantity) : '',
      reason:   saved?.reason ?? 'damaged',
      remarks:  saved?.remarks ?? '',
      pieces:   it.pieces.map((p) => {
        const savedPiece = savedByPiece.get(p.piece_id)
        return { ...p, checked: Boolean(savedPiece), quantity: savedPiece ? String(savedPiece.quantity) : '' }
      }),
    }
  })
}

/** A blocking SweetAlert dialog is open — shortcuts must not fire behind it (toasts are fine). */
function alertOpen() {
  const popup = document.querySelector('.swal2-popup')
  return popup && !popup.classList.contains('swal2-toast')
}

export default function SupplierReturnFormPage() {
  const { id }      = useParams()
  const isEdit      = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const { can }     = usePermissions()
  const today       = new Date().toISOString().slice(0, 10)

  // ?grn= deep link (GRN view → "Create Return") pre-selects the GRN and its supplier.
  const [search]   = useSearchParams()
  const grnFromUrl = !isEdit ? search.get('grn') : null

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Supplier Returns', to: '/inventory/supplier-returns' },
    { label: isEdit ? 'Return' : 'New Return' },
  ]

  const [form, setForm] = useState({
    return_date: today,
    supplier_id: '',
    grn_id:      grnFromUrl ?? '',
    store_id:    '',
    remarks:     '',
  })
  const [lines, setLines]     = useState([])
  const [errors, setErrors]   = useState({})
  const [pdfBusy, setPdfBusy] = useState(false)

  const { data: suppliers = EMPTY_ARRAY } = useQuery({ queryKey: ['suppliers-all'], queryFn: getAllSuppliers, staleTime: Infinity })
  const { data: stores = EMPTY_ARRAY }    = useQuery({ queryKey: ['stores-all'], queryFn: getAllStores, staleTime: 5 * 60 * 1000 })

  const { data: nextReturnNo } = useQuery({
    queryKey:  ['supplier-returns-next-no'],
    queryFn:   getNextSupplierReturnNo,
    enabled:   !isEdit,
    staleTime: 0,
  })

  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['supplier-return', id],
    queryFn:  () => getSupplierReturn(id),
    enabled:  isEdit,
  })

  const saved    = existing?.data
  const status   = saved?.status ?? 'draft'
  const isDraft  = !isEdit || status === 'draft'
  const returnNo = isEdit ? (saved?.return_no ?? '') : (nextReturnNo ?? '')

  // Seed the header from a saved return — adjusted while rendering (not in an effect)
  // whenever a fresh copy of it arrives.
  const [seededFrom, setSeededFrom] = useState(null)
  if (saved && seededFrom !== saved) {
    setSeededFrom(saved)
    setForm({
      return_date: saved.return_date ?? today,
      supplier_id: String(saved.supplier_id ?? ''),
      grn_id:      String(saved.grn_id ?? ''),
      store_id:    String(saved.store_id ?? ''),
      remarks:     saved.remarks ?? '',
    })
  }

  const { data: grns = EMPTY_ARRAY } = useQuery({
    queryKey: ['returnable-grns', form.supplier_id],
    queryFn:  () => getReturnableGrns(form.supplier_id),
    enabled:  Boolean(form.supplier_id) && isDraft,
  })

  // Never refetched behind the user's back — a refetch would rebuild the rows and wipe
  // quantities they are typing.
  const { data: returnable, isFetching: loadingItems } = useQuery({
    queryKey: ['returnable-grn-items', form.grn_id],
    queryFn:  () => getReturnableGrnItems(form.grn_id),
    enabled:  Boolean(form.grn_id) && isDraft,
    refetchOnWindowFocus: false,
  })

  // Build the editable rows once the GRN's returnable lines arrive (drafts only),
  // re-applying the saved draft's choices when it is the same GRN.
  const [builtFrom, setBuiltFrom] = useState(null)
  if (returnable && builtFrom !== returnable) {
    const grn = returnable.grn
    setBuiltFrom(returnable)
    setForm((f) => ({
      ...f,
      supplier_id: f.supplier_id || String(grn.supplier_id),
      store_id:    f.store_id || (grn.default_store_id ? String(grn.default_store_id) : ''),
    }))
    setLines(buildLines(returnable.items, String(saved?.grn_id) === String(grn.id) ? saved.items : null))
  }

  const supplierOptions = suppliers.map((s) => ({ value: s.id, label: s.name }))
  const grnOptions      = useMemo(() => {
    const opts = grns.map((g) => ({
      value: g.grn_id,
      label: `${g.grn_no} · ${g.grn_date}${g.reference_no ? ` · ${g.reference_no}` : ''}`,
    }))
    // A draft's GRN may no longer be "returnable" (e.g. returned in full by another
    // confirmed return meanwhile) — keep it selectable so the draft stays reviewable.
    if (form.grn_id && returnable?.grn && !opts.some((o) => String(o.value) === String(form.grn_id))) {
      opts.unshift({ value: returnable.grn.id, label: `${returnable.grn.grn_no} · ${returnable.grn.grn_date}` })
    }
    return opts
  }, [grns, form.grn_id, returnable])

  // ── Row editing ──────────────────────────────────────────────────────────
  const patchLine = (itemId, patch) =>
    setLines((prev) => prev.map((l) => (l.grn_item_id === itemId ? { ...l, ...patch } : l)))

  // Ticking a quantity line proposes everything still returnable; a roll line starts with
  // no roll ticked — the user picks the physical rolls going back.
  const toggleLine = (line) => {
    const selected = !line.selected
    patchLine(line.grn_item_id, {
      selected,
      quantity: selected && !line.is_scanned ? String(line.returnable_qty) : '',
      pieces:   line.pieces.map((p) => ({ ...p, checked: false, quantity: '' })),
    })
  }

  const patchPiece = (line, pieceId, patch) =>
    patchLine(line.grn_item_id, {
      pieces: line.pieces.map((p) => (p.piece_id === pieceId ? { ...p, ...patch } : p)),
    })

  const togglePiece = (line, piece) =>
    patchPiece(line, piece.piece_id, {
      checked:  !piece.checked,
      quantity: !piece.checked ? String(piece.available_qty) : '',
    })

  const selectedLines = lines.filter((l) => l.selected)
  const lineOver      = (l) => lineQty(l) - l.returnable_qty > QTY_TOLERANCE
  const pieceOver     = (p) => (parseFloat(p.quantity) || 0) - p.available_qty > QTY_TOLERANCE

  const totalValue  = round2(selectedLines.reduce((sum, l) => sum + lineValue(l), 0))
  const outstanding = returnable?.grn?.outstanding ?? 0
  const toGrn       = Math.min(totalValue, outstanding)
  const toCredit    = round2(totalValue - toGrn)

  // ── Save / confirm ───────────────────────────────────────────────────────
  const saveMutation = useMutation({
    mutationFn: (payload) => (isEdit ? updateSupplierReturn(id, payload) : createSupplierReturn(payload)),
    onSuccess: (res) => {
      showSuccess(isEdit ? 'Supplier return updated.' : 'Supplier return saved as draft.')
      const savedId = res?.data?.id ?? id
      queryClient.invalidateQueries({ queryKey: ['supplier-returns'] })
      queryClient.invalidateQueries({ queryKey: ['supplier-return', String(savedId)] })
      navigate(`/inventory/supplier-returns/${savedId}/edit`)
    },
    onError: (e) => {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save supplier return.')
    },
  })

  const confirmMutation = useMutation({
    mutationFn: () => confirmSupplierReturn(id),
    onSuccess: () => {
      showSuccess('Return confirmed. Stock sent back and supplier debited.')
      queryClient.invalidateQueries({ queryKey: ['supplier-return', id] })
      queryClient.invalidateQueries({ queryKey: ['supplier-returns'] })
      queryClient.invalidateQueries({ queryKey: ['returnable-grns'] })
      queryClient.invalidateQueries({ queryKey: ['returnable-grn-items'] })
      queryClient.invalidateQueries({ queryKey: ['outstanding-grns'] })
      queryClient.invalidateQueries({ queryKey: ['open-credit-notes'] })
      queryClient.invalidateQueries({ queryKey: ['supplier-credit-notes'] })
      queryClient.invalidateQueries({ queryKey: ['grn'] })
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to confirm return.'),
  })

  const err = (f) => errors[f]?.[0]

  const handleSubmit = () => {
    const clientErrors = {}
    if (!form.return_date) clientErrors.return_date = ['Return date is required.']
    else if (form.return_date > today) clientErrors.return_date = ['The return date cannot be in the future.']
    else if (returnable?.grn?.grn_date && form.return_date < returnable.grn.grn_date) {
      clientErrors.return_date = [`The return date cannot be before the GRN date (${returnable.grn.grn_date}).`]
    }
    if (!form.supplier_id) clientErrors.supplier_id = ['Supplier is required.']
    if (!form.grn_id)      clientErrors.grn_id      = ['GRN is required.']
    if (!form.store_id)    clientErrors.store_id    = ['From store is required.']
    if (selectedLines.length === 0) clientErrors.items = ['Tick at least one item being returned.']
    else if (selectedLines.some((l) => l.is_scanned && !l.pieces.some((p) => p.checked))) {
      clientErrors.items = ['Tick the rolls going back for every ticked roll item.']
    } else if (selectedLines.some((l) => lineQty(l) <= 0 || l.pieces.some((p) => p.checked && !(parseFloat(p.quantity) > 0)))) {
      clientErrors.items = ['Enter the quantity for every ticked item and roll.']
    } else if (selectedLines.some((l) => lineOver(l) || l.pieces.some((p) => p.checked && pieceOver(p)))) {
      clientErrors.items = ['A return quantity is more than what is still returnable or in the roll.']
    }

    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors)
      showError('Please resolve the highlighted issues before saving.')
      return
    }

    setErrors({})
    saveMutation.mutate({
      return_date: form.return_date,
      supplier_id: parseInt(form.supplier_id),
      grn_id:      parseInt(form.grn_id),
      store_id:    parseInt(form.store_id),
      remarks:     form.remarks || null,
      items: selectedLines.map((l) => ({
        grn_item_id: l.grn_item_id,
        quantity:    l.is_scanned ? null : parseFloat(l.quantity),
        reason:      l.reason,
        remarks:     l.remarks || null,
        ...(l.is_scanned && {
          pieces: l.pieces
            .filter((p) => p.checked)
            .map((p) => ({ piece_id: p.piece_id, quantity: parseFloat(p.quantity) })),
        }),
      })),
    })
  }

  const handleConfirm = async () => {
    const ok = await confirmAction({
      title: 'Confirm this return?',
      message: 'The goods will be <strong>taken out of stock</strong> and the supplier debited — against the GRN balance first, any excess as a credit note. This cannot be undone.',
      confirmText: 'Yes, Confirm Return',
    })
    if (ok) confirmMutation.mutate()
  }

  const handlePdf = async (print) => {
    setPdfBusy(true)
    try {
      const blob = await downloadSupplierReturnPdf(id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href     = url
        a.download = `PRN_${returnNo || id}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError('Failed to generate the return note PDF.')
    } finally {
      setPdfBusy(false)
    }
  }

  // ── Keyboard: Ctrl+Enter saves the draft, Alt+C confirms a saved draft ──
  const canSave    = isDraft && can(isEdit ? 'edit_supplier_returns' : 'create_supplier_returns')
  const canConfirm = isEdit && status === 'draft' && can('confirm_supplier_returns')

  const shortcutRef = useRef({})
  useEffect(() => {
    shortcutRef.current = {
      save:    () => { if (canSave && !saveMutation.isPending) handleSubmit() },
      confirm: () => { if (canConfirm && !confirmMutation.isPending) handleConfirm() },
    }
  })
  useEffect(() => {
    const handler = (e) => {
      if (e.repeat || e.isComposing || alertOpen()) return
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey) {
        e.preventDefault()
        shortcutRef.current.save?.()
      } else if (e.altKey && !e.ctrlKey && !e.metaKey && e.key.toLowerCase() === 'c') {
        e.preventDefault()
        shortcutRef.current.confirm?.()
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])

  if (isEdit && loadingExisting) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <RefreshCw size={14} className="animate-spin" /> Loading…
        </div>
      </div>
    )
  }

  const grn = returnable?.grn

  return (
    <div className="w-full pb-16">
      <div className="mb-2 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">
            {!isEdit ? 'New Supplier Return' : isDraft ? 'Edit Supplier Return' : 'Supplier Return'}
          </h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {isEdit && (
          <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-bold ${
            status === 'confirmed' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700'
          }`}>
            {status === 'confirmed' ? 'Confirmed' : 'Draft'}
          </span>
        )}
      </div>

      <div className="space-y-2">
        {/* ── Header ── no overflow-hidden, and stacked above the items card: the
             supplier/GRN dropdown pads must be free to open over the cards below. */}
        <div className="relative z-20 rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader icon={FileText} title="Return Details" colorClass="rounded-t-lg text-indigo-700 bg-indigo-50 border-indigo-100" />
          <div className="grid grid-cols-1 gap-2 p-2.5 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <div>
              <label className={LABEL_CLS}>Return No <span className="ml-1 normal-case font-medium text-indigo-400 text-[10px]">auto</span></label>
              <input readOnly className={INPUT_DISABLED_CLS} placeholder={!isEdit ? 'Generating…' : ''} value={returnNo} />
            </div>
            <div>
              <label className={LABEL_CLS}>Return Date <span className="text-red-500">*</span></label>
              <input
                type="date"
                max={today}
                min={grn?.grn_date}
                className={inputCls(Boolean(err('return_date')))}
                value={form.return_date}
                onChange={(e) => setForm((f) => ({ ...f, return_date: e.target.value }))}
                disabled={!isDraft}
              />
              {err('return_date') && <p className="mt-0.5 text-[10px] text-red-500">{err('return_date')}</p>}
            </div>
            <div>
              <label className={LABEL_CLS}>Supplier <span className="text-red-500">*</span></label>
              {isDraft ? (
                <FilterSearchSelect
                  value={form.supplier_id}
                  onChange={(val) => { setForm((f) => ({ ...f, supplier_id: val, grn_id: '', store_id: '' })); setLines([]); setBuiltFrom(null) }}
                  options={supplierOptions}
                  placeholder="Select supplier…"
                />
              ) : (
                <input readOnly className={INPUT_DISABLED_CLS} value={saved?.supplier?.name ?? ''} />
              )}
              {err('supplier_id') && <p className="mt-0.5 text-[10px] text-red-500">{err('supplier_id')}</p>}
            </div>
            <div>
              <label className={LABEL_CLS}>GRN <span className="text-red-500">*</span></label>
              {isDraft ? (
                <FilterSearchSelect
                  value={form.grn_id}
                  onChange={(val) => { setForm((f) => ({ ...f, grn_id: val, store_id: '' })); setLines([]); setBuiltFrom(null) }}
                  options={grnOptions}
                  placeholder={form.supplier_id ? (grnOptions.length ? 'Select GRN…' : 'No returnable GRNs') : 'Select a supplier first'}
                  wide
                />
              ) : (
                <input readOnly className={INPUT_DISABLED_CLS} value={saved?.grn?.grn_no ?? ''} />
              )}
              {err('grn_id') && <p className="mt-0.5 text-[10px] text-red-500">{err('grn_id')}</p>}
            </div>
            <div>
              <label className={LABEL_CLS}>From Store <span className="text-red-500">*</span></label>
              <select
                className={selectCls(Boolean(err('store_id')))}
                value={form.store_id}
                onChange={(e) => setForm((f) => ({ ...f, store_id: e.target.value }))}
                disabled={!isDraft}
                title="Quantity items leave from this store; rolls leave from the store they sit in"
              >
                <option value="">— Select store —</option>
                {stores.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.store_name}{grn?.default_store_id === s.id ? ' (received)' : ''}
                  </option>
                ))}
              </select>
              {err('store_id') && <p className="mt-0.5 text-[10px] text-red-500">{err('store_id')}</p>}
            </div>
            <div className="md:col-span-2 lg:col-span-2 xl:col-span-3">
              <label className={LABEL_CLS}>Remarks</label>
              <input className={INPUT_CLS} maxLength={1000} value={form.remarks} onChange={(e) => setForm((f) => ({ ...f, remarks: e.target.value }))} disabled={!isDraft} />
            </div>
          </div>

          {isDraft && grn && (
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 px-3 py-1.5 text-[11px] text-slate-500">
              <span>GRN Date: <span className="font-semibold text-slate-700">{grn.grn_date}</span></span>
              {grn.reference_no && <span>Supplier Ref: <span className="font-mono font-semibold text-slate-700">{grn.reference_no}</span></span>}
              <span>GRN Total: <span className="font-semibold text-slate-700"><Money value={grn.total_amount} /></span></span>
              <span>Outstanding: <span className={`font-semibold ${outstanding > 0 ? 'text-amber-600' : 'text-green-600'}`}><Money value={outstanding} /></span></span>
            </div>
          )}
          {!isDraft && saved?.grn && (
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 px-3 py-1.5 text-[11px] text-slate-500">
              <span>GRN: <Link to={`/inventory/goods-received-notes/${saved.grn.id}`} className="font-mono font-semibold text-indigo-600 hover:underline">{saved.grn.grn_no}</Link></span>
              <span>Confirmed: <span className="font-semibold text-slate-700">{saved.confirmed_at}</span>{saved.confirmed_by ? ` by ${saved.confirmed_by}` : ''}</span>
            </div>
          )}
        </div>

        {/* ── Items (draft: editable from the live returnable lines) ── */}
        {isDraft && (
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <SectionHeader
              icon={PackageOpen}
              title="Items Being Returned"
              colorClass="text-rose-700 bg-rose-50 border-rose-100"
              right={selectedLines.length > 0 && <span className="text-[11px] font-semibold">{selectedLines.length} item(s) ticked</span>}
            />

            {!form.grn_id ? (
              <div className="px-4 py-10 text-center text-xs text-slate-400">Select a supplier and a GRN to load its items.</div>
            ) : loadingItems && lines.length === 0 ? (
              <div className="flex items-center justify-center gap-2 py-10 text-xs text-slate-400"><RefreshCw size={12} className="animate-spin" /> Loading items…</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-2 py-1.5"></th>
                      <th className={TH_CLS}>Item</th>
                      <th className={`${TH_CLS} w-20 text-right`}>Received</th>
                      <th className={`${TH_CLS} w-20 text-right`}>Returned</th>
                      <th className={`${TH_CLS} w-20 text-right`}>Returnable</th>
                      <th className={`${TH_CLS} w-24 text-right`}>Cost</th>
                      <th className={`${TH_CLS} w-28`}>Return Qty</th>
                      <th className={`${TH_CLS} w-32`}>Reason</th>
                      <th className={`${TH_CLS} w-48`}>Line Remarks</th>
                      <th className={`${TH_CLS} w-24 text-right`}>Value</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {lines.map((l) => (
                      <ReturnLineRows
                        key={l.grn_item_id}
                        line={l}
                        disabled={l.returnable_qty <= 0 || (l.is_scanned && l.pieces.length === 0)}
                        over={l.selected && lineOver(l)}
                        pieceOver={pieceOver}
                        onToggle={() => toggleLine(l)}
                        onPatch={(patch) => patchLine(l.grn_item_id, patch)}
                        onTogglePiece={(p) => togglePiece(l, p)}
                        onPatchPiece={(p, patch) => patchPiece(l, p.piece_id, patch)}
                      />
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {err('items') && <div className="border-t border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700">{err('items')}</div>}
          </div>
        )}

        {/* ── Items (confirmed: read-only from the saved return) ── */}
        {!isDraft && saved && (
          <ConfirmedReturnItems saved={saved} />
        )}

        {isDraft && (canSave || canConfirm) && (
          <KeyboardShortcuts shortcuts={[
            ...(canSave ? [{ keys: ['Ctrl', 'Enter'], desc: isEdit ? 'Update draft' : 'Save draft' }] : []),
            ...(canConfirm ? [{ keys: ['Alt', 'C'], desc: 'Confirm return' }] : []),
          ]} />
        )}
      </div>

      {/* ── Footer: totals + actions ── */}
      <div className="fixed bottom-0 left-0 right-0 z-10 border-t-2 border-slate-200 bg-white px-4 py-2 shadow-[0_-2px_8px_rgba(0,0,0,0.05)] lg:pl-64">
        <div className="mx-auto flex max-w-full flex-wrap items-center justify-end gap-x-4 gap-y-1">
          {isDraft ? (
            <div className="flex flex-wrap items-center gap-x-4 text-xs text-slate-500">
              <span>Against GRN: <span className="font-semibold text-slate-700"><Money value={toGrn} /></span></span>
              {toCredit > 0 && <span>As credit note: <span className="font-semibold text-sky-600"><Money value={toCredit} /></span></span>}
              <span className="text-sm text-rose-700">Return Value: <span className="text-base font-black"><Money value={totalValue} /></span></span>
            </div>
          ) : saved && (
            <div className="flex flex-wrap items-center gap-x-4 text-xs text-slate-500">
              <span>Deducted from GRN: <span className="font-semibold text-slate-700"><Money value={saved.applied_to_grn} /></span></span>
              {saved.credit_note && (
                <span>Credit note <span className="font-mono font-semibold text-sky-600">{saved.credit_note.credit_note_no}</span>: <span className="font-semibold text-sky-600"><Money value={saved.credit_note.amount} /></span></span>
              )}
              <span className="text-sm text-rose-700">Return Value: <span className="text-base font-black"><Money value={saved.total_amount} /></span></span>
            </div>
          )}

          {isEdit && status === 'confirmed' && (
            <>
              <button
                type="button"
                disabled={pdfBusy}
                onClick={() => handlePdf(true)}
                className="flex items-center gap-1 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50 disabled:opacity-60 active:scale-95"
              >
                <Printer size={12} /> Print
              </button>
              <button
                type="button"
                disabled={pdfBusy}
                onClick={() => handlePdf(false)}
                className="flex items-center gap-1 rounded bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm shadow-indigo-100 transition-all hover:bg-indigo-700 disabled:opacity-60 active:scale-95"
              >
                {pdfBusy ? (<><RefreshCw size={11} className="animate-spin" /> Generating…</>) : (<><Download size={12} /> Download Note</>)}
              </button>
            </>
          )}

          {canSave && (
            <button
              type="button"
              disabled={saveMutation.isPending}
              onClick={handleSubmit}
              className="flex items-center gap-1 rounded bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm shadow-indigo-100 transition-all hover:bg-indigo-700 disabled:opacity-40 active:scale-95"
            >
              {saveMutation.isPending
                ? (<><RefreshCw size={11} className="animate-spin" /> Saving…</>)
                : (<><Save size={11} /> {isEdit ? 'Update' : 'Save Draft'}<kbd className={KBD_CLS}>Ctrl+Enter</kbd></>)}
            </button>
          )}

          {canConfirm && (
            <button
              type="button"
              disabled={confirmMutation.isPending}
              onClick={handleConfirm}
              className="flex items-center gap-1 rounded bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm shadow-emerald-100 transition-all hover:bg-emerald-700 disabled:opacity-40 active:scale-95"
            >
              {confirmMutation.isPending ? 'Confirming…' : (<>Confirm Return<kbd className={KBD_CLS}>Alt+C</kbd></>)}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

/** One GRN line — plus, for a roll line, a sub-row listing the line's rolls still in stock. */
function ReturnLineRows({ line, disabled, over, pieceOver, onToggle, onPatch, onTogglePiece, onPatchPiece }) {
  const l = line

  return (
    <>
      <tr className={`${l.selected ? 'bg-rose-50/40' : ''} ${disabled ? 'opacity-50' : 'hover:bg-slate-50/60'}`}>
        <td className="px-2 py-1 text-center">
          <input
            type="checkbox"
            checked={l.selected}
            onChange={onToggle}
            disabled={disabled}
            className="h-3.5 w-3.5 cursor-pointer rounded border-slate-300 text-rose-600 focus:ring-rose-500 disabled:cursor-not-allowed"
          />
        </td>
        <td className="max-w-[18rem] px-2 py-1">
          <div className="truncate font-medium text-slate-700" title={l.product_name}>
            <span className="font-mono text-slate-400">{l.product_code}</span> {l.product_name}
          </div>
          <div className="flex items-center gap-1.5 text-[10px] text-slate-400">
            {(l.variant_label || l.attribute_name) && <span>{l.variant_label || l.attribute_name}</span>}
            {l.is_scanned && <span className="inline-flex items-center gap-0.5 rounded bg-indigo-50 px-1 font-semibold text-indigo-500"><Layers size={9} /> Rolls</span>}
            {l.is_scanned && l.pieces.length === 0 && l.returnable_qty > 0 && <span className="text-amber-600">No roll left in stock</span>}
          </div>
        </td>
        <td className="px-2 py-1 text-right text-slate-600">{fmtQty(l.received_qty)} <span className="text-[10px] text-slate-400">{l.unit_name}</span></td>
        <td className="px-2 py-1 text-right text-slate-500">{fmtQty(l.returned_qty)}</td>
        <td className="px-2 py-1 text-right font-semibold text-slate-700">{fmtQty(l.returnable_qty)}</td>
        <td className="px-2 py-1 text-right text-slate-600"><Money value={l.unit_price} /></td>
        <td className="px-2 py-1">
          {l.is_scanned ? (
            <span className={`font-semibold ${over ? 'text-red-600' : 'text-slate-700'}`}>{l.selected ? fmtQty(lineQty(l)) : '—'}</span>
          ) : (
            <input
              type="number" min="0" max={l.returnable_qty} step="0.0001"
              className={over ? CELL_INPUT_ERR_CLS : CELL_INPUT_CLS}
              value={l.quantity}
              onChange={(e) => onPatch({ quantity: e.target.value })}
              disabled={!l.selected}
            />
          )}
        </td>
        <td className="px-2 py-1">
          <select className={CELL_INPUT_CLS} value={l.reason} onChange={(e) => onPatch({ reason: e.target.value })} disabled={!l.selected}>
            {REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
          </select>
        </td>
        <td className="px-2 py-1">
          <input className={CELL_INPUT_CLS} maxLength={500} value={l.remarks} onChange={(e) => onPatch({ remarks: e.target.value })} disabled={!l.selected} />
        </td>
        <td className="px-2 py-1 text-right font-semibold text-slate-700">{l.selected ? <Money value={lineValue(l)} /> : <span className="text-slate-300">—</span>}</td>
      </tr>

      {l.is_scanned && l.selected && (
        <tr className="bg-slate-50/60">
          <td></td>
          <td colSpan={9} className="px-2 pb-1.5 pt-0.5">
            <div className="flex flex-wrap gap-1.5">
              {l.pieces.map((p) => {
                const pOver = p.checked && pieceOver(p)
                return (
                  <label
                    key={p.piece_id}
                    title={p.store_name ? `In ${p.store_name}` : undefined}
                    className={`flex cursor-pointer items-center gap-1.5 rounded border px-1.5 py-1 ${p.checked ? 'border-rose-300 bg-white' : 'border-slate-200 bg-slate-50'}`}
                  >
                    <input
                      type="checkbox"
                      checked={p.checked}
                      onChange={() => onTogglePiece(p)}
                      className="h-3 w-3 rounded border-slate-300 text-rose-600 focus:ring-rose-500"
                    />
                    <span className="font-mono text-[10px] text-slate-600">{p.piece_code}</span>
                    <input
                      type="number" min="0" max={p.available_qty} step="0.0001"
                      className={`${pOver ? CELL_INPUT_ERR_CLS : CELL_INPUT_CLS} !w-20`}
                      value={p.quantity}
                      onChange={(e) => onPatchPiece(p, { quantity: e.target.value })}
                      disabled={!p.checked}
                    />
                    <span className="text-[10px] text-slate-400">of {fmtQty(p.available_qty)}</span>
                  </label>
                )
              })}
            </div>
          </td>
        </tr>
      )}
    </>
  )
}

/** Read-only lines of a confirmed return, including the remnant each cut roll left in stock. */
function ConfirmedReturnItems({ saved }) {
  return (
    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <SectionHeader icon={PackageOpen} title="Returned Items" colorClass="text-rose-700 bg-rose-50 border-rose-100" />
      <div className="overflow-x-auto">
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left">
              <th className={`${TH_CLS} w-8`}>#</th>
              <th className={TH_CLS}>Item</th>
              <th className={`${TH_CLS} w-24 text-right`}>Qty</th>
              <th className={`${TH_CLS} w-24 text-right`}>Cost</th>
              <th className={`${TH_CLS} w-28`}>Reason</th>
              <th className={`${TH_CLS} w-36`}>Store</th>
              <th className={`${TH_CLS} w-24 text-right`}>Value</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {(saved.items ?? []).map((it, i) => (
              <tr key={it.id} className="align-top">
                <td className="px-2 py-1 text-slate-400">{i + 1}</td>
                <td className="px-2 py-1">
                  <div className="font-medium text-slate-700"><span className="font-mono text-slate-400">{it.product_code}</span> {it.product_name}</div>
                  {(it.variant_label || it.attribute_name) && <div className="text-[10px] text-slate-400">{it.variant_label || it.attribute_name}</div>}
                  {it.pieces?.length > 0 && (
                    <div className="mt-0.5 flex flex-wrap gap-1">
                      {it.pieces.map((p) => (
                        <span key={p.id} className="rounded bg-slate-100 px-1 font-mono text-[10px] text-slate-600" title="Roll sent back → remnant kept in stock">
                          {p.piece_code} ({fmtQty(p.quantity)}){p.remnant_piece_code ? ` → kept ${p.remnant_piece_code}` : ''}
                        </span>
                      ))}
                    </div>
                  )}
                  {it.remarks && <div className="text-[10px] italic text-slate-400">{it.remarks}</div>}
                </td>
                <td className="px-2 py-1 text-right text-slate-700">{fmtQty(it.quantity)} <span className="text-[10px] text-slate-400">{it.unit_name}</span></td>
                <td className="px-2 py-1 text-right text-slate-600"><Money value={it.unit_price} /></td>
                <td className="px-2 py-1 text-slate-600">{it.reason_label}</td>
                <td className="px-2 py-1 text-slate-600">{it.pieces?.length > 0 ? 'Each roll’s own store' : it.store_name}</td>
                <td className="px-2 py-1 text-right font-semibold text-slate-700"><Money value={it.line_total} /></td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {saved.remarks && <div className="border-t border-slate-100 px-3 py-1.5 text-xs text-slate-500"><span className="font-semibold">Remarks:</span> {saved.remarks}</div>}
    </div>
  )
}
