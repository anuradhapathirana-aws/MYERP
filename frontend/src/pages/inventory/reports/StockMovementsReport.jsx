import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BarChart2 } from 'lucide-react'
import { getStockMovementsReport } from '../../../api/reports'
import { getAllStockReferenceTypes } from '../../../api/stockReferenceTypes'
import { getAllLocations } from '../../../api/locations'
import { getAllStores } from '../../../api/stores'
import { getAllProducts } from '../../../api/products'
import { getAllAttributes } from '../../../api/attributes'
import Pagination from '../../../components/ui/Pagination'
import Money from '../../../components/ui/Money'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../../utils/fieldStyles'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Stock Report' },
  { label: 'Stock Movements' },
]

const INITIAL_FILTERS = {
  search: '',
  product_id: '',
  attribute_id: '',
  location_id: '',
  store_id: '',
  reference_type: '',
  date_from: '',
  date_to: '',
}

/** Plain n-dp number — quantities carry their own unit symbol beside them. */
const num = (n, d = 2) =>
  Number(n ?? 0).toLocaleString(undefined, { minimumFractionDigits: d, maximumFractionDigits: d })

export default function StockMovementsReport() {
  const [page, setPage] = useState(1)
  const resetPage = () => setPage(1)

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: refTypesData } = useQuery({
    queryKey: ['stock-reference-types-all'],
    queryFn: getAllStockReferenceTypes,
    staleTime: Infinity,
  })
  const refTypeLabels = Object.fromEntries((refTypesData ?? []).map((t) => [t.code, t.label]))

  const { data: locationsData } = useQuery({
    queryKey: ['locations-all'],
    queryFn: getAllLocations,
    staleTime: Infinity,
  })

  const { data: storesData } = useQuery({
    queryKey: ['stores-all'],
    queryFn: getAllStores,
    staleTime: Infinity,
  })
  const storeOptions = (storesData ?? []).map((s) => ({ value: s.id, label: s.store_name }))

  const { data: productsData } = useQuery({
    queryKey: ['products-all'],
    queryFn: getAllProducts,
    staleTime: Infinity,
  })
  const productOptions = (productsData ?? []).map((p) => ({
    value: p.id,
    label: p.product_code ? `[${p.product_code}] ${p.name}` : p.name,
  }))

  const { data: attributesData } = useQuery({
    queryKey: ['attributes-all'],
    queryFn: getAllAttributes,
    staleTime: Infinity,
  })
  const colorOptions = (attributesData ?? []).map((a) => ({
    value: a.id,
    label: a.attribute_name,
  }))

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-stock-movements', page, applied],
    queryFn: () => getStockMovementsReport(page, applied),
    placeholderData: (prev) => prev,
  })

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <BarChart2 size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Stock Movements Report</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply(resetPage)} onClear={() => clear(resetPage)} activeCount={activeCount}>
        <FilterField label="Search">
          <input className={FILTER_INPUT_CLS} placeholder="Product, code, colour, batch…" value={draft.search} onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))} />
        </FilterField>
        <FilterField label="Product">
          <FilterSearchSelect
            value={draft.product_id}
            onChange={(val) => setDraft((d) => ({ ...d, product_id: val }))}
            options={productOptions} wide
            placeholder="All products"
          />
        </FilterField>
        <FilterField label="Colour">
          <FilterSearchSelect
            value={draft.attribute_id}
            onChange={(val) => setDraft((d) => ({ ...d, attribute_id: val }))}
            options={colorOptions}
            placeholder="All colours"
          />
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
        <FilterField label="Reference Type">
          <select className={FILTER_SELECT_CLS} value={draft.reference_type} onChange={(e) => setDraft((d) => ({ ...d, reference_type: e.target.value }))}>
            <option value="">All types</option>
            {(refTypesData ?? []).map((t) => <option key={t.code} value={t.code}>{t.label}</option>)}
          </select>
        </FilterField>
        <FilterField label="Date From">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_from} onChange={(e) => setDraft((d) => ({ ...d, date_from: e.target.value }))} />
        </FilterField>
        <FilterField label="Date To">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_to} onChange={(e) => setDraft((d) => ({ ...d, date_to: e.target.value }))} />
        </FilterField>
      </TableFilter>

      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load stock movements.</div>}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="w-36 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Product</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Colour</th>
                    <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Reference Type</th>
                    <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Ref ID</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Batch No</th>
                    {/* Qty In/Out are in the product's stocking UOM; the small line beneath
                        restates what the document actually transacted (10 Roll, not 500 kg). */}
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Qty In</th>
                    <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Qty Out</th>
                    <th className="w-24 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Unit Price</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Location</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Store</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={13} className="px-4 py-12 text-center text-sm text-slate-400">No stock movements found.</td>
                    </tr>
                  ) : (
                    rows.map((row, i) => {
                      const qtyIn  = Number(row.qty_in)  || 0
                      const qtyOut = Number(row.qty_out) || 0
                      // Only worth the extra line when the transacted unit differs from the
                      // stocking unit — otherwise it just repeats the number above it.
                      const showEntered =
                        row.entered_uom && row.uom && row.entered_uom !== row.uom && Number(row.entered_qty) > 0
                      const entered = showEntered
                        ? <div className="text-[9px] font-normal text-slate-400">= {num(row.entered_qty)} {row.entered_uom}</div>
                        : null

                      return (
                        <tr key={row.id} className="transition-colors hover:bg-slate-50">
                          <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.transaction_date?.slice(0, 16)}</td>
                          <td className="px-3 py-2 font-mono text-slate-600">{row.product_code}</td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.product_name}</td>
                          <td className="px-3 py-2">
                            {row.color
                              ? <span className="rounded-full bg-slate-100 px-1.5 py-px text-[10px] font-semibold text-slate-600">{row.color}</span>
                              : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2 text-slate-500">{refTypeLabels[row.reference_type] ?? row.reference_type}</td>
                          <td className="px-3 py-2 font-mono text-slate-500">{row.reference_id}</td>
                          <td className="px-3 py-2 font-mono text-slate-500">{row.batch_no || <span className="italic text-slate-300">—</span>}</td>
                          <td className={`px-3 py-2 text-right font-semibold tabular-nums ${qtyIn > 0 ? 'text-green-700' : 'text-slate-300'}`}>
                            {qtyIn > 0
                              ? <>{num(qtyIn)}<span className="ml-0.5 text-[9px] font-normal text-slate-400">{row.uom}</span>{entered}</>
                              : '—'}
                          </td>
                          <td className={`px-3 py-2 text-right font-semibold tabular-nums ${qtyOut > 0 ? 'text-red-600' : 'text-slate-300'}`}>
                            {qtyOut > 0
                              ? <>{num(qtyOut)}<span className="ml-0.5 text-[9px] font-normal text-slate-400">{row.uom}</span>{entered}</>
                              : '—'}
                          </td>
                          <td className="px-3 py-2 text-right text-slate-600">
                            <Money value={row.unit_price} />
                            {row.uom && <span className="text-[9px] font-normal text-slate-400">/{row.uom}</span>}
                          </td>
                          <td className="px-3 py-2 text-slate-500">{row.location_name}</td>
                          <td className="px-3 py-2 text-slate-500">{row.store_name}</td>
                        </tr>
                      )
                    })
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
