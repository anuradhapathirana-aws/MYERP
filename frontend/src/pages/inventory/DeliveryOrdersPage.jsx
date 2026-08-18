import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { deleteDeliveryOrder, downloadDoPdf, getDeliveryOrders } from '../../api/deliveryOrders'
import { getAllCustomers } from '../../api/customers'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'
import { ViewBtn, EditBtn, DeleteBtn, PrintBtn, PdfBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Sales' },
  { label: 'Delivery Orders' },
]

const INITIAL_FILTERS = { search: '', status: '', customer_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  confirmed: 'bg-green-100 text-green-700',
  cancelled: 'bg-red-100 text-red-500',
}

const STATUS_OPTIONS = [
  { value: 'draft',     label: 'Draft' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'cancelled', label: 'Cancelled' },
]

export default function DeliveryOrdersPage() {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const [pdfBusy, setPdfBusy] = useState(null)
  const queryClient     = useQueryClient()
  const resetPage       = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const { data: customers = [] } = useQuery({
    queryKey: ['customers-all'],
    queryFn:  getAllCustomers,
    staleTime: Infinity,
  })
  const customerOptions = customers.map((c) => ({ value: c.id, label: c.name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['delivery-orders', page, applied],
    queryFn:  () => getDeliveryOrders(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteDeliveryOrder,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['delivery-orders'] }); showSuccess('Delivery order deleted.') },
    onError:    (e) => showError(e.response?.data?.message ?? 'Cannot delete — only draft delivery orders can be removed.'),
  })

  const handleDelete = async (id, doNo) => {
    if (await confirmDelete(doNo)) deleteMutation.mutate(id)
  }

  const handlePrintPdf = async (id) => {
    setPdfBusy(id)
    try {
      printPdfBlob(await downloadDoPdf(id))
    } catch {
      showError('Failed to print PDF.')
    } finally {
      setPdfBusy(null)
    }
  }

  const handleDownloadPdf = async (id, doNo) => {
    setPdfBusy(id)
    try {
      const blob = await downloadDoPdf(id)
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      a.download = `DO_${doNo}.pdf`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      showError('Failed to download PDF.')
    } finally {
      setPdfBusy(null)
    }
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Delivery Orders</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/delivery-orders/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New Delivery Order
        </Link>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="DO No, SO No or customer…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            {STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </FilterField>
        <FilterField label="Customer">
          <FilterSearchSelect
            value={draft.customer_id}
            onChange={(val) => setDraft((d) => ({ ...d, customer_id: val }))}
            options={customerOptions}
            placeholder="All customers"
          />
        </FilterField>
        <FilterField label="Date From">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_from} onChange={(e) => setDraft((d) => ({ ...d, date_from: e.target.value }))} />
        </FilterField>
        <FilterField label="Date To">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_to} onChange={(e) => setDraft((d) => ({ ...d, date_to: e.target.value }))} />
        </FilterField>
      </TableFilter>

      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load delivery orders.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">DO No</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">SO No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Qty</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No delivery orders match the current filters.' : (
                          <><Link to="/inventory/delivery-orders/create" className="font-medium text-indigo-600 hover:underline">Create the first delivery order.</Link></>
                        )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((row, i) => (
                      <tr
                        key={row.id}
                        onClick={() => navigate(`/inventory/delivery-orders/${row.id}`)}
                        className="cursor-pointer transition-colors hover:bg-slate-50"
                      >
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/delivery-orders/${row.id}`} className="hover:underline">{row.do_no}</Link>
                        </td>
                        {/* stopPropagation so the SO link doesn't also fire the row's navigate */}
                        <td className="px-3 py-2 font-mono text-slate-500" onClick={(e) => row.sales_order?.so_no && e.stopPropagation()}>
                          {row.sales_order?.so_no ? (
                            <Link to={`/inventory/sales-orders/${row.so_id}`} className="hover:underline">{row.sales_order.so_no}</Link>
                          ) : '—'}
                        </td>
                        <td className="px-3 py-2 font-medium text-slate-700">{row.customer?.name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.delivery_date}</td>
                        <td className="px-3 py-2 text-right text-slate-600 tabular-nums">
                          {row.total_quantity != null ? Number(row.total_quantity).toLocaleString(undefined, { maximumFractionDigits: 2 }) : '—'}
                        </td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[row.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {row.status_label}
                          </span>
                        </td>
                        {/* stopPropagation so action buttons don't also fire the row's navigate */}
                        <td className="px-3 py-2" onClick={(e) => e.stopPropagation()}>
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/delivery-orders/${row.id}`} />
                            <PrintBtn onClick={() => handlePrintPdf(row.id)} disabled={pdfBusy === row.id} />
                            <PdfBtn onClick={() => handleDownloadPdf(row.id, row.do_no)} disabled={pdfBusy === row.id} />
                            {row.status === 'draft' && (
                              <>
                                <EditBtn to={`/inventory/delivery-orders/${row.id}/edit`} />
                                <DeleteBtn onClick={() => handleDelete(row.id, row.do_no)} disabled={deleteMutation.isPending} />
                              </>
                            )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            <Pagination meta={meta} page={page} onPageChange={setPage} />
          </>
        )}
      </div>
    </div>
  )
}
