import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, FolderTree, Save, Search, X } from 'lucide-react'
import {
  createAccountCategory, deleteAccountCategory, getAccountCategories, getAccountCategory,
  getAccountTypes, getNextAccountCategoryCode, updateAccountCategory,
} from '../../api/accountCategories'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'
import {
  FILTER_INPUT_CLS, FILTER_SELECT_CLS, INPUT_CLS, INPUT_DISABLED_CLS, INPUT_ERR_CLS,
  LABEL_CLS, SELECT_CLS, SELECT_ERR_CLS, TEXTAREA_CLS,
} from '../../utils/fieldStyles'

const CRUMBS = [{ label: 'Finance' }, { label: 'Account Categories' }]

const EMPTY_FORM = { account_type: '', category_name: '', description: '', is_active: true }

const ERR_CLS = 'mt-0.5 text-[10px] text-red-500'

// Colour by account type so the chart is scannable at a glance. Balance-sheet
// types sit in cool colours, profit & loss types in warm ones.
const TYPE_BADGE = {
  asset:     'bg-sky-50 text-sky-700',
  liability: 'bg-violet-50 text-violet-700',
  equity:    'bg-indigo-50 text-indigo-700',
  income:    'bg-emerald-50 text-emerald-700',
  expense:   'bg-amber-50 text-amber-700',
}

function validate(field, value) {
  if (field === 'account_type' && !String(value)) return 'Account type is required.'
  if (field === 'category_name') {
    const v = String(value).trim()
    if (!v) return 'Account category is required.'
    if (v.length > 100) return 'Max 100 characters.'
  }
  if (field === 'description' && String(value).length > 1000) return 'Max 1000 characters.'
  return ''
}

function AccountCategoryForm({ editId, accountTypes, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const typeRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['account-category', editId],
    queryFn:  () => getAccountCategory(editId),
    enabled:  isEditing,
  })

  // Live preview of the code this category would receive. Display only — the
  // authoritative code is allocated by the server inside the save transaction.
  const { data: previewCode = '' } = useQuery({
    queryKey: ['account-category-next-code', form.account_type],
    queryFn:  () => getNextAccountCategoryCode(form.account_type),
    enabled:  !isEditing && Boolean(form.account_type),
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
      const c = fetchedData.data
      setForm({
        account_type:  c.account_type  ?? '',
        category_name: c.category_name ?? '',
        description:   c.description   ?? '',
        is_active:     c.is_active     ?? true,
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
    setForm((prev) => ({ ...prev, [name]: newVal }))
    if (touched[name]) setErrors((prev) => ({ ...prev, [name]: validate(name, newVal) }))
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) => (isEditing ? updateAccountCategory(editId, payload) : createAccountCategory(payload)),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account-categories'] })
      queryClient.invalidateQueries({ queryKey: ['account-categories-all'] })
      queryClient.invalidateQueries({ queryKey: ['account-category-next-code'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['account-category', editId] })
      showSuccess(isEditing ? 'Account category updated.' : 'Account category created.')
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
    const newErrors = {
      account_type:  validate('account_type', form.account_type),
      category_name: validate('category_name', form.category_name),
      description:   validate('description', form.description),
    }
    setErrors(newErrors)
    setTouched({ account_type: true, category_name: true, description: true })
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      account_type:  form.account_type,
      category_name: form.category_name.trim(),
      description:   form.description.trim() || null,
      is_active:     form.is_active,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
  }

  const codeValue = isEditing ? (fetchedData?.data?.code ?? '') : previewCode

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">
      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Account Type <span className="text-red-500">*</span></label>
          <select
            ref={typeRef}
            name="account_type"
            value={form.account_type}
            onChange={handleChange}
            onBlur={handleBlur}
            // Locked after creation: the type is the first digit of the code,
            // and the code is immutable once assigned.
            disabled={isEditing}
            className={errors.account_type && touched.account_type ? SELECT_ERR_CLS : SELECT_CLS}
          >
            <option value="">— Select —</option>
            {accountTypes.map((t) => (
              <option key={t.value} value={t.value}>{t.digit} · {t.label}</option>
            ))}
          </select>
          {errors.account_type && touched.account_type && <p className={ERR_CLS}>{errors.account_type}</p>}
          {isEditing && (
            <p className="mt-0.5 text-[10px] text-slate-400">Locked — the type is built into the code.</p>
          )}
        </div>

        <div>
          <label className={LABEL_CLS}>Code</label>
          <input
            type="text"
            value={codeValue}
            readOnly
            tabIndex={-1}
            placeholder="Automatically generated"
            className={`${INPUT_DISABLED_CLS} font-mono`}
          />
          <p className="mt-0.5 text-[10px] text-slate-400">
            {isEditing ? 'Immutable once assigned.' : 'Assigned on save.'}
          </p>
        </div>
      </div>

      <div>
        <label className={LABEL_CLS}>Account Category <span className="text-red-500">*</span></label>
        <input
          name="category_name"
          type="text"
          value={form.category_name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Current Assets"
          maxLength={100}
          autoComplete="off"
          className={errors.category_name && touched.category_name ? INPUT_ERR_CLS : INPUT_CLS}
        />
        {errors.category_name && touched.category_name && <p className={ERR_CLS}>{errors.category_name}</p>}
      </div>

      <div>
        <label className={LABEL_CLS}>Description</label>
        <textarea
          name="description"
          rows={2}
          value={form.description}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="Optional notes"
          maxLength={1000}
          className={TEXTAREA_CLS}
        />
        {errors.description && touched.description && <p className={ERR_CLS}>{errors.description}</p>}
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
        <p className="mt-0.5 text-[10px] text-slate-400">Inactive categories stay on existing records but are hidden from new entries.</p>
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
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Category'}
        </button>
      </div>
    </form>
  )
}

export default function AccountCategoriesPage() {
  const [page,       setPage]       = useState(1)
  const [editId,     setEditId]     = useState(null)
  const [search,     setSearch]     = useState('')
  const [typeFilter, setTypeFilter] = useState('')
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data: accountTypes = [] } = useQuery({
    queryKey: ['account-types'],
    queryFn:  getAccountTypes,
    staleTime: Infinity, // Five fixed values backed by a PHP enum.
  })

  const { data, isLoading, isError } = useQuery({
    queryKey: ['account-categories', page, search, typeFilter],
    queryFn:  () => getAccountCategories(page, {
      ...(search ? { search } : {}),
      ...(typeFilter ? { account_type: typeFilter } : {}),
    }),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteAccountCategory,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account-categories'] })
      showSuccess('Account category deleted.')
    },
    onError: (err) =>
      showError(
        err.response?.data?.errors?.id?.[0]
        ?? err.response?.data?.message
        ?? 'Failed to delete. The category may be in use.',
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Account Category Master</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-slate-50 px-3 py-1.5">
            <div className="relative w-56">
              <Search size={12} className="absolute left-2 top-1/2 -translate-y-1/2 text-slate-400" />
              <input
                type="text"
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1) }}
                placeholder="Search name or code…"
                className={`${FILTER_INPUT_CLS} pl-6`}
              />
            </div>
            <select
              value={typeFilter}
              onChange={(e) => { setTypeFilter(e.target.value); setPage(1) }}
              className={`${FILTER_SELECT_CLS} w-40`}
            >
              <option value="">All account types</option>
              {accountTypes.map((t) => (
                <option key={t.value} value={t.value}>{t.label}</option>
              ))}
            </select>
          </div>

          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load account categories.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Account Type</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Account Category</th>
                      <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Controls</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-400">
                          No account categories yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((row) => (
                        <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                          <td className="px-3 py-2 font-mono font-semibold text-slate-700">{row.code}</td>
                          <td className="px-3 py-2">
                            <span className={`inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold ${TYPE_BADGE[row.account_type] ?? 'bg-slate-100 text-slate-600'}`}>
                              {row.account_type_label}
                            </span>
                          </td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.category_name}</td>
                          <td className="px-3 py-2 text-right text-slate-500">{row.control_accounts_count ?? 0}</td>
                          <td className="px-3 py-2">
                            {row.is_active ? (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                            ) : (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Inactive</span>
                            )}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              {can('edit_account_categories') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(row.id)}
                                  className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_account_categories') && (
                                <DeleteBtn onClick={() => handleDelete(row.id, `${row.code} — ${row.category_name}`)} disabled={deleteMutation.isPending} />
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
              <FolderTree size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Account Category' : 'Create Account Category'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_account_categories') || can('edit_account_categories') ? (
            <AccountCategoryForm
              key={editId ?? 'create'}
              editId={editId}
              accountTypes={accountTypes}
              onDone={() => setEditId(null)}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage account categories.</div>
          )}
        </div>
      </div>
    </div>
  )
}
