import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, Download, Pencil, Printer, RefreshCw, Send, Trash2, Wallet } from 'lucide-react'
import { useState } from 'react'
import { deleteInvoice, downloadInvoicePdf, getInvoice, updateInvoiceStatus } from '../../api/invoices'
import Breadcrumb from '../../components/Breadcrumb'
import Money from '../../components/ui/Money'
import { confirmAction, confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  issued:    'bg-blue-100 text-blue-700',
  paid:      'bg-green-100 text-green-700',
  cancelled: 'bg-red-100 text-red-500',
}

function Field({ label, value, mono = false }) {
  return (
    <div>
      <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
      <p className={`text-xs text-slate-700 ${mono ? 'font-mono' : ''}`}>{value || <span className="italic text-slate-300">—</span>}</p>
    </div>
  )
}

export default function InvoiceViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const [pdfBusy, setPdfBusy] = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['invoice', id],
    queryFn:  () => getInvoice(id),
  })

  const statusMutation = useMutation({
    mutationFn: (status) => updateInvoiceStatus(id, status),
    onSuccess: (_, status) => {
      queryClient.invalidateQueries({ queryKey: ['invoice', id] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      showSuccess(`Invoice ${status}.`)
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to update status.'),
  })

  const deleteMutation = useMutation({
    mutationFn: () => deleteInvoice(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      showSuccess('Invoice deleted.')
      navigate('/inventory/invoices')
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to delete invoice.'),
  })

  const handlePdf = async (print) => {
    setPdfBusy(true)
    try {
      const blob = await downloadInvoicePdf(id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href    = url
        a.download = `INV_${data?.data?.invoice_no ?? id}.pdf`
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
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <RefreshCw size={14} className="animate-spin" /> Loading…
        </div>
      </div>
    )
  }

  if (isError || !data?.data) {
    return <div className="py-20 text-center text-sm text-red-500">Failed to load the invoice.</div>
  }

  const inv   = data.data
  const items = inv.items ?? []

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Invoices', to: '/inventory/invoices' },
    { label: inv.invoice_no },
  ]

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-2 flex items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="font-mono text-base font-bold leading-none text-slate-800">{inv.invoice_no}</h1>
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[inv.status] ?? 'bg-slate-100 text-slate-500'}`}>
              {inv.status_label}
            </span>
            <span
              className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${inv.invoice_type === 'tax' ? 'bg-sky-100 text-sky-700' : 'bg-slate-100 text-slate-500'}`}
              title={inv.invoice_type === 'tax'
                ? 'Tax invoice — lines bill the Before-Tax price and add VAT per line'
                : 'Non Tax invoice — lines bill the After-Tax price (VAT inside), no VAT added'}
            >
              {inv.invoice_type_label ?? (inv.invoice_type === 'tax' ? 'Tax Invoice' : 'Non Tax Invoice')}
            </span>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>

        <div className="flex items-center gap-2">
          {inv.status === 'draft' && (
            <>
              <button
                type="button"
                disabled={statusMutation.isPending}
                onClick={async () => {
                  if (await confirmAction({
                    title: 'Issue Invoice?',
                    message: `${inv.invoice_no} will be issued to the customer. Issued invoices cannot be edited.`,
                    confirmText: 'Yes, Issue',
                    confirmColor: '#2563eb',
                  })) statusMutation.mutate('issued')
                }}
                className="flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-blue-700 disabled:opacity-60 active:scale-95"
              >
                <Send size={12} /> Issue
              </button>
              <Link
                to={`/inventory/invoices/${inv.id}/edit`}
                className="flex items-center gap-1 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50 active:scale-95"
              >
                <Pencil size={12} /> Edit
              </Link>
              <button
                type="button"
                disabled={deleteMutation.isPending}
                onClick={async () => { if (await confirmDelete(inv.invoice_no)) deleteMutation.mutate() }}
                className="flex items-center gap-1 rounded border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition-all hover:bg-red-50 disabled:opacity-60 active:scale-95"
              >
                <Trash2 size={12} /> Delete
              </button>
            </>
          )}
          {inv.status === 'issued' && (
            // Opens a new Customer Receipt with this invoice pre-selected — the invoice
            // flips to Paid automatically when the receipt is confirmed, never by hand.
            <button
              type="button"
              onClick={() => navigate(`/inventory/customer-receipts/create?invoice=${inv.id}`)}
              className="flex items-center gap-1 rounded bg-green-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-green-700 active:scale-95"
            >
              <Wallet size={12} /> Make a Payment
            </button>
          )}
          {(inv.status === 'draft' || inv.status === 'issued') && (
            <button
              type="button"
              disabled={statusMutation.isPending}
              onClick={async () => {
                if (await confirmAction({
                  title: 'Cancel Invoice?',
                  message: `${inv.invoice_no} will be cancelled — its source document can then be invoiced again.`,
                  confirmText: 'Yes, Cancel Invoice',
                  confirmColor: '#ef4444',
                  icon: 'warning',
                })) statusMutation.mutate('cancelled')
              }}
              className="flex items-center gap-1 rounded border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition-all hover:bg-red-50 disabled:opacity-60 active:scale-95"
            >
              <Ban size={12} /> Cancel
            </button>
          )}
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
            className="flex items-center gap-1 rounded border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-bold text-red-700 transition-all hover:bg-red-100 disabled:opacity-60 active:scale-95"
          >
            <Download size={12} /> PDF
          </button>
        </div>
      </div>

      <div className="flex flex-col gap-2">

        {/* Details */}
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
          <div className="grid grid-cols-2 gap-x-4 gap-y-2 md:grid-cols-4 lg:grid-cols-6">
            <Field label="Supplier" value={inv.company?.name} />
            <Field label="Customer" value={inv.customer?.name} />
            <Field label="Sales Order" value={inv.sales_order?.so_no} mono />
            <Field label="Delivery Order" value={inv.delivery_order?.do_no ?? 'Direct billing'} mono />
            <Field label="Invoice Date" value={inv.invoice_date} />
            <Field label="Due Date" value={inv.due_date} />
            <Field label="Issued / Paid" value={[inv.issued_at?.slice(0, 10), inv.paid_at?.slice(0, 10)].filter(Boolean).join(' / ')} />
            <Field label="Mode of Payment" value={inv.mode_of_payment_label} />
            <div className="col-span-2">
              <Field label="Billing / Delivery Address" value={inv.delivery_address} />
            </div>
            {inv.remarks && (
              <div className="col-span-2 md:col-span-4">
                <Field label="Remarks" value={inv.remarks} />
              </div>
            )}
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
                {items.map((it, idx) => (
                  <tr key={it.id} className="hover:bg-slate-50/60 transition-colors">
                    <td className="px-2 py-1.5 text-slate-400 tabular-nums">{idx + 1}</td>
                    <td className="px-2 py-1.5 font-mono text-slate-500">{it.product?.product_code}</td>
                    <td className="px-2 py-1.5 font-medium text-slate-700">{it.product?.name}</td>
                    <td className="px-2 py-1.5 text-slate-600">{it.attribute?.name || <span className="italic text-slate-300">—</span>}</td>
                    <td className="px-2 py-1.5 text-slate-500">{it.unit?.name}</td>
                    <td className="px-2 py-1.5 text-right text-slate-600 tabular-nums">{Number(it.quantity).toLocaleString(undefined, { maximumFractionDigits: 4 })}</td>
                    <td className="px-2 py-1.5 text-right text-slate-600"><Money value={it.unit_price} /></td>
                    <td className="px-2 py-1.5 text-right text-amber-600 tabular-nums">{Number(it.discount) > 0 ? Number(it.discount) : '—'}</td>
                    <td className="px-2 py-1.5 text-right text-sky-600 tabular-nums">{Number(it.tax) > 0 ? Number(it.tax) : '—'}</td>
                    <td className="px-2 py-1.5 text-right font-bold text-slate-800"><Money value={it.line_total} /></td>
                  </tr>
                ))}
              </tbody>
              <tfoot>
                <tr className="bg-indigo-50 border-t border-indigo-100">
                  <td colSpan={8} className="px-3 py-1.5">
                    <div className="flex items-center gap-4 text-xs">
                      <span className="font-bold uppercase tracking-wider text-indigo-600">Summary</span>
                      <span className="text-slate-500">Sub Total: <Money value={inv.subtotal} className="font-bold text-slate-700" /></span>
                      <span className="text-slate-500">Transport: <Money value={inv.transport_charge} className="font-bold text-slate-700" /></span>
                    </div>
                  </td>
                  <td colSpan={2} className="px-3 py-1.5 text-right">
                    <div className="flex flex-col items-end leading-tight">
                      <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-500">Invoice Total</span>
                      <Money value={inv.grand_total} className="text-base font-black text-indigo-700" />
                    </div>
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

      </div>
    </div>
  )
}
