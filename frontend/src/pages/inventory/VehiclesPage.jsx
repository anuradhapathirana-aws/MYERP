import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { deleteVehicle, getVehicles } from '../../api/vehicles'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS } from '../../utils/fieldStyles'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Vehicles' },
]

const STATUS_COLORS = {
  active:            'bg-green-50 text-green-700',
  inactive:          'bg-slate-100 text-slate-500',
  under_maintenance: 'bg-amber-50 text-amber-700',
}

const STATUS_LABELS = {
  active:            'Active',
  inactive:          'Inactive',
  under_maintenance: 'Maintenance',
}

const TYPE_COLORS = {
  Car:           'bg-blue-50 text-blue-700',
  Van:           'bg-indigo-50 text-indigo-700',
  Truck:         'bg-orange-50 text-orange-700',
  Bus:           'bg-purple-50 text-purple-700',
  Motorcycle:    'bg-teal-50 text-teal-700',
  'Heavy Truck': 'bg-red-50 text-red-700',
}

function StatusBadge({ status }) {
  if (!status) return <span className="italic text-slate-300">—</span>
  return (
    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${STATUS_COLORS[status] ?? 'bg-slate-100 text-slate-600'}`}>
      {STATUS_LABELS[status] ?? status}
    </span>
  )
}

function TypeBadge({ type }) {
  if (!type) return <span className="italic text-slate-300">—</span>
  return (
    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${TYPE_COLORS[type] ?? 'bg-slate-100 text-slate-600'}`}>
      {type}
    </span>
  )
}

export default function VehiclesPage() {
  const [page, setPage]     = useState(1)
  const [search, setSearch] = useState('')
  const [q, setQ]           = useState('')
  const queryClient         = useQueryClient()
  const { can }             = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['vehicles', page, q],
    queryFn:  () => getVehicles(page, q),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteVehicle,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vehicles'] })
      showSuccess('Vehicle deleted.')
    },
    onError: () => showError('Failed to delete. The vehicle may be in use.'),
  })

  const handleSearch = (e) => {
    e.preventDefault()
    setQ(search.trim())
    setPage(1)
  }

  const handleDelete = async (id, label) => {
    const ok = await confirmDelete(label)
    if (ok) deleteMutation.mutate(id)
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Vehicles</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_vehicle_masters') && (
          <Link
            to="/inventory/vehicles/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            New Vehicle
          </Link>
        )}
      </div>
      {/* Search */}
      <form onSubmit={handleSearch} className="mt-2 flex items-center gap-2">
        <div className="relative max-w-xs flex-1">
          <Search size={13} className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by code, reg no. or make…"
            className={`${FILTER_INPUT_CLS} pl-8`}
          />
        </div>
        <button
          type="submit"
          className="rounded border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
        >
          Search
        </button>
        {q && (
          <button
            type="button"
            onClick={() => { setQ(''); setSearch(''); setPage(1) }}
            className="text-xs text-slate-400 hover:text-slate-700"
          >
            Clear
          </button>
        )}
      </form>

      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && (
          <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
        )}
        {isError && (
          <div className="flex items-center justify-center py-14 text-sm text-red-500">
            Failed to load vehicles.
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8  px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Reg No.</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Make / Model</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Fuel</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Driver</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-8 text-center text-sm text-slate-400">
                        {q ? `No vehicles found for "${q}".` : 'No vehicles yet.'}{' '}
                        {!q && can('create_vehicle_masters') && (
                          <Link to="/inventory/vehicles/create" className="font-medium text-indigo-600 hover:underline">
                            Add the first one.
                          </Link>
                        )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((v, i) => (
                      <tr key={v.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>
                        <td className="px-3 py-2 font-mono text-slate-500">
                          {v.vehicle_code ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 font-mono font-medium text-slate-700">
                          <Link
                            to={`/inventory/vehicles/${v.id}`}
                            className="hover:text-indigo-600 hover:underline"
                          >
                            {v.registration_number}
                          </Link>
                        </td>
                        <td className="px-3 py-2 text-slate-700">
                          {(v.make || v.model)
                            ? <span>{[v.make, v.model].filter(Boolean).join(' ')}{v.year ? <span className="ml-1 text-slate-400">({v.year})</span> : null}</span>
                            : <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2">
                          <TypeBadge type={v.vehicle_type} />
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {v.fuel_type ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="max-w-32 truncate px-3 py-2 text-slate-500">
                          {v.assigned_driver?.name ?? <span className="italic text-slate-300">Unassigned</span>}
                        </td>
                        <td className="px-3 py-2">
                          <StatusBadge status={v.status} />
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/vehicles/${v.id}`} />
                            {can('edit_vehicle_masters') && (
                              <EditBtn to={`/inventory/vehicles/${v.id}/edit`} />
                            )}
                            {can('delete_vehicle_masters') && (
                              <DeleteBtn onClick={() => handleDelete(v.id, v.vehicle_code ?? v.registration_number)} disabled={deleteMutation.isPending} />
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
