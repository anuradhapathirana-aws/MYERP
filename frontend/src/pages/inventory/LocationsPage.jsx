import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { MapPin, Plus } from 'lucide-react'
import { deleteLocation, getLocations } from '../../api/locations'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Locations' },
]

const INITIAL_FILTERS = { search: '', type: '', city: '', country: '' }

const LOCATION_TYPES = ['HQ', 'Branch', 'Warehouse', 'Retail', 'Other']

export default function LocationsPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const resetPage = () => setPage(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['locations', page, applied],
    queryFn: () => getLocations(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteLocation,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['locations'] })
      showSuccess('Location deleted.')
    },
    onError: () => showError('Failed to delete. The location may be in use.'),
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Locations</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_locations') && (
          <Link
            to="/inventory/locations/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            New Location
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

        <FilterField label="Type">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.type}
            onChange={(e) => setDraft((d) => ({ ...d, type: e.target.value }))}
          >
            <option value="">All types</option>
            {LOCATION_TYPES.map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </select>
        </FilterField>

        <FilterField label="City">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="City…"
            value={draft.city}
            onChange={(e) => setDraft((d) => ({ ...d, city: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Country">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Country…"
            value={draft.country}
            onChange={(e) => setDraft((d) => ({ ...d, country: e.target.value }))}
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
            Failed to load locations.
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Location Name</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Company</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">City</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Country</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Currency</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="px-4 py-8 text-center text-sm text-slate-400">
                        {activeCount > 0
                          ? 'No locations match the current filters.'
                          : (
                            <>
                              No locations yet.{' '}
                              {can('create_locations') && (
                                <Link to="/inventory/locations/create" className="font-medium text-indigo-600 hover:underline">
                                  Create the first one.
                                </Link>
                              )}
                            </>
                          )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((loc, i) => (
                      <tr key={loc.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>
                        <td className="px-3 py-2 font-mono text-slate-600">{loc.location_code}</td>
                        <td className="px-3 py-2 font-medium text-slate-800">
                          <Link
                            to={`/inventory/locations/${loc.id}`}
                            className="flex items-center gap-1 hover:text-indigo-600 hover:underline"
                          >
                            <MapPin size={11} className="shrink-0 text-slate-400" />
                            {loc.location_name}
                          </Link>
                        </td>
                        <td className="max-w-30 truncate px-3 py-2 text-slate-500">
                          {loc.company?.name ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {loc.location_type ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {loc.loc_city ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {loc.country ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2">
                          <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-600">
                            {loc.base_currency}
                          </span>
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                          {new Date(loc.created_at).toLocaleDateString()}
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/locations/${loc.id}`} />
                            {can('edit_locations') && (
                              <EditBtn to={`/inventory/locations/${loc.id}/edit`} />
                            )}
                            {can('delete_locations') && (
                              <DeleteBtn onClick={() => handleDelete(loc.id, loc.location_name)} disabled={deleteMutation.isPending} />
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
