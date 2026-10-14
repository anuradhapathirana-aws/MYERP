import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { AlertTriangle, FileClock, Lock, RefreshCw, Save, Send } from 'lucide-react'
import {
  createProformaInvoice,
  getNextProformaNo,
  getProformaInvoice,
  getProformaSourceForSo,
  updateProformaInvoice,
  updateProformaInvoiceStatus,
} from '../../api/proformaInvoices'
import { getAllCompanies } from '../../api/companies'
import { getSalesOrders } from '../../api/salesOrders'
import Breadcrumb from '../../components/Breadcrumb'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Money from '../../components/ui/Money'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'

const INPUT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20'
const INPUT_ERR_CLS =
  'block w-full rounded border border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-1 focus:ring-red-500/20'
const INPUT_RO_CLS =
  'block w-full rounded border border-slate-200 bg-slate-100 px-2 py-1 text-xs font-mono text-slate-500 outline-none cursor-not-allowed'
const SELECT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20 cursor-pointer'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'text-[10px] text-red-500 leading-tight'

/** Offer validity used when the user hasn't picked a date (mirrors the backend default). */
const DEFAULT_VALID_DAYS = 14

const addDays = (iso, days) => {
  const d = new Date(`${iso}T00:00:00`)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

/** A line's quoted price for the chosen type — the same pair a DO invoice would bill. */
const linePrice = (it, type) => type === 'tax'
  ? { unit_price: Number(it.tax_unit_price ?? it.unit_price), tax: Number(it.tax_vat_pct ?? 0) }
  : { unit_price: Number(it.unit_price), tax: 0 }

function calcLine(qty, price, discPct, taxPct) {
  const gross = (Number(qty) || 0) * (Number(price) || 0)
  return gross - (gross * (Number(discPct) || 0) / 100) + (gross * (Number(taxPct) || 0) / 100)
}

export default function ProformaInvoiceFormPage() {
  const { id }    = useParams()
  const isEdit    = Boolean(id)
  const navigate  = useNavigate()
  const [search]  = useSearchParams()
  // ?so= deep link from the Sales Order view page pre-selects the order.
  const soFromUrl = search.get('so')

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Proforma Invoices', to: '/inventory/proforma-invoices' },
    { label: isEdit ? 'Edit Proforma' : 'New Proforma' },
  ]

  const today = new Date().toISOString().slice(0, 10)
  const [form, setForm] = useState({
    proforma_date:    today,
    valid_until:      addDays(today, DEFAULT_VALID_DAYS),
    payment_terms:    '',
    delivery_address: '',
    remarks:          '',
    company_id:       '',
  })
  const [soId, setSoId]         = useState(soFromUrl ?? '')
  const [invoiceType, setType]  = useState('non_tax')
  const [errors, setErrors]     = useState({})
  const [saving, setSaving]     = useState(false)

  /* ── Edit-mode hydration ── */
  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['proforma-invoice', id],
    queryFn:  () => getProformaInvoice(id),
    enabled:  isEdit,
  })

  useEffect(() => {
    if (!existing?.data) return
    const pf = existing.data
    setSoId(String(pf.so_id))
    setType(pf.invoice_type ?? 'non_tax')
    setForm({
      proforma_date:    pf.proforma_date ?? '',
      valid_until:      pf.valid_until ?? '',
      payment_terms:    pf.payment_terms ?? '',
      delivery_address: pf.delivery_address ?? '',
      remarks:          pf.remarks ?? '',
      company_id:       pf.company_id ? String(pf.company_id) : '',
    })
  }, [existing])

  const { data: companiesRes } = useQuery({
    queryKey: ['companies-all'],
    queryFn:  getAllCompanies,
    staleTime: 5 * 60 * 1000,
  })
  const companies = companiesRes?.data ?? []

  useEffect(() => {
    if (isEdit || form.company_id || companies.length === 0) return
    setForm((f) => ({ ...f, company_id: String(companies[0].id) }))
  }, [companies, isEdit, form.company_id])

  /* ── Confirmed sales orders — the only ones a proforma can quote ── */
  const { data: sosRes } = useQuery({
    queryKey: ['sales-orders-confirmed'],
    queryFn:  () => getSalesOrders(1, { status: 'confirmed' }),
    enabled:  !isEdit && !soFromUrl,
    staleTime: 60 * 1000,
  })
  const soOptions = (sosRes?.data ?? []).map((s) => ({
    value: String(s.id),
    label: `${s.so_no} · ${s.customer?.name ?? s.customer?.customer_name ?? ''}`,
  }))

  const { data: nextNo, isLoading: loadingNo } = useQuery({
    queryKey: ['next-proforma-no', soId, form.proforma_date],
    queryFn:  () => getNextProformaNo(soId, form.proforma_date),
    enabled:  !isEdit && Boolean(soId),
    staleTime: 0,
  })

  /* ── SO quote preview: lines with both price sets ── */
  const { data: source, isLoading: loadingSource, isError: sourceError, error: sourceErrorObj } = useQuery({
    queryKey: ['proforma-source', soId],
    queryFn:  () => getProformaSourceForSo(soId),
    enabled:  Boolean(soId),
    retry:    false,
  })

  // New proforma: adopt the customer's default type and the SO's address.
  useEffect(() => {
    if (!source || isEdit) return
    setType(source.default_invoice_type ?? 'non_tax')
    setForm((f) => ({ ...f, delivery_address: source.sales_order?.delivery_address ?? '' }))
  }, [source, isEdit])

  const guards  = source?.guards
  const blocked = !isEdit && guards && (!guards.so_confirmed || guards.has_live_proforma || guards.has_invoices)

  const lines = useMemo(() => (source?.items ?? []).map((it) => {
    const { unit_price, tax } = linePrice(it, invoiceType)
    return { ...it, unit_price, tax, amount: calcLine(it.quantity, unit_price, it.discount, tax) }
  }), [source, invoiceType])

  const totals = useMemo(() => {
    const lineTotal = lines.reduce((s, l) => s + l.amount, 0)
    const transport = Number(source?.sales_order?.transport_charge ?? 0)
    return { lineTotal, transport, grand: lineTotal + transport }
  }, [lines, source])

  const set = (field) => (e) => {
    const value = e.target.value
    setForm((f) => ({ ...f, [field]: value }))
    setErrors((er) => ({ ...er, [field]: undefined }))
  }

  const getClientErrors = () => {
    const ce = {}
    if (!isEdit && !soId) ce.so_id = ['Select a confirmed sales order.']
    if (!form.proforma_date) ce.proforma_date = ['Proforma date is required.']
    if (form.valid_until && form.valid_until < form.proforma_date)
      ce.valid_until = ['Valid until must be on or after the proforma date.']
    return ce
  }

  const payload = () => ({
    ...(isEdit ? {} : { so_id: Number(soId) }),
    proforma_date:    form.proforma_date,
    valid_until:      form.valid_until || null,
    payment_terms:    form.payment_terms.trim() || null,
    invoice_type:     invoiceType,
    delivery_address: form.delivery_address.trim() || null,
    remarks:          form.remarks.trim() || null,
    company_id:       form.company_id ? Number(form.company_id) : null,
  })

  const saveMutation = useMutation({
    mutationFn: (data) => isEdit ? updateProformaInvoice(id, data) : createProformaInvoice(data),
  })

  /** Save as draft, or save and issue straight away. */
  const handleSave = async (issue) => {
    const ce = getClientErrors()
    if (Object.keys(ce).length) {
      setErrors(ce)
      showError('Please complete the highlighted fields.')
      return
    }
    if (issue && !(await confirmAction({
      title: 'Issue Proforma?',
      message: 'The proforma will be issued to the customer and locked. Delivery-order invoices of this sales order will bill its prices.',
      confirmText: 'Yes, Issue',
      confirmColor: '#2563eb',
    }))) return

    setErrors({})
    setSaving(true)
    try {
      const res = await saveMutation.mutateAsync(payload())
      const pfId = res.data.id
      if (issue) await updateProformaInvoiceStatus(pfId, 'issued')
      showSuccess(issue ? 'Proforma invoice issued.' : (isEdit ? 'Proforma invoice updated.' : 'Proforma invoice saved as draft.'))
      navigate(`/inventory/proforma-invoices/${pfId}`)
    } catch (e) {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save proforma invoice.')
    } finally {
      setSaving(false)
    }
  }

  const err = (f) => errors[f]?.[0]

  if (isEdit && loadingExisting) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <RefreshCw size={14} className="animate-spin" /> Loading…
        </div>
      </div>
    )
  }

  return (
    <div className="w-full">
      <div className="mb-2 flex items-start justify-between gap-2">
        <div>
          <h1 className="text-base font-bold leading-none text-slate-800">
            {isEdit ? 'Edit Proforma Invoice' : 'New Proforma Invoice'}
          </h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <div className="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-1.5 text-center">
          <Money value={totals.grand} className="block text-sm font-black leading-tight text-indigo-700" />
          <div className="text-[9px] font-bold uppercase tracking-wider text-indigo-400">Proforma Total</div>
        </div>
      </div>

      {blocked && (
        <div className="mb-2 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-medium text-red-600">
          <AlertTriangle size={13} className="shrink-0" />
          {!guards.so_confirmed && 'Only a confirmed sales order can be quoted on a proforma.'}
          {guards.so_confirmed && guards.has_live_proforma && (
            <span>
              This sales order already has proforma{' '}
              <Link to={`/inventory/proforma-invoices/${guards.live_proforma?.id}`} className="font-mono font-bold underline">
                {guards.live_proforma?.proforma_no}
              </Link>
              {' '}— cancel it to raise a new one.
            </span>
          )}
          {guards.so_confirmed && !guards.has_live_proforma && guards.has_invoices &&
            'This sales order is already invoiced — a proforma must be raised before invoicing.'}
        </div>
      )}

      <div className="flex flex-col gap-2">

        {/* ── Proforma Details ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center justify-between gap-1.5 rounded-t-lg border-b border-indigo-100 bg-indigo-50 px-3 py-1.5 text-indigo-700">
            <div className="flex items-center gap-1.5">
              <FileClock size={12} />
              <h2 className="text-xs font-bold">
                Proforma Details
                {source && (
                  <span className="ml-2 font-normal text-indigo-400">
                    for {source.sales_order?.so_no} — {source.sales_order?.customer?.name}
                  </span>
                )}
              </h2>
            </div>
            {/* Tax: Before-Tax prices + VAT per line · Non Tax: After-Tax prices, no VAT */}
            <div className="flex items-center gap-1.5">
              <span className="text-[9px] font-semibold uppercase tracking-wider text-indigo-400">Invoice Type</span>
              {!isEdit && source?.default_invoice_type && (
                <span className="text-[9px] text-indigo-400" title="Auto-selected from the customer's TIN — you can still switch it">
                  (Auto: {source.default_invoice_type === 'tax' ? 'customer has TIN' : 'no customer TIN'})
                </span>
              )}
              <div className="flex overflow-hidden rounded-md border-2 border-indigo-200 text-[10px] font-bold">
                {[['tax', 'Tax'], ['non_tax', 'Non Tax']].map(([value, label], i) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => setType(value)}
                    className={`${i ? 'border-l-2 border-indigo-200 ' : ''}px-2.5 py-0.5 transition-colors ${invoiceType === value ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50'}`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>
          </div>
          <div className="p-3">
            <div className="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-6">
              <div>
                <label className={LABEL_CLS}>Proforma Number</label>
                <input
                  readOnly
                  className={INPUT_RO_CLS}
                  value={isEdit
                    ? (existing?.data?.proforma_no ?? '')
                    : (!soId ? 'Select a sales order first' : (loadingNo ? 'Generating…' : (nextNo ?? '')))}
                />
              </div>
              <div>
                <label className={LABEL_CLS}>
                  Sales Order {!isEdit && <span className="text-red-500 normal-case font-bold">*</span>}
                </label>
                {isEdit || soFromUrl ? (
                  <input readOnly className={INPUT_RO_CLS} value={source?.sales_order?.so_no ?? existing?.data?.sales_order?.so_no ?? ''} />
                ) : (
                  <FilterSearchSelect
                    value={soId}
                    onChange={(val) => { setSoId(val); setErrors((e) => ({ ...e, so_id: undefined })) }}
                    options={soOptions}
                    placeholder="Select confirmed SO…"
                  />
                )}
                {err('so_id') && <p className={ERR_CLS}>{err('so_id')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Supplier (Company)</label>
                <select className={SELECT_CLS} value={form.company_id} onChange={set('company_id')}>
                  <option value="">— Select —</option>
                  {companies.map((c) => (
                    <option key={c.id} value={c.id}>{c.company_name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className={LABEL_CLS}>Proforma Date <span className="text-red-500 normal-case font-bold">*</span></label>
                <input type="date" className={err('proforma_date') ? INPUT_ERR_CLS : INPUT_CLS} value={form.proforma_date} onChange={set('proforma_date')} />
                {err('proforma_date') && <p className={ERR_CLS}>{err('proforma_date')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Valid Until</label>
                <input type="date" className={err('valid_until') ? INPUT_ERR_CLS : INPUT_CLS} value={form.valid_until} onChange={set('valid_until')} />
                {err('valid_until') && <p className={ERR_CLS}>{err('valid_until')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Transport Charge</label>
                <input readOnly className={INPUT_RO_CLS} value={totals.transport.toFixed(2)} title="Taken from the sales order" />
              </div>
              <div className="col-span-2">
                <label className={LABEL_CLS}>Payment Terms</label>
                <input className={INPUT_CLS} placeholder="e.g. 50% advance, balance before delivery" value={form.payment_terms} onChange={set('payment_terms')} />
              </div>
              <div className="col-span-2">
                <label className={LABEL_CLS}>Billing / Delivery Address</label>
                <input className={INPUT_CLS} value={form.delivery_address} onChange={set('delivery_address')} />
              </div>
              <div className="col-span-2">
                <label className={LABEL_CLS}>Remarks</label>
                <input className={INPUT_CLS} placeholder="Notes…" value={form.remarks} onChange={set('remarks')} />
              </div>
            </div>
          </div>
        </div>

        {/* ── Lines (locked to the sales order) ── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-1 text-[10px] font-medium text-slate-500">
            <Lock size={10} /> Quantities and prices come from the sales order — change them on the SO.
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-left">
                  <th className="w-7 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">#</th>
                  <th className="w-24 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Code</th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Product</th>
                  <th className="w-20 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Colour</th>
                  <th className="w-14 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">UOM</th>
                  <th className="w-20 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Qty</th>
                  <th className="w-24 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Unit Price</th>
                  <th className="w-16 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-amber-500">Disc%</th>
                  <th className="w-14 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-sky-500">Tax%</th>
                  <th className="w-28 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-700">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {!soId && (
                  <tr>
                    <td colSpan={10} className="px-4 py-10 text-center text-sm text-slate-400">
                      Select a confirmed <b>Sales Order</b> above to load its lines.
                    </td>
                  </tr>
                )}
                {Boolean(soId) && loadingSource && (
                  <tr>
                    <td colSpan={10} className="px-4 py-10 text-center text-sm text-slate-400">
                      <span className="inline-flex items-center gap-2"><RefreshCw size={13} className="animate-spin" /> Loading sales order…</span>
                    </td>
                  </tr>
                )}
                {sourceError && (
                  <tr>
                    <td colSpan={10} className="px-4 py-10 text-center text-sm text-red-500">
                      {sourceErrorObj?.response?.data?.message ?? 'Failed to load the sales order.'}
                    </td>
                  </tr>
                )}
                {lines.map((row, idx) => (
                  <tr key={row.so_item_id} className="hover:bg-slate-50/60 transition-colors">
                    <td className="px-2 py-1.5 text-slate-400 tabular-nums">{idx + 1}</td>
                    <td className="px-2 py-1.5 font-mono text-slate-500">{row.product?.product_code}</td>
                    <td className="max-w-0 truncate px-2 py-1.5 font-medium text-slate-700" title={row.product?.name}>{row.product?.name}</td>
                    <td className="px-2 py-1.5 text-slate-600">{row.attribute?.name || <span className="italic text-slate-300">—</span>}</td>
                    <td className="px-2 py-1.5 text-slate-500">{row.unit?.name}</td>
                    <td className="px-2 py-1.5 text-right text-slate-600 tabular-nums">{Number(row.quantity).toLocaleString(undefined, { maximumFractionDigits: 4 })}</td>
                    <td className="px-2 py-1.5 text-right text-slate-600"><Money value={row.unit_price} /></td>
                    <td className="px-2 py-1.5 text-right text-amber-600 tabular-nums">{Number(row.discount) > 0 ? Number(row.discount) : '—'}</td>
                    <td className="px-2 py-1.5 text-right text-sky-600 tabular-nums">{Number(row.tax) > 0 ? Number(row.tax) : '—'}</td>
                    <td className="px-2 py-1.5 text-right font-bold text-slate-800"><Money value={row.amount} /></td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="bg-indigo-50 border-t border-indigo-100">
                  <td colSpan={8} className="px-3 py-1.5">
                    <div className="flex items-center gap-4 text-xs">
                      <span className="font-bold uppercase tracking-wider text-indigo-600">Summary</span>
                      <span className="text-slate-500">Lines: <Money value={totals.lineTotal} className="font-bold text-slate-700" /></span>
                      <span className="text-slate-500">Transport: <Money value={totals.transport} className="font-bold text-slate-700" /></span>
                    </div>
                  </td>
                  <td colSpan={2} className="px-3 py-1.5 text-right">
                    <div className="flex flex-col items-end leading-tight">
                      <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-500">Proforma Total</span>
                      <Money value={totals.grand} className="text-base font-black text-indigo-700" />
                    </div>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div className="flex items-center justify-end gap-2 border-t border-slate-100 bg-white px-3 py-2">
            <button
              type="button"
              disabled={saving || blocked || lines.length === 0}
              onClick={() => handleSave(false)}
              className="flex items-center gap-1 rounded border border-indigo-300 bg-white px-4 py-1.5 text-xs font-bold text-indigo-600 transition-all hover:bg-indigo-50 disabled:opacity-60 active:scale-95"
            >
              {saving ? <RefreshCw size={11} className="animate-spin" /> : <Save size={11} />}
              {isEdit ? 'Update Draft' : 'Save Draft'}
            </button>
            <button
              type="button"
              disabled={saving || blocked || lines.length === 0}
              onClick={() => handleSave(true)}
              className="flex items-center gap-1 rounded bg-blue-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-blue-700 disabled:opacity-60 active:scale-95"
            >
              <Send size={11} /> {isEdit ? 'Update & Issue' : 'Save & Issue'}
            </button>
          </div>
        </div>

      </div>
    </div>
  )
}
