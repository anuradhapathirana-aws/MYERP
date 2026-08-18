import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Ban, CheckCircle2, Download, FileText, Pencil, Printer, RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { downloadDoPdf, getDeliveryOrder, updateDeliveryOrderStatus } from '../../api/deliveryOrders'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  confirmed: 'bg-green-100 text-green-700',
  cancelled: 'bg-red-100 text-red-500',
}

const fmt = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

function Field({ label, value, mono = false }) {
  return (
    <div>
      <p className="text-[10px] font-bold uppercase tracking-wider text-slate-400">{label}</p>
      <p className={`text-xs text-slate-700 ${mono ? 'font-mono' : ''}`}>{value || <span className="italic text-slate-300">—</span>}</p>
    </div>
  )
}

export default function DeliveryOrderViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const [pdfBusy, setPdfBusy] = useState(false)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['delivery-order', id],
    queryFn:  () => getDeliveryOrder(id),
  })

  const statusMutation = useMutation({
    mutationFn: (status) => updateDeliveryOrderStatus(id, status),
    onSuccess: (_, status) => {
      queryClient.invalidateQueries({ queryKey: ['delivery-order', id] })
      queryClient.invalidateQueries({ queryKey: ['delivery-orders'] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      showSuccess(`Delivery order ${status}.`)
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to update status.'),
  })

  const handlePdf = async (print) => {
    setPdfBusy(true)
    try {
      const blob = await downloadDoPdf(id)
      if (print) {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href    = url
        a.download = `DO_${data?.data?.do_no ?? id}.pdf`
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
    return <div className="py-20 text-center text-sm text-red-500">Failed to load the delivery order.</div>
  }

  const doc   = data.data
  const items = doc.items ?? []
  const totalQty = items.reduce((s, it) => s + (parseFloat(it.quantity) || 0), 0)

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Delivery Orders', to: '/inventory/delivery-orders' },
    { label: doc.do_no },
  ]

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-2 flex items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="font-mono text-base font-bold leading-none text-slate-800">{doc.do_no}</h1>
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[doc.status] ?? 'bg-slate-100 text-slate-500'}`}>
              {doc.status_label}
            </span>
            {doc.invoice && (
              <Link to={`/inventory/invoices/${doc.invoice.id}`} className="rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold text-blue-700 hover:bg-blue-200 transition-colors">
                {doc.invoice.invoice_no}
              </Link>
            )}
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>

        <div className="flex items-center gap-2">
          {doc.status === 'draft' && (
            <>
              <button
                type="button"
                disabled={statusMutation.isPending}
                onClick={async () => {
                  if (await confirmAction({
                    title: 'Confirm Delivery Order?',
                    message: `${doc.do_no} will be confirmed and <b>stock will be deducted</b> for the shipped rolls/quantities. Confirmed delivery orders cannot be edited.`,
                    confirmText: 'Yes, Confirm & Ship',
                    confirmColor: '#4f46e5',
                    icon: 'warning',
                  })) statusMutation.mutate('confirmed')
                }}
                className="flex items-center gap-1 rounded bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-indigo-700 disabled:opacity-60 active:scale-95"
              >
                <CheckCircle2 size={12} /> Confirm & Ship
              </button>
              <button
                type="button"
                disabled={statusMutation.isPending}
                onClick={async () => {
                  if (await confirmAction({
                    title: 'Cancel Delivery Order?',
                    message: `${doc.do_no} will be cancelled. Its rolls stay reserved for the sales order.`,
                    confirmText: 'Yes, Cancel',
                    confirmColor: '#ef4444',
                    icon: 'warning',
                  })) statusMutation.mutate('cancelled')
                }}
                className="flex items-center gap-1 rounded border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition-all hover:bg-red-50 disabled:opacity-60 active:scale-95"
              >
                <Ban size={12} /> Cancel
              </button>
              <Link
                to={`/inventory/delivery-orders/${doc.id}/edit`}
                className="flex items-center gap-1 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50 active:scale-95"
              >
                <Pencil size={12} /> Edit
              </Link>
            </>
          )}
          {doc.status === 'confirmed' && !doc.invoice && (
            <Link
              to={`/inventory/invoices/create?do=${doc.id}`}
              className="flex items-center gap-1 rounded bg-blue-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-blue-700 active:scale-95"
            >
              <FileText size={12} /> Create Invoice
            </Link>
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
            <Field label="Date" value={doc.document_date} />
            <Field label="Sales Order" value={doc.sales_order?.so_no} mono />
            <Field label="Customer" value={doc.customer?.name} />
            <Field label="Customer Type" value={doc.sales_order?.customer_type} />
            <Field label="Sales Person" value={doc.sales_order?.sales_person} />
            <Field label="Order Taken By" value={doc.sales_order?.order_taken_by} />
            <Field label="Order Source" value={doc.sales_order?.order_source} />
            <Field label="Transaction Date" value={doc.sales_order?.transaction_date} />
            <Field label="Delivery Date" value={doc.delivery_date} />
            <Field label="Delivery Mode" value={doc.delivery_mode} />
            <Field label="Delivery Vehicle & Number" value={doc.delivery_vehicle} />
            <Field label="Responsible Person" value={doc.responsible_person} />
            <Field label="Confirmed At" value={doc.confirmed_at?.slice(0, 16)} />
            <div className="col-span-2">
              <Field label="Delivery Address" value={doc.delivery_address} />
            </div>
            {(doc.store || doc.location) && (
              <Field label="Ship-From (manual lines)" value={[doc.store?.name, doc.location?.name].filter(Boolean).join(' / ')} />
            )}
            {doc.remarks && (
              <div className="col-span-2 md:col-span-3">
                <Field label="Remarks" value={doc.remarks} />
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
                  <th className="w-16 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">UOM</th>
                  <th className="w-16 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Rolls</th>
                  <th className="w-24 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-700">Quantity</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {items.map((it, idx) => (
                  <FragmentRow key={it.id}>
                    <tr className="hover:bg-slate-50/60 transition-colors">
                      <td className="px-2 py-1.5 text-slate-400 tabular-nums">{idx + 1}</td>
                      <td className="px-2 py-1.5 font-mono text-slate-500">{it.product?.product_code}</td>
                      <td className="px-2 py-1.5 font-medium text-slate-700">{it.product?.name}</td>
                      <td className="px-2 py-1.5 text-slate-600">{it.attribute?.name || <span className="italic text-slate-300">—</span>}</td>
                      <td className="px-2 py-1.5 text-slate-500">{it.unit?.name}</td>
                      <td className="px-2 py-1.5 text-right text-slate-600 tabular-nums">{it.pieces?.length || '—'}</td>
                      <td className="px-2 py-1.5 text-right font-bold text-slate-800 tabular-nums">{Number(it.quantity).toLocaleString(undefined, { maximumFractionDigits: 4 })}</td>
                    </tr>
                    {(it.pieces?.length ?? 0) > 0 && (
                      <tr>
                        <td></td>
                        <td colSpan={6} className="px-2 pb-2">
                          <div className="rounded border border-indigo-100 bg-indigo-50/40 px-2 py-1">
                            <table className="w-full text-[11px]">
                              <tbody>
                                {it.pieces.map((p) => (
                                  <tr key={p.id} className="text-slate-600">
                                    <td className="py-0.5 pr-2 font-mono">{p.piece_code}</td>
                                    <td className="py-0.5 pr-2">Roll {p.roll_no || '—'}</td>
                                    <td className="py-0.5 pr-2 text-right tabular-nums">{Number(p.weight).toLocaleString(undefined, { maximumFractionDigits: 4 })}</td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        </td>
                      </tr>
                    )}
                  </FragmentRow>
                ))}
              </tbody>
              <tfoot>
                <tr className="bg-indigo-50 border-t border-indigo-100">
                  <td colSpan={6} className="px-3 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-indigo-500">Total Quantity</td>
                  <td className="px-2 py-1.5 text-right text-base font-black text-indigo-700 tabular-nums">{fmt(totalQty)}</td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

      </div>
    </div>
  )
}

function FragmentRow({ children }) {
  return <>{children}</>
}
