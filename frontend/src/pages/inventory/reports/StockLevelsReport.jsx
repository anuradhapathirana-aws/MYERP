import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart2 } from 'lucide-react'
import { getStockLevelsReport } from '../../../api/reports'
import { getAllLocations } from '../../../api/locations'
import { getAllCategories } from '../../../api/categories'
import { getAllProducts } from '../../../api/products'
import Pagination from '../../../components/ui/Pagination'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Reports' },
  { label: 'Stock Levels' },
]

const INITIAL_FILTERS = {
  search: '',
  product_id: '',
  location_id: '',
  category_id: '',
  stock_status: '',
}

const STOCK_STATUS_OPTIONS = [
  { value: 'below_reorder', label: 'Below Reorder Level' },
  { value: 'out_of_stock', label: 'Out of Stock' },
]

export default function StockLevelsReport() {
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: locationsData } = useQuery({
    queryKey: ['locations-all'],
    queryFn: getAllLocations,
    staleTime: Infinity,
  })

  const { data: categoriesData } = useQuery({
    queryKey: ['categories-all'],
    queryFn: getAllCategories,
    staleTime: Infinity,
  })

  const { data: productsData } = useQuery({
    queryKey: ['products-all'],
    queryFn: getAllProducts,
    staleTime: Infinity,
  })
  const productOptions = (productsData ?? []).map((p) => ({
    value: p.id,
    label: p.product_code ? `[${p.product_code}] ${p.name}` : p.name,
  }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-stock-levels', page, applied],
    queryFn: () => getStockLevelsReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta    = data?.meta
  const rows    = data?.data ?? []
  const summary = data?.summary

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <BarChart2 size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Stock Levels Report</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Product name or code…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Product">
          <FilterSearchSelect
            value={draft.product_id}
            onChange={(val) => setDraft((d) => ({ ...d, product_id: val }))}
            options={productOptions} wide
            placeholder="All products"
          />
        </FilterField>
        <FilterField label="Location">
          <select className={FILTER_SELECT_CLS} value={draft.location_id} onChange={(e) => setDraft((d) => ({ ...d, location_id: e.target.value }))}>
            <option value="">All locations</option>
            {(locationsData ?? []).map((l) => <option key={l.id} value={l.id}>{l.location_name}</option>)}
          </select>
        </FilterField>
        <FilterField label="Category">
          <select className={FILTER_SELECT_CLS} value={draft.category_id} onChange={(e) => setDraft((d) => ({ ...d, category_id: e.target.value }))}>
            <option value="">All categories</option>
            {(categoriesData ?? []).map((c) => <option key={c.id} value={c.id}>{c.category_name}</option>)}
          </select>
        </FilterField>
        <FilterField label="Stock Status">
          <select className={FILTER_SELECT_CLS} value={draft.stock_status} onChange={(e) => setDraft((d) => ({ ...d, stock_status: e.target.value }))}>
            <option value="">All stock</option>
            {STOCK_STATUS_OPTIONS.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
          </select>
        </FilterField>
      </TableFilter>

      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load stock levels.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Product</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Category</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Location</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Store</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Stock</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Reorder Lvl</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Reorder Qty</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="px-4 py-12 text-center text-sm text-slate-400">No stock records found.</td>
                    </tr>
                  ) : (
                    rows.map((row, i) => {
                      const belowReorder = row.reorder_level > 0 && row.current_stock <= row.reorder_level
                      const outOfStock = row.current_stock <= 0
                      return (
                        <tr key={`${row.product_id}-${row.store_id}`} className="transition-colors hover:bg-slate-50">
                          <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                          <td className="px-3 py-2 font-mono text-slate-600">{row.product_code}</td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.product_name}</td>
                          <td className="px-3 py-2 text-slate-500">{row.category_name || <span className="italic text-slate-300">—</span>}</td>
                          <td className="px-3 py-2 text-slate-500">{row.location_name}</td>
                          <td className="px-3 py-2 text-slate-500">{row.store_name}</td>
                          <td className={`px-3 py-2 text-right font-semibold ${outOfStock ? 'text-red-600' : belowReorder ? 'text-amber-600' : 'text-green-700'}`}>
                            {Number(row.current_stock).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                          </td>
                          <td className="px-3 py-2 text-right text-slate-500">{Number(row.reorder_level).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                          <td className="px-3 py-2 text-right text-slate-500">{Number(row.reorder_qty).toLocaleString(undefined, { minimumFractionDigits: 2 })}</td>
                          <td className="px-3 py-2">
                            {outOfStock ? (
                              <span className="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-600">Out of Stock</span>
                            ) : belowReorder ? (
                              <span className="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Low Stock</span>
                            ) : (
                              <span className="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-[10px] font-semibold text-green-700">OK</span>
                            )}
                          </td>
                        </tr>
                      )
                    })
                  )}
                </tbody>
                {rows.length > 0 && summary && (
                  <tfoot>
                    <tr className="border-t-2 border-slate-300 bg-slate-50">
                      <td colSpan={6} className="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-slate-600">Total Stock Qty</td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-slate-800">
                        {Number(summary.total_stock).toLocaleString(undefined, { minimumFractionDigits: 2 })}
                      </td>
                      <td colSpan={3} />
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
            <Pagination meta={meta} page={page} onPageChange={setPage} />
          </>
        )}
      </div>
    </div>
  )
}
