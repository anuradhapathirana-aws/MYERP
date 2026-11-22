import { useEffect, useState } from 'react'
import { BookOpen, X } from 'lucide-react'
import { downloadTAccountPdf } from '../../api/journalEntries'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import { PdfBtn, PrintBtn } from '../../components/ui/ActionButtons'
import { showError } from '../../utils/alerts'
import { downloadPdfBlob, printPdfBlob } from '../../utils/pdf'
import AccountLedgerBody from './ledger/AccountLedgerBody'
import LedgerViewSwitch from './ledger/LedgerViewSwitch'
import SummaryTile from './ledger/SummaryTile'
import { balance, balanceCls, fileSafe, money, periodLabel } from './ledger/format'
import { useAccountLedger } from './ledger/useAccountLedger'
import { useLedgerView } from './ledger/useLedgerView'

const SHORTCUTS = [
  { keys: ['Esc'], desc: 'Close' },
  { keys: ['Alt', 'T'], desc: 'Switch List / T-Account' },
]

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
 * The lines, filters and T-account are the shared ledger/ components that the
 * Ledger Details page uses too.
 */
export default function AccountTransactionsModal({ account, filters, onClose }) {
  const { view, changeView, toggleView, adoptDefault } = useLedgerView()
  const ledger = useAccountLedger({ accountId: account.ledger_account_id, filters, view })
  const [exportBusy, setExportBusy] = useState(null) // 'pdf' | 'print'

  adoptDefault(ledger.data?.default_view)

  useEffect(() => {
    const onKey = (e) => {
      if (e.key === 'Escape') onClose()
      if (e.altKey && (e.key === 't' || e.key === 'T')) {
        e.preventDefault()
        toggleView()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose, toggleView])

  const exportTAccount = async (action) => {
    setExportBusy(action)
    try {
      const blob = await downloadTAccountPdf(account.ledger_account_id, ledger.base)
      if (action === 'print') printPdfBlob(blob)
      else downloadPdfBlob(blob, `TAccount_${account.code}_${fileSafe(account.ledger_account_name)}.pdf`)
    } catch {
      showError('Could not create the T-account PDF.')
    } finally {
      setExportBusy(null)
    }
  }

  const { info, summary } = ledger

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
              <span>Period: <b className="text-slate-700">{periodLabel(filters)}</b></span>
            </div>
          </div>
          {/* View switch + T-account PDF */}
          <div className="flex shrink-0 items-center gap-1.5">
            <LedgerViewSwitch view={view} onChange={changeView} />
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
          <SummaryTile label="Period Debits" value={money(summary.period_debit)} />
          <SummaryTile label="Period Credits" value={money(summary.period_credit)} />
          <SummaryTile
            label="Period Movement"
            hint="Equals the Trial Balance row"
            value={balance(summary.period_net)}
            cls={balanceCls(summary.period_net)}
          />
          <SummaryTile label="Closing Balance (C/F)" value={balance(summary.closing)} cls={balanceCls(summary.closing)} strong />
        </div>

        {/* ── Filters, lines / T-account, pager ──────────────────────── */}
        <div className="flex min-h-0 flex-1 flex-col px-3">
          <AccountLedgerBody
            ledger={ledger}
            view={view}
            filters={filters}
            account={account}
            scrollCls="min-h-0 flex-1"
            autoFocus
          />
        </div>

        {/* ── Footer ─────────────────────────────────────────────────── */}
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
