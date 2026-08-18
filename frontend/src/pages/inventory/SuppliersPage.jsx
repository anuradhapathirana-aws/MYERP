import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { deleteSupplier, getSuppliers } from '../../api/suppliers'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Suppliers' },
]

const INITIAL_FILTERS = { search: '', supplier_type: '', mobile: '', bil_city: '', bil_country: '' }

const SUPPLIER_TYPES = ['Local', 'Foreign', 'Service', 'Manufacturer', 'Distributor', 'Other']

export default function SuppliersPage() {
  const [page, setPage] = useState(1)
  const queryClient    = useQueryClient()
  const { can }        = usePermissions()

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const resetPage = () => setPage(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['suppliers', page, applied],
    queryFn:  () => getSuppliers(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteSupplier,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['suppliers'] })
      showSuccess('Supplier deleted.')
    },
    onError: () => showError('Failed to delete. The supplier may be in use.'),
  })

  const handleDelete = async (id, name) => {
    const ok = await confirmDelete(name)
    if (ok) deleteMutation.mutate(id)
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Suppliers</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_supplier_masters') && (
          <Link
            to="/inventory/suppliers/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            New Supplier
          </Link>
        )}
      </div>
      {/* ── Filter Panel ── */}
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
            placeholder="Name, code or email…"
            value={draft.search}
            onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Supplier Type">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.supplier_type}
            onChange={(e) => setDraft((d) => ({ ...d, supplier_type: e.target.value }))}
          >
            <option value="">All types</option>
            {SUPPLIER_TYPES.map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </select>
        </FilterField>

        <FilterField label="Mobile">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Mobile number…"
            value={draft.mobile}
            onChange={(e) => setDraft((d) => ({ ...d, mobile: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Billing City">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="City…"
            value={draft.bil_city}
            onChange={(e) => setDraft((d) => ({ ...d, bil_city: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Billing Country">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Country…"
            value={draft.bil_country}
            onChange={(e) => setDraft((d) => ({ ...d, bil_country: e.target.value }))}
          />
        </FilterField>
      </TableFilter>

      {/* ── Data Table ── */}
      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && (
          <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
        )}
        {isError && (
          <div className="flex items-center justify-center py-14 text-sm text-red-500">
            Failed to load suppliers.
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier Name</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Mobile</th>
                    <th className="w-44 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Email</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-8 text-center text-sm text-slate-400">
                        {activeCount > 0
                          ? 'No suppliers match the current filters.'
                          : (
                            <>
                              No suppliers yet.{' '}
                              {can('create_supplier_masters') && (
                                <Link to="/inventory/suppliers/create" className="font-medium text-indigo-600 hover:underline">
                                  Add the first one.
                                </Link>
                              )}
                            </>
                          )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((s, i) => (
                      <tr key={s.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>
                        <td className="px-3 py-2 font-mono text-slate-500">
                          {s.supplier_code ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="max-w-55 px-3 py-2 font-medium text-slate-800">
                          <Link
                            to={`/inventory/suppliers/${s.id}`}
                            className="truncate hover:text-indigo-600 hover:underline"
                          >
                            {s.supplier_name}
                          </Link>
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {s.supplier_type ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {s.mobile ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="max-w-44 truncate px-3 py-2 text-slate-500">
                          {s.email ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                          {new Date(s.created_at).toLocaleDateString()}
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/suppliers/${s.id}`} />
                            {can('edit_supplier_masters') && (
                              <EditBtn to={`/inventory/suppliers/${s.id}/edit`} />
                            )}
                            {can('delete_supplier_masters') && (
                              <DeleteBtn
                                onClick={() => handleDelete(s.id, s.supplier_name)}
                                disabled={deleteMutation.isPending}
                              />
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
