import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Clock, AlertCircle } from 'lucide-react'
import { getOutstandingPOsReport } from '../../../api/reports'
import { getAllSuppliers } from '../../../api/suppliers'
import { getAllLocations } from '../../../api/locations'
import { getAllStores } from '../../../api/stores'
import { getAllProducts } from '../../../api/products'
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
  { label: 'Outstanding POs' },
]

const INITIAL_FILTERS = { search: '', supplier_id: '', location_id: '', store_id: '', product_id: '', overdue_only: '' }

const PO_STATUS_STYLES = {
  confirmed:          'bg-indigo-100 text-indigo-700',
  partially_received: 'bg-amber-100 text-amber-700',
}

const fmt = (n) => Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: 2 })

export default function OutstandingPOsReport() {
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: suppliersData } = useQuery({ queryKey: ['suppliers-all'], queryFn: getAllSuppliers, staleTime: Infinity })
  const { data: locationsData } = useQuery({ queryKey: ['locations-all'], queryFn: getAllLocations, staleTime: Infinity })
  const { data: storesData }    = useQuery({ queryKey: ['stores-all'],    queryFn: getAllStores,    staleTime: Infinity })
  const { data: productsData }  = useQuery({ queryKey: ['products-all'],  queryFn: getAllProducts,  staleTime: Infinity })

  const supplierOptions = (suppliersData ?? []).map((s) => ({ value: s.id, label: s.name }))
  const storeOptions    = (storesData    ?? []).map((s) => ({ value: s.id, label: s.store_name }))
  const productOptions  = (productsData  ?? []).map((p) => ({ value: p.id, label: p.product_code ? `[${p.product_code}] ${p.name}` : p.name }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-outstanding-pos', page, applied],
    queryFn: () => getOutstandingPOsReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <Clock size={18} className="text-amber-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Outstanding Purchase Orders</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="PO No, supplier, product…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Supplier">
          <FilterSearchSelect value={draft.supplier_id} onChange={(val) => setDraft((d) => ({ ...d, supplier_id: val }))} options={supplierOptions} placeholder="All suppliers" />
        </FilterField>
        <FilterField label="Product">
          <FilterSearchSelect value={draft.product_id} onChange={(val) => setDraft((d) => ({ ...d, product_id: val }))} options={productOptions} wide placeholder="All products" />
        </FilterField>
        <FilterField label="Location">
          <FilterSearchSelect value={draft.location_id} onChange={(val) => setDraft((d) => ({ ...d, location_id: val }))} options={(locationsData ?? []).map((l) => ({ value: l.id, label: l.location_name }))} placeholder="All locations" />
        </FilterField>
        <FilterField label="Store">
          <FilterSearchSelect value={draft.store_id} onChange={(val) => setDraft((d) => ({ ...d, store_id: val }))} options={storeOptions} placeholder="All stores" />
        </FilterField>
        <FilterField label="Overdue Only">
          <select className={FILTER_SELECT_CLS} value={draft.overdue_only} onChange={(e) => setDraft((d) => ({ ...d, overdue_only: e.target.value }))}>
            <option value="">All</option>
            <option value="1">Overdue only</option>
          </select>
        </FilterField>
      </TableFilter>

      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load outstanding POs.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">PO No</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Supplier</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Store</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Product</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Order Date</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Exp. Delivery</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Ordered</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Received</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Remaining</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Rem. Value</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">PO Status</th>
                    <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Overdue</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={13} className="px-4 py-12 text-center text-sm text-slate-400">No outstanding purchase order items.</td>
                    </tr>
                  ) : (
                    rows.map((row, i) => (
                      <tr key={i} className={`transition-colors ${row.is_overdue ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-slate-50'}`}>
                        <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                        <td className="px-3 py-2 font-mono font-medium text-indigo-600">{row.po_no}</td>
                        <td className="px-3 py-2 font-medium text-slate-700">{row.supplier_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-500">{row.store_name || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-700">
                          <span className="font-mono text-slate-400">{row.product_code}</span>{' '}
                          {row.product_name}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.order_date}</td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.expected_delivery_date || <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-right text-slate-600">{fmt(row.quantity_ordered)}</td>
                        <td className="px-3 py-2 text-right text-green-700">{fmt(row.quantity_received)}</td>
                        <td className="px-3 py-2 text-right font-bold text-amber-700">{fmt(row.remaining_qty)}</td>
                        <td className="px-3 py-2 text-right font-bold text-slate-800"><Money value={row.remaining_value} /></td>
                        <td className="px-3 py-2">
                          <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold ${PO_STATUS_STYLES[row.po_status] ?? 'bg-slate-100 text-slate-500'}`}>
                            {row.po_status?.replace('_', ' ')}
                          </span>
                        </td>
                        <td className="px-3 py-2">
                          {row.is_overdue ? (
                            <span className="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-600">
                              <AlertCircle size={10} />
                              {row.overdue_days}d
                            </span>
                          ) : (
                            <span className="text-slate-300">—</span>
                          )}
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
