import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Building2, MapPin, Phone, Save } from 'lucide-react'
import { createCompany, getCompany, updateCompany } from '../../api/companies'
import { getAllIndustries } from '../../api/industries'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'

const COMPANY_TYPES = [
  'Manufacturer',
  'Distributor',
  'Retailer',
  'Wholesaler',
  'Service Provider',
  'Importer',
  'Exporter',
  'Contractor',
  'Consultant',
  'Other',
]

const EMPTY_FORM = {
  company_type:    '',
  company_name:    '',
  registration_no: '',
  tax_reg_no:      '',
  street_address:  '',
  city:            '',
  country:         '',
  state:           '',
  postal_zip_code: '',
  company_email:   '',
  company_email_2: '',
  company_mobile:  '',
  industry_id:     '',
}

function validate(field, value) {
  switch (field) {
    case 'company_type':
      if (!String(value).trim()) return 'Company type is required.'
      break
    case 'company_name':
      if (!String(value).trim()) return 'Company name is required.'
      if (String(value).length > 100) return 'Max 100 characters.'
      break
    case 'street_address':
      if (!String(value).trim()) return 'Street address is required.'
      if (String(value).length > 100) return 'Max 100 characters.'
      break
    case 'industry_id':
      if (!value) return 'Industry is required.'
      break
    case 'company_email':
    case 'company_email_2':
      if (value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value).trim()))
        return 'Enter a valid email address.'
      break
    case 'registration_no':
      if (String(value).length > 50) return 'Max 50 characters.'
      break
    case 'tax_reg_no':
      if (String(value).length > 50) return 'Max 50 characters.'
      break
    case 'city':
    case 'country':
    case 'state':
      if (String(value).length > 50) return 'Max 50 characters.'
      break
    case 'postal_zip_code':
      if (String(value).length > 20) return 'Max 20 characters.'
      break
    case 'company_mobile':
      if (String(value).length > 20) return 'Max 20 characters.'
      break
  }
  return ''
}

const inputBase =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const inputErr =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

const fieldCls = (errors, touched, name) =>
  errors[name] && touched[name] ? inputErr : inputBase

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

export default function CompanyFormPage() {
  const { id }      = useParams()
  const isEditing   = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)

  const [form,    setForm]    = useState(EMPTY_FORM)
  const [errors,  setErrors]  = useState({})
  const [touched, setTouched] = useState({})

  const { data: industriesData } = useQuery({
    queryKey: ['industries-all'],
    queryFn:  getAllIndustries,
  })
  const industries = industriesData ?? []

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['company', id],
    queryFn:  () => getCompany(id),
    enabled:  isEditing,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const c = fetchedData.data
      setForm({
        company_type:    c.company_type    ?? '',
        company_name:    c.company_name    ?? '',
        registration_no: c.registration_no ?? '',
        tax_reg_no:      c.tax_reg_no      ?? '',
        street_address:  c.street_address  ?? '',
        city:            c.city            ?? '',
        country:         c.country         ?? '',
        state:           c.state           ?? '',
        postal_zip_code: c.postal_zip_code ?? '',
        company_email:   c.company_email   ?? '',
        company_email_2: c.company_email_2 ?? '',
        company_mobile:  c.company_mobile  ?? '',
        industry_id:     c.industry_id     ? String(c.industry_id) : '',
      })
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => { nameRef.current?.focus() }, [])

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
      isEditing ? updateCompany(id, payload) : createCompany(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['companies'] })
      queryClient.invalidateQueries({ queryKey: ['companies-all'] })
      queryClient.invalidateQueries({ queryKey: ['companies-all-list'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['company', id] })
      showSuccess(isEditing ? 'Company updated successfully.' : 'Company created successfully.')
      navigate('/inventory/companies')
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

    mutation.mutate({
      company_type:    form.company_type.trim(),
      company_name:    form.company_name.trim(),
      registration_no: form.registration_no.trim() || null,
      tax_reg_no:      form.tax_reg_no.trim()      || null,
      street_address:  form.street_address.trim(),
      city:            form.city.trim()            || null,
      country:         form.country.trim()         || null,
      state:           form.state.trim()           || null,
      postal_zip_code: form.postal_zip_code.trim() || null,
      company_email:   form.company_email.trim()   || null,
      company_email_2: form.company_email_2.trim() || null,
      company_mobile:  form.company_mobile.trim()  || null,
      industry_id:     Number(form.industry_id),
    })
  }

  const crumbs = [
    { label: 'Inventory',  to: '/inventory/products' },
    { label: 'Companies',  to: '/inventory/companies' },
    { label: isEditing ? 'Edit Company' : 'New Company' },
  ]

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
  }

  return (
    <div className="w-full">
      <div className="mb-2">
        <h1 className="text-xl font-bold leading-none text-slate-800">
          {isEditing ? 'Edit Company' : 'New Company'}
        </h1>
        <Breadcrumb crumbs={crumbs} />
      </div>
      <form onSubmit={handleSubmit} noValidate>
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">

          {/* ── Left column ── */}
          <div className="space-y-2">

            {/* Basic Information */}
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <SectionHeader icon={Building2} title="Basic Information" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100" />
              <div className="space-y-2 p-2.5">

                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">

                  <div>
                    <Label required>Company Type</Label>
                    <select
                      name="company_type"
                      value={form.company_type}
                      onChange={handleChange}
                      onBlur={handleBlur}
                      className={fieldCls(errors, touched, 'company_type')}
                    >
                      <option value="">— Select type —</option>
                      {COMPANY_TYPES.map((t) => (
                        <option key={t} value={t}>{t}</option>
                      ))}
                    </select>
                    <FieldError errors={errors} touched={touched} name="company_type" />
                  </div>

                  <div>
                    <Label required>Industry</Label>
                    <select
                      name="industry_id"
                      value={form.industry_id}
                      onChange={handleChange}
                      onBlur={handleBlur}
                      className={fieldCls(errors, touched, 'industry_id')}
                    >
                      <option value="">— Select industry —</option>
                      {industries.map((ind) => (
                        <option key={ind.id} value={ind.id}>{ind.name}</option>
                      ))}
                    </select>
                    <FieldError errors={errors} touched={touched} name="industry_id" />
                  </div>

                </div>

                <div className="grid grid-cols-1 gap-2 sm:grid-cols-3">

                  <div>
                    <Label required>Company Name</Label>
                    <input
                      ref={nameRef}
                      name="company_name"
                      type="text"
                      value={form.company_name}
                      onChange={handleChange}
                      onBlur={handleBlur}
                      placeholder="e.g. Acme Corporation Ltd."
                      maxLength={100}
                      autoComplete="off"
                      className={fieldCls(errors, touched, 'company_name')}
                    />
                    <FieldError errors={errors} touched={touched} name="company_name" />
                  </div>

                  <div>
                    <Label>Registration No.</Label>
                    <input
                      name="registration_no"
                      type="text"
                      value={form.registration_no}
                      onChange={handleChange}
                      onBlur={handleBlur}
                      placeholder="e.g. PV-00123"
                      maxLength={50}
                      autoComplete="off"
                      className={fieldCls(errors, touched, 'registration_no')}
                    />
                    <FieldError errors={errors} touched={touched} name="registration_no" />
                  </div>

                  <div>
                    <Label>Tax Reg. No.</Label>
                    <input
                      name="tax_reg_no"
                      type="text"
                      value={form.tax_reg_no}
                      onChange={handleChange}
                      onBlur={handleBlur}
                      placeholder="e.g. TAX-456789"
                      maxLength={50}
                      autoComplete="off"
                      className={fieldCls(errors, touched, 'tax_reg_no')}
                    />
                    <FieldError errors={errors} touched={touched} name="tax_reg_no" />
                  </div>

                </div>

              </div>
            </div>

            {/* Contact */}
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <SectionHeader icon={Phone} title="Contact Details" colorClass="text-sky-700 bg-sky-50 border-sky-100" />
              <div className="grid grid-cols-1 gap-2 p-2.5 sm:grid-cols-3">

                <div>
                  <Label>Company Email</Label>
                  <input
                    name="company_email"
                    type="email"
                    value={form.company_email}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="info@company.com"
                    maxLength={100}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'company_email')}
                  />
                  <FieldError errors={errors} touched={touched} name="company_email" />
                </div>

                <div>
                  <Label>Company Email 2</Label>
                  <input
                    name="company_email_2"
                    type="email"
                    value={form.company_email_2}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="accounts@company.com"
                    maxLength={100}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'company_email_2')}
                  />
                  <FieldError errors={errors} touched={touched} name="company_email_2" />
                </div>

                <div>
                  <Label>Mobile</Label>
                  <input
                    name="company_mobile"
                    type="text"
                    value={form.company_mobile}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="+94 77 123 4567"
                    maxLength={20}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'company_mobile')}
                  />
                  <FieldError errors={errors} touched={touched} name="company_mobile" />
                </div>

              </div>
            </div>

          </div>

          {/* ── Right column — Address ── */}
          <div>
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
              <SectionHeader icon={MapPin} title="Address" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100" />
              <div className="grid grid-cols-1 gap-2 p-2.5 sm:grid-cols-2">

                <div className="sm:col-span-2">
                  <Label required>Street Address</Label>
                  <input
                    name="street_address"
                    type="text"
                    value={form.street_address}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="123 Main Street"
                    maxLength={100}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'street_address')}
                  />
                  <FieldError errors={errors} touched={touched} name="street_address" />
                </div>

                <div>
                  <Label>City</Label>
                  <input
                    name="city"
                    type="text"
                    value={form.city}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="Colombo"
                    maxLength={50}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'city')}
                  />
                  <FieldError errors={errors} touched={touched} name="city" />
                </div>

                <div>
                  <Label>State / Province</Label>
                  <input
                    name="state"
                    type="text"
                    value={form.state}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="Western"
                    maxLength={50}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'state')}
                  />
                  <FieldError errors={errors} touched={touched} name="state" />
                </div>

                <div>
                  <Label>Country</Label>
                  <input
                    name="country"
                    type="text"
                    value={form.country}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="Sri Lanka"
                    maxLength={50}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'country')}
                  />
                  <FieldError errors={errors} touched={touched} name="country" />
                </div>

                <div>
                  <Label>Postal / Zip Code</Label>
                  <input
                    name="postal_zip_code"
                    type="text"
                    value={form.postal_zip_code}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    placeholder="10100"
                    maxLength={20}
                    autoComplete="off"
                    className={fieldCls(errors, touched, 'postal_zip_code')}
                  />
                  <FieldError errors={errors} touched={touched} name="postal_zip_code" />
                </div>

              </div>
            </div>
          </div>

        </div>

        {/* Footer */}
        <div className="mt-2 flex items-center justify-end gap-2 rounded-lg border border-slate-200 bg-white px-3 py-1.5">
          <Link
            to="/inventory/companies"
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
            {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Company'}
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
