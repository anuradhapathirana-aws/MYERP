import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Car, FileText, MapPin, Phone, Save, ShieldCheck } from 'lucide-react'
import { createDriver, getDriver, updateDriver } from '../../api/drivers'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'

const LICENSE_TYPES = ['A', 'A1', 'B', 'B1', 'C', 'C1', 'CE', 'D', 'DE']
const STATUSES      = ['active', 'inactive', 'suspended']

const EMPTY_FORM = {
  driver_code:          '',
  first_name:           '',
  last_name:            '',
  nic_number:           '',
  date_of_birth:        '',
  license_number:       '',
  license_type:         '',
  license_expiry_date:  '',
  phone:                '',
  email:                '',
  address_line1:        '',
  address_line2:        '',
  city:                 '',
  state:                '',
  country:              '',
  postal_code:          '',
  hired_date:           '',
  status:               'active',
  notes:                '',
}

const REQUIRED = new Set(['driver_code', 'first_name', 'license_number'])

const LABELS = {
  driver_code:    'Driver Code',
  first_name:     'First Name',
  license_number: 'Licence Number',
}

function validate(field, value) {
  const v = typeof value === 'string' ? value.trim() : value
  if (REQUIRED.has(field) && !v) return `${LABELS[field]} is required.`
  switch (field) {
    case 'driver_code':
      if (v && v.length > 20) return 'Max 20 characters.'
      break
    case 'first_name':
    case 'last_name':
      if (v && v.length > 100) return 'Max 100 characters.'
      break
    case 'license_number':
      if (v && v.length > 50) return 'Max 50 characters.'
      break
    case 'email':
      if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email.'
      break
    case 'license_expiry_date':
    case 'date_of_birth':
    case 'hired_date':
      if (v && isNaN(Date.parse(v))) return 'Enter a valid date.'
      break
  }
  return ''
}

const inputBase = 'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr  = 'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function fieldCls(errors, touched, name) {
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

export default function DriverFormPage() {
  const { id }      = useParams()
  const isEditing   = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const firstRef    = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['driver', id],
    queryFn:  () => getDriver(id),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const d = fetchedData.data
      setForm(
        Object.fromEntries(
          Object.keys(EMPTY_FORM).map((k) => [k, d[k] != null ? String(d[k]) : ''])
        )
      )
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => { firstRef.current?.focus() }, [])

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
      isEditing ? updateDriver(id, payload) : createDriver(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['drivers'] })
      queryClient.invalidateQueries({ queryKey: ['drivers-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['driver', id] })
      showSuccess(isEditing ? 'Driver updated successfully.' : 'Driver created successfully.')
      navigate('/inventory/drivers')
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
    const fields = Object.keys(EMPTY_FORM)
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f])]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(fields.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return

    const str = (v) => (typeof v === 'string' && v.trim() === '' ? null : typeof v === 'string' ? v.trim() : v)

    mutation.mutate({
      driver_code:         str(form.driver_code),
      first_name:          form.first_name.trim(),
      last_name:           str(form.last_name),
      nic_number:          str(form.nic_number),
      date_of_birth:       str(form.date_of_birth),
      license_number:      form.license_number.trim(),
      license_type:        str(form.license_type),
      license_expiry_date: str(form.license_expiry_date),
      phone:               str(form.phone),
      email:               str(form.email),
      address_line1:       str(form.address_line1),
      address_line2:       str(form.address_line2),
      city:                str(form.city),
      state:               str(form.state),
      country:             str(form.country),
      postal_code:         str(form.postal_code),
      hired_date:          str(form.hired_date),
      status:              form.status || 'active',
      notes:               str(form.notes),
    })
  }

  const crumbs = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Drivers',   to: '/inventory/drivers' },
    { label: isEditing ? 'Edit Driver' : 'New Driver' },
  ]

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  }

  const inp = (name, extra = {}) => ({
    name, value: form[name],
    onChange: handleChange, onBlur: handleBlur,
    className: fieldCls(errors, touched, name),
    autoComplete: 'off', ...extra,
  })

  return (
    <div className="w-full">
      <div className="mb-2">
        <h1 className="text-xl font-bold leading-none text-slate-800">
          {isEditing ? 'Edit Driver' : 'New Driver'}
        </h1>
        <Breadcrumb crumbs={crumbs} />
      </div>
      <form onSubmit={handleSubmit} noValidate>
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">

          {/* ── LEFT column ─────────────────────────────────────────────── */}
          <div className="space-y-2">

            {/* Identity */}
            <SectionCard icon={Car} title="Identity" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100">
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                <div>
                  <Label required>Driver Code</Label>
                  <input ref={firstRef} type="text" placeholder="DRV-001" maxLength={20} {...inp('driver_code')} />
                  <FieldError errors={errors} touched={touched} name="driver_code" />
                </div>
                <div>
                  <Label>NIC Number</Label>
                  <input type="text" placeholder="NIC / ID no." maxLength={50} {...inp('nic_number')} />
                  <FieldError errors={errors} touched={touched} name="nic_number" />
                </div>
                <div>
                  <Label>Date of Birth</Label>
                  <input type="date" {...inp('date_of_birth')} />
                  <FieldError errors={errors} touched={touched} name="date_of_birth" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label required>First Name</Label>
                  <input type="text" placeholder="First name" maxLength={100} {...inp('first_name')} />
                  <FieldError errors={errors} touched={touched} name="first_name" />
                </div>
                <div>
                  <Label>Last Name</Label>
                  <input type="text" placeholder="Last name" maxLength={100} {...inp('last_name')} />
                  <FieldError errors={errors} touched={touched} name="last_name" />
                </div>
              </div>
            </SectionCard>

            {/* Licence */}
            <SectionCard icon={ShieldCheck} title="Licence" colorClass="text-amber-700 bg-amber-50 border-amber-100">
              <div className="grid grid-cols-3 gap-2">
                <div>
                  <Label required>Licence Number</Label>
                  <input type="text" placeholder="B1234567" maxLength={50} {...inp('license_number')} />
                  <FieldError errors={errors} touched={touched} name="license_number" />
                </div>
                <div>
                  <Label>Licence Type</Label>
                  <select {...inp('license_type')}>
                    <option value="">— Select —</option>
                    {LICENSE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                  <FieldError errors={errors} touched={touched} name="license_type" />
                </div>
                <div>
                  <Label>Expiry Date</Label>
                  <input type="date" {...inp('license_expiry_date')} />
                  <FieldError errors={errors} touched={touched} name="license_expiry_date" />
                </div>
              </div>
            </SectionCard>

          </div>

          {/* ── RIGHT column ────────────────────────────────────────────── */}
          <div className="space-y-2">

            {/* Contact */}
            <SectionCard icon={Phone} title="Contact" colorClass="text-sky-700 bg-sky-50 border-sky-100">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Phone</Label>
                  <input type="tel" placeholder="+94 71 234 5678" maxLength={20} {...inp('phone')} />
                  <FieldError errors={errors} touched={touched} name="phone" />
                </div>
                <div>
                  <Label>Email</Label>
                  <input type="email" placeholder="driver@example.com" maxLength={100} {...inp('email')} />
                  <FieldError errors={errors} touched={touched} name="email" />
                </div>
              </div>
            </SectionCard>

            {/* Address */}
            <SectionCard icon={MapPin} title="Address" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Address Line 1</Label>
                  <input type="text" placeholder="Street / building" maxLength={150} {...inp('address_line1')} />
                  <FieldError errors={errors} touched={touched} name="address_line1" />
                </div>
                <div>
                  <Label>Address Line 2</Label>
                  <input type="text" placeholder="Apt / floor" maxLength={150} {...inp('address_line2')} />
                  <FieldError errors={errors} touched={touched} name="address_line2" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                <div>
                  <Label>City</Label>
                  <input type="text" placeholder="City" maxLength={100} {...inp('city')} />
                  <FieldError errors={errors} touched={touched} name="city" />
                </div>
                <div>
                  <Label>State</Label>
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

            {/* Employment */}
            <SectionCard icon={FileText} title="Employment" colorClass="text-violet-700 bg-violet-50 border-violet-100">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Hired Date</Label>
                  <input type="date" {...inp('hired_date')} />
                  <FieldError errors={errors} touched={touched} name="hired_date" />
                </div>
                <div>
                  <Label>Status</Label>
                  <select {...inp('status')}>
                    {STATUSES.map((s) => (
                      <option key={s} value={s} className="capitalize">{s.charAt(0).toUpperCase() + s.slice(1)}</option>
                    ))}
                  </select>
                  <FieldError errors={errors} touched={touched} name="status" />
                </div>
              </div>
              <div>
                <Label>Notes</Label>
                <textarea
                  rows={2}
                  placeholder="Additional notes…"
                  {...inp('notes')}
                  className={`${fieldCls(errors, touched, 'notes')} resize-none`}
                />
                <FieldError errors={errors} touched={touched} name="notes" />
              </div>
            </SectionCard>

          </div>
        </div>

        {/* ── Footer ──────────────────────────────────────────────────────── */}
        <div className="mt-2 flex items-center justify-end gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5">
          <Link
            to="/inventory/drivers"
            className="rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100"
          >
            Cancel
          </Link>
          <button
            type="submit"
            disabled={mutation.isPending}
            className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <Save size={12} strokeWidth={2.5} />
            {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Driver'}
          </button>
        </div>

        {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
          <p className="mt-1 text-[10px] text-red-600">
            {mutation.error?.response?.data?.message ?? 'An unexpected error occurred. Please try again.'}
          </p>
        )}
      </form>
    </div>
  )
}
