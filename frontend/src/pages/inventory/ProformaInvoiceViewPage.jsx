import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, Download, Pencil, Printer, RefreshCw, Send, ShoppingCart, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import {
  deleteProformaInvoice,
  downloadProformaInvoicePdf,
  getProformaInvoice,
  updateProformaInvoiceStatus,
} from '../../api/proformaInvoices'
import Breadcrumb from '../../components/Breadcrumb'
import Money from '../../components/ui/Money'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import { usePermissions } from '../../hooks/usePermissions'
import { confirmAction, confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'

const STATUS_STYLES = {
  draft:              'bg-amber-100 text-amber-700',
  issued:             'bg-blue-100 text-blue-700',
  partially_invoiced: 'bg-violet-100 text-violet-700',
  converted:          'bg-green-100 text-green-700',
  cancelled:          'bg-red-100 text-red-500',
}

const DOC_STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  confirmed: 'bg-indigo-100 text-indigo-700',
  completed: 'bg-green-100 text-green-700',
  issued:    'bg-blue-100 text-blue-700',
  paid:      'bg-green-100 text-green-700',
  cancelled: 'bg-red-100 text-red-500',
}

const STOCK_BADGE = {
  in_stock:  { cls: 'bg-green-100 text-green-700', label: 'In stock' },
  partial:   { cls: 'bg-amber-100 text-amber-700', label: 'Partial' },
  non_stock: { cls: 'bg-slate-100 text-slate-500', label: 'Non-stock' },
}

const fmtQty = (n) => Number(n ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 })
const cap = (s) => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '')

function Field({ label, value, mono = false }) {
  return (
    <div>
      <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
      <p className={`text-xs text-slate-700 ${mono ? 'font-mono' : ''}`}>{value || <span className="italic text-slate-300">—</span>}</p>
    </div>
  )
}

export default function ProformaInvoiceViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const { can }     = usePermissions()
  const [pdfBusy, setPdfBusy] = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['proforma-invoice', id],
    queryFn:  () => getProformaInvoice(id),
  })

  const statusMutation = useMutation({
    mutationFn: (status) => updateProformaInvoiceStatus(id, status),
    onSuccess: (_, status) => {
      queryClient.invalidateQueries({ queryKey: ['proforma-invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['proforma-invoices'] })
      showSuccess(`Proforma invoice ${status}.`)
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to update status.'),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteProformaInvoice(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['proforma-invoices'] })
      showSuccess('Proforma invoice deleted.')
      navigate('/inventory/proforma-invoices')
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to delete proforma invoice.'),
  })

  const pf       = data?.data
  const items    = pf?.items ?? []
  const quoteOut = ['issued', 'partially_invoiced', 'converted'].includes(pf?.status)
  const toRecall = items.some((it) => Number(it.quantity) - Number(it.recalled_quantity ?? 0) > 0.0001)
  const canRecall = ['issued', 'partially_invoiced'].includes(pf?.status) && toRecall && can('create_sales_orders')
  const isDirect  = pf?.source_type === 'direct'

  const handleIssue = async () => {
    if (!pf || pf.status !== 'draft' || statusMutation.isPending) return
    if (await confirmAction({
      title: 'Issue Proforma?',
      message: `${pf.proforma_no} will be issued to the customer and locked. Each line records the stock available today.`,
      confirmText: 'Yes, Issue',
      confirmColor: '#2563eb',
    })) statusMutation.mutate('issued')
  }

  const createSo = () => navigate(`/inventory/sales-orders/create?proforma=${id}`)

  /* ── Ctrl+Enter = the next step: Issue (draft) → Create SO from Proforma ── */
  const primaryRef = useRef(null)
  useEffect(() => {
    primaryRef.current = () => {
      if (pf?.status === 'draft') handleIssue()
      else if (canRecall) createSo()
    }
  })
  useEffect(() => {
    const handler = (e) => {
      if (e.key !== 'Enter' || !(e.ctrlKey || e.metaKey) || e.altKey || e.shiftKey || e.repeat) return
      const popup = document.querySelector('.swal2-popup')
      if (popup && !popup.classList.contains('swal2-toast')) return
      e.preventDefault()
      primaryRef.current?.()
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [])

  const handlePdf = async (print) => {
    setPdfBusy(true)
    try {
      const blob = await downloadProformaInvoicePdf(id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href    = url
        a.download = `PI_${pf?.proforma_no ?? id}.pdf`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError('Failed to generate PDF.')
    } finally {
      setPdfBusy(false)
    }
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400"><RefreshCw size={14} className="animate-spin" /> Loading…</div>
      </div>
    )
  }

  if (isError || !pf) {
    return <div className="py-20 text-center text-sm text-red-500">Failed to load the proforma invoice.</div>
  }

  const invoices    = pf.invoices ?? []
  const salesOrders = pf.sales_orders ?? []
  // Legacy proformas (raised from an SO) still point at that order
  const legacySo    = !isDirect && pf.sales_order ? [{ id: pf.sales_order.id, so_no: pf.sales_order.so_no, status: null }] : []
  const soList      = salesOrders.length ? salesOrders : legacySo

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Proforma Invoices', to: '/inventory/proforma-invoices' },
    { label: pf.proforma_no },
  ]

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-2 flex items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="font-mono text-base font-bold leading-none text-slate-800">{pf.proforma_no}</h1>
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[pf.status] ?? 'bg-slate-100 text-slate-500'}`}>{pf.status_label}</span>
            {pf.is_expired && (
              <span className="inline-flex items-center rounded-full bg-red-50 px-2 py-0.5 text-[10px] font-semibold text-red-500" title="Valid-until date has passed">Expired</span>
            )}
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${pf.invoice_type === 'tax' ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-500'}`}>{pf.invoice_type_label}</span>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>

        <div className="flex items-center gap-2">
          {pf.status === 'draft' && (
            <>
              <button type="button" disabled={statusMutation.isPending} onClick={handleIssue} title="Issue (Ctrl+Enter)"
                className="flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-blue-700 disabled:opacity-60 active:scale-95">
                <Send size={12} /> Issue
                <kbd className="ml-1 rounded border border-blue-300 bg-blue-500 px-1 text-[9px] font-semibold text-blue-50">Ctrl+Enter</kbd>
              </button>
              {isDirect && (
                <Link to={`/inventory/proforma-invoices/${pf.id}/edit`}
                  className="flex items-center gap-1 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50 active:scale-95">
                  <Pencil size={12} /> Edit
                </Link>
              )}
              <button type="button" disabled={deleteMutation.isPending} onClick={async () => { if (await confirmDelete(pf.proforma_no)) deleteMutation.mutate() }}
                className="flex items-center gap-1 rounded border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition-all hover:bg-red-50 disabled:opacity-60 active:scale-95">
                <Trash2 size={12} /> Delete
              </button>
            </>
          )}
          {canRecall && (
            <button type="button" onClick={createSo} title="Open a new Sales Order pre-filled from this proforma (Ctrl+Enter)"
              className="flex items-center gap-1 rounded bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-indigo-700 active:scale-95">
              <ShoppingCart size={12} /> Create SO from Proforma
              <kbd className="ml-1 rounded border border-indigo-300 bg-indigo-500 px-1 text-[9px] font-semibold text-indigo-50">Ctrl+Enter</kbd>
            </button>
          )}
          {(pf.status === 'draft' || pf.status === 'issued') && (
            <button
              type="button"
              disabled={statusMutation.isPending}
              onClick={async () => {
                if (await confirmAction({
                  title: 'Cancel Proforma?',
                  message: `${pf.proforma_no} will be cancelled. A proforma already recalled into a sales order cannot be cancelled.`,
                  confirmText: 'Yes, Cancel Proforma',
                  confirmColor: '#ef4444',
                  icon: 'warning',
                })) statusMutation.mutate('cancelled')
              }}
              className="flex items-center gap-1 rounded border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition-all hover:bg-red-50 disabled:opacity-60 active:scale-95"
            >
              <Ban size={12} /> Cancel
            </button>
          )}
          <button type="button" disabled={pdfBusy} onClick={() => handlePdf(true)}
            className="flex items-center gap-1 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50 disabled:opacity-60 active:scale-95">
            <Printer size={12} /> Print
          </button>
          <button type="button" disabled={pdfBusy} onClick={() => handlePdf(false)}
            className="flex items-center gap-1 rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700 transition-all hover:bg-red-100 disabled:opacity-60 active:scale-95">
            <Download size={12} /> PDF
          </button>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        {/* Details + linked documents */}
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">
          <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm lg:col-span-2">
            <div className="grid grid-cols-2 gap-x-4 gap-y-2 md:grid-cols-4">
              <Field label="Supplier" value={pf.company?.name} />
              <Field label="Customer" value={pf.customer?.name} />
              <Field label="Proforma Date" value={pf.proforma_date} />
              <Field label="Valid Until" value={pf.valid_until} />
              <Field label="Issued" value={pf.issued_at?.slice(0, 10)} />
              <Field label="Converted" value={pf.converted_at?.slice(0, 10)} />
              <Field label="Cancelled" value={pf.cancelled_at?.slice(0, 10)} />
              <Field label="Transport" value={Number(pf.transport_charge) > 0 ? Number(pf.transport_charge).toFixed(2) : ''} />
              <div className="col-span-2"><Field label="Payment Terms" value={pf.payment_terms} /></div>
              <div className="col-span-2"><Field label="Billing / Delivery Address" value={pf.delivery_address} /></div>
              {pf.remarks && <div className="col-span-2 md:col-span-4"><Field label="Remarks" value={pf.remarks} /></div>}
            </div>
          </div>

          <div className="flex flex-col gap-2">
            <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
              <div className="border-b border-slate-100 bg-slate-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Sales Orders</div>
              {soList.length === 0 ? (
                <p className="px-3 py-3 text-center text-xs text-slate-400">
                  {quoteOut ? 'Not recalled yet — use Create SO from Proforma.' : 'Issue the proforma to recall it into a sales order.'}
                </p>
              ) : (
                <table className="w-full text-xs"><tbody className="divide-y divide-slate-100">
                  {soList.map((so) => (
                    <tr key={so.id} className="hover:bg-slate-50/60">
                      <td className="px-3 py-1.5"><Link to={`/inventory/sales-orders/${so.id}`} className="font-mono text-indigo-600 hover:underline">{so.so_no}</Link></td>
                      <td className="px-3 py-1.5 text-right">
                        {so.status && <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${DOC_STATUS_STYLES[so.status] ?? 'bg-slate-100 text-slate-500'}`}>{cap(so.status)}</span>}
                      </td>
                    </tr>
                  ))}
                </tbody></table>
              )}
            </div>
            <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
              <div className="border-b border-slate-100 bg-slate-50 px-3 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Invoices</div>
              {invoices.length === 0 ? (
                <p className="px-3 py-3 text-center text-xs text-slate-400">Invoices of the recalled orders' deliveries link here automatically.</p>
              ) : (
                <table className="w-full text-xs"><tbody className="divide-y divide-slate-100">
                  {invoices.map((inv) => (
                    <tr key={inv.id} className="hover:bg-slate-50/60">
                      <td className="px-3 py-1.5">
                        <Link to={`/inventory/invoices/${inv.id}`} className="font-mono text-indigo-600 hover:underline">{inv.invoice_no}</Link>
                        <div className="font-mono text-[10px] text-slate-400">{inv.do_no} · {inv.invoice_date}</div>
                      </td>
                      <td className="px-2 py-1.5"><span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${DOC_STATUS_STYLES[inv.status] ?? 'bg-slate-100 text-slate-500'}`}>{inv.status_label}</span></td>
                      <td className="px-3 py-1.5 text-right font-medium text-slate-700"><Money value={inv.grand_total} /></td>
                    </tr>
                  ))}
                </tbody></table>
              )}
            </div>
          </div>
        </div>

        {/* Items */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-left">
                  <th className="w-7 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">#</th>
                  <th className="w-24 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Code</th>
                  <th className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Product</th>
                  <th className="w-32 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Colour / Variant</th>
                  <th className="w-14 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">UOM</th>
                  <th className="w-20 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Qty</th>
                  {quoteOut && (
                    <>
                      <th className="w-20 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-indigo-600">Recalled</th>
                      <th className="w-20 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-green-600">Invoiced</th>
                      <th className="w-20 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-violet-600">Balance</th>
                    </>
                  )}
                  <th className="w-24 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Unit Price</th>
                  <th className="w-16 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-amber-500">Disc%</th>
                  <th className="w-14 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-sky-500">Tax%</th>
                  <th className="w-28 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-700">Amount</th>
                  {quoteOut && <th className="w-24 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500" title="Stock available when the proforma was issued">Stock at issue</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {items.map((it, idx) => {
                  const balance = Math.max(0, Number(it.quantity) - Number(it.invoiced_quantity ?? 0))
                  const badge   = STOCK_BADGE[it.stock_status]
                  return (
                    <tr key={it.id} className="hover:bg-slate-50/60 transition-colors">
                      <td className="px-2 py-1.5 text-slate-400 tabular-nums">{idx + 1}</td>
                      <td className="px-2 py-1.5 font-mono text-slate-500">{it.product?.product_code}</td>
                      <td className="max-w-0 truncate px-2 py-1.5 font-medium text-slate-700" title={it.product?.name}>{it.product?.name}</td>
                      <td className="px-2 py-1.5 text-slate-600">{it.variant?.name || it.attribute?.name || <span className="italic text-slate-300">—</span>}</td>
                      <td className="px-2 py-1.5 text-slate-500">{it.unit?.name}</td>
                      <td className="px-2 py-1.5 text-right text-slate-600 tabular-nums">{fmtQty(it.quantity)}</td>
                      {quoteOut && (
                        <>
                          <td className="px-2 py-1.5 text-right text-indigo-600 tabular-nums">{fmtQty(it.recalled_quantity)}</td>
                          <td className="px-2 py-1.5 text-right text-green-600 tabular-nums">{fmtQty(it.invoiced_quantity)}</td>
                          <td className={`px-2 py-1.5 text-right tabular-nums ${balance > 0 ? 'font-semibold text-violet-600' : 'text-slate-300'}`}>{fmtQty(balance)}</td>
                        </>
                      )}
                      <td className="px-2 py-1.5 text-right text-slate-600"><Money value={it.unit_price} /></td>
                      <td className="px-2 py-1.5 text-right text-amber-600 tabular-nums">{Number(it.discount) > 0 ? Number(it.discount) : '—'}</td>
                      <td className="px-2 py-1.5 text-right text-sky-600 tabular-nums">{Number(it.tax) > 0 ? Number(it.tax) : '—'}</td>
                      <td className="px-2 py-1.5 text-right font-bold text-slate-800"><Money value={it.line_total} /></td>
                      {quoteOut && (
                        <td className="px-2 py-1.5">
                          {badge && (
                            <span className={`inline-flex rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${badge.cls}`} title={`Available at issue: ${fmtQty(it.available_qty)}`}>
                              {badge.label}{it.stock_status === 'partial' ? ` ${fmtQty(it.available_qty)}` : ''}
                            </span>
                          )}
                        </td>
                      )}
                    </tr>
                  )
                })}
              </tbody>
              <tfoot>
                <tr className="bg-indigo-50 border-t border-indigo-100">
                  <td colSpan={quoteOut ? 11 : 8} className="px-3 py-1.5">
                    <div className="flex items-center gap-4 text-xs">
                      <span className="font-bold uppercase tracking-wider text-indigo-600">Summary</span>
                      <span className="text-slate-500">Sub Total: <Money value={pf.subtotal} className="font-bold text-slate-700" /></span>
                      <span className="text-slate-500">Transport: <Money value={pf.transport_charge} className="font-bold text-slate-700" /></span>
                    </div>
                  </td>
                  <td colSpan={quoteOut ? 3 : 2} className="px-3 py-1.5 text-right">
                    <div className="flex flex-col items-end leading-tight">
                      <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-500">Proforma Total</span>
                      <Money value={pf.grand_total} className="text-base font-black text-indigo-700" />
                    </div>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>
      </div>

      {(pf.status === 'draft' || canRecall) && (
        <KeyboardShortcuts shortcuts={[
          pf.status === 'draft'
            ? { keys: ['Ctrl', 'Enter'], desc: 'Issue the proforma' }
            : { keys: ['Ctrl', 'Enter'], desc: 'Create SO from Proforma (lines still to recall)' },
          { keys: ['Enter'], desc: 'In a confirmation: Yes' },
        ]} />
      )}
    </div>
  )
}
