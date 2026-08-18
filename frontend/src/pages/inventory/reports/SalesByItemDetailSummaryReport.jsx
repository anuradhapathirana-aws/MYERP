import { Fragment, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { ListTree } from 'lucide-react'
import {
  downloadSalesByItemDetailSummaryCsv,
  downloadSalesByItemDetailSummaryPdf,
  getSalesByItemDetailSummaryReport,
} from '../../../api/reports'
import { getAllCategories } from '../../../api/categories'
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
  { label: 'Sales Report' },
  { label: 'Sales By Item Detail Summary' },
]

// Date From / Date To are required — default both to today so the report
// always has a valid (if narrow) range on first load.
const toYmd = (d) => d.toISOString().slice(0, 10)
const todayYmd = toYmd(new Date())

const INITIAL_FILTERS = {
  date_from: todayYmd,
  date_to: todayYmd,
  category_id: '',
  product_id: '',
}

function fmt(n) {
  return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const NUM_TD = 'px-3 py-2 text-right tabular-nums'

export default function SalesByItemDetailSummaryReport() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf' | 'csv'

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

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
  // Product dropdown cascades from the selected category
  const productOptions = (productsData ?? [])
    .filter((p) => !draft.category_id || String(p.category_id) === String(draft.category_id))
    .map((p) => ({ value: p.id, label: `${p.product_code} - ${p.name}` }))

  const setCategory = (val) => setDraft((d) => {
    const product = (productsData ?? []).find((p) => String(p.id) === String(d.product_id))
    const productStillValid = !val || (product && String(product.category_id) === String(val))
    return { ...d, category_id: val, product_id: productStillValid ? d.product_id : '' }
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-sales-by-item-detail-summary', applied],
    queryFn: () => getSalesByItemDetailSummaryReport(applied),
    placeholderData: (prev) => prev,
    enabled: Boolean(applied.date_from && applied.date_to),
  })

  const categories = data?.categories ?? []
  const summary    = data?.summary

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadSalesByItemDetailSummaryPdf(applied))
      } else {
        const blob = action === 'pdf'
          ? await downloadSalesByItemDetailSummaryPdf(applied)
          : await downloadSalesByItemDetailSummaryCsv(applied)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = `SalesByItemDetailSummary.${action === 'pdf' ? 'pdf' : 'csv'}`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the sales detail summary.`)
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
            <ListTree size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Sales By Item Detail Summary</h1>
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
        <FilterField label="Category">
          <FilterSearchSelect
            value={draft.category_id}
            onChange={setCategory}
            options={(categoriesData ?? []).map((c) => ({ value: c.id, label: c.category_name }))}
            placeholder="All categories"
          />
        </FilterField>
        <FilterField label="Product">
          <FilterSearchSelect
            value={draft.product_id}
            onChange={(val) => setDraft((d) => ({ ...d, product_id: val }))}
            options={productOptions}
            placeholder="All products"
            wide
          />
        </FilterField>
      </TableFilter>

      {/* ── Category > Product > Transaction table ── */}
      <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {(!applied.date_from || !applied.date_to) && (
          <div className="flex items-center justify-center py-14 text-sm text-red-500">Date From and Date To are required.</div>
        )}
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the sales detail summary.</div>}

        {!isLoading && !isError && data && (
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-slate-200 bg-slate-50 text-left">
                  <th className="px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Description</th>
                  <th className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Colour</th>
                  <th className="w-20 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                  <th className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                  <th className="w-24 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Number</th>
                  <th className="px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Customer Name</th>
                  <th className="w-16 px-3 py-1.5 font-semibold uppercase tracking-wider text-slate-500">Unit</th>
                  <th className="w-20 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Qty</th>
                  <th className="w-24 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Price</th>
                  <th className="w-32 px-3 py-1.5 text-right font-semibold uppercase tracking-wider text-slate-500">Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {categories.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="px-4 py-12 text-center text-sm text-slate-400">No sales found for the selected filters.</td>
                  </tr>
                ) : (
                  categories.map((cat) => (
                    <Fragment key={cat.category_id}>
                      <tr className="bg-slate-50">
                        <td colSpan={10} className="px-3 py-1.5 font-bold uppercase tracking-wide text-slate-700">{cat.category_name}</td>
                      </tr>
                      {cat.products.map((prod) => (
                        <Fragment key={prod.product_id}>
                          <tr>
                            <td colSpan={10} className="px-3 py-1.5 pl-6 font-semibold text-slate-700">{prod.product_name}</td>
                          </tr>
                          {prod.transactions.map((txn, i) => (
                            <tr key={`${prod.product_id}-${i}`} className="transition-colors hover:bg-slate-50">
                              <td className="px-3 py-2 pl-10 text-slate-400">—</td>
                              <td className="px-3 py-2 text-slate-500">{txn.attribute_name ?? '—'}</td>
                              <td className="px-3 py-2 text-slate-500">{txn.type}</td>
                              <td className="px-3 py-2 text-slate-500">{txn.date}</td>
                              <td className="px-3 py-2 text-slate-500">{txn.number}</td>
                              <td className="px-3 py-2 text-slate-700">{txn.customer_name ?? '—'}</td>
                              <td className="px-3 py-2 text-slate-500">{txn.unit ?? '—'}</td>
                              <td className={`${NUM_TD} text-slate-600`}>{fmt(txn.quantity)}</td>
                              <td className={`${NUM_TD} text-slate-600`}><Money value={txn.price} /></td>
                              <td className={`${NUM_TD} text-slate-600`}><Money value={txn.amount} /></td>
                            </tr>
                          ))}
                          <tr className="font-semibold text-slate-800">
                            <td colSpan={9} className="px-3 py-1.5 text-right uppercase tracking-wider text-slate-500">Total {prod.product_name}</td>
                            <td className={NUM_TD}><Money value={prod.product_amount} /></td>
                          </tr>
                        </Fragment>
                      ))}
                      <tr className="border-b border-slate-200 font-bold text-slate-800">
                        <td colSpan={9} className="px-3 py-1.5 text-right uppercase tracking-wider text-slate-500">Total {cat.category_name}</td>
                        <td className={NUM_TD}><Money value={cat.category_amount} /></td>
                      </tr>
                    </Fragment>
                  ))
                )}
              </tbody>
              {summary && categories.length > 0 && (
                <tfoot>
                  <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                    <td colSpan={7} className="px-3 py-2 text-right uppercase tracking-wider text-slate-500">Grand Total</td>
                    <td className={NUM_TD}>{fmt(summary.total_qty)}</td>
                    <td className={NUM_TD}></td>
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
