import { CornerDownRight, Info } from 'lucide-react'

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const nextDay = (iso) => {
  if (!iso) return ''
  const d = new Date(`${iso}T00:00:00`)
  d.setDate(d.getDate() + 1)
  return d.toLocaleDateString('en-CA')
}

const today = () => new Date().toLocaleDateString('en-CA')

const SIDE = {
  debit: {
    word:   'To',
    head:   'bg-indigo-50 text-indigo-700 border-indigo-200',
    row:    'hover:bg-indigo-50/50',
    amount: 'text-indigo-900',
    total:  'bg-indigo-50/70',
    word_cls: 'bg-indigo-100 text-indigo-700',
  },
  credit: {
    word:   'By',
    head:   'bg-amber-50 text-amber-800 border-amber-200',
    row:    'hover:bg-amber-50/50',
    amount: 'text-amber-900',
    total:  'bg-amber-50/70',
    word_cls: 'bg-amber-100 text-amber-800',
  },
}

/**
 * The traditional two-sided ledger account.
 *
 *   Dr                    Purchases Account                     Cr
 *   To Balance b/d   10,000 │ By Cash in Hand              1,000
 *   To Accounts Pay.  2,500 │ By Balance c/d              11,500
 *                    ══════ │                             ══════
 *                    12,500 │                             12,500
 *   To Balance b/d   11,500 │
 *
 * Built from the same page of lines as the list view. Each page is balanced
 * on its own: it opens with the page's b/f as Balance b/d and closes with its
 * c/f as Balance c/d, so a long account reads like consecutive ledger folios.
 * Only the last page reopens the closing below the double rule.
 */
export default function TAccountView({ account, rows, meta, summary, filters, page, isFirst, isLast }) {
  const debit  = []
  const credit = []

  const bf = Number(meta?.balance_bf ?? 0)
  const bfDate = isFirst ? (filters.date_from || rows[0]?.journal_date || '') : (rows[0]?.journal_date || '')
  const balance = (side, label, date, amount, hint) => ({
    key: `${side}-${label}`, date, isBalance: true, label, hint, amount: Math.abs(amount),
  })

  if (bf > 0.004) debit.push(balance('debit', 'Balance b/d', bfDate, bf, isFirst ? null : `from page ${page - 1}`))
  if (bf < -0.004) credit.push(balance('credit', 'Balance b/d', bfDate, bf, isFirst ? null : `from page ${page - 1}`))

  for (const r of rows) {
    const entry = (amount) => ({
      key:       r.journal_line_id,
      date:      r.journal_date,
      contra:    r.contra,
      ref:       r.journal_no,
      document:  r.document_no ? `${r.source_label} ${r.document_no}` : r.source_label,
      narration: [r.narration, r.description].filter(Boolean).join(' — '),
      party:     r.party_name,
      reversed:  r.is_reversed,
      amount,
    })
    if (r.debit > 0) debit.push(entry(r.debit))
    if (r.credit > 0) credit.push(entry(r.credit))
  }

  const cf     = Number(isLast ? summary.closing : (meta?.balance_cf ?? 0))
  const cdDate = isLast ? (filters.date_to || today()) : (rows[rows.length - 1]?.journal_date || '')
  const cdHint = isLast ? null : `to page ${page + 1}`

  // Balance c/d on the smaller side, so both sides agree.
  if (cf > 0.004) credit.push(balance('credit', 'Balance c/d', cdDate, cf, cdHint))
  if (cf < -0.004) debit.push(balance('debit', 'Balance c/d', cdDate, cf, cdHint))

  const sum   = (list) => list.reduce((s, e) => s + Number(e.amount || 0), 0)
  const total = Math.max(sum(debit), sum(credit))

  const reopen = isLast && Math.abs(cf) > 0.004
    ? { side: cf > 0 ? 'debit' : 'credit', date: nextDay(cdDate), amount: Math.abs(cf) }
    : null

  return (
    <div className="overflow-hidden rounded-lg border border-slate-300 shadow-sm">
      {/* ── Title bar ── */}
      <div className="flex items-center bg-linear-to-r from-indigo-800 via-indigo-700 to-indigo-800 px-3 py-1.5 text-white">
        <span className="rounded bg-white/15 px-2 py-0.5 text-sm font-black tracking-wider">Dr</span>
        <h3 className="flex-1 truncate text-center text-sm font-bold tracking-wide">
          {account.ledger_account_name} Account
          <span className="ml-2 font-mono text-xs font-normal text-indigo-200">({account.code})</span>
        </h3>
        <span className="rounded bg-white/15 px-2 py-0.5 text-sm font-black tracking-wider">Cr</span>
      </div>

      {/* ── The two sides ── */}
      <div className="grid grid-cols-1 lg:grid-cols-2">
        <Side side="debit" accountName={account.ledger_account_name} entries={debit} total={total} reopen={reopen?.side === 'debit' ? reopen : null} isLast={isLast} />
        <Side side="credit" accountName={account.ledger_account_name} entries={credit} total={total} reopen={reopen?.side === 'credit' ? reopen : null} isLast={isLast} divider />
      </div>

      {!isLast && (
        <p className="border-t border-slate-200 bg-slate-50 px-3 py-1 text-center text-[11px] text-slate-500">
          Continued on page {page + 1} — Balance c/d is brought down there as Balance b/d.
        </p>
      )}

      {/* How to read To / By -- the words name the other account, not where money went. */}
      <div className="flex items-start gap-1.5 border-t border-sky-200 bg-sky-50 px-3 py-1.5 text-[11px] leading-snug text-sky-900">
        <Info size={13} className="mt-px shrink-0 text-sky-600" />
        <p>
          <strong>How to read:</strong> Left side = <strong className="text-indigo-700">Debit</strong>, right side = <strong className="text-amber-800">Credit</strong>.{' '}
          <span className={`rounded px-1 text-[9px] font-bold uppercase ${SIDE.debit.word_cls}`}>To</span> /{' '}
          <span className={`rounded px-1 text-[9px] font-bold uppercase ${SIDE.credit.word_cls}`}>By</span>{' '}
          name the <strong>other account in the same voucher</strong> — not where the money went.
          E.g. <em>“To Cash in Hand”</em> on this account means <strong>this account was debited and Cash in Hand was credited</strong>.
          {' '}<strong>Sundries</strong> = several other accounts. Hover a line for its plain-English meaning.
        </p>
      </div>
    </div>
  )
}

// Plain-English meaning of one T-account line, shown on hover.
const explain = (side, accountName, e) => {
  const other  = e.contra?.accounts?.length > 1
    ? `${e.contra.accounts.length} accounts (${e.contra.accounts.map((a) => a.name).join(', ')})`
    : (e.contra?.label ?? 'the other account')
  const amount = money(e.amount)
  return side === 'debit'
    ? `${accountName} was DEBITED ${amount}.\nThe other side of ${e.ref}: ${other} was credited.`
    : `${accountName} was CREDITED ${amount}.\nThe other side of ${e.ref}: ${other} was debited.`
}

// Hover note for Balance b/d and Balance c/d rows.
// b/d sits on the side of the balance; c/d sits on the opposite (smaller) side.
const explainBalance = (side, accountName, e) => {
  const amount = money(e.amount)
  const kind   = (s) => (s === 'debit' ? 'DEBIT' : 'CREDIT')
  const other  = side === 'debit' ? 'credit' : 'debit'
  if (e.label === 'Balance b/d') {
    const from = e.hint ? `the previous page (${e.hint})` : 'the previous period'
    return `Balance b/d = balance brought down from ${from}.\n${accountName} started with a ${kind(side)} balance of ${amount}.`
  }
  const bigger = side === 'credit' ? 'Debits' : 'Credits'
  const smaller = side === 'credit' ? 'credits' : 'debits'
  const where = e.hint ? `It is brought down on the next page (${e.hint}) as Balance b/d.` : 'It is brought down below the totals as Balance b/d.'
  return `Balance c/d = closing balance carried down (a balancing figure, not a transaction).\n${bigger} exceed ${smaller} by ${amount}, so it is written on this side to make both totals equal.\n${where}\nThe account has a ${kind(other)} balance of ${amount}.`
}

function Side({ side, accountName, entries, total, reopen, divider = false, isLast }) {
  const s = SIDE[side]

  return (
    <div className={`flex min-w-0 flex-col ${divider ? 'border-t-2 border-slate-800 lg:border-t-0 lg:border-l-2' : ''}`}>
      <table className="w-full table-fixed text-xs">
        <colgroup>
          <col className="w-[5.5rem]" />
          <col />
          <col className="w-[6.5rem]" />
          <col className="w-[6.5rem]" />
        </colgroup>
        <thead>
          <tr className={`border-b ${s.head} text-[10px] font-bold uppercase tracking-wider`}>
            <th className="px-2 py-1.5 text-left">Date</th>
            <th className="px-2 py-1.5 text-left">Particulars</th>
            <th className="px-2 py-1.5 text-left">Ref</th>
            <th className="px-2 py-1.5 text-right">Amount</th>
          </tr>
        </thead>
        <tbody>
          {entries.length === 0 && (
            <tr><td colSpan={4} className="px-2 py-4 text-center text-[11px] italic text-slate-400">No {side} entries on this page.</td></tr>
          )}

          {entries.map((e) => (e.isBalance ? (
            <tr key={e.key} className="cursor-help border-b border-slate-100 bg-slate-100/80" title={explainBalance(side, accountName, e)}>
              <td className="whitespace-nowrap px-2 py-1.5 text-slate-500">{e.date}</td>
              <td className="px-2 py-1.5" colSpan={2}>
                <span className={`mr-1 rounded px-1 py-px text-[9px] font-bold uppercase ${s.word_cls}`}>{s.word}</span>
                <span className="font-semibold italic text-slate-700">{e.label}</span>
                {e.hint && <span className="ml-1 text-[10px] text-slate-400">({e.hint})</span>}
              </td>
              <td className="whitespace-nowrap px-2 py-1.5 text-right font-bold italic tabular-nums text-slate-700">{money(e.amount)}</td>
            </tr>
          ) : (
            <tr key={e.key} className={`border-b border-slate-100 ${s.row}`} title={explain(side, accountName, e)}>
              <td className="whitespace-nowrap px-2 py-1.5 align-top text-slate-600">{e.date}</td>
              <td className="px-2 py-1.5 align-top">
                <div className="flex min-w-0 items-center gap-1">
                  <span className={`shrink-0 rounded px-1 py-px text-[9px] font-bold uppercase ${s.word_cls}`}>{s.word}</span>
                  <span
                    className={`truncate font-semibold text-slate-800 ${e.contra?.accounts?.length > 1 ? 'cursor-help' : ''}`}
                    title={(e.contra?.accounts ?? []).map((a) => `${a.code} · ${a.name}`).join('\n')}
                  >
                    {e.contra?.accounts?.length > 1 ? 'Sundries' : (e.contra?.label ?? '—')}
                  </span>
                  {e.contra?.accounts?.length > 1 && (
                    <span className="shrink-0 rounded-full bg-slate-200 px-1.5 text-[9px] font-semibold text-slate-600">
                      {e.contra.accounts.length} a/c
                    </span>
                  )}
                  {e.reversed && (
                    <span className="shrink-0 rounded border border-slate-200 bg-slate-100 px-1 text-[9px] font-semibold uppercase text-slate-500">Rev</span>
                  )}
                </div>
                {(e.narration || e.party) && (
                  <p className="mt-0.5 truncate text-[10px] text-slate-400" title={[e.party, e.narration].filter(Boolean).join(' · ')}>
                    {[e.party, e.narration].filter(Boolean).join(' · ')}
                  </p>
                )}
              </td>
              <td className="px-2 py-1.5 align-top">
                <span className="block truncate font-mono text-[11px] font-semibold text-slate-700">{e.ref}</span>
                {e.document && <span className="block truncate text-[10px] text-slate-400" title={e.document}>{e.document}</span>}
              </td>
              <td className={`whitespace-nowrap px-2 py-1.5 text-right align-top font-semibold tabular-nums ${s.amount}`}>{money(e.amount)}</td>
            </tr>
          )))}
        </tbody>
      </table>

      {/* Pushes the totals to the bottom, so both sides' totals line up. */}
      <div className="flex-1" />

      <div className={`flex items-center justify-end gap-3 border-t border-slate-800 px-2 py-1.5 ${s.total}`}>
        <span className="text-[10px] font-bold uppercase tracking-wider text-slate-500">{isLast ? 'Total' : 'Page total'}</span>
        <span className="min-w-[6.5rem] border-b-4 border-double border-slate-800 text-right text-sm font-black tabular-nums text-slate-900">
          {money(total)}
        </span>
      </div>

      <div className="min-h-8 px-2 py-1.5">
        {reopen && (
          <div
            className="flex cursor-help items-center gap-1 text-xs"
            title={`Balance b/d = next period's opening balance, brought down from Balance c/d above.\n${accountName} opens ${reopen.date} with a ${side === 'debit' ? 'DEBIT' : 'CREDIT'} balance of ${money(reopen.amount)}.`}
          >
            <CornerDownRight size={13} className="text-indigo-500" />
            <span className="w-[5.5rem] text-slate-500">{reopen.date}</span>
            <span className={`rounded px-1 py-px text-[9px] font-bold uppercase ${s.word_cls}`}>{s.word}</span>
            <span className="flex-1 font-bold italic text-indigo-900">Balance b/d</span>
            <span className="font-bold italic tabular-nums text-indigo-900">{money(reopen.amount)}</span>
          </div>
        )}
      </div>
    </div>
  )
}
