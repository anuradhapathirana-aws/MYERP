import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Search } from 'lucide-react'
import { getItemSearchReport } from '../../../api/reports'
import { getAllCategories } from '../../../api/categories'
import { getAllSuppliers } from '../../../api/suppliers'
import { getAllGrns } from '../../../api/goodsReceivedNotes'
import { getAllProducts } from '../../../api/products'
import Pagination from '../../../components/ui/Pagination'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import Money from '../../../components/ui/Money'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS } from '../../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Stock Report' },
  { label: 'Item Search Report' },
]

const INITIAL_FILTERS = {
  product_code: '',
  product_id: '',
  category_id: '',
  supplier_id: '',
  grn_id: '',
}

function fmt(n) {
  return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const NUM_TD = 'px-3 py-2 text-right tabular-nums'

export default function ItemSearchReport() {
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: categoriesData } = useQuery({
    queryKey: ['categories-all'],
    queryFn: getAllCategories,
    staleTime: Infinity,
  })

  const { data: suppliersData } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn: getAllSuppliers,
    staleTime: Infinity,
  })

  const { data: grnsData } = useQuery({
    queryKey: ['grns-all'],
    queryFn: getAllGrns,
    staleTime: Infinity,
  })

  const { data: productsData } = useQuery({
    queryKey: ['products-all'],
    queryFn: getAllProducts,
    staleTime: Infinity,
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-item-search', page, applied],
    queryFn: () => getItemSearchReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <Search size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Item Search Report</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Product">
          <input className={FILTER_INPUT_CLS} placeholder="Product code…" value={draft.product_code} onChange={(e) => setDraft((d) => ({ ...d, product_code: e.target.value }))} />
        </FilterField>
        <FilterField label="Product Name">
          <FilterSearchSelect
            value={draft.product_id}
            onChange={(val) => setDraft((d) => ({ ...d, product_id: val }))}
            options={(productsData ?? []).map((p) => ({ value: p.id, label: `${p.product_code} - ${p.name}` }))}
            placeholder="All products"
            wide
          />
        </FilterField>
        <FilterField label="Category">
          <FilterSearchSelect
            value={draft.category_id}
            onChange={(val) => setDraft((d) => ({ ...d, category_id: val }))}
            options={(categoriesData ?? []).map((c) => ({ value: c.id, label: c.category_name }))}
            placeholder="All categories"
          />
        </FilterField>
        <FilterField label="Supplier">
          <FilterSearchSelect
            value={draft.supplier_id}
            onChange={(val) => setDraft((d) => ({ ...d, supplier_id: val }))}
            options={(suppliersData ?? []).map((s) => ({ value: s.id, label: s.supplier_name }))}
            placeholder="All suppliers"
            wide
          />
        </FilterField>
        <FilterField label="GRN">
          <FilterSearchSelect
            value={draft.grn_id}
            onChange={(val) => setDraft((d) => ({ ...d, grn_id: val }))}
            options={(grnsData ?? []).map((g) => ({ value: g.id, label: g.grn_no }))}
            placeholder="All GRNs"
          />
        </FilterField>
      </TableFilter>

      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the item search report.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Category</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Color</th>
                    <th className="w-36 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">GRN</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Batch</th>
                    <th className="w-16 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">UOM</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Selling Price</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Stock</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="px-4 py-12 text-center text-sm text-slate-400">No items found for the selected filters.</td>
                    </tr>
                  ) : (
                    rows.map((row) => (
                      <tr key={row.row_id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-500">{row.category_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 font-mono text-slate-600">{row.product_code}</td>
                        <td className="px-3 py-2 font-medium text-slate-800">{row.product_name}</td>
                        <td className="px-3 py-2 text-slate-500">{row.attribute_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-500">{row.supplier_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-500">{row.grn_no}</td>
                        <td className="px-3 py-2 text-slate-500">{row.batch_no || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-500">{row.uom || '—'}</td>
                        <td className={`${NUM_TD} text-slate-600`}><Money value={row.selling_price} /></td>
                        <td className={`${NUM_TD} font-semibold ${Number(row.stock_qty) <= 0 ? 'text-red-600' : 'text-slate-700'}`}>{fmt(row.stock_qty)}</td>
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
