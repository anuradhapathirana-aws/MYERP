import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, ClipboardList, CreditCard, MapPin, Package, Phone, Save, Settings2 } from 'lucide-react'
import { createLocation, getLocation, updateLocation } from '../../api/locations'
import { getCompanies } from '../../api/companies'
import { getAllIndustries } from '../../api/industries'
import { getAllLocations } from '../../api/locations'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'

// ── Constants ──────────────────────────────────────────────────────────────────
const LOCATION_TYPES = ['Head Office', 'Branch', 'Warehouse', 'Retail Store', 'Distribution Center', 'Factory', 'Other']

const DATE_FORMATS   = ['M d, Y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'jan-02-2022']
const NUMBER_FORMATS = ['#,###.##', '#.###,##', '# ###.##']
const TIME_FORMATS   = ['H:i:s', 'h:i A', 'HH:mm:ss']
const TIMEZONES = [
  'Asia/Colombo', 'Asia/Kolkata', 'Asia/Singapore', 'Asia/Dubai',
  'Asia/Tokyo', 'Europe/London', 'Europe/Paris', 'America/New_York',
  'America/Los_Angeles', 'UTC',
]
const CURRENCIES = ['USD', 'EUR', 'GBP', 'AED', 'INR', 'LKR', 'SGD', 'JPY', 'AUD', 'CAD']
const COUNTRIES  = [
  'Sri Lanka', 'India', 'United States', 'United Kingdom', 'Australia',
  'Canada', 'Singapore', 'United Arab Emirates', 'Germany', 'France',
  'Japan', 'China', 'Bangladesh', 'Pakistan', 'Nepal', 'Other',
]
const ALL_MODULES = ['HRM', 'Account', 'CRM', 'Inventory']

const EMPTY_FORM = {
  // Basic Details
  company_id: '', industry_id: '', parent_location_id: '',
  location_code: '', location_name: '', location_type: '', country: '',
  // Location Address
  loc_street_address: '', loc_city: '', loc_country: '', loc_state: '', loc_postal_zip_code: '',
  // Billing Address
  billing_same_as_location: false,
  bill_street_address: '', bill_city: '', bill_country: '', bill_state: '', bill_postal_zip_code: '',
  // Contact
  company_email: '', customer_facing_email: '', company_phone: '', mobile: '',
  fax: '', website: '', longitude: '', latitude: '', map_url: '',
  // Advanced
  date_format: 'M d, Y', number_format: '#,###.##', time_format: 'H:i:s',
  float_precision: '3', base_currency: 'LKR', time_zone: 'Asia/Colombo',
  financial_year: '', open_hours_from: '09:00', open_hours_to: '18:00',
  // Modules & stock
  available_modules: [], stock_releasing_method: 'FIFO',
}

function validate(field, value, form) {
  switch (field) {
    case 'company_id':      if (!value) return 'Company is required.'; break
    case 'industry_id':     if (!value) return 'Industry is required.'; break
    case 'location_code':
      if (!String(value).trim()) return 'Location code is required.'
      if (String(value).length > 50) return 'Max 50 characters.'
      break
    case 'location_name':
      if (!String(value).trim()) return 'Location name is required.'
      if (String(value).length > 100) return 'Max 100 characters.'
      break
    case 'country':         if (!String(value).trim()) return 'Country is required.'; break
    case 'loc_street_address':
      if (!String(value).trim()) return 'Street address is required.'
      if (String(value).length > 150) return 'Max 150 characters.'
      break
    case 'loc_city':        if (!String(value).trim()) return 'City is required.'; break
    case 'loc_country':     if (!String(value).trim()) return 'Country is required.'; break
    case 'loc_state':       if (!String(value).trim()) return 'State is required.'; break
    case 'loc_postal_zip_code': if (!String(value).trim()) return 'Postal/Zip code is required.'; break
    case 'bill_city':
      if (!form?.billing_same_as_location && !String(value).trim()) return 'Billing city is required.'
      break
    case 'bill_country':
      if (!form?.billing_same_as_location && !String(value).trim()) return 'Billing country is required.'
      break
    case 'bill_postal_zip_code':
      if (!form?.billing_same_as_location && !String(value).trim()) return 'Billing postal/zip is required.'
      break
    case 'company_email':
    case 'customer_facing_email':
      if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim()))
        return 'Enter a valid email address.'
      break
    case 'website':
      if (value && !/^https?:\/\//.test(String(value).trim()))
        return 'Enter a valid URL (https://...).'
      break
    case 'base_currency':   if (!value) return 'Base currency is required.'; break
    case 'financial_year':  if (!String(value).trim()) return 'Financial year is required.'; break
    case 'stock_releasing_method': if (!value) return 'Stock releasing method is required.'; break
  }
  return ''
}

// ── Style helpers ──────────────────────────────────────────────────────────────
const inputBase = 'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr  = 'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const fieldCls = (errors, touched, name) => errors[name] && touched[name] ? inputErr : inputBase

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

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

function SectionHeader({ icon: Icon, title, colorClass }) {
  return (
    <div className={`flex items-center gap-1.5 px-3 py-2 border-b ${colorClass}`}>
      {Icon && <Icon size={13} />}
      <h2 className="text-xs font-bold">{title}</h2>
    </div>
  )
}

// ── Tab constants ──────────────────────────────────────────────────────────────
const TABS = [
  { label: 'Basic Details', icon: ClipboardList },
  { label: 'Contact Info',  icon: Phone },
]

export default function LocationFormPage() {
  const { id }      = useParams()
  const isEditing   = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const codeRef     = useRef(null)

  const [activeTab, setActiveTab] = useState(0)
  const [form,      setForm]      = useState(EMPTY_FORM)
  const [errors,    setErrors]    = useState({})
  const [touched,   setTouched]   = useState({})

  // ── Data fetches ──────────────────────────────────────────────────────────
  const { data: companiesData } = useQuery({
    queryKey: ['companies-all-list'],
    queryFn:  () => getCompanies(1).then((r) => r.data ?? []),
  })
  const companies = companiesData ?? []

  const { data: industriesData } = useQuery({
    queryKey: ['industries-all'],
    queryFn:  getAllIndustries,
  })
  const industries = industriesData ?? []

  const { data: locationsData } = useQuery({
    queryKey: ['locations-all'],
    queryFn:  getAllLocations,
  })
  const parentLocations = (locationsData ?? []).filter((l) => String(l.id) !== String(id))

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['location', id],
    queryFn:  () => getLocation(id),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const d = fetchedData.data
      setForm({
        company_id:                d.company_id              ? String(d.company_id)      : '',
        industry_id:               d.industry_id             ? String(d.industry_id)     : '',
        parent_location_id:        d.parent_location_id      ? String(d.parent_location_id) : '',
        location_code:             d.location_code           ?? '',
        location_name:             d.location_name           ?? '',
        location_type:             d.location_type           ?? '',
        country:                   d.country                 ?? '',
        loc_street_address:        d.loc_street_address      ?? '',
        loc_city:                  d.loc_city                ?? '',
        loc_country:               d.loc_country             ?? '',
        loc_state:                 d.loc_state               ?? '',
        loc_postal_zip_code:       d.loc_postal_zip_code     ?? '',
        billing_same_as_location:  d.billing_same_as_location ?? false,
        bill_street_address:       d.bill_street_address     ?? '',
        bill_city:                 d.bill_city               ?? '',
        bill_country:              d.bill_country            ?? '',
        bill_state:                d.bill_state              ?? '',
        bill_postal_zip_code:      d.bill_postal_zip_code    ?? '',
        company_email:             d.company_email           ?? '',
        customer_facing_email:     d.customer_facing_email   ?? '',
        company_phone:             d.company_phone           ?? '',
        mobile:                    d.mobile                  ?? '',
        fax:                       d.fax                     ?? '',
        website:                   d.website                 ?? '',
        longitude:                 d.longitude               ?? '',
        latitude:                  d.latitude                ?? '',
        map_url:                   d.map_url                 ?? '',
        date_format:               d.date_format             ?? 'M d, Y',
        number_format:             d.number_format           ?? '#,###.##',
        time_format:               d.time_format             ?? 'H:i:s',
        float_precision:           d.float_precision         != null ? String(d.float_precision) : '3',
        base_currency:             d.base_currency           ?? 'LKR',
        time_zone:                 d.time_zone               ?? 'Asia/Colombo',
        financial_year:            d.financial_year          ?? '',
        open_hours_from:           d.open_hours_from         ?? '09:00',
        open_hours_to:             d.open_hours_to           ?? '18:00',
        available_modules:         d.available_modules       ?? [],
        stock_releasing_method:    d.stock_releasing_method  ?? 'FIFO',
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => { codeRef.current?.focus() }, [])

  // ── Handlers ─────────────────────────────────────────────────────────────
  const handleChange = (e) => {
    const { name, value, type, checked } = e.target
    const newVal = type === 'checkbox' ? checked : value
    setForm((prev) => {
      const next = { ...prev, [name]: newVal }
      if (name === 'billing_same_as_location' && checked) {
        next.bill_street_address  = prev.loc_street_address
        next.bill_city            = prev.loc_city
        next.bill_country         = prev.loc_country
        next.bill_state           = prev.loc_state
        next.bill_postal_zip_code = prev.loc_postal_zip_code
      }
      return next
    })
    if (touched[name]) setErrors((prev) => ({ ...prev, [name]: validate(name, newVal, { ...form, [name]: newVal }) }))
  }

  const handleModuleToggle = (mod) => {
    setForm((prev) => {
      const mods = prev.available_modules.includes(mod)
        ? prev.available_modules.filter((m) => m !== mod)
        : [...prev.available_modules, mod]
      return { ...prev, available_modules: mods }
    })
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validate(name, value, form) }))
  }

  const mutation = useMutation({
    mutationFn: (payload) =>
      isEditing ? updateLocation(id, payload) : createLocation(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['locations'] })
      queryClient.invalidateQueries({ queryKey: ['locations-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['location', id] })
      showSuccess(isEditing ? 'Location updated successfully.' : 'Location created successfully.')
      navigate('/inventory/locations')
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
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f], form)]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(fields.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return

    mutation.mutate({
      company_id:                Number(form.company_id),
      industry_id:               Number(form.industry_id),
      parent_location_id:        form.parent_location_id ? Number(form.parent_location_id) : null,
      location_code:             form.location_code.trim(),
      location_name:             form.location_name.trim(),
      location_type:             form.location_type.trim()  || null,
      country:                   form.country.trim(),
      loc_street_address:        form.loc_street_address.trim(),
      loc_city:                  form.loc_city.trim(),
      loc_country:               form.loc_country,
      loc_state:                 form.loc_state.trim(),
      loc_postal_zip_code:       form.loc_postal_zip_code.trim(),
      billing_same_as_location:  form.billing_same_as_location,
      bill_street_address:       form.bill_street_address.trim() || null,
      bill_city:                 form.billing_same_as_location ? null : (form.bill_city.trim() || null),
      bill_country:              form.billing_same_as_location ? null : (form.bill_country || null),
      bill_state:                form.bill_state.trim()         || null,
      bill_postal_zip_code:      form.billing_same_as_location ? null : (form.bill_postal_zip_code.trim() || null),
      company_email:             form.company_email.trim()          || null,
      customer_facing_email:     form.customer_facing_email.trim()  || null,
      company_phone:             form.company_phone.trim()          || null,
      mobile:                    form.mobile.trim()                 || null,
      fax:                       form.fax.trim()                    || null,
      website:                   form.website.trim()                || null,
      longitude:                 form.longitude !== '' ? Number(form.longitude) : null,
      latitude:                  form.latitude  !== '' ? Number(form.latitude)  : null,
      map_url:                   form.map_url.trim()                || null,
      date_format:               form.date_format   || null,
      number_format:             form.number_format || null,
      time_format:               form.time_format   || null,
      float_precision:           form.float_precision !== '' ? Number(form.float_precision) : null,
      base_currency:             form.base_currency,
      time_zone:                 form.time_zone || null,
      financial_year:            form.financial_year.trim(),
      open_hours_from:           form.open_hours_from || null,
      open_hours_to:             form.open_hours_to   || null,
      available_modules:         form.available_modules,
      stock_releasing_method:    form.stock_releasing_method,
    })
  }

  const handleClear = () => {
    setForm(EMPTY_FORM)
    setErrors({})
    setTouched({})
  }

  const BASIC_DETAILS_FIELDS = [
    'company_id', 'industry_id', 'location_code', 'location_name', 'country',
    'loc_street_address', 'loc_city', 'loc_country', 'loc_state', 'loc_postal_zip_code',
    'bill_city', 'bill_country', 'bill_postal_zip_code',
  ]

  const handleNext = () => {
    const newErrors = Object.fromEntries(
      BASIC_DETAILS_FIELDS.map((f) => [f, validate(f, form[f], form)])
    )
    setErrors((prev) => ({ ...prev, ...newErrors }))
    setTouched((prev) => ({ ...prev, ...Object.fromEntries(BASIC_DETAILS_FIELDS.map((f) => [f, true])) }))
    if (Object.values(newErrors).some(Boolean)) return
    setActiveTab(1)
  }

  const crumbs = [
    { label: 'Inventory',  to: '/inventory/products' },
    { label: 'Locations',  to: '/inventory/locations' },
    { label: isEditing ? 'Edit Location' : 'New Location' },
  ]

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  }

  return (
    <div className="w-full">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-bold leading-none text-slate-800">
          {isEditing ? 'Edit Location' : 'Add New Location'}
        </h1>
        <Breadcrumb crumbs={crumbs} />
      </div>
      <form onSubmit={handleSubmit} noValidate>
        {/* ── Tab bar ── */}
        <div className="mb-2 inline-flex gap-1 rounded-lg bg-slate-100 p-1 shadow-inner">
          {TABS.map((tab, idx) => (
            <button
              key={tab.label}
              type="button"
              onClick={() => setActiveTab(idx)}
              className={[
                'flex items-center gap-1.5 rounded-md px-4 py-1.5 text-xs font-semibold transition-all duration-150',
                activeTab === idx
                  ? 'bg-white text-indigo-600 shadow-sm ring-1 ring-slate-200'
                  : 'text-slate-500 hover:text-slate-700',
              ].join(' ')}
            >
              <tab.icon size={12} strokeWidth={2.5} />
              {tab.label}
            </button>
          ))}
        </div>

        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">

          {/* ── Tab 1: Basic Details ── */}
          {activeTab === 0 && (
            <div className="p-2.5 space-y-2">

              {/* Row 1 */}
              <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                <div>
                  <Label required>Company Name</Label>
                  <select
                    name="company_id"
                    value={form.company_id}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    className={fieldCls(errors, touched, 'company_id')}
                  >
                    <option value="">Company</option>
                    {companies.map((c) => (
                      <option key={c.id} value={c.id}>{c.company_name}</option>
                    ))}
                  </select>
                  <FieldError errors={errors} touched={touched} name="company_id" />
                </div>

                <div>
                  <Label required>Industry Name</Label>
                  <select
                    name="industry_id"
                    value={form.industry_id}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    className={fieldCls(errors, touched, 'industry_id')}
                  >
                    <option value="">Industry</option>
                    {industries.map((ind) => (
                      <option key={ind.id} value={ind.id}>{ind.name}</option>
                    ))}
                  </select>
                  <FieldError errors={errors} touched={touched} name="industry_id" />
                </div>

                <div>
                  <Label>Parent Location</Label>
                  <select
                    name="parent_location_id"
                    value={form.parent_location_id}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    className={fieldCls(errors, touched, 'parent_location_id')}
                  >
                    <option value="">— None —</option>
                    {parentLocations.map((l) => (
                      <option key={l.id} value={l.id}>{l.location_name}</option>
                    ))}
                  </select>
                  <FieldError errors={errors} touched={touched} name="parent_location_id" />
                </div>

                <div>
                  <Label>Location Type</Label>
                  <select
                    name="location_type"
                    value={form.location_type}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    className={fieldCls(errors, touched, 'location_type')}
                  >
                    <option value="">Company Type</option>
                    {LOCATION_TYPES.map((t) => (
                      <option key={t} value={t}>{t}</option>
                    ))}
                  </select>
                  <FieldError errors={errors} touched={touched} name="location_type" />
                </div>
              </div>

              {/* Row 2 */}
              <div className="grid grid-cols-2 gap-2 md:grid-cols-3">
                <div>
                  <Label required>Location Code</Label>
                  <input
                    ref={codeRef}
                    name="location_code"
                    type="text"
                    value={form.location_code}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="e.g. LOC-001"
                    maxLength={50}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'location_code')}
                  />
                  <FieldError errors={errors} touched={touched} name="location_code" />
                </div>

                <div>
                  <Label required>Location Name</Label>
                  <input
                    name="location_name"
                    type="text"
                    value={form.location_name}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="e.g. Colombo Head Office"
                    maxLength={100}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'location_name')}
                  />
                  <FieldError errors={errors} touched={touched} name="location_name" />
                </div>

                <div>
                  <Label required>Country</Label>
                  <select
                    name="country"
                    value={form.country}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    className={fieldCls(errors, touched, 'country')}
                  >
                    <option value="">— Select —</option>
                    {COUNTRIES.map((c) => <option key={c} value={c}>{c}</option>)}
                  </select>
                  <FieldError errors={errors} touched={touched} name="country" />
                </div>
              </div>

              {/* Address cards — two column */}
              <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">

                {/* Location Address */}
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                  <SectionHeader icon={MapPin} title="Location Address" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100" />
                  <div className="p-2.5 space-y-2">
                    <div>
                      <Label required>Street Address</Label>
                      <input
                        name="loc_street_address"
                        type="text"
                        value={form.loc_street_address}
                        onChange={handleChange}
                        onBlur={handleBlur}
                        placeholder="Street Address"
                        maxLength={150}
                        autoComplete="off"
                        className={fieldCls(errors, touched, 'loc_street_address')}
                      />
                      <FieldError errors={errors} touched={touched} name="loc_street_address" />
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                      <div>
                        <Label required>City</Label>
                        <input
                          name="loc_city"
                          type="text"
                          value={form.loc_city}
                          onChange={handleChange}
                          onBlur={handleBlur}
                          placeholder="City"
                          maxLength={50}
                          autoComplete="off"
                          className={fieldCls(errors, touched, 'loc_city')}
                        />
                        <FieldError errors={errors} touched={touched} name="loc_city" />
                      </div>
                      <div>
                        <Label required>Country</Label>
                        <select
                          name="loc_country"
                          value={form.loc_country}
                          onChange={handleChange}
                          onBlur={handleBlur}
                          className={fieldCls(errors, touched, 'loc_country')}
                        >
                          <option value="">Select</option>
                          {COUNTRIES.map((c) => <option key={c} value={c}>{c}</option>)}
                        </select>
                        <FieldError errors={errors} touched={touched} name="loc_country" />
                      </div>
                      <div>
                        <Label required>State</Label>
                        <input
                          name="loc_state"
                          type="text"
                          value={form.loc_state}
                          onChange={handleChange}
                          onBlur={handleBlur}
                          placeholder="State"
                          maxLength={50}
                          autoComplete="off"
                          className={fieldCls(errors, touched, 'loc_state')}
                        />
                        <FieldError errors={errors} touched={touched} name="loc_state" />
                      </div>
                      <div>
                        <Label required>Postal/Zip Code</Label>
                        <input
                          name="loc_postal_zip_code"
                          type="text"
                          value={form.loc_postal_zip_code}
                          onChange={handleChange}
                          onBlur={handleBlur}
                          placeholder="Postal/Zip Code"
                          maxLength={20}
                          autoComplete="off"
                          className={fieldCls(errors, touched, 'loc_postal_zip_code')}
                        />
                        <FieldError errors={errors} touched={touched} name="loc_postal_zip_code" />
                      </div>
                    </div>
                  </div>
                </div>

                {/* Billing Address */}
                <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                  <SectionHeader icon={CreditCard} title="Billing Address" colorClass="text-amber-700 bg-amber-50 border-amber-100" />
                  <div className="p-2.5 space-y-2">
                    <div>
                      <Label>Street Address</Label>
                      <input
                        name="bill_street_address"
                        type="text"
                        value={form.billing_same_as_location ? form.loc_street_address : form.bill_street_address}
                        onChange={handleChange}
                        onBlur={handleBlur}
                        placeholder="Street Address"
                        maxLength={150}
                        disabled={form.billing_same_as_location}
                        autoComplete="off"
                        className={`${fieldCls(errors, touched, 'bill_street_address')} ${form.billing_same_as_location ? 'bg-slate-50 text-slate-400' : ''}`}
                      />
                    </div>
                    <div className="grid grid-cols-2 gap-2">
                      <div>
                        <Label required={!form.billing_same_as_location}>City</Label>
                        <input
                          name="bill_city"
                          type="text"
                          value={form.billing_same_as_location ? form.loc_city : form.bill_city}
                          onChange={handleChange}
                          onBlur={handleBlur}
                          placeholder="City"
                          maxLength={50}
                          disabled={form.billing_same_as_location}
                          autoComplete="off"
                          className={`${fieldCls(errors, touched, 'bill_city')} ${form.billing_same_as_location ? 'bg-slate-50 text-slate-400' : ''}`}
                        />
                        <FieldError errors={errors} touched={touched} name="bill_city" />
                      </div>
                      <div>
                        <Label required={!form.billing_same_as_location}>Country</Label>
                        <select
                          name="bill_country"
                          value={form.billing_same_as_location ? form.loc_country : form.bill_country}
                          onChange={handleChange}
                          onBlur={handleBlur}
                          disabled={form.billing_same_as_location}
                          className={`${fieldCls(errors, touched, 'bill_country')} ${form.billing_same_as_location ? 'bg-slate-50 text-slate-400' : ''}`}
                        >
                          <option value="">Select</option>
                          {COUNTRIES.map((c) => <option key={c} value={c}>{c}</option>)}
                        </select>
                        <FieldError errors={errors} touched={touched} name="bill_country" />
                      </div>
                      <div>
                        <Label>State</Label>
                        <input
                          name="bill_state"
                          type="text"
                          value={form.billing_same_as_location ? form.loc_state : form.bill_state}
                          onChange={handleChange}
                          onBlur={handleBlur}
                          placeholder="State"
                          maxLength={50}
                          disabled={form.billing_same_as_location}
                          autoComplete="off"
                          className={`${fieldCls(errors, touched, 'bill_state')} ${form.billing_same_as_location ? 'bg-slate-50 text-slate-400' : ''}`}
                        />
                      </div>
                      <div>
                        <Label required={!form.billing_same_as_location}>Postal/Zip Code</Label>
                        <input
                          name="bill_postal_zip_code"
                          type="text"
                          value={form.billing_same_as_location ? form.loc_postal_zip_code : form.bill_postal_zip_code}
                          onChange={handleChange}
                          onBlur={handleBlur}
                          placeholder="Postal/Zip Code"
                          maxLength={20}
                          disabled={form.billing_same_as_location}
                          autoComplete="off"
                          className={`${fieldCls(errors, touched, 'bill_postal_zip_code')} ${form.billing_same_as_location ? 'bg-slate-50 text-slate-400' : ''}`}
                        />
                        <FieldError errors={errors} touched={touched} name="bill_postal_zip_code" />
                      </div>
                    </div>

                    <label className="flex cursor-pointer items-center gap-2 text-xs text-slate-600">
                      <input
                        type="checkbox"
                        name="billing_same_as_location"
                        checked={form.billing_same_as_location}
                        onChange={handleChange}
                        className="h-3.5 w-3.5 rounded border-slate-300 accent-indigo-600"
                      />
                      Same as Location address
                    </label>
                  </div>
                </div>

              </div>
            </div>
          )}

          {/* ── Tab 2: Contact Info ── */}
          {activeTab === 1 && (
            <div className="p-2.5 space-y-2">

              {/* Contact fields — 4 col */}
              <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                <div>
                  <Label>Company email</Label>
                  <input name="company_email" type="email" value={form.company_email}
                    onChange={handleChange} onBlur={handleBlur} placeholder="company@example.com"
                    maxLength={100} autoComplete="off" className={fieldCls(errors, touched, 'company_email')} />
                  <FieldError errors={errors} touched={touched} name="company_email" />
                </div>
                <div>
                  <Label>Customer-facing email</Label>
                  <input name="customer_facing_email" type="email" value={form.customer_facing_email}
                    onChange={handleChange} onBlur={handleBlur} placeholder="sales@example.com"
                    maxLength={100} autoComplete="off" className={fieldCls(errors, touched, 'customer_facing_email')} />
                  <FieldError errors={errors} touched={touched} name="customer_facing_email" />
                </div>
                <div>
                  <Label>Company phone</Label>
                  <input name="company_phone" type="text" value={form.company_phone}
                    onChange={handleChange} onBlur={handleBlur} placeholder="+94 11 234 5678"
                    maxLength={30} autoComplete="off" className={fieldCls(errors, touched, 'company_phone')} />
                </div>
                <div>
                  <Label>Mobile</Label>
                  <input name="mobile" type="text" value={form.mobile}
                    onChange={handleChange} onBlur={handleBlur} placeholder="+94 77 123 4567"
                    maxLength={30} autoComplete="off" className={fieldCls(errors, touched, 'mobile')} />
                </div>
                <div>
                  <Label>Fax</Label>
                  <input name="fax" type="text" value={form.fax}
                    onChange={handleChange} onBlur={handleBlur} placeholder="Fax number"
                    maxLength={30} autoComplete="off" className={fieldCls(errors, touched, 'fax')} />
                </div>
                <div>
                  <Label>Website</Label>
                  <input name="website" type="url" value={form.website}
                    onChange={handleChange} onBlur={handleBlur} placeholder="https://example.com"
                    maxLength={255} autoComplete="off" className={fieldCls(errors, touched, 'website')} />
                  <FieldError errors={errors} touched={touched} name="website" />
                </div>
                <div>
                  <Label>Longitude</Label>
                  <input name="longitude" type="number" value={form.longitude}
                    onChange={handleChange} onBlur={handleBlur} placeholder="e.g. 79.8612"
                    step="any" className={fieldCls(errors, touched, 'longitude')} />
                </div>
                <div>
                  <Label>Latitude</Label>
                  <input name="latitude" type="number" value={form.latitude}
                    onChange={handleChange} onBlur={handleBlur} placeholder="e.g. 6.9271"
                    step="any" className={fieldCls(errors, touched, 'latitude')} />
                </div>
              </div>

              {/* Map URL */}
              <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">
                <div>
                  <Label>Select Location / Enter URL</Label>
                  <input name="map_url" type="text" value={form.map_url}
                    onChange={handleChange} onBlur={handleBlur}
                    placeholder="https://maps.google.com/?q=..."
                    maxLength={500} autoComplete="off" className={fieldCls(errors, touched, 'map_url')} />
                </div>
                {form.map_url && (
                  <div className="flex h-14 items-center justify-center overflow-hidden rounded border border-slate-200 bg-slate-50">
                    <p className="text-xs text-slate-400 text-center px-2 truncate">{form.map_url}</p>
                  </div>
                )}
              </div>

              {/* Advanced Settings */}
              <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <SectionHeader icon={Settings2} title="Advanced Settings" colorClass="text-violet-700 bg-violet-50 border-violet-100" />
                <div className="p-2.5 space-y-2">
                  <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                    <div>
                      <Label>Date Format</Label>
                      <select name="date_format" value={form.date_format} onChange={handleChange}
                        className={fieldCls(errors, touched, 'date_format')}>
                        {DATE_FORMATS.map((f) => <option key={f} value={f}>{f}</option>)}
                      </select>
                    </div>
                    <div>
                      <Label>Number Format</Label>
                      <select name="number_format" value={form.number_format} onChange={handleChange}
                        className={fieldCls(errors, touched, 'number_format')}>
                        {NUMBER_FORMATS.map((f) => <option key={f} value={f}>{f}</option>)}
                      </select>
                    </div>
                    <div>
                      <Label>Time Format</Label>
                      <select name="time_format" value={form.time_format} onChange={handleChange}
                        className={fieldCls(errors, touched, 'time_format')}>
                        {TIME_FORMATS.map((f) => <option key={f} value={f}>{f}</option>)}
                      </select>
                    </div>
                    <div>
                      <Label>Float Precision</Label>
                      <select name="float_precision" value={form.float_precision} onChange={handleChange}
                        className={fieldCls(errors, touched, 'float_precision')}>
                        {[0,1,2,3,4,5,6].map((n) => <option key={n} value={n}>{n}</option>)}
                      </select>
                    </div>
                  </div>

                  <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                    <div>
                      <Label required>Base Currency</Label>
                      <select name="base_currency" value={form.base_currency}
                        onChange={handleChange} onBlur={handleBlur}
                        className={fieldCls(errors, touched, 'base_currency')}>
                        {CURRENCIES.map((c) => <option key={c} value={c}>{c}</option>)}
                      </select>
                      <FieldError errors={errors} touched={touched} name="base_currency" />
                    </div>
                    <div>
                      <Label>Time Zone</Label>
                      <select name="time_zone" value={form.time_zone} onChange={handleChange}
                        className={fieldCls(errors, touched, 'time_zone')}>
                        <option value="">— Select —</option>
                        {TIMEZONES.map((tz) => <option key={tz} value={tz}>{tz}</option>)}
                      </select>
                    </div>
                    <div>
                      <Label required>Financial Year</Label>
                      <select name="financial_year" value={form.financial_year}
                        onChange={handleChange} onBlur={handleBlur}
                        className={fieldCls(errors, touched, 'financial_year')}>
                        <option value="">— Select —</option>
                        {['Jan-Dec', 'Apr-Mar', 'Jul-Jun', 'Oct-Sep'].map((y) => (
                          <option key={y} value={y}>{y}</option>
                        ))}
                      </select>
                      <FieldError errors={errors} touched={touched} name="financial_year" />
                    </div>
                    <div>
                      <Label>Open Hours</Label>
                      <div className="flex items-center gap-1.5">
                        <span className="text-xs text-slate-500 shrink-0">From</span>
                        <input name="open_hours_from" type="time" value={form.open_hours_from}
                          onChange={handleChange}
                          className="block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15" />
                        <span className="text-xs text-slate-500 shrink-0">To:</span>
                        <input name="open_hours_to" type="time" value={form.open_hours_to}
                          onChange={handleChange}
                          className="block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15" />
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Modules & Stock */}
              <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <SectionHeader icon={Package} title="Modules & Stock" colorClass="text-blue-700 bg-blue-50 border-blue-100" />
                <div className="grid grid-cols-1 gap-2 p-2.5 lg:grid-cols-2">

                  {/* Available Modules */}
                  <div>
                    <Label>Available Modules</Label>
                    <div className="mt-1 flex flex-wrap gap-2">
                      {ALL_MODULES.map((mod) => (
                        <label key={mod} className="flex cursor-pointer items-center gap-1.5 text-xs text-slate-700">
                          <input
                            type="checkbox"
                            checked={form.available_modules.includes(mod)}
                            onChange={() => handleModuleToggle(mod)}
                            className="h-3.5 w-3.5 rounded border-slate-300 accent-indigo-600"
                          />
                          {mod}
                        </label>
                      ))}
                    </div>
                  </div>

                  {/* Stock Releasing Method */}
                  <div>
                    <Label required>Stock Releasing Method</Label>
                    <div className="mt-1 flex gap-4">
                      {['LIFO', 'FIFO', 'AVG'].map((method) => (
                        <label key={method} className="flex cursor-pointer items-center gap-1.5 text-xs text-slate-700">
                          <input
                            type="radio"
                            name="stock_releasing_method"
                            value={method}
                            checked={form.stock_releasing_method === method}
                            onChange={handleChange}
                            onBlur={handleBlur}
                            className="h-3.5 w-3.5 accent-indigo-600"
                          />
                          {method}
                        </label>
                      ))}
                    </div>
                    <FieldError errors={errors} touched={touched} name="stock_releasing_method" />
                  </div>

                </div>
              </div>

            </div>
          )}
        </div>

        {/* ── Footer ── */}
        <div className="mt-2 flex items-center justify-between rounded-lg border border-slate-200 bg-white px-3 py-1.5">
          <p className="text-[10px] text-slate-400">* Required</p>
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={handleClear}
              className="rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100"
            >
              Clear
            </button>
            <Link
              to="/inventory/locations"
              className="rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100"
            >
              Cancel
            </Link>
            {!isEditing && activeTab === 0 ? (
              <button
                type="button"
                onClick={handleNext}
                className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700"
              >
                Next
                <ArrowRight size={12} strokeWidth={2.5} />
              </button>
            ) : (
              <button
                type="submit"
                disabled={mutation.isPending}
                className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
              >
                <Save size={12} strokeWidth={2.5} />
                {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create & Close'}
              </button>
            )}
          </div>
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
