import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { PackageSearch } from 'lucide-react'
import {
  downloadAvailableStockCsv,
  downloadAvailableStockPdf,
  getAvailableStockReport,
} from '../../../api/reports'
import { getAllAttributes } from '../../../api/attributes'
import { getAllCategories } from '../../../api/categories'
import { getAllLocations } from '../../../api/locations'
import { getAllProducts } from '../../../api/products'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import CollapsibleCard from '../../../components/ui/CollapsibleCard'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { ExcelBtn, PdfBtn, PrintBtn } from '../../../components/ui/ActionButtons'
import Money from '../../../components/ui/Money'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { printPdfBlob } from '../../../utils/pdf'
import { showError } from '../../../utils/alerts'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Stock Report' },
  { label: 'Available Stock' },
]

// Booleans travel as '' / '1' — the same shape every other filter uses, so the panel's
// active-filter badge counts this tick like any other applied filter.
const INITIAL_FILTERS = {
  product_id: '',
  category_id: '',
  attribute_id: '',
  location_id: '',
  include_selling_price: '',
}

/** Quantities are not money — no currency tag, its own formatter. */
function fmtQty(n) {
  return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function HeaderItem({ label, value }) {
  return (
    <div className="flex gap-1.5 text-xs">
      <span className="font-semibold text-slate-500">{label} :</span>
      <span className="font-bold text-slate-800">{value ?? '—'}</span>
    </div>
  )
}

const NUM_TD = 'px-3 py-2 text-right tabular-nums'

export default function AvailableStockReport() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf' | 'csv'

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: productsData } = useQuery({
    queryKey: ['products-all'],
    queryFn: getAllProducts,
    staleTime: Infinity,
  })

  const { data: categoriesData } = useQuery({
    queryKey: ['categories-all'],
    queryFn: getAllCategories,
    staleTime: Infinity,
  })

  const { data: attributesData } = useQuery({
    queryKey: ['attributes-all'],
    queryFn: getAllAttributes,
    staleTime: Infinity,
  })

  const { data: locationsData } = useQuery({
    queryKey: ['locations-all'],
    queryFn: getAllLocations,
    staleTime: Infinity,
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-available-stock', applied],
    queryFn: () => getAvailableStockReport(applied),
    placeholderData: (prev) => prev,
  })

  const header = data?.header
  const rows   = data?.rows ?? []

  // Drives the column from what the server actually returned, not from the draft tick —
  // the table and the applied filter can never disagree about the price column.
  const withPrice = Boolean(header?.include_selling_price)
  const colSpan   = withPrice ? 5 : 4

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadAvailableStockPdf(applied))
      } else {
        const blob = action === 'pdf'
          ? await downloadAvailableStockPdf(applied)
          : await downloadAvailableStockCsv(applied)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = `AvailableStock.${action === 'pdf' ? 'pdf' : 'csv'}`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the available stock report.`)
    } finally {
      setExportBusy(null)
    }
  }

  const exportsDisabled = !data || Boolean(exportBusy)

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <PackageSearch size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Available Stock</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <div className="flex items-center gap-1.5">
          <PrintBtn onClick={() => handleExport('print')} disabled={exportsDisabled} title="Print" />
          <PdfBtn onClick={() => handleExport('pdf')} disabled={exportsDisabled} title="Download PDF" />
          <ExcelBtn onClick={() => handleExport('csv')} disabled={exportsDisabled} title="Download Excel (CSV)" />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={() => apply()} onClear={() => clear()} activeCount={activeCount}>
        <FilterField label="Product">
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
        <FilterField label="Colour">
          <FilterSearchSelect
            value={draft.attribute_id}
            onChange={(val) => setDraft((d) => ({ ...d, attribute_id: val }))}
            options={(attributesData ?? []).map((a) => ({ value: a.id, label: a.attribute_name }))}
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

        {/* Options — same highlighted-field treatment as the inputs, so the tick reads as
            part of the filter set rather than page furniture. */}
        <div className="flex flex-col gap-0.5">
          <label className="text-[11px] font-bold uppercase tracking-wider text-slate-500">Options</label>
          <label className="flex cursor-pointer select-none items-center gap-1.5 rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-700 transition-colors hover:border-indigo-300 hover:bg-white">
            <input
              type="checkbox"
              className="h-3.5 w-3.5 cursor-pointer rounded border-slate-300 text-indigo-600 focus:ring-2 focus:ring-indigo-500/30"
              checked={draft.include_selling_price === '1'}
              onChange={(e) => setDraft((d) => ({ ...d, include_selling_price: e.target.checked ? '1' : '' }))}
            />
            <span className="font-medium">Include Selling Price</span>
          </label>
        </div>
      </TableFilter>

      {/* ── Report header (company + applied filters) — collapsed by default ── */}
      {header && (
        <CollapsibleCard title="Available Stock Report Details" className="mt-3">
          <div className="flex items-start justify-between gap-4 border-b border-slate-100 pb-2">
            <div>
              <div className="text-sm font-bold text-slate-800">{header.company_name}</div>
              <div className="text-[11px] text-slate-500">{header.company_address}</div>
              {header.company_email && <div className="text-[11px] text-slate-500">{header.company_email}</div>}
            </div>
            <div className="text-right text-[11px] text-slate-500">
              <div className="text-sm font-bold text-slate-800">Available Stock Report</div>
              <div>Balance as at {header.generated_at}</div>
            </div>
          </div>
          <div className="grid grid-cols-1 gap-x-6 gap-y-1 pt-2 md:grid-cols-2 lg:grid-cols-3">
            <HeaderItem label="Product" value={header.product_name ?? 'All'} />
            <HeaderItem label="Category" value={header.category_name ?? 'All'} />
            <HeaderItem label="Generated By" value={header.generated_by} />
            <HeaderItem label="Colour" value={header.attribute_name ?? 'All'} />
            <HeaderItem label="Location" value={header.location_name ?? 'All'} />
            <HeaderItem label="Generated Time" value={header.generated_at} />
            <HeaderItem label="Items In Stock" value={Number(header.row_count ?? 0).toLocaleString()} />
            <HeaderItem label="Selling Price" value={withPrice ? 'Included' : 'Not included'} />
          </div>
        </CollapsibleCard>
      )}

      {/* ── Full details table ── */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the available stock report.</div>}

        {!isLoading && !isError && data && (
          <div className="max-h-[calc(100vh-19rem)] overflow-auto">
            <table className="w-full text-xs">
              <thead className="sticky top-0 z-10">
                <tr className="border-b border-slate-200 bg-slate-50 text-left">
                  <th className="w-10 bg-slate-50 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">#</th>
                  <th className="bg-slate-50 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Product Name</th>
                  <th className="w-40 bg-slate-50 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Colour</th>
                  <th className="w-40 bg-slate-50 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Available Qty</th>
                  {withPrice && (
                    <th className="w-36 bg-slate-50 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Selling Price</th>
                  )}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={colSpan} className="px-4 py-12 text-center text-sm text-slate-400">No available stock found for the selected filters.</td>
                  </tr>
                ) : (
                  rows.map((row, i) => (
                    <tr key={`${row.product_id}-${row.attribute_id ?? 0}`} className="transition-colors hover:bg-slate-50">
                      <td className="px-3 py-2 text-slate-400">{i + 1}</td>
                      <td className="max-w-0 truncate px-3 py-2 font-medium text-slate-800" title={row.product_name}>{row.product_name}</td>
                      <td className="px-3 py-2 text-slate-500">{row.attribute_name || <span className="italic text-slate-300">—</span>}</td>
                      <td className={`${NUM_TD} font-semibold text-slate-800`}>
                        {fmtQty(row.available_qty)}
                        {row.unit && <span className="ml-1 text-[10px] font-normal text-slate-400">{row.unit}</span>}
                      </td>
                      {withPrice && (
                        <td className={`${NUM_TD} text-slate-600`}>
                          {row.selling_price === null || row.selling_price === undefined
                            ? <span className="italic text-slate-300">—</span>
                            : <Money value={row.selling_price} />}
                        </td>
                      )}
                    </tr>
                  ))
                )}
              </tbody>
              {rows.length > 0 && (
                <tfoot className="sticky bottom-0">
                  <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                    {/* Quantities are deliberately not summed: a list mixes Kg, m and Yards,
                        so one total would add numbers that mean different things. */}
                    <td colSpan={colSpan} className="bg-slate-50 px-3 py-2 text-right uppercase tracking-wider text-slate-500">
                      {rows.length.toLocaleString()} item(s) in stock
                    </td>
                  </tr>
                </tfoot>
              )}
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
