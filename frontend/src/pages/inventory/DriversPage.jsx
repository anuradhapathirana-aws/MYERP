import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, Search } from 'lucide-react'
import { deleteDriver, getDrivers } from '../../api/drivers'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS } from '../../utils/fieldStyles'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Drivers' },
]

const STATUS_COLORS = {
  active:    'bg-green-50 text-green-700',
  inactive:  'bg-slate-100 text-slate-500',
  suspended: 'bg-red-50 text-red-700',
}

function StatusBadge({ status }) {
  if (!status) return <span className="italic text-slate-300">—</span>
  return (
    <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${STATUS_COLORS[status] ?? 'bg-slate-100 text-slate-600'}`}>
      {status}
    </span>
  )
}

export default function DriversPage() {
  const [page, setPage]     = useState(1)
  const [search, setSearch] = useState('')
  const [q, setQ]           = useState('')
  const queryClient         = useQueryClient()
  const { can }             = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['drivers', page, q],
    queryFn:  () => getDrivers(page, q),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteDriver,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['drivers'] })
      showSuccess('Driver deleted.')
    },
    onError: () => showError('Failed to delete. The driver may be in use.'),
  })

  const handleSearch = (e) => {
    e.preventDefault()
    setQ(search.trim())
    setPage(1)
  }

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
          <h1 className="text-xl font-bold leading-none text-slate-800">Drivers</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_drivers') && (
          <Link
            to="/inventory/drivers/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            New Driver
          </Link>
        )}
      </div>
      {/* Search bar */}
      <form onSubmit={handleSearch} className="mt-2 flex items-center gap-2">
        <div className="relative flex-1 max-w-xs">
          <Search size={13} className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search by name, code or licence…"
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
            Failed to load drivers.
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Driver Name</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Licence No.</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Expiry</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Phone</th>
                    <th className="w-22 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-8 text-center text-sm text-slate-400">
                        {q ? `No drivers found for "${q}".` : 'No drivers yet.'}{' '}
                        {!q && can('create_drivers') && (
                          <Link to="/inventory/drivers/create" className="font-medium text-indigo-600 hover:underline">
                            Add the first one.
                          </Link>
                        )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((d, i) => (
                      <tr key={d.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>
                        <td className="px-3 py-2 font-mono text-slate-500">
                          {d.driver_code ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="max-w-[200px] px-3 py-2 font-medium text-slate-800">
                          <Link
                            to={`/inventory/drivers/${d.id}`}
                            className="truncate hover:text-indigo-600 hover:underline"
                          >
                            {d.full_name}
                          </Link>
                        </td>
                        <td className="px-3 py-2 font-mono text-slate-500">
                          {d.license_number ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {d.license_type ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">
                          {d.license_expiry_date
                            ? <span className={new Date(d.license_expiry_date) < new Date() ? 'font-semibold text-red-600' : ''}>
                                {new Date(d.license_expiry_date).toLocaleDateString()}
                              </span>
                            : <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {d.phone ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2">
                          <StatusBadge status={d.status} />
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/drivers/${d.id}`} />
                            {can('edit_drivers') && (
                              <EditBtn to={`/inventory/drivers/${d.id}/edit`} />
                            )}
                            {can('delete_drivers') && (
                              <DeleteBtn onClick={() => handleDelete(d.id, d.full_name)} disabled={deleteMutation.isPending} />
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
