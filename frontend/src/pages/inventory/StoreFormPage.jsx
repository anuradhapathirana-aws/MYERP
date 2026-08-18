import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FileText, MapPin, Phone, Save, Settings2, Warehouse } from 'lucide-react'
import { createStore, getStore, updateStore } from '../../api/stores'
import { getAllStores } from '../../api/stores'
import { getAllStoreTypes } from '../../api/storeTypes'
import { getAllLocations } from '../../api/locations'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'

const EMPTY_FORM = {
  store_type_id:    '',
  store_code:       '',
  store_name:       '',
  location_id:      '',
  parent_store_id:  '',
  address_line_1:   '',
  address_line_2:   '',
  city:             '',
  state:            '',
  country:          '',
  postal_code:      '',
  manager_name:     '',
  phone:            '',
  email:            '',
  description:      '',
  is_active:        true,
}

const REQUIRED = new Set(['store_type_id', 'store_code', 'store_name'])

const LABELS = {
  store_type_id: 'Store Type',
  store_code:    'Store Code',
  store_name:    'Store Name',
}

function validate(field, value) {
  const v = typeof value === 'string' ? value.trim() : value
  if (REQUIRED.has(field) && (v === '' || v === null || v === undefined)) {
    return `${LABELS[field]} is required.`
  }
  switch (field) {
    case 'store_code':
      if (v && String(v).length > 50) return 'Max 50 characters.'
      break
    case 'store_name':
      if (v && String(v).length > 150) return 'Max 150 characters.'
      break
    case 'email':
      if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email.'
      break
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function cls(errors, touched, name) {
  return errors[name] && touched[name] ? inputErr : inputBase
}

function Label({ children, required }) {
  return (
    <label className={LABEL_CLS}>
      {children}{required && <span className="ml-0.5 text-red-500">*</span>}
    </label>
  )
}

function FieldError({ errors, touched, name }) {
  if (!errors[name] || !touched[name]) return null
  return <p className={ERR_CLS}>{errors[name]}</p>
}

function SectionCard({ icon: Icon, title, colorClass, children }) {
  return (
    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
      <div className={`flex items-center gap-1.5 px-3 py-2 border-b ${colorClass}`}>
        {Icon && <Icon size={13} />}
        <h2 className="text-xs font-bold">{title}</h2>
      </div>
      <div className="space-y-2 p-2.5">{children}</div>
    </div>
  )
}

/**
 * Convert a flat store list (each with id, parent_store_id, store_name, store_code)
 * into a depth-first ordered array of { id, label } where label is indented to
 * reflect nesting level.  Root nodes (parent_store_id == null) come first;
 * their children are inserted directly below them, recursively.
 */
function buildTreeOptions(stores) {
  const byParent = {}
  for (const s of stores) {
    const key = s.parent_store_id ?? null
    ;(byParent[key] ??= []).push(s)
  }

  const result = []
  function walk(parentId, depth) {
    const indent  = '   '.repeat(depth)
    const prefix  = depth > 0 ? '└─ ' : ''
    for (const s of (byParent[parentId] ?? [])) {
      result.push({ id: s.id, label: `${indent}${prefix}${s.store_name} (${s.store_code})` })
      walk(s.id, depth + 1)
    }
  }
  walk(null, 0)
  return result
}

export default function StoreFormPage() {
  const { id }      = useParams()
  const isEditing   = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  // ── Remote data for dropdowns ──────────────────────────────────
  const { data: storeTypes = [] } = useQuery({
    queryKey: ['store-types-all'],
    queryFn:  getAllStoreTypes,
  })

  const { data: locations = [] } = useQuery({
    queryKey: ['locations-all'],
    queryFn:  () => getAllLocations().then((r) => (Array.isArray(r) ? r : r?.data ?? [])),
  })

  const { data: allStores = [] } = useQuery({
    queryKey: ['stores-all'],
    queryFn:  getAllStores,
  })
  // Exclude self in edit mode; then flatten into nested option list
  const candidateStores = isEditing
    ? allStores.filter((s) => String(s.id) !== String(id))
    : allStores
  const nestedParentOptions = buildTreeOptions(candidateStores)

  // ── Edit: populate form ────────────────────────────────────────
  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['store', id],
    queryFn:  () => getStore(id),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const s = fetchedData.data
      setForm({
        store_type_id:   String(s.store_type_id ?? ''),
        store_code:      s.store_code ?? '',
        store_name:      s.store_name ?? '',
        location_id:     String(s.location_id ?? ''),
        parent_store_id: String(s.parent_store_id ?? ''),
        address_line_1:  s.address_line_1 ?? '',
        address_line_2:  s.address_line_2 ?? '',
        city:            s.city ?? '',
        state:           s.state ?? '',
        country:         s.country ?? '',
        postal_code:     s.postal_code ?? '',
        manager_name:    s.manager_name ?? '',
        phone:           s.phone ?? '',
        email:           s.email ?? '',
        description:     s.description ?? '',
        is_active:       s.is_active ?? true,
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => { nameRef.current?.focus() }, [])

  // ── Handlers ───────────────────────────────────────────────────
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

  const inp = (name, extra = {}) => ({
    id: `f-${name}`, name, value: form[name],
    onChange: handleChange, onBlur: handleBlur,
    className: cls(errors, touched, name),
    autoComplete: 'off', ...extra,
  })

  // ── Mutation ───────────────────────────────────────────────────
  const mutation = useMutation({
    mutationFn: (payload) =>
      isEditing ? updateStore(id, payload) : createStore(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['stores'] })
      queryClient.invalidateQueries({ queryKey: ['stores-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['store', id] })
      showSuccess(isEditing ? 'Store updated successfully.' : 'Store created successfully.')
      navigate('/inventory/stores')
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
    const fields = Object.keys(EMPTY_FORM).filter((f) => f !== 'is_active')
    const newErrors  = Object.fromEntries(fields.map((f) => [f, validate(f, form[f])]))
    const newTouched = Object.fromEntries(fields.map((f) => [f, true]))
    setErrors(newErrors)
    setTouched(newTouched)
    if (Object.values(newErrors).some(Boolean)) return

    const str  = (v) => (String(v).trim() === '' ? null : String(v).trim())
    const intv = (v) => (v === '' || v === '0' ? null : parseInt(v, 10))

    mutation.mutate({
      store_type_id:    parseInt(form.store_type_id, 10),
      store_code:       form.store_code.trim(),
      store_name:       form.store_name.trim(),
      location_id:      intv(form.location_id),
      parent_store_id:  intv(form.parent_store_id),
      address_line_1:   str(form.address_line_1),
      address_line_2:   str(form.address_line_2),
      city:             str(form.city),
      state:            str(form.state),
      country:          str(form.country),
      postal_code:      str(form.postal_code),
      manager_name:     str(form.manager_name),
      phone:            str(form.phone),
      email:            str(form.email),
      description:      str(form.description),
      is_active:        form.is_active,
    })
  }

  const crumbs = [
    { label: 'Inventory', to: '/inventory/stores' },
    { label: 'Stores',    to: '/inventory/stores' },
    { label: isEditing ? 'Edit Store' : 'New Store' },
  ]

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  }

  return (
    <div className="w-full">
      <div className="mb-2">
        <h1 className="text-xl font-bold leading-none text-slate-800">
          {isEditing ? 'Edit Store' : 'New Store'}
        </h1>
        <Breadcrumb crumbs={crumbs} />
      </div>
      <form onSubmit={handleSubmit} noValidate>
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">

          {/* ── LEFT + CENTRE (2 cols) ───────────────────────────── */}
          <div className="space-y-2 lg:col-span-2">

            {/* Store Details */}
            <SectionCard icon={Warehouse} title="Store Details" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100">
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">

                {/* Store Type */}
                <div>
                  <Label required>Store Type</Label>
                  <select {...inp('store_type_id')}>
                    <option value="">— Select type —</option>
                    {storeTypes.map((t) => (
                      <option key={t.id} value={t.id}>{t.store_type_name}</option>
                    ))}
                  </select>
                  <FieldError errors={errors} touched={touched} name="store_type_id" />
                </div>

                {/* Store Code */}
                <div>
                  <Label required>Store Code</Label>
                  <input type="text" placeholder="WH-001" maxLength={50} {...inp('store_code')} />
                  <FieldError errors={errors} touched={touched} name="store_code" />
                </div>

                {/* Store Name */}
                <div className="xl:col-span-2">
                  <Label required>Store Name</Label>
                  <input ref={nameRef} type="text" placeholder="Main Warehouse" maxLength={150} {...inp('store_name')} />
                  <FieldError errors={errors} touched={touched} name="store_name" />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                {/* Location */}
                <div>
                  <Label>Location</Label>
                  <select {...inp('location_id')}>
                    <option value="">— Select location —</option>
                    {locations.map((l) => (
                      <option key={l.id} value={l.id}>{l.location_name}</option>
                    ))}
                  </select>
                  <FieldError errors={errors} touched={touched} name="location_id" />
                </div>

                {/* Parent Store — nested hierarchy */}
                <div>
                  <Label>Parent Store</Label>
                  <select {...inp('parent_store_id')}>
                    <option value="">— None (top-level) —</option>
                    {nestedParentOptions.map(({ id: sid, label }) => (
                      <option key={sid} value={sid}>{label}</option>
                    ))}
                  </select>
                  <FieldError errors={errors} touched={touched} name="parent_store_id" />
                </div>
              </div>
            </SectionCard>

            {/* Address */}
            <SectionCard icon={MapPin} title="Address" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Address Line 1</Label>
                  <input type="text" placeholder="Street / building" maxLength={150} {...inp('address_line_1')} />
                  <FieldError errors={errors} touched={touched} name="address_line_1" />
                </div>
                <div>
                  <Label>Address Line 2</Label>
                  <input type="text" placeholder="Floor / suite" maxLength={150} {...inp('address_line_2')} />
                  <FieldError errors={errors} touched={touched} name="address_line_2" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                <div>
                  <Label>City</Label>
                  <input type="text" placeholder="City" maxLength={100} {...inp('city')} />
                  <FieldError errors={errors} touched={touched} name="city" />
                </div>
                <div>
                  <Label>State / Province</Label>
                  <input type="text" placeholder="State" maxLength={100} {...inp('state')} />
                  <FieldError errors={errors} touched={touched} name="state" />
                </div>
                <div>
                  <Label>Country</Label>
                  <input type="text" placeholder="Country" maxLength={100} {...inp('country')} />
                  <FieldError errors={errors} touched={touched} name="country" />
                </div>
                <div>
                  <Label>Postal Code</Label>
                  <input type="text" placeholder="10001" maxLength={20} {...inp('postal_code')} />
                  <FieldError errors={errors} touched={touched} name="postal_code" />
                </div>
              </div>
            </SectionCard>

            {/* Contact */}
            <SectionCard icon={Phone} title="Contact" colorClass="text-sky-700 bg-sky-50 border-sky-100">
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-3">
                <div>
                  <Label>Manager Name</Label>
                  <input type="text" placeholder="Full name" maxLength={100} {...inp('manager_name')} />
                  <FieldError errors={errors} touched={touched} name="manager_name" />
                </div>
                <div>
                  <Label>Phone</Label>
                  <input type="tel" placeholder="+1 234 567 8900" maxLength={30} {...inp('phone')} />
                  <FieldError errors={errors} touched={touched} name="phone" />
                </div>
                <div>
                  <Label>Email</Label>
                  <input type="email" placeholder="store@example.com" maxLength={100} {...inp('email')} />
                  <FieldError errors={errors} touched={touched} name="email" />
                </div>
              </div>
            </SectionCard>

          </div>

          {/* ── RIGHT column ────────────────────────────────────── */}
          <div className="space-y-2">
            <SectionCard icon={Settings2} title="Settings" colorClass="text-violet-700 bg-violet-50 border-violet-100">
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
              <p className="text-[10px] text-slate-400">
                Inactive stores are hidden from stock movement dropdowns.
              </p>
            </SectionCard>

            {/* Description */}
            <SectionCard icon={FileText} title="Description" colorClass="text-amber-700 bg-amber-50 border-amber-100">
              <textarea
                rows={4}
                placeholder="Additional notes about this store…"
                className={`${inputBase} resize-none`}
                name="description"
                value={form.description}
                onChange={handleChange}
                onBlur={handleBlur}
              />
            </SectionCard>

            {/* Actions */}
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white p-2.5 flex flex-col gap-1.5">
              <button
                type="submit"
                disabled={mutation.isPending}
                className="flex w-full items-center justify-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                <Save size={12} strokeWidth={2.5} />
                {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Store'}
              </button>
              <Link
                to="/inventory/stores"
                className="block w-full rounded border border-slate-200 px-3 py-1 text-center text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100"
              >
                Cancel
              </Link>
              {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
                <p className="text-[10px] text-red-600">
                  {mutation.error?.response?.data?.message ?? 'An unexpected error occurred. Please try again.'}
                </p>
              )}
            </div>
          </div>
        </div>
      </form>
    </div>
  )
}
