import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle, Plus } from 'lucide-react'
import {
  confirmCustomerReceipt,
  deleteCustomerReceipt,
  getCustomerReceipts,
} from '../../api/customerReceipts'
import { getAllCustomers } from '../../api/customers'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, confirmAction, showError, showSuccess } from '../../utils/alerts'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Sales' },
  { label: 'Customer Receipts' },
]

const INITIAL_FILTERS = { search: '', status: '', customer_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  confirmed: 'bg-green-100 text-green-700',
}

export default function CustomerReceiptsPage() {
  const [page, setPage] = useState(1)
  const navigate        = useNavigate()
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
    queryKey: ['customer-receipts', page, applied],
    queryFn:  () => getCustomerReceipts(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCustomerReceipt,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['customer-receipts'] }); showSuccess('Receipt deleted.') },
    onError:    () => showError('Cannot delete — only draft receipts can be removed.'),
  })

  const confirmMutation = useMutation({
    mutationFn: confirmCustomerReceipt,
    onSuccess:  () => {
      queryClient.invalidateQueries({ queryKey: ['customer-receipts'] })
      queryClient.invalidateQueries({ queryKey: ['invoices'] })
      showSuccess('Receipt confirmed. Invoice outstanding balances updated.')
    },
    onError: (err) => showError(err.response?.data?.message ?? 'Confirmation failed.'),
  })

  const handleDelete = async (id, receiptNo) => {
    if (await confirmDelete(receiptNo)) deleteMutation.mutate(id)
  }

  const handleConfirm = async (id, receiptNo) => {
    const ok = await confirmAction({
      title: `Confirm ${receiptNo}?`,
      message: 'This will <strong>settle against outstanding invoice balances</strong>, post any setoffs and mark fully-received invoices as Paid. This action cannot be easily undone.',
      confirmText: 'Yes, Confirm Receipt',
    })
    if (ok) confirmMutation.mutate(id)
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Customer Receipts</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/customer-receipts/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New Receipt
        </Link>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Receipt No or Reference No…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="confirmed">Confirmed</option>
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

      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load customer receipts.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Receipt No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Customer</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Gross</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Setoff</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Net</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No receipts match the current filters.' : 'No customer receipts yet.'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((r, i) => (
                      <tr
                        key={r.id}
                        onClick={() => navigate(`/inventory/customer-receipts/${r.id}/edit`)}
                        className="cursor-pointer transition-colors hover:bg-slate-50"
                      >
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/customer-receipts/${r.id}/edit`} className="hover:underline">{r.receipt_no}</Link>
                          {r.is_advance && <span className="ml-1 rounded bg-sky-50 px-1 py-0.5 text-[9px] font-bold text-sky-600">ADV</span>}
                        </td>
                        <td className="px-3 py-2 font-medium text-slate-700">{r.customer?.name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{r.receipt_date}</td>
                        <td className="px-3 py-2 text-right text-slate-600"><Money value={r.gross_amount} /></td>
                        <td className="px-3 py-2 text-right text-slate-600"><Money value={r.setoff_amount} /></td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700"><Money value={r.net_amount} /></td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[r.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {r.status_label}
                          </span>
                        </td>
                        {/* stopPropagation so action buttons don't also fire the row's navigate */}
                        <td className="px-3 py-2" onClick={(e) => e.stopPropagation()}>
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/customer-receipts/${r.id}/edit`} />
                            {r.status === 'draft' && (
                              <>
                                <EditBtn to={`/inventory/customer-receipts/${r.id}/edit`} />
                                <button type="button" title="Confirm Receipt" onClick={() => handleConfirm(r.id, r.receipt_no)} disabled={confirmMutation.isPending} className="inline-flex items-center justify-center rounded-lg p-1.5 bg-green-50 text-green-600 hover:bg-green-100 transition-colors disabled:opacity-40"><CheckCircle size={14} strokeWidth={2} /></button>
                                <DeleteBtn onClick={() => handleDelete(r.id, r.receipt_no)} disabled={deleteMutation.isPending} />
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
