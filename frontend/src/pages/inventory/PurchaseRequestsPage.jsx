import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle, Plus, XCircle } from 'lucide-react'
import {
  approvePurchaseRequest,
  deletePurchaseRequest,
  getPurchaseRequests,
  rejectPurchaseRequest,
} from '../../api/purchaseRequests'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, confirmAction, confirmWithReason, showError, showSuccess } from '../../utils/alerts'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Purchasing' },
  { label: 'Purchase Requests' },
]

const INITIAL_FILTERS = { search: '', status: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-slate-100 text-slate-600',
  submitted: 'bg-amber-100 text-amber-700',
  approved:  'bg-green-100 text-green-700',
  rejected:  'bg-red-100 text-red-700',
  cancelled: 'bg-slate-100 text-slate-400',
  completed: 'bg-blue-100 text-blue-700',
}

const STATUS_OPTIONS = [
  { value: 'draft',     label: 'Draft' },
  { value: 'submitted', label: 'Pending Approval' },
  { value: 'approved',  label: 'Approved' },
  { value: 'rejected',  label: 'Rejected' },
  { value: 'cancelled', label: 'Cancelled' },
  { value: 'completed', label: 'Completed' },
]

export default function PurchaseRequestsPage() {
  const [page, setPage]     = useState(1)
  const queryClient         = useQueryClient()
  const resetPage           = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['purchase-requests', page, applied],
    queryFn:  () => getPurchaseRequests(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deletePurchaseRequest,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['purchase-requests'] }); showSuccess('Purchase request deleted.') },
    onError:    () => showError('Cannot delete — only draft PRs can be removed.'),
  })

  const approveMutation = useMutation({
    mutationFn: ({ id }) => approvePurchaseRequest(id),
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['purchase-requests'] }); showSuccess('Purchase request approved.') },
    onError:    () => showError('Approval failed.'),
  })

  const rejectMutation = useMutation({
    mutationFn: ({ id, reason }) => rejectPurchaseRequest(id, reason),
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['purchase-requests'] }); showSuccess('Purchase request rejected.') },
    onError:    () => showError('Rejection failed.'),
  })

  const handleDelete = async (id, prNo) => {
    if (await confirmDelete(prNo)) deleteMutation.mutate(id)
  }

  const handleApprove = async (id, prNo) => {
    const ok = await confirmAction({
      title: `Approve ${prNo}?`,
      message: 'This PR will be marked as Approved and a PO can be created from it.',
      confirmText: 'Yes, Approve',
    })
    if (ok) approveMutation.mutate({ id })
  }

  const handleReject = async (id, prNo) => {
    const reason = await confirmWithReason({
      title: `Reject ${prNo}?`,
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Purchase Requests</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/purchase-requests/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New PR
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
            placeholder="PR No or purpose…"
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
        <FilterField label="Date From">
          <input
            type="date"
            className={FILTER_INPUT_CLS}
            value={draft.date_from}
            onChange={(e) => setDraft((d) => ({ ...d, date_from: e.target.value }))}
          />
        </FilterField>
        <FilterField label="Date To">
          <input
            type="date"
            className={FILTER_INPUT_CLS}
            value={draft.date_to}
            onChange={(e) => setDraft((d) => ({ ...d, date_to: e.target.value }))}
          />
        </FilterField>
      </TableFilter>

      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load purchase requests.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">PR No</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Request Date</th>
                    <th className="w-44 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Target Store</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Required Date</th>
                    <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total Qty</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No purchase requests match the current filters.' : (
                          <>No purchase requests yet.{' '}<Link to="/inventory/purchase-requests/create" className="font-medium text-indigo-600 hover:underline">Create the first one.</Link></>
                        )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((pr, i) => (
                      <tr key={pr.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/purchase-requests/${pr.id}`} className="hover:underline">{pr.pr_no}</Link>
                          {pr.reference_no && (
                            <div className="text-[9px] font-normal text-slate-400">Ref: {pr.reference_no}</div>
                          )}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{pr.request_date}</td>
                        <td
                          className="max-w-44 truncate px-3 py-2 text-slate-700"
                          title={pr.target_store?.name || pr.source_store?.name || ''}
                        >
                          {pr.target_store?.name || pr.source_store?.name || <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{pr.required_date || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700">
                          {pr.total_quantity != null ? Number(pr.total_quantity).toLocaleString() : '—'}
                        </td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[pr.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {pr.status_label}
                          </span>
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/purchase-requests/${pr.id}`} />
                            {pr.status === 'draft' && (
                              <EditBtn to={`/inventory/purchase-requests/${pr.id}/edit`} />
                            )}
                            {pr.status === 'submitted' && (
                              <>
                                <button
                                  type="button"
                                  title="Approve"
                                  onClick={() => handleApprove(pr.id, pr.pr_no)}
                                  className="inline-flex items-center justify-center rounded-lg p-1.5 bg-green-50 text-green-600 hover:bg-green-100 transition-colors"
                                >
                                  <CheckCircle size={14} strokeWidth={2} />
                                </button>
                                <button
                                  type="button"
                                  title="Reject"
                                  onClick={() => handleReject(pr.id, pr.pr_no)}
                                  className="inline-flex items-center justify-center rounded-lg p-1.5 bg-red-50 text-red-500 hover:bg-red-100 transition-colors"
                                >
                                  <XCircle size={14} strokeWidth={2} />
                                </button>
                              </>
                            )}
                            {pr.status === 'draft' && (
                              <DeleteBtn onClick={() => handleDelete(pr.id, pr.pr_no)} disabled={deleteMutation.isPending} />
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
