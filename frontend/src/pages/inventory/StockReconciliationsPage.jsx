import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle, Plus, XCircle } from 'lucide-react'
import {
  approveStockReconciliation,
  deleteStockReconciliation,
  getStockReconciliations,
  rejectStockReconciliation,
} from '../../api/stockReconciliations'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, confirmAction, confirmWithReason, showError, showSuccess } from '../../utils/alerts'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Stock Reconciliations' },
]

const INITIAL_FILTERS = { search: '', status: '' }

const STATUS_STYLES = {
  draft:             'bg-slate-100 text-slate-600',
  pending_approval:  'bg-amber-100 text-amber-700',
  approved:          'bg-green-100 text-green-700',
  rejected:          'bg-red-100 text-red-700',
}

const STATUS_OPTIONS = [
  { value: 'draft',            label: 'Draft' },
  { value: 'pending_approval', label: 'Pending Approval' },
  { value: 'approved',         label: 'Approved' },
  { value: 'rejected',         label: 'Rejected' },
]

export default function StockReconciliationsPage() {
  const [page, setPage] = useState(1)
  const queryClient      = useQueryClient()
  const resetPage        = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['stock-reconciliations', page, applied],
    queryFn:  () => getStockReconciliations(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteStockReconciliation,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['stock-reconciliations'] }); showSuccess('Reconciliation deleted.') },
    onError:    () => showError('Cannot delete — only draft reconciliations can be removed.'),
  })

  const approveMutation = useMutation({
    mutationFn: (id) => approveStockReconciliation(id),
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['stock-reconciliations'] }); showSuccess('Reconciliation approved — stock updated.') },
    onError:    (err) => showError(err?.response?.data?.message || 'Approval failed.'),
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }) => rejectStockReconciliation(id, reason),
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['stock-reconciliations'] }); showSuccess('Reconciliation rejected.') },
    onError:    (err) => showError(err?.response?.data?.message || 'Rejection failed.'),
  })

  const handleDelete = async (id, no) => {
    if (await confirmDelete(no)) deleteMutation.mutate(id)
  }

  const handleApprove = async (id, no) => {
    const ok = await confirmAction({
      title: `Approve ${no}?`,
      message: 'This will post the correction to the stock ledger, update the roll weight(s), and cannot be undone by rejecting afterwards.',
      confirmText: 'Yes, Approve',
    })
    if (ok) approveMutation.mutate(id)
  }

  const handleReject = async (id, no) => {
    const reason = await confirmWithReason({
      title: `Reject ${no}?`,
      inputLabel: 'Reason for rejection',
      inputPlaceholder: 'Enter rejection reason…',
      confirmText: 'Reject',
    })
    if (reason !== null) rejectMutation.mutate({ id, reason })
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Stock Reconciliations</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/stock-reconciliations/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New Reconciliation
        </Link>
      </div>

      <TableFilter
        open={open}
        onToggle={toggle}
        onApply={() => apply(resetPage)}
        onClear={() => clear(resetPage)}
        activeCount={activeCount}
      >
        <FilterField label="Search">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Reconciliation No or reason…"
            value={draft.search}
            onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))}
          />
        </FilterField>
        <FilterField label="Status">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.status}
            onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}
          >
            <option value="">All statuses</option>
            {STATUS_OPTIONS.map((s) => (
              <option key={s.value} value={s.value}>{s.label}</option>
            ))}
          </select>
        </FilterField>
      </TableFilter>

      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load reconciliations.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Reconciliation No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Product</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Store</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Variance</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Reason</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No reconciliations match the current filters.' : (
                          <>No stock reconciliations yet.{' '}<Link to="/inventory/stock-reconciliations/create" className="font-medium text-indigo-600 hover:underline">Create the first one.</Link></>
                        )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((r, i) => (
                      <tr key={r.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/stock-reconciliations/${r.id}`} className="hover:underline">{r.reconciliation_no}</Link>
                        </td>
                        <td className="max-w-56 truncate px-3 py-2 text-slate-700" title={r.product?.name}>
                          {r.product?.name ?? '—'}
                          {r.product?.product_code && <span className="ml-1 font-mono text-[10px] text-slate-400">({r.product.product_code})</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-600">{r.store?.name ?? '—'}</td>
                        <td className={`px-3 py-2 text-right font-semibold tabular-nums ${r.variance_qty_base > 0 ? 'text-emerald-600' : r.variance_qty_base < 0 ? 'text-red-600' : 'text-slate-500'}`}>
                          {r.variance_qty_base > 0 ? '+' : ''}{Number(r.variance_qty_base).toLocaleString(undefined, { maximumFractionDigits: 4 })}
                        </td>
                        <td className="max-w-56 truncate px-3 py-2 text-slate-500" title={r.reason}>{r.reason}</td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[r.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {r.status_label}
                          </span>
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/stock-reconciliations/${r.id}`} />
                            {r.status === 'draft' && (
                              <EditBtn to={`/inventory/stock-reconciliations/${r.id}/edit`} />
                            )}
                            {r.status === 'pending_approval' && (
                              <>
                                <button
                                  type="button"
                                  title="Approve"
                                  onClick={() => handleApprove(r.id, r.reconciliation_no)}
                                  className="inline-flex items-center justify-center rounded-lg p-1.5 bg-green-50 text-green-600 hover:bg-green-100 transition-colors"
                                >
                                  <CheckCircle size={14} strokeWidth={2} />
                                </button>
                                <button
                                  type="button"
                                  title="Reject"
                                  onClick={() => handleReject(r.id, r.reconciliation_no)}
                                  className="inline-flex items-center justify-center rounded-lg p-1.5 bg-red-50 text-red-500 hover:bg-red-100 transition-colors"
                                >
                                  <XCircle size={14} strokeWidth={2} />
                                </button>
                              </>
                            )}
                            {r.status === 'draft' && (
                              <DeleteBtn onClick={() => handleDelete(r.id, r.reconciliation_no)} disabled={deleteMutation.isPending} />
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
