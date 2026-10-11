import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, Check, Link2, Save, Settings2, ShieldAlert, Users, Zap,
} from 'lucide-react'
import {
  getFinanceSettings, updateAccountMappings, updatePartyLedgerMode, updatePostingMode,
} from '../../api/financeSettings'
import { getAllLedgerAccounts } from '../../api/ledgerAccounts'
import { getAllCompanies } from '../../api/companies'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Breadcrumb from '../../components/Breadcrumb'
import { usePermissions } from '../../hooks/usePermissions'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'
import { FILTER_SELECT_CLS } from '../../utils/fieldStyles'

const CRUMBS = [{ label: 'Finance' }, { label: 'Account Mapping' }]

const EMPTY_ARRAY = []

/**
 * Finance > Account Mapping: which ledger account each posting role resolves to.
 *
 * Set up once per installation, then almost never opened again -- which is
 * exactly why it has to exist. Without this screen the mapping could only be
 * seeded blind or changed with SQL, and a client could never see WHY a document
 * refused to confirm.
 *
 * ── The readiness banner is the point of the page ────────────────────────────
 * missing_required comes from AccountResolver::missingRequiredRoles(). An
 * incomplete setup shows here, before a document fails, rather than as a 422 at
 * the moment someone tries to confirm a payment.
 *
 * ── Why the account list is filtered per row ─────────────────────────────────
 * Each role declares accepted_types. Mapping Accounts Payable to Sales Revenue
 * would balance perfectly and quietly produce a wrong Balance Sheet a month
 * later, so the server refuses it with a 422. Filtering the dropdown means the
 * user never reaches that error in the first place.
 */
export default function FinanceSettingsPage() {
  const { can }      = usePermissions()
  const queryClient  = useQueryClient()
  const canManage    = can('manage_finance_settings')

  // null = the global mapping every company shares. A company id shows that
  // company's overrides layered on top, which is how AccountResolver reads it.
  const [companyId, setCompanyId] = useState('')

  // rule_key => ledger_account_id (string) | '' for unmapped. Only rows the
  // user actually touched, so an untouched role is never rewritten.
  const [draft, setDraft] = useState({})

  const settingsKey = ['finance-settings', companyId || 'global']

  const { data: settings, isLoading } = useQuery({
    queryKey: settingsKey,
    queryFn:  () => getFinanceSettings(companyId || undefined),
  })

  const { data: accounts = EMPTY_ARRAY } = useQuery({
    queryKey:  ['ledger-accounts-all-active'],
    queryFn:   () => getAllLedgerAccounts(),
    staleTime: 5 * 60 * 1000,
  })

  const { data: companies = EMPTY_ARRAY } = useQuery({
    queryKey:  ['companies-all'],
    queryFn:   getAllCompanies,
    staleTime: 5 * 60 * 1000,
  })

  const rows    = settings?.mappings ?? EMPTY_ARRAY
  const missing = settings?.missing_required ?? EMPTY_ARRAY
  const modes   = settings?.posting_modes ?? EMPTY_ARRAY

  const mappingMutation = useMutation({
    mutationFn: (payload) => updateAccountMappings(payload, companyId || null),
    onSuccess:  () => {
      setDraft({})
      queryClient.invalidateQueries({ queryKey: settingsKey })
      showSuccess('Account mapping saved.')
    },
    onError: (e) => {
      const errors = e?.response?.data?.errors
      const first  = errors ? Object.values(errors)[0]?.[0] : null

      showError(first ?? e?.response?.data?.message ?? 'Could not save the mapping.')
    },
  })

  const partyModeMutation = useMutation({
    mutationFn: ({ partyType, mode }) => updatePartyLedgerMode(partyType, mode),
    onSuccess:  () => {
      queryClient.invalidateQueries({ queryKey: settingsKey })
      // The chart changes shape, so every account picker is now stale.
      queryClient.invalidateQueries({ queryKey: ['ledger-accounts-all-active'] })
      showSuccess('Party balance setting updated.')
    },
    onError: (e) => showError(
      e?.response?.data?.errors?.mode?.[0]
      ?? e?.response?.data?.message
      ?? 'Could not change that setting.',
    ),
  })

  const modeMutation = useMutation({
    mutationFn: updatePostingMode,
    onSuccess:  () => {
      queryClient.invalidateQueries({ queryKey: settingsKey })
      showSuccess('Posting mode updated.')
    },
    onError: (e) => showError(e?.response?.data?.message ?? 'Could not change the posting mode.'),
  })

  /*
   * One option list per account type, built once per render.
   *
   * Not wrapped in useMemo on purpose: it is a small map over a cached list,
   * and a manual memo here blocks the React Compiler from optimizing the
   * component because it cannot prove the query result is stable.
   */
  const optionsByType = (() => {
    const byType = {}

    for (const a of accounts) {
      if (!a.account_type) continue

      byType[a.account_type] ??= []
      byType[a.account_type].push({
        value: String(a.id),
        label: `${a.code} · ${a.ledger_account_name}`,
        group: a.account_category_name ?? undefined,
      })
    }

    return byType
  })()

  const optionsFor = (row) =>
    (row.accepted_types ?? []).flatMap((t) => optionsByType[t.value] ?? [])

  const valueFor = (row) =>
    draft[row.rule_key] ?? (row.ledger_account_id ? String(row.ledger_account_id) : '')

  const setRow = (ruleKey, value) =>
    setDraft((d) => ({ ...d, [ruleKey]: value }))

  const dirtyKeys = Object.keys(draft).filter((k) => {
    const row = rows.find((r) => r.rule_key === k)
    const was = row?.ledger_account_id ? String(row.ledger_account_id) : ''

    return draft[k] !== was
  })

  const save = async () => {
    if (dirtyKeys.length === 0) return

    // Repointing a role that already has posted lines does NOT move them. The
    // old entries stay where they are and only future postings follow the new
    // account, which leaves the balance split across two accounts until
    // somebody transfers it. Worth a warning rather than a surprise.
    const inUse = dirtyKeys
      .map((k) => rows.find((r) => r.rule_key === k))
      .filter((r) => (r?.journal_lines_using ?? 0) > 0)

    if (inUse.length > 0) {
      const list = inUse
        .map((r) => `<li><strong>${r.label}</strong> — ${r.journal_lines_using} posted line(s)</li>`)
        .join('')

      const ok = await confirmAction({
        title:   'Repoint an account already in use?',
        message: `<ul class="text-left">${list}</ul>`
          + '<p class="mt-2 text-left">Existing entries stay on the old account — they are never '
          + 'rewritten. Only future postings use the new one, so the balance will sit across both '
          + 'until you move it with a journal voucher.</p>',
        confirmText: 'Yes, Repoint',
      })

      if (!ok) return
    }

    const payload = {}

    for (const k of dirtyKeys) {
      payload[k] = draft[k] === '' ? null : Number(draft[k])
    }

    mappingMutation.mutate(payload)
  }

  const changePartyMode = async (partyType, mode) => {
    if (mode === partyModes[partyType]) return

    const noun   = partyType === 'supplier' ? 'supplier' : 'customer'
    const chosen = partyOptions.find((o) => o.value === mode)

    const ok = await confirmAction({
      title:   `Hold ${noun} balances in ${mode === 'per_party' ? 'separate accounts' : 'one account'}?`,
      message: `${chosen?.description ?? ''}`
        + '<p class="mt-2 text-left"><strong>Entries already posted do not move.</strong> '
        + 'They stay on the account they were posted to, because a posted entry is never '
        + 'rewritten. Ageing reports are unaffected either way, since every line records the '
        + `${noun}. Only that ${noun}'s own ledger will show a partial history until you move `
        + 'the balance with a journal voucher.</p>',
      confirmText: 'Yes, Change It',
    })

    if (ok) partyModeMutation.mutate({ partyType, mode })
  }

  const changeMode = async (mode) => {
    if (mode === settings?.posting_mode) return

    const chosen = modes.find((m) => m.value === mode)

    const ok = await confirmAction({
      title:       `Switch to “${chosen?.label ?? mode}”?`,
      message:     chosen?.description ?? '',
      confirmText: 'Yes, Switch',
    })

    if (ok) modeMutation.mutate(mode)
  }

  const partyModes   = settings?.party_ledger_modes ?? { supplier: 'shared', customer: 'shared' }
  const partyOptions = settings?.party_ledger_options ?? EMPTY_ARRAY

  const ready = missing.length === 0

  return (
    <div className="w-full">
      <Breadcrumb crumbs={CRUMBS} />

      <div className="mb-2 flex items-center justify-between gap-2">
        <div className="flex items-center gap-1.5">
          <Settings2 size={16} className="text-slate-500" strokeWidth={2} />
          <h1 className="text-xl font-bold text-slate-900">Account Mapping</h1>
        </div>

        {canManage && (
          <button
            type="button"
            onClick={save}
            disabled={dirtyKeys.length === 0 || mappingMutation.isPending}
            className="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-40"
          >
            <Save size={13} strokeWidth={2.5} />
            {dirtyKeys.length === 0 ? 'Save' : `Save ${dirtyKeys.length} change${dirtyKeys.length > 1 ? 's' : ''}`}
          </button>
        )}
      </div>

      {/* The readiness verdict, stated plainly. An incomplete setup must be
          visible here rather than discovered when a document refuses to save. */}
      {!isLoading && (
        <div
          className={`mb-2 flex items-start gap-2 rounded-lg border px-3 py-2 text-xs ${
            ready
              ? 'border-green-200 bg-green-50 text-green-800'
              : 'border-red-200 bg-red-50 text-red-800'
          }`}
        >
          {ready
            ? <Check size={14} className="mt-0.5 shrink-0" strokeWidth={2.5} />
            : <ShieldAlert size={14} className="mt-0.5 shrink-0" strokeWidth={2.5} />}

          <div>
            {ready ? (
              <>
                <span className="font-semibold">Ready to post.</span>{' '}
                Every required role resolves to an active account.
              </>
            ) : (
              <>
                <span className="font-semibold">
                  {missing.length} required role{missing.length > 1 ? 's' : ''} not mapped.
                </span>{' '}
                A document that needs {missing.length > 1 ? 'one of these' : 'this'} will refuse to
                confirm until it is set:{' '}
                <span className="font-semibold">{missing.map((m) => m.label).join(', ')}</span>
              </>
            )}
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">
        {/* ── Mapping ─────────────────────────────────────────────────────── */}
        <div className="lg:col-span-2 rounded-lg border border-slate-200 bg-white">
          <div className="flex items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2">
            <div className="flex items-center gap-1.5">
              <Link2 size={14} className="text-slate-500" strokeWidth={2} />
              <h2 className="text-sm font-bold text-slate-800">Posting Roles</h2>
            </div>

            <div className="flex items-center gap-1.5">
              <label className="text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                Scope
              </label>
              <select
                className={FILTER_SELECT_CLS}
                value={companyId}
                onChange={(e) => { setCompanyId(e.target.value); setDraft({}) }}
              >
                <option value="">All companies (global)</option>
                {companies.map((c) => (
                  <option key={c.id} value={c.id}>{c.company_name}</option>
                ))}
              </select>
            </div>
          </div>

          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead className="sticky top-0 bg-slate-100 text-left text-[11px] uppercase tracking-wider text-slate-500">
                <tr>
                  <th className="px-3 py-2 font-semibold">Role</th>
                  <th className="px-3 py-2 font-semibold">Ledger Account</th>
                  <th className="px-3 py-2 text-right font-semibold">In Use</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {isLoading && (
                  <tr><td colSpan={3} className="px-3 py-6 text-center text-slate-400">Loading…</td></tr>
                )}

                {!isLoading && rows.map((row) => {
                  const options = optionsFor(row)
                  const value   = valueFor(row)
                  const dirty   = dirtyKeys.includes(row.rule_key)

                  // Mapped but the account has since been deactivated: it
                  // resolves and then fails assertPostable(), so it must read
                  // as broken rather than as set.
                  const inactive = row.is_mapped && !row.is_active

                  return (
                    <tr key={row.rule_key} className={dirty ? 'bg-amber-50' : 'hover:bg-slate-50'}>
                      <td className="px-3 py-1.5 align-top">
                        <div className="flex items-center gap-1.5">
                          <span className="font-semibold text-slate-800">{row.label}</span>
                          {row.required && (
                            <span className="rounded bg-red-100 px-1 py-0.5 text-[9px] font-bold uppercase text-red-700">
                              Required
                            </span>
                          )}
                        </div>
                        <p className="mt-0.5 max-w-md text-[10px] leading-snug text-slate-400">
                          {row.hint}
                        </p>
                      </td>

                      <td className="px-3 py-1.5 align-top">
                        <FilterSearchSelect
                          size="form"
                          wide
                          disabled={!canManage}
                          invalid={(row.required && value === '') || inactive}
                          placeholder={options.length === 0 ? 'No suitable account exists' : 'Not mapped'}
                          value={value}
                          onChange={(v) => setRow(row.rule_key, v ?? '')}
                          options={options}
                        />

                        <p className="mt-0.5 text-[10px] text-slate-400">
                          Accepts {(row.accepted_types ?? []).map((t) => t.label).join(' or ')}
                        </p>

                        {inactive && (
                          <p className="mt-0.5 flex items-center gap-1 text-[10px] font-semibold text-red-600">
                            <AlertTriangle size={10} strokeWidth={2.5} />
                            That account is inactive — postings will be refused
                          </p>
                        )}
                      </td>

                      <td className="whitespace-nowrap px-3 py-1.5 text-right align-top text-slate-500">
                        {row.journal_lines_using > 0
                          ? <span title="Posted journal lines already using this account">{row.journal_lines_using}</span>
                          : <span className="text-slate-300">—</span>}
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>

        {/* ── Posting mode + explanation ──────────────────────────────────── */}
        <div className="flex flex-col gap-2">
          <div className="rounded-lg border border-slate-200 bg-white">
            <div className="flex items-center gap-1.5 border-b border-slate-200 bg-slate-50 px-3 py-2">
              <Zap size={14} className="text-slate-500" strokeWidth={2} />
              <h2 className="text-sm font-bold text-slate-800">Posting Mode</h2>
            </div>

            <div className="space-y-1.5 p-2">
              {modes.map((m) => {
                const active = settings?.posting_mode === m.value

                return (
                  <button
                    key={m.value}
                    type="button"
                    disabled={!canManage || modeMutation.isPending}
                    onClick={() => changeMode(m.value)}
                    className={`block w-full rounded-lg border px-2.5 py-2 text-left transition-colors disabled:cursor-not-allowed ${
                      active
                        ? 'border-slate-900 bg-slate-900 text-white'
                        : 'border-slate-200 bg-white hover:border-slate-400 disabled:opacity-60'
                    }`}
                  >
                    <div className="flex items-center gap-1.5">
                      <span className={`flex h-3 w-3 shrink-0 items-center justify-center rounded-full border ${
                        active ? 'border-white' : 'border-slate-400'
                      }`}
                      >
                        {active && <span className="h-1.5 w-1.5 rounded-full bg-white" />}
                      </span>
                      <span className="text-xs font-bold">{m.label}</span>
                    </div>
                    <p className={`mt-1 text-[10px] leading-snug ${active ? 'text-slate-300' : 'text-slate-500'}`}>
                      {m.description}
                    </p>
                  </button>
                )
              })}
            </div>
          </div>

          {/* ── Party balances ──────────────────────────────────────────── */}
          <div className="rounded-lg border border-slate-200 bg-white">
            <div className="flex items-center gap-1.5 border-b border-slate-200 bg-slate-50 px-3 py-2">
              <Users size={14} className="text-slate-500" strokeWidth={2} />
              <h2 className="text-sm font-bold text-slate-800">Party Balances</h2>
            </div>

            <div className="space-y-2 p-2">
              {[
                { key: 'supplier', label: 'Suppliers', role: 'Accounts Payable' },
                { key: 'customer', label: 'Customers', role: 'Accounts Receivable' },
              ].map(({ key, label, role }) => (
                <div key={key}>
                  <div className="mb-1 flex items-baseline justify-between gap-2">
                    <span className="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                      {label}
                    </span>
                    <span className="text-[10px] text-slate-400">under {role}</span>
                  </div>

                  <div className="grid grid-cols-2 gap-1">
                    {partyOptions.map((o) => {
                      const active = partyModes[key] === o.value

                      return (
                        <button
                          key={o.value}
                          type="button"
                          title={o.description}
                          disabled={!canManage || partyModeMutation.isPending}
                          onClick={() => changePartyMode(key, o.value)}
                          className={`rounded-lg border px-2 py-1.5 text-[11px] font-semibold transition-colors disabled:cursor-not-allowed ${
                            active
                              ? 'border-slate-900 bg-slate-900 text-white'
                              : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400 disabled:opacity-60'
                          }`}
                        >
                          {o.label}
                        </button>
                      )
                    })}
                  </div>
                </div>
              ))}

              <p className="border-t border-slate-100 pt-1.5 text-[10px] leading-snug text-slate-500">
                <span className="font-semibold text-slate-700">One account per party</span> creates
                a ledger for each supplier or customer the first time they are posted to — Tally&rsquo;s
                shape. They are hidden from account dropdowns, because documents pick them
                automatically.
              </p>
            </div>
          </div>

          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] leading-relaxed text-slate-600">
            <h3 className="mb-1 text-xs font-bold text-slate-800">How this works</h3>
            <p className="mb-1.5">
              Documents never name an account. A payment asks for{' '}
              <code className="rounded bg-white px-1 text-[10px]">accounts_payable</code> and this
              screen decides which account that is — which is what lets the mapping change without
              touching any code.
            </p>
            <p className="mb-1.5">
              <span className="font-semibold">A missing mapping stops the document.</span> It never
              falls back to a guessed account, because a payment posted to the wrong account costs
              far more to unpick than one that refused to save.
            </p>
            <p>
              <span className="font-semibold">Changing a mapping is not retrospective.</span> Posted
              entries stay on the old account. Only future postings follow the new one.
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
