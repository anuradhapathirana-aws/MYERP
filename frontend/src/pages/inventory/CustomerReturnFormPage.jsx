import { useMemo, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, FileText, Layers, PackageOpen, Printer, RefreshCw, Save } from 'lucide-react'
import {
  confirmCustomerReturn,
  createCustomerReturn,
  downloadCustomerReturnPdf,
  getCustomerReturn,
  getNextReturnNo,
  getReturnableInvoices,
  getReturnableItems,
  updateCustomerReturn,
} from '../../api/customerReturns'
import { getAllCustomers } from '../../api/customers'
import { getAllStores } from '../../api/stores'
import Breadcrumb from '../../components/Breadcrumb'
import Money from '../../components/ui/Money'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { usePermissions } from '../../hooks/usePermissions'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'
import { INPUT_CLS, INPUT_DISABLED_CLS, LABEL_CLS, selectCls } from '../../utils/fieldStyles'

const EMPTY_ARRAY = []

const REASONS = [
  { value: 'damaged',       label: 'Damaged' },
  { value: 'wrong_item',    label: 'Wrong Item' },
  { value: 'excess',        label: 'Excess' },
  { value: 'quality_issue', label: 'Quality Issue' },
  { value: 'other',         label: 'Other' },
]

const CONDITIONS = [
  { value: 'good',    label: 'Good' },
  { value: 'damaged', label: 'Damaged' },
]

// Same tolerance the backend allows for a typed (4 dp) quantity.
const QTY_TOLERANCE = 0.00005

const CELL_INPUT_CLS = 'block w-full rounded border-2 border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white disabled:cursor-not-allowed disabled:opacity-50'
const CELL_INPUT_ERR_CLS = CELL_INPUT_CLS.replace('border-slate-200 bg-slate-50', 'border-red-300 bg-red-50/40')
const TH_CLS = 'px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500'

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

/** What the invoice charged for this much of the line — the same pro-rata the backend credits. */
function lineValue(line) {
  return line.invoiced_qty > 0 ? round2((line.line_total * lineQty(line)) / line.invoiced_qty) : 0
}

/** Build editable rows from the returnable lines, re-applying a saved draft's choices. */
function buildLines(items, savedItems) {
  const savedByItem = new Map((savedItems ?? []).map((s) => [s.invoice_item_id, s]))

  return items.map((it) => {
    const saved        = savedByItem.get(it.invoice_item_id)
    const savedByPiece = new Map((saved?.pieces ?? []).map((p) => [p.do_piece_id, p]))

    return {
      ...it,
      selected:  Boolean(saved),
      quantity:  saved && !it.is_scanned ? String(saved.quantity) : '',
      reason:    saved?.reason ?? 'damaged',
      condition: saved?.condition ?? 'good',
      store_id:  saved?.store_id ? String(saved.store_id) : '',
      remarks:   saved?.remarks ?? '',
      pieces:    it.pieces.map((p) => {
        const savedPiece = savedByPiece.get(p.do_piece_id)
        return { ...p, checked: Boolean(savedPiece), quantity: savedPiece ? String(savedPiece.quantity) : '' }
      }),
    }
  })
}

export default function CustomerReturnFormPage() {
  const { id }      = useParams()
  const isEdit      = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const { can }     = usePermissions()
  const today       = new Date().toISOString().slice(0, 10)

  // ?invoice= deep link (Invoice View → "Create Return") pre-selects the invoice and its customer.
  const [search]       = useSearchParams()
  const invoiceFromUrl = !isEdit ? search.get('invoice') : null

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Customer Returns', to: '/inventory/customer-returns' },
    { label: isEdit ? 'Return' : 'New Return' },
  ]

  const [form, setForm] = useState({
    return_date: today,
    customer_id: '',
    invoice_id:  invoiceFromUrl ?? '',
    store_id:    '',
    remarks:     '',
  })
  const [lines, setLines]     = useState([])
  const [errors, setErrors]   = useState({})
  const [pdfBusy, setPdfBusy] = useState(false)

  const { data: customers = EMPTY_ARRAY } = useQuery({ queryKey: ['customers-all'], queryFn: getAllCustomers, staleTime: Infinity })
  const { data: stores = EMPTY_ARRAY }    = useQuery({ queryKey: ['stores-all'], queryFn: getAllStores, staleTime: 5 * 60 * 1000 })

  const { data: nextReturnNo } = useQuery({
    queryKey:  ['customer-returns-next-no'],
    queryFn:   getNextReturnNo,
    enabled:   !isEdit,
    staleTime: 0,
  })

  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['customer-return', id],
    queryFn:  () => getCustomerReturn(id),
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
      customer_id: String(saved.customer_id ?? ''),
      invoice_id:  String(saved.invoice_id ?? ''),
      store_id:    String(saved.store_id ?? ''),
      remarks:     saved.remarks ?? '',
    })
  }

  const { data: invoices = EMPTY_ARRAY } = useQuery({
    queryKey: ['returnable-invoices', form.customer_id],
    queryFn:  () => getReturnableInvoices(form.customer_id),
    enabled:  Boolean(form.customer_id) && isDraft,
  })

  // Never refetched behind the user's back — a refetch would rebuild the rows and wipe
  // quantities they are typing.
  const { data: returnable, isFetching: loadingItems } = useQuery({
    queryKey: ['returnable-items', form.invoice_id],
    queryFn:  () => getReturnableItems(form.invoice_id),
    enabled:  Boolean(form.invoice_id) && isDraft,
    refetchOnWindowFocus: false,
  })

  // Build the editable rows once the invoice's returnable lines arrive (drafts only),
  // re-applying the saved draft's choices when it is the same invoice.
  const [builtFrom, setBuiltFrom] = useState(null)
  if (returnable && builtFrom !== returnable) {
    const inv = returnable.invoice
    setBuiltFrom(returnable)
    setForm((f) => ({
      ...f,
      customer_id: f.customer_id || String(inv.customer_id),
      store_id:    f.store_id || (inv.default_store_id ? String(inv.default_store_id) : ''),
    }))
    setLines(buildLines(returnable.items, String(saved?.invoice_id) === String(inv.id) ? saved.items : null))
  }

  const customerOptions = customers.map((c) => ({ value: c.id, label: c.name }))
  const invoiceOptions  = useMemo(() => {
    const opts = invoices.map((inv) => ({
      value: inv.invoice_id,
      label: `${inv.invoice_no} · ${inv.invoice_date} · ${inv.status_label}`,
    }))
    // A draft's invoice may no longer be "returnable" (e.g. returned in full by another
    // confirmed return meanwhile) — keep it selectable so the draft stays reviewable.
    if (form.invoice_id && returnable?.invoice && !opts.some((o) => String(o.value) === String(form.invoice_id))) {
      opts.unshift({ value: returnable.invoice.id, label: `${returnable.invoice.invoice_no} · ${returnable.invoice.invoice_date}` })
    }
    return opts
  }, [invoices, form.invoice_id, returnable])

  const storeName = (storeId) => stores.find((s) => String(s.id) === String(storeId))?.store_name

  // ── Row editing ──────────────────────────────────────────────────────────
  const patchLine = (itemId, patch) =>
    setLines((prev) => prev.map((l) => (l.invoice_item_id === itemId ? { ...l, ...patch } : l)))

  // Ticking a line proposes returning everything still returnable; the user reduces it.
  const toggleLine = (line) => {
    const selected = !line.selected
    patchLine(line.invoice_item_id, {
      selected,
      quantity: selected && !line.is_scanned ? String(line.returnable_qty) : '',
      pieces:   line.pieces.map((p) => ({
        ...p,
        checked:  selected && p.returnable_qty > 0,
        quantity: selected && p.returnable_qty > 0 ? String(p.returnable_qty) : '',
      })),
    })
  }

  const patchPiece = (line, doPieceId, patch) =>
    patchLine(line.invoice_item_id, {
      pieces: line.pieces.map((p) => (p.do_piece_id === doPieceId ? { ...p, ...patch } : p)),
    })

  const togglePiece = (line, piece) =>
    patchPiece(line, piece.do_piece_id, {
      checked:  !piece.checked,
      quantity: !piece.checked ? String(piece.returnable_qty) : '',
    })

  const selectedLines = lines.filter((l) => l.selected)
  const lineOver      = (l) => lineQty(l) - l.returnable_qty > QTY_TOLERANCE
  const pieceOver     = (p) => (parseFloat(p.quantity) || 0) - p.returnable_qty > QTY_TOLERANCE

  const totalValue  = round2(selectedLines.reduce((sum, l) => sum + lineValue(l), 0))
  const outstanding = returnable?.invoice?.outstanding ?? 0
  const toInvoice   = Math.min(totalValue, outstanding)
  const toCredit    = round2(totalValue - toInvoice)

  // ── Save / confirm ───────────────────────────────────────────────────────
  const saveMutation = useMutation({
    mutationFn: (payload) => (isEdit ? updateCustomerReturn(id, payload) : createCustomerReturn(payload)),
    onSuccess: (res) => {
      showSuccess(isEdit ? 'Customer return updated.' : 'Customer return saved as draft.')
      const savedId = res?.data?.id ?? id
      queryClient.invalidateQueries({ queryKey: ['customer-returns'] })
      queryClient.invalidateQueries({ queryKey: ['customer-return', String(savedId)] })
      navigate(`/inventory/customer-returns/${savedId}/edit`)
    },
    onError: (e) => {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save customer return.')
    },
  })

  const confirmMutation = useMutation({
    mutationFn: () => confirmCustomerReturn(id),
    onSuccess: () => {
      showSuccess('Return confirmed. Stock received back and customer credited.')
      queryClient.invalidateQueries({ queryKey: ['customer-return', id] })
      queryClient.invalidateQueries({ queryKey: ['customer-returns'] })
      queryClient.invalidateQueries({ queryKey: ['returnable-invoices'] })
      queryClient.invalidateQueries({ queryKey: ['returnable-items'] })
      queryClient.invalidateQueries({ queryKey: ['outstanding-invoices'] })
      queryClient.invalidateQueries({ queryKey: ['open-customer-credit-notes'] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to confirm return.'),
  })

  const err = (f) => errors[f]?.[0]

  const handleSubmit = () => {
    const clientErrors = {}
    if (!form.customer_id) clientErrors.customer_id = ['Customer is required.']
    if (!form.invoice_id)  clientErrors.invoice_id  = ['Invoice is required.']
    if (!form.store_id)    clientErrors.store_id    = ['Return store is required.']
    if (selectedLines.length === 0) clientErrors.items = ['Tick at least one item being returned.']
    if (selectedLines.some((l) => lineQty(l) <= 0)) clientErrors.items = ['Enter the quantity for every ticked item.']
    if (selectedLines.some((l) => lineOver(l) || l.pieces.some((p) => p.checked && pieceOver(p)))) {
      clientErrors.items = ['A return quantity is more than what is still returnable.']
    }

    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors)
      showError('Please resolve the highlighted issues before saving.')
      return
    }

    setErrors({})
    saveMutation.mutate({
      return_date: form.return_date,
      customer_id: parseInt(form.customer_id),
      invoice_id:  parseInt(form.invoice_id),
      store_id:    parseInt(form.store_id),
      remarks:     form.remarks || null,
      items: selectedLines.map((l) => ({
        invoice_item_id: l.invoice_item_id,
        quantity:        l.is_scanned ? null : parseFloat(l.quantity),
        reason:          l.reason,
        condition:       l.condition,
        store_id:        l.store_id ? parseInt(l.store_id) : null,
        remarks:         l.remarks || null,
        ...(l.is_scanned && {
          pieces: l.pieces
            .filter((p) => p.checked && parseFloat(p.quantity) > 0)
            .map((p) => ({ do_piece_id: p.do_piece_id, quantity: parseFloat(p.quantity) })),
        }),
      })),
    })
  }

  const handleConfirm = async () => {
    const ok = await confirmAction({
      title: 'Confirm this return?',
      message: 'The goods will be <strong>received back into stock</strong> and the customer credited — against the invoice balance first, any excess as a credit note. This cannot be undone.',
      confirmText: 'Yes, Confirm Return',
    })
    if (ok) confirmMutation.mutate()
  }

  const handlePdf = async (print) => {
    setPdfBusy(true)
    try {
      const blob = await downloadCustomerReturnPdf(id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href     = url
        a.download = `SRN_${returnNo || id}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError('Failed to generate the return note PDF.')
    } finally {
      setPdfBusy(false)
    }
  }

  if (isEdit && loadingExisting) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <RefreshCw size={14} className="animate-spin" /> Loading…
        </div>
      </div>
    )
  }

  const inv   = returnable?.invoice

  return (
    <div className="w-full pb-16">
      <div className="mb-2 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">
            {!isEdit ? 'New Customer Return' : isDraft ? 'Edit Customer Return' : 'Customer Return'}
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
             customer/invoice dropdown pads must be free to open over the cards below. */}
        <div className="relative z-20 rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader icon={FileText} title="Return Details" colorClass="rounded-t-lg text-indigo-700 bg-indigo-50 border-indigo-100" />
          <div className="grid grid-cols-1 gap-2 p-2.5 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <div>
              <label className={LABEL_CLS}>Return No <span className="ml-1 normal-case font-medium text-indigo-400 text-[10px]">auto</span></label>
              <input readOnly className={INPUT_DISABLED_CLS} placeholder={!isEdit ? 'Generating…' : ''} value={returnNo} />
            </div>
            <div>
              <label className={LABEL_CLS}>Return Date <span className="text-red-500">*</span></label>
              <input type="date" className={INPUT_CLS} value={form.return_date} onChange={(e) => setForm((f) => ({ ...f, return_date: e.target.value }))} disabled={!isDraft} />
            </div>
            <div>
              <label className={LABEL_CLS}>Customer <span className="text-red-500">*</span></label>
              {isDraft ? (
                <FilterSearchSelect
                  value={form.customer_id}
                  onChange={(val) => { setForm((f) => ({ ...f, customer_id: val, invoice_id: '', store_id: '' })); setLines([]); setBuiltFrom(null) }}
                  options={customerOptions}
                  placeholder="Select customer…"
                />
              ) : (
                <input readOnly className={INPUT_DISABLED_CLS} value={saved?.customer?.name ?? ''} />
              )}
              {err('customer_id') && <p className="mt-0.5 text-[10px] text-red-500">{err('customer_id')}</p>}
            </div>
            <div>
              <label className={LABEL_CLS}>Invoice <span className="text-red-500">*</span></label>
              {isDraft ? (
                <FilterSearchSelect
                  value={form.invoice_id}
                  onChange={(val) => { setForm((f) => ({ ...f, invoice_id: val, store_id: '' })); setLines([]); setBuiltFrom(null) }}
                  options={invoiceOptions}
                  placeholder={form.customer_id ? (invoiceOptions.length ? 'Select invoice…' : 'No returnable invoices') : 'Select a customer first'}
                  wide
                />
              ) : (
                <input readOnly className={INPUT_DISABLED_CLS} value={saved?.invoice?.invoice_no ?? ''} />
              )}
              {err('invoice_id') && <p className="mt-0.5 text-[10px] text-red-500">{err('invoice_id')}</p>}
            </div>
            <div>
              <label className={LABEL_CLS}>Return Store <span className="text-red-500">*</span></label>
              <select
                className={selectCls(Boolean(err('store_id')))}
                value={form.store_id}
                onChange={(e) => setForm((f) => ({ ...f, store_id: e.target.value }))}
                disabled={!isDraft}
              >
                <option value="">— Select store —</option>
                {stores.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.store_name}{inv?.default_store_id === s.id ? ' (original)' : ''}
                  </option>
                ))}
              </select>
              {err('store_id') && <p className="mt-0.5 text-[10px] text-red-500">{err('store_id')}</p>}
            </div>
            <div className="md:col-span-2 lg:col-span-2 xl:col-span-3">
              <label className={LABEL_CLS}>Remarks</label>
              <input className={INPUT_CLS} value={form.remarks} onChange={(e) => setForm((f) => ({ ...f, remarks: e.target.value }))} disabled={!isDraft} />
            </div>
          </div>

          {isDraft && inv && (
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-slate-100 px-3 py-1.5 text-[11px] text-slate-500">
              <span>DO: <span className="font-mono font-semibold text-slate-700">{inv.do_no ?? '—'}</span></span>
              <span>Invoice Total: <span className="font-semibold text-slate-700"><Money value={inv.grand_total} /></span></span>
              <span>Outstanding: <span className={`font-semibold ${outstanding > 0 ? 'text-amber-600' : 'text-green-600'}`}><Money value={outstanding} /></span></span>
              <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${inv.status === 'paid' ? 'bg-green-100 text-green-700' : 'bg-sky-100 text-sky-700'}`}>{inv.status_label}</span>
            </div>
          )}
        </div>

        {/* ── Items (draft: editable from the live returnable lines) ── */}
        {isDraft && (
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <SectionHeader
              icon={PackageOpen}
              title="Items Being Returned"
              colorClass="text-emerald-700 bg-emerald-50 border-emerald-100"
              right={selectedLines.length > 0 && <span className="text-[11px] font-semibold">{selectedLines.length} item(s) ticked</span>}
            />

            {!form.invoice_id ? (
              <div className="px-4 py-10 text-center text-xs text-slate-400">Select a customer and an invoice to load its items.</div>
            ) : loadingItems && lines.length === 0 ? (
              <div className="flex items-center justify-center gap-2 py-10 text-xs text-slate-400"><RefreshCw size={12} className="animate-spin" /> Loading items…</div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-2 py-1.5"></th>
                      <th className={TH_CLS}>Item</th>
                      <th className={`${TH_CLS} w-20 text-right`}>Invoiced</th>
                      <th className={`${TH_CLS} w-20 text-right`}>Returned</th>
                      <th className={`${TH_CLS} w-20 text-right`}>Returnable</th>
                      <th className={`${TH_CLS} w-28`}>Return Qty</th>
                      <th className={`${TH_CLS} w-32`}>Reason</th>
                      <th className={`${TH_CLS} w-24`}>Condition</th>
                      <th className={`${TH_CLS} w-36`}>Store</th>
                      <th className={`${TH_CLS} w-24 text-right`}>Value</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {lines.map((l) => {
                      const nothingLeft = l.returnable_qty <= 0
                      return (
                        <ReturnLineRows
                          key={l.invoice_item_id}
                          line={l}
                          disabled={nothingLeft}
                          stores={stores}
                          headerStoreName={storeName(form.store_id)}
                          over={l.selected && lineOver(l)}
                          pieceOver={pieceOver}
                          onToggle={() => toggleLine(l)}
                          onPatch={(patch) => patchLine(l.invoice_item_id, patch)}
                          onTogglePiece={(p) => togglePiece(l, p)}
                          onPatchPiece={(p, patch) => patchPiece(l, p.do_piece_id, patch)}
                        />
                      )
                    })}
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
      </div>

      {/* ── Footer: totals + actions ── */}
      <div className="fixed bottom-0 left-0 right-0 z-10 border-t-2 border-slate-200 bg-white px-4 py-2 shadow-[0_-2px_8px_rgba(0,0,0,0.05)] lg:pl-64">
        <div className="mx-auto flex max-w-full flex-wrap items-center justify-end gap-x-4 gap-y-1">
          {isDraft ? (
            <div className="flex flex-wrap items-center gap-x-4 text-xs text-slate-500">
              <span>To invoice: <span className="font-semibold text-slate-700"><Money value={toInvoice} /></span></span>
              {toCredit > 0 && <span>As credit note: <span className="font-semibold text-sky-600"><Money value={toCredit} /></span></span>}
              <span className="text-sm text-emerald-700">Return Value: <span className="text-base font-black"><Money value={totalValue} /></span></span>
            </div>
          ) : saved && (
            <div className="flex flex-wrap items-center gap-x-4 text-xs text-slate-500">
              <span>Credited to invoice: <span className="font-semibold text-slate-700"><Money value={saved.applied_to_invoice} /></span></span>
              {saved.credit_note && (
                <span>Credit note <span className="font-mono font-semibold text-sky-600">{saved.credit_note.credit_note_no}</span>: <span className="font-semibold text-sky-600"><Money value={saved.credit_note.amount} /></span></span>
              )}
              <span className="text-sm text-emerald-700">Return Value: <span className="text-base font-black"><Money value={saved.total_amount} /></span></span>
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

          {isDraft && can(isEdit ? 'edit_customer_returns' : 'create_customer_returns') && (
            <button
              type="button"
              disabled={saveMutation.isPending}
              onClick={handleSubmit}
              className="flex items-center gap-1 rounded bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm shadow-indigo-100 transition-all hover:bg-indigo-700 disabled:opacity-40 active:scale-95"
            >
              {saveMutation.isPending ? (<><RefreshCw size={11} className="animate-spin" /> Saving…</>) : (<><Save size={11} /> {isEdit ? 'Update' : 'Save Draft'}</>)}
            </button>
          )}

          {isEdit && status === 'draft' && can('confirm_customer_returns') && (
            <button
              type="button"
              disabled={confirmMutation.isPending}
              onClick={handleConfirm}
              className="flex items-center gap-1 rounded bg-emerald-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm shadow-emerald-100 transition-all hover:bg-emerald-700 disabled:opacity-40 active:scale-95"
            >
              {confirmMutation.isPending ? 'Confirming…' : 'Confirm Return'}
            </button>
          )}
        </div>
      </div>
    </div>
  )
}

/** One invoice line — plus, for a roll line, a sub-row listing the rolls it delivered. */
function ReturnLineRows({ line, disabled, stores, headerStoreName, over, pieceOver, onToggle, onPatch, onTogglePiece, onPatchPiece }) {
  const l = line

  return (
    <>
      <tr className={`${l.selected ? 'bg-emerald-50/40' : ''} ${disabled ? 'opacity-50' : 'hover:bg-slate-50/60'}`}>
        <td className="px-2 py-1 text-center">
          <input
            type="checkbox"
            checked={l.selected}
            onChange={onToggle}
            disabled={disabled}
            className="h-3.5 w-3.5 cursor-pointer rounded border-slate-300 text-emerald-600 focus:ring-emerald-500 disabled:cursor-not-allowed"
          />
        </td>
        <td className="max-w-[18rem] px-2 py-1">
          <div className="truncate font-medium text-slate-700" title={l.product_name}>
            <span className="font-mono text-slate-400">{l.product_code}</span> {l.product_name}
          </div>
          <div className="flex items-center gap-1.5 text-[10px] text-slate-400">
            {l.attribute_name && <span>{l.attribute_name}</span>}
            {l.is_scanned && <span className="inline-flex items-center gap-0.5 rounded bg-indigo-50 px-1 font-semibold text-indigo-500"><Layers size={9} /> Rolls</span>}
          </div>
        </td>
        <td className="px-2 py-1 text-right text-slate-600">{fmtQty(l.invoiced_qty)} <span className="text-[10px] text-slate-400">{l.unit_name}</span></td>
        <td className="px-2 py-1 text-right text-slate-500">{fmtQty(l.returned_qty)}</td>
        <td className="px-2 py-1 text-right font-semibold text-slate-700">{fmtQty(l.returnable_qty)}</td>
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
          <select
            className={`${CELL_INPUT_CLS} ${l.selected && l.condition === 'damaged' ? 'text-red-600' : ''}`}
            value={l.condition}
            onChange={(e) => onPatch({ condition: e.target.value })}
            disabled={!l.selected}
          >
            {CONDITIONS.map((c) => <option key={c.value} value={c.value}>{c.label}</option>)}
          </select>
        </td>
        <td className="px-2 py-1">
          <select className={CELL_INPUT_CLS} value={l.store_id} onChange={(e) => onPatch({ store_id: e.target.value })} disabled={!l.selected} title="Leave as header store, or send this item elsewhere (e.g. a Damaged store)">
            <option value="">{headerStoreName ? `Header (${headerStoreName})` : 'Header store'}</option>
            {stores.map((s) => <option key={s.id} value={s.id}>{s.store_name}</option>)}
          </select>
        </td>
        <td className="px-2 py-1 text-right font-semibold text-slate-700">{l.selected ? <Money value={lineValue(l)} /> : <span className="text-slate-300">—</span>}</td>
      </tr>

      {l.is_scanned && l.selected && (
        <tr className="bg-slate-50/60">
          <td></td>
          <td colSpan={9} className="px-2 pb-1.5 pt-0.5">
            <div className="flex flex-wrap gap-1.5">
              {l.pieces.map((p) => {
                const pDisabled = p.returnable_qty <= 0
                const pOver     = p.checked && pieceOver(p)
                return (
                  <label
                    key={p.do_piece_id}
                    className={`flex items-center gap-1.5 rounded border px-1.5 py-1 ${p.checked ? 'border-emerald-300 bg-white' : 'border-slate-200 bg-slate-50'} ${pDisabled ? 'opacity-50' : 'cursor-pointer'}`}
                  >
                    <input
                      type="checkbox"
                      checked={p.checked}
                      onChange={() => onTogglePiece(p)}
                      disabled={pDisabled}
                      className="h-3 w-3 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
                    />
                    <span className="font-mono text-[10px] text-slate-600">{p.piece_code}</span>
                    <input
                      type="number" min="0" max={p.returnable_qty} step="0.0001"
                      className={`${pOver ? CELL_INPUT_ERR_CLS : CELL_INPUT_CLS} !w-20`}
                      value={p.quantity}
                      onChange={(e) => onPatchPiece(p, { quantity: e.target.value })}
                      disabled={!p.checked}
                    />
                    <span className="text-[10px] text-slate-400">of {fmtQty(p.returnable_qty)}</span>
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

/** Read-only lines of a confirmed return, including where each returned roll went. */
function ConfirmedReturnItems({ saved }) {
  return (
    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <SectionHeader icon={PackageOpen} title="Returned Items" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100" />
      <div className="overflow-x-auto">
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b border-slate-200 bg-slate-50 text-left">
              <th className={`${TH_CLS} w-8`}>#</th>
              <th className={TH_CLS}>Item</th>
              <th className={`${TH_CLS} w-24 text-right`}>Qty</th>
              <th className={`${TH_CLS} w-24 text-right`}>Price</th>
              <th className={`${TH_CLS} w-28`}>Reason</th>
              <th className={`${TH_CLS} w-24`}>Condition</th>
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
                  {it.attribute_name && <div className="text-[10px] text-slate-400">{it.attribute_name}</div>}
                  {it.pieces?.length > 0 && (
                    <div className="mt-0.5 flex flex-wrap gap-1">
                      {it.pieces.map((p) => (
                        <span key={p.id} className="rounded bg-slate-100 px-1 font-mono text-[10px] text-slate-600" title="Delivered roll → roll now holding the returned goods">
                          {p.piece_code} ({fmtQty(p.quantity)}){p.restored_piece_code && p.restored_piece_code !== p.piece_code ? ` → ${p.restored_piece_code}` : ''}
                        </span>
                      ))}
                    </div>
                  )}
                  {it.remarks && <div className="text-[10px] italic text-slate-400">{it.remarks}</div>}
                </td>
                <td className="px-2 py-1 text-right text-slate-700">{fmtQty(it.quantity)} <span className="text-[10px] text-slate-400">{it.unit_name}</span></td>
                <td className="px-2 py-1 text-right text-slate-600"><Money value={it.unit_price} /></td>
                <td className="px-2 py-1 text-slate-600">{it.reason_label}</td>
                <td className={`px-2 py-1 font-semibold ${it.condition === 'damaged' ? 'text-red-600' : 'text-green-600'}`}>{it.condition_label}</td>
                <td className="px-2 py-1 text-slate-600">{it.store_name}</td>
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
