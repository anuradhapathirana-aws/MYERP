import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, Edit2, Save, X } from 'lucide-react'
import {
  createBankBranch, deleteBankBranch, getBankBranch, getBankBranches, updateBankBranch,
} from '../../api/bankBranches'
import { getAllBanks } from '../../api/banks'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Master Data', to: '/master-data/banks' },
  { label: 'Bank Branches' },
]

const EMPTY_FORM = { bank_id: '', branch_name: '', branch_code: '', swift_code: '', address: '', is_active: true }

const CODE_RE  = /^[A-Za-z0-9_-]+$/
const SWIFT_RE = /^[A-Za-z0-9]+$/

function validate(field, value) {
  if (field === 'bank_id' && !String(value)) return 'Bank is required.'
  if (field === 'branch_name') {
    const v = String(value).trim()
    if (!v) return 'Branch name is required.'
    if (v.length > 100) return 'Max 100 characters.'
  }
  if (field === 'branch_code') {
    const v = String(value).trim()
    if (!v) return 'Branch code is required.'
    if (v.length > 30) return 'Max 30 characters.'
    if (!CODE_RE.test(v)) return 'Letters, numbers, hyphens and underscores only.'
  }
  if (field === 'swift_code') {
    const v = String(value).trim()
    if (v && !SWIFT_RE.test(v)) return 'Letters and numbers only.'
    if (v.length > 20) return 'Max 20 characters.'
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function BankBranchForm({ editId, banks, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const bankRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['bank-branch', editId],
    queryFn:  () => getBankBranch(editId),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    initialized.current = false
    setForm(EMPTY_FORM)
    setErrors({})
    setTouched({})
  }, [editId])

  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const b = fetchedData.data
      setForm({
        bank_id:     b.bank_id ? String(b.bank_id) : '',
        branch_name: b.branch_name ?? '',
        branch_code: b.branch_code ?? '',
        swift_code:  b.swift_code  ?? '',
        address:     b.address     ?? '',
        is_active:   b.is_active   ?? true,
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) bankRef.current?.focus()
  }, [isFetching, editId])

  const handleChange = (e) => {
    const { name, value, type, checked } = e.target
    const newVal = type === 'checkbox' ? checked : value
    setForm((prev) => ({ ...prev, [name]: newVal }))
    if (touched[name]) setErrors((prev) => ({ ...prev, [name]: validate(name, newVal) }))
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) => (isEditing ? updateBankBranch(editId, payload) : createBankBranch(payload)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-branches'] })
      queryClient.invalidateQueries({ queryKey: ['bank-branches-all'] })
      queryClient.invalidateQueries({ queryKey: ['banks'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['bank-branch', editId] })
      showSuccess(isEditing ? 'Branch updated.' : 'Branch created.')
      onDone()
    },
    onError: (err) => {
      const apiErrors = err.response?.data?.errors ?? {}
      if (Object.keys(apiErrors).length) {
        setErrors(Object.fromEntries(Object.entries(apiErrors).map(([k, v]) => [k, v[0]])))
        setTouched(Object.fromEntries(Object.keys(apiErrors).map((k) => [k, true])))
      }
      showError('Failed to save. Please check the form and try again.')
    },
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    const newErrors = {
      bank_id:     validate('bank_id', form.bank_id),
      branch_name: validate('branch_name', form.branch_name),
      branch_code: validate('branch_code', form.branch_code),
      swift_code:  validate('swift_code', form.swift_code),
    }
    setErrors(newErrors)
    setTouched({ bank_id: true, branch_name: true, branch_code: true, swift_code: true })
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      bank_id:     parseInt(form.bank_id, 10),
      branch_name: form.branch_name.trim(),
      branch_code: form.branch_code.trim().toUpperCase(),
      swift_code:  form.swift_code.trim().toUpperCase() || null,
      address:     form.address.trim() || null,
      is_active:   form.is_active,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">
      <div>
        <label className={LABEL_CLS}>Bank <span className="text-red-500">*</span></label>
        <select
          ref={bankRef}
          name="bank_id"
          value={form.bank_id}
          onChange={handleChange}
          onBlur={handleBlur}
          className={errors.bank_id && touched.bank_id ? inputErr : inputBase}
        >
          <option value="">— Select bank —</option>
          {banks.map((b) => (
            <option key={b.id} value={b.id}>{b.bank_name}</option>
          ))}
        </select>
        {errors.bank_id && touched.bank_id && <p className={ERR_CLS}>{errors.bank_id}</p>}
      </div>

      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Branch Code <span className="text-red-500">*</span></label>
          <input
            name="branch_code"
            type="text"
            value={form.branch_code}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="e.g. 001"
            maxLength={30}
            autoComplete="off"
            className={errors.branch_code && touched.branch_code ? inputErr : inputBase}
          />
          {errors.branch_code && touched.branch_code && <p className={ERR_CLS}>{errors.branch_code}</p>}
        </div>
        <div>
          <label className={LABEL_CLS}>SWIFT Code</label>
          <input
            name="swift_code"
            type="text"
            value={form.swift_code}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="e.g. CCEYLKLX"
            maxLength={20}
            autoComplete="off"
            className={errors.swift_code && touched.swift_code ? inputErr : inputBase}
          />
          {errors.swift_code && touched.swift_code && <p className={ERR_CLS}>{errors.swift_code}</p>}
        </div>
      </div>

      <div>
        <label className={LABEL_CLS}>Branch Name <span className="text-red-500">*</span></label>
        <input
          name="branch_name"
          type="text"
          value={form.branch_name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Kandy"
          maxLength={100}
          autoComplete="off"
          className={errors.branch_name && touched.branch_name ? inputErr : inputBase}
        />
        {errors.branch_name && touched.branch_name && <p className={ERR_CLS}>{errors.branch_name}</p>}
      </div>

      <div>
        <label className={LABEL_CLS}>Address</label>
        <input
          name="address"
          type="text"
          value={form.address}
          onChange={handleChange}
          placeholder="Branch address"
          maxLength={255}
          className={inputBase}
        />
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
        <p className="mt-0.5 text-[10px] text-slate-400">Branch codes must be unique within a bank, not globally.</p>
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
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Branch'}
        </button>
      </div>
    </form>
  )
}

export default function BankBranchesPage() {
  const [page,       setPage]       = useState(1)
  const [editId,     setEditId]     = useState(null)
  const [bankFilter, setBankFilter] = useState('')
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data: banks = [] } = useQuery({
    queryKey: ['banks-all'],
    queryFn:  getAllBanks,
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['bank-branches', page, bankFilter],
    queryFn:  () => getBankBranches(page, bankFilter ? { bank_id: bankFilter } : {}),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteBankBranch,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bank-branches'] })
      queryClient.invalidateQueries({ queryKey: ['banks'] })
      showSuccess('Branch deleted.')
    },
    onError: (err) =>
      showError(err.response?.data?.message ?? 'Failed to delete. The branch may be in use.'),
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Bank Branches</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-1.5">
            <label className="text-[10px] font-bold uppercase tracking-wider text-slate-500">Bank</label>
            <select
              value={bankFilter}
              onChange={(e) => { setBankFilter(e.target.value); setPage(1) }}
              className="rounded border-2 border-slate-200 bg-white px-2 py-0.5 text-xs text-slate-700 outline-none focus:border-indigo-500"
            >
              <option value="">All banks</option>
              {banks.map((b) => (
                <option key={b.id} value={b.id}>{b.bank_name}</option>
              ))}
            </select>
          </div>

          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load branches.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Bank</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Branch</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">SWIFT</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-400">
                          No branches yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((row, i) => (
                        <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                          <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                          <td className="max-w-[12rem] truncate px-3 py-2 text-slate-600" title={row.bank_name ?? ''}>{row.bank_name}</td>
                          <td className="px-3 py-2 font-mono text-slate-500">{row.branch_code}</td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.branch_name}</td>
                          <td className="px-3 py-2 font-mono text-slate-500">
                            {row.swift_code || <span className="italic text-slate-300">—</span>}
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
                              {can('edit_bank_branches') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(row.id)}
                                  className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_bank_branches') && (
                                <DeleteBtn onClick={() => handleDelete(row.id, `${row.bank_name} — ${row.branch_name}`)} disabled={deleteMutation.isPending} />
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

        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm self-start">
          <div className="flex items-center justify-between gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <Building2 size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Branch' : 'New Branch'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_bank_branches') || can('edit_bank_branches') ? (
            <BankBranchForm key={editId ?? 'create'} editId={editId} banks={banks} onDone={() => setEditId(null)} onCancel={() => setEditId(null)} />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage bank branches.</div>
          )}
        </div>
      </div>
    </div>
  )
}
