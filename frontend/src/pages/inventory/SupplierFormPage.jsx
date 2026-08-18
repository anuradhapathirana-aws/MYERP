import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Banknote, Building2, CreditCard, MapPin, Paperclip, Phone,
  Receipt, Save, Trash2, Upload, UserCheck, X,
} from 'lucide-react'
import { createSupplier, getNextSupplierCode, getSupplier, updateSupplier } from '../../api/suppliers'
import {
  deleteSupplierAttachment,
  getSupplierAttachments,
  uploadSupplierAttachments,
} from '../../api/supplierAttachments'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'

const SUPPLIER_TYPES = ['Trade', 'Service']

const EMPTY_FORM = {
  supplier_code:    '',
  reference_no:     '',
  supplier_type:    '',
  supplier_name:    '',
  check_writer_name:'',
  mobile:           '',
  land_line:        '',
  email:            '',
  wechat:           '',
  whatsapp:         '',
  fax:              '',
  website:          '',
  bil_address_line_1: '',
  bil_address_line_2: '',
  bil_address_line_3: '',
  bil_city:           '',
  bil_postal_code:    '',
  bil_state_province: '',
  bil_country:        '',
  tax_type:    '',
  tax_no:      '',
  tax_regis_no:'',
  credit_limit:        '',
  credit_period:       '',
  privileges_discount: '',
  bank_name:            '',
  bank_branch:          '',
  bank_acc_holder_name: '',
  bank_acc_no:          '',
  contact_person_name:        '',
  contact_person_designation: '',
  contact_person_mobile:      '',
  contact_person_email:       '',
  contact_person_fax:         '',
}

const REQUIRED_FIELDS = new Set([
  'supplier_code', 'supplier_type', 'supplier_name',
  'mobile', 'land_line', 'email',
  'bil_address_line_1',
  'contact_person_name', 'contact_person_mobile',
])

function validate(field, value) {
  const v = typeof value === 'string' ? value.trim() : value
  if (REQUIRED_FIELDS.has(field) && !v) {
    const labels = {
      supplier_code:          'Supplier code',
      supplier_type:          'Supplier type',
      supplier_name:          'Supplier name',
      mobile:                 'Mobile',
      land_line:              'Land line',
      email:                  'Email',
      bil_address_line_1:     'Address line 1',
      contact_person_name:    'Contact person name',
      contact_person_mobile:  'Contact person mobile',
    }
    return `${labels[field]} is required.`
  }
  switch (field) {
    case 'supplier_code':
      if (v && String(v).length > 50) return 'Max 50 characters.'
      break
    case 'supplier_name':
      if (v && String(v).length > 100) return 'Max 100 characters.'
      break
    case 'email':
      if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email.'
      break
    case 'contact_person_email':
      if (v && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return 'Enter a valid email.'
      break
    case 'website':
      if (v && !/^https?:\/\/.+/.test(v)) return 'Must start with http:// or https://'
      break
    case 'credit_limit':
      if (v !== '' && v !== null && (isNaN(Number(v)) || Number(v) < 0)) return 'Must be ≥ 0.'
      break
    case 'credit_period':
      if (v !== '' && v !== null && (!Number.isInteger(Number(v)) || Number(v) < 0)) return 'Whole number ≥ 0.'
      break
    case 'privileges_discount':
      if (v !== '' && v !== null && (isNaN(Number(v)) || Number(v) < 0 || Number(v) > 100)) return '0–100.'
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

function formatBytes(bytes) {
  if (!bytes) return ''
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function isImageMime(mime) {
  return typeof mime === 'string' && mime.startsWith('image/')
}

function FileTypeIcon({ mime }) {
  const ext = mime?.split('/')[1]?.toUpperCase() ?? 'FILE'
  const colors = {
    PDF:  'bg-red-100 text-red-700',
    DOC:  'bg-blue-100 text-blue-700',
    DOCX: 'bg-blue-100 text-blue-700',
    XLS:  'bg-emerald-100 text-emerald-700',
    XLSX: 'bg-emerald-100 text-emerald-700',
    ZIP:  'bg-amber-100 text-amber-700',
    RAR:  'bg-amber-100 text-amber-700',
  }
  const cls = colors[ext] ?? 'bg-slate-100 text-slate-600'
  return (
    <div className={`flex h-10 w-10 items-center justify-center rounded text-[9px] font-bold ${cls}`}>
      {ext.slice(0, 4)}
    </div>
  )
}

export default function SupplierFormPage() {
  const { id }      = useParams()
  const isEditing   = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()
  const nameRef     = useRef(null)
  const attachFileRef = useRef(null)

  const [form,       setForm]       = useState(EMPTY_FORM)
  const [errors,     setErrors]     = useState({})
  const [touched,    setTouched]    = useState({})

  /* ── Attachment state ────────────────────────────────────────── */
  const [newFiles,     setNewFiles]     = useState([])
  const [isUploading,  setIsUploading]  = useState(false)
  const [hoverPreview, setHoverPreview] = useState(null) // { url, name }

  const { isLoading: isFetching, data: fetchedData } = useQuery({
    queryKey: ['supplier', id],
    queryFn:  () => getSupplier(id),
    enabled:  isEditing,
  })

  const { data: nextCode } = useQuery({
    queryKey: ['supplier-next-code'],
    queryFn:  getNextSupplierCode,
    enabled:  !isEditing,
  })

  /* ── Existing attachments query (edit mode) ──────────────────── */
  const {
    data: attachmentsData,
    refetch: refetchAttachments,
  } = useQuery({
    queryKey: ['supplier-attachments', id],
    queryFn:  () => getSupplierAttachments(id),
    enabled:  isEditing,
    staleTime: 0,
  })
  const existingFiles = attachmentsData ?? []

  const initialized = useRef(false)
  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const s = fetchedData.data
      setForm(
        Object.fromEntries(
          Object.keys(EMPTY_FORM).map((k) => [k, s[k] != null ? String(s[k]) : ''])
        )
      )
      initialized.current = true
    }
  }, [fetchedData])

  useEffect(() => { nameRef.current?.focus() }, [])

  // Show the backend-generated preview code once it arrives (create mode only)
  const codeSeeded = useRef(false)
  useLayoutEffect(() => {
    if (!isEditing && nextCode && !codeSeeded.current) {
      setForm((prev) => ({ ...prev, supplier_code: nextCode }))
      codeSeeded.current = true
    }
  }, [isEditing, nextCode])

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

  /* ── Attachment handlers ─────────────────────────────────────── */
  const handleAddFiles = (e) => {
    const incoming = Array.from(e.target.files ?? [])
    if (attachFileRef.current) attachFileRef.current.value = ''
    if (!incoming.length) return
    setNewFiles((prev) => {
      const existingNames = new Set(prev.map((f) => f.name))
      return [...prev, ...incoming.filter((f) => !existingNames.has(f.name))]
    })
  }

  const handleRemoveNew = (idx) => {
    setNewFiles((prev) => prev.filter((_, i) => i !== idx))
  }

  const handleDeleteExisting = async (attachmentId) => {
    try {
      await deleteSupplierAttachment(id, attachmentId)
      await refetchAttachments()
    } catch {
      showError('Failed to delete attachment.')
    }
  }

  const mutation = useMutation({
    mutationFn: (payload) =>
      isEditing ? updateSupplier(id, payload) : createSupplier(payload),
    onError: (err) => {
      const apiErrors = err.response?.data?.errors ?? {}
      if (Object.keys(apiErrors).length) {
        setErrors(Object.fromEntries(Object.entries(apiErrors).map(([k, v]) => [k, v[0]])))
        setTouched(Object.fromEntries(Object.keys(apiErrors).map((k) => [k, true])))
      }
      showError('Failed to save. Please check the form and try again.')
    },
  })

  const handleSubmit = async (e) => {
    e.preventDefault()
    const fields = Object.keys(EMPTY_FORM)
    const newErrors = Object.fromEntries(fields.map((f) => [f, validate(f, form[f])]))
    setErrors(newErrors)
    setTouched(Object.fromEntries(fields.map((f) => [f, true])))
    if (Object.values(newErrors).some(Boolean)) return

    const str = (v) => (v.trim() === '' ? null : v.trim())
    const num = (v) => (v === '' ? null : Number(v))
    const int = (v) => (v === '' ? null : parseInt(v, 10))

    const payload = {
      supplier_name:    form.supplier_name.trim(),
      supplier_code:    str(form.supplier_code),
      reference_no:     str(form.reference_no),
      supplier_type:    str(form.supplier_type),
      check_writer_name: str(form.check_writer_name),
      mobile:           str(form.mobile),
      land_line:        str(form.land_line),
      email:            str(form.email),
      wechat:           str(form.wechat),
      whatsapp:         str(form.whatsapp),
      fax:              str(form.fax),
      website:          str(form.website),
      bil_address_line_1: str(form.bil_address_line_1),
      bil_address_line_2: str(form.bil_address_line_2),
      bil_address_line_3: str(form.bil_address_line_3),
      bil_city:           str(form.bil_city),
      bil_postal_code:    str(form.bil_postal_code),
      bil_state_province: str(form.bil_state_province),
      bil_country:        str(form.bil_country),
      tax_type:    str(form.tax_type),
      tax_no:      str(form.tax_no),
      tax_regis_no: str(form.tax_regis_no),
      credit_limit:        num(form.credit_limit),
      credit_period:       int(form.credit_period),
      privileges_discount: num(form.privileges_discount),
      bank_name:            str(form.bank_name),
      bank_branch:          str(form.bank_branch),
      bank_acc_holder_name: str(form.bank_acc_holder_name),
      bank_acc_no:          str(form.bank_acc_no),
      contact_person_name:        str(form.contact_person_name),
      contact_person_designation: str(form.contact_person_designation),
      contact_person_mobile:      str(form.contact_person_mobile),
      contact_person_email:       str(form.contact_person_email),
      contact_person_fax:         str(form.contact_person_fax),
    }

    try {
      const response = await mutation.mutateAsync(payload)
      const supplierId = isEditing ? Number(id) : (response?.data?.id ?? response?.id)

      if (newFiles.length > 0 && supplierId) {
        setIsUploading(true)
        try {
          await uploadSupplierAttachments(supplierId, newFiles)
        } catch {
          showError('Supplier saved, but some attachments failed to upload.')
        } finally {
          setIsUploading(false)
        }
      }

      queryClient.invalidateQueries({ queryKey: ['suppliers'] })
      queryClient.invalidateQueries({ queryKey: ['suppliers-all'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['supplier', id] })
      showSuccess(isEditing ? 'Supplier updated successfully.' : 'Supplier created successfully.')
      navigate('/inventory/suppliers')
    } catch {
      // onError handles display
    }
  }

  const crumbs = [
    { label: 'Inventory',  to: '/inventory/products' },
    { label: 'Suppliers',  to: '/inventory/suppliers' },
    { label: isEditing ? 'Edit Supplier' : 'New Supplier' },
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

  const isSaving = mutation.isPending || isUploading

  return (
    <div className="w-full">
      <div className="mb-2">
        <h1 className="text-xl font-bold leading-none text-slate-800">
          {isEditing ? 'Edit Supplier' : 'New Supplier'}
        </h1>
        <Breadcrumb crumbs={crumbs} />
      </div>
      <form onSubmit={handleSubmit} noValidate>
        {/* ── 2-column section grid ──────────────────────────────────── */}
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">

          {/* ── LEFT column ─────────────────────────────────────────── */}
          <div className="space-y-2">

            {/* General */}
            <SectionCard icon={Building2} title="General" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100">
              <div className="grid grid-cols-3 gap-2">
                <div>
                  <Label required>Supplier Code</Label>
                  <input
                    type="text"
                    placeholder={isEditing ? '' : 'Generating…'}
                    maxLength={50}
                    readOnly
                    {...inp('supplier_code')}
                    className={`${fieldCls(errors, touched, 'supplier_code')} cursor-not-allowed bg-slate-100 text-slate-500`}
                  />
                  <p className="mt-0.5 text-[10px] text-slate-400">Auto-generated, sequential.</p>
                  <FieldError errors={errors} touched={touched} name="supplier_code" />
                </div>
                <div>
                  <Label>Reference No.</Label>
                  <input type="text" placeholder="REF-001" maxLength={50} {...inp('reference_no')} />
                  <FieldError errors={errors} touched={touched} name="reference_no" />
                </div>
                <div>
                  <Label required>Type</Label>
                  <select {...inp('supplier_type')}>
                    <option value="">— Select —</option>
                    {SUPPLIER_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                  </select>
                  <FieldError errors={errors} touched={touched} name="supplier_type" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label required>Supplier Name</Label>
                  <input ref={nameRef} type="text" placeholder="Full supplier name" maxLength={100} {...inp('supplier_name')} />
                  <FieldError errors={errors} touched={touched} name="supplier_name" />
                </div>
                <div>
                  <Label>Check Writer Name</Label>
                  <input type="text" placeholder="Name on cheques" maxLength={100} {...inp('check_writer_name')} />
                  <FieldError errors={errors} touched={touched} name="check_writer_name" />
                </div>
              </div>
            </SectionCard>

            {/* Contact */}
            <SectionCard icon={Phone} title="Contact" colorClass="text-sky-700 bg-sky-50 border-sky-100">
              <div className="grid grid-cols-2 gap-2 md:grid-cols-4">
                <div>
                  <Label required>Mobile</Label>
                  <input type="tel" placeholder="+1 234 567 8900" maxLength={20} {...inp('mobile')} />
                  <FieldError errors={errors} touched={touched} name="mobile" />
                </div>
                <div>
                  <Label required>Land Line</Label>
                  <input type="tel" placeholder="+1 234 567 8900" maxLength={20} {...inp('land_line')} />
                  <FieldError errors={errors} touched={touched} name="land_line" />
                </div>
                <div>
                  <Label required>Email</Label>
                  <input type="email" placeholder="supplier@example.com" maxLength={100} {...inp('email')} />
                  <FieldError errors={errors} touched={touched} name="email" />
                </div>
                <div>
                  <Label>WeChat</Label>
                  <input type="text" placeholder="WeChat ID" maxLength={100} {...inp('wechat')} />
                  <FieldError errors={errors} touched={touched} name="wechat" />
                </div>
              </div>
              <div className="grid grid-cols-3 gap-2">
                <div>
                  <Label>WhatsApp</Label>
                  <input type="tel" placeholder="+1 234 567 8900" maxLength={20} {...inp('whatsapp')} />
                  <FieldError errors={errors} touched={touched} name="whatsapp" />
                </div>
                <div>
                  <Label>Fax</Label>
                  <input type="tel" placeholder="+1 234 567 8900" maxLength={20} {...inp('fax')} />
                  <FieldError errors={errors} touched={touched} name="fax" />
                </div>
                <div>
                  <Label>Website</Label>
                  <input type="url" placeholder="https://supplier.com" maxLength={255} {...inp('website')} />
                  <FieldError errors={errors} touched={touched} name="website" />
                </div>
              </div>
            </SectionCard>

            {/* Billing Address */}
            <SectionCard icon={MapPin} title="Billing Address" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100">
              <div className="grid grid-cols-3 gap-2">
                <div>
                  <Label required>Address Line 1</Label>
                  <input type="text" placeholder="Street / building" maxLength={100} {...inp('bil_address_line_1')} />
                  <FieldError errors={errors} touched={touched} name="bil_address_line_1" />
                </div>
                <div>
                  <Label>Address Line 2</Label>
                  <input type="text" placeholder="Suite / floor" maxLength={100} {...inp('bil_address_line_2')} />
                  <FieldError errors={errors} touched={touched} name="bil_address_line_2" />
                </div>
                <div>
                  <Label>Address Line 3</Label>
                  <input type="text" maxLength={100} {...inp('bil_address_line_3')} />
                  <FieldError errors={errors} touched={touched} name="bil_address_line_3" />
                </div>
              </div>
              <div className="grid grid-cols-4 gap-2">
                <div>
                  <Label>City</Label>
                  <input type="text" placeholder="City" maxLength={50} {...inp('bil_city')} />
                  <FieldError errors={errors} touched={touched} name="bil_city" />
                </div>
                <div>
                  <Label>Postal Code</Label>
                  <input type="text" placeholder="10001" maxLength={20} {...inp('bil_postal_code')} />
                  <FieldError errors={errors} touched={touched} name="bil_postal_code" />
                </div>
                <div>
                  <Label>State / Province</Label>
                  <input type="text" placeholder="State" maxLength={50} {...inp('bil_state_province')} />
                  <FieldError errors={errors} touched={touched} name="bil_state_province" />
                </div>
                <div>
                  <Label>Country</Label>
                  <input type="text" placeholder="Country" maxLength={50} {...inp('bil_country')} />
                  <FieldError errors={errors} touched={touched} name="bil_country" />
                </div>
              </div>
            </SectionCard>

          </div>

          {/* ── RIGHT column ────────────────────────────────────────── */}
          <div className="space-y-2">

            {/* Tax */}
            <SectionCard icon={Receipt} title="Tax" colorClass="text-amber-700 bg-amber-50 border-amber-100">
              <div className="grid grid-cols-3 gap-2">
                <div>
                  <Label>Tax Type</Label>
                  <input type="text" placeholder="e.g. VAT, GST" maxLength={50} {...inp('tax_type')} />
                  <FieldError errors={errors} touched={touched} name="tax_type" />
                </div>
                <div>
                  <Label>Tax No.</Label>
                  <input type="text" placeholder="Tax number" maxLength={50} {...inp('tax_no')} />
                  <FieldError errors={errors} touched={touched} name="tax_no" />
                </div>
                <div>
                  <Label>Tax Reg. No.</Label>
                  <input type="text" placeholder="Reg. number" maxLength={50} {...inp('tax_regis_no')} />
                  <FieldError errors={errors} touched={touched} name="tax_regis_no" />
                </div>
              </div>
            </SectionCard>

            {/* Financial Terms */}
            <SectionCard icon={Banknote} title="Financial Terms" colorClass="text-violet-700 bg-violet-50 border-violet-100">
              <div className="grid grid-cols-3 gap-2">
                <div>
                  <Label>Credit Limit</Label>
                  <input type="number" min="0" step="0.01" placeholder="0.00" {...inp('credit_limit')} />
                  <FieldError errors={errors} touched={touched} name="credit_limit" />
                </div>
                <div>
                  <Label>Credit Period (days)</Label>
                  <input type="number" min="0" step="1" placeholder="30" {...inp('credit_period')} />
                  <FieldError errors={errors} touched={touched} name="credit_period" />
                </div>
                <div>
                  <Label>Privileges Discount (%)</Label>
                  <input type="number" min="0" max="100" step="0.01" placeholder="0.00" {...inp('privileges_discount')} />
                  <FieldError errors={errors} touched={touched} name="privileges_discount" />
                </div>
              </div>
            </SectionCard>

            {/* Banking */}
            <SectionCard icon={CreditCard} title="Banking" colorClass="text-blue-700 bg-blue-50 border-blue-100">
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                <div>
                  <Label>Bank Name</Label>
                  <input type="text" placeholder="Bank name" maxLength={100} {...inp('bank_name')} />
                  <FieldError errors={errors} touched={touched} name="bank_name" />
                </div>
                <div>
                  <Label>Branch</Label>
                  <input type="text" placeholder="Branch name" maxLength={100} {...inp('bank_branch')} />
                  <FieldError errors={errors} touched={touched} name="bank_branch" />
                </div>
                <div>
                  <Label>Account Holder</Label>
                  <input type="text" placeholder="Holder name" maxLength={100} {...inp('bank_acc_holder_name')} />
                  <FieldError errors={errors} touched={touched} name="bank_acc_holder_name" />
                </div>
                <div>
                  <Label>Account No.</Label>
                  <input type="text" placeholder="Account no." maxLength={50} {...inp('bank_acc_no')} />
                  <FieldError errors={errors} touched={touched} name="bank_acc_no" />
                </div>
              </div>
            </SectionCard>

            {/* Contact Person */}
            <SectionCard icon={UserCheck} title="Contact Person" colorClass="text-teal-700 bg-teal-50 border-teal-100">
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                <div>
                  <Label required>Name</Label>
                  <input type="text" placeholder="Full name" maxLength={100} {...inp('contact_person_name')} />
                  <FieldError errors={errors} touched={touched} name="contact_person_name" />
                </div>
                <div>
                  <Label>Designation</Label>
                  <input type="text" placeholder="Job title" maxLength={100} {...inp('contact_person_designation')} />
                  <FieldError errors={errors} touched={touched} name="contact_person_designation" />
                </div>
                <div>
                  <Label required>Mobile</Label>
                  <input type="tel" placeholder="+1 234 567 8900" maxLength={20} {...inp('contact_person_mobile')} />
                  <FieldError errors={errors} touched={touched} name="contact_person_mobile" />
                </div>
                <div>
                  <Label>Fax</Label>
                  <input type="tel" placeholder="+1 234 567 8900" maxLength={20} {...inp('contact_person_fax')} />
                  <FieldError errors={errors} touched={touched} name="contact_person_fax" />
                </div>
              </div>
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <Label>Email</Label>
                  <input type="email" placeholder="contact@supplier.com" maxLength={100} {...inp('contact_person_email')} />
                  <FieldError errors={errors} touched={touched} name="contact_person_email" />
                </div>
              </div>
            </SectionCard>

          </div>
        </div>

        {/* ── Attachments (full-width) ─────────────────────────────── */}
        <div className="mt-2">
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div className="flex items-center justify-between gap-1.5 border-b border-rose-100 bg-rose-50 px-3 py-2">
              <div className="flex items-center gap-1.5 text-rose-700">
                <Paperclip size={13} />
                <h2 className="text-xs font-bold">Attachments</h2>
                {(existingFiles.length > 0 || newFiles.length > 0) && (
                  <span className="rounded-full bg-rose-200 px-1.5 py-px text-[10px] font-semibold text-rose-800">
                    {existingFiles.length + newFiles.length}
                  </span>
                )}
              </div>
              <button
                type="button"
                onClick={() => attachFileRef.current?.click()}
                className="flex items-center gap-1 rounded border border-rose-300 bg-white px-2 py-0.5 text-[10px] font-semibold text-rose-700 transition hover:bg-rose-50"
              >
                <Upload size={10} strokeWidth={2.5} />
                Add Files
              </button>
              <input
                ref={attachFileRef}
                type="file"
                multiple
                className="hidden"
                onChange={handleAddFiles}
              />
            </div>

            <div className="p-2.5">
              {existingFiles.length === 0 && newFiles.length === 0 ? (
                <div className="flex flex-col items-center justify-center py-6 text-slate-400">
                  <Paperclip size={24} strokeWidth={1.5} className="mb-1 opacity-40" />
                  <p className="text-xs">No attachments yet. Click <strong>Add Files</strong> to upload.</p>
                </div>
              ) : (
                <div className="flex flex-wrap gap-2">
                  {/* Existing saved attachments */}
                  {existingFiles.map((file) => (
                    <div
                      key={file.id}
                      className="relative flex flex-col items-center gap-1 rounded-lg border border-slate-200 bg-slate-50 p-1.5 w-22.5 group"
                    >
                      {isImageMime(file.mime_type) ? (
                        <a
                          href={file.url}
                          target="_blank"
                          rel="noreferrer"
                          onMouseEnter={() => setHoverPreview({ url: file.url, name: file.file_name })}
                          onMouseLeave={() => setHoverPreview(null)}
                        >
                          <img
                            src={file.url}
                            alt={file.file_name}
                            className="h-12 w-18.5 rounded object-cover border border-slate-200"
                          />
                        </a>
                      ) : (
                        <a href={file.url} target="_blank" rel="noreferrer">
                          <FileTypeIcon mime={file.mime_type} />
                        </a>
                      )}
                      <p className="w-full truncate text-center text-[9px] text-slate-600" title={file.file_name}>
                        {file.file_name}
                      </p>
                      {file.file_size && (
                        <p className="text-[9px] text-slate-400">{formatBytes(file.file_size)}</p>
                      )}
                      <button
                        type="button"
                        onClick={() => handleDeleteExisting(file.id)}
                        className="absolute -right-1 -top-1 hidden group-hover:flex items-center justify-center h-4 w-4 rounded-full bg-red-500 text-white shadow"
                        title="Remove"
                      >
                        <X size={8} strokeWidth={3} />
                      </button>
                    </div>
                  ))}

                  {/* Pending new files (queued for upload on save) */}
                  {newFiles.map((file, idx) => {
                    const objectUrl = URL.createObjectURL(file)
                    return (
                      <div
                        key={`new-${idx}`}
                        className="relative flex flex-col items-center gap-1 rounded-lg border border-dashed border-indigo-300 bg-indigo-50/40 p-1.5 w-22.5 group"
                      >
                        {isImageMime(file.type) ? (
                          <a
                            href={objectUrl}
                            target="_blank"
                            rel="noreferrer"
                            onMouseEnter={() => setHoverPreview({ url: objectUrl, name: file.name })}
                            onMouseLeave={() => setHoverPreview(null)}
                          >
                            <img
                              src={objectUrl}
                              alt={file.name}
                              className="h-12 w-18.5 rounded object-cover border border-indigo-200"
                            />
                          </a>
                        ) : (
                          <a href={objectUrl} target="_blank" rel="noreferrer">
                            <FileTypeIcon mime={file.type} />
                          </a>
                        )}
                        <p className="w-full truncate text-center text-[9px] text-slate-600" title={file.name}>
                          {file.name}
                        </p>
                        <p className="text-[9px] text-indigo-500">Pending</p>
                        <button
                          type="button"
                          onClick={() => handleRemoveNew(idx)}
                          className="absolute -right-1 -top-1 hidden group-hover:flex items-center justify-center h-4 w-4 rounded-full bg-red-500 text-white shadow"
                          title="Remove"
                        >
                          <X size={8} strokeWidth={3} />
                        </button>
                      </div>
                    )
                  })}
                </div>
              )}
            </div>
          </div>
        </div>

        {/* ── Footer ──────────────────────────────────────────────────── */}
        <div className="mt-2 flex items-center justify-end gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5">
          <Link
            to="/inventory/suppliers"
            className="rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100"
          >
            Cancel
          </Link>
          <button
            type="submit"
            disabled={isSaving}
            className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60"
          >
            <Save size={12} strokeWidth={2.5} />
            {isSaving ? (isUploading ? 'Uploading…' : 'Saving…') : isEditing ? 'Save Changes' : 'Create Supplier'}
          </button>
        </div>

        {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
          <p className="mt-1 text-[10px] text-red-600">
            {mutation.error?.response?.data?.message ?? 'An unexpected error occurred. Please try again.'}
          </p>
        )}
      </form>

      {/* ── Image hover preview popup ────────────────────────────────── */}
      {hoverPreview && (
        <div className="fixed inset-0 z-50 flex items-center justify-center pointer-events-none">
          <div className="rounded-xl border border-slate-300 bg-white p-2 shadow-2xl max-w-sm pointer-events-none">
            <img
              src={hoverPreview.url}
              alt={hoverPreview.name}
              className="max-h-64 max-w-xs rounded object-contain"
            />
            <p className="mt-1 truncate text-center text-[10px] text-slate-500">{hoverPreview.name}</p>
          </div>
        </div>
      )}
    </div>
  )
}
