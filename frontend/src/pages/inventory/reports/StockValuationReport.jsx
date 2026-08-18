import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { DollarSign } from 'lucide-react'
import { getStockValuationReport } from '../../../api/reports'
import { getAllLocations } from '../../../api/locations'
import { getAllCategories } from '../../../api/categories'
import { getAllProducts } from '../../../api/products'
import { getAllStores } from '../../../api/stores'
import Pagination from '../../../components/ui/Pagination'
import Money from '../../../components/ui/Money'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Reports' },
  { label: 'Stock Valuation' },
]

const INITIAL_FILTERS = { search: '', product_id: '', product_code: '', location_id: '', store_id: '', category_id: '' }

const fmt = (n) => Number(n).toLocaleString(undefined, { minimumFractionDigits: 2 })

export default function StockValuationReport() {
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

  const { data: storesData } = useQuery({
    queryKey: ['stores-all'],
    queryFn: getAllStores,
    staleTime: Infinity,
  })
  const storeOptions = (storesData ?? []).map((s) => ({ value: s.id, label: s.store_name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-stock-valuation', page, applied],
    queryFn: () => getStockValuationReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []
  const totalValue = data?.summary?.total_value ?? 0

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <DollarSign size={18} className="text-green-600" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Stock Valuation Report</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {totalValue > 0 && (
          <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-right">
            <div className="text-[10px] font-semibold uppercase tracking-wider text-green-600">Total Inventory Value</div>
            <div className="text-lg font-bold text-green-700"><Money value={totalValue} /></div>
          </div>
        )}
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
        <FilterField label="Code">
          <input className={FILTER_INPUT_CLS} placeholder="Product code…" value={draft.product_code} onChange={(e) => setDraft((d) => ({ ...d, product_code: e.target.value }))} />
        </FilterField>
        <FilterField label="Location">
          <FilterSearchSelect
            value={draft.location_id}
            onChange={(val) => setDraft((d) => ({ ...d, location_id: val }))}
            options={(locationsData ?? []).map((l) => ({ value: l.id, label: l.location_name }))}
            placeholder="All locations"
          />
        </FilterField>
        <FilterField label="Store">
          <FilterSearchSelect
            value={draft.store_id}
            onChange={(val) => setDraft((d) => ({ ...d, store_id: val }))}
            options={storeOptions}
            placeholder="All stores"
          />
        </FilterField>
        <FilterField label="Category">
          <select className={FILTER_SELECT_CLS} value={draft.category_id} onChange={(e) => setDraft((d) => ({ ...d, category_id: e.target.value }))}>
            <option value="">All categories</option>
            {(categoriesData ?? []).map((c) => <option key={c.id} value={c.id}>{c.category_name}</option>)}
          </select>
        </FilterField>
      </TableFilter>

      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load stock valuation.</div>}

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
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Qty on Hand</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Avg Unit Cost</th>
                    <th className="w-32 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Total Value</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">No stock with value found.</td>
                    </tr>
                  ) : (
                    rows.map((row, i) => (
                      <tr key={`${row.product_code}-${row.store_name}`} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                        <td className="px-3 py-2 font-mono text-slate-600">{row.product_code}</td>
                        <td className="px-3 py-2 font-medium text-slate-800">{row.product_name}</td>
                        <td className="px-3 py-2 text-slate-500">{row.category_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-500">{row.location_name}</td>
                        <td className="px-3 py-2 text-slate-500">{row.store_name}</td>
                        <td className="px-3 py-2 text-right font-medium text-slate-700">{fmt(row.current_stock)}</td>
                        <td className="px-3 py-2 text-right text-slate-600"><Money value={row.avg_unit_cost} /></td>
                        <td className="px-3 py-2 text-right font-bold text-green-700"><Money value={row.total_value} /></td>
                      </tr>
                    ))
                  )}
                </tbody>
                {rows.length > 0 && (
                  <tfoot>
                    <tr className="border-t-2 border-slate-200 bg-slate-50">
                      <td colSpan={8} className="px-3 py-2 text-right text-xs font-bold uppercase tracking-wider text-slate-600">Page Total</td>
                      <td className="px-3 py-2 text-right text-xs font-bold text-green-700">
                        <Money value={rows.reduce((s, r) => s + Number(r.total_value), 0)} />
                      </td>
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
