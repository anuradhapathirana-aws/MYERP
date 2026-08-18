import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { deleteSalesOrder, getSalesOrders } from '../../api/salesOrders'
import { getAllCustomers } from '../../api/customers'
import { getUsersAll } from '../../api/users'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Sales' },
  { label: 'Sales Orders' },
]

const INITIAL_FILTERS = { search: '', status: '', customer_id: '', sales_person_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-slate-100 text-slate-600',
  confirmed: 'bg-indigo-100 text-indigo-700',
  completed: 'bg-green-100 text-green-700',
  cancelled: 'bg-red-100 text-red-500',
}

const STATUS_OPTIONS = [
  { value: 'draft',     label: 'Draft' },
  { value: 'confirmed', label: 'Confirmed' },
  { value: 'completed', label: 'Completed' },
  { value: 'cancelled', label: 'Cancelled' },
]

export default function SalesOrdersPage() {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
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

  const { data: users = [] } = useQuery({
    queryKey: ['users-all'],
    queryFn:  getUsersAll,
    staleTime: Infinity,
  })
  const userOptions = users.map((u) => ({ value: u.id, label: u.name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['sales-orders', page, applied],
    queryFn:  () => getSalesOrders(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteSalesOrder,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['sales-orders'] }); showSuccess('Sales order deleted.') },
    onError:    () => showError('Cannot delete — only draft sales orders can be removed.'),
  })

  const handleDelete = async (id, soNo) => {
    if (await confirmDelete(soNo)) deleteMutation.mutate(id)
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Sales Orders</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/sales-orders/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New Sales Order
        </Link>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="SO No, ref or customer…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
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
        <FilterField label="Sales Person">
          <FilterSearchSelect
            value={draft.sales_person_id}
            onChange={(val) => setDraft((d) => ({ ...d, sales_person_id: val }))}
            options={userOptions}
            placeholder="All sales persons"
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
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load sales orders.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">SO No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Sales Person</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Order Date</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Expected</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Qty</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Net Total</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No sales orders match the current filters.' : (
                          <><Link to="/inventory/sales-orders/create" className="font-medium text-indigo-600 hover:underline">Create the first sales order.</Link></>
                        )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((so, i) => (
                      <tr
                        key={so.id}
                        onClick={() => navigate(`/inventory/sales-orders/${so.id}`)}
                        className="cursor-pointer transition-colors hover:bg-slate-50"
                      >
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/sales-orders/${so.id}`} className="hover:underline">{so.so_no}</Link>
                          {so.reference_no && (
                            <div className="text-[9px] font-normal text-slate-400">Ref: {so.reference_no}</div>
                          )}
                        </td>
                        <td className="px-3 py-2 font-medium text-slate-700">
                          {so.customer?.name || <span className="italic text-slate-300">—</span>}
                          {so.customer_type && <span className="ml-1 text-[9px] font-normal text-slate-400">({so.customer_type})</span>}
                        </td>
                        <td className="max-w-32 truncate px-3 py-2 text-slate-600" title={so.sales_person?.name || ''}>
                          {so.sales_person?.name || <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{so.order_date}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{so.expected_date || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-right text-slate-600 tabular-nums">
                          {so.total_quantity != null ? Number(so.total_quantity).toLocaleString(undefined, { maximumFractionDigits: 2 }) : '—'}
                        </td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700"><Money value={so.grand_total} /></td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[so.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {so.status_label}
                          </span>
                        </td>
                        {/* stopPropagation so action buttons don't also fire the row's navigate */}
                        <td className="px-3 py-2" onClick={(e) => e.stopPropagation()}>
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/sales-orders/${so.id}`} />
                            {(so.status === 'draft' || so.status === 'confirmed') && (
                              <EditBtn to={`/inventory/sales-orders/${so.id}/edit`} />
                            )}
                            {so.status === 'draft' && (
                              <DeleteBtn onClick={() => handleDelete(so.id, so.so_no)} disabled={deleteMutation.isPending} />
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
