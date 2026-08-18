import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Car, FileText, Save, Settings, ShieldCheck, Truck, UserCheck } from 'lucide-react'
import { createVehicle, getVehicle, updateVehicle } from '../../api/vehicles'
import { getAllDrivers } from '../../api/drivers'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'

const VEHICLE_TYPES = ['Car', 'Van', 'Truck', 'Bus', 'Motorcycle', 'Heavy Truck']
const FUEL_TYPES    = ['Petrol', 'Diesel', 'Electric', 'Hybrid', 'CNG']
const STATUSES      = [
  { value: 'active',            label: 'Active' },
  { value: 'inactive',          label: 'Inactive' },
  { value: 'under_maintenance', label: 'Under Maintenance' },
]

const CURRENT_YEAR = new Date().getFullYear()

const EMPTY_FORM = {
  vehicle_code:               '',
  registration_number:        '',
  make:                       '',
  model:                      '',
  year:                       '',
  color:                      '',
  vehicle_type:               '',
  fuel_type:                  '',
  engine_number:              '',
  chassis_number:             '',
  seating_capacity:           '',
  payload_capacity:           '',
  insurance_policy_no:        '',
  insurance_expiry_date:      '',
  road_tax_expiry_date:       '',
  emission_test_expiry_date:  '',
  assigned_driver_id:         '',
  status:                     'active',
  notes:                      '',
}

const REQUIRED = new Set(['vehicle_code', 'registration_number'])

const LABELS = {
  vehicle_code:        'Vehicle Code',
  registration_number: 'Registration Number',
}

function validate(field, value) {
  const v = typeof value === 'string' ? value.trim() : value
  if (REQUIRED.has(field) && !v) return `${LABELS[field]} is required.`
  switch (field) {
    case 'vehicle_code':
      if (v && v.length > 20) return 'Max 20 characters.'
      break
    case 'registration_number':
      if (v && v.length > 50) return 'Max 50 characters.'
      break
    case 'year':
      if (v && (isNaN(Number(v)) || Number(v) < 1900 || Number(v) > CURRENT_YEAR + 1))
        return `Enter a year between 1900 and ${CURRENT_YEAR + 1}.`
      break
    case 'seating_capacity':
      if (v && (isNaN(Number(v)) || !Number.isInteger(Number(v)) || Number(v) < 1))
        return 'Enter a whole number ≥ 1.'
      break
    case 'payload_capacity':
      if (v && (isNaN(Number(v)) || Number(v) < 0))
        return 'Enter a number ≥ 0.'
      break
    case 'insurance_expiry_date':
    case 'road_tax_expiry_date':
    case 'emission_test_expiry_date':
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

export default function VehicleFormPage() {
  const { id }      = useParams()
  const isEditing   = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const codeRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  // Drivers dropdown
  const { data: driversData } = useQuery({
    queryKey: ['drivers-all'],
    queryFn:  getAllDrivers,
  })
  const drivers = driversData ?? []

  // Load existing record for edit
  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['vehicle', id],
    queryFn:  () => getVehicle(id),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const v = fetchedData.data
      setForm(
        Object.fromEntries(
          Object.keys(EMPTY_FORM).map((k) => [k, v[k] != null ? String(v[k]) : ''])
        )
      )
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => { codeRef.current?.focus() }, [])

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
      isEditing ? updateVehicle(id, payload) : createVehicle(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['vehicles'] })
      queryClient.invalidateQueries({ queryKey: ['vehicles-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['vehicle', id] })
      showSuccess(isEditing ? 'Vehicle updated successfully.' : 'Vehicle created successfully.')
      navigate('/inventory/vehicles')
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
    const fields    = Object.keys(EMPTY_FORM)
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f])]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(fields.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return

    const str  = (v) => (typeof v === 'string' && v.trim() === '' ? null : typeof v === 'string' ? v.trim() : v)
    const num  = (v) => (v === '' || v === null || v === undefined ? null : Number(v))
    const nint = (v) => (v === '' || v === null || v === undefined ? null : parseInt(v, 10))

    mutation.mutate({
      vehicle_code:               form.vehicle_code.trim(),
      registration_number:        form.registration_number.trim(),
      make:                       str(form.make),
      model:                      str(form.model),
      year:                       nint(form.year),
      color:                      str(form.color),
      vehicle_type:               str(form.vehicle_type),
      fuel_type:                  str(form.fuel_type),
      engine_number:              str(form.engine_number),
      chassis_number:             str(form.chassis_number),
      seating_capacity:           nint(form.seating_capacity),
      payload_capacity:           num(form.payload_capacity),
      insurance_policy_no:        str(form.insurance_policy_no),
      insurance_expiry_date:      str(form.insurance_expiry_date),
      road_tax_expiry_date:       str(form.road_tax_expiry_date),
      emission_test_expiry_date:  str(form.emission_test_expiry_date),
      assigned_driver_id:         nint(form.assigned_driver_id),
      status:                     form.status || 'active',
      notes:                      str(form.notes),
    })
  }

  const crumbs = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Vehicles',  to: '/inventory/vehicles' },
    { label: isEditing ? 'Edit Vehicle' : 'New Vehicle' },
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
          {isEditing ? 'Edit Vehicle' : 'New Vehicle'}
        </h1>
        <Breadcrumb crumbs={crumbs} />
      </div>
      <form onSubmit={handleSubmit} noValidate>
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">

          {/* ── LEFT column ─────────────────────────────────────────────── */}
          <div className="space-y-2">

            {/* Identity */}
            <SectionCard icon={Car} title="Identity" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label required>Vehicle Code</Label>
                  <input ref={codeRef} type="text" placeholder="VHL-001" maxLength={20} {...inp('vehicle_code')} />
                  <FieldError errors={errors} touched={touched} name="vehicle_code" />
                </div>
                <div>
                  <Label required>Registration Number</Label>
                  <input type="text" placeholder="ABC-1234" maxLength={50} {...inp('registration_number')} />
                  <FieldError errors={errors} touched={touched} name="registration_number" />
                </div>
              </div>
            </SectionCard>

            {/* Specifications */}
            <SectionCard icon={Settings} title="Specifications" colorClass="text-sky-700 bg-sky-50 border-sky-100">
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                <div>
                  <Label>Make</Label>
                  <input type="text" placeholder="Toyota" maxLength={100} {...inp('make')} />
                  <FieldError errors={errors} touched={touched} name="make" />
                </div>
                <div>
                  <Label>Model</Label>
                  <input type="text" placeholder="Corolla" maxLength={100} {...inp('model')} />
                  <FieldError errors={errors} touched={touched} name="model" />
                </div>
                <div>
                  <Label>Year</Label>
                  <input type="number" placeholder={String(CURRENT_YEAR)} min={1900} max={CURRENT_YEAR + 1} {...inp('year')} />
                  <FieldError errors={errors} touched={touched} name="year" />
                </div>
                <div>
                  <Label>Color</Label>
                  <input type="text" placeholder="White" maxLength={50} {...inp('color')} />
                  <FieldError errors={errors} touched={touched} name="color" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Vehicle Type</Label>
                  <select {...inp('vehicle_type')}>
                    <option value="">— Select —</option>
                    {VEHICLE_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                  <FieldError errors={errors} touched={touched} name="vehicle_type" />
                </div>
                <div>
                  <Label>Fuel Type</Label>
                  <select {...inp('fuel_type')}>
                    <option value="">— Select —</option>
                    {FUEL_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                  <FieldError errors={errors} touched={touched} name="fuel_type" />
                </div>
              </div>
            </SectionCard>

            {/* Engine & Chassis */}
            <SectionCard icon={Truck} title="Engine & Chassis" colorClass="text-amber-700 bg-amber-50 border-amber-100">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Engine Number</Label>
                  <input type="text" placeholder="ENG-XXXXXXXXX" maxLength={100} {...inp('engine_number')} />
                  <FieldError errors={errors} touched={touched} name="engine_number" />
                </div>
                <div>
                  <Label>Chassis Number</Label>
                  <input type="text" placeholder="VIN/Chassis no." maxLength={100} {...inp('chassis_number')} />
                  <FieldError errors={errors} touched={touched} name="chassis_number" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Seating Capacity</Label>
                  <input type="number" placeholder="5" min={1} max={255} {...inp('seating_capacity')} />
                  <FieldError errors={errors} touched={touched} name="seating_capacity" />
                </div>
                <div>
                  <Label>Payload Capacity (t)</Label>
                  <input type="number" placeholder="1.5" min={0} step="0.01" {...inp('payload_capacity')} />
                  <FieldError errors={errors} touched={touched} name="payload_capacity" />
                </div>
              </div>
            </SectionCard>

          </div>

          {/* ── RIGHT column ────────────────────────────────────────────── */}
          <div className="space-y-2">

            {/* Assignment */}
            <SectionCard icon={UserCheck} title="Driver Assignment" colorClass="text-violet-700 bg-violet-50 border-violet-100">
              <div>
                <Label>Assigned Driver</Label>
                <select {...inp('assigned_driver_id')}>
                  <option value="">— Unassigned —</option>
                  {drivers.map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.name}{d.driver_code ? ` (${d.driver_code})` : ''}
                    </option>
                  ))}
                </select>
                <FieldError errors={errors} touched={touched} name="assigned_driver_id" />
              </div>
            </SectionCard>

            {/* Compliance */}
            <SectionCard icon={ShieldCheck} title="Compliance & Insurance" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Insurance Policy No.</Label>
                  <input type="text" placeholder="POL-XXXXXXX" maxLength={50} {...inp('insurance_policy_no')} />
                  <FieldError errors={errors} touched={touched} name="insurance_policy_no" />
                </div>
                <div>
                  <Label>Insurance Expiry</Label>
                  <input type="date" {...inp('insurance_expiry_date')} />
                  <FieldError errors={errors} touched={touched} name="insurance_expiry_date" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Road Tax Expiry</Label>
                  <input type="date" {...inp('road_tax_expiry_date')} />
                  <FieldError errors={errors} touched={touched} name="road_tax_expiry_date" />
                </div>
                <div>
                  <Label>Emission Test Expiry</Label>
                  <input type="date" {...inp('emission_test_expiry_date')} />
                  <FieldError errors={errors} touched={touched} name="emission_test_expiry_date" />
                </div>
              </div>
            </SectionCard>

            {/* Status & Notes */}
            <SectionCard icon={FileText} title="Status & Notes" colorClass="text-blue-700 bg-blue-50 border-blue-100">
              <div>
                <Label>Status</Label>
                <select {...inp('status')}>
                  {STATUSES.map((s) => (
                    <option key={s.value} value={s.value}>{s.label}</option>
                  ))}
                </select>
                <FieldError errors={errors} touched={touched} name="status" />
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
            to="/inventory/vehicles"
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
            {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Vehicle'}
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
