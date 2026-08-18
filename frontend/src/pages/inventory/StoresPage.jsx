import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { deleteStore, getStores } from '../../api/stores'
import { getAllStoreTypes } from '../../api/storeTypes'
import { getAllLocations } from '../../api/locations'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/stores' },
  { label: 'Stores' },
]

const INITIAL_FILTERS = { search: '', store_type_id: '', is_active: '', location_id: '' }

export default function StoresPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const resetPage = () => setPage(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['stores', page, applied],
    queryFn: () => getStores(page, applied),
    placeholderData: (prev) => prev,
  })

  const { data: storeTypes = [] } = useQuery({
    queryKey: ['store-types-all'],
    queryFn: getAllStoreTypes,
    staleTime: 5 * 60 * 1000,
  })

  const { data: locations = [] } = useQuery({
    queryKey: ['locations-all'],
    queryFn: getAllLocations,
    staleTime: 5 * 60 * 1000,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteStore,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['stores'] })
      showSuccess('Store deleted.')
    },
    onError: () => showError('Failed to delete. The store may be in use.'),
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Stores</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_stores') && (
          <Link
            to="/inventory/stores/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            New Store
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
            placeholder="Name or code…"
            value={draft.search}
            onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Store Type">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.store_type_id}
            onChange={(e) => setDraft((d) => ({ ...d, store_type_id: e.target.value }))}
          >
            <option value="">All types</option>
            {storeTypes.map((t) => (
              <option key={t.id} value={t.id}>{t.store_type_name}</option>
            ))}
          </select>
        </FilterField>

        <FilterField label="Status">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.is_active}
            onChange={(e) => setDraft((d) => ({ ...d, is_active: e.target.value }))}
          >
            <option value="">All statuses</option>
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </FilterField>

        <FilterField label="Location">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.location_id}
            onChange={(e) => setDraft((d) => ({ ...d, location_id: e.target.value }))}
          >
            <option value="">All locations</option>
            {locations.map((l) => (
              <option key={l.id} value={l.id}>{l.location_name}</option>
            ))}
          </select>
        </FilterField>

      </TableFilter>

      {/* ── Data Table ── */}
      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && (
          <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
        )}
        {isError && (
          <div className="flex items-center justify-center py-14 text-sm text-red-500">
            Failed to load stores. Check that the backend is running.
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
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Store Name</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-36 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Location</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Capacity</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>

                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-8 text-center text-sm text-slate-400">
                        {activeCount > 0
                          ? 'No stores match the current filters.'
                          : (
                            <>
                              No stores yet.{' '}
                              {can('create_stores') && (
                                <Link to="/inventory/stores/create" className="font-medium text-indigo-600 hover:underline">
                                  Create the first one.
                                </Link>
                              )}
                            </>
                          )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((row, i) => (
                      <tr key={row.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>
                        <td className="px-3 py-2 font-mono text-slate-500">{row.store_code}</td>
                        <td className="max-w-50 px-3 py-2 font-medium text-slate-800">
                          {can('edit_stores') ? (
                            <Link
                              to={`/inventory/stores/${row.id}/edit`}
                              className="truncate hover:text-indigo-600 hover:underline"
                            >
                              {row.store_name}
                            </Link>
                          ) : (
                            <span className="truncate">{row.store_name}</span>
                          )}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {row.store_type?.name ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="max-w-36 truncate px-3 py-2 text-slate-500">
                          {row.location?.name ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700">
                          {Number(row.capacity).toLocaleString(undefined, { maximumFractionDigits: 2 })}
                        </td>
                        <td className="px-3 py-2">
                          {row.is_active ? (
                            <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                          ) : (
                            <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Inactive</span>
                          )}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                          {new Date(row.created_at).toLocaleDateString()}
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            {can('edit_stores') && (
                              <EditBtn to={`/inventory/stores/${row.id}/edit`} />
                            )}
                            {can('delete_stores') && (
                              <DeleteBtn onClick={() => handleDelete(row.id, row.store_name)} disabled={deleteMutation.isPending} />
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
