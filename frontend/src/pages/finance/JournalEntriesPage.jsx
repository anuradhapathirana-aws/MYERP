import { Fragment, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ChevronDown, ChevronRight, Check, Plus, RotateCcw, Trash2,
} from 'lucide-react'
import {
  deleteJournalEntry, getJournalEntries, getJournalEntry,
  postJournalEntry, reverseJournalEntry,
} from '../../api/journalEntries'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmAction, confirmWithReason, showError, showSuccess, showWarning } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS, LABEL_CLS } from '../../utils/fieldStyles'

const CRUMBS = [{ label: 'Finance' }, { label: 'Journal Vouchers' }]

const money = (n) =>
  Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const STATUS_STYLE = {
  draft:    'bg-amber-50 text-amber-700 border-amber-200',
  posted:   'bg-green-50 text-green-700 border-green-200',
  reversed: 'bg-slate-100 text-slate-500 border-slate-200',
}

/**
 * Every voucher in the ledger, hand-entered and automatic alike.
 *
 * Three things are deliberately absent. There is no Edit, because a posted
 * journal is immutable. Delete appears only on drafts. And a reversed journal
 * is still shown in full, because it still counts towards every balance -- the
 * original and its mirror cancel only if both remain.
 */
export default function JournalEntriesPage() {
  const qc      = useQueryClient()
  const { can } = usePermissions()

  const [page, setPage]         = useState(1)
  const [expanded, setExpanded] = useState(null)
  const [filters, setFilters]   = useState({
    search: '', journal_type: '', status: '', date_from: '', date_to: '',
  })

  const { data, isLoading } = useQuery({
    queryKey: ['journal-entries', page, filters],
    queryFn:  () => getJournalEntries(page, filters),
    enabled:  can('view_journal_entries'),
  })

  const { data: detail } = useQuery({
    queryKey: ['journal-entry', expanded],
    queryFn:  () => getJournalEntry(expanded),
    enabled:  Boolean(expanded),
  })

  const refresh = () => {
    qc.invalidateQueries({ queryKey: ['journal-entries'] })
    qc.invalidateQueries({ queryKey: ['trial-balance'] })
  }

  const postMutation = useMutation({
    mutationFn: postJournalEntry,
    onSuccess: (res) => { refresh(); showSuccess(`${res?.data?.journal_no} posted.`) },
    onError:   (e) => showError(e?.response?.data?.message ?? 'Could not post this journal.'),
  })

  const reverseMutation = useMutation({
    mutationFn: ({ id, narration }) => reverseJournalEntry(id, narration),
    onSuccess: (res) => { refresh(); showSuccess(`Reversed as ${res?.data?.journal_no}.`) },
    onError:   (e) => showError(e?.response?.data?.message ?? 'Could not reverse this journal.'),
  })

  const deleteMutation = useMutation({
    mutationFn: deleteJournalEntry,
    onSuccess: () => { refresh(); showSuccess('Draft deleted.') },
    onError:   (e) => {
      // A 404 means the row on screen no longer exists -- it was deleted here
      // on an earlier click, or discarded when its source document was voided.
      // Refetching makes it disappear instead of reporting a raw
      // "No query results for model" at the user.
      if (e?.response?.status === 404) {
        refresh()
        showWarning('That voucher no longer exists. The list has been refreshed.')

        return
      }

      showError(
        e?.response?.data?.errors?.id?.[0]
        ?? e?.response?.data?.errors?.status?.[0]
        ?? e?.response?.data?.message
        ?? 'Could not delete this journal.',
      )
    },
  })

  const askReverse = async (row) => {
    const reason = await confirmWithReason({
      title:        `Reverse ${row.journal_no}?`,
      inputLabel:   'Why is this being reversed?',
      inputPlaceholder: 'e.g. Wrong amount entered',
      confirmText:  'Post Reversal',
    })

    if (reason === null || reason === undefined) return

    reverseMutation.mutate({ id: row.id, narration: reason || null })
  }

  const askPost = async (row) => {
    const ok = await confirmAction({
      title:   `Post ${row.journal_no}?`,
      message: 'Once posted it becomes part of the books and can only be undone by a reversal.',
      confirmText: 'Yes, Post',
    })

    if (ok) postMutation.mutate(row.id)
  }

  if (!can('view_journal_entries')) {
    return (
      <div className="p-3">
        <Breadcrumb crumbs={CRUMBS} />
        <p className="mt-3 rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500">
          You do not have permission to view journal vouchers.
        </p>
      </div>
    )
  }

  const rows = data?.data ?? []

  return (
    <div className="p-3">
      <div className="flex items-center gap-2">
        <Breadcrumb crumbs={CRUMBS} />
        {can('create_journal_entries') && (
          <Link
            to="/finance/journal-voucher"
            className="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} /> New Voucher
          </Link>
        )}
      </div>

      {/* ── Filters ─────────────────────────────────────────────────── */}
      <div className="mt-2 rounded-lg border border-slate-200 bg-white p-2">
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <label className={LABEL_CLS}>Search</label>
            <input
              type="text"
              value={filters.search}
              onChange={(e) => { setFilters({ ...filters, search: e.target.value }); setPage(1) }}
              placeholder="Number or narration"
              className={FILTER_INPUT_CLS}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>Type</label>
            <select
              value={filters.journal_type}
              onChange={(e) => { setFilters({ ...filters, journal_type: e.target.value }); setPage(1) }}
              className={FILTER_SELECT_CLS}
            >
              <option value="">All</option>
              <option value="general">General</option>
              <option value="opening">Opening Balance</option>
              <option value="adjustment">Adjustment</option>
              <option value="reversal">Reversal</option>
              <option value="auto">Automatic</option>
            </select>
          </div>
          <div>
            <label className={LABEL_CLS}>Status</label>
            <select
              value={filters.status}
              onChange={(e) => { setFilters({ ...filters, status: e.target.value }); setPage(1) }}
              className={FILTER_SELECT_CLS}
            >
              <option value="">All</option>
              <option value="draft">Draft</option>
              <option value="posted">Posted</option>
              <option value="reversed">Reversed</option>
            </select>
          </div>
          <div>
            <label className={LABEL_CLS}>From</label>
            <input
              type="date"
              value={filters.date_from}
              onChange={(e) => { setFilters({ ...filters, date_from: e.target.value }); setPage(1) }}
              className={FILTER_INPUT_CLS}
            />
          </div>
          <div>
            <label className={LABEL_CLS}>To</label>
            <input
              type="date"
              value={filters.date_to}
              onChange={(e) => { setFilters({ ...filters, date_to: e.target.value }); setPage(1) }}
              className={FILTER_INPUT_CLS}
            />
          </div>
        </div>
      </div>

      {/* ── List ────────────────────────────────────────────────────── */}
      <div className="mt-2 overflow-x-auto rounded-lg border border-slate-200 bg-white">
        <table className="w-full text-sm">
          <thead className="sticky top-0 bg-slate-50 text-[10px] uppercase tracking-wider text-slate-500">
            <tr>
              <th className="w-8 px-2 py-2" />
              <th className="px-2 py-2 text-left">Number</th>
              <th className="px-2 py-2 text-left">Date</th>
              <th className="px-2 py-2 text-left">Type</th>
              <th className="px-2 py-2 text-left">Status</th>
              <th className="px-2 py-2 text-left">Narration</th>
              <th className="px-2 py-2 text-right">Debit</th>
              <th className="px-2 py-2 text-right">Credit</th>
              <th className="w-24 px-2 py-2 text-center">Actions</th>
            </tr>
          </thead>

          <tbody>
            {isLoading && (
              <tr><td colSpan={9} className="px-2 py-6 text-center text-sm text-slate-400">Loading…</td></tr>
            )}

            {!isLoading && rows.length === 0 && (
              <tr>
                <td colSpan={9} className="px-2 py-6 text-center text-sm text-slate-400">
                  No journals yet. Create a voucher to post your first entry.
                </td>
              </tr>
            )}

            {rows.map((row) => {
              const isOpen = expanded === row.id

              return (
                <Fragment key={row.id}>
                  <tr className="border-t border-slate-100 hover:bg-slate-50">
                    <td className="px-2 py-1.5">
                      <button
                        type="button"
                        onClick={() => setExpanded(isOpen ? null : row.id)}
                        title={isOpen ? 'Hide lines' : 'Show lines'}
                        className="rounded p-0.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600"
                      >
                        {isOpen ? <ChevronDown size={14} /> : <ChevronRight size={14} />}
                      </button>
                    </td>

                    <td className="px-2 py-1.5 font-mono text-xs font-semibold text-slate-700">
                      {row.journal_no}
                    </td>
                    <td className="px-2 py-1.5 text-xs text-slate-600">{row.journal_date}</td>
                    <td className="px-2 py-1.5 text-xs text-slate-600">{row.journal_type_label}</td>

                    <td className="px-2 py-1.5">
                      <span className={`rounded border px-1.5 py-px text-[10px] font-semibold uppercase ${STATUS_STYLE[row.status] ?? ''}`}>
                        {row.status_label}
                      </span>
                    </td>

                    <td className="max-w-xs px-2 py-1.5 text-xs text-slate-600">
                      <span className="line-clamp-1" title={row.narration ?? ''}>
                        {row.narration ?? (row.source_type ? `From ${row.source_type.replace(/_/g, ' ')}` : '—')}
                      </span>
                    </td>

                    <td className="px-2 py-1.5 text-right tabular-nums text-slate-800">{money(row.total_debit)}</td>
                    <td className="px-2 py-1.5 text-right tabular-nums text-slate-800">{money(row.total_credit)}</td>

                    <td className="px-2 py-1.5">
                      <div className="flex items-center justify-center gap-0.5">
                        {row.status === 'draft' && can('post_journal_entries') && (
                          <button
                            type="button"
                            onClick={() => askPost(row)}
                            title="Post this draft"
                            className="rounded p-1 text-green-600 transition-colors hover:bg-green-50"
                          >
                            <Check size={14} />
                          </button>
                        )}

                        {row.status === 'posted' && can('reverse_journal_entries') && (
                          <button
                            type="button"
                            onClick={() => askReverse(row)}
                            title="Post a reversing journal"
                            className="rounded p-1 text-amber-600 transition-colors hover:bg-amber-50"
                          >
                            <RotateCcw size={14} />
                          </button>
                        )}

                        {row.status === 'draft' && can('delete_journal_entries') && (
                          <button
                            type="button"
                            onClick={() => deleteMutation.mutate(row.id)}
                            title="Delete this draft"
                            className="rounded p-1 text-red-500 transition-colors hover:bg-red-50"
                          >
                            <Trash2 size={14} />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>

                  {isOpen && (
                    <tr className="border-t border-slate-100 bg-slate-50/60">
                      <td />
                      <td colSpan={8} className="px-2 py-2">
                        {/* A two-line journal reads as From -> To, the way a
                            Contra voucher does. With three or more there is no
                            meaningful pairing, so every line is listed. */}
                        {detail?.data?.transfer ? (
                          <div className="flex flex-wrap items-center gap-2 text-xs">
                            <span className="rounded bg-white px-2 py-1 font-mono text-slate-500">
                              From
                            </span>
                            <span className="font-semibold text-slate-700">
                              {detail.data.transfer.from_account_code} · {detail.data.transfer.from_account_name}
                            </span>
                            <ChevronRight size={13} className="text-slate-400" />
                            <span className="rounded bg-white px-2 py-1 font-mono text-slate-500">To</span>
                            <span className="font-semibold text-slate-700">
                              {detail.data.transfer.to_account_code} · {detail.data.transfer.to_account_name}
                            </span>
                            <span className="ml-auto tabular-nums font-bold text-slate-800">
                              {money(detail.data.transfer.amount)}
                            </span>
                          </div>
                        ) : (
                          <table className="w-full text-xs">
                            <thead className="text-[10px] uppercase tracking-wider text-slate-400">
                              <tr>
                                <th className="py-1 text-left">Account</th>
                                <th className="py-1 text-left">Description</th>
                                <th className="py-1 text-left">Party</th>
                                <th className="py-1 text-right">Debit</th>
                                <th className="py-1 text-right">Credit</th>
                              </tr>
                            </thead>
                            <tbody>
                              {(detail?.data?.lines ?? []).map((l) => (
                                <tr key={l.id} className="border-t border-slate-200/70">
                                  <td className="py-1 text-slate-700">
                                    <span className="font-mono text-slate-400">{l.ledger_account_code}</span>{' '}
                                    {l.ledger_account_name}
                                  </td>
                                  <td className="py-1 text-slate-500">{l.description ?? '—'}</td>
                                  <td className="py-1 text-slate-500">
                                    {l.party_type ? `${l.party_type} #${l.party_id}` : '—'}
                                  </td>
                                  <td className="py-1 text-right tabular-nums text-slate-700">
                                    {l.debit > 0 ? money(l.debit) : ''}
                                  </td>
                                  <td className="py-1 text-right tabular-nums text-slate-700">
                                    {l.credit > 0 ? money(l.credit) : ''}
                                  </td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        )}

                        {detail?.data?.reversed_by_journal_id && (
                          <p className="mt-1.5 text-[11px] text-slate-500">
                            Reversed by journal #{detail.data.reversed_by_journal_id}. Both entries still
                            count towards balances — together they cancel.
                          </p>
                        )}
                      </td>
                    </tr>
                  )}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>

      <Pagination meta={data?.meta} page={page} onPageChange={setPage} />
    </div>
  )
}
