import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BookOpen, Columns2, List, X } from 'lucide-react'
import { downloadTAccountPdf, getAccountTransactions } from '../../api/journalEntries'
import Pagination from '../../components/ui/Pagination'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import { PdfBtn, PrintBtn } from '../../components/ui/ActionButtons'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS, LABEL_CLS } from '../../utils/fieldStyles'
import { showError } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'
import TAccountView from './TAccountView'

// Each user's own last choice; the company default (Finance Settings) applies until they pick one.
const VIEW_KEY = 'ledger.view'

const readView = () => {
  try {
    const v = localStorage.getItem(VIEW_KEY)
    return v === 'list' || v === 't_account' ? v : null
  } catch { return null }
}

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

/** Signed net (debit positive) shown the accountant's way: 1,250.00 Dr. */
const balance = (n) => {
  const v = Number(n || 0)
  if (Math.abs(v) < 0.005) return '0.00'
  return `${money(Math.abs(v))} ${v > 0 ? 'Dr' : 'Cr'}`
}

const balanceCls = (n) => {
  const v = Number(n || 0)
  if (Math.abs(v) < 0.005) return 'text-slate-500'
  return v > 0 ? 'text-indigo-700' : 'text-amber-700'
}

const SHORTCUTS = [
  { keys: ['Esc'], desc: 'Close' },
  { keys: ['Alt', 'T'], desc: 'Switch List / T-Account' },
]

const COLS = 9

/**
 * The Trial Balance drill-down: every transaction of one account, as a
 * General Ledger shows it.
 *
 *   Opening balance -> transactions with a running balance -> Closing balance
 *
 * The Trial Balance row only counts movement inside the dates, so with a From
 * date the closing here also includes the opening. The footer shows both
 * figures, Period movement (equals the row clicked) and Closing, so the two
 * never look like a disagreement.
 *
 * Search and the document filter narrow the list; a running balance over a
 * subset means nothing, so the server leaves it out while either is set.
 */
export default function AccountTransactionsModal({ account, filters, onClose }) {
  const [page, setPage]             = useState(1)
  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch]         = useState('')
  const [sourceType, setSourceType] = useState('')
  const [userView, setUserView]     = useState(readView)
  const [exportBusy, setExportBusy] = useState(null) // 'pdf' | 'print'

  // Debounced: one request per pause in typing, not per keystroke.
  useEffect(() => {
    const t = setTimeout(() => { setSearch(searchInput.trim()); setPage(1) }, 300)
    return () => clearTimeout(t)
  }, [searchInput])

  const base = {
    date_from:   filters.date_from || undefined,
    date_to:     filters.date_to || undefined,
    location_id: filters.location_id || undefined,
  }

  // The company default comes back with the first page; until then (and
  // until the user picks), the list is assumed.
  const [defaultView, setDefaultView] = useState(null)
  const view = userView ?? defaultView ?? 'list'

  // A T-account only balances over every entry, so search and the document
  // filter apply to the list alone.
  const params = view === 'list'
    ? { ...base, search: search || undefined, source_type: sourceType || undefined, page }
    : { ...base, page }

  const { data, isLoading, isFetching, isError } = useQuery({
    queryKey: ['account-transactions', account.ledger_account_id, params],
    queryFn:  () => getAccountTransactions(account.ledger_account_id, params),
    placeholderData: (prev) => prev,
  })

  if (data?.default_view && defaultView === null) setDefaultView(data.default_view)

  const changeView = (v) => {
    setUserView(v)
    setPage(1)
    try { localStorage.setItem(VIEW_KEY, v) } catch { /* storage unavailable: keep in memory */ }
  }

  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose()
      if (e.altKey && (e.key === 't' || e.key === 'T')) {
        e.preventDefault()
        changeView(view === 'list' ? 't_account' : 'list')
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

  const exportTAccount = async (action) => {
    setExportBusy(action)
    try {
      const blob = await downloadTAccountPdf(account.ledger_account_id, base)
      if (action === 'print') {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href     = url
        a.download = `TAccount_${account.code}_${String(account.ledger_account_name).replace(/[^A-Za-z0-9]+/g, '_')}.pdf`
        a.click()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
      }
    } catch {
      showError('Could not create the T-account PDF.')
    } finally {
      setExportBusy(null)
    }
  }

  const info     = data?.account
  const summary  = data?.summary ?? { opening: 0, period_debit: 0, period_credit: 0, period_net: 0, closing: 0, is_filtered: false }
  const rows     = data?.rows ?? []
  const meta     = data?.meta
  const filtered = summary.is_filtered
  const isFirst  = page === 1
  const isLast   = !meta || page >= meta.last_page

  const range = filters.date_from || filters.date_to
    ? `${filters.date_from || 'Beginning'} → ${filters.date_to || 'Today'}`
    : 'All dates'

  const pageDebit  = rows.reduce((s, r) => s + Number(r.debit || 0), 0)
  const pageCredit = rows.reduce((s, r) => s + Number(r.credit || 0), 0)

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-2 sm:p-4"
      onMouseDown={(e) => { if (e.target === e.currentTarget) onClose() }}
    >
      <div className="flex max-h-[94vh] w-full max-w-7xl flex-col rounded-xl bg-white shadow-xl">

        {/* ── Header ─────────────────────────────────────────────────── */}
        <div className="flex items-start gap-2 border-b border-slate-200 px-3 py-2">
          <BookOpen size={18} className="mt-0.5 text-indigo-600" />
          <div className="min-w-0 flex-1">
            <h2 className="truncate text-base font-bold text-slate-800">
              <span className="font-mono text-slate-500">{account.code}</span> · {account.ledger_account_name}
            </h2>
            <div className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-slate-500">
              {(info?.account_type_label ?? account.account_type_label) && (
                <span className="rounded border border-indigo-200 bg-indigo-50 px-1.5 py-px font-semibold text-indigo-700">
                  {info?.account_type_label ?? account.account_type_label}
                </span>
              )}
              <span>Control: <b className="text-slate-700">{info?.control_account_name ?? account.control_account_name}</b></span>
              {info?.category_name && <span>Category: <b className="text-slate-700">{info.category_name}</b></span>}
              {info?.normal_balance && (
                <span>Normal balance: <b className="text-slate-700">{info.normal_balance === 'debit' ? 'Debit' : 'Credit'}</b></span>
              )}
              <span>Period: <b className="text-slate-700">{range}</b></span>
            </div>
          </div>
          {/* View switch + T-account PDF */}
          <div className="flex shrink-0 items-center gap-1.5">
            <div className="inline-flex rounded-lg border-2 border-slate-200 bg-slate-50 p-0.5 text-xs font-semibold" role="tablist">
              {[
                ['list', 'List', List],
                ['t_account', 'T-Account', Columns2],
              ].map(([v, label, Icon]) => (
                <button
                  key={v}
                  type="button"
                  role="tab"
                  aria-selected={view === v}
                  onClick={() => changeView(v)}
                  title={`${label} view (Alt+T)`}
                  className={`inline-flex items-center gap-1 rounded-md px-2 py-1 transition-colors ${
                    view === v ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-white'
                  }`}
                >
                  <Icon size={13} /> {label}
                </button>
              ))}
            </div>
            <PrintBtn
              onClick={() => exportTAccount('print')}
              disabled={exportBusy !== null}
              title="Print T-account (whole period)"
            />
            <PdfBtn
              onClick={() => exportTAccount('pdf')}
              disabled={exportBusy !== null}
              title="Download T-account PDF (whole period)"
            />
          </div>
          <button
            type="button"
            onClick={onClose}
            title="Close (Esc)"
            className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600"
          >
            <X size={16} />
          </button>
        </div>

        {/* ── Summary ────────────────────────────────────────────────── */}
        <div className="grid grid-cols-2 gap-2 px-3 pt-2 sm:grid-cols-3 lg:grid-cols-5">
          <SummaryTile label="Opening Balance (B/F)" value={balance(summary.opening)} cls={balanceCls(summary.opening)} />
          <SummaryTile label="Period Debits" value={money(summary.period_debit)} cls="text-slate-800" />
          <SummaryTile label="Period Credits" value={money(summary.period_credit)} cls="text-slate-800" />
          <SummaryTile
            label="Period Movement"
            hint="Equals the Trial Balance row"
            value={balance(summary.period_net)}
            cls={balanceCls(summary.period_net)}
          />
          <SummaryTile label="Closing Balance (C/F)" value={balance(summary.closing)} cls={balanceCls(summary.closing)} strong />
        </div>

        {view === 't_account' && (
          <p className="mx-3 mt-2 flex items-center gap-2 rounded-md border border-indigo-100 bg-indigo-50/60 px-2 py-1 text-[11px] text-indigo-800">
            <Columns2 size={13} className="shrink-0" />
            Debits on the left (<b>To</b>), credits on the right (<b>By</b>). Balance c/d sits on the smaller side so both
            sides agree. Search and document filters are available in List view.
            {isFetching && !isLoading && <span className="ml-auto text-slate-400">Updating…</span>}
          </p>
        )}

        {/* ── Filters ────────────────────────────────────────────────── */}
        <div className={`grid grid-cols-1 gap-2 px-3 pt-2 sm:grid-cols-3 lg:grid-cols-4 ${view === 'list' ? '' : 'hidden'}`}>
          <div>
            <label className={LABEL_CLS}>Search</label>
            <input
              type="text"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
              placeholder="Journal no, narration, description"
              className={FILTER_INPUT_CLS}
              autoFocus
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Document Type</label>
            <select
              value={sourceType}
              onChange={(e) => { setSourceType(e.target.value); setPage(1) }}
              className={FILTER_SELECT_CLS}
            >
              <option value="">All</option>
              {(data?.source_types ?? []).map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
          </div>
          <div className="flex items-end gap-2">
            {(searchInput || sourceType) && (
              <button
                type="button"
                onClick={() => { setSearchInput(''); setSearch(''); setSourceType(''); setPage(1) }}
                className="rounded-md border-2 border-slate-200 px-2 py-1 text-xs font-semibold text-slate-600 transition-colors hover:bg-slate-50"
              >
                Clear
              </button>
            )}
            {isFetching && !isLoading && <span className="pb-1 text-[11px] text-slate-400">Updating…</span>}
          </div>
        </div>

        {filtered && view === 'list' && (
          <p className="mx-3 mt-2 rounded-md border border-amber-200 bg-amber-50 px-2 py-1 text-[11px] text-amber-700">
            Filtered view: the running balance is hidden because it only makes sense over every transaction.
            The balances above are still for the whole account.
          </p>
        )}

        {/* ── T-account ──────────────────────────────────────────────── */}
        {view === 't_account' && (
          <div className="mx-3 mt-2 min-h-0 flex-1 overflow-auto">
            {isLoading && <p className="py-6 text-center text-xs text-slate-400">Loading…</p>}
            {isError && <p className="py-6 text-center text-xs text-red-500">Could not load the transactions.</p>}
            {!isLoading && !isError && info && (
              <TAccountView
                account={info}
                rows={rows}
                meta={meta}
                summary={summary}
                filters={filters}
                page={page}
                isFirst={isFirst}
                isLast={isLast}
              />
            )}
          </div>
        )}

        {/* ── Transactions ───────────────────────────────────────────── */}
        <div className={`mx-3 mt-2 min-h-0 flex-1 overflow-auto rounded-lg border border-slate-200 ${view === 'list' ? '' : 'hidden'}`}>
          <table className="w-full text-xs">
            <thead className="sticky top-0 z-10 bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
              <tr>
                <th className="whitespace-nowrap px-2 py-2 text-left">Date</th>
                <th className="whitespace-nowrap px-2 py-2 text-left">Journal No</th>
                <th className="whitespace-nowrap px-2 py-2 text-left">Source Document</th>
                <th className="px-2 py-2 text-left">Narration / Description</th>
                <th className="px-2 py-2 text-left">Party</th>
                <th className="px-2 py-2 text-left">Contra Account</th>
                <th className="whitespace-nowrap px-2 py-2 text-right">Debit</th>
                <th className="whitespace-nowrap px-2 py-2 text-right">Credit</th>
                <th className="whitespace-nowrap px-2 py-2 text-right">Balance</th>
              </tr>
            </thead>

            <tbody>
              {isLoading && (
                <tr><td colSpan={COLS} className="px-2 py-6 text-center text-slate-400">Loading…</td></tr>
              )}

              {isError && (
                <tr><td colSpan={COLS} className="px-2 py-6 text-center text-red-500">Could not load the transactions.</td></tr>
              )}

              {/* Balance b/f: the opening on page 1, the previous page's
                  c/f on every later page, like a paper ledger. */}
              {!isLoading && !filtered && meta?.balance_bf != null && (
                <tr className="bg-slate-50/80 font-semibold">
                  <td className="px-2 py-1.5 text-slate-500">{isFirst ? (filters.date_from || '—') : ''}</td>
                  <td colSpan={5} className="px-2 py-1.5 text-slate-600">
                    {isFirst ? 'Opening Balance (b/f)' : `Balance b/f from page ${page - 1}`}
                  </td>
                  <td className="px-2 py-1.5" />
                  <td className="px-2 py-1.5" />
                  <td className={`whitespace-nowrap px-2 py-1.5 text-right tabular-nums ${balanceCls(meta.balance_bf)}`}>
                    {balance(meta.balance_bf)}
                  </td>
                </tr>
              )}

              {!isLoading && rows.length === 0 && !isError && (
                <tr>
                  <td colSpan={COLS} className="px-2 py-6 text-center text-slate-400">
                    {filtered ? 'No transactions match these filters.' : 'No transactions in this period.'}
                  </td>
                </tr>
              )}

              {rows.map((r) => {
                const text = [r.narration, r.description].filter(Boolean)
                const full = text.join(' — ')

                return (
                  <tr key={r.journal_line_id} className="border-t border-slate-100 hover:bg-indigo-50/40">
                    <td className="whitespace-nowrap px-2 py-1.5 text-slate-600">{r.journal_date}</td>
                    <td className="whitespace-nowrap px-2 py-1.5">
                      <span className="font-mono font-semibold text-slate-700">{r.journal_no}</span>
                      {r.is_reversed && (
                        <span className="ml-1 rounded border border-slate-200 bg-slate-100 px-1 py-px text-[9px] font-semibold uppercase text-slate-500">
                          Reversed
                        </span>
                      )}
                    </td>
                    <td className="whitespace-nowrap px-2 py-1.5">
                      <span className="text-slate-500">{r.source_label}</span>
                      {r.document_no && <span className="ml-1 font-mono font-semibold text-slate-700">{r.document_no}</span>}
                    </td>
                    <td className="max-w-xs px-2 py-1.5 text-slate-600">
                      <span className="line-clamp-1" title={full}>{full || '—'}</span>
                    </td>
                    <td className="max-w-40 px-2 py-1.5 text-slate-600">
                      <span className="line-clamp-1" title={r.party_name ?? ''}>{r.party_name ?? '—'}</span>
                    </td>
                    <td className="max-w-48 px-2 py-1.5 text-slate-600">
                      <span
                        className={`line-clamp-1 ${r.contra?.accounts?.length > 1 ? 'cursor-help italic text-slate-500' : ''}`}
                        title={(r.contra?.accounts ?? []).map((a) => `${a.code} · ${a.name}`).join('\n')}
                      >
                        {r.contra?.label ?? '—'}
                      </span>
                    </td>
                    <td className="whitespace-nowrap px-2 py-1.5 text-right tabular-nums text-slate-800">
                      {r.debit > 0 ? money(r.debit) : ''}
                    </td>
                    <td className="whitespace-nowrap px-2 py-1.5 text-right tabular-nums text-slate-800">
                      {r.credit > 0 ? money(r.credit) : ''}
                    </td>
                    <td className={`whitespace-nowrap px-2 py-1.5 text-right font-semibold tabular-nums ${r.balance === null ? 'text-slate-300' : balanceCls(r.balance)}`}>
                      {r.balance === null ? '—' : balance(r.balance)}
                    </td>
                  </tr>
                )
              })}
            </tbody>

            {rows.length > 0 && (
              <tfoot className="sticky bottom-0 bg-slate-100 font-semibold">
                <tr className="border-t-2 border-slate-300">
                  <td colSpan={6} className="px-2 py-1.5 text-right text-[10px] uppercase tracking-wider text-slate-500">
                    {meta?.last_page > 1 ? 'Page total' : 'Total'}
                  </td>
                  <td className="whitespace-nowrap px-2 py-1.5 text-right tabular-nums text-slate-800">{money(pageDebit)}</td>
                  <td className="whitespace-nowrap px-2 py-1.5 text-right tabular-nums text-slate-800">{money(pageCredit)}</td>
                  <td className="px-2 py-1.5" />
                </tr>
                {!filtered && isLast && (
                  <tr className="bg-slate-200/70">
                    <td colSpan={8} className="px-2 py-1.5 text-right text-[10px] uppercase tracking-wider text-slate-600">
                      Closing Balance (c/f)
                    </td>
                    <td className={`whitespace-nowrap px-2 py-1.5 text-right font-bold tabular-nums ${balanceCls(summary.closing)}`}>
                      {balance(summary.closing)}
                    </td>
                  </tr>
                )}
                {!filtered && !isLast && meta?.balance_cf != null && (
                  <tr className="bg-slate-200/70">
                    <td colSpan={8} className="px-2 py-1.5 text-right text-[10px] uppercase tracking-wider text-slate-600">
                      Balance c/f to page {page + 1}
                    </td>
                    <td className={`whitespace-nowrap px-2 py-1.5 text-right font-bold tabular-nums ${balanceCls(meta.balance_cf)}`}>
                      {balance(meta.balance_cf)}
                    </td>
                  </tr>
                )}
              </tfoot>
            )}
          </table>
        </div>

        {/* ── Footer ─────────────────────────────────────────────────── */}
        <div className="px-3">
          <Pagination meta={meta} page={page} onPageChange={setPage} />
        </div>
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 px-3 pt-1 text-[11px] text-slate-500">
          <span>Period movement: <b className={balanceCls(summary.period_net)}>{balance(summary.period_net)}</b> (matches the Trial Balance)</span>
          <span>Closing balance: <b className={balanceCls(summary.closing)}>{balance(summary.closing)}</b> (includes the opening balance)</span>
        </div>
        <div className="px-3 pb-2">
          <KeyboardShortcuts shortcuts={SHORTCUTS} />
        </div>
      </div>
    </div>
  )
}

function SummaryTile({ label, value, cls, hint, strong = false }) {
  return (
    <div className={`rounded-lg border px-2 py-1.5 ${strong ? 'border-indigo-200 bg-indigo-50/50' : 'border-slate-200 bg-slate-50'}`}>
      <p className="text-[10px] font-bold uppercase tracking-wider text-slate-500" title={hint}>{label}</p>
      <p className={`text-sm font-bold tabular-nums ${cls}`}>{value}</p>
    </div>
  )
}
