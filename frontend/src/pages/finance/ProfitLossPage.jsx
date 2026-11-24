import { useCallback, useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  AlertTriangle, ArrowDownRight, ArrowUpRight, Banknote, Building2, Check, Coins, Landmark,
  Package, Pencil, PiggyBank, RotateCcw, ShoppingCart, TrendingDown, TrendingUp, X,
} from 'lucide-react'
import { downloadProfitLossPdf, getProfitLoss } from '../../api/profitLoss'
import { getAllLocations } from '../../api/locations'
import Breadcrumb from '../../components/Breadcrumb'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import HelpTip from '../../components/ui/HelpTip'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import { PdfBtn, PrintBtn } from '../../components/ui/ActionButtons'
import { usePermissions } from '../../hooks/usePermissions'
import { showError } from '../../utils/alerts'
import { CURRENCY, fmtMoney } from '../../utils/currency'
import { FILTER_INPUT_CLS, LABEL_CLS } from '../../utils/fieldStyles'
import { downloadPdfBlob, printPdfBlob } from '../../utils/pdf'
import AccountTransactionsModal from './AccountTransactionsModal'

const CRUMBS = [{ label: 'Finance' }, { label: 'Financial Statements' }, { label: 'Profit & Loss' }]

const SHORTCUTS = [
  { keys: ['Alt', 'C'], desc: 'Compare period on / off' },
  { keys: ['Alt', 'P'], desc: 'Download PDF' },
  { keys: ['Alt', 'R'], desc: 'Reset filters' },
  { keys: ['Enter'], desc: 'Apply a typed stock figure' },
  { keys: ['Esc'], desc: 'Cancel stock edit / close popup' },
]

/**
 * Hover notes, in plain words, for everything an admin may not know. One
 * place, so the wording stays consistent between the tiles and the rows.
 */
const NOTE = {
  from:        'First day of the report. It cannot be before the Finance go-live date.',
  location:    'Only journals and stock of this location. Leave empty for the whole company.',
  compare:     'On: a second set of columns for another period, so you can see what changed. It starts as the period just before; change its dates to compare with any period, e.g. the same month last year.',
  details:     'Each item on its own: every account, plus opening and closing stock.',
  amount:      'Section totals and results (Total Revenue, Cost of Goods Sold, Gross Profit, Net Profit…).',
  change:      'Change against the comparative period. Green is good for the business (more income or profit, or less cost); red is bad.',
  revenue:     'Sales income for the period, WITHOUT VAT and SSCL (those belong to the government). Sales returns and discounts allowed are deducted here.',
  cos:         'What the goods you sold cost you: Opening Stock + Purchases + direct costs − Closing Stock.',
  opening:     'Stock on hand when the period starts, i.e. at the END of the day BEFORE the From date (From 01-11 → end of 31-10). It is the same figure as the previous period’s closing stock. Valued from Inventory at average GRN cost. Click the pencil to type a stock-count value instead.',
  purchases:   'Goods received on GRNs (net of claimable tax), less goods returned to suppliers. These are the accounts mapped to Purchases and Purchase Returns.',
  closing:     'Stock still on hand at the end of the last day. It is deducted because those goods are not sold yet. Click the pencil to type a stock-count value.',
  cogs:        'Opening Stock + Purchases + direct costs − Closing Stock = the cost of the goods actually sold in the period.',
  gross:       'Revenue − Cost of Goods Sold: the profit from buying and selling, before running costs.',
  otherIncome: 'Income that is not from selling goods, such as discounts received from suppliers or bank interest.',
  overheads:   'Running costs of the business, grouped by the P&L Section of their account category: administration, selling & distribution, finance and other.',
  beforeTax:   'Gross Profit + Other Income − Overheads.',
  taxation:    'Income tax on the company’s profit, posted by Journal Voucher at period end. This is NOT the VAT on your invoices: VAT is owed to the government and sits on the Balance Sheet.',
  afterTax:    'Net Profit before Taxation − Taxation.',
  reserves:    'Profit set aside to a reserve, posted by Journal Voucher (Dr Retained Earnings / Cr General Reserve).',
  forPeriod:   'The final result: what the business earned (or lost) in the period after tax and reserves.',
  entered:     'You typed this value; it is used instead of the automatic one but is not saved.',
  margin:      'This result as a percentage of Revenue.',
}

const COMPARE_KEY = 'pl.compare'

const isoDate    = (d) => d.toLocaleDateString('en-CA')
const monthStart = () => { const d = new Date(); d.setDate(1); return isoDate(d) }

const defaultFilters = () => ({ date_from: monthStart(), date_to: isoDate(new Date()), location_id: '' })
const EMPTY_STOCK    = { opening: '', closing: '', compare_opening: '', compare_closing: '' }

const readCompare = () => {
  try { return localStorage.getItem(COMPARE_KEY) !== '0' } catch { return true }
}

/** Accounting style: negatives in brackets, blank when there is no figure. */
const amt = (n) => {
  if (n === null || n === undefined) return ''
  const v = Number(n) || 0
  return v < 0 ? `(${fmtMoney(-v)})` : fmtMoney(v)
}

const fmtDate = (d) => (d ? d.split('-').reverse().join('-') : '')

/** Percentage change from previous to current; null when there is no base to compare with. */
const changePct = (cur, prev) => {
  const c = Number(cur) || 0
  const p = Number(prev) || 0
  if (prev === null || prev === undefined || p === 0) return null
  return ((c - p) / Math.abs(p)) * 100
}

/** A figure as a percentage of revenue, or null without revenue. */
const ofRevenue = (n, revenue) => {
  const r = Number(revenue) || 0
  return r ? ((Number(n) || 0) / r) * 100 : null
}

/** The first validation message of a response body. */
const firstMessage = (body, fallback) => {
  const errors = body?.errors
  return (errors && Object.values(errors)[0]?.[0]) ?? body?.message ?? fallback
}

const jsonMessage = (err, fallback) => firstMessage(err?.response?.data, fallback)

/** Same, for a request made with responseType 'blob' (the PDF): the body is a Blob. */
async function blobMessage(err, fallback) {
  const body = err?.response?.data
  if (!(body instanceof Blob)) return firstMessage(body, fallback)
  try { return firstMessage(JSON.parse(await body.text()), fallback) } catch { return fallback }
}

const fileSafe = (s) => String(s ?? '').replace(/[^A-Za-z0-9-]+/g, '_')

/**
 * Finance > Financial Statements > Profit & Loss, in the periodic (trading
 * account) format.
 *
 * Everything but stock comes from posted journals (the same lines as the
 * Trial Balance). Opening and closing stock come from Inventory, valued by
 * document date at weighted average GRN cost; the user can type over either
 * figure (a stock count, or old stock not in the system). Typed figures are
 * not saved -- they travel with the request, so the PDF prints exactly what
 * is on screen.
 */
export default function ProfitLossPage() {
  const { can } = usePermissions()
  const allowed = can('view_finance_reports')

  const [filters, setFilters]       = useState(defaultFilters)
  const [compare, setCompare]       = useState(readCompare)
  const [compareDates, setCmpDates] = useState({ from: '', to: '' })
  const [stock, setStock]           = useState(EMPTY_STOCK)
  const [selected, setSelected]     = useState(null)
  const [exportBusy, setExportBusy] = useState(null)

  // A typed stock figure belongs to one period: any change of period drops it.
  const changeFilters = (patch) => { setFilters((f) => ({ ...f, ...patch })); setStock(EMPTY_STOCK) }
  const changeCompareDates = (patch) => { setCmpDates((c) => ({ ...c, ...patch })); setStock((s) => ({ ...s, compare_opening: '', compare_closing: '' })) }

  const toggleCompare = useCallback(() => {
    setCompare((c) => {
      try { localStorage.setItem(COMPARE_KEY, c ? '0' : '1') } catch { /* storage unavailable */ }
      return !c
    })
  }, [])

  const reset = useCallback(() => {
    setFilters(defaultFilters())
    setCmpDates({ from: '', to: '' })
    setStock(EMPTY_STOCK)
  }, [])

  const params = useMemo(() => ({
    date_from:             filters.date_from || undefined,
    date_to:               filters.date_to || undefined,
    location_id:           filters.location_id || undefined,
    compare:               compare ? 1 : 0,
    compare_from:          compare && compareDates.from && compareDates.to ? compareDates.from : undefined,
    compare_to:            compare && compareDates.from && compareDates.to ? compareDates.to : undefined,
    opening_stock:         stock.opening !== '' ? stock.opening : undefined,
    closing_stock:         stock.closing !== '' ? stock.closing : undefined,
    compare_opening_stock: compare && stock.compare_opening !== '' ? stock.compare_opening : undefined,
    compare_closing_stock: compare && stock.compare_closing !== '' ? stock.compare_closing : undefined,
  }), [filters, compare, compareDates, stock])

  const { data: locations = [] } = useQuery({
    queryKey: ['locations', 'all'],
    queryFn:  getAllLocations,
    staleTime: 5 * 60 * 1000,
  })

  const ready = allowed && Boolean(filters.date_from && filters.date_to)

  const { data, isLoading, isFetching, error } = useQuery({
    queryKey: ['profit-loss', params],
    queryFn:  () => getProfitLoss(params),
    enabled:  ready,
    retry:    false,
    placeholderData: (prev) => prev,
  })

  // A 422 (before go-live, bad dates) is shown in place of the statement.
  const errorText = error ? jsonMessage(error, 'Could not load the Profit & Loss.') : ''

  const exportPdf = useCallback(async (action) => {
    if (!ready) return
    setExportBusy(action)
    try {
      const blob = await downloadProfitLossPdf(params)
      if (action === 'print') printPdfBlob(blob)
      else downloadPdfBlob(blob, `Profit_and_Loss_${fileSafe(filters.date_from)}_to_${fileSafe(filters.date_to)}.pdf`)
    } catch (e) {
      showError(await blobMessage(e, 'Could not create the Profit & Loss PDF.'))
    } finally {
      setExportBusy(null)
    }
  }, [ready, params, filters.date_from, filters.date_to])

  useEffect(() => {
    const onKey = (e) => {
      if (!e.altKey || selected) return
      const k = e.key.toLowerCase()
      if (k === 'c') { e.preventDefault(); toggleCompare() }
      if (k === 'p') { e.preventDefault(); exportPdf('pdf') }
      if (k === 'r') { e.preventDefault(); reset() }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [selected, toggleCompare, exportPdf, reset])

  if (!allowed) {
    return (
      <div className="p-3">
        <Breadcrumb crumbs={CRUMBS} />
        <p className="mt-3 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500">
          You do not have permission to view financial reports.
        </p>
      </div>
    )
  }

  const report = error ? null : data
  const cmp    = Boolean(report?.comparative?.available)

  return (
    <div className="p-3">
      {/* ── Title ───────────────────────────────────────────────────── */}
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-600 text-white shadow-sm">
            <TrendingUp size={18} />
          </span>
          <div>
            <h1 className="text-xl font-bold leading-none text-slate-800">Profit &amp; Loss Account</h1>
            <Breadcrumb crumbs={CRUMBS} />
          </div>
        </div>
        <div className="flex items-center gap-1">
          <PrintBtn onClick={() => exportPdf('print')} disabled={!report || exportBusy !== null} title="Print" />
          <PdfBtn onClick={() => exportPdf('pdf')} disabled={!report || exportBusy !== null} title="Download PDF (Alt+P)" />
        </div>
      </div>

      {/* ── Filters ─────────────────────────────────────────────────── */}
      <div className="mt-2 rounded-lg border border-slate-200 bg-white p-2 shadow-sm">
        <div className="grid grid-cols-2 gap-2 md:grid-cols-4 xl:grid-cols-7">
          <div>
            <label className={LABEL_CLS}>From <span className="text-red-500">*</span> <HelpTip text={NOTE.from} /></label>
            <input type="date" value={filters.date_from} max={filters.date_to || undefined}
              onChange={(e) => changeFilters({ date_from: e.target.value })} className={FILTER_INPUT_CLS} />
          </div>
          <div>
            <label className={LABEL_CLS}>To <span className="text-red-500">*</span></label>
            <input type="date" value={filters.date_to} min={filters.date_from || undefined}
              onChange={(e) => changeFilters({ date_to: e.target.value })} className={FILTER_INPUT_CLS} />
          </div>
          <div>
            <label className={LABEL_CLS}>Location <HelpTip text={NOTE.location} /></label>
            <FilterSearchSelect
              value={filters.location_id}
              onChange={(v) => changeFilters({ location_id: v })}
              options={locations.map((l) => ({ value: String(l.id), label: l.location_name ?? l.name }))}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Compare Period <HelpTip text={NOTE.compare} /></label>
            <button
              type="button"
              role="switch"
              aria-checked={compare}
              onClick={toggleCompare}
              title="Show or hide the comparative period (Alt+C)"
              className={`flex h-[26px] min-w-[4.5rem] items-center gap-2 rounded-md border-2 pl-1.5 pr-2.5 text-xs font-bold transition-colors ${
                compare ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-slate-200 bg-white text-slate-500 hover:bg-slate-50'
              }`}
            >
              <span className={`relative inline-block h-4 w-7 shrink-0 overflow-hidden rounded-full transition-colors ${compare ? 'bg-indigo-600' : 'bg-slate-300'}`}>
                <span className={`absolute top-0.5 h-3 w-3 rounded-full bg-white shadow transition-transform ${compare ? 'translate-x-3.5' : 'translate-x-0.5'}`} />
              </span>
              {compare ? 'On' : 'Off'}
            </button>
          </div>
          {compare && (
            <>
              <div>
                <label className={LABEL_CLS}>Compare From</label>
                <input type="date" value={compareDates.from || report?.comparative?.from || ''}
                  onChange={(e) => changeCompareDates({ from: e.target.value, to: compareDates.to || report?.comparative?.to || '' })}
                  className={FILTER_INPUT_CLS} />
              </div>
              <div>
                <label className={LABEL_CLS}>Compare To</label>
                <input type="date" value={compareDates.to || report?.comparative?.to || ''}
                  onChange={(e) => changeCompareDates({ to: e.target.value, from: compareDates.from || report?.comparative?.from || '' })}
                  className={FILTER_INPUT_CLS} />
              </div>
            </>
          )}
          <div className="flex items-end gap-1">
            <button type="button" onClick={reset} title="Reset (Alt+R)"
              className="flex items-center gap-1 rounded-md border-2 border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50">
              <RotateCcw size={12} /> Reset
            </button>
            {isFetching && <span className="pb-1 text-[11px] text-slate-400">Updating…</span>}
          </div>
        </div>
      </div>

      {/* ── Error (e.g. before go-live) ─────────────────────────────── */}
      {errorText && (
        <div className="mt-2 flex items-start gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
          <AlertTriangle size={15} className="mt-0.5 shrink-0" /> <span>{errorText}</span>
        </div>
      )}

      {/* ── Warnings ────────────────────────────────────────────────── */}
      {report?.warnings?.length > 0 && (
        <div className="mt-2 space-y-0.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5">
          {report.warnings.map((w) => (
            <p key={w.code} className="flex items-start gap-1.5 text-[11px] font-medium text-amber-800">
              <AlertTriangle size={12} className="mt-0.5 shrink-0" /> {w.message}
            </p>
          ))}
        </div>
      )}
      {report?.comparative?.enabled && !cmp && report.comparative.reason && (
        <p className="mt-1 rounded-md border border-slate-200 bg-slate-50 px-2 py-1 text-[11px] text-slate-500">
          Comparative not shown: {report.comparative.reason}
        </p>
      )}

      {!ready && (
        <p className="mt-2 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500">Choose the From and To dates.</p>
      )}
      {ready && isLoading && (
        <p className="mt-2 rounded-lg border border-slate-200 bg-white p-6 text-center text-sm text-slate-400">Loading…</p>
      )}

      {report && (
        <>
          <KpiTiles report={report} cmp={cmp} />
          <Statement
            report={report}
            cmp={cmp}
            stock={stock}
            setStock={setStock}
            onAccount={(l) => setSelected({ ledger_account_id: l.ledger_account_id, code: l.code, ledger_account_name: l.name })}
          />
          <p className="mt-1 text-[10px] text-slate-400">
            Stock: {report.stock_source.basis} All other figures are posted journals. Click an account name to see its
            transactions; hover a <span className="font-bold">?</span> for an explanation.
          </p>
        </>
      )}

      <KeyboardShortcuts shortcuts={SHORTCUTS} />

      {selected && (
        <AccountTransactionsModal
          account={selected}
          filters={{ date_from: report?.period?.from ?? filters.date_from, date_to: report?.period?.to ?? filters.date_to, location_id: filters.location_id }}
          onClose={() => setSelected(null)}
        />
      )}
    </div>
  )
}

/* ── Change chip ───────────────────────────────────────────────────────── */

/**
 * ▲ / ▼ with the percentage. `higherIsGood` decides the colour: more revenue
 * or profit is green, more cost is red.
 */
function ChangeChip({ cur, prev, higherIsGood = true, compact = false }) {
  const pct = changePct(cur, prev)
  if (pct === null) return <span className="text-[10px] text-slate-300">—</span>

  const up   = pct >= 0
  const good = up === higherIsGood
  const Icon = up ? ArrowUpRight : ArrowDownRight

  return (
    <span className={`inline-flex items-center gap-0.5 rounded px-1 font-bold tabular-nums ${compact ? 'text-[10px]' : 'text-[11px]'} ${
      Math.abs(pct) < 0.05 ? 'bg-slate-100 text-slate-500' : good ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
    }`}>
      <Icon size={compact ? 10 : 11} />{Math.abs(pct) >= 1000 ? '999+' : Math.abs(pct).toFixed(1)}%
    </span>
  )
}

/* ── KPI tiles ─────────────────────────────────────────────────────────── */

function KpiTiles({ report, cmp }) {
  const revenue = report.revenue.total.amount
  const tiles = [
    { label: 'Revenue', v: report.revenue.total, note: NOTE.revenue, icon: ShoppingCart, profit: false },
    { label: 'Gross Profit', v: report.gross_profit, note: NOTE.gross, icon: Coins, profit: true },
    { label: 'Net Profit before Tax', v: report.net_before_tax, note: NOTE.beforeTax, icon: Banknote, profit: true },
    { label: 'Net Profit for the Period', v: report.net_for_period, note: NOTE.forPeriod, icon: PiggyBank, profit: true },
  ]

  return (
    <div className="mt-2 grid grid-cols-2 gap-2 lg:grid-cols-4">
      {tiles.map((t) => {
        const n      = Number(t.v.amount) || 0
        const loss   = t.profit && n < 0
        const margin = t.profit ? ofRevenue(n, revenue) : null
        const accent = loss ? 'border-l-red-500' : t.profit ? 'border-l-green-500' : 'border-l-indigo-500'
        const tone   = loss ? 'text-red-700' : t.profit ? 'text-green-700' : 'text-slate-800'
        const Icon   = t.icon

        return (
          <div key={t.label} className={`rounded-lg border border-l-4 border-slate-200 bg-white px-3 py-1.5 shadow-sm ${accent}`}>
            <div className="flex items-center justify-between">
              <p className="flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider text-slate-500">
                {t.label} <HelpTip text={t.note} size={10} />
              </p>
              <Icon size={14} className={loss ? 'text-red-400' : t.profit ? 'text-green-500' : 'text-indigo-400'} />
            </div>
            <p className={`flex items-center gap-1 text-lg font-bold leading-tight tabular-nums ${tone}`}>
              {t.profit && (loss ? <TrendingDown size={15} /> : <TrendingUp size={15} />)}
              {amt(n)}
              <span className="text-[10px] font-semibold text-slate-400">{CURRENCY}</span>
            </p>
            <div className="flex flex-wrap items-center gap-1.5 text-[10px] text-slate-500">
              {margin !== null && (
                <span title={NOTE.margin} className="rounded bg-slate-100 px-1 font-semibold text-slate-600">{margin.toFixed(1)}% of revenue</span>
              )}
              {cmp && (
                <>
                  <ChangeChip cur={t.v.amount} prev={t.v.compare} compact />
                  <span>vs {amt(t.v.compare)}</span>
                </>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}

/* ── The statement ─────────────────────────────────────────────────────── */

const NUM = 'whitespace-nowrap px-2 py-1 text-right tabular-nums'

/** Section colours: a left accent, a tint and an icon per block of the statement. */
const SECTION = {
  revenue:     { bar: 'border-l-emerald-500', tint: 'bg-emerald-50/70', text: 'text-emerald-800', icon: ShoppingCart },
  cos:         { bar: 'border-l-amber-500',   tint: 'bg-amber-50/70',   text: 'text-amber-800',   icon: Package },
  otherIncome: { bar: 'border-l-teal-500',    tint: 'bg-teal-50/70',    text: 'text-teal-800',    icon: Coins },
  overheads:   { bar: 'border-l-orange-500',  tint: 'bg-orange-50/70',  text: 'text-orange-800',  icon: Building2 },
  tax:         { bar: 'border-l-violet-500',  tint: 'bg-violet-50/70',  text: 'text-violet-800',  icon: Landmark },
}

function Heading({ cols, kind, note, children }) {
  const s    = SECTION[kind]
  const Icon = s.icon
  return (
    <tr className={s.tint}>
      <td colSpan={cols} className={`border-l-4 px-2 pt-1.5 pb-1 ${s.bar}`}>
        <span className={`flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider ${s.text}`}>
          <Icon size={13} /> {children} {note && <HelpTip text={note} />}
        </span>
      </td>
    </tr>
  )
}

/** A detail line in the Details column; account lines open the drill-down. */
function Line({ l, cmp, onAccount, indent = 'pl-6', note }) {
  return (
    <tr className="border-t border-slate-100 transition-colors hover:bg-indigo-50/40">
      <td className={`${indent} py-1 pr-2 text-xs`}>
        {onAccount && l.ledger_account_id ? (
          <button type="button" onClick={() => onAccount(l)} title={`View transactions of ${l.name}`}
            className="text-left text-indigo-700 hover:text-indigo-900 hover:underline">
            {l.name} <span className="font-mono text-[10px] text-slate-400">{l.code}</span>
          </button>
        ) : <span className="text-slate-700">{l.name}</span>}
        {note && <> <HelpTip text={note} /></>}
      </td>
      <td className={`${NUM} text-xs text-slate-800`}>{amt(l.amount)}</td>
      <td />
      {cmp && <><td className={`${NUM} border-l border-slate-100 text-xs text-slate-500`}>{amt(l.compare)}</td><td /><td /></>}
    </tr>
  )
}

/** A section total in the Amount column. */
function Total({ label, v, cmp, higherIsGood, note }) {
  return (
    <tr className="border-t border-slate-300 font-semibold text-slate-800">
      <td colSpan={2} className="px-2 py-1 text-xs">{label} {note && <HelpTip text={note} />}</td>
      <td className={`${NUM} text-xs`}>{amt(v.amount)}</td>
      {cmp && (
        <>
          <td className="border-l border-slate-100" />
          <td className={`${NUM} text-xs text-slate-600`}>{amt(v.compare)}</td>
          <td className="px-1 text-right"><ChangeChip cur={v.amount} prev={v.compare} higherIsGood={higherIsGood} compact /></td>
        </>
      )}
    </tr>
  )
}

/** A key result band: green on profit, red on loss, with its share of revenue. */
function Result({ label, v, cmp, revenue, note, final = false }) {
  const n      = Number(v.amount) || 0
  const loss   = n < 0
  const margin = ofRevenue(n, revenue)
  const band   = loss ? 'bg-red-50 border-l-red-500' : 'bg-green-50 border-l-green-500'
  const tone   = loss ? 'text-red-700' : 'text-green-800'
  const double = final ? 'border-b-4 border-double border-slate-500' : 'border-b border-slate-400'

  return (
    <tr className={`border-t-2 border-slate-300 border-l-4 ${band} ${final ? 'text-sm' : 'text-xs'}`}>
      <td colSpan={2} className="px-2 py-1.5 font-bold text-slate-900">
        <span className="flex flex-wrap items-center gap-1.5">
          {label} {note && <HelpTip text={note} />}
          {margin !== null && (
            <span title={NOTE.margin} className="rounded bg-white/80 px-1 text-[10px] font-semibold text-slate-500">{margin.toFixed(1)}% of revenue</span>
          )}
        </span>
      </td>
      <td className={`${NUM} font-bold ${tone} ${double}`}>{amt(v.amount)}</td>
      {cmp && (
        <>
          <td className="border-l border-slate-200" />
          <td className={`${NUM} font-bold text-slate-600 ${double}`}>{amt(v.compare)}</td>
          <td className="px-1 text-right"><ChangeChip cur={v.amount} prev={v.compare} compact /></td>
        </>
      )}
    </tr>
  )
}

function Statement({ report, cmp, stock, setStock, onAccount }) {
  const cos     = report.cost_of_sales
  const revenue = report.revenue.total.amount
  const cols    = cmp ? 6 : 3

  const stockRow = (label, fig, keyCur, keyCmp, negate, note) => (
    <tr className="border-t border-slate-100 bg-sky-50/50">
      <td className="py-1 pl-6 pr-2 text-xs text-slate-700">
        {label}
        <span className="ml-1 rounded bg-white px-1 text-[10px] text-slate-400">value at end of {fmtDate(fig.as_at)}</span>
        {' '}<HelpTip text={note} />
      </td>
      <td className={`${NUM} text-xs`}>
        <StockCell fig={{ amount: fig.amount, auto: fig.auto, overridden: fig.overridden }} negate={negate}
          value={stock[keyCur]} onChange={(v) => setStock((s) => ({ ...s, [keyCur]: v }))} />
      </td>
      <td />
      {cmp && (
        <>
          <td className={`${NUM} border-l border-slate-100 text-xs`}>
            <StockCell fig={{ amount: fig.compare, auto: fig.compare_auto, overridden: fig.compare_overridden }} negate={negate} muted
              value={stock[keyCmp]} onChange={(v) => setStock((s) => ({ ...s, [keyCmp]: v }))} />
          </td>
          <td /><td />
        </>
      )}
    </tr>
  )

  const subHead = 'px-2 py-1 text-right'

  return (
    <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-1 border-b border-slate-200 bg-slate-50 px-3 py-1.5">
        <h2 className="text-sm font-bold text-slate-800">Statement of Profit or Loss</h2>
        <p className="text-[11px] text-slate-500">
          For the period <b className="text-slate-700">{fmtDate(report.period.from)}</b> to <b className="text-slate-700">{fmtDate(report.period.to)}</b>
          {cmp && <> · compared with {fmtDate(report.comparative.from)} to {fmtDate(report.comparative.to)}</>}
          {' '}· Amounts in {CURRENCY}
        </p>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full min-w-[680px] text-sm">
          <thead className="sticky top-0 z-10 text-[10px] uppercase tracking-wider text-slate-500">
            <tr className="bg-slate-100">
              <th rowSpan={2} className="px-2 py-1.5 text-left align-bottom text-slate-700">Particulars</th>
              <th colSpan={2} className="border-l border-slate-200 bg-indigo-100/70 px-2 py-1 text-center text-indigo-800">
                Current Period <span className="normal-case tracking-normal text-indigo-500">({fmtDate(report.period.from)} – {fmtDate(report.period.to)})</span>
              </th>
              {cmp && (
                <th colSpan={3} className="border-l border-slate-200 px-2 py-1 text-center text-slate-600">
                  Comparative Period <span className="normal-case tracking-normal text-slate-400">({fmtDate(report.comparative.from)} – {fmtDate(report.comparative.to)})</span>
                </th>
              )}
            </tr>
            <tr className="bg-slate-50">
              <th className={`${subHead} border-l border-slate-200 bg-indigo-50 text-indigo-700`}>
                <span className="inline-flex items-center gap-1">Details <HelpTip text={NOTE.details} size={10} /></span>
              </th>
              <th className={`${subHead} bg-indigo-50 text-indigo-700`}>
                <span className="inline-flex items-center gap-1">Amount <HelpTip text={NOTE.amount} size={10} /></span>
              </th>
              {cmp && (
                <>
                  <th className={`${subHead} border-l border-slate-200`}>Details</th>
                  <th className={subHead}>Amount</th>
                  <th className={`${subHead} w-16`}>
                    <span className="inline-flex items-center gap-1">Change <HelpTip text={NOTE.change} size={10} /></span>
                  </th>
                </>
              )}
            </tr>
          </thead>
          <tbody>
            <Heading cols={cols} kind="revenue" note={NOTE.revenue}>Revenue</Heading>
            {report.revenue.lines.length === 0 && <EmptyRow cols={cols}>No sales posted in this period.</EmptyRow>}
            {report.revenue.lines.map((l) => <Line key={l.ledger_account_id} l={l} cmp={cmp} onAccount={onAccount} />)}
            <Total label="Total Revenue" v={report.revenue.total} cmp={cmp} higherIsGood />

            <Heading cols={cols} kind="cos" note={NOTE.cos}>Cost of Sales</Heading>
            {stockRow('Opening Stock', cos.opening_stock, 'opening', 'compare_opening', false, NOTE.opening)}
            {cos.purchases.lines.map((l, i) => (
              <Line key={l.ledger_account_id} l={l} cmp={cmp} onAccount={onAccount} note={i === 0 ? NOTE.purchases : undefined} />
            ))}
            {cos.direct_costs.lines.map((l) => <Line key={l.ledger_account_id} l={l} cmp={cmp} onAccount={onAccount} />)}
            {stockRow('(−) Closing Stock', cos.closing_stock, 'closing', 'compare_closing', true, NOTE.closing)}
            <Total label="Cost of Goods Sold" v={cos.total} cmp={cmp} higherIsGood={false} note={NOTE.cogs} />

            <Result label="Gross Profit" v={report.gross_profit} cmp={cmp} revenue={revenue} note={NOTE.gross} />

            {report.other_income.lines.length > 0 && (
              <>
                <Heading cols={cols} kind="otherIncome" note={NOTE.otherIncome}>Other Income</Heading>
                {report.other_income.lines.map((l) => <Line key={l.ledger_account_id} l={l} cmp={cmp} onAccount={onAccount} />)}
                <Total label="Total Other Income" v={report.other_income.total} cmp={cmp} higherIsGood />
              </>
            )}

            <Heading cols={cols} kind="overheads" note={NOTE.overheads}>Overhead Costs</Heading>
            {report.overheads.groups.length === 0 && <EmptyRow cols={cols}>No overhead costs in this period.</EmptyRow>}
            {report.overheads.groups.map((g) => (
              <GroupRows key={g.key} g={g} cmp={cmp} onAccount={onAccount} />
            ))}
            <Total label="Total Overheads" v={report.overheads.total} cmp={cmp} higherIsGood={false} />

            <Result label="Net Profit / (Loss) before Taxation" v={report.net_before_tax} cmp={cmp} revenue={revenue} note={NOTE.beforeTax} />

            <Heading cols={cols} kind="tax">Taxation &amp; Appropriation</Heading>
            <Line l={{ name: '(−) Taxation (income tax)', ...report.taxation.total }} cmp={cmp} note={NOTE.taxation} />
            {report.taxation.lines.map((l) => <Line key={l.ledger_account_id} l={l} cmp={cmp} onAccount={onAccount} indent="pl-10" />)}
            <Result label="Net Profit / (Loss) after Taxation" v={report.net_after_tax} cmp={cmp} revenue={revenue} note={NOTE.afterTax} />

            <Line l={{ name: '(−) Provision for Reserves', ...report.reserves.total }} cmp={cmp} note={NOTE.reserves} />
            {report.reserves.lines.map((l) => <Line key={l.ledger_account_id} l={l} cmp={cmp} onAccount={onAccount} indent="pl-10" />)}

            <Result label="Net Profit / (Loss) for the Period" v={report.net_for_period} cmp={cmp} revenue={revenue} note={NOTE.forPeriod} final />
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap gap-x-4 gap-y-0.5 border-t border-slate-200 bg-slate-50 px-3 py-1 text-[10px] text-slate-500">
        <span><b className="text-slate-700">Details</b> = each item · <b className="text-slate-700">Amount</b> = section total or result</span>
        <span>(Brackets) = negative or deducted</span>
        <span className="text-green-700">Green = profit</span>
        <span className="text-red-700">Red = loss</span>
        <span>Opening stock = value at end of {fmtDate(cos.opening_stock.as_at)} (day before From) · Closing stock = value at end of {fmtDate(cos.closing_stock.as_at)}</span>
      </div>
    </div>
  )
}

function EmptyRow({ cols, children }) {
  return <tr><td colSpan={cols} className="py-1 pl-6 text-xs italic text-slate-400">{children}</td></tr>
}

function GroupRows({ g, cmp, onAccount }) {
  return (
    <>
      <tr><td colSpan={cmp ? 6 : 3} className="pl-6 pt-1 text-xs font-semibold text-slate-700">{g.label}</td></tr>
      {g.lines.map((l) => <Line key={l.ledger_account_id} l={l} cmp={cmp} onAccount={onAccount} indent="pl-10" />)}
      <tr className="text-xs">
        <td className="py-0.5 pl-10 italic text-slate-500">Total {g.label}</td>
        <td className={`${NUM} border-t border-slate-300 font-semibold text-slate-700`}>{amt(g.total.amount)}</td>
        <td />
        {cmp && <><td className={`${NUM} border-l border-t border-slate-100 border-t-slate-300 text-slate-500`}>{amt(g.total.compare)}</td><td /><td /></>}
      </tr>
    </>
  )
}

/**
 * A stock figure: the automatic value, or the user's. Click the pencil to
 * type over it (Enter applies, Esc cancels); the reset arrow goes back to the
 * automatic value. Closing stock is shown negated, as it is deducted.
 */
function StockCell({ fig, value, onChange, negate = false, muted = false }) {
  const [editing, setEditing] = useState(false)
  const [draft, setDraft]     = useState('')

  const shown = negate ? -(Number(fig.amount) || 0) : fig.amount

  const start = () => { setDraft(value !== '' ? value : String(fig.amount ?? '')); setEditing(true) }
  const apply = () => {
    const n = Number(draft)
    if (draft === '' || Number.isNaN(n) || n < 0) { showError('Enter a stock value of zero or more.'); return }
    onChange(String(n))
    setEditing(false)
  }

  if (editing) {
    return (
      <span className="inline-flex items-center gap-0.5">
        <input
          autoFocus
          type="number"
          min="0"
          step="0.01"
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') { e.preventDefault(); apply() }
            if (e.key === 'Escape') { e.stopPropagation(); setEditing(false) }
          }}
          className="w-32 rounded border-2 border-indigo-400 bg-white px-1 py-0.5 text-right text-xs focus:outline-none"
        />
        <button type="button" onClick={apply} title="Apply (Enter)" className="rounded p-0.5 text-green-600 hover:bg-green-50"><Check size={12} /></button>
        <button type="button" onClick={() => setEditing(false)} title="Cancel (Esc)" className="rounded p-0.5 text-slate-500 hover:bg-slate-100"><X size={12} /></button>
      </span>
    )
  }

  return (
    <span className="inline-flex items-center justify-end gap-1">
      {fig.overridden && (
        <span className="rounded bg-amber-100 px-1 text-[9px] font-bold uppercase text-amber-700"
          title={`${NOTE.entered}${fig.auto !== null && fig.auto !== undefined ? ` Automatic value: ${fmtMoney(fig.auto)}.` : ''}`}>
          Entered
        </span>
      )}
      <span className={muted ? 'text-slate-500' : 'text-slate-800'}>{amt(shown)}</span>
      <button type="button" onClick={start} title="Type a stock value (e.g. from a stock count)" className="rounded p-0.5 text-amber-500 hover:bg-amber-50 hover:text-amber-700"><Pencil size={11} /></button>
      {fig.overridden && (
        <button type="button" onClick={() => onChange('')} title="Back to the automatic value" className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"><RotateCcw size={11} /></button>
      )}
    </span>
  )
}
