import { Fragment, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Truck } from 'lucide-react'
import {
  downloadSupplierWiseGrnDetailsCsv,
  downloadSupplierWiseGrnDetailsPdf,
  getSupplierWiseGrnDetailsReport,
} from '../../../api/reports'
import { getAllSuppliers } from '../../../api/suppliers'
import { getAllProducts } from '../../../api/products'
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
  { label: 'Stock Report' },
  { label: 'Supplier Wise GRN Details' },
]

// Date From / Date To are required — default both to today so the report
// always has a valid (if narrow) range on first load.
const toYmd = (d) => d.toISOString().slice(0, 10)
const todayYmd = toYmd(new Date())

const INITIAL_FILTERS = {
  date_from: todayYmd,
  date_to: todayYmd,
  supplier_id: '',
  product_id: '',
}

function fmt(n) {
  return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

function fmtPct(n) {
  return `${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`
}

const NUM_TD = 'px-3 py-2 text-right tabular-nums'

export default function SupplierWiseGrnDetailsReport() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf' | 'csv'

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: suppliersData } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn: getAllSuppliers,
    staleTime: Infinity,
  })

  const { data: productsData } = useQuery({
    queryKey: ['products-all'],
    queryFn: getAllProducts,
    staleTime: Infinity,
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-supplier-wise-grn-details', applied],
    queryFn: () => getSupplierWiseGrnDetailsReport(applied),
    placeholderData: (prev) => prev,
    enabled: Boolean(applied.date_from && applied.date_to),
  })

  const suppliers = data?.suppliers ?? []
  const summary    = data?.summary

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadSupplierWiseGrnDetailsPdf(applied))
      } else {
        const blob = action === 'pdf'
          ? await downloadSupplierWiseGrnDetailsPdf(applied)
          : await downloadSupplierWiseGrnDetailsCsv(applied)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = `SupplierWiseGrnDetails.${action === 'pdf' ? 'pdf' : 'csv'}`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the supplier wise GRN details report.`)
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
            <Truck size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Supplier Wise GRN Details</h1>
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
        <FilterField label="Date From *">
          <input type="date" required className={FILTER_INPUT_CLS} value={draft.date_from} onChange={(e) => setDraft((d) => ({ ...d, date_from: e.target.value }))} />
        </FilterField>
        <FilterField label="Date To *">
          <input type="date" required className={FILTER_INPUT_CLS} value={draft.date_to} onChange={(e) => setDraft((d) => ({ ...d, date_to: e.target.value }))} />
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
        <FilterField label="Item">
          <FilterSearchSelect
            value={draft.product_id}
            onChange={(val) => setDraft((d) => ({ ...d, product_id: val }))}
            options={(productsData ?? []).map((p) => ({ value: p.id, label: `${p.product_code} - ${p.name}` }))}
            placeholder="All items"
            wide
          />
        </FilterField>
      </TableFilter>

      {/* ── Supplier > GRN > Item table ── */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {(!applied.date_from || !applied.date_to) && (
          <div className="flex items-center justify-center py-14 text-sm text-red-500">Date From and Date To are required.</div>
        )}
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the supplier wise GRN details report.</div>}

        {!isLoading && !isError && data && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left">
                  <th className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Item Code</th>
                  <th className="px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Item Name</th>
                  <th className="w-20 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Qty</th>
                  <th className="w-16 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">UOM</th>
                  <th className="w-24 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Unit Price</th>
                  <th className="w-20 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Discount</th>
                  <th className="w-20 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Tax</th>
                  <th className="w-32 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {suppliers.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-12 text-center text-sm text-slate-400">No GRNs found for the selected filters.</td>
                  </tr>
                ) : (
                  suppliers.map((sup) => (
                    <Fragment key={sup.supplier_id}>
                      <tr className="bg-slate-50">
                        <td colSpan={8} className="px-3 py-1.5 font-bold uppercase tracking-wide text-slate-700">{sup.supplier_name}</td>
                      </tr>
                      {sup.grns.map((grn) => (
                        <Fragment key={grn.grn_id}>
                          <tr className="bg-slate-50/60 text-slate-500">
                            <td colSpan={3} className="px-3 py-1.5 pl-6 font-medium">GRN No: {grn.grn_no}</td>
                            <td colSpan={2} className="px-3 py-1.5 font-medium">Date: {grn.grn_date}</td>
                            <td colSpan={2} className="px-3 py-1.5 font-medium">PO No: {grn.po_no ?? '—'}</td>
                            <td className="px-3 py-1.5 text-right font-medium">Qty: {fmt(grn.total_qty)}</td>
                          </tr>
                          {grn.items.map((item, i) => (
                            <tr key={`${grn.grn_id}-${i}`} className="transition-colors hover:bg-slate-50">
                              <td className="px-3 py-2 pl-10 text-slate-500">{item.product_code}</td>
                              <td className="px-3 py-2 text-slate-800">{item.item_name}</td>
                              <td className={`${NUM_TD} text-slate-600`}>{fmt(item.quantity)}</td>
                              <td className="px-3 py-2 text-slate-500">{item.unit ?? '—'}</td>
                              <td className={`${NUM_TD} text-slate-600`}><Money value={item.unit_price} /></td>
                              <td className={`${NUM_TD} text-slate-600`}>{fmtPct(item.discount)}</td>
                              <td className={`${NUM_TD} text-slate-600`}>{fmtPct(item.tax)}</td>
                              <td className={`${NUM_TD} text-slate-600`}><Money value={item.amount} /></td>
                            </tr>
                          ))}
                          <tr className="font-semibold text-slate-800">
                            <td colSpan={7} className="px-3 py-1.5 text-right uppercase tracking-wider text-slate-500">TOTAL</td>
                            <td className={NUM_TD}><Money value={grn.grn_amount} /></td>
                          </tr>
                        </Fragment>
                      ))}
                      <tr className="border-b border-slate-200 font-bold text-slate-800">
                        <td colSpan={7} className="px-3 py-1.5 text-right uppercase tracking-wider text-slate-500">Total {sup.supplier_name}</td>
                        <td className={NUM_TD}><Money value={sup.supplier_amount} /></td>
                      </tr>
                    </Fragment>
                  ))
                )}
              </tbody>
              {summary && suppliers.length > 0 && (
                <tfoot>
                  <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                    <td colSpan={7} className="px-3 py-2 text-right uppercase tracking-wider text-slate-500">Grand Total</td>
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
