import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { deleteCustomer, getCustomers } from '../../api/customers'
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
  { label: 'Customers' },
]

const INITIAL_FILTERS = { search: '', customer_type: '', customer_code: '', mobile: '', email: '', billing_city: '', billing_country: '' }

const CUSTOMER_TYPES = ['Trade', 'Retail', 'Wholesale', 'Corporate']

const TYPE_COLORS = {
  Trade:     'bg-blue-50 text-blue-700',
  Retail:    'bg-green-50 text-green-700',
  Wholesale: 'bg-amber-50 text-amber-700',
  Corporate: 'bg-indigo-50 text-indigo-700',
}

export default function CustomersPage() {
  const [page, setPage] = useState(1)
  const queryClient    = useQueryClient()
  const { can }        = usePermissions()

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const resetPage = () => setPage(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['customers', page, applied],
    queryFn:  () => getCustomers(page, applied),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCustomer,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['customers'] })
      showSuccess('Customer deleted.')
    },
    onError: () => showError('Failed to delete. The customer may be in use.'),
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Customers</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_customer_masters') && (
          <Link
            to="/inventory/customers/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            New Customer
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

        <FilterField label="Customer Type">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.customer_type}
            onChange={(e) => setDraft((d) => ({ ...d, customer_type: e.target.value }))}
          >
            <option value="">All types</option>
            {CUSTOMER_TYPES.map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </select>
        </FilterField>

        <FilterField label="Customer Code">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Code…"
            value={draft.customer_code}
            onChange={(e) => setDraft((d) => ({ ...d, customer_code: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Mobile">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Mobile number…"
            value={draft.mobile}
            onChange={(e) => setDraft((d) => ({ ...d, mobile: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Email">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Email address…"
            value={draft.email}
            onChange={(e) => setDraft((d) => ({ ...d, email: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Billing City">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="City…"
            value={draft.billing_city}
            onChange={(e) => setDraft((d) => ({ ...d, billing_city: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Billing Country">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Country…"
            value={draft.billing_country}
            onChange={(e) => setDraft((d) => ({ ...d, billing_country: e.target.value }))}
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
            Failed to load customers.
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Customer Name</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
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
                          ? 'No customers match the current filters.'
                          : (
                            <>
                              No customers yet.{' '}
                              {can('create_customer_masters') && (
                                <Link to="/inventory/customers/create" className="font-medium text-indigo-600 hover:underline">
                                  Add the first one.
                                </Link>
                              )}
                            </>
                          )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((c, i) => (
                      <tr key={c.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>
                        <td className="px-3 py-2 font-mono text-slate-500">
                          {c.customer_code ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="max-w-55 px-3 py-2 font-medium text-slate-800">
                          <Link
                            to={`/inventory/customers/${c.id}`}
                            className="truncate hover:text-indigo-600 hover:underline"
                          >
                            {c.title ? `${c.title} ` : ''}{c.customer_name}
                          </Link>
                        </td>
                        <td className="px-3 py-2">
                          {c.customer_type ? (
                            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${TYPE_COLORS[c.customer_type] ?? 'bg-slate-100 text-slate-600'}`}>
                              {c.customer_type}
                            </span>
                          ) : <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {c.customer_mobile ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="max-w-44 truncate px-3 py-2 text-slate-500">
                          {c.customer_email ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                          {new Date(c.created_at).toLocaleDateString()}
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/customers/${c.id}`} />
                            {can('edit_customer_masters') && (
                              <EditBtn to={`/inventory/customers/${c.id}/edit`} />
                            )}
                            {can('delete_customer_masters') && (
                              <DeleteBtn onClick={() => handleDelete(c.id, c.customer_name)} disabled={deleteMutation.isPending} />
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
