import { Fragment, useEffect, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ShoppingCart } from 'lucide-react'
import {
  downloadSalesByItemSummaryCsv,
  downloadSalesByItemSummaryPdf,
  getSalesByItemSummaryReport,
} from '../../../api/reports'
import { getAllLocations } from '../../../api/locations'
import { getAllCategories } from '../../../api/categories'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { ExcelBtn, PdfBtn, PrintBtn } from '../../../components/ui/ActionButtons'
import Money from '../../../components/ui/Money'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS } from '../../../utils/fieldStyles'
import { printPdfBlob } from '../../../utils/pdf'
import { showError } from '../../../utils/alerts'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Sales Report' },
  { label: 'Sales By Item Summary' },
]

// Smart default: today only — the report opens showing today's sales.
const toYmd = (d) => d.toISOString().slice(0, 10)
const todayYmd = toYmd(new Date())

const INITIAL_FILTERS = {
  location_id: '',
  category_id: '',
  date_from: todayYmd,
  date_to: todayYmd,
}

function fmt(n) {
  return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const NUM_TD = 'px-3 py-2 text-right tabular-nums'

export default function SalesByItemSummaryReport() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf' | 'csv'

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount, setDefaults } =
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

  // Default the Location filter to the first location once the list loads —
  // only once, so it doesn't fight a user who later clears it back to "All".
  const locationDefaulted = useRef(false)
  useEffect(() => {
    if (!locationDefaulted.current && locationsData?.length) {
      locationDefaulted.current = true
      setDefaults({ location_id: locationsData[0].id })
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [locationsData])

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-sales-by-item-summary', applied],
    queryFn: () => getSalesByItemSummaryReport(applied),
    placeholderData: (prev) => prev,
  })

  const categories = data?.categories ?? []
  const summary    = data?.summary

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadSalesByItemSummaryPdf(applied))
      } else {
        const blob = action === 'pdf'
          ? await downloadSalesByItemSummaryPdf(applied)
          : await downloadSalesByItemSummaryCsv(applied)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = `SalesByItemSummary.${action === 'pdf' ? 'pdf' : 'csv'}`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the sales summary.`)
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
            <ShoppingCart size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Sales By Item Summary</h1>
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
        <FilterField label="Date From">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_from} onChange={(e) => setDraft((d) => ({ ...d, date_from: e.target.value }))} />
        </FilterField>
        <FilterField label="Date To">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_to} onChange={(e) => setDraft((d) => ({ ...d, date_to: e.target.value }))} />
        </FilterField>
        <FilterField label="Location">
          <FilterSearchSelect
            value={draft.location_id}
            onChange={(val) => setDraft((d) => ({ ...d, location_id: val }))}
            options={(locationsData ?? []).map((l) => ({ value: l.id, label: l.location_name }))}
            placeholder="All locations"
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
      </TableFilter>

      {/* ── Category-wise item table ── */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the sales summary.</div>}

        {!isLoading && !isError && data && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left">
                  <th className="px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Description</th>
                  <th className="w-28 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Colour</th>
                  <th className="w-20 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Unit</th>
                  <th className="w-24 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Qty</th>
                  <th className="w-32 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {categories.length === 0 ? (
                  <tr>
                    <td colSpan={5} className="px-4 py-12 text-center text-sm text-slate-400">No sales found for the selected filters.</td>
                  </tr>
                ) : (
                  categories.map((cat) => (
                    <Fragment key={cat.category_id}>
                      <tr className="bg-slate-50">
                        <td colSpan={5} className="px-3 py-1.5 font-bold uppercase tracking-wide text-slate-700">{cat.category_name}</td>
                      </tr>
                      {cat.items.map((item) => (
                        <tr key={`item-${cat.category_id}-${item.product_id}-${item.attribute_name ?? ''}-${item.unit ?? ''}`} className="transition-colors hover:bg-slate-50">
                          <td className="px-3 py-2 pl-6 text-slate-800">{item.product_name}</td>
                          <td className="px-3 py-2 text-slate-500">{item.attribute_name ?? '—'}</td>
                          <td className="px-3 py-2 text-slate-500">{item.unit ?? '—'}</td>
                          <td className={`${NUM_TD} text-slate-600`}>{fmt(item.quantity)}</td>
                          <td className={`${NUM_TD} text-slate-600`}><Money value={item.amount} /></td>
                        </tr>
                      ))}
                      <tr className="border-b border-slate-200 font-semibold text-slate-800">
                        <td colSpan={4} className="px-3 py-1.5 text-right uppercase tracking-wider text-slate-500">Total {cat.category_name}</td>
                        <td className={NUM_TD}><Money value={cat.category_amount} /></td>
                      </tr>
                    </Fragment>
                  ))
                )}
              </tbody>
              {summary && categories.length > 0 && (
                <tfoot>
                  <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                    <td colSpan={3} className="px-3 py-2 text-right uppercase tracking-wider text-slate-500">Grand Total</td>
                    <td className={NUM_TD}>{fmt(summary.total_qty)}</td>
                    <td className={NUM_TD}><Money value={summary.total_amount} /></td>
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
