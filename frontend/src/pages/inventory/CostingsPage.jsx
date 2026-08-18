import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { CheckCircle, Plus } from 'lucide-react'
import { confirmCosting, deleteCosting, getCostings } from '../../api/costings'
import { getAllSuppliers } from '../../api/suppliers'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, confirmAction, showError, showSuccess } from '../../utils/alerts'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'
import Pagination from '../../components/ui/Pagination'
import Money from '../../components/ui/Money'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Purchasing' },
  { label: 'Costings' },
]

const INITIAL_FILTERS = { search: '', costing_type: '', status: '', supplier_id: '', date_from: '', date_to: '' }

const STATUS_STYLES = {
  draft:     'bg-amber-100 text-amber-700',
  confirmed: 'bg-green-100 text-green-700',
}

const TYPE_STYLES = {
  fob: 'bg-blue-100 text-blue-700',
  cif: 'bg-purple-100 text-purple-700',
}

export default function CostingsPage() {
  const navigate = useNavigate()
  const [page, setPage] = useState(1)
  const queryClient     = useQueryClient()
  const resetPage       = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const { data: suppliersData } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn:  getAllSuppliers,
    staleTime: Infinity,
  })
  const supplierOptions = (suppliersData ?? []).map((s) => ({ value: s.id, label: s.name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['costings', page, applied],
    queryFn:  () => getCostings(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCosting,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['costings'] }); showSuccess('Costing deleted.') },
    onError:    () => showError('Cannot delete — only draft costings can be removed.'),
  })

  const confirmMutation = useMutation({
    mutationFn: confirmCosting,
    onSuccess:  () => { queryClient.invalidateQueries({ queryKey: ['costings'] }); showSuccess('Costing confirmed successfully.') },
    onError:    (err) => showError(err.response?.data?.message ?? 'Confirmation failed.'),
  })

  const handleDelete = async (id, docNo) => {
    if (await confirmDelete(docNo)) deleteMutation.mutate(id)
  }

  const handleConfirm = async (id, docNo) => {
    const ok = await confirmAction({
      title: `Confirm ${docNo}?`,
      message: 'This will <strong>lock the costing</strong> and finalize the landed cost calculation. This action cannot be undone.',
      confirmText: 'Yes, Confirm Costing',
    })
    if (ok) confirmMutation.mutate(id)
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Costings</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <Link
          to="/inventory/costings/create"
          className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
        >
          <Plus size={14} strokeWidth={2.5} />
          New Costing
        </Link>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Document No, Ref No or Bill of Lading…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Type">
          <select className={FILTER_SELECT_CLS} value={draft.costing_type} onChange={(e) => setDraft((d) => ({ ...d, costing_type: e.target.value }))}>
            <option value="">All types</option>
            <option value="fob">FOB</option>
            <option value="cif">CIF</option>
          </select>
        </FilterField>
        <FilterField label="Status">
          <select className={FILTER_SELECT_CLS} value={draft.status} onChange={(e) => setDraft((d) => ({ ...d, status: e.target.value }))}>
            <option value="">All statuses</option>
            <option value="draft">Draft</option>
            <option value="confirmed">Confirmed</option>
          </select>
        </FilterField>
        <FilterField label="Supplier">
          <FilterSearchSelect
            value={draft.supplier_id}
            onChange={(val) => setDraft((d) => ({ ...d, supplier_id: val }))}
            options={supplierOptions}
            placeholder="All suppliers"
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
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load costings.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Document No</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Reference No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-16 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Trans. Date</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total w/ VAT</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-32 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">
                        {activeCount > 0 ? 'No costings match the current filters.' : 'No costings created yet.'}
                      </td>
                    </tr>
                  ) : (
                    rows.map((c, i) => (
                      <tr
                        key={c.id}
                        onClick={() => navigate(`/inventory/costings/${c.id}`)}
                        className="cursor-pointer transition-colors hover:bg-slate-50"
                      >
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 25) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">
                          <Link to={`/inventory/costings/${c.id}`} className="hover:underline">{c.document_no}</Link>
                        </td>
                        <td className="px-3 py-2 font-mono text-slate-500">{c.reference_no}</td>
                        <td className="px-3 py-2 font-medium text-slate-700">{c.supplier?.name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold ${TYPE_STYLES[c.costing_type] ?? 'bg-slate-100 text-slate-500'}`}>
                            {c.costing_type_short}
                          </span>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{c.transaction_date || '—'}</td>
                        <td className="px-3 py-2 text-right font-semibold text-slate-700">
                          <Money value={c.total_price_with_vat} />
                        </td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold ${STATUS_STYLES[c.status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {c.status_label}
                          </span>
                        </td>
                        {/* stopPropagation so action buttons don't also fire the row's navigate */}
                        <td className="px-3 py-2" onClick={(e) => e.stopPropagation()}>
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/costings/${c.id}`} />
                            {c.status === 'draft' && (
                              <>
                                <EditBtn to={`/inventory/costings/${c.id}/edit`} />
                                <button type="button" title="Confirm Costing" onClick={() => handleConfirm(c.id, c.document_no)} disabled={confirmMutation.isPending} className="inline-flex items-center justify-center rounded-lg p-1.5 bg-green-50 text-green-600 hover:bg-green-100 transition-colors disabled:opacity-40">
                                  <CheckCircle size={14} strokeWidth={2} />
                                </button>
                                <DeleteBtn onClick={() => handleDelete(c.id, c.document_no)} disabled={deleteMutation.isPending} />
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
