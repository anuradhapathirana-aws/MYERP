import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { TrendingUp } from 'lucide-react'
import {
  downloadStockMovementSummaryCsv,
  downloadStockMovementSummaryPdf,
  getStockMovementSummaryReport,
} from '../../../api/reports'
import { getAllLocations } from '../../../api/locations'
import { getAllStores } from '../../../api/stores'
import { getAllCategories } from '../../../api/categories'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import CollapsibleCard from '../../../components/ui/CollapsibleCard'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { ExcelBtn, PdfBtn, PrintBtn } from '../../../components/ui/ActionButtons'
import Money from '../../../components/ui/Money'
import { fmtMoneyWithSymbol } from '../../../utils/currency'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS } from '../../../utils/fieldStyles'
import { printPdfBlob } from '../../../utils/pdf'
import { showError } from '../../../utils/alerts'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Stock Report' },
  { label: 'Movement Summary' },
]

// Smart defaults: first day of the current month → today
const today = new Date()
const toYmd = (d) => d.toISOString().slice(0, 10)

const INITIAL_FILTERS = {
  location_id: '',
  store_id: '',
  category_id: '',
  date_from: toYmd(new Date(today.getFullYear(), today.getMonth(), 1)),
  date_to: toYmd(today),
}

function fmt(n) {
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

const QTY_TH = 'w-24 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500'
const NUM_TD = 'px-3 py-2 text-right tabular-nums'

export default function StockMovementSummaryReport() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf' | 'csv'

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

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
  // Store dropdown cascades from the selected location
  const storeOptions = (storesData ?? [])
    .filter((s) => !draft.location_id || String(s.location_id) === String(draft.location_id))
    .map((s) => ({ value: s.id, label: s.store_name }))

  const { data: categoriesData } = useQuery({
    queryKey: ['categories-all'],
    queryFn: getAllCategories,
    staleTime: Infinity,
  })

  const setLocation = (val) => setDraft((d) => {
    const store = (storesData ?? []).find((s) => String(s.id) === String(d.store_id))
    const storeStillValid = !val || (store && String(store.location_id) === String(val))
    return { ...d, location_id: val, store_id: storeStillValid ? d.store_id : '' }
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-movement-summary', applied],
    queryFn: () => getStockMovementSummaryReport(applied),
    placeholderData: (prev) => prev,
  })

  const header  = data?.header
  const rows    = data?.rows ?? []
  const summary = data?.summary

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadStockMovementSummaryPdf(applied))
      } else {
        const blob = action === 'pdf'
          ? await downloadStockMovementSummaryPdf(applied)
          : await downloadStockMovementSummaryCsv(applied)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = `StockMovementSummary.${action === 'pdf' ? 'pdf' : 'csv'}`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the movement summary.`)
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
            <TrendingUp size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Stock Movement Summary</h1>
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
            onChange={setLocation}
            options={(locationsData ?? []).map((l) => ({ value: l.id, label: l.location_name }))}
            placeholder="All locations"
          />
        </FilterField>
        <FilterField label="Warehouse">
          <FilterSearchSelect
            value={draft.store_id}
            onChange={(val) => setDraft((d) => ({ ...d, store_id: val }))}
            options={storeOptions}
            placeholder="All warehouses"
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

      {/* ── Report header (company + summary info) — collapsed by default ── */}
      {header && (
        <CollapsibleCard title="Stock Movement Summary Details" className="mt-3">
          <div className="flex items-start justify-between gap-4 border-b border-slate-100 pb-2">
            <div>
              <div className="text-sm font-bold text-slate-800">{header.company_name}</div>
              <div className="text-[11px] text-slate-500">{header.company_address}</div>
              {header.company_email && <div className="text-[11px] text-slate-500">{header.company_email}</div>}
            </div>
            <div className="text-right text-[11px] text-slate-500">
              <div className="text-sm font-bold text-slate-800">Stock Movement Summary</div>
              <div>Period: {header.date_from ?? 'Beginning'} — {header.date_to ?? 'To Date'}</div>
            </div>
          </div>
          <div className="grid grid-cols-1 gap-x-6 gap-y-1 pt-2 md:grid-cols-2 lg:grid-cols-3">
            <HeaderItem label="Opening Qty" value={fmt(header.opening_qty)} />
            <HeaderItem label="Location" value={header.location_name ?? 'All'} />
            <HeaderItem label="Generated By" value={header.generated_by} />
            <HeaderItem label="Opening Value" value={fmtMoneyWithSymbol(header.opening_value)} />
            <HeaderItem label="Warehouse" value={header.store_name ?? 'All'} />
            <HeaderItem label="Generated Time" value={header.generated_at} />
            <HeaderItem label="Closing Qty" value={fmt(header.closing_qty)} />
            <HeaderItem label="Category" value={header.category_name ?? 'All'} />
            <HeaderItem label="Closing Value" value={fmtMoneyWithSymbol(header.closing_value)} />
          </div>
        </CollapsibleCard>
      )}

      {/* ── Summary table ── */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the movement summary.</div>}

        {!isLoading && !isError && data && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left">
                  <th rowSpan={2} className="w-8 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">#</th>
                  <th rowSpan={2} className="px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Item Description</th>
                  <th rowSpan={2} className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Unit</th>
                  <th colSpan={2} className="border-l border-slate-200 px-3 py-1.5 text-center font-semibold uppercase tracking-wider text-slate-500">Opening Balance</th>
                  <th colSpan={2} className="border-l border-slate-200 px-3 py-1.5 text-center font-semibold uppercase tracking-wider text-slate-500">Purchase</th>
                  <th colSpan={2} className="border-l border-slate-200 px-3 py-1.5 text-center font-semibold uppercase tracking-wider text-slate-500">Sales / Consumptions</th>
                  <th colSpan={2} className="border-l border-slate-200 px-3 py-1.5 text-center font-semibold uppercase tracking-wider text-slate-500">Closing Balance</th>
                </tr>
                <tr className="border-b border-slate-200 bg-slate-100/70">
                  <th className={`border-l border-slate-200 ${QTY_TH}`}>Qty</th>
                  <th className={QTY_TH}>Value</th>
                  <th className={`border-l border-slate-200 ${QTY_TH}`}>Qty</th>
                  <th className={QTY_TH}>Value</th>
                  <th className={`border-l border-slate-200 ${QTY_TH}`}>Qty</th>
                  <th className={QTY_TH}>Value</th>
                  <th className={`border-l border-slate-200 ${QTY_TH}`}>Qty</th>
                  <th className={QTY_TH}>Value</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={11} className="px-4 py-12 text-center text-sm text-slate-400">No stock movement found for the selected filters.</td>
                  </tr>
                ) : (
                  rows.map((row, i) => (
                    <tr key={row.product_id} className="transition-colors hover:bg-slate-50">
                      <td className="px-3 py-2 text-slate-400">{i + 1}</td>
                      <td className="px-3 py-2 font-medium text-slate-800">{row.product_name}</td>
                      <td className="px-3 py-2 text-slate-500">{row.unit ?? '—'}</td>
                      <td className={`border-l border-slate-100 ${NUM_TD} text-slate-600`}>{fmt(row.opening_qty)}</td>
                      <td className={`${NUM_TD} text-slate-600`}><Money value={row.opening_value} /></td>
                      <td className={`border-l border-slate-100 ${NUM_TD} font-semibold ${Number(row.purchase_qty) > 0 ? 'text-green-700' : 'text-slate-300'}`}>{fmt(row.purchase_qty)}</td>
                      <td className={`${NUM_TD} text-slate-600`}><Money value={row.purchase_value} /></td>
                      <td className={`border-l border-slate-100 ${NUM_TD} font-semibold ${Number(row.sales_qty) > 0 ? 'text-red-600' : 'text-slate-300'}`}>{fmt(row.sales_qty)}</td>
                      <td className={`${NUM_TD} text-slate-600`}><Money value={row.sales_value} /></td>
                      <td className={`border-l border-slate-100 ${NUM_TD} font-bold text-slate-800`}>{fmt(row.closing_qty)}</td>
                      <td className={`${NUM_TD} font-bold text-slate-800`}><Money value={row.closing_value} /></td>
                    </tr>
                  ))
                )}
              </tbody>
              {summary && rows.length > 0 && (
                <tfoot>
                  <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                    <td colSpan={3} className="px-3 py-2 text-right uppercase tracking-wider text-slate-500">Total</td>
                    <td className={`border-l border-slate-200 ${NUM_TD}`}></td>
                    <td className={NUM_TD}><Money value={summary.opening_value} /></td>
                    <td className={`border-l border-slate-200 ${NUM_TD}`}></td>
                    <td className={NUM_TD}><Money value={summary.purchase_value} /></td>
                    <td className={`border-l border-slate-200 ${NUM_TD}`}></td>
                    <td className={NUM_TD}><Money value={summary.sales_value} /></td>
                    <td className={`border-l border-slate-200 ${NUM_TD}`}></td>
                    <td className={NUM_TD}><Money value={summary.closing_value} /></td>
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
