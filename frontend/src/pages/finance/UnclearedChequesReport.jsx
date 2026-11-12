import { Fragment, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { AlertTriangle, ArrowDownCircle, ArrowUpCircle, Clock, Landmark, ScrollText } from 'lucide-react'
import {
  downloadUnclearedChequesCsv,
  downloadUnclearedChequesPdf,
  getUnclearedChequesOptions,
  getUnclearedChequesReport,
} from '../../api/unclearedCheques'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import { ExcelBtn, PdfBtn, PrintBtn } from '../../components/ui/ActionButtons'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import Money from '../../components/ui/Money'
import { useTableFilter } from '../../hooks/useTableFilter'
import { showError } from '../../utils/alerts'
import { CURRENCY_CODE } from '../../utils/currency'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'
import { printPdfBlob } from '../../utils/pdf'

const CRUMBS = [
  { label: 'Finance' },
  { label: 'Banking' },
  { label: 'Uncleared Cheques' },
]

/** Today in the user's own time zone (toISOString would give UTC's date). */
const todayYmd = () => {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

const fmtDate = (d) => (d ? d.split('-').reverse().join('-') : '')

const INITIAL_FILTERS = { as_at: todayYmd(), company_id: '', bank_account_id: '', type: '', search: '' }

const SECTIONS = [
  { type: 'issued',   label: 'Issued, not presented',  hint: 'Cheques we paid out that the bank has not paid yet', icon: ArrowUpCircle,   tone: 'text-amber-600' },
  { type: 'received', label: 'Deposited, not cleared', hint: 'Customer cheques banked but not yet credited',      icon: ArrowDownCircle, tone: 'text-sky-600' },
]

const TH = 'sticky top-0 z-10 bg-slate-50 px-2 py-1.5 font-semibold uppercase tracking-wider text-slate-500'
const NUM = 'px-2 py-1.5 text-right tabular-nums'

function StatTile({ label, value, note, tone = 'text-slate-800' }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-3 py-2 shadow-sm">
      <div className="text-[10px] font-medium uppercase tracking-wide text-slate-400">{label}</div>
      <div className={`mt-0.5 text-base font-semibold ${tone}`}>{value}</div>
      {note && <div className="mt-0.5 truncate text-[10px] leading-tight text-slate-400" title={note}>{note}</div>}
    </div>
  )
}

function Flag({ children, tone, title }) {
  return <span title={title} className={`rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase tracking-wide ${tone}`}>{children}</span>
}

/**
 * Uncleared Cheques: every cheque on a bank account that the bank had not
 * cleared at the end of the As at day -- issued and not presented, or
 * deposited and not yet credited. "Cleared" = realized or returned on a posted
 * Bank Reconciliation dated on or before that day, so an old date reprints the
 * list as it stood then.
 */
export default function UnclearedChequesReport() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf' | 'csv'

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS, { openByDefault: true })

  const { data: options = {} } = useQuery({
    queryKey: ['uncleared-cheques-options'],
    queryFn: getUnclearedChequesOptions,
    staleTime: 5 * 60 * 1000,
  })

  const { data, isLoading, isError, error } = useQuery({
    queryKey: ['report-uncleared-cheques', applied],
    queryFn: () => getUnclearedChequesReport(applied),
    enabled: Boolean(applied.as_at),
    placeholderData: (prev) => prev,
  })

  const companies = options.companies ?? []
  const bankOptions = useMemo(
    () => (options.bank_accounts ?? []).filter((b) => !draft.company_id || b.company_id === null || String(b.company_id) === String(draft.company_id)),
    [options.bank_accounts, draft.company_id],
  )

  const rows = data?.rows ?? []
  const summary = data?.summary
  const banks = data?.banks ?? []

  const setField = (key) => (e) => {
    const value = e.target.value
    setDraft((d) => ({ ...d, [key]: value, ...(key === 'company_id' ? { bank_account_id: '' } : {}) }))
  }

  /** The As at date is required and cannot be in the future. */
  const onApply = () => {
    if (!draft.as_at) { showError('Choose the As at date.'); return }
    if (draft.as_at > todayYmd()) { showError('The As at date cannot be in the future.'); return }
    apply()
  }

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      if (action === 'print') {
        printPdfBlob(await downloadUnclearedChequesPdf(applied))
      } else {
        const blob = action === 'pdf' ? await downloadUnclearedChequesPdf(applied) : await downloadUnclearedChequesCsv(applied)
        const url = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = url
        a.download = `UnclearedCheques_${applied.as_at.replaceAll('-', '')}.${action === 'pdf' ? 'pdf' : 'csv'}`
        document.body.appendChild(a)
        a.click()
        a.remove()
        setTimeout(() => URL.revokeObjectURL(url), 1000)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the uncleared cheques report.`)
    } finally {
      setExportBusy(null)
    }
  }

  const exportsDisabled = !data || Boolean(exportBusy)
  const shown = SECTIONS.filter((s) => !applied.type || applied.type === s.type)
  const errorText = error?.response?.data?.message ?? 'Failed to load the uncleared cheques report.'

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <ScrollText size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Uncleared Cheques</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <div className="flex items-center gap-1.5">
          <PrintBtn onClick={() => handleExport('print')} disabled={exportsDisabled} title="Print" />
          <PdfBtn onClick={() => handleExport('pdf')} disabled={exportsDisabled} title="Download PDF" />
          <ExcelBtn onClick={() => handleExport('csv')} disabled={exportsDisabled} title="Download Excel (CSV)" />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={onApply} onClear={() => clear()} activeCount={activeCount}>
        <FilterField label="As at *">
          <input type="date" max={todayYmd()} className={FILTER_INPUT_CLS} value={draft.as_at} onChange={setField('as_at')} />
        </FilterField>
        <FilterField label="Company">
          <select className={FILTER_SELECT_CLS} value={draft.company_id} onChange={setField('company_id')}>
            <option value="">All companies</option>
            {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        </FilterField>
        <FilterField label="Bank Account">
          <select className={FILTER_SELECT_CLS} value={draft.bank_account_id} onChange={setField('bank_account_id')}>
            <option value="">All bank accounts</option>
            {bankOptions.map((b) => (
              <option key={b.id} value={b.id}>{[b.code, b.name].filter(Boolean).join(' - ')}{b.bank_account_no ? ` (${b.bank_account_no})` : ''}</option>
            ))}
          </select>
        </FilterField>
        <FilterField label="Type">
          <select className={FILTER_SELECT_CLS} value={draft.type} onChange={setField('type')}>
            <option value="">All cheques</option>
            <option value="issued">Issued, not presented</option>
            <option value="received">Deposited, not cleared</option>
          </select>
        </FilterField>
        <FilterField label="Search">
          <input type="text" maxLength={50} placeholder="Cheque no, party, document" className={FILTER_INPUT_CLS} value={draft.search} onChange={setField('search')} />
        </FilterField>
      </TableFilter>

      {summary && (
        <div className="mt-2 grid grid-cols-2 gap-2 lg:grid-cols-4">
          <StatTile label="Issued, not presented" tone="text-amber-700"
            value={<Money value={summary.issued_total} />} note={`${summary.issued_count} cheque(s) the bank has not paid yet`} />
          <StatTile label="Deposited, not cleared" tone="text-sky-700"
            value={<Money value={summary.received_total} />} note={`${summary.received_count} cheque(s) not yet credited`} />
          <StatTile label="Stale cheques" tone={summary.stale_count ? 'text-red-600' : 'text-slate-800'}
            value={summary.stale_count} note={`Cheque date more than ${data.header.stale_months} months old`} />
          <StatTile label="Bank accounts" value={banks.length} note={data.header.bank_account} />
        </div>
      )}

      {data?.truncated && (
        <div className="mt-2 flex items-center gap-1.5 rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800">
          <AlertTriangle size={13} /> Only the first 5,000 cheques are listed. Choose a bank account or type to see them all.
        </div>
      )}

      {/* ── Per bank account, when more than one has items ── */}
      {banks.length > 1 && (
        <div className="mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b border-slate-200 text-left">
                <th className={TH}>Bank Account</th>
                <th className={TH}>Bank / A/C No</th>
                <th className={`${TH} text-right`}>Issued</th>
                <th className={`${TH} w-36 text-right`}>Not presented</th>
                <th className={`${TH} text-right`}>Deposited</th>
                <th className={`${TH} w-36 text-right`}>Not cleared</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {banks.map((b) => (
                <tr key={b.bank_account_id} className="hover:bg-slate-50">
                  <td className="px-2 py-1.5 font-medium text-slate-800">
                    <span className="inline-flex items-center gap-1"><Landmark size={12} className="text-slate-400" />{b.bank_account}</span>
                  </td>
                  <td className="px-2 py-1.5 text-slate-500">{[b.bank_name, b.bank_account_no].filter(Boolean).join(' · ')}</td>
                  <td className={`${NUM} text-slate-600`}>{b.issued_count}</td>
                  <td className={`${NUM} text-slate-800`}><Money value={b.issued_total} /></td>
                  <td className={`${NUM} text-slate-600`}>{b.received_count}</td>
                  <td className={`${NUM} text-slate-800`}><Money value={b.received_total} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* ── The cheques ── */}
      <div className="mt-2 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>}
        {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">{errorText}</div>}

        {!isLoading && !isError && data && (
          <div className="max-h-[65vh] overflow-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b border-slate-200 text-left">
                  <th className={TH}>Bank Account</th>
                  <th className={TH}>Cheque No</th>
                  <th className={TH}>Chq Date</th>
                  <th className={TH}>Journal</th>
                  <th className={TH}>Document</th>
                  <th className={TH}>Party / Description</th>
                  <th className={`${TH} text-right`}>Days</th>
                  <th className={TH}>Flags</th>
                  <th className={`${TH} w-36 text-right`}>Amount</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rows.length === 0 ? (
                  <tr><td colSpan={9} className="px-4 py-12 text-center text-sm text-slate-400">No uncleared cheques as at {fmtDate(applied.as_at)}.</td></tr>
                ) : shown.map((s) => {
                  const group = rows.filter((r) => r.type === s.type)
                  const Icon = s.icon
                  return (
                    <Fragment key={s.type}>
                      <tr className="bg-slate-100/70">
                        <td colSpan={9} className="px-2 py-1 text-[11px] font-bold uppercase tracking-wider text-slate-600">
                          <span className="inline-flex items-center gap-1"><Icon size={13} className={s.tone} />{s.label} ({group.length})</span>
                          <span className="ml-2 font-normal normal-case tracking-normal text-slate-400">{s.hint}</span>
                        </td>
                      </tr>
                      {group.length === 0 && (
                        <tr><td colSpan={9} className="px-2 py-2 text-slate-400">None.</td></tr>
                      )}
                      {group.map((r) => (
                        <tr key={r.journal_line_id} className="transition-colors hover:bg-slate-50">
                          <td className="max-w-[12rem] truncate px-2 py-1.5 text-slate-600" title={r.bank_account}>{r.bank_account}</td>
                          <td className="px-2 py-1.5 font-semibold text-slate-800">{r.cheque_no}</td>
                          <td className="whitespace-nowrap px-2 py-1.5 text-slate-600">{fmtDate(r.cheque_date)}</td>
                          <td className="whitespace-nowrap px-2 py-1.5 text-slate-600">
                            {r.journal_no}<span className="ml-1 text-[10px] text-slate-400">{fmtDate(r.journal_date)}</span>
                          </td>
                          <td className="whitespace-nowrap px-2 py-1.5 text-slate-600">{r.document_no ?? '—'}</td>
                          <td className="max-w-[16rem] truncate px-2 py-1.5 text-slate-700" title={[r.party_name, r.description, r.drawee_bank].filter(Boolean).join(' · ')}>
                            {r.party_name ?? r.description}
                            {r.drawee_bank && <span className="ml-1 text-[10px] text-slate-400">{r.drawee_bank}</span>}
                          </td>
                          <td className={`${NUM} ${r.days_outstanding > 30 ? 'font-semibold text-amber-700' : 'text-slate-600'}`}>{r.days_outstanding}</td>
                          <td className="whitespace-nowrap px-2 py-1.5">
                            {r.is_stale && <Flag tone="bg-red-100 text-red-700" title={`Cheque date more than ${data.header.stale_months} months before the As at date`}>Stale</Flag>}
                            {r.is_post_dated && <Flag tone="bg-amber-100 text-amber-700" title="Post-dated: the cheque date is after the As at date">PDC</Flag>}
                          </td>
                          <td className={`${NUM} font-medium text-slate-800`}><Money value={r.amount} /></td>
                        </tr>
                      ))}
                      {group.length > 0 && (
                        <tr className="border-t border-slate-300 bg-slate-50 font-bold text-slate-800">
                          <td colSpan={8} className="px-2 py-1.5 text-right uppercase tracking-wider text-slate-500">Total {s.label} ({CURRENCY_CODE})</td>
                          <td className={NUM}><Money value={s.type === 'issued' ? summary.issued_total : summary.received_total} /></td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <p className="mt-1.5 flex items-center gap-1 text-[10px] text-slate-400">
        <Clock size={11} /> A cheque stays here until a posted Bank Reconciliation dated on or before the As at date marks it Realized or Returned.
        Days = days since it was entered in the books. Only entries with a cheque number are listed.
      </p>

      <KeyboardShortcuts shortcuts={[
        { keys: ['Enter'], desc: 'Apply the filters (in any filter field)' },
      ]} />
    </div>
  )
}
