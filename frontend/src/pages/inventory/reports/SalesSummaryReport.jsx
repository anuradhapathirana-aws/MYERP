import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Wallet } from 'lucide-react'
import {
  downloadSalesSummaryCsv,
  downloadSalesSummaryPdf,
  getSalesSummaryReport,
} from '../../../api/reports'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import { ExcelBtn, PdfBtn, PrintBtn } from '../../../components/ui/ActionButtons'
import Money from '../../../components/ui/Money'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS } from '../../../utils/fieldStyles'
import { CURRENCY_CODE } from '../../../utils/currency'
import { printPdfBlob } from '../../../utils/pdf'
import { showError } from '../../../utils/alerts'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Sales Report' },
  { label: 'Sales Summary' },
]

// Smart default: today only — the report opens showing today's takings.
const toYmd = (d) => d.toISOString().slice(0, 10)
const todayYmd = toYmd(new Date())

const INITIAL_FILTERS = {
  date_from: todayYmd,
  date_to: todayYmd,
}

const NUM_TD = 'px-3 py-2 text-right tabular-nums'

/** Compact KPI card for a period total — value uses proportional figures, not tabular-nums (a standalone figure, not a table column). */
function StatTile({ label, value, note }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm">
      <div className="text-[10px] font-medium uppercase tracking-wide text-slate-400">{label}</div>
      <div className="mt-0.5 text-base font-semibold text-slate-800">{value}</div>
      {note && <div className="mt-0.5 truncate text-[10px] leading-tight text-slate-400" title={note}>{note}</div>}
    </div>
  )
}

export default function SalesSummaryReport() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf' | 'csv'

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-sales-summary', applied],
    queryFn: () => getSalesSummaryReport(applied),
    placeholderData: (prev) => prev,
  })

  const rows    = data?.rows ?? []
  const summary = data?.summary
  const header  = data?.header

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadSalesSummaryPdf(applied))
      } else {
        const blob = action === 'pdf'
          ? await downloadSalesSummaryPdf(applied)
          : await downloadSalesSummaryCsv(applied)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = `SalesSummary.${action === 'pdf' ? 'pdf' : 'csv'}`
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
            <Wallet size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Sales Summary</h1>
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
      </TableFilter>

      {/* ── Period stat tiles ── */}
      {header && (
        <div className="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
          <StatTile
            label="Number of bills"
            value={header.bill_count.toLocaleString()}
            note="Invoices issued/paid in this period"
          />
          <StatTile
            label="Net sale"
            value={<Money value={header.net_sale} />}
            note="Total invoiced amount, any payment mode"
          />
          <StatTile
            label="Cash sale"
            value={<Money value={header.cash_sale} />}
            note="Cash collected via confirmed receipts"
          />
          <StatTile
            label="Non-cash sale"
            value={<Money value={header.non_cash_sale} />}
            note="Cheque + Bank Deposit + Cards collected"
          />
          <StatTile
            label="Credit sale"
            value={<Money value={header.credit_sale} />}
            note="New credit invoices issued this period"
          />
        </div>
      )}

      {/* ── Date-wise takings table ── */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the sales summary.</div>}

        {!isLoading && !isError && data && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left">
                  <th className="px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                  <th className="w-28 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Cash</th>
                  <th className="w-28 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Credit</th>
                  <th className="w-28 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Cheque</th>
                  <th className="w-32 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Bank Deposit</th>
                  <th className="w-28 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Cards</th>
                  <th className="w-32 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Total Sales</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-12 text-center text-sm text-slate-400">No sales found for the selected period.</td>
                  </tr>
                ) : (
                  rows.map((row) => (
                    <tr key={row.date} className="transition-colors hover:bg-slate-50">
                      <td className="px-3 py-2 font-medium text-slate-800">{row.date}</td>
                      <td className={`${NUM_TD} text-slate-600`}><Money value={row.cash} /></td>
                      <td className={`${NUM_TD} text-slate-600`}><Money value={row.credit} /></td>
                      <td className={`${NUM_TD} text-slate-600`}><Money value={row.cheque} /></td>
                      <td className={`${NUM_TD} text-slate-600`}><Money value={row.bank_deposit} /></td>
                      <td className={`${NUM_TD} text-slate-600`}><Money value={row.cards} /></td>
                      <td className={`${NUM_TD} font-semibold text-slate-800`}><Money value={row.total_sales} /></td>
                    </tr>
                  ))
                )}
              </tbody>
              {summary && rows.length > 0 && (
                <tfoot>
                  <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                    <td className="px-3 py-2 uppercase tracking-wider text-slate-500">Total ({CURRENCY_CODE})</td>
                    <td className={NUM_TD}><Money value={summary.cash} /></td>
                    <td className={NUM_TD}><Money value={summary.credit} /></td>
                    <td className={NUM_TD}><Money value={summary.cheque} /></td>
                    <td className={NUM_TD}><Money value={summary.bank_deposit} /></td>
                    <td className={NUM_TD}><Money value={summary.cards} /></td>
                    <td className={NUM_TD}><Money value={summary.total_sales} /></td>
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
