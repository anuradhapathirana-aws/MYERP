import { Link } from 'react-router-dom'
import { balance, balanceCls, money } from './format'
import { sourceDocumentPath } from './sourceDocuments'

const COLS = 9

/**
 * One page of an account's ledger as a running-balance table:
 *
 *   Balance b/f -> lines with a running balance -> page total -> Balance c/f
 *
 * b/f is the opening on page 1 and the previous page's c/f after that, like a
 * paper ledger. While search or the document filter is set the server leaves
 * the running balance out (a balance over a subset means nothing), so b/f, c/f
 * and the Balance column are hidden too.
 */
export default function LedgerLinesTable({ ledger, dateFrom }) {
  const { rows, meta, summary, page, isFirst, isLast, filtered, isLoading, isError } = ledger

  const pageDebit  = rows.reduce((s, r) => s + Number(r.debit || 0), 0)
  const pageCredit = rows.reduce((s, r) => s + Number(r.credit || 0), 0)

  return (
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

        {!isLoading && !filtered && meta?.balance_bf != null && (
          <tr className="bg-slate-50/80 font-semibold">
            <td className="px-2 py-1.5 text-slate-500">{isFirst ? (dateFrom || '—') : ''}</td>
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

        {rows.map((r) => <LineRow key={r.journal_line_id} r={r} />)}
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
  )
}

function LineRow({ r }) {
  const full    = [r.narration, r.description].filter(Boolean).join(' — ')
  const docPath = sourceDocumentPath(r)

  return (
    <tr className="border-t border-slate-100 hover:bg-indigo-50/40">
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
        {r.document_no && (docPath ? (
          // New tab: the report keeps its place while the document is checked.
          <Link
            to={docPath}
            target="_blank"
            rel="noopener"
            title={`Open ${r.source_label} ${r.document_no} in a new tab`}
            className="ml-1 font-mono font-semibold text-indigo-700 hover:text-indigo-900 hover:underline"
          >
            {r.document_no}
          </Link>
        ) : (
          <span className="ml-1 font-mono font-semibold text-slate-700">{r.document_no}</span>
        ))}
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
}
