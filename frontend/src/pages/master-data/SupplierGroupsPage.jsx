import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Layers, Save, X } from 'lucide-react'
import {
  createSupplierGroup, deleteSupplierGroup, getSupplierGroup, getSupplierGroups, updateSupplierGroup,
} from '../../api/supplierGroups'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Master Data', to: '/master-data/supplier-groups' },
  { label: 'Supplier Groups' },
]

const EMPTY_FORM = { code: '', name: '', description: '', is_active: true, sort_order: 0 }

const CODE_RE = /^[A-Za-z0-9_-]+$/

function validate(field, value) {
  if (field === 'code') {
    const v = String(value).trim()
    if (!v) return 'Code is required.'
    if (v.length > 30) return 'Max 30 characters.'
    if (!CODE_RE.test(v)) return 'Letters, numbers, hyphens and underscores only.'
  }
  if (field === 'name') {
    const v = String(value).trim()
    if (!v) return 'Name is required.'
    if (v.length > 100) return 'Max 100 characters.'
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function SupplierGroupForm({ editId, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const codeRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['supplier-group', editId],
    queryFn:  () => getSupplierGroup(editId),
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
      const g = fetchedData.data
      setForm({
        code:        g.code        ?? '',
        name:        g.name        ?? '',
        description: g.description ?? '',
        is_active:   g.is_active   ?? true,
        sort_order:  g.sort_order  ?? 0,
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) codeRef.current?.focus()
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
    mutationFn: (payload) =>
      isEditing ? updateSupplierGroup(editId, payload) : createSupplierGroup(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['supplier-groups'] })
      queryClient.invalidateQueries({ queryKey: ['supplier-groups-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['supplier-group', editId] })
      showSuccess(isEditing ? 'Supplier group updated.' : 'Supplier group created.')
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
    const newErrors = { code: validate('code', form.code), name: validate('name', form.name) }
    setErrors(newErrors)
    setTouched({ code: true, name: true })
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      code:        form.code.trim().toUpperCase(),
      name:        form.name.trim(),
      description: form.description.trim() || null,
      is_active:   form.is_active,
      sort_order:  parseInt(form.sort_order, 10) || 0,
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">
      <div className="grid grid-cols-2 gap-2">
        <div>
          <label className={LABEL_CLS}>Code <span className="text-red-500">*</span></label>
          <input
            ref={codeRef}
            name="code"
            type="text"
            value={form.code}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="e.g. UTILITY"
            maxLength={30}
            autoComplete="off"
            className={errors.code && touched.code ? inputErr : inputBase}
          />
          {errors.code && touched.code && <p className={ERR_CLS}>{errors.code}</p>}
        </div>
        <div>
          <label className={LABEL_CLS}>Sort Order</label>
          <input name="sort_order" type="number" value={form.sort_order} onChange={handleChange} className={inputBase} />
        </div>
      </div>

      <div>
        <label className={LABEL_CLS}>Name <span className="text-red-500">*</span></label>
        <input
          name="name"
          type="text"
          value={form.name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Utility Providers"
          maxLength={100}
          autoComplete="off"
          className={errors.name && touched.name ? inputErr : inputBase}
        />
        {errors.name && touched.name && <p className={ERR_CLS}>{errors.name}</p>}
      </div>

      <div>
        <label className={LABEL_CLS}>Description</label>
        <input
          name="description"
          type="text"
          value={form.description}
          onChange={handleChange}
          placeholder="What this group is used for"
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
        <p className="mt-0.5 text-[10px] text-slate-400">Inactive groups are hidden from the supplier form dropdown.</p>
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
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Group'}
        </button>
      </div>
    </form>
  )
}

export default function SupplierGroupsPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['supplier-groups', page],
    queryFn:  () => getSupplierGroups(page),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteSupplierGroup,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['supplier-groups'] })
      showSuccess('Supplier group deleted.')
    },
    // The API returns 422 with a clear reason when suppliers still use the group.
    onError: (err) =>
      showError(err.response?.data?.message ?? 'Failed to delete. The group may be in use.'),
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
          <h1 className="text-xl font-bold leading-none text-slate-800">Supplier Groups</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          {isLoading && <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>}
          {isError && <div className="flex items-center justify-center py-16 text-sm text-red-500">Failed to load supplier groups.</div>}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Code</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Description</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Suppliers</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="px-4 py-8 text-center text-sm text-slate-400">
                          No supplier groups yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((row, i) => (
                        <tr key={row.id} className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}>
                          <td className="px-3 py-2 text-slate-400">{(page - 1) * (meta?.per_page ?? 50) + i + 1}</td>
                          <td className="px-3 py-2 font-mono text-slate-500">{row.code}</td>
                          <td className="px-3 py-2 font-medium text-slate-800">{row.name}</td>
                          <td className="max-w-[16rem] truncate px-3 py-2 text-slate-500" title={row.description ?? ''}>
                            {row.description || <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2 text-right tabular-nums text-slate-500">{row.suppliers_count ?? 0}</td>
                          <td className="px-3 py-2">
                            {row.is_active ? (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                            ) : (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Inactive</span>
                            )}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              {can('edit_supplier_groups') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(row.id)}
                                  className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_supplier_groups') && (
                                <DeleteBtn onClick={() => handleDelete(row.id, row.name)} disabled={deleteMutation.isPending} />
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
              <Layers size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Supplier Group' : 'New Supplier Group'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_supplier_groups') || can('edit_supplier_groups') ? (
            <SupplierGroupForm key={editId ?? 'create'} editId={editId} onDone={() => setEditId(null)} onCancel={() => setEditId(null)} />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage supplier groups.</div>
          )}
        </div>
      </div>
    </div>
  )
}
