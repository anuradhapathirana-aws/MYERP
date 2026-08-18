import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Edit2, Save, Tag, X } from 'lucide-react'
import { createAttribute, deleteAttribute, getAttribute, getAttributes, updateAttribute } from '../../api/attributes'
import { getAllAttributeTypes } from '../../api/attributeTypes'
import { getAllCategories } from '../../api/categories'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import TreeSelect from '../../components/TreeSelect'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { useTableFilter } from '../../hooks/useTableFilter'
import { FILTER_INPUT_CLS, FILTER_SELECT_CLS } from '../../utils/fieldStyles'
import { DeleteBtn } from '../../components/ui/ActionButtons'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/attributes' },
  { label: 'Attributes' },
]

const INITIAL_FILTERS = { search: '', category_id: '', attribute_type_id: '' }

const EMPTY_FORM = {
  attribute_type_id: '',
  attribute_name:    '',
}

function validate(field, value) {
  if (field === 'attribute_type_id' && !value) return 'Attribute type is required.'
  if (field === 'attribute_name') {
    if (!value.trim()) return 'At least one attribute name is required.'
    const names = value.split(',').map((s) => s.trim()).filter(Boolean)
    const tooLong = names.find((n) => n.length > 100)
    if (tooLong) return `"${tooLong}" exceeds 100 characters.`
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'
const fieldCls = (errors, touched, name) =>
  errors[name] && touched[name] ? inputErr : inputBase

// ── Inline form panel ───────────────────────────────────────────────────────
function AttributeForm({ editId, onDone, onCancel }) {
  const isEditing   = Boolean(editId)
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { data: typesData } = useQuery({
    queryKey: ['attribute-types-all'],
    queryFn:  getAllAttributeTypes,
  })
  const attributeTypes = typesData ?? []

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['attribute', editId],
    queryFn:  () => getAttribute(editId),
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
      const a = fetchedData.data
      setForm({
        attribute_type_id: String(a.attribute_type_id ?? ''),
        attribute_name:    a.attribute_name ?? '',
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => {
    if (!isFetching) nameRef.current?.focus()
  }, [isFetching, editId])

  const parsedNames = form.attribute_name.split(',').map((s) => s.trim()).filter(Boolean)

  const handleChange = (e) => {
    const { name, value } = e.target
    setForm((prev) => ({ ...prev, [name]: value }))
    if (touched[name]) setErrors((prev) => ({ ...prev, [name]: validate(name, value) }))
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) =>
      isEditing ? updateAttribute(editId, payload) : createAttribute(payload),
    onSuccess: (result) => {
      queryClient.invalidateQueries({ queryKey: ['attributes'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['attribute', editId] })
      if (isEditing) {
        const extra = parsedNames.length - 1
        showSuccess(extra > 0
          ? `Attribute updated. ${extra} new attribute${extra > 1 ? 's' : ''} also created.`
          : 'Attribute updated.')
      } else {
        const count = Array.isArray(result?.data) ? result.data.length : 1
        showSuccess(count > 1 ? `${count} attributes created.` : 'Attribute created.')
      }
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
    const fields    = ['attribute_type_id', 'attribute_name']
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f])]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(fields.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return
    mutation.mutate({
      attribute_type_id: Number(form.attribute_type_id),
      attribute_name:    form.attribute_name.trim(),
    })
  }

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-8 text-xs text-slate-400">Loading…</div>
  }

  return (
    <form onSubmit={handleSubmit} noValidate className="flex flex-col gap-2 p-2.5">

      {/* Attribute Type */}
      <div>
        <label className={LABEL_CLS}>
          Attribute Type <span className="text-red-500">*</span>
        </label>
        <select
          name="attribute_type_id"
          value={form.attribute_type_id}
          onChange={handleChange}
          onBlur={handleBlur}
          className={fieldCls(errors, touched, 'attribute_type_id')}
        >
          <option value="">— Select attribute type —</option>
          {attributeTypes.map((t) => (
            <option key={t.id} value={t.id}>
              {t.attribute_type_name}{t.category_name ? ` — ${t.category_name}` : ''}
            </option>
          ))}
        </select>
        {errors.attribute_type_id && touched.attribute_type_id && (
          <p className={ERR_CLS}>{errors.attribute_type_id}</p>
        )}
      </div>

      {/* Attribute Name(s) */}
      <div>
        <label className={LABEL_CLS}>
          Attribute Name(s) <span className="text-red-500">*</span>
        </label>
        <input
          ref={nameRef}
          name="attribute_name"
          type="text"
          value={form.attribute_name}
          onChange={handleChange}
          onBlur={handleBlur}
          placeholder="e.g. Red, Blue, XL, Cotton"
          autoComplete="off"
          className={fieldCls(errors, touched, 'attribute_name')}
        />
        {errors.attribute_name && touched.attribute_name ? (
          <p className={ERR_CLS}>{errors.attribute_name}</p>
        ) : (
          <p className="mt-0.5 text-[10px] text-slate-400">
            {isEditing
              ? 'First name updates this record; additional names create new attributes.'
              : 'Separate multiple names with commas to create them all at once.'}
          </p>
        )}
        {parsedNames.length > 1 && (
          <div className="mt-1 flex flex-wrap gap-1">
            {parsedNames.map((name, i) => (
              <span
                key={i}
                className={`rounded px-1.5 py-0.5 text-[10px] font-medium border ${
                  isEditing && i === 0
                    ? 'bg-amber-50 text-amber-700 border-amber-100'
                    : 'bg-indigo-50 text-indigo-700 border-indigo-100'
                }`}
              >
                {isEditing && i === 0 ? `↻ ${name}` : `+ ${name}`}
              </span>
            ))}
          </div>
        )}
      </div>

      {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
        <p className="text-[10px] text-red-600">
          {mutation.error?.response?.data?.message ?? 'An unexpected error occurred.'}
        </p>
      )}

      {/* Actions */}
      <div className="flex items-center justify-end gap-1.5 pt-0.5">
        {isEditing && (
          <button
            type="button"
            onClick={onCancel}
            className="flex items-center gap-1 rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-200"
          >
            <X size={12} />
            Cancel
          </button>
        )}
        <button
          type="submit"
          disabled={mutation.isPending}
          className="flex items-center gap-1.5 rounded bg-indigo-600 px-4 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          <Save size={12} strokeWidth={2.5} />
          {mutation.isPending
            ? 'Saving…'
            : isEditing
              ? parsedNames.length > 1
                ? `Update + ${parsedNames.length - 1} New`
                : 'Save Changes'
              : parsedNames.length > 1
                ? `Create ${parsedNames.length} Attributes`
                : 'Create Attribute'
          }
        </button>
      </div>
    </form>
  )
}

// ── Main page ───────────────────────────────────────────────────────────────
export default function AttributesPage() {
  const [page,   setPage]   = useState(1)
  const [editId, setEditId] = useState(null)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const resetPage = () => setPage(1)

  const { data, isLoading, isError } = useQuery({
    queryKey: ['attributes', page, applied],
    queryFn:  () => getAttributes(page, applied),
    placeholderData: (prev) => prev,
  })

  const { data: categories = [] } = useQuery({
    queryKey: ['categories-all'],
    queryFn:  getAllCategories,
    staleTime: 5 * 60 * 1000,
  })

  const { data: attributeTypesData } = useQuery({
    queryKey: ['attribute-types-all'],
    queryFn:  getAllAttributeTypes,
    staleTime: 5 * 60 * 1000,
  })
  const attributeTypes = attributeTypesData ?? []

  // Cascade: once a Category is picked, only show Attribute Types under it.
  const filterAttributeTypeOptions = attributeTypes.filter(
    (t) => !draft.category_id || String(t.category_id) === String(draft.category_id)
  )

  const handleFilterCategoryChange = (e) => {
    const value = e.target.value
    setDraft((d) => ({
      ...d,
      category_id: value,
      // Drop the selected Attribute Type if it no longer belongs to the new category.
      attribute_type_id: attributeTypes.some(
        (t) => String(t.id) === d.attribute_type_id && (!value || String(t.category_id) === String(value))
      ) ? d.attribute_type_id : '',
    }))
  }

  const deleteMutation = useMutation({
    mutationFn: deleteAttribute,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['attributes'] })
      showSuccess('Attribute deleted.')
    },
    onError: () => showError('Failed to delete. The attribute may be in use.'),
  })

  const handleDelete = async (id, name) => {
    const ok = await confirmDelete(name)
    if (ok) {
      if (editId === id) setEditId(null)
      deleteMutation.mutate(id)
    }
  }

  const handleDone = () => setEditId(null)

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div>
        <h1 className="text-xl font-bold leading-none text-slate-800">Attributes</h1>
        <Breadcrumb crumbs={CRUMBS} />
      </div>

      {/* ── Filter Panel ── */}
      <TableFilter
        open={open}
        onToggle={toggle}
        onApply={() => apply(resetPage)}
        onClear={() => clear(resetPage)}
        activeCount={activeCount}
      >
        <FilterField label="Search">
          <input
            className={FILTER_INPUT_CLS}
            placeholder="Attribute name…"
            value={draft.search}
            onChange={(e) => setDraft((d) => ({ ...d, search: e.target.value }))}
          />
        </FilterField>

        <FilterField label="Category">
          <TreeSelect
            name="category_id"
            value={draft.category_id}
            onChange={handleFilterCategoryChange}
            items={categories}
            parentField="parent_category_id"
            labelField="category_name"
            placeholder="All categories"
            emptyText="No categories available."
          />
        </FilterField>

        <FilterField label="Attribute Type">
          <select
            className={FILTER_SELECT_CLS}
            value={draft.attribute_type_id}
            onChange={(e) => setDraft((d) => ({ ...d, attribute_type_id: e.target.value }))}
          >
            <option value="">All attribute types</option>
            {filterAttributeTypeOptions.map((t) => (
              <option key={t.id} value={t.id}>{t.attribute_type_name}</option>
            ))}
          </select>
        </FilterField>
      </TableFilter>

      <div className="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-3">

        {/* ── LEFT: Table ─────────────────────────────────────────────── */}
        <div className="lg:col-span-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          {isLoading && (
            <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>
          )}
          {isError && (
            <div className="flex items-center justify-center py-16 text-sm text-red-500">
              Failed to load attributes.
            </div>
          )}

          {!isLoading && !isError && (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-slate-200 bg-slate-50 text-left">
                      <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Attribute Name</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Attribute Type</th>
                      <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Category</th>
                      <th className="w-24 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                      <th className="w-16 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="px-4 py-8 text-center text-sm text-slate-400">
                          {activeCount > 0
                            ? 'No attributes match the current filters.'
                            : 'No attributes yet. Use the form to create the first one.'}
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
                            {row.attribute_name}
                          </td>
                          <td className="px-3 py-2 text-slate-600">
                            {row.attribute_type_name ?? <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="px-3 py-2 text-slate-600">
                            {row.attribute_type_category_name ?? <span className="italic text-slate-300">—</span>}
                          </td>
                          <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                            {new Date(row.created_at).toLocaleDateString()}
                          </td>
                          <td className="px-3 py-2">
                            <div className="flex items-center justify-end gap-1">
                              {can('edit_attributes') && (
                                <button
                                  type="button"
                                  title="Edit"
                                  onClick={() => setEditId(row.id)}
                                  className={`rounded p-1 transition-colors ${editId === row.id ? 'bg-indigo-100 text-indigo-600' : 'text-amber-500 hover:bg-amber-50 hover:text-amber-700'}`}
                                >
                                  <Edit2 size={13} />
                                </button>
                              )}
                              {can('delete_attributes') && (
                                <DeleteBtn onClick={() => handleDelete(row.id, row.attribute_name)} disabled={deleteMutation.isPending} />
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

        {/* ── RIGHT: Form panel ───────────────────────────────────────── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm self-start">
          <div className="flex items-center justify-between border-b border-indigo-100 bg-indigo-50 px-3 py-2">
            <div className="flex items-center gap-1.5 text-indigo-700">
              <Tag size={13} />
              <h2 className="text-xs font-bold">
                {editId ? 'Edit Attribute' : 'New Attribute'}
              </h2>
            </div>
            {editId && (
              <span className="flex items-center gap-1 rounded bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-600">
                <Edit2 size={10} /> Editing
              </span>
            )}
          </div>

          {can('create_attributes') || can('edit_attributes') ? (
            <AttributeForm
              key={editId ?? 'create'}
              editId={editId}
              onDone={handleDone}
              onCancel={() => setEditId(null)}
            />
          ) : (
            <div className="p-2.5 text-xs text-slate-400">
              You don't have permission to manage attributes.
            </div>
          )}
        </div>

      </div>
    </div>
  )
}
