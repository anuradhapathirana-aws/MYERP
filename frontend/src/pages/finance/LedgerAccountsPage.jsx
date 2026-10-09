import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BookOpen, Edit2, Save, Search, X } from 'lucide-react'
import {
  createLedgerAccount, deleteLedgerAccount, getCashBookTypes, getLedgerAccount,
  getLedgerAccounts, getNextLedgerAccountCode, updateLedgerAccount,
} from '../../api/ledgerAccounts'
import { getAccountTypes, getAllAccountCategories } from '../../api/accountCategories'
import { getAllControlAccounts } from '../../api/controlAccounts'
import { getAllBanks } from '../../api/banks'
import { getAllBankBranches } from '../../api/bankBranches'
import { getAllCompanies } from '../../api/companies'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'
import {
  FILTER_INPUT_CLS, FILTER_SELECT_CLS, INPUT_CLS, INPUT_DISABLED_CLS, INPUT_ERR_CLS,
  LABEL_CLS, SELECT_CLS, SELECT_ERR_CLS, TEXTAREA_CLS,
} from '../../utils/fieldStyles'

const CRUMBS = [{ label: 'Finance' }, { label: 'Ledger Accounts' }]

const EMPTY_FORM = {
  account_type: '', account_category_id: '', control_account_id: '',
  ledger_account_name: '', description: '', is_active: true,
  // '' means "ordinary account" — no cash/bank behaviour at all.
  cash_book_type: '', company_id: '',
  allows_cash: false, allows_cheque: false,
  bank_id: '', bank_branch_id: '', bank_account_no: '',
}

const ERR_CLS = 'mt-0.5 text-[10px] text-red-500'

const TYPE_BADGE = {
  asset:     'bg-sky-50 text-sky-700',
  liability: 'bg-violet-50 text-violet-700',
  equity:    'bg-indigo-50 text-indigo-700',
  income:    'bg-emerald-50 text-emerald-700',
  expense:   'bg-amber-50 text-amber-700',
}

const CASH_BADGE = {
  cash_book:       'bg-teal-50 text-teal-700',
  petty_cash_book: 'bg-orange-50 text-orange-700',
  bank:            'bg-blue-50 text-blue-700',
}

const ACCOUNT_NO_RE = /^[A-Za-z0-9 -]+$/

function validate(field, value, form) {
  if (field === 'control_account_id' && !String(value)) return 'Control account is required.'
  if (field === 'ledger_account_name') {
    const v = String(value).trim()
    if (!v) return 'Ledger account is required.'
    if (v.length > 100) return 'Max 100 characters.'
  }
  if (field === 'description' && String(value).length > 1000) return 'Max 1000 characters.'

  // The cash/bank block is conditional — each rule applies only to its branch.
  if (field === 'company_id' && form?.cash_book_type && !String(value)) {
    return 'Select the company that owns this account.'
  }
  if (form?.cash_book_type === 'bank') {
    if (field === 'bank_id' && !String(value)) return 'Bank is required.'
    if (field === 'bank_branch_id' && !String(value)) return 'Branch is required.'
    if (field === 'bank_account_no') {
      const v = String(value).trim()
      if (!v) return 'Account number is required.'
      if (v.length > 50) return 'Max 50 characters.'
      if (!ACCOUNT_NO_RE.test(v)) return 'Letters, numbers, spaces and hyphens only.'
    }
  }
  return ''
}

function LedgerAccountForm({ editId, accountTypes, cashBookTypes, categories, controls, banks, companies, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const typeRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['ledger-account', editId],
    queryFn:  () => getLedgerAccount(editId),
    enabled:  isEditing,
  })

  const { data: previewCode = '' } = useQuery({
    queryKey: ['ledger-account-next-code', form.control_account_id],
    queryFn:  () => getNextLedgerAccountCode(form.control_account_id),
    enabled:  !isEditing && Boolean(form.control_account_id),
  })

  // Branches are fetched per bank so the dropdown can never offer a branch of
  // a different bank — which the server rejects anyway.
  const { data: branches = [] } = useQuery({
    queryKey: ['bank-branches-all', form.bank_id],
    queryFn:  () => getAllBankBranches(form.bank_id),
    enabled:  Boolean(form.bank_id),
  })

  // Account Type and Account Category narrow the Control Account list. Neither
  // is stored — both are derived from the control account on the server.
  const visibleCategories = useMemo(
    () => (form.account_type ? categories.filter((c) => c.account_type === form.account_type) : categories),
    [categories, form.account_type],
  )

  const visibleControls = useMemo(() => {
    let list = controls
    if (form.account_type) list = list.filter((c) => c.account_type === form.account_type)
    if (form.account_category_id) {
      list = list.filter((c) => String(c.account_category_id) === String(form.account_category_id))
    }
    return list
  }, [controls, form.account_type, form.account_category_id])

  // ── Option lists for the searchable pads ─────────────────────────────────
  const categoryOptions = useMemo(
    () => visibleCategories.map((c) => ({ value: String(c.id), label: `${c.code} · ${c.category_name}` })),
    [visibleCategories],
  )

  // `group` makes the pad render each category as a header with its control
  // accounts nested beneath — the chart of accounts as a shallow tree.
  const controlOptions = useMemo(
    () => visibleControls.map((c) => ({
      value: String(c.id),
      label: `${c.code} · ${c.control_account_name}`,
      group: c.account_category_name ?? undefined,
    })),
    [visibleControls],
  )

  const bankOptions = useMemo(
    () => banks.map((b) => ({ value: String(b.id), label: b.bank_name })),
    [banks],
  )

  const branchOptions = useMemo(
    () => branches.map((b) => ({ value: String(b.id), label: `${b.branch_code} · ${b.branch_name}` })),
    [branches],
  )

  const selectedBranch = useMemo(
    () => branches.find((b) => String(b.id) === String(form.bank_branch_id)) ?? null,
    [branches, form.bank_branch_id],
  )
  const selectedBank = useMemo(
    () => banks.find((b) => String(b.id) === String(form.bank_id)) ?? null,
    [banks, form.bank_id],
  )

  const initialized = useRef(false)
  useLayoutEffect(() => {
    initialized.current = false
    setForm(EMPTY_FORM)
    setErrors({})
    setTouched({})
  }, [editId])

  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const l = fetchedData.data
      setForm({
        account_type:        l.account_type ?? '',
        account_category_id: l.account_category_id ? String(l.account_category_id) : '',
        control_account_id:  l.control_account_id ? String(l.control_account_id) : '',
        ledger_account_name: l.ledger_account_name ?? '',
        description:         l.description ?? '',
        is_active:           l.is_active ?? true,
        cash_book_type:      l.cash_book_type ?? '',
        company_id:          l.company_id ? String(l.company_id) : '',
        allows_cash:         l.allows_cash ?? false,
        allows_cheque:       l.allows_cheque ?? false,
        bank_id:             l.bank_id ? String(l.bank_id) : '',
        bank_branch_id:      l.bank_branch_id ? String(l.bank_branch_id) : '',
        bank_account_no:     l.bank_account_no ?? '',
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) typeRef.current?.focus()
  }, [isFetching, editId])

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target
    const newVal = type === 'checkbox' ? checked : value
    setForm((prev) => {
      const next = { ...prev, [name]: newVal }
      // Clear downstream selections that the new value invalidates.
      if (name === 'account_type')        { next.account_category_id = ''; next.control_account_id = '' }
      if (name === 'account_category_id') { next.control_account_id = '' }
      if (name === 'bank_id')             { next.bank_branch_id = '' }
      if (name === 'cash_book_type') {
        if (newVal !== 'cash_book') { next.allows_cash = false; next.allows_cheque = false }
        if (newVal !== 'bank')      { next.bank_id = ''; next.bank_branch_id = ''; next.bank_account_no = '' }
        if (!newVal)                { next.company_id = '' }
      }
      return next
    })
    if (touched[name]) {
      setErrors((prev) => ({ ...prev, [name]: validate(name, newVal, { ...form, [name]: newVal }) }))
    }
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value, form) }))
  }

  /**
   * FilterSearchSelect reports a bare value rather than an event, so this
   * routes it through handleChange to reuse the cascade that clears downstream
   * selections, then marks the field touched and validates it — the pad has no
   * blur of its own, and picking an option IS the interaction.
   */
  const handleSelect = (name, value) => {
    handleChange({ target: { name, value, type: 'select-one' } })
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value, { ...form, [name]: value }) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) => (isEditing ? updateLedgerAccount(editId, payload) : createLedgerAccount(payload)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ledger-accounts'] })
      queryClient.invalidateQueries({ queryKey: ['ledger-accounts-all'] })
      queryClient.invalidateQueries({ queryKey: ['control-accounts'] })
      queryClient.invalidateQueries({ queryKey: ['ledger-account-next-code'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['ledger-account', editId] })
      showSuccess(isEditing ? 'Ledger account updated.' : 'Ledger account created.')
      onDone()
    },
    onError: (err) => {
      const apiErrors = err.response?.data?.errors ?? {}
      if (Object.keys(apiErrors).length) {
        setErrors(Object.fromEntries(Object.entries(apiErrors).map(([k, v]) => [k, v[0]])))
        setTouched(Object.fromEntries(Object.keys(apiErrors).map((k) => [k, true])))
      }
      showError(err.response?.data?.message ?? 'Failed to save. Please check the form and try again.')
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    const fields = ['control_account_id', 'ledger_account_name', 'description', 'company_id',
      'bank_id', 'bank_branch_id', 'bank_account_no']
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f], form)]))

    // Mirrors the server's cross-field rule: a cash book that takes neither
    // cash nor cheques cannot receive money.
    if (form.cash_book_type === 'cash_book' && !form.allows_cash && !form.allows_cheque) {
      newErrors.allows_cash = 'A cash book must accept cash, cheques, or both.'
    }

    setErrors(newErrors)
    setTouched(Object.fromEntries([...fields, 'allows_cash'].map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return

    const isCashBook = form.cash_book_type === 'cash_book'
    const isBank     = form.cash_book_type === 'bank'

    mutation.mutate({
      control_account_id:  parseInt(form.control_account_id, 10),
      ledger_account_name: form.ledger_account_name.trim(),
      description:         form.description.trim() || null,
      is_active:           form.is_active,
      cash_book_type:      form.cash_book_type || null,
      company_id:          form.cash_book_type ? parseInt(form.company_id, 10) : null,
      allows_cash:         isCashBook && form.allows_cash,
      allows_cheque:       isCashBook && form.allows_cheque,
      bank_id:             isBank ? parseInt(form.bank_id, 10) : null,
      bank_branch_id:      isBank ? parseInt(form.bank_branch_id, 10) : null,
      bank_account_no:     isBank ? (form.bank_account_no.trim() || null) : null,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
  }

  const codeValue  = isEditing ? (fetchedData?.data?.code ?? '') : previewCode
  const isCashBook = form.cash_book_type === 'cash_book'
  const isBank     = form.cash_book_type === 'bank'

  const radio = (value, label) => (
    <label key={value || 'none'} className="flex cursor-pointer items-center gap-1.5">
      <input
        type="radio"
        name="cash_book_type"
        value={value}
        checked={form.cash_book_type === value}
        onChange={handleChange}
        className="h-3.5 w-3.5 cursor-pointer accent-indigo-600"
      />
      <span className="text-xs font-medium text-slate-700">{label}</span>
    </label>
  )

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">
      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
          <label className={LABEL_CLS}>Account Type</label>
          {/* Five fixed enum values — a plain select is faster to use than a
              search pad, so this one stays as it is. */}
          <select ref={typeRef} name="account_type" value={form.account_type} onChange={handleChange} disabled={isEditing} className={SELECT_CLS}>
            <option value="">All types</option>
            {accountTypes.map((t) => <option key={t.value} value={t.value}>{t.digit} · {t.label}</option>)}
          </select>
        </div>
        <div>
          <label className={LABEL_CLS}>Account Category</label>
          <FilterSearchSelect
            size="form"
            value={form.account_category_id}
            onChange={(v) => handleSelect('account_category_id', v)}
            options={categoryOptions}
            placeholder="All categories"
            disabled={isEditing}
          />
        </div>
      </div>
      <p className="-mt-1 text-[10px] text-slate-400">
        Both narrow the Control Account list. Neither is stored — the type and category come from the control account.
      </p>

      <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <div>
          <label className={LABEL_CLS}>Control Account <span className="text-red-500">*</span></label>
          {/* Searchable: a real chart runs to hundreds of control accounts, and
              the pad groups them under their category. */}
          <FilterSearchSelect
            size="form"
            value={form.control_account_id}
            onChange={(v) => handleSelect('control_account_id', v)}
            options={controlOptions}
            placeholder="— Select —"
            // Locked after creation: its code is the first five digits of this one.
            disabled={isEditing}
            invalid={Boolean(errors.control_account_id && touched.control_account_id)}
            clearable={false}
            wide
          />
          {errors.control_account_id && touched.control_account_id && <p className={ERR_CLS}>{errors.control_account_id}</p>}
          {!isEditing && visibleControls.length === 0 && (
            <p className="mt-0.5 text-[10px] text-amber-600">No control accounts match this filter.</p>
          )}
        </div>

        <div>
          <label className={LABEL_CLS}>Code</label>
          <input type="text" value={codeValue} readOnly tabIndex={-1} placeholder="Automatically generated" className={`${INPUT_DISABLED_CLS} font-mono`} />
          <p className="mt-0.5 text-[10px] text-slate-400">
            {isEditing ? 'Immutable once assigned.' : 'Control code + sequence.'}
          </p>
        </div>
      </div>

      <div>
        <label className={LABEL_CLS}>Ledger Account <span className="text-red-500">*</span></label>
        <input
          name="ledger_account_name"
          type="text"
          value={form.ledger_account_name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Trade Debtors - Local"
          maxLength={100}
          autoComplete="off"
          className={errors.ledger_account_name && touched.ledger_account_name ? INPUT_ERR_CLS : INPUT_CLS}
        />
        {errors.ledger_account_name && touched.ledger_account_name && <p className={ERR_CLS}>{errors.ledger_account_name}</p>}
      </div>

      <div>
        <label className={LABEL_CLS}>Description</label>
        <textarea
          name="description" rows={2} value={form.description} onChange={handleChange} onBlur={handleBlur}
          placeholder="Optional notes" maxLength={1000} className={TEXTAREA_CLS}
        />
        {errors.description && touched.description && <p className={ERR_CLS}>{errors.description}</p>}
      </div>

      {/* ── Cash / bank classification ───────────────────────────────────────
          Null for almost every account. Only the handful money actually moves
          through carry a value, and payment screens filter on it. */}
      <div className="rounded-lg border-2 border-slate-200 bg-slate-50/60 p-2">
        <p className="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-slate-500">Selection of Cash</p>

        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
          {radio('', 'None')}
          {cashBookTypes.map((t) => radio(t.value, t.label))}
        </div>

        {isCashBook && (
          <div className="mt-1.5 flex items-center gap-4 rounded border border-slate-200 bg-white px-2 py-1.5">
            <label className="flex cursor-pointer items-center gap-1.5">
              <input type="checkbox" name="allows_cash" checked={form.allows_cash} onChange={handleChange} className="h-3.5 w-3.5 cursor-pointer accent-indigo-600" />
              <span className="text-xs font-medium text-slate-700">Cash</span>
            </label>
            <label className="flex cursor-pointer items-center gap-1.5">
              <input type="checkbox" name="allows_cheque" checked={form.allows_cheque} onChange={handleChange} className="h-3.5 w-3.5 cursor-pointer accent-indigo-600" />
              <span className="text-xs font-medium text-slate-700">Cheque</span>
            </label>
          </div>
        )}
        {errors.allows_cash && touched.allows_cash && <p className={ERR_CLS}>{errors.allows_cash}</p>}

        {form.cash_book_type && (
          <div className="mt-1.5">
            <label className={LABEL_CLS}>Company <span className="text-red-500">*</span></label>
            <select
              name="company_id"
              value={form.company_id}
              onChange={handleChange}
              onBlur={handleBlur}
              className={errors.company_id && touched.company_id ? SELECT_ERR_CLS : SELECT_CLS}
            >
              <option value="">— Select —</option>
              {companies.map((c) => <option key={c.id} value={c.id}>{c.company_name}</option>)}
            </select>
            {errors.company_id && touched.company_id && <p className={ERR_CLS}>{errors.company_id}</p>}
            <p className="mt-0.5 text-[10px] text-slate-400">
              Cash and bank accounts belong to one legal entity. Ordinary accounts are shared by all companies.
            </p>
          </div>
        )}

        {isBank && (
          <div className="mt-1.5 flex flex-col gap-1.5 rounded border border-slate-200 bg-white p-2">
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div>
                <label className={LABEL_CLS}>Bank <span className="text-red-500">*</span></label>
                <FilterSearchSelect
                  size="form"
                  value={form.bank_id}
                  onChange={(v) => handleSelect('bank_id', v)}
                  options={bankOptions}
                  placeholder="— Select —"
                  invalid={Boolean(errors.bank_id && touched.bank_id)}
                  clearable={false}
                />
                {errors.bank_id && touched.bank_id && <p className={ERR_CLS}>{errors.bank_id}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Branch <span className="text-red-500">*</span></label>
                <FilterSearchSelect
                  size="form"
                  value={form.bank_branch_id}
                  onChange={(v) => handleSelect('bank_branch_id', v)}
                  options={branchOptions}
                  placeholder={form.bank_id ? '— Select —' : 'Select a bank first'}
                  disabled={!form.bank_id}
                  invalid={Boolean(errors.bank_branch_id && touched.bank_branch_id)}
                  clearable={false}
                />
                {errors.bank_branch_id && touched.bank_branch_id && <p className={ERR_CLS}>{errors.bank_branch_id}</p>}
                {form.bank_id && branches.length === 0 && (
                  <p className="mt-0.5 text-[10px] text-amber-600">This bank has no branches yet.</p>
                )}
              </div>
            </div>

            {/* Read live from the bank masters — never stored on this record,
                so a corrected branch code shows through immediately. */}
            {(selectedBank || selectedBranch) && (
              <div className="flex flex-wrap gap-x-4 gap-y-0.5 rounded bg-slate-50 px-2 py-1 text-[10px] text-slate-500">
                {selectedBank?.bank_code && <span>Bank Code <strong className="font-mono text-slate-700">{selectedBank.bank_code}</strong></span>}
                {selectedBranch?.branch_code && <span>Branch Code <strong className="font-mono text-slate-700">{selectedBranch.branch_code}</strong></span>}
                {selectedBranch?.swift_code && <span>SWIFT <strong className="font-mono text-slate-700">{selectedBranch.swift_code}</strong></span>}
              </div>
            )}

            <div>
              <label className={LABEL_CLS}>Account Number <span className="text-red-500">*</span></label>
              <input
                name="bank_account_no" type="text" value={form.bank_account_no}
                onChange={handleChange} onBlur={handleBlur}
                placeholder="e.g. 0012 3456 7890" maxLength={50} autoComplete="off"
                className={errors.bank_account_no && touched.bank_account_no ? INPUT_ERR_CLS : INPUT_CLS}
              />
              {errors.bank_account_no && touched.bank_account_no && <p className={ERR_CLS}>{errors.bank_account_no}</p>}
            </div>
          </div>
        )}
      </div>

      <div className="rounded border border-slate-100 bg-slate-50 p-2">
        <label className="flex cursor-pointer items-center gap-3">
          <div className="relative">
            <input type="checkbox" name="is_active" checked={form.is_active} onChange={handleChange} className="sr-only peer" />
            <div className="h-5 w-9 rounded-full bg-slate-200 transition-colors peer-checked:bg-indigo-600" />
            <div className="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
          </div>
          <span className="text-xs font-medium text-slate-700">{form.is_active ? 'Active' : 'Inactive'}</span>
        </label>
        <p className="mt-0.5 text-[10px] text-slate-400">Ledger accounts are the only level journal entries can post to.</p>
      </div>

      {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
        <p className={ERR_CLS}>{mutation.error?.response?.data?.message ?? 'An unexpected error occurred.'}</p>
      )}

      <div className="flex items-center justify-end gap-2 pt-0.5">
        {isEditing && (
          <button type="button" onClick={onCancel} className="flex items-center gap-1 rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-200">
            <X size={11} /> Cancel
          </button>
        )}
        <button
          type="submit"
          disabled={mutation.isPending}
          className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Save size={12} strokeWidth={2.5} />
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Ledger Account'}
        </button>
      </div>
    </form>
  )
}

export default function LedgerAccountsPage() {
  const [page,       setPage]       = useState(1)
  const [editId,     setEditId]     = useState(null)
  const [search,     setSearch]     = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const [cashFilter, setCashFilter] = useState('')
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data: accountTypes = [] }  = useQuery({ queryKey: ['account-types'],    queryFn: getAccountTypes,  staleTime: Infinity })
  const { data: cashBookTypes = [] } = useQuery({ queryKey: ['cash-book-types'],  queryFn: getCashBookTypes, staleTime: Infinity })
  const { data: categories = [] }    = useQuery({ queryKey: ['account-categories-all'], queryFn: () => getAllAccountCategories() })
  const { data: controls = [] }      = useQuery({ queryKey: ['control-accounts-all'],   queryFn: () => getAllControlAccounts() })
  const { data: banks = [] }         = useQuery({ queryKey: ['banks-all'],              queryFn: getAllBanks })
  const { data: companies = [] }     = useQuery({ queryKey: ['companies-all'],          queryFn: getAllCompanies })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['ledger-accounts', page, search, typeFilter, cashFilter],
    queryFn:  () => getLedgerAccounts(page, {
      ...(search ? { search } : {}),
      ...(typeFilter ? { account_type: typeFilter } : {}),
      ...(cashFilter ? { cash_book_type: cashFilter } : {}),
    }),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteLedgerAccount,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ledger-accounts'] })
      queryClient.invalidateQueries({ queryKey: ['control-accounts'] })
      showSuccess('Ledger account deleted.')
    },
    onError: (err) =>
      showError(
        err.response?.data?.errors?.id?.[0]
        ?? err.response?.data?.message
        ?? 'Failed to delete. The ledger account may be in use.',
      ),
  })

  const handleDelete = async (id, name) => {
    const ok = await confirmDelete(name)
    if (ok) {
      if (editId === id) setEditId(null)
      deleteMutation.mutate(id)
    }
  }

  const meta = data?.meta
  const rows = data?.data ?? []
  const isEditMode = editId !== null

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Ledger Account Master</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      {/* The form carries the cash/bank block, so it gets 2 of 5 columns
          rather than 1 of 3 — the list still keeps the majority of the width. */}
      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-5">
        <div className="lg:col-span-3 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-1.5">
            <div className="relative w-52">
              <Search size={12} className="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder="Search name or code…"
                className={`${FILTER_INPUT_CLS} pl-6`}
              />
            </div>
            <select value={typeFilter} onChange={(e) => { setTypeFilter(e.target.value); setPage(1) }} className={`${FILTER_SELECT_CLS} w-36`}>
              <option value="">All types</option>
              {accountTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
            <select value={cashFilter} onChange={(e) => { setCashFilter(e.target.value); setPage(1) }} className={`${FILTER_SELECT_CLS} w-40`}>
              <option value="">All accounts</option>
              {cashBookTypes.map((t) => <option key={t.value} value={t.value}>{t.label}</option>)}
            </select>
          </div>

          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load ledger accounts.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Category</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Control</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Ledger Account</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Cash / Bank</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="px-4 py-8 text-center text-sm text-slate-400">
                          No ledger accounts yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((row) => (
                        <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                          <td className="px-3 py-2 font-mono font-semibold text-slate-700">{row.code}</td>
                          <td className="px-3 py-2">
                            <span className={`inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold ${TYPE_BADGE[row.account_type] ?? 'bg-slate-100 text-slate-600'}`}>
                              {row.account_type_label}
                            </span>
                          </td>
                          <td className="max-w-[9rem] truncate px-3 py-2 text-slate-500" title={row.account_category_name ?? ''}>{row.account_category_name}</td>
                          <td className="max-w-36 truncate px-3 py-2 text-slate-500" title={row.control_account_name ?? ''}>{row.control_account_name}</td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.ledger_account_name}</td>
                          <td className="px-3 py-2">
                            {row.cash_book_type ? (
                              <div className="flex flex-col gap-0.5">
                                <span className={`inline-flex w-fit items-center rounded px-1.5 py-0.5 text-[10px] font-semibold ${CASH_BADGE[row.cash_book_type] ?? 'bg-slate-100 text-slate-600'}`}>
                                  {row.cash_book_type_label}
                                </span>
                                {row.cash_book_type === 'bank' && row.bank_name && (
                                  <span className="text-[10px] text-slate-400">{row.bank_name} · {row.bank_account_no}</span>
                                )}
                                {row.cash_book_type === 'cash_book' && (
                                  <span className="text-[10px] text-slate-400">
                                    {[row.allows_cash && 'Cash', row.allows_cheque && 'Cheque'].filter(Boolean).join(' + ')}
                                  </span>
                                )}
                                {row.company_name && <span className="text-[10px] text-slate-400">{row.company_name}</span>}
                              </div>
                            ) : (
                              <span className="italic text-slate-300">—</span>
                            )}
                          </td>
                          <td className="px-3 py-2">
                            {row.is_active ? (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                            ) : (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Inactive</span>
                            )}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              {can('edit_ledger_accounts') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(row.id)}
                                  className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_ledger_accounts') && (
                                <DeleteBtn onClick={() => handleDelete(row.id, `${row.code} — ${row.ledger_account_name}`)} disabled={deleteMutation.isPending} />
                              )}
                            </div>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

              <Pagination meta={meta} page={page} onPageChange={setPage} />
            </>
          )}
        </div>

        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm self-start">
          <div className="flex items-center justify-between gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <BookOpen size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Ledger Account' : 'Create Ledger Account'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_ledger_accounts') || can('edit_ledger_accounts') ? (
            <LedgerAccountForm
              key={editId ?? 'create'}
              editId={editId}
              accountTypes={accountTypes}
              cashBookTypes={cashBookTypes}
              categories={categories}
              controls={controls}
              banks={banks}
              companies={companies}
              onDone={() => setEditId(null)}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage ledger accounts.</div>
          )}
        </div>
      </div>
    </div>
  )
}
