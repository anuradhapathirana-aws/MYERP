import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Ban, CheckCircle2, ChevronDown, ChevronRight, FileText, Pencil, QrCode, RefreshCw, Truck,
} from 'lucide-react'
import { useState } from 'react'
import { getSalesOrder, updateSalesOrderStatus } from '../../api/salesOrders'
import Breadcrumb from '../../components/Breadcrumb'
import Money from '../../components/ui/Money'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'
import { fmtMoneyWithSymbol } from '../../utils/currency'

const STATUS_STYLES = {
  draft:     'bg-slate-100 text-slate-600',
  confirmed: 'bg-indigo-100 text-indigo-700',
  completed: 'bg-green-100 text-green-700',
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

export default function SalesOrderViewPage() {
  const { id }      = useParams()
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const [expanded, setExpanded] = useState({}) // { [itemId]: bool }

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sales-order', id],
    queryFn:  () => getSalesOrder(id),
  })

  const statusMutation = useMutation({
    mutationFn: (status) => updateSalesOrderStatus(id, status),
    onSuccess: (_, status) => {
      queryClient.invalidateQueries({ queryKey: ['sales-order', id] })
      queryClient.invalidateQueries({ queryKey: ['sales-orders'] })
      showSuccess(`Sales order ${status}.`)
    },
    onError: (e) => showError(e.response?.data?.message ?? 'Failed to update status.'),
  })

  const handleStatus = async (status, { title, message, confirmText, confirmColor, icon }) => {
    if (await confirmAction({ title, message, confirmText, confirmColor, icon })) {
      statusMutation.mutate(status)
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
    return <div className="py-20 text-center text-sm text-red-500">Failed to load the sales order.</div>
  }

  const so    = data.data
  const items = so.items ?? []

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Sales Orders', to: '/inventory/sales-orders' },
    { label: so.so_no },
  ]

  return (
    <div className="w-full">
      {/* Header */}
      <div className="mb-2 flex items-start justify-between gap-2">
        <div>
          <div className="flex items-center gap-2">
            <h1 className="font-mono text-base font-bold leading-none text-slate-800">{so.so_no}</h1>
            <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[so.status] ?? 'bg-slate-100 text-slate-500'}`}>
              {so.status_label}
            </span>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>

        <div className="flex items-center gap-2">
          {so.status === 'confirmed' && (
            <Link
              to={`/inventory/delivery-orders/create?so=${so.id}`}
              className="flex items-center gap-1 rounded bg-violet-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-violet-700 active:scale-95"
            >
              <Truck size={12} /> Create DO
            </Link>
          )}
          {so.status === 'draft' && (
            <button
              type="button"
              disabled={statusMutation.isPending}
              onClick={() => handleStatus('confirmed', {
                title: 'Confirm Sales Order?',
                message: `${so.so_no} will be confirmed and its scanned pieces stay reserved.`,
                confirmText: 'Yes, Confirm',
                confirmColor: '#4f46e5',
              })}
              className="flex items-center gap-1 rounded bg-indigo-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-indigo-700 disabled:opacity-60 active:scale-95"
            >
              <CheckCircle2 size={12} /> Confirm
            </button>
          )}
          {/* No manual Complete — the SO completes automatically when its
              confirmed DOs deliver every line in full. Invoices are raised
              from confirmed DOs (Invoices → New Invoice), never from the SO. */}
          {(so.status === 'draft' || so.status === 'confirmed') && (
            <>
              <button
                type="button"
                disabled={statusMutation.isPending}
                onClick={() => handleStatus('cancelled', {
                  title: 'Cancel Sales Order?',
                  message: `All allocated pieces of ${so.so_no} will be returned to stock.`,
                  confirmText: 'Yes, Cancel Order',
                  confirmColor: '#ef4444',
                  icon: 'warning',
                })}
                className="flex items-center gap-1 rounded border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition-all hover:bg-red-50 disabled:opacity-60 active:scale-95"
              >
                <Ban size={12} /> Cancel
              </button>
              <Link
                to={`/inventory/sales-orders/${so.id}/edit`}
                className="flex items-center gap-1 rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50 active:scale-95"
              >
                <Pencil size={12} /> Edit
              </Link>
            </>
          )}
        </div>
      </div>

      <div className="flex flex-col gap-2">

        {/* Details */}
        <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
          <div className="grid grid-cols-2 gap-x-4 gap-y-2 md:grid-cols-4 lg:grid-cols-6">
            <Field label="Order Date" value={so.order_date} />
            <Field label="Transaction Date" value={so.transaction_date} />
            <Field label="Expected Date" value={so.expected_date} />
            <Field label="Reference No" value={so.reference_no} mono />
            <Field label="Order Source" value={so.order_source ? so.order_source.replace('_', ' ') : ''} />
            <Field label="Created" value={so.created_at?.slice(0, 16)} />
            <Field label="Customer" value={so.customer ? `${so.customer.customer_code ? so.customer.customer_code + ' — ' : ''}${so.customer.name}` : ''} />
            <Field label="Customer Type" value={so.customer_type} />
            <Field label="Sales Person" value={so.sales_person?.name} />
            <Field label="Order Taken By" value={so.order_taken_by_user?.name} />
            <div className="col-span-2">
              <Field label="Delivery Address" value={so.delivery_address} />
            </div>
            {so.remarks && (
              <div className="col-span-2 md:col-span-4 lg:col-span-6">
                <Field label="Remarks" value={so.remarks} />
              </div>
            )}
          </div>
        </div>

        {/* Deliveries & Invoices */}
        {((so.delivery_orders?.length ?? 0) > 0 || (so.invoices?.length ?? 0) > 0) && (
          <div className="rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
            <p className="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Deliveries &amp; Invoices</p>
            <div className="flex flex-wrap gap-1.5">
              {(so.delivery_orders ?? []).map((d) => (
                <Link
                  key={`do-${d.id}`}
                  to={`/inventory/delivery-orders/${d.id}`}
                  className="flex items-center gap-1.5 rounded border border-violet-200 bg-violet-50 px-2 py-1 text-[11px] font-semibold text-violet-700 transition-colors hover:bg-violet-100"
                >
                  <Truck size={11} />
                  <span className="font-mono">{d.do_no}</span>
                  <span className="text-[9px] font-normal text-violet-400">{d.status_label} · {d.delivery_date}</span>
                </Link>
              ))}
              {(so.invoices ?? []).map((inv) => (
                <Link
                  key={`inv-${inv.id}`}
                  to={`/inventory/invoices/${inv.id}`}
                  className="flex items-center gap-1.5 rounded border border-blue-200 bg-blue-50 px-2 py-1 text-[11px] font-semibold text-blue-700 transition-colors hover:bg-blue-100"
                >
                  <FileText size={11} />
                  <span className="font-mono">{inv.invoice_no}</span>
                  <span className="text-[9px] font-normal text-blue-400">
                    {inv.status_label} · {fmtMoneyWithSymbol(inv.grand_total)}{inv.do_id == null ? ' · Direct' : ''}
                  </span>
                </Link>
              ))}
            </div>
          </div>
        )}

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
                  <th className="w-24 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Unit Price</th>
                  <th className="w-24 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Quantity</th>
                  <th className="w-24 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-green-600">Delivered</th>
                  <th className="w-16 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-amber-500">Disc%</th>
                  <th className="w-16 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-sky-500">Tax%</th>
                  <th className="w-28 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-700">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {items.map((it, idx) => (
                  <FragmentRow key={it.id}>
                    <tr className="hover:bg-slate-50/60 transition-colors">
                      <td className="px-2 py-1.5 text-slate-400 tabular-nums">{idx + 1}</td>
                      <td className="px-2 py-1.5 font-mono text-slate-500">{it.product?.product_code}</td>
                      <td className="px-2 py-1.5 font-medium text-slate-700">
                        <div className="flex items-center gap-1.5">
                          {it.is_scanned && (it.pieces?.length ?? 0) > 0 && (
                            <button
                              type="button"
                              onClick={() => setExpanded((x) => ({ ...x, [it.id]: !x[it.id] }))}
                              className="shrink-0 rounded p-0.5 text-indigo-400 hover:bg-indigo-50 transition-colors"
                              title={expanded[it.id] ? 'Hide pieces' : 'Show pieces'}
                            >
                              {expanded[it.id] ? <ChevronDown size={11} /> : <ChevronRight size={11} />}
                            </button>
                          )}
                          {it.product?.name}
                          {it.is_scanned && (
                            <span className="rounded-full bg-indigo-100 px-1.5 py-px text-[9px] font-bold text-indigo-600">
                              <QrCode size={8} className="mr-0.5 inline" />
                              {it.pieces?.length ?? 0} pcs
                            </span>
                          )}
                        </div>
                      </td>
                      <td className="px-2 py-1.5 text-slate-600">{it.attribute?.name || <span className="italic text-slate-300">—</span>}</td>
                      <td className="px-2 py-1.5 text-slate-500">{it.unit?.name}</td>
                      <td className="px-2 py-1.5 text-right text-slate-600"><Money value={it.unit_price} /></td>
                      <td className="px-2 py-1.5 text-right text-slate-600 tabular-nums">{Number(it.quantity).toLocaleString(undefined, { maximumFractionDigits: 4 })}</td>
                      <td className="px-2 py-1.5 text-right tabular-nums">
                        <span className={Number(it.quantity_delivered) >= Number(it.quantity) - 0.0001 && Number(it.quantity) > 0 ? 'font-bold text-green-600' : 'text-slate-500'}>
                          {Number(it.quantity_delivered ?? 0).toLocaleString(undefined, { maximumFractionDigits: 4 })}
                        </span>
                      </td>
                      <td className="px-2 py-1.5 text-right text-amber-600 tabular-nums">{Number(it.discount) > 0 ? Number(it.discount) : '—'}</td>
                      <td className="px-2 py-1.5 text-right text-sky-600 tabular-nums">{Number(it.tax) > 0 ? Number(it.tax) : '—'}</td>
                      <td className="px-2 py-1.5 text-right font-bold text-slate-800"><Money value={it.line_total} /></td>
                    </tr>
                    {it.is_scanned && expanded[it.id] && (
                      <tr>
                        <td></td>
                        <td colSpan={10} className="px-2 pb-2">
                          <div className="rounded border border-indigo-100 bg-indigo-50/40 px-2 py-1">
                            <table className="w-full text-[11px]">
                              <thead>
                                <tr className="text-left text-[9px] font-bold uppercase tracking-wider text-indigo-400">
                                  <th className="py-0.5 pr-2">Piece Code</th>
                                  <th className="py-0.5 pr-2">Roll No</th>
                                  <th className="py-0.5 pr-2 text-right">Weight</th>
                                </tr>
                              </thead>
                              <tbody>
                                {(it.pieces ?? []).map((p) => (
                                  <tr key={p.id} className="text-slate-600">
                                    <td className="py-0.5 pr-2 font-mono">{p.piece_code}</td>
                                    <td className="py-0.5 pr-2">{p.roll_no || '—'}</td>
                                    <td className="py-0.5 pr-2 text-right tabular-nums">{Number(p.weight).toLocaleString(undefined, { maximumFractionDigits: 4 })}</td>
                                    <td className="py-0.5 pl-2 text-right">
                                      {p.delivered
                                        ? <span className="rounded-full bg-green-100 px-1.5 py-px text-[9px] font-bold text-green-700">Delivered</span>
                                        : <span className="rounded-full bg-indigo-100 px-1.5 py-px text-[9px] font-bold text-indigo-600">Reserved</span>}
                                    </td>
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
                  <td colSpan={9} className="px-3 py-1.5">
                    <div className="flex items-center gap-4 text-xs">
                      <span className="font-bold uppercase tracking-wider text-indigo-600">Summary</span>
                      <span className="text-slate-500">Sub Total: <Money value={so.subtotal} className="font-bold text-slate-700" /></span>
                      <span className="text-slate-500">Transport: <Money value={so.transport_charge} className="font-bold text-slate-700" /></span>
                      <span className="text-slate-500">Total Qty: <span className="font-bold text-slate-700">{so.total_quantity != null ? Number(so.total_quantity).toLocaleString(undefined, { maximumFractionDigits: 2 }) : '—'}</span></span>
                    </div>
                  </td>
                  <td colSpan={2} className="px-3 py-1.5 text-right">
                    <div className="flex flex-col items-end leading-tight">
                      <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-500">Net Total</span>
                      <Money value={so.grand_total} className="text-base font-black text-indigo-700" />
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

function FragmentRow({ children }) {
  return <>{children}</>
}
