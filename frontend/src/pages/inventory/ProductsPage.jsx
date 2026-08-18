import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { deleteProduct, getProducts } from '../../api/products'
import { getAllCategories } from '../../api/categories'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import TreeSelect from '../../components/TreeSelect'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import { useTableFilter } from '../../hooks/useTableFilter'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Products' },
]

const INITIAL_FILTERS = { search: '', product_type: '', category_id: '' }

const PRODUCT_TYPES = ['Product', 'Service', 'Bundle', 'Raw Material']

const TYPE_COLORS = {
  Product:        'bg-blue-50 text-blue-700',
  Service:        'bg-purple-50 text-purple-700',
  Bundle:         'bg-amber-50 text-amber-700',
  'Raw Material': 'bg-green-50 text-green-700',
}

export default function ProductsPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const resetPage = () => setPage(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['products', page, applied],
    queryFn:  () => getProducts(page, applied),
    placeholderData: (prev) => prev,
  })

  const { data: categories = [] } = useQuery({
    queryKey: ['categories-all'],
    queryFn:  getAllCategories,
    staleTime: 5 * 60 * 1000,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteProduct,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      showSuccess('Product deleted.')
    },
    onError: () => showError('Failed to delete. The product may be in use.'),
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Products</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_products') && (
          <Link
            to="/inventory/products/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            New Product
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
            placeholder="Name, code or EAN…"
            value={draft.search}
            onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Product Type">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.product_type}
            onChange={(e) => setDraft((d) => ({ ...d, product_type: e.target.value }))}
          >
            <option value="">All types</option>
            {PRODUCT_TYPES.map((t) => (
              <option key={t} value={t}>{t}</option>
            ))}
          </select>
        </FilterField>

        <FilterField label="Category">
          <TreeSelect
            name="category_id"
            value={draft.category_id}
            onChange={(e) => setDraft((d) => ({ ...d, category_id: e.target.value }))}
            items={categories}
            parentField="parent_category_id"
            labelField="category_name"
            placeholder="All categories"
            emptyText="No categories available."
          />
        </FilterField>

      </TableFilter>

      {/* ── Data Table ── */}
      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && (
          <div className="flex items-center justify-center py-16 text-sm text-slate-400">
            Loading…
          </div>
        )}

        {isError && (
          <div className="flex items-center justify-center py-16 text-sm text-red-500">
            Failed to load products. Check that the backend is running.
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                    <th className="w-36 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Display Name</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Category</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>

                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-8 text-center text-sm text-slate-400">
                        {activeCount > 0
                          ? 'No products match the current filters.'
                          : (
                            <>
                              No products yet.{' '}
                              {can('create_products') && (
                                <Link
                                  to="/inventory/products/create"
                                  className="font-medium text-indigo-600 hover:underline"
                                >
                                  Create the first one.
                                </Link>
                              )}
                            </>
                          )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((prod, i) => (
                      <tr key={prod.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>

                        <td className="px-3 py-2 font-mono text-slate-600">
                          {prod.product_code
                            ? <span className="rounded bg-slate-100 px-1.5 py-0.5">{prod.product_code}</span>
                            : <span className="italic text-slate-300">—</span>}
                        </td>

                        <td className="px-3 py-2">
                          <span className="font-medium text-slate-800">{prod.name}</span>
                          {prod.ean_13 && (
                            <span className="ml-2 text-slate-400">{prod.ean_13}</span>
                          )}
                        </td>

                        <td className="max-w-36 px-3 py-2">
                          {prod.display_name
                            ? <span title={prod.display_name} className="line-clamp-1 text-slate-600">{prod.display_name}</span>
                            : <span className="italic text-slate-300">—</span>}
                        </td>

                        <td className="px-3 py-2">
                          {prod.product_type ? (
                            <span className={`inline-flex rounded px-1.5 py-0.5 font-medium ${TYPE_COLORS[prod.product_type] ?? 'bg-slate-100 text-slate-600'}`}>
                              {prod.product_type}
                            </span>
                          ) : (
                            <span className="italic text-slate-300">—</span>
                          )}
                        </td>

                        <td className="max-w-32 px-3 py-2">
                          {prod.category_name
                            ? <span title={prod.category_name} className="line-clamp-1 text-slate-600">{prod.category_name}</span>
                            : <span className="italic text-slate-300">—</span>}
                        </td>

                        <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                          {new Date(prod.created_at).toLocaleDateString()}
                        </td>

                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/products/${prod.id}`} />
                            {can('edit_products') && (
                              <EditBtn to={`/inventory/products/${prod.id}/edit`} />
                            )}
                            {can('delete_products') && (
                              <DeleteBtn
                                onClick={() => handleDelete(prod.id, prod.name)}
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
