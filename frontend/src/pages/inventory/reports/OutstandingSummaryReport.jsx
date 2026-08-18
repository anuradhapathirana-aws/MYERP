import { Fragment, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight, Receipt } from 'lucide-react'
import {
  downloadOutstandingSummaryCsv,
  downloadOutstandingSummaryPdf,
  getOutstandingSummaryReport,
} from '../../../api/reports'
import { getAllCustomers } from '../../../api/customers'
import { getInvoicePaymentHistory } from '../../../api/invoices'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { ExcelBtn, PdfBtn, PrintBtn } from '../../../components/ui/ActionButtons'
import Money from '../../../components/ui/Money'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_SELECT_CLS } from '../../../utils/fieldStyles'
import { printPdfBlob } from '../../../utils/pdf'
import { showError } from '../../../utils/alerts'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Sales Report' },
  { label: 'Outstanding Summary' },
]

const INITIAL_FILTERS = {
  customer_id: '',
  overdue_only: '',
}

const NUM_TD = 'px-3 py-2 text-right tabular-nums'

/** Compact one-line label for a settlement (Receipt Detail) row. */
function settlementLabel(s) {
  const parts = [s.mode || 'Payment']
  if (s.bank_name) parts.push(s.bank_name)
  if (s.reference_no) parts.push(`Ref# ${s.reference_no}`)
  if (s.instrument_date) parts.push(s.instrument_date)
  return parts.join(' · ')
}

/** Confirmed-receipt payoff trail for one invoice — fetched only once its row is expanded. */
function PaymentHistoryPanel({ invoiceId }) {
  const navigate = useNavigate()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['invoice-payment-history', invoiceId],
    queryFn: () => getInvoicePaymentHistory(invoiceId),
  })

  return (
    <tr className="bg-slate-50/60">
      <td colSpan={9} className="px-3 py-2">
        {isLoading && <div className="py-3 text-center text-[11px] text-slate-400">Loading payment history…</div>}
        {isError && <div className="py-3 text-center text-[11px] text-red-500">Failed to load payment history.</div>}
        {!isLoading && !isError && (
          !data?.length ? (
            <div className="py-3 text-center text-[11px] text-slate-400">No payments recorded yet.</div>
          ) : (
            <table className="w-full text-[11px]">
              <thead>
                <tr className="text-left text-slate-500">
                  <th className="px-2 py-1 font-semibold uppercase tracking-wider">Receipt No</th>
                  <th className="px-2 py-1 font-semibold uppercase tracking-wider">Date</th>
                  <th className="px-2 py-1 text-right font-semibold uppercase tracking-wider">Amount</th>
                  <th className="px-2 py-1 text-right font-semibold uppercase tracking-wider">Discount</th>
                  <th className="px-2 py-1 text-right font-semibold uppercase tracking-wider">Balance After</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-200">
                {data.map((p) => (
                  <Fragment key={p.receipt_id}>
                    <tr
                      className="cursor-pointer transition-colors hover:bg-indigo-50"
                      onClick={() => navigate(`/inventory/customer-receipts/${p.receipt_id}/edit`)}
                      title="Open this receipt"
                    >
                      <td className="px-2 py-1 font-medium text-indigo-600">{p.receipt_no}</td>
                      <td className="px-2 py-1 text-slate-500">{p.receipt_date}</td>
                      <td className="px-2 py-1 text-right tabular-nums text-slate-600"><Money value={p.receipt_amount} /></td>
                      <td className="px-2 py-1 text-right tabular-nums text-slate-500"><Money value={p.discount} /></td>
                      <td className="px-2 py-1 text-right tabular-nums font-semibold text-slate-800"><Money value={p.outstanding_after} /></td>
                    </tr>
                    {p.settlements?.length > 0 && (
                      <tr className="bg-white">
                        <td></td>
                        <td colSpan={4} className="px-2 pb-1.5 pt-0">
                          <div className="flex flex-wrap gap-1">
                            {p.settlements.map((s, i) => (
                              <span
                                key={i}
                                className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600"
                                title={s.remark || undefined}
                              >
                                {settlementLabel(s)}: <Money value={s.amount} />
                              </span>
                            ))}
                          </div>
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </tbody>
            </table>
          )
        )}
      </td>
    </tr>
  )
}

export default function OutstandingSummaryReport() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf' | 'csv'
  // Only one row's payment history panel is open at a time — clicking a different
  // row collapses whichever was open and expands the new one.
  const [expandedId, setExpandedId] = useState(null)

  const toggleExpand = (invoiceId) => {
    setExpandedId((prev) => (prev === invoiceId ? null : invoiceId))
  }

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: customersData } = useQuery({
    queryKey: ['customers-all'],
    queryFn: getAllCustomers,
    staleTime: Infinity,
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-outstanding-summary', applied],
    queryFn: () => getOutstandingSummaryReport(applied),
    placeholderData: (prev) => prev,
  })

  const customers = data?.customers ?? []
  const summary    = data?.summary

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadOutstandingSummaryPdf(applied))
      } else {
        const blob = action === 'pdf'
          ? await downloadOutstandingSummaryPdf(applied)
          : await downloadOutstandingSummaryCsv(applied)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = `OutstandingSummary.${action === 'pdf' ? 'pdf' : 'csv'}`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the outstanding summary.`)
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
            <Receipt size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Outstanding Summary</h1>
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
        <FilterField label="Customer">
          <FilterSearchSelect
            value={draft.customer_id}
            onChange={(val) => setDraft((d) => ({ ...d, customer_id: val }))}
            options={(customersData ?? []).map((c) => ({ value: c.id, label: c.name }))}
            placeholder="All customers"
            wide
          />
        </FilterField>
        <FilterField label="Overdue Only">
          <select className={FILTER_SELECT_CLS} value={draft.overdue_only} onChange={(e) => setDraft((d) => ({ ...d, overdue_only: e.target.value }))}>
            <option value="">All</option>
            <option value="1">Overdue only</option>
          </select>
        </FilterField>
      </TableFilter>

      {/* ── Customer > Outstanding invoice table ── */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the outstanding summary.</div>}

        {!isLoading && !isError && data && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left">
                  <th className="px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Invoice No</th>
                  <th className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                  <th className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Due Date</th>
                  <th className="w-20 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Days O/D</th>
                  <th className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">SO No</th>
                  <th className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">DO No</th>
                  <th className="w-28 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                  <th className="w-28 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Received</th>
                  <th className="w-32 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Outstanding</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {customers.length === 0 ? (
                  <tr>
                    <td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">No outstanding invoices found.</td>
                  </tr>
                ) : (
                  customers.map((cust) => (
                    <Fragment key={cust.customer_id}>
                      <tr className="bg-slate-50">
                        <td colSpan={9} className="px-3 py-1.5 font-bold uppercase tracking-wide text-slate-700">
                          {cust.customer_name}
                          <span className="ml-2 font-normal normal-case text-slate-400">({cust.invoice_count} invoice{cust.invoice_count === 1 ? '' : 's'})</span>
                        </td>
                      </tr>
                      {cust.invoices.map((inv) => {
                        const isExpanded = expandedId === inv.invoice_id
                        return (
                          <Fragment key={inv.invoice_id}>
                            <tr
                              className="cursor-pointer transition-colors hover:bg-slate-50"
                              onClick={() => toggleExpand(inv.invoice_id)}
                              title="Click to view payment history"
                            >
                              <td className="flex items-center gap-1 px-3 py-2 pl-6 font-medium text-slate-800">
                                {isExpanded ? <ChevronDown size={12} className="shrink-0 text-slate-400" /> : <ChevronRight size={12} className="shrink-0 text-slate-400" />}
                                {inv.invoice_no}
                              </td>
                              <td className="px-3 py-2 text-slate-500">{inv.invoice_date}</td>
                              <td className="px-3 py-2 text-slate-500">{inv.due_date ?? '—'}</td>
                              <td className={`${NUM_TD} ${inv.days_overdue > 0 ? 'font-semibold text-red-600' : 'text-slate-400'}`}>
                                {inv.days_overdue ?? '—'}
                              </td>
                              <td className="px-3 py-2 text-slate-500">{inv.so_no ?? '—'}</td>
                              <td className="px-3 py-2 text-slate-500">{inv.do_no ?? '—'}</td>
                              <td className={`${NUM_TD} text-slate-600`}><Money value={inv.amount} /></td>
                              <td className={`${NUM_TD} text-slate-600`}><Money value={inv.received} /></td>
                              <td className={`${NUM_TD} font-semibold text-slate-800`}><Money value={inv.outstanding} /></td>
                            </tr>
                            {isExpanded && <PaymentHistoryPanel invoiceId={inv.invoice_id} />}
                          </Fragment>
                        )
                      })}
                      <tr className="border-b border-slate-200 font-bold text-slate-800">
                        <td colSpan={8} className="px-3 py-1.5 text-right uppercase tracking-wider text-slate-500">Total {cust.customer_name}</td>
                        <td className={NUM_TD}><Money value={cust.customer_outstanding} /></td>
                      </tr>
                    </Fragment>
                  ))
                )}
              </tbody>
              {summary && customers.length > 0 && (
                <tfoot>
                  <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                    <td colSpan={8} className="px-3 py-2 text-right uppercase tracking-wider text-slate-500">Grand Total</td>
                    <td className={NUM_TD}><Money value={summary.total_outstanding} /></td>
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
