import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { FileClock, Plus, RefreshCw, Save, Send, Trash2 } from 'lucide-react'
import {
  checkProformaStock,
  createProformaInvoice,
  getProformaPrices,
  getNextProformaNo,
  getProformaInvoice,
  updateProformaInvoice,
  updateProformaInvoiceStatus,
} from '../../api/proformaInvoices'
import { getAllCompanies } from '../../api/companies'
import { getAllCustomers } from '../../api/customers'
import { getProduct, getProducts } from '../../api/products'
import { getAllUnitTypesFlat } from '../../api/unitTypes'
import { getAllAttributeTypes } from '../../api/attributeTypes'
import { getAllAttributes } from '../../api/attributes'
import Breadcrumb from '../../components/Breadcrumb'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Money from '../../components/ui/Money'
import KeyboardShortcuts from '../../components/ui/KeyboardShortcuts'
import ProductSearchCell from '../../components/inventory/ProductSearchCell'
import { confirmAction, showError, showSuccess } from '../../utils/alerts'

const INPUT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20'
const INPUT_ERR_CLS =
  'block w-full rounded border border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-1 focus:ring-red-500/20'
const INPUT_RO_CLS =
  'block w-full rounded border border-slate-200 bg-slate-100 px-2 py-1 text-xs font-mono text-slate-500 outline-none cursor-not-allowed'
const SELECT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20 cursor-pointer'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'text-[10px] text-red-500 leading-tight'
const CELL_INPUT =
  'block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white'
const CELL_SELECT = `${CELL_INPUT} cursor-pointer`

/** Offer validity used when the user hasn't picked a date (mirrors the backend default). */
const DEFAULT_VALID_DAYS = 14
/** VAT % assumed when a line's price set does not carry one (same as the invoice). */
const FALLBACK_VAT_PCT = 18

const round2 = (n) => String(Number((Number(n) || 0).toFixed(2)))

/**
 * Every line holds BOTH price sets, exactly like the invoice:
 *   so_unit_price             Non-Tax — after-tax price, VAT inside
 *   tax_unit_price + tax_vat_pct  Tax — before-tax price, VAT added
 * The Tax / Non-Tax toggle swaps unit_price / tax between them, so the
 * customer total does not change when the type changes.
 */
const priceForType = (line, type) => (type === 'tax'
  ? { unit_price: line.tax_unit_price != null ? round2(line.tax_unit_price) : line.unit_price, tax: line.tax_vat_pct != null ? String(line.tax_vat_pct) : line.tax }
  : { unit_price: line.so_unit_price != null ? round2(line.so_unit_price) : line.unit_price, tax: '' })

const STOCK_BADGE = {
  in_stock:  { cls: 'bg-green-100 text-green-700', label: 'In stock' },
  partial:   { cls: 'bg-amber-100 text-amber-700', label: 'Partial' },
  non_stock: { cls: 'bg-slate-100 text-slate-500', label: 'Non-stock' },
}

const addDays = (iso, days) => {
  const d = new Date(`${iso}T00:00:00`)
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

const isColorTypeName = (name) => ['color', 'colour'].includes((name || '').trim().toLowerCase())

function emptyLine() {
  return {
    _key:          Date.now() + Math.random(),
    product_id:    '',
    product_name:  '',
    product_code:  '',
    unit_id:       '',
    unit_category_id: null,
    attribute_id:  '',
    color_options: [],
    quantity:      '',
    unit_price:    '',
    discount:      '',
    tax:           '',
    so_unit_price:  null, // Non-Tax price set (VAT inside)
    tax_unit_price: null, // Tax price set (before tax) …
    tax_vat_pct:    null, // … plus this VAT %
    stock:         null, // { available_qty, stock_status } — live
  }
}

function calcLine(line, isTax) {
  const gross = (parseFloat(line.quantity) || 0) * (parseFloat(line.unit_price) || 0)
  const disc  = gross * (parseFloat(line.discount) || 0) / 100
  const vat   = isTax ? gross * (parseFloat(line.tax) || 0) / 100 : 0
  return gross - disc + vat
}

export default function ProformaInvoiceFormPage() {
  const { id }   = useParams()
  const isEdit   = Boolean(id)
  const navigate = useNavigate()

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Proforma Invoices', to: '/inventory/proforma-invoices' },
    { label: isEdit ? 'Edit Proforma' : 'New Proforma' },
  ]

  const today = new Date().toISOString().slice(0, 10)
  const [form, setForm] = useState({
    customer_id:      '',
    proforma_date:    today,
    valid_until:      addDays(today, DEFAULT_VALID_DAYS),
    payment_terms:    '',
    delivery_address: '',
    transport_charge: '',
    remarks:          '',
    company_id:       '',
  })
  const [invoiceType, setInvoiceType] = useState('non_tax')
  const [lines, setLines]   = useState([emptyLine()])
  const [errors, setErrors] = useState({})
  const [saving, setSaving] = useState(false)
  const [productSearch, setProductSearch] = useState({ key: null, query: '', results: [], open: false })
  const searchTimerRef = useRef(null)
  const cellRefs       = useRef({}) // { [lineKey]: { product, qty, price, disc, vat } }
  const isTax = invoiceType === 'tax'

  /* ── Masters ── */
  const { data: customers = [] } = useQuery({ queryKey: ['customers-all'], queryFn: getAllCustomers, staleTime: 5 * 60 * 1000 })
  const { data: companiesRes }   = useQuery({ queryKey: ['companies-all'], queryFn: getAllCompanies, staleTime: 5 * 60 * 1000 })
  const { data: unitTypes = [] } = useQuery({ queryKey: ['unit-types-flat'], queryFn: getAllUnitTypesFlat, staleTime: 5 * 60 * 1000 })
  const { data: attributeTypes = [] } = useQuery({ queryKey: ['attribute-types-all'], queryFn: getAllAttributeTypes })
  const { data: allAttributes = [] }  = useQuery({ queryKey: ['attributes-all'], queryFn: getAllAttributes })
  const companies       = companiesRes?.data ?? []
  const customerOptions = customers.map((c) => ({ value: String(c.id), label: `${c.customer_code ?? ''} · ${c.name}` }))

  useEffect(() => {
    if (isEdit || form.company_id || companies.length === 0) return
    setForm((f) => ({ ...f, company_id: String(companies[0].id) }))
  }, [companies, isEdit, form.company_id])

  /* ── Edit hydration ── */
  const { data: existing, isLoading: loadingExisting } = useQuery({
    queryKey: ['proforma-invoice', id],
    queryFn:  () => getProformaInvoice(id),
    enabled:  isEdit,
  })

  useEffect(() => {
    if (!existing?.data) return
    const pf = existing.data
    setInvoiceType(pf.invoice_type ?? 'non_tax')
    setForm({
      customer_id:      pf.customer_id ? String(pf.customer_id) : '',
      proforma_date:    pf.proforma_date ?? '',
      valid_until:      pf.valid_until ?? '',
      payment_terms:    pf.payment_terms ?? '',
      delivery_address: pf.delivery_address ?? '',
      transport_charge: pf.transport_charge ? String(pf.transport_charge) : '',
      remarks:          pf.remarks ?? '',
      company_id:       pf.company_id ? String(pf.company_id) : '',
    })
    const hydrated = (pf.items ?? []).map((it) => ({
      ...emptyLine(),
      _key:         it.id,
      product_id:   it.product_id,
      product_name: it.product?.name ?? '',
      product_code: it.product?.product_code ?? '',
      unit_id:      it.unit_id != null ? String(it.unit_id) : '',
      attribute_id: it.attribute_id != null ? String(it.attribute_id) : '',
      color_options: it.attribute ? [{ id: it.attribute.id, name: it.attribute.name }] : [],
      quantity:     String(it.quantity),
      unit_price:   String(it.unit_price),
      discount:     Number(it.discount) > 0 ? String(it.discount) : '',
      tax:          Number(it.tax) > 0 ? String(it.tax) : '',
      // Only the saved type's price is stored — derive its pair so the toggle still works
      ...(pf.invoice_type === 'tax'
        ? {
            tax_unit_price: Number(it.unit_price),
            tax_vat_pct:    Number(it.tax),
            so_unit_price:  Number(it.unit_price) * (1 + Number(it.tax) / 100),
          }
        : {
            so_unit_price:  Number(it.unit_price),
            tax_vat_pct:    FALLBACK_VAT_PCT,
            tax_unit_price: Number(it.unit_price) / (1 + FALLBACK_VAT_PCT / 100),
          }),
    }))
    setLines(hydrated.length ? hydrated : [emptyLine()])
  }, [existing])

  /* ── Number preview (needs the customer's code) ── */
  const { data: nextNo, isLoading: loadingNo } = useQuery({
    queryKey: ['next-proforma-no', form.customer_id, form.proforma_date],
    queryFn:  () => getNextProformaNo(form.customer_id, form.proforma_date),
    enabled:  !isEdit && Boolean(form.customer_id),
    staleTime: 0,
  })

  const unitById = useMemo(() => new Map(unitTypes.map((u) => [String(u.id), u])), [unitTypes])
  const unitsFor = (categoryId) => unitTypes.filter((u) => categoryId == null || String(u.unit_category_id) === String(categoryId))

  const colourOptionsFor = useCallback(async (productId) => {
    try {
      const res = await getProduct(productId)
      const product = res?.data ?? res
      const colourTypeIds = new Set(attributeTypes.filter((t) => isColorTypeName(t.attribute_type_name)).map((t) => String(t.id)))
      const options = (product.product_attributes ?? [])
        .filter((pa) => colourTypeIds.has(String(pa.attribute_type_id)))
        .map((pa) => allAttributes.find((a) => String(a.id) === String(pa.attribute_id)))
        .filter(Boolean)
        .map((a) => ({ id: a.id, name: a.attribute_name }))
      return { product, options }
    } catch {
      return { product: null, options: [] }
    }
  }, [attributeTypes, allAttributes])

  /* ── Live stock badge (debounced) ── */
  const stockKey = lines
    .filter((l) => l.product_id)
    .map((l) => `${l.product_id}|${l.attribute_id}|${l.unit_id}|${l.quantity}`)
    .join(',')

  useEffect(() => {
    const withProduct = lines.filter((l) => l.product_id)
    if (withProduct.length === 0) return undefined
    const timer = setTimeout(() => {
      checkProformaStock(withProduct.map((l) => ({
        product_id:   Number(l.product_id),
        attribute_id: l.attribute_id ? Number(l.attribute_id) : null,
        unit_id:      l.unit_id ? Number(l.unit_id) : null,
        quantity:     parseFloat(l.quantity) || 0,
      })))
        .then((results) => {
          const byKey = new Map(withProduct.map((l, i) => [l._key, results[i]]))
          setLines((prev) => prev.map((l) => byKey.has(l._key) ? { ...l, stock: byKey.get(l._key) } : l))
        })
        .catch(() => { /* badge is informational */ })
    }, 400)
    return () => clearTimeout(timer)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [stockKey])

  /* ── Line editing ── */
  const setLineField = (key, field, value) =>
    setLines((prev) => prev.map((l) => l._key === key ? { ...l, [field]: value } : l))

  const focusCell = (key, cell) => setTimeout(() => cellRefs.current[key]?.[cell]?.focus(), 30)
  const setCellRef = (key, cell) => (el) => {
    cellRefs.current[key] = { ...(cellRefs.current[key] ?? {}), [cell]: el }
  }

  const addLine = useCallback(() => {
    const line = emptyLine()
    setLines((prev) => [...prev, line])
    setTimeout(() => cellRefs.current[line._key]?.product?.focus(), 50)
  }, [])

  const removeLine = (key) => setLines((prev) => (prev.length > 1 ? prev.filter((l) => l._key !== key) : [emptyLine()]))

  const handleProductQueryChange = (key, query) => {
    setProductSearch({ key, query, results: [], open: false })
    clearTimeout(searchTimerRef.current)
    const run = async (search) => {
      try {
        const res = await getProducts(1, search ? { search, per_page: 30 } : { per_page: 100 })
        setProductSearch((prev) => (prev.key === key ? { ...prev, results: res.data ?? [], open: true } : prev))
      } catch { /* silent */ }
    }
    if (query === '') run('')
    else searchTimerRef.current = setTimeout(() => run(query), 300)
  }

  const selectProduct = (key, product) => {
    setProductSearch({ key: null, query: '', results: [], open: false })
    setLines((prev) => prev.map((l) => l._key === key ? {
      ...l,
      product_id:       product.id,
      product_name:     product.name ?? '',
      product_code:     product.product_code ?? '',
      unit_id:          product.base_unit_type_id != null ? String(product.base_unit_type_id) : '',
      unit_category_id: product.base_unit_category_id ?? null,
      attribute_id:     '',
      color_options:    [],
      unit_price:       '',
      tax:              '',
      so_unit_price:    null,
      tax_unit_price:   null,
      tax_vat_pct:      null,
      stock:            null,
    } : l))

    // Colours + the UOM category (search results may not carry it) from the full product
    colourOptionsFor(product.id).then(({ product: full, options }) => {
      setLines((prev) => prev.map((l) => l._key === key ? {
        ...l,
        color_options:    options,
        unit_category_id: full?.base_unit_category_id ?? l.unit_category_id,
      } : l))
    })

    refreshPrices(key, {
      product_id: product.id,
      unit_id:    product.base_unit_type_id ?? null,
    })

    focusCell(key, 'qty')
  }

  /* Both price sets for a line (costing first, else price list − VAT) — refreshed
     whenever the product, colour or unit changes, like the invoice's billing source. */
  const invoiceTypeRef = useRef(invoiceType)
  useEffect(() => { invoiceTypeRef.current = invoiceType })

  const refreshPrices = (key, { product_id, attribute_id = null, unit_id = null }) => {
    getProformaPrices([{
      product_id:   Number(product_id),
      attribute_id: attribute_id ? Number(attribute_id) : null,
      unit_id:      unit_id ? Number(unit_id) : null,
    }])
      .then(([p]) => {
        if (!p || p.unit_price == null) return // no price anywhere — stays manual
        setLines((prev) => prev.map((l) => {
          if (l._key !== key) return l
          const next = { ...l, so_unit_price: p.unit_price, tax_unit_price: p.tax_unit_price, tax_vat_pct: p.tax_vat_pct }
          return { ...next, ...priceForType(next, invoiceTypeRef.current) }
        }))
      })
      .catch(() => { /* price stays manual */ })
  }

  const changeColour = (line, value) => {
    setLineField(line._key, 'attribute_id', value)
    refreshPrices(line._key, { product_id: line.product_id, attribute_id: value, unit_id: line.unit_id })
  }

  const changeUnit = (line, value) => {
    setLineField(line._key, 'unit_id', value)
    refreshPrices(line._key, { product_id: line.product_id, attribute_id: line.attribute_id, unit_id: value })
  }

  /** A typed price belongs to the active type; the other set is re-derived through the VAT %. */
  const changePrice = (key, value) => setLines((prev) => prev.map((l) => {
    if (l._key !== key) return l
    const price = parseFloat(value)
    const vat   = l.tax_vat_pct ?? FALLBACK_VAT_PCT
    if (!(price >= 0)) return { ...l, unit_price: value }
    return invoiceTypeRef.current === 'tax'
      ? { ...l, unit_price: value, tax_unit_price: price, so_unit_price: price * (1 + vat / 100), tax_vat_pct: vat }
      : { ...l, unit_price: value, so_unit_price: price, tax_unit_price: price / (1 + vat / 100), tax_vat_pct: vat }
  }))

  /** VAT % (Tax type): keeps the before-tax price, moves the Non-Tax price with it. */
  const changeVat = (key, value) => setLines((prev) => prev.map((l) => {
    if (l._key !== key) return l
    const vat = parseFloat(value)
    if (!(vat >= 0)) return { ...l, tax: value }
    const before = l.tax_unit_price ?? (parseFloat(l.unit_price) || 0)
    return { ...l, tax: value, tax_vat_pct: vat, so_unit_price: before * (1 + vat / 100) }
  }))

  const clearProduct = (key) => {
    setLines((prev) => prev.map((l) => l._key === key ? { ...emptyLine(), _key: key } : l))
    setProductSearch({ key, query: '', results: [], open: false })
  }

  /** Enter walks the row: Qty → Price → Disc → (VAT) → next row's product (adds one at the end). */
  const onCellEnter = (key, from) => (e) => {
    if (e.key !== 'Enter' || e.ctrlKey || e.metaKey) return
    e.preventDefault()
    const order = isTax ? ['qty', 'price', 'disc', 'vat'] : ['qty', 'price', 'disc']
    const next  = order[order.indexOf(from) + 1]
    if (next) return focusCell(key, next)
    const idx = lines.findIndex((l) => l._key === key)
    if (idx === lines.length - 1) addLine()
    else focusCell(lines[idx + 1]._key, 'product')
  }

  /* Tax ↔ Non-Tax: swap every line onto that type's price set (total unchanged) */
  const applyInvoiceType = (type) => {
    if (type === invoiceType) return
    setInvoiceType(type)
    invoiceTypeRef.current = type
    setLines((prev) => prev.map((l) => (l.product_id ? { ...l, ...priceForType(l, type) } : l)))
  }

  const handleCustomerChange = (value) => {
    const c = customers.find((x) => String(x.id) === String(value))
    setForm((f) => ({ ...f, customer_id: value, delivery_address: c?.shipping_address || f.delivery_address }))
    setErrors((e) => ({ ...e, customer_id: undefined }))
    // Same default as the invoice: customer has a TIN → Tax, otherwise Non Tax (still switchable)
    if (c) applyInvoiceType(c.has_tin ? 'tax' : 'non_tax')
  }

  /* ── Totals ── */
  const totals = useMemo(() => {
    const lineTotal = lines.reduce((s, l) => s + calcLine(l, isTax), 0)
    const transport = parseFloat(form.transport_charge) || 0
    return { lineTotal, transport, grand: lineTotal + transport }
  }, [lines, isTax, form.transport_charge])

  /* ── Save ── */
  const saveMutation = useMutation({
    mutationFn: (data) => (isEdit ? updateProformaInvoice(id, data) : createProformaInvoice(data)),
  })

  const getClientErrors = () => {
    const ce = {}
    if (!isEdit && !form.customer_id) ce.customer_id = ['Select the customer.']
    if (!form.proforma_date) ce.proforma_date = ['Proforma date is required.']
    if (form.valid_until && form.valid_until < form.proforma_date) ce.valid_until = ['Valid until must be on or after the proforma date.']
    const filled = lines.filter((l) => l.product_id)
    if (filled.length === 0) ce.items = ['Add at least one product.']
    else if (filled.some((l) => !(parseFloat(l.quantity) > 0))) ce.items = ['Every line needs a quantity.']
    else if (filled.some((l) => l.unit_price === '' || parseFloat(l.unit_price) < 0)) ce.items = ['Every line needs a unit price.']
    else if (filled.some((l) => l.color_options.length > 0 && !l.attribute_id)) ce.items = ['Select a colour for every product that has colours.']
    return ce
  }

  const payload = () => ({
    ...(isEdit ? {} : { customer_id: Number(form.customer_id) }),
    proforma_date:    form.proforma_date,
    valid_until:      form.valid_until || null,
    payment_terms:    form.payment_terms.trim() || null,
    invoice_type:     invoiceType,
    transport_charge: parseFloat(form.transport_charge) || 0,
    delivery_address: form.delivery_address.trim() || null,
    remarks:          form.remarks.trim() || null,
    company_id:       form.company_id ? Number(form.company_id) : null,
    items: lines.filter((l) => l.product_id).map((l) => ({
      product_id:   Number(l.product_id),
      attribute_id: l.attribute_id ? Number(l.attribute_id) : null,
      unit_id:      l.unit_id ? Number(l.unit_id) : null,
      quantity:     parseFloat(l.quantity),
      unit_price:   parseFloat(l.unit_price) || 0,
      discount:     parseFloat(l.discount) || 0,
      tax:          isTax ? (parseFloat(l.tax) || 0) : 0,
    })),
  })

  const handleSave = async (issue) => {
    const ce = getClientErrors()
    if (Object.keys(ce).length) {
      setErrors(ce)
      showError(ce.items?.[0] ?? 'Please complete the highlighted fields.')
      return
    }
    if (issue && !(await confirmAction({
      title: 'Issue Proforma?',
      message: 'The proforma will be issued to the customer and locked. Each line records the stock available today.',
      confirmText: 'Yes, Issue',
      confirmColor: '#2563eb',
    }))) return

    setErrors({})
    setSaving(true)
    try {
      const res  = await saveMutation.mutateAsync(payload())
      const pfId = res.data.id
      if (issue) await updateProformaInvoiceStatus(pfId, 'issued')
      showSuccess(issue ? 'Proforma invoice issued.' : (isEdit ? 'Proforma invoice updated.' : 'Proforma invoice saved as draft.'))
      navigate(`/inventory/proforma-invoices/${pfId}`)
    } catch (e) {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save proforma invoice.')
    } finally {
      setSaving(false)
    }
  }

  /* ── Shortcuts: Alt+N add line · Ctrl+Enter Save & Issue ── */
  const saveRef = useRef(null)
  useEffect(() => { saveRef.current = () => { if (!saving) handleSave(true) } })
  useEffect(() => {
    const handler = (e) => {
      const popup = document.querySelector('.swal2-popup')
      if (popup && !popup.classList.contains('swal2-toast')) return
      if (e.altKey && !e.ctrlKey && (e.key === 'n' || e.key === 'N')) { e.preventDefault(); addLine() }
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && !e.altKey && !e.repeat) { e.preventDefault(); saveRef.current?.() }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [addLine])

  const err = (f) => errors[f]?.[0]

  if (isEdit && loadingExisting) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400"><RefreshCw size={14} className="animate-spin" /> Loading…</div>
      </div>
    )
  }

  const customerLabel = existing?.data?.customer?.name

  return (
    <div className="w-full">
      <div className="mb-2 flex items-start justify-between gap-2">
        <div>
          <h1 className="text-base font-bold leading-none text-slate-800">{isEdit ? 'Edit Proforma Invoice' : 'New Proforma Invoice'}</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <div className="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-1.5 text-center">
          <Money value={totals.grand} className="block text-sm font-black leading-tight text-indigo-700" />
          <div className="text-[9px] font-bold uppercase tracking-wider text-indigo-400">Proforma Total</div>
        </div>
      </div>

      <div className="flex flex-col gap-2">
        {/* ── Details ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="flex items-center justify-between gap-1.5 rounded-t-lg border-b border-indigo-100 bg-indigo-50 px-3 py-1.5 text-indigo-700">
            <div className="flex items-center gap-1.5">
              <FileClock size={12} />
              <h2 className="text-xs font-bold">Proforma Details</h2>
              <span className="text-[10px] font-normal text-indigo-400">Quote any product — in stock or not. Nothing is reserved.</span>
            </div>
            <div className="flex items-center gap-1.5">
              <span className="text-[9px] font-semibold uppercase tracking-wider text-indigo-400">Invoice Type</span>
              <div className="flex overflow-hidden rounded-md border-2 border-indigo-200 text-[10px] font-bold">
                {[['tax', 'Tax'], ['non_tax', 'Non Tax']].map(([value, label], i) => (
                  <button
                    key={value}
                    type="button"
                    onClick={() => applyInvoiceType(value)}
                    className={`${i ? 'border-l-2 border-indigo-200 ' : ''}px-2.5 py-0.5 transition-colors ${invoiceType === value ? 'bg-indigo-600 text-white' : 'bg-white text-slate-500 hover:bg-slate-50'}`}
                  >
                    {label}
                  </button>
                ))}
              </div>
            </div>
          </div>
          <div className="p-3">
            <div className="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-6">
              <div>
                <label className={LABEL_CLS}>Proforma Number</label>
                <input
                  readOnly
                  className={INPUT_RO_CLS}
                  value={isEdit ? (existing?.data?.proforma_no ?? '') : (!form.customer_id ? 'Select a customer first' : (loadingNo ? 'Generating…' : (nextNo ?? '')))}
                />
              </div>
              <div className="col-span-2 md:col-span-1 lg:col-span-2">
                <label className={LABEL_CLS}>Customer {!isEdit && <span className="text-red-500 normal-case font-bold">*</span>}</label>
                {isEdit ? (
                  <input readOnly className={INPUT_RO_CLS} value={customerLabel ?? ''} />
                ) : (
                  <FilterSearchSelect value={form.customer_id} onChange={handleCustomerChange} options={customerOptions} placeholder="Select customer…" />
                )}
                {err('customer_id') && <p className={ERR_CLS}>{err('customer_id')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Supplier (Company)</label>
                <select className={SELECT_CLS} value={form.company_id} onChange={(e) => setForm((f) => ({ ...f, company_id: e.target.value }))}>
                  <option value="">— Select —</option>
                  {companies.map((c) => <option key={c.id} value={c.id}>{c.company_name}</option>)}
                </select>
              </div>
              <div>
                <label className={LABEL_CLS}>Proforma Date <span className="text-red-500 normal-case font-bold">*</span></label>
                <input type="date" className={err('proforma_date') ? INPUT_ERR_CLS : INPUT_CLS} value={form.proforma_date} onChange={(e) => setForm((f) => ({ ...f, proforma_date: e.target.value }))} />
                {err('proforma_date') && <p className={ERR_CLS}>{err('proforma_date')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Valid Until</label>
                <input type="date" className={err('valid_until') ? INPUT_ERR_CLS : INPUT_CLS} value={form.valid_until} onChange={(e) => setForm((f) => ({ ...f, valid_until: e.target.value }))} />
                {err('valid_until') && <p className={ERR_CLS}>{err('valid_until')}</p>}
              </div>
              <div className="col-span-2">
                <label className={LABEL_CLS}>Payment Terms</label>
                <input className={INPUT_CLS} placeholder="e.g. 50% advance, balance before delivery" value={form.payment_terms} onChange={(e) => setForm((f) => ({ ...f, payment_terms: e.target.value }))} />
              </div>
              <div className="col-span-2">
                <label className={LABEL_CLS}>Billing / Delivery Address</label>
                <input className={INPUT_CLS} value={form.delivery_address} onChange={(e) => setForm((f) => ({ ...f, delivery_address: e.target.value }))} />
              </div>
              <div>
                <label className={LABEL_CLS}>Transport Charge</label>
                <input type="number" min="0" step="0.01" className={INPUT_CLS} value={form.transport_charge} onChange={(e) => setForm((f) => ({ ...f, transport_charge: e.target.value }))} />
              </div>
              <div>
                <label className={LABEL_CLS}>Remarks</label>
                <input className={INPUT_CLS} placeholder="Notes…" value={form.remarks} onChange={(e) => setForm((f) => ({ ...f, remarks: e.target.value }))} />
              </div>
            </div>
          </div>
        </div>

        {/* ── Lines ── */}
        <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200 text-left">
                  <th className="w-7 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">#</th>
                  <th className="min-w-48 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Product</th>
                  <th className="w-28 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Colour</th>
                  <th className="w-20 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">UOM</th>
                  <th className="w-20 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Qty</th>
                  <th className="w-24 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Unit Price</th>
                  <th className="w-16 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-amber-500">Disc%</th>
                  {isTax && <th className="w-16 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-sky-500">VAT%</th>}
                  <th className="w-24 px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-700">Amount</th>
                  <th className="w-24 px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-500">Stock</th>
                  <th className="w-8"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {lines.map((l, idx) => {
                  const badge = l.stock ? STOCK_BADGE[l.stock.stock_status] : null
                  const sym   = unitById.get(String(l.unit_id))?.symbol ?? ''
                  return (
                    <tr key={l._key} className="hover:bg-slate-50/60">
                      <td className="px-2 py-1 text-slate-400 tabular-nums">{idx + 1}</td>
                      <td className="px-2 py-1">
                        <ProductSearchCell
                          row={l}
                          productSearch={productSearch}
                          onQueryChange={handleProductQueryChange}
                          onSelect={selectProduct}
                          onClear={clearProduct}
                          onClose={() => setProductSearch((p) => ({ ...p, open: false }))}
                          onInputRef={setCellRef(l._key, 'product')}
                        />
                      </td>
                      <td className="px-2 py-1">
                        {l.color_options.length > 0 ? (
                          <select className={CELL_SELECT} value={l.attribute_id} onChange={(e) => changeColour(l, e.target.value)}>
                            <option value="">— Colour —</option>
                            {l.color_options.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
                          </select>
                        ) : <span className="italic text-slate-300">—</span>}
                      </td>
                      <td className="px-2 py-1">
                        <select
                          className={CELL_SELECT}
                          value={l.unit_id}
                          disabled={!l.product_id}
                          title="The price is quoted per this unit"
                          onChange={(e) => changeUnit(l, e.target.value)}
                        >
                          <option value="">—</option>
                          {unitsFor(l.unit_category_id).map((u) => <option key={u.id} value={u.id}>{u.symbol ?? u.name}</option>)}
                        </select>
                      </td>
                      <td className="px-2 py-1">
                        <input ref={setCellRef(l._key, 'qty')} type="number" min="0" step="0.0001" className={`${CELL_INPUT} text-right`} value={l.quantity}
                          onChange={(e) => setLineField(l._key, 'quantity', e.target.value)} onKeyDown={onCellEnter(l._key, 'qty')} />
                      </td>
                      <td className="px-2 py-1">
                        <input ref={setCellRef(l._key, 'price')} type="number" min="0" step="0.01" className={`${CELL_INPUT} text-right`} value={l.unit_price}
                          onChange={(e) => changePrice(l._key, e.target.value)} onKeyDown={onCellEnter(l._key, 'price')}
                          title={isTax ? 'Before-tax price — VAT is added on this line' : 'Price with VAT already inside — no VAT is added'} />
                      </td>
                      <td className="px-2 py-1">
                        <input ref={setCellRef(l._key, 'disc')} type="number" min="0" max="100" step="0.01" placeholder="0"
                          className="block w-full rounded border border-amber-200 bg-amber-50/50 px-1.5 py-0.5 text-right text-xs outline-none focus:border-amber-400 focus:bg-white"
                          value={l.discount} onChange={(e) => setLineField(l._key, 'discount', e.target.value)} onKeyDown={onCellEnter(l._key, 'disc')} />
                      </td>
                      {isTax && (
                        <td className="px-2 py-1">
                          <input ref={setCellRef(l._key, 'vat')} type="number" min="0" max="100" step="0.01" placeholder="0"
                            className="block w-full rounded border border-sky-200 bg-sky-50/50 px-1.5 py-0.5 text-right text-xs outline-none focus:border-sky-400 focus:bg-white"
                            value={l.tax} onChange={(e) => changeVat(l._key, e.target.value)} onKeyDown={onCellEnter(l._key, 'vat')} />
                        </td>
                      )}
                      <td className="px-2 py-1 text-right font-bold text-slate-800"><Money value={calcLine(l, isTax)} /></td>
                      <td className="px-2 py-1">
                        {badge && (
                          <span className={`inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${badge.cls}`}
                            title={`Available now: ${Number(l.stock.available_qty).toLocaleString(undefined, { maximumFractionDigits: 2 })} ${sym}`}>
                            {badge.label}{l.stock.stock_status === 'partial' ? ` ${Number(l.stock.available_qty).toLocaleString(undefined, { maximumFractionDigits: 2 })}` : ''}
                          </span>
                        )}
                      </td>
                      <td className="px-1 py-1 text-center">
                        <button type="button" onClick={() => removeLine(l._key)} className="rounded p-1 text-slate-300 hover:bg-red-50 hover:text-red-500" title="Remove line">
                          <Trash2 size={12} />
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
              <tfoot>
                <tr className="bg-indigo-50 border-t border-indigo-100">
                  <td colSpan={isTax ? 7 : 6} className="px-3 py-1.5">
                    <div className="flex items-center gap-4 text-xs">
                      <button type="button" onClick={addLine} className="flex items-center gap-1 rounded border border-indigo-200 bg-white px-2 py-0.5 text-[11px] font-bold text-indigo-600 hover:bg-indigo-50">
                        <Plus size={11} /> Add Line <kbd className="ml-1 rounded bg-indigo-100 px-1 text-[9px] font-mono text-indigo-500">Alt+N</kbd>
                      </button>
                      <span className="text-slate-500">Lines: <Money value={totals.lineTotal} className="font-bold text-slate-700" /></span>
                      <span className="text-slate-500">Transport: <Money value={totals.transport} className="font-bold text-slate-700" /></span>
                    </div>
                  </td>
                  <td colSpan={4} className="px-3 py-1.5 text-right">
                    <span className="mr-2 text-[10px] font-bold uppercase tracking-wider text-indigo-500">Proforma Total</span>
                    <Money value={totals.grand} className="text-base font-black text-indigo-700" />
                  </td>
                </tr>
              </tfoot>
            </table>
          </div>

          {err('items') && <div className="border-t border-red-100 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600">{err('items')}</div>}

          <div className="flex items-center justify-end gap-2 border-t border-slate-100 bg-white px-3 py-2">
            <button type="button" disabled={saving} onClick={() => handleSave(false)}
              className="flex items-center gap-1 rounded border border-indigo-300 bg-white px-4 py-1.5 text-xs font-bold text-indigo-600 transition-all hover:bg-indigo-50 disabled:opacity-60 active:scale-95">
              {saving ? <RefreshCw size={11} className="animate-spin" /> : <Save size={11} />} {isEdit ? 'Update Draft' : 'Save Draft'}
            </button>
            <button type="button" disabled={saving} onClick={() => handleSave(true)}
              className="flex items-center gap-1 rounded bg-blue-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-blue-700 disabled:opacity-60 active:scale-95">
              <Send size={11} /> {isEdit ? 'Update & Issue' : 'Save & Issue'}
              <kbd className="ml-1 rounded border border-blue-300 bg-blue-500 px-1 text-[9px] font-semibold text-blue-50">Ctrl+Enter</kbd>
            </button>
          </div>
        </div>
      </div>

      <KeyboardShortcuts shortcuts={[
        { keys: ['Alt', 'N'], desc: 'Add a line' },
        { keys: ['Enter'], desc: 'Qty → Price → Disc → VAT → next line' },
        { keys: ['↑', '↓'], desc: 'Navigate product search results' },
        { keys: ['Esc'], desc: 'Close product search' },
        { keys: ['Ctrl', 'Enter'], desc: 'Save & Issue' },
      ]} />
    </div>
  )
}
