import { ChevronDown, ChevronRight, UserRound } from 'lucide-react'
import AccountLedgerBody from './AccountLedgerBody'
import { balance, balanceCls, money } from './format'
import { useAccountLedger } from './useAccountLedger'

/**
 * One account on the Ledger Details page. The header is the account's
 * summary (from the report), so a collapsed card costs nothing; expanding it
 * mounts the body, which loads the lines through the shared drill-down hook.
 */
export default function LedgerAccountCard({ account, filters, view, expanded, onToggle }) {
  return (
    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white">
      <button
        type="button"
        onClick={onToggle}
        aria-expanded={expanded}
        className={`flex w-full flex-wrap items-center gap-x-3 gap-y-1 px-2 py-1.5 text-left transition-colors ${
          expanded ? 'border-b border-slate-200 bg-indigo-50/60' : 'hover:bg-slate-50'
        }`}
      >
        <span className="flex min-w-0 flex-1 items-center gap-1.5">
          {expanded
            ? <ChevronDown size={15} className="shrink-0 text-indigo-600" />
            : <ChevronRight size={15} className="shrink-0 text-slate-400" />}
          <span className="font-mono text-xs text-slate-500">{account.code}</span>
          <span className="truncate text-sm font-semibold text-slate-800" title={account.ledger_account_name}>
            {account.ledger_account_name}
          </span>
          {account.is_party && (
            <span className="inline-flex shrink-0 items-center gap-0.5 rounded border border-sky-200 bg-sky-50 px-1 text-[9px] font-semibold uppercase text-sky-700">
              <UserRound size={9} /> Party
            </span>
          )}
          {!account.is_active && (
            <span className="shrink-0 rounded border border-slate-200 bg-slate-100 px-1 text-[9px] font-semibold uppercase text-slate-500">
              Inactive
            </span>
          )}
          <span className="hidden truncate text-[11px] text-slate-400 lg:inline" title={account.control_account_name}>
            · {account.control_account_name}
          </span>
        </span>

        <span className="grid shrink-0 grid-cols-2 gap-x-4 gap-y-0.5 text-[11px] sm:grid-cols-5">
          <Figure label="B/F" value={balance(account.opening)} cls={balanceCls(account.opening)} />
          <Figure label="Debits" value={money(account.period_debit)} />
          <Figure label="Credits" value={money(account.period_credit)} />
          <Figure label="C/F" value={balance(account.closing)} cls={`font-bold ${balanceCls(account.closing)}`} />
          <Figure label="Lines" value={account.line_count} />
        </span>
      </button>

      {expanded && <CardBody account={account} filters={filters} view={view} />}
    </div>
  )
}

function CardBody({ account, filters, view }) {
  const ledger = useAccountLedger({ accountId: account.ledger_account_id, filters, view })

  return (
    <div className="px-2 pb-1">
      <AccountLedgerBody
        ledger={ledger}
        view={view}
        filters={filters}
        account={account}
        scrollCls="max-h-[60vh]"
      />
    </div>
  )
}

function Figure({ label, value, cls = 'text-slate-700' }) {
  return (
    <span className="text-right">
      <span className="block text-[9px] font-bold uppercase tracking-wider text-slate-400">{label}</span>
      <span className={`block whitespace-nowrap tabular-nums ${cls}`}>{value}</span>
    </span>
  )
}
