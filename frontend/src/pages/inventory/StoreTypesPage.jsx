import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Layers, Plus, Save, X } from 'lucide-react'
import {
  createStoreType, deleteStoreType, getStoreType, getStoreTypes, updateStoreType,
} from '../../api/storeTypes'
import Pagination from '../../components/ui/Pagination'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { DeleteBtn } from '../../components/ui/ActionButtons'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/store-types' },
  { label: 'Store Types' },
]

const EMPTY_FORM = { store_type_name: '', description: '', is_active: true }

function validate(field, value) {
  if (field === 'store_type_name') {
    if (!String(value).trim()) return 'Store type name is required.'
    if (String(value).length > 100) return 'Max 100 characters.'
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

// ── Inline form panel ───────────────────────────────────────────────────────
function StoreTypeForm({ editId, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['store-type', editId],
    queryFn:  () => getStoreType(editId),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    // Reset when editId changes
    initialized.current = false
    setForm(EMPTY_FORM)
    setErrors({})
    setTouched({})
  }, [editId])

  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const t = fetchedData.data
      setForm({
        store_type_name: t.store_type_name ?? '',
        description:     t.description     ?? '',
        is_active:       t.is_active       ?? true,
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) nameRef.current?.focus()
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
      isEditing ? updateStoreType(editId, payload) : createStoreType(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['store-types'] })
      queryClient.invalidateQueries({ queryKey: ['store-types-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['store-type', editId] })
      showSuccess(isEditing ? 'Store type updated.' : 'Store type created.')
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
    const newErrors = { store_type_name: validate('store_type_name', form.store_type_name) }
    setErrors(newErrors)
    setTouched({ store_type_name: true })
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      store_type_name: form.store_type_name.trim(),
      description:     form.description.trim() || null,
      is_active:       form.is_active,
    })
  }

  if (isEditing && isFetching) {
    return (
      <div className="flex items-center justify-center py-12 text-xs text-slate-400">Loading…</div>
    )
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">

      {/* Store Type Name */}
      <div>
        <label className={LABEL_CLS}>
          Store Type Name <span className="text-red-500">*</span>
        </label>
        <input
          ref={nameRef}
          name="store_type_name"
          type="text"
          value={form.store_type_name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Warehouse, Cold Storage, Retail"
          maxLength={100}
          autoComplete="off"
          className={errors.store_type_name && touched.store_type_name ? inputErr : inputBase}
        />
        {errors.store_type_name && touched.store_type_name ? (
          <p className={ERR_CLS}>{errors.store_type_name}</p>
        ) : (
          <p className="mt-0.5 text-[10px] text-slate-400">{form.store_type_name.length}/100</p>
        )}
      </div>

      {/* Description */}
      <div>
        <label className={LABEL_CLS}>
          Description <span className="font-normal normal-case text-slate-400">(optional)</span>
        </label>
        <div className="relative">
          <textarea
            name="description"
            value={form.description}
            onChange={handleChange}
            onBlur={handleBlur}
            placeholder="Briefly describe what this store type represents…"
            rows={3}
            className={`${inputBase} resize-none pb-4`}
          />
          <span className="absolute bottom-1 right-2 text-[10px] text-slate-400">
            {form.description.length}
          </span>
        </div>
      </div>

      {/* Active toggle */}
      <div className="rounded border border-slate-100 bg-slate-50 p-2">
        <label className="flex cursor-pointer items-center gap-3">
          <div className="relative">
            <input
              type="checkbox"
              name="is_active"
              checked={form.is_active}
              onChange={handleChange}
              className="sr-only peer"
            />
            <div className="h-5 w-9 rounded-full bg-slate-200 transition-colors peer-checked:bg-indigo-600" />
            <div className="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4" />
          </div>
          <span className="text-xs font-medium text-slate-700">
            {form.is_active ? 'Active' : 'Inactive'}
          </span>
        </label>
        <p className="mt-0.5 text-[10px] text-slate-400">
          Inactive types are hidden from store assignment dropdowns.
        </p>
      </div>

      {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
        <p className={ERR_CLS}>
          {mutation.error?.response?.data?.message ?? 'An unexpected error occurred.'}
        </p>
      )}

      {/* Actions */}
      <div className="flex items-center justify-end gap-2 pt-0.5">
        {isEditing && (
          <button
            type="button"
            onClick={onCancel}
            className="flex items-center gap-1 rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-200"
          >
            <X size={11} />
            Cancel
          </button>
        )}
        <button
          type="submit"
          disabled={mutation.isPending}
          className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Save size={12} strokeWidth={2.5} />
          {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Store Type'}
        </button>
      </div>
    </form>
  )
}

// ── Main page ───────────────────────────────────────────────────────────────
export default function StoreTypesPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)   // null = create mode, number = edit mode
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['store-types', page],
    queryFn:  () => getStoreTypes(page),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteStoreType,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['store-types'] })
      showSuccess('Store type deleted.')
    },
    onError: () => showError('Failed to delete. The store type may be in use.'),
  })

  const handleDelete = async (id, name) => {
    const ok = await confirmDelete(name)
    if (ok) {
      if (editId === id) setEditId(null)
      deleteMutation.mutate(id)
    }
  }

  // After create/edit success → back to create mode
  const handleDone = () => setEditId(null)

  const meta = data?.meta
  const rows = data?.data ?? []

  const isEditMode = editId !== null

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Store Types</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">

        {/* ── LEFT: Table (takes 2/3 width) ─────────────────────────── */}
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          {isLoading && (
            <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>
          )}
          {isError && (
            <div className="flex items-center justify-center py-16 text-sm text-red-500">
              Failed to load store types.
            </div>
          )}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Name</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Description</th>
                      <th className="w-20 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Status</th>
                      <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-400">
                          No store types yet. Use the form to create the first one.
                        </td>
                      </tr>
                    ) : (
                      rows.map((row, i) => (
                        <tr
                          key={row.id}
                          className={`transition-colors hover:bg-slate-50 ${editId === row.id ? 'bg-indigo-50/60' : ''}`}
                        >
                          <td className="px-3 py-2 text-slate-400">
                            {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                          </td>
                          <td className="px-3 py-2 font-medium text-slate-800">
                            {row.store_type_name}
                          </td>
                          <td className="max-w-xs px-3 py-2 text-slate-500">
                            {row.description
                              ? <span className="line-clamp-1" title={row.description}>{row.description}</span>
                              : <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2">
                            {row.is_active ? (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-green-50 text-green-700">Active</span>
                            ) : (
                              <span className="inline-flex items-center rounded px-2 py-0.5 text-[11px] font-semibold bg-slate-100 text-slate-500">Inactive</span>
                            )}
                          </td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                            {new Date(row.created_at).toLocaleDateString()}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              {can('edit_store_types') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(row.id)}
                                  className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_store_types') && (
                                <DeleteBtn onClick={() => handleDelete(row.id, row.store_type_name)} disabled={deleteMutation.isPending} />
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

        {/* ── RIGHT: Form panel (1/3 width) ─────────────────────────── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm self-start">
          <div className="flex items-center justify-between gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <Layers size={13} />
              <h2 className="text-xs font-bold">{isEditMode ? 'Edit Store Type' : 'New Store Type'}</h2>
            </div>
            {isEditMode && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">
                <Edit2 size={9} /> Editing
              </span>
            )}
          </div>

          {can('create_store_types') || can('edit_store_types') ? (
            <StoreTypeForm
              key={editId ?? 'create'}
              editId={editId}
              onDone={handleDone}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">You don't have permission to manage store types.</div>
          )}
        </div>

      </div>
    </div>
  )
}
