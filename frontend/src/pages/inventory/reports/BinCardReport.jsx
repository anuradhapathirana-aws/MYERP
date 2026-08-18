import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BookOpen } from 'lucide-react'
import { downloadBinCardCsv, downloadBinCardPdf, getBinCardReport } from '../../../api/reports'
import { getAllLocations } from '../../../api/locations'
import { getAllStores } from '../../../api/stores'
import { getAllProducts } from '../../../api/products'
import { getAllAttributes } from '../../../api/attributes'
import Breadcrumb from '../../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../../components/TableFilter'
import CollapsibleCard from '../../../components/ui/CollapsibleCard'
import FilterSearchSelect from '../../../components/ui/FilterSearchSelect'
import { ExcelBtn, PdfBtn, PrintBtn } from '../../../components/ui/ActionButtons'
import { useTableFilter } from '../../../hooks/useTableFilter'
import { FILTER_INPUT_CLS } from '../../../utils/fieldStyles'
import { printPdfBlob } from '../../../utils/pdf'
import { showError } from '../../../utils/alerts'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Reports' },
  { label: 'Bin Card' },
]

const INITIAL_FILTERS = {
  product_id: '',
  attribute_id: '',
  location_id: '',
  store_id: '',
  date_from: '',
  date_to: '',
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

export default function BinCardReport() {
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

  const setLocation = (val) => setDraft((d) => {
    const store = (storesData ?? []).find((s) => String(s.id) === String(d.store_id))
    const storeStillValid = !val || (store && String(store.location_id) === String(val))
    return { ...d, location_id: val, store_id: storeStillValid ? d.store_id : '' }
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['report-bin-card', applied],
    queryFn: () => getBinCardReport(applied),
    enabled: Boolean(applied.product_id),
    placeholderData: (prev) => prev,
  })

  const header  = data?.header
  const opening = data?.opening
  const rows    = data?.rows ?? []
  const summary = data?.summary

  const handleApply = () => {
    if (!draft.product_id) {
      showError('Select a product to generate the bin card.')
      return
    }
    apply()
  }

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadBinCardPdf(applied))
      } else {
        const blob = action === 'pdf' ? await downloadBinCardPdf(applied) : await downloadBinCardCsv(applied)
        const url  = URL.createObjectURL(blob)
        const a    = document.createElement('a')
        a.href     = url
        a.download = `BinCard_${header?.product_code ?? 'product'}.${action === 'pdf' ? 'pdf' : 'csv'}`
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the bin card.`)
    } finally {
      setExportBusy(null)
    }
  }

  const exportsDisabled = !applied.product_id || !data || Boolean(exportBusy)

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <BookOpen size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Bin Card Report</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <div className="flex items-center gap-1.5">
          <PrintBtn onClick={() => handleExport('print')} disabled={exportsDisabled} title="Print" />
          <PdfBtn onClick={() => handleExport('pdf')} disabled={exportsDisabled} title="Download PDF" />
          <ExcelBtn onClick={() => handleExport('csv')} disabled={exportsDisabled} title="Download Excel (CSV)" />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={handleApply} onClear={() => clear()} activeCount={activeCount}>
        <FilterField label="Product *">
          <FilterSearchSelect
            value={draft.product_id}
            onChange={(val) => setDraft((d) => ({ ...d, product_id: val }))}
            options={productOptions} wide
            placeholder="Select product…"
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
            onChange={setLocation}
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
        <FilterField label="Date From">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_from} onChange={(e) => setDraft((d) => ({ ...d, date_from: e.target.value }))} />
        </FilterField>
        <FilterField label="Date To">
          <input type="date" className={FILTER_INPUT_CLS} value={draft.date_to} onChange={(e) => setDraft((d) => ({ ...d, date_to: e.target.value }))} />
        </FilterField>
      </TableFilter>

      {!applied.product_id ? (
        <div className="mt-3 rounded-xl border border-slate-200 bg-white py-16 text-center text-sm text-slate-400 shadow-sm">
          Select a product in the filters above to generate the bin card.
        </div>
      ) : (
        <>
          {/* ── Report header (company + product info) — collapsed by default ── */}
          {header && (
            <CollapsibleCard title="Bin Card Details" className="mt-3">
              <div className="flex items-start justify-between gap-4 border-b border-slate-100 pb-2">
                <div>
                  <div className="text-sm font-bold text-slate-800">{header.company_name}</div>
                  <div className="text-[11px] text-slate-500">{header.company_address}</div>
                  {header.company_email && <div className="text-[11px] text-slate-500">{header.company_email}</div>}
                </div>
                <div className="text-right text-[11px] text-slate-500">
                  <div className="text-sm font-bold text-slate-800">Bin Card</div>
                  <div>Period: {header.date_from ?? 'Beginning'} — {header.date_to ?? 'To Date'}</div>
                </div>
              </div>
              <div className="grid grid-cols-1 gap-x-6 gap-y-1 pt-2 md:grid-cols-2 lg:grid-cols-3">
                <HeaderItem label="Product Code" value={header.product_code} />
                <HeaderItem label="Location" value={header.location_name ?? 'All'} />
                <HeaderItem label="Generated By" value={header.generated_by} />
                <HeaderItem label="Product Name" value={header.product_name} />
                <HeaderItem label="Store" value={header.store_name ?? 'All'} />
                <HeaderItem label="Generated Time" value={header.generated_at} />
                {/* Every quantity on this card is in the stocking UOM; every price in this currency. */}
                <HeaderItem label="UOM" value={header.uom} />
                <HeaderItem label="Currency" value={header.currency_code ? `${header.currency_code} (${header.currency_symbol})` : null} />
                <HeaderItem label="Stock In Hand" value={`${fmt(header.stock_in_hand)}${header.uom ? ` ${header.uom}` : ''}`} />
                <HeaderItem label="Reorder Level" value={fmt(header.reorder_level)} />
                <HeaderItem label="Reorder Qty / Period" value={`${fmt(header.reorder_qty)} / ${header.reorder_period ?? '—'}`} />
              </div>
            </CollapsibleCard>
          )}

          {/* ── Ledger table ── */}
          <div className="mt-3 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
            {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load the bin card.</div>}

            {!isLoading && !isError && data && (
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Date</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Description</th>
                      <th className="w-32 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Document No</th>
                      <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Colour</th>
                      {/* Cells stay bare numbers — the header names the unit/currency once. */}
                      <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">
                        Unit Price{header?.currency_code && <span className="ml-0.5 font-semibold normal-case text-indigo-500">({header.currency_code})</span>}
                      </th>
                      <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">
                        Stock IN{header?.uom && <span className="ml-0.5 font-semibold normal-case text-indigo-500">({header.uom})</span>}
                      </th>
                      <th className="w-28 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">
                        Stock Out{header?.uom && <span className="ml-0.5 font-semibold normal-case text-indigo-500">({header.uom})</span>}
                      </th>
                      <th className="w-32 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">
                        Stock Balance{header?.uom && <span className="ml-0.5 font-semibold normal-case text-indigo-500">({header.uom})</span>}
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {opening?.show && (
                      <tr className="bg-slate-50/70 font-semibold italic">
                        <td className="px-3 py-2"></td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-500">{header?.date_from}</td>
                        <td className="px-3 py-2 text-slate-700">Opening Balance</td>
                        <td className="px-3 py-2 text-slate-300">—</td>
                        <td className="px-3 py-2 text-slate-300">—</td>
                        <td className="px-3 py-2 text-right text-slate-300">—</td>
                        <td className="px-3 py-2 text-right text-slate-300">—</td>
                        <td className="px-3 py-2 text-right text-slate-300">—</td>
                        <td className="px-3 py-2 text-right font-bold text-slate-800 tabular-nums">{fmt(opening.balance)}</td>
                      </tr>
                    )}
                    {rows.length === 0 && !opening?.show ? (
                      <tr>
                        <td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">No stock transactions found for the selected filters.</td>
                      </tr>
                    ) : (
                      rows.map((row, i) => (
                        <tr key={row.id} className="transition-colors hover:bg-slate-50">
                          <td className="px-3 py-2 text-slate-400">{i + 1}</td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-500">{row.date}</td>
                          <td className="px-3 py-2 font-medium text-slate-700">{row.description}</td>
                          <td className="px-3 py-2 font-mono text-indigo-600">{row.document_no}</td>
                          <td className="px-3 py-2">
                            {row.color
                              ? <span className="rounded-full bg-slate-100 px-1.5 py-px text-[10px] font-semibold text-slate-600">{row.color}</span>
                              : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2 text-right tabular-nums text-slate-600">
                            {row.unit_price != null ? fmt(row.unit_price) : <span className="text-slate-300">—</span>}
                          </td>
                          <td className={`px-3 py-2 text-right font-semibold tabular-nums ${Number(row.qty_in) > 0 ? 'text-green-700' : 'text-slate-300'}`}>
                            {Number(row.qty_in) > 0 ? fmt(row.qty_in) : '—'}
                          </td>
                          <td className={`px-3 py-2 text-right font-semibold tabular-nums ${Number(row.qty_out) > 0 ? 'text-red-600' : 'text-slate-300'}`}>
                            {Number(row.qty_out) > 0 ? fmt(row.qty_out) : '—'}
                          </td>
                          <td className="px-3 py-2 text-right font-bold text-slate-800 tabular-nums">{fmt(row.balance)}</td>
                        </tr>
                      ))
                    )}
                  </tbody>
                  {summary && (
                    <tfoot>
                      <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                        <td colSpan={6} className="px-3 py-2 text-right uppercase tracking-wider text-slate-500">Total</td>
                        <td className="px-3 py-2 text-right tabular-nums text-green-700">{fmt(summary.total_in)}</td>
                        <td className="px-3 py-2 text-right tabular-nums text-red-600">{fmt(summary.total_out)}</td>
                        <td className="px-3 py-2 text-right tabular-nums">{fmt(summary.closing_balance)}</td>
                      </tr>
                    </tfoot>
                  )}
                </table>
              </div>
            )}
          </div>
        </>
      )}
    </div>
  )
}
