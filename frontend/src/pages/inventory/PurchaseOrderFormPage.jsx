import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import {
  AlertTriangle, Building2, CalendarDays, ChevronDown, Download, Package, Plus, Printer, RefreshCw, Save, Trash2, X,
} from 'lucide-react'
import {
  createPurchaseOrder,
  downloadPoPdf,
  getNextPoNo,
  getPurchaseOrder,
  loadPOFromPR,
  updatePurchaseOrder,
} from '../../api/purchaseOrders'
import { getProducts, getProduct } from '../../api/products'
import { getPurchaseRequests } from '../../api/purchaseRequests'
import { getAllLocations } from '../../api/locations'
import { getAllStores } from '../../api/stores'
import { getAllSuppliers } from '../../api/suppliers'
import { getAllUnitTypesFlat } from '../../api/unitTypes'
import { getAllAttributeTypes } from '../../api/attributeTypes'
import { getAllAttributes } from '../../api/attributes'
import Breadcrumb from '../../components/Breadcrumb'
import Money from '../../components/ui/Money'
import { CURRENCY, fmtMoney } from '../../utils/currency'
import { showError, showSuccess } from '../../utils/alerts'
import { printPdfBlob } from '../../utils/pdf'

const PAYMENT_TERMS = ['Net 15', 'Net 30', 'Net 45', 'Net 60', 'COD', 'Advance', 'LC']

const ALL_STATUSES = [
  { value: 'draft',              label: 'Draft' },
  { value: 'sent',               label: 'Sent to Supplier' },
  { value: 'confirmed',          label: 'Confirmed' },
  { value: 'partially_received', label: 'Partially Received' },
  { value: 'completed',          label: 'Completed' },
  { value: 'cancelled',          label: 'Cancelled' },
]

const STATUS_SELECT_STYLES = {
  draft:              { background: '#f1f5f9', color: '#475569', borderColor: '#94a3b8' },
  sent:               { background: '#eff6ff', color: '#1d4ed8', borderColor: '#93c5fd' },
  confirmed:          { background: '#eef2ff', color: '#4338ca', borderColor: '#a5b4fc' },
  partially_received: { background: '#fffbeb', color: '#b45309', borderColor: '#fcd34d' },
  completed:          { background: '#f0fdf4', color: '#15803d', borderColor: '#86efac' },
  cancelled:          { background: '#fef2f2', color: '#dc2626', borderColor: '#fca5a5' },
}

const INPUT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20'
const INPUT_ERR_CLS =
  'block w-full rounded border border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-1 focus:ring-red-500/20'
const SELECT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20 cursor-pointer'
const SELECT_ERR_CLS =
  'block w-full rounded border border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-1 focus:ring-red-500/20 cursor-pointer'
const SELECT_DISABLED_CLS =
  'block w-full rounded border border-slate-200 bg-slate-100 px-2 py-1 text-xs text-slate-400 outline-none cursor-not-allowed'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS        = 'text-[10px] text-red-500 leading-tight'
const TABLE_INPUT =
  'block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white'
const TABLE_DROPDOWN_BTN =
  'flex w-full items-center justify-between gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white cursor-pointer'
const TABLE_DROPDOWN_BTN_ERR =
  'flex w-full items-center justify-between gap-1 rounded border border-red-400 bg-red-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-red-500 focus:bg-white cursor-pointer'
const TABLE_DROPDOWN_BTN_DISABLED =
  'flex w-full items-center justify-between gap-1 rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-xs text-slate-400 outline-none cursor-not-allowed'

function SectionHeader({ icon: Icon, title, colorClass, extra }) {
  return (
    <div className={`flex items-center justify-between gap-1.5 px-3 py-1.5 border-b rounded-t-lg ${colorClass}`}>
      <div className="flex items-center gap-1.5">
        {Icon && <Icon size={12} />}
        <h2 className="text-xs font-bold">{title}</h2>
      </div>
      {extra}
    </div>
  )
}

/* ── Product search cell (searchable dropdown, same style as GRN's Product cell) ── */
function ProductSearchCell({ row, productSearch, onQueryChange, onSelect, onClear, onClose, onInputRef }) {
  const [highlightIdx, setHighlightIdx] = useState(0)
  const [dropPos, setDropPos]           = useState({ top: 0, left: 0, width: 280 })
  const inputRef = useRef(null)

  const results    = productSearch.key === row._key ? (productSearch.results ?? []) : []
  const isOpen     = productSearch.key === row._key && productSearch.open && results.length > 0
  const isSelected = Boolean(row.product_id)

  // Reset highlight when a new result list arrives
  useEffect(() => { setHighlightIdx(0) }, [results.length])

  // Recalculate dropdown position whenever it opens
  useEffect(() => {
    if (isOpen && inputRef.current) {
      const rect = inputRef.current.getBoundingClientRect()
      setDropPos({
        top:   rect.bottom + 2,
        left:  rect.left,
        width: Math.max(rect.width, 280),
      })
    }
  }, [isOpen])

  const handleKeyDown = (e) => {
    if (e.key === 'ArrowDown') {
      if (!isOpen) return
      e.preventDefault()
      setHighlightIdx((i) => Math.min(i + 1, results.length - 1))
    } else if (e.key === 'ArrowUp') {
      if (!isOpen) return
      e.preventDefault()
      setHighlightIdx((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (isOpen && results[highlightIdx]) onSelect(row._key, results[highlightIdx])
    } else if (e.key === 'Escape') {
      onClose?.()
    }
  }

  if (isSelected) {
    return (
      <div className="flex items-center gap-1 min-w-0">
        <span className="truncate text-xs font-medium text-slate-700">{row.product_name}</span>
        <button
          type="button"
          title="Clear product"
          onClick={() => onClear(row._key)}
          className="shrink-0 text-slate-300 hover:text-red-500 transition-colors"
        >
          <X size={10} />
        </button>
      </div>
    )
  }

  return (
    <div className="relative">
      <input
        ref={(el) => { inputRef.current = el; onInputRef?.(el) }}
        type="text"
        placeholder="Search product…"
        className={TABLE_INPUT}
        value={productSearch.key === row._key ? productSearch.query : ''}
        onChange={(e) => onQueryChange(row._key, e.target.value)}
        onKeyDown={handleKeyDown}
        onFocus={() => {
          const currentQuery = productSearch.key === row._key ? productSearch.query : ''
          onQueryChange(row._key, currentQuery)
        }}
        autoComplete="off"
      />
      {isOpen && createPortal(
        <div
          style={{ position: 'fixed', top: dropPos.top, left: dropPos.left, width: dropPos.width, zIndex: 9999 }}
          className="rounded border border-slate-200 bg-white shadow-xl max-h-52 overflow-y-auto"
        >
          {results.map((p, i) => (
            <button
              key={p.id}
              type="button"
              className={`flex w-full items-center gap-2 px-2 py-1.5 text-left text-xs transition-colors ${
                i === highlightIdx
                  ? 'bg-indigo-100 text-indigo-900'
                  : 'hover:bg-slate-50 text-slate-700'
              }`}
              onMouseDown={(e) => { e.preventDefault(); onSelect(row._key, p) }}
              onMouseEnter={() => setHighlightIdx(i)}
            >
              <span className="font-mono text-[10px] text-indigo-400 shrink-0 w-20 truncate">{p.product_code}</span>
              <span className="truncate font-medium">{p.name}</span>
            </button>
          ))}
        </div>,
        document.body
      )}
    </div>
  )
}

/* ── Custom dropdown cell (Color / Unit) — opens automatically on focus so the
   Enter-key row-navigation chain can "show the option pad" the way a native
   <select> can't (browsers block scripts from opening a native select's popup). ── */
function DropdownSelectCell({ groups, value, onChange, onNext, onBlur, placeholder = '—', disabled, error, cellRef }) {
  const [open, setOpen] = useState(false)
  const [highlightIdx, setHighlightIdx] = useState(0)
  const [dropPos, setDropPos] = useState({ top: 0, left: 0, width: 120 })
  const btnRef = useRef(null)
  const containerRef = useRef(null)

  const flatItems = useMemo(() => groups.flatMap((g) => g.items), [groups])
  const selected = flatItems.find((it) => String(it.value) === String(value))

  const openDropdown = () => {
    if (disabled) return
    const idx = flatItems.findIndex((it) => String(it.value) === String(value))
    setHighlightIdx(idx >= 0 ? idx : 0)
    setOpen(true)
  }

  useEffect(() => {
    if (open && btnRef.current) {
      const rect = btnRef.current.getBoundingClientRect()
      setDropPos({ top: rect.bottom + 2, left: rect.left, width: Math.max(rect.width, 140) })
    }
  }, [open])

  useEffect(() => {
    if (!open) return
    const handler = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const selectItem = (item) => {
    onChange(item.value)
    setOpen(false)
    setTimeout(() => onNext?.(), 0)
  }

  const handleKeyDown = (e) => {
    if (disabled) return
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      if (!open) { openDropdown(); return }
      setHighlightIdx((i) => Math.min(i + 1, flatItems.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      if (!open) { openDropdown(); return }
      setHighlightIdx((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      e.preventDefault()
      if (open && flatItems[highlightIdx]) {
        selectItem(flatItems[highlightIdx])
      } else {
        setOpen(false)
        onNext?.()
      }
    } else if (e.key === 'Escape') {
      e.preventDefault()
      setOpen(false)
    } else if (e.key === ' ') {
      e.preventDefault()
      if (open) setOpen(false)
      else openDropdown()
    }
  }

  const btnClass = disabled ? TABLE_DROPDOWN_BTN_DISABLED : error ? TABLE_DROPDOWN_BTN_ERR : TABLE_DROPDOWN_BTN

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        ref={(el) => { btnRef.current = el; cellRef?.(el) }}
        disabled={disabled}
        onFocus={openDropdown}
        onClick={() => { if (!disabled) { if (open) setOpen(false); else openDropdown() } }}
        onKeyDown={handleKeyDown}
        onBlur={onBlur}
        className={btnClass}
      >
        <span className={`truncate text-left ${selected ? '' : 'text-slate-400'}`}>{selected ? selected.label : placeholder}</span>
        <ChevronDown size={10} className="shrink-0 text-slate-400" />
      </button>
      {open && !disabled && createPortal(
        <div
          style={{ position: 'fixed', top: dropPos.top, left: dropPos.left, width: dropPos.width, zIndex: 9999 }}
          className="rounded border border-slate-200 bg-white shadow-xl max-h-52 overflow-y-auto"
        >
          {flatItems.length === 0 && <div className="px-2 py-1.5 text-xs text-slate-400">No options</div>}
          {groups.map((g, gi) => (
            g.items.length === 0 ? null : (
              <div key={gi}>
                {g.label && (
                  <div className="px-2 py-1 text-[9px] font-bold uppercase tracking-wider text-slate-400 bg-slate-50">{g.label}</div>
                )}
                {g.items.map((it) => {
                  const flatIdx = flatItems.indexOf(it)
                  return (
                    <button
                      key={it.value}
                      type="button"
                      className={`block w-full px-2 py-1 text-left text-xs transition-colors ${
                        flatIdx === highlightIdx ? 'bg-indigo-100 text-indigo-900' : 'hover:bg-slate-50 text-slate-700'
                      }`}
                      onMouseDown={(e) => { e.preventDefault(); selectItem(it) }}
                      onMouseEnter={() => setHighlightIdx(flatIdx)}
                    >
                      {it.label}
                    </button>
                  )
                })}
              </div>
            )
          ))}
        </div>,
        document.body
      )}
    </div>
  )
}

function emptyItem() {
  return {
    _key:             Date.now() + Math.random(),
    product_id:       '',
    product_code:     '',
    product_name:     '',
    pr_item_id:       null,
    quantity_ordered: '',
    unit_id:          '',
    attribute_id:     '',
    color_options:    [],
    unit_price:       '',
    discount:         '',
    tax:              '',
  }
}

// "Color" or "Colour" — attribute-type spelling varies by who entered the master data.
function isColorAttributeTypeName(name) {
  const n = (name || '').trim().toLowerCase()
  return n === 'color' || n === 'colour'
}

function calcItem(row) {
  const qty     = parseFloat(row.quantity_ordered) || 0
  const price   = parseFloat(row.unit_price)       || 0
  const discPct = parseFloat(row.discount)         || 0
  const gross    = qty * price
  const discAmt  = gross * (discPct / 100)
  const taxAmt   = 0 // item-wise tax feature hidden — always inert
  const amount   = gross - discAmt + taxAmt
  return { gross, discAmt, taxAmt, amount }
}

const EMPTY_FORM = {
  po_no:                  '',
  pr_id:                  '',
  supplier_id:            '',
  location_id:            '',
  store_id:               '',
  order_date:             new Date().toISOString().slice(0, 10),
  expected_delivery_date: '',
  reference_no:           '',
  payment_terms:          '',
  contact_person_name:    '',
  contact_person_phone:   '',
  is_consignment:         false,
  billing_address:        '',
  shipping_address:       '',
  remarks:                '',
  status:                 'draft',
}

export default function PurchaseOrderFormPage() {
  const { id }      = useParams()
  const isEdit      = Boolean(id)
  const navigate    = useNavigate()
  const [search]    = useSearchParams()
  const prIdFromUrl = search.get('pr_id')
  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Purchase Orders', to: '/inventory/purchase-orders' },
    { label: isEdit ? 'Edit PO' : 'New PO' },
  ]

  const today = new Date().toISOString().slice(0, 10)

  const [form, setForm]               = useState({ ...EMPTY_FORM, pr_id: prIdFromUrl ?? '', order_date: today })
  const [items, setItems]             = useState([emptyItem()])
  const [errors, setErrors]           = useState({})
  const [itemTouched, setItemTouched] = useState({})
  const [isDownloading, setIsDownloading] = useState(false)
  const [isPrinting,    setIsPrinting]    = useState(false)

  /* ── Product search state (searchable dropdown for Order Items) ──── */
  const [productSearch, setProductSearch] = useState({ key: null, query: '', results: [], open: false })
  const searchTimerRef = useRef(null)

  /* ── Cell refs for Enter-key row navigation ───────────────── */
  const cellRefs = useRef({}) // { [rowKey]: { product, color, qty, unit, price, disc, tax } }

  const setCellRef = (rowKey, field) => (el) => {
    if (!cellRefs.current[rowKey]) cellRefs.current[rowKey] = {}
    cellRefs.current[rowKey][field] = el
  }

  const focusCell = (rowKey, field) => {
    cellRefs.current[rowKey]?.[field]?.focus()
  }

  const { data: locations  = [] } = useQuery({ queryKey: ['locations-all'],  queryFn: getAllLocations })
  const { data: suppliers  = [] } = useQuery({ queryKey: ['suppliers-all'],  queryFn: getAllSuppliers })
  const { data: stores     = [] } = useQuery({ queryKey: ['stores-all'],     queryFn: getAllStores })
  // Flat, cross-category list — a product's UOM (from its sales channel) may belong
  // to any unit category, not just the system default one.
  const { data: unitTypes  = [] } = useQuery({ queryKey: ['unit-types-flat'], queryFn: getAllUnitTypesFlat, staleTime: 5 * 60 * 1000 })
  const uomGroups = unitTypes.reduce((acc, u) => {
    const key = String(u.unit_category_id)
    if (!acc[key]) acc[key] = { name: u.unit_category_name ?? 'Other', items: [] }
    acc[key].items.push(u)
    return acc
  }, {})

  // Color options are scoped to the SELECTED PRODUCT's own "Product Attributes"
  // (set at product creation), not just its category — a product may only come in
  // a subset of the colors its category's Color attribute type defines.
  const { data: attributeTypes = [] } = useQuery({ queryKey: ['attribute-types-all'], queryFn: getAllAttributeTypes })
  const { data: allAttributes  = [] } = useQuery({ queryKey: ['attributes-all'],      queryFn: getAllAttributes })
  const colorOptionsCache    = useRef({})
  const attributeTypesRef    = useRef(attributeTypes)
  const allAttributesRef     = useRef(allAttributes)
  const itemsRef             = useRef(items)
  const attrRehydratedRef    = useRef(false)
  attributeTypesRef.current  = attributeTypes
  allAttributesRef.current   = allAttributes
  itemsRef.current           = items

  const loadColorOptions = (productId) => {
    if (!productId) return Promise.resolve([])
    const typesReady = attributeTypesRef.current.length > 0
    if (typesReady && colorOptionsCache.current[productId]) return colorOptionsCache.current[productId]

    const promise = getProduct(productId)
      .then((res) => {
        const colorTypeIds = new Set(
          attributeTypesRef.current.filter((t) => isColorAttributeTypeName(t.attribute_type_name)).map((t) => String(t.id))
        )
        return (res.data.product_attributes ?? [])
          .filter((pa) => colorTypeIds.has(String(pa.attribute_type_id)))
          .map((pa) => allAttributesRef.current.find((a) => String(a.id) === String(pa.attribute_id)))
          .filter(Boolean)
          .map((a) => ({ id: a.id, name: a.attribute_name }))
      })
      .catch(() => [])

    if (typesReady) colorOptionsCache.current[productId] = promise
    return promise
  }

  // Fetch color options for a batch of rows (edit/PR hydration) and patch them in once resolved.
  const hydrateColorOptions = (rows) => {
    rows.forEach((row) => {
      if (!row.product_id) return
      loadColorOptions(row.product_id).then((options) => {
        setItems((prev) => prev.map((r) => r._key === row._key ? { ...r, color_options: options } : r))
      })
    })
  }

  // Re-hydrate once attribute data arrives (fixes edit-mode race where hydrateColorOptions
  // ran before attributeTypes loaded, leaving color_options empty and validation skipped).
  useEffect(() => {
    if (attributeTypes.length === 0 || allAttributes.length === 0) return
    if (attrRehydratedRef.current) return
    attrRehydratedRef.current = true
    colorOptionsCache.current = {}
    itemsRef.current.filter((r) => r.product_id).forEach((row) => {
      loadColorOptions(row.product_id).then((options) => {
        setItems((prev) => prev.map((r) => r._key === row._key ? { ...r, color_options: options } : r))
      })
    })
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [attributeTypes.length, allAttributes.length])

  const filteredStores = useMemo(
    () => form.location_id
      ? stores.filter((s) => String(s.location_id) === String(form.location_id))
      : [],
    [stores, form.location_id]
  )

  // Default Location & Store to the first available option (create mode only —
  // editing an existing PO keeps its saved values via the hydration effect below).
  const locationSeeded = useRef(false)
  useEffect(() => {
    if (!isEdit && !locationSeeded.current && locations.length > 0) {
      setForm((f) => (f.location_id ? f : { ...f, location_id: String(locations[0].id) }))
      locationSeeded.current = true
    }
  }, [isEdit, locations])

  const storeSeeded = useRef(false)
  useEffect(() => {
    if (!isEdit && !storeSeeded.current && form.location_id && filteredStores.length > 0) {
      setForm((f) => (f.store_id ? f : { ...f, store_id: String(filteredStores[0].id) }))
      storeSeeded.current = true
    }
  }, [isEdit, form.location_id, filteredStores])

  const { data: approvedPRs } = useQuery({
    queryKey: ['prs-approved'],
    queryFn:  () => getPurchaseRequests(1, { status: 'approved' }),
  })

  const { data: nextPoNo, isLoading: loadingPoNo } = useQuery({
    queryKey: ['next-po-no'],
    queryFn:  getNextPoNo,
    enabled:  !isEdit,
    staleTime: 0,
  })

  useEffect(() => {
    if (!isEdit && nextPoNo) {
      setForm((f) => ({ ...f, po_no: f.po_no || nextPoNo }))
    }
  }, [nextPoNo, isEdit])

  const { data: existingPO, isLoading: loadingPO } = useQuery({
    queryKey: ['purchase-order', id],
    queryFn:  () => getPurchaseOrder(id),
    enabled:  isEdit,
  })

  useEffect(() => {
    if (!existingPO?.data) return
    const po = existingPO.data
    setForm({
      po_no:                  po.po_no                  ?? '',
      pr_id:                  po.pr_id                  ?? '',
      supplier_id:            po.supplier_id            ?? '',
      location_id:            po.location_id            ?? '',
      store_id:               po.store_id               ?? '',
      order_date:             po.order_date             ?? today,
      expected_delivery_date: po.expected_delivery_date ?? '',
      reference_no:           po.reference_no           ?? '',
      payment_terms:          po.payment_terms          ?? '',
      contact_person_name:    po.contact_person_name    ?? '',
      contact_person_phone:   po.contact_person_phone   ?? '',
      is_consignment:         po.is_consignment         ?? false,
      billing_address:        po.billing_address        ?? '',
      shipping_address:       po.shipping_address       ?? '',
      remarks:                po.remarks                ?? '',
      status:                 po.status                 ?? '',
    })
    if (po.items?.length) {
      const newItems = po.items.map((it) => ({
        _key:             it.id,
        product_id:       it.product_id,
        product_code:     it.product?.product_code ?? '',
        product_name:     it.product?.name         ?? '',
        pr_item_id:       it.pr_item_id            ?? null,
        quantity_ordered: it.quantity_ordered,
        unit_id:          it.unit_id               ?? '',
        attribute_id:     it.attribute_id != null ? String(it.attribute_id) : '',
        color_options:    [],
        unit_price:       it.unit_price,
        discount:         it.discount              ?? '',
        tax:              it.tax                   ?? '',
      }))
      setItems(newItems)
      hydrateColorOptions(newItems)
    }
  }, [existingPO])

  const loadPRMutation = useMutation({
    mutationFn: loadPOFromPR,
    onSuccess: (result) => {
      const data = result.data
      if (data?.items?.length) {
        const newItems = data.items.map((it) => ({
          _key:             Date.now() + Math.random(),
          product_id:       it.product_id,
          product_code:     it.product?.product_code ?? '',
          product_name:     it.product?.name         ?? '',
          pr_item_id:       it.pr_item_id,
          quantity_ordered: it.quantity_ordered,
          unit_id:          it.unit_id ?? '',
          attribute_id:     it.attribute_id != null ? String(it.attribute_id) : '',
          color_options:    [],
          unit_price:       it.unit_price,
          discount:         '',
          tax:              '',
        }))
        setItems(newItems)
        hydrateColorOptions(newItems)
      }
      if (data?.pr?.target_store_id) {
        setForm((f) => ({ ...f, store_id: data.pr.target_store_id }))
      }
    },
    onError: () => showError('Failed to load items from PR.'),
  })

  useEffect(() => {
    if (prIdFromUrl && !isEdit) {
      setForm((f) => ({ ...f, pr_id: prIdFromUrl }))
      loadPRMutation.mutate(prIdFromUrl)
    }
  }, [prIdFromUrl])

  const handlePRChange = (prId) => {
    setForm((f) => ({ ...f, pr_id: prId }))
    if (prId) loadPRMutation.mutate(prId)
    else setItems([emptyItem()])
  }

  const handleSupplierChange = (supplierId) => {
    clearFieldError('supplier_id')
    if (!supplierId) {
      setForm((f) => ({ ...f, supplier_id: '' }))
      return
    }
    const supplier = suppliers.find((s) => String(s.id) === String(supplierId))
    if (!supplier) {
      setForm((f) => ({ ...f, supplier_id: supplierId }))
      return
    }
    const billingAddress = [
      supplier.bil_address_line_1,
      supplier.bil_address_line_2,
      supplier.bil_address_line_3,
      supplier.bil_city,
      supplier.bil_postal_code,
      supplier.bil_state_province,
      supplier.bil_country,
    ].filter(Boolean).join(', ')

    setForm((f) => ({
      ...f,
      supplier_id:          supplierId,
      contact_person_name:  supplier.contact_person_name   || f.contact_person_name,
      contact_person_phone: supplier.contact_person_mobile || f.contact_person_phone,
      billing_address:      billingAddress                 || f.billing_address,
      shipping_address:     billingAddress                 || f.shipping_address,
    }))
  }

  const storeSelectRef = useRef(null)

  const handleLocationChange = (locationId) => {
    setForm((f) => ({ ...f, location_id: locationId, store_id: '' }))
    if (errors.location_id || errors.store_id) {
      setErrors((prev) => ({ ...prev, location_id: undefined, store_id: undefined }))
    }
    // Auto-advance: focus Store and open its options pad once it renders
    if (locationId) {
      setTimeout(() => {
        const el = storeSelectRef.current
        if (!el) return
        el.focus()
        try { el.showPicker?.() } catch { /* unsupported browser — focus is enough */ }
      }, 0)
    }
  }

  /* ── Product search for Order Items ───────────────────────── */
  const handleProductQueryChange = (rowKey, query) => {
    setProductSearch({ key: rowKey, query, results: [], open: false })
    clearTimeout(searchTimerRef.current)

    const doFetch = async (search) => {
      try {
        const params = search ? { search, per_page: 30 } : { per_page: 100 }
        const res = await getProducts(1, params)
        setProductSearch((prev) =>
          prev.key === rowKey
            ? { ...prev, results: res.data ?? [], open: true }
            : prev
        )
      } catch { /* silent */ }
    }

    if (query === '') {
      doFetch('')              // immediate — show all on focus
    } else {
      searchTimerRef.current = setTimeout(() => doFetch(query), 300)
    }
  }

  const selectProduct = (rowKey, product) => {
    // Default UOM from the product's first sales channel (Product creation's "Unit of Measure").
    // Editable afterward — this is just a starting guess, not a lock.
    const defaultUnitTypeId = product.cost_details?.[0]?.unit_type_id
    setItems((prev) => prev.map((row) =>
      row._key === rowKey
        ? {
            ...row,
            product_id:   product.id,
            product_code: product.product_code ?? '',
            product_name: product.name ?? '',
            unit_id:      defaultUnitTypeId != null ? String(defaultUnitTypeId) : '',
            attribute_id: '',
            color_options: [],
          }
        : row
    ))
    setProductSearch({ key: null, query: '', results: [], open: false })

    loadColorOptions(product.id).then((options) => {
      setItems((prev) => prev.map((row) => row._key === rowKey ? { ...row, color_options: options } : row))
      // Auto-focus Color once its options are ready — skip straight to Qty if this
      // product has none, since a disabled Color select can't receive focus.
      setTimeout(() => focusCell(rowKey, options.length > 0 ? 'color' : 'qty'), 50)
    })
  }

  const clearProductSelection = (rowKey) => {
    setItems((prev) => prev.map((row) =>
      row._key === rowKey
        ? { ...row, product_id: '', product_code: '', product_name: '', attribute_id: '', color_options: [] }
        : row
    ))
    setProductSearch({ key: rowKey, query: '', results: [], open: false })
  }

  const clearFieldError = (field) => {
    if (errors[field]) setErrors((prev) => ({ ...prev, [field]: undefined }))
  }

  const addRows = (n = 1) => setItems((prev) => [...prev, ...Array.from({ length: n }, emptyItem)])

  const addManualRow = useCallback(() => {
    const row = emptyItem()
    setItems((prev) => [...prev, row])
    // Auto-focus the product search input on the new row
    setTimeout(() => cellRefs.current[row._key]?.product?.focus(), 50)
  }, [])

  const removeRow   = (idx)   => setItems((prev) => prev.length > 1 ? prev.filter((_, i) => i !== idx) : prev)
  const setRowField = (idx, field, value) => setItems((prev) => prev.map((row, i) => i === idx ? { ...row, [field]: value } : row))

  /* ── Alt+N shortcut to add a new item row ─────────────────── */
  useEffect(() => {
    const handler = (e) => {
      if (e.altKey && (e.key === 'n' || e.key === 'N')) {
        e.preventDefault()
        addManualRow()
      }
    }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [addManualRow])
  const setField    = (field) => (e) => {
    setForm((f) => ({ ...f, [field]: e.target.value }))
    clearFieldError(field)
  }

  const totals = items.reduce(
    (acc, row) => {
      const { gross, discAmt, taxAmt, amount } = calcItem(row)
      return { gross: acc.gross + gross, disc: acc.disc + discAmt, tax: acc.tax + taxAmt, total: acc.total + amount }
    },
    { gross: 0, disc: 0, tax: 0, total: 0 },
  )

  const saveMutation = useMutation({
    mutationFn: (payload) => isEdit ? updatePurchaseOrder(id, payload) : createPurchaseOrder(payload),
    onSuccess: () => {
      showSuccess(isEdit ? 'Purchase order updated.' : 'Purchase order created.')
      navigate('/inventory/purchase-orders')
    },
    onError: (e) => {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save purchase order.')
    },
  })

  /* ── PDF download / print ─────────────────────────────────── */
  const handleDownloadPdf = async () => {
    if (!isEdit) { showError('Save the purchase order first before downloading the PDF.'); return }
    setIsDownloading(true)
    try {
      const blob = await downloadPoPdf(id)
      const url  = URL.createObjectURL(blob)
      const a    = document.createElement('a')
      a.href     = url
      a.download = `PO_${form.po_no || id}.pdf`
      a.click()
      URL.revokeObjectURL(url)
    } catch {
      showError('Failed to download PDF.')
    } finally {
      setIsDownloading(false)
    }
  }

  const handlePrintPdf = async () => {
    if (!isEdit) { showError('Save the purchase order first before printing.'); return }
    setIsPrinting(true)
    try {
      const blob = await downloadPoPdf(id)
      printPdfBlob(blob)
    } catch {
      showError('Failed to print PDF.')
    } finally {
      setIsPrinting(false)
    }
  }

  const handleSubmit = () => {
    const clientErrors = {}

    if (!form.po_no.trim())
      clientErrors.po_no = ['PO Number is required.']
    else if (form.po_no.trim().length > 30)
      clientErrors.po_no = ['PO Number must not exceed 30 characters.']

    if (!form.supplier_id)
      clientErrors.supplier_id = ['Supplier is required.']

    if (!form.location_id)
      clientErrors.location_id = ['Location is required.']

    if (!form.store_id)
      clientErrors.store_id = ['Store is required.']

    if (!form.order_date)
      clientErrors.order_date = ['Transaction Date is required.']

    if (form.expected_delivery_date && form.order_date && form.expected_delivery_date < form.order_date)
      clientErrors.expected_delivery_date = ['Expected delivery date must be on or after the transaction date.']

    if (!form.contact_person_name.trim())
      clientErrors.contact_person_name = ['Contact Person is required.']
    else if (form.contact_person_name.trim().length > 100)
      clientErrors.contact_person_name = ['Contact Person must not exceed 100 characters.']

    if (!form.contact_person_phone.trim())
      clientErrors.contact_person_phone = ['Contact Phone is required.']
    else if (form.contact_person_phone.trim().length > 30)
      clientErrors.contact_person_phone = ['Contact Phone must not exceed 30 characters.']

    if (!form.billing_address.trim())
      clientErrors.billing_address = ['Billing Address is required.']
    else if (form.billing_address.trim().length > 500)
      clientErrors.billing_address = ['Billing Address must not exceed 500 characters.']

    if (form.shipping_address.trim().length > 500)
      clientErrors.shipping_address = ['Shipping Address must not exceed 500 characters.']

    if (form.remarks.trim().length > 1000)
      clientErrors.remarks = ['Remarks must not exceed 1000 characters.']

    const validItems = items.filter((r) => r.product_id && parseFloat(r.quantity_ordered) > 0)
    if (validItems.length === 0)
      clientErrors.items = ['At least one product with a valid quantity is required.']

    const itemsMissingUom = validItems.filter((r) => !r.unit_id)
    if (itemsMissingUom.length) {
      setItemTouched((t) => {
        const next = { ...t }
        itemsMissingUom.forEach((r) => { next[r._key] = { ...next[r._key], uom: true } })
        return next
      })
      if (!clientErrors.items) clientErrors.items = ['Unit of measure is required for all line items.']
    }

    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors)
      showError('Please fill in all required fields.')
      return
    }

    const itemsMissingColor = validItems.filter((r) => r.color_options?.length > 0 && !r.attribute_id)
    if (itemsMissingColor.length) {
      setItemTouched((t) => {
        const next = { ...t }
        itemsMissingColor.forEach((r) => { next[r._key] = { ...next[r._key], color: true } })
        return next
      })
      showError('Color is required for all items with color options.')
      return
    }

    setErrors({})
    saveMutation.mutate({
      ...form,
      po_no:       form.po_no.trim(),
      pr_id:       form.pr_id       || null,
      supplier_id: form.supplier_id || null,
      location_id: form.location_id || null,
      store_id:    form.store_id    || null,
      status:      form.status      || null,
      items: validItems.map((r) => ({
        product_id:       parseInt(r.product_id),
        pr_item_id:       r.pr_item_id ?? null,
        unit_id:          r.unit_id ? parseInt(r.unit_id) : null,
        attribute_id:     r.attribute_id ? parseInt(r.attribute_id) : null,
        quantity_ordered: parseFloat(r.quantity_ordered),
        unit_price:       parseFloat(r.unit_price)  || 0,
        discount:         parseFloat(r.discount)    || 0,
        tax:              0, // item-wise tax feature hidden
      })),
    })
  }

  const handleClear = () => {
    setForm({ ...EMPTY_FORM, order_date: today })
    setItems([emptyItem()])
    setErrors({})
    setItemTouched({})
  }

  const err = (f) => errors[f]?.[0]

  const touchItemField = (rowKey, field) =>
    setItemTouched((t) => ({ ...t, [rowKey]: { ...t[rowKey], [field]: true } }))

  const getItemErr = (rowKey, field) => {
    if (!itemTouched[rowKey]?.[field]) return null
    const row = items.find((r) => r._key === rowKey)
    if (!row) return null
    if (field === 'color') return (row.color_options?.length > 0 && !row.attribute_id) ? 'Required' : null
    if (field === 'uom') return row.unit_id ? null : 'Required'
    return null
  }

  // Rows sharing the same product + color — nudge the user to merge them instead of blocking save
  const duplicateRowNumbers = useMemo(() => {
    const groups = {}
    items.forEach((row, idx) => {
      if (!row.product_id) return
      const key = `${row.product_id}|${row.attribute_id || ''}`
      ;(groups[key] ??= []).push(idx + 1)
    })
    const result = {}
    items.forEach((row) => {
      if (!row.product_id) return
      const key = `${row.product_id}|${row.attribute_id || ''}`
      const rowNumbers = groups[key]
      if (rowNumbers.length > 1) result[row._key] = rowNumbers
    })
    return result
  }, [items])

  if (isEdit && loadingPO) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <RefreshCw size={14} className="animate-spin" /> Loading…
        </div>
      </div>
    )
  }

  return (
    <div className="w-full">
      {/* Page Header */}
      <div className="mb-2 flex items-center justify-between">
        <div>
          <h1 className="text-base font-bold leading-none text-slate-800">
            {isEdit ? 'Edit Purchase Order' : 'New Purchase Order'}
          </h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      <div className="flex flex-col gap-2">

        {/* ── Order Details ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader icon={CalendarDays} title="Order Details" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100" />
          <div className="space-y-2 p-3">

            {/* Row 1: PO No | Tx Date | Exp Delivery | Ref No | Payment Terms */}
            <div className="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-5">
              <div>
                <label className={LABEL_CLS}>PO Number <span className="text-red-500 normal-case font-bold">*</span></label>
                <input
                  className={err('po_no') ? INPUT_ERR_CLS : INPUT_CLS}
                  placeholder={!isEdit && loadingPoNo ? 'Generating…' : 'e.g. PO-2026-0001'}
                  value={form.po_no}
                  onChange={setField('po_no')}
                />
                {err('po_no') && <p className={ERR_CLS}>{err('po_no')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Transaction Date <span className="text-red-500 normal-case font-bold">*</span></label>
                <input type="date" className={err('order_date') ? INPUT_ERR_CLS : INPUT_CLS} value={form.order_date} onChange={setField('order_date')} />
                {err('order_date') && <p className={ERR_CLS}>{err('order_date')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Expected Delivery</label>
                <input type="date" className={err('expected_delivery_date') ? INPUT_ERR_CLS : INPUT_CLS} value={form.expected_delivery_date} onChange={setField('expected_delivery_date')} />
                {err('expected_delivery_date') && <p className={ERR_CLS}>{err('expected_delivery_date')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Reference No.</label>
                <input className={INPUT_CLS} placeholder="e.g. REF-001" value={form.reference_no} onChange={setField('reference_no')} />
              </div>
              <div>
                <label className={LABEL_CLS}>Payment Terms</label>
                <select className={SELECT_CLS} value={form.payment_terms} onChange={setField('payment_terms')}>
                  <option value="">— Select —</option>
                  {PAYMENT_TERMS.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </div>
            </div>

            {/* Row 2: Location | Store | From PR | Consignment | Remarks */}
            <div className="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-5">
              <div>
                <label className={LABEL_CLS}>Location <span className="text-red-500 normal-case font-bold">*</span></label>
                <select className={err('location_id') ? SELECT_ERR_CLS : SELECT_CLS} value={form.location_id} onChange={(e) => handleLocationChange(e.target.value)}>
                  <option value="">— Select location —</option>
                  {locations.map((l) => <option key={l.id} value={l.id}>{l.location_name ?? l.name}</option>)}
                </select>
                {err('location_id') && <p className={ERR_CLS}>{err('location_id')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>
                  Store <span className="text-red-500 normal-case font-bold">*</span>
                  {!form.location_id && <span className="ml-1 normal-case font-medium text-amber-500 text-[10px]">↑ first</span>}
                </label>
                {form.location_id ? (
                  <select ref={storeSelectRef} className={err('store_id') ? SELECT_ERR_CLS : SELECT_CLS} value={form.store_id} onChange={(e) => { setForm((f) => ({ ...f, store_id: e.target.value })); clearFieldError('store_id') }}>
                    <option value="">— Select store —</option>
                    {filteredStores.map((s) => <option key={s.id} value={s.id}>{s.store_name}</option>)}
                  </select>
                ) : (
                  <select className={SELECT_DISABLED_CLS} disabled><option>— Select location first —</option></select>
                )}
                {err('store_id') && <p className={ERR_CLS}>{err('store_id')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>From PR (optional)</label>
                <select className={SELECT_CLS} value={form.pr_id} onChange={(e) => handlePRChange(e.target.value)}>
                  <option value="">— Direct PO —</option>
                  {(approvedPRs?.data ?? []).map((pr) => <option key={pr.id} value={pr.id}>{pr.pr_no} — {pr.purpose || 'No purpose'}</option>)}
                </select>
              </div>
              <div>
                <label className={LABEL_CLS}>Remarks</label>
                <input className={err('remarks') ? INPUT_ERR_CLS : INPUT_CLS} placeholder="Notes…" value={form.remarks} onChange={setField('remarks')} />
                {err('remarks') && <p className={ERR_CLS}>{err('remarks')}</p>}
              </div>
              <div className="flex items-end pb-0.5">
                <label className="flex cursor-pointer select-none items-center gap-1.5 group">
                  <input type="checkbox" checked={form.is_consignment} onChange={(e) => setForm((f) => ({ ...f, is_consignment: e.target.checked }))} className="sr-only" />
                  <span className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border-2 transition-all ${form.is_consignment ? 'border-indigo-500 bg-indigo-500' : 'border-slate-300 bg-white group-hover:border-indigo-400'}`}>
                    {form.is_consignment && (
                      <svg className="h-2 w-2 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3.5}>
                        <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                      </svg>
                    )}
                  </span>
                  <span className="text-[11px] font-semibold text-slate-600 group-hover:text-slate-800 transition-colors">Consignment</span>
                </label>
              </div>
            </div>

          </div>
        </div>

        {/* ── Supplier Details ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader icon={Building2} title="Supplier Details" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100" />
          <div className="p-3">
            <div className="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-5">
              <div>
                <label className={LABEL_CLS}>Supplier <span className="text-red-500 normal-case font-bold">*</span></label>
                <select className={err('supplier_id') ? SELECT_ERR_CLS : SELECT_CLS} value={form.supplier_id} onChange={(e) => handleSupplierChange(e.target.value)}>
                  <option value="">— Select supplier —</option>
                  {suppliers.map((s) => <option key={s.id} value={s.id}>{s.supplier_name ?? s.name}</option>)}
                </select>
                {err('supplier_id') && <p className={ERR_CLS}>{err('supplier_id')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Contact Person <span className="text-red-500 normal-case font-bold">*</span></label>
                <input className={err('contact_person_name') ? INPUT_ERR_CLS : INPUT_CLS} placeholder="Name" value={form.contact_person_name} onChange={setField('contact_person_name')} />
                {err('contact_person_name') && <p className={ERR_CLS}>{err('contact_person_name')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Contact Phone <span className="text-red-500 normal-case font-bold">*</span></label>
                <input className={err('contact_person_phone') ? INPUT_ERR_CLS : INPUT_CLS} placeholder="Phone" value={form.contact_person_phone} onChange={setField('contact_person_phone')} />
                {err('contact_person_phone') && <p className={ERR_CLS}>{err('contact_person_phone')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Billing Address <span className="text-red-500 normal-case font-bold">*</span></label>
                <input className={err('billing_address') ? INPUT_ERR_CLS : INPUT_CLS} placeholder="Billing address" value={form.billing_address} onChange={setField('billing_address')} />
                {err('billing_address') && <p className={ERR_CLS}>{err('billing_address')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Shipping Address</label>
                <input className={err('shipping_address') ? INPUT_ERR_CLS : INPUT_CLS} placeholder="Shipping address" value={form.shipping_address} onChange={setField('shipping_address')} />
                {err('shipping_address') && <p className={ERR_CLS}>{err('shipping_address')}</p>}
              </div>
            </div>
          </div>
        </div>

        {/* ── Order Items ── */}
        <div className="flex flex-col rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader
            icon={Package}
            title="Order Items"
            colorClass="text-violet-700 bg-violet-50 border-violet-100"
            extra={
              <div className="flex items-center gap-2">
                {items.length > 0 && (
                  <span className="text-[10px] text-slate-500">{items.length} line{items.length !== 1 ? 's' : ''}</span>
                )}
                <button
                  type="button"
                  onClick={addManualRow}
                  title="Add new item (Alt+N)"
                  className="flex items-center gap-1 rounded border border-violet-200 bg-white px-2 py-0.5 text-[10px] font-bold text-violet-700 hover:bg-violet-100 transition-colors"
                >
                  <Plus size={10} /> Add Row
                  <span className="ml-0.5 rounded bg-violet-100 px-1 py-px text-[9px] font-mono text-violet-500">Alt+N</span>
                </button>
                <button
                  type="button"
                  onClick={() => addRows(5)}
                  className="flex items-center gap-1 rounded border border-slate-200 bg-white px-2 py-0.5 text-[10px] font-bold text-slate-500 hover:bg-slate-50 transition-colors"
                >
                  <Plus size={10} /> Add 5
                </button>
              </div>
            }
          />

          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="bg-slate-50 border-b border-slate-200">
                  <th className="w-7 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">#</th>
                  <th className="w-16 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Code</th>
                  <th className="px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Product</th>
                  <th className="w-24 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Color</th>
                  <th className="w-24 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Qty</th>
                  <th className="w-16 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Unit</th>
                  <th className="w-28 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Unit Price ({CURRENCY})</th>
                  <th className="w-24 px-1.5 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Gross ({CURRENCY})</th>
                  <th className="w-20 px-1.5 py-1.5 text-center text-[10px] font-bold uppercase tracking-wider text-amber-500">Disc%</th>
                  <th className="w-24 px-1.5 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-700">Amount ({CURRENCY})</th>
                  <th className="w-8"></th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {items.map((row, idx) => {
                  const { gross, amount } = calcItem(row)
                  return (
                    <tr key={row._key} className="group hover:bg-slate-50/60 transition-colors">
                      <td className="px-1.5 py-1 text-slate-400 font-medium tabular-nums">{idx + 1}</td>
                      <td className="px-1.5 py-1">
                        <input readOnly value={row.product_code} placeholder="—" className="block w-full rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-xs font-mono text-slate-500 outline-none cursor-not-allowed" />
                      </td>
                      <td className="px-1.5 py-1 min-w-28">
                        <ProductSearchCell
                          row={row}
                          productSearch={productSearch}
                          onQueryChange={handleProductQueryChange}
                          onSelect={selectProduct}
                          onClear={clearProductSelection}
                          onClose={() => setProductSearch((prev) => ({ ...prev, open: false }))}
                          onInputRef={setCellRef(row._key, 'product')}
                        />
                        {duplicateRowNumbers[row._key] && (
                          <span className="mt-0.5 flex items-center gap-1 text-[9px] font-medium text-amber-600">
                            <AlertTriangle size={10} />
                            Same product &amp; color as row {duplicateRowNumbers[row._key].filter((n) => n !== idx + 1).join(', ')} — consider merging
                          </span>
                        )}
                      </td>
                      <td className="px-1.5 py-1">
                        <div className="flex flex-col gap-0.5">
                          <DropdownSelectCell
                            cellRef={setCellRef(row._key, 'color')}
                            value={row.attribute_id}
                            groups={[{ label: null, items: row.color_options.map((c) => ({ value: String(c.id), label: c.name })) }]}
                            onChange={(val) => { setRowField(idx, 'attribute_id', val); touchItemField(row._key, 'color') }}
                            onNext={() => focusCell(row._key, 'qty')}
                            onBlur={() => touchItemField(row._key, 'color')}
                            placeholder={!row.product_id ? '—' : row.color_options.length === 0 ? 'No colors' : '—'}
                            disabled={!row.product_id || row.color_options.length === 0}
                            error={!!getItemErr(row._key, 'color')}
                          />
                          {getItemErr(row._key, 'color') && (
                            <span className="text-[9px] text-red-500 leading-none">Required</span>
                          )}
                        </div>
                      </td>
                      <td className="px-1.5 py-1">
                        <input
                          ref={setCellRef(row._key, 'qty')}
                          type="number" min="0" step="0.0001" placeholder="0" value={row.quantity_ordered}
                          onChange={(e) => setRowField(idx, 'quantity_ordered', e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') { e.preventDefault(); focusCell(row._key, 'unit') }
                          }}
                          className="block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white" />
                      </td>
                      <td className="px-1.5 py-1">
                        <div className="flex flex-col gap-0.5">
                          <DropdownSelectCell
                            cellRef={setCellRef(row._key, 'unit')}
                            value={row.unit_id}
                            groups={Object.values(uomGroups).map((group) => ({
                              label: group.name,
                              items: group.items.map((u) => ({ value: String(u.id), label: u.symbol ?? u.name })),
                            }))}
                            onChange={(val) => setRowField(idx, 'unit_id', val)}
                            onNext={() => focusCell(row._key, 'price')}
                            onBlur={() => touchItemField(row._key, 'uom')}
                            error={getItemErr(row._key, 'uom')}
                          />
                          {getItemErr(row._key, 'uom') && (
                            <span className="text-[9px] text-red-500 leading-none">Required</span>
                          )}
                        </div>
                      </td>
                      <td className="px-1.5 py-1">
                        <input
                          ref={setCellRef(row._key, 'price')}
                          type="number" min="0" step="0.01" placeholder="0.00" value={row.unit_price}
                          onChange={(e) => setRowField(idx, 'unit_price', e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'Enter') { e.preventDefault(); focusCell(row._key, 'disc') }
                          }}
                          className="block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white" />
                      </td>
                      <td className="px-1.5 py-1 text-right font-medium text-slate-600 tabular-nums">
                        {gross > 0 ? fmtMoney(gross) : <span className="text-slate-300">—</span>}
                      </td>
                      <td className="px-1.5 py-1">
                        <input
                          ref={setCellRef(row._key, 'disc')}
                          type="number" min="0" max="100" step="0.01" placeholder="0" value={row.discount}
                          onChange={(e) => setRowField(idx, 'discount', e.target.value)}
                          className="block w-full rounded border border-amber-200 bg-amber-50/50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-amber-400 focus:bg-white" />
                      </td>
                      <td className="px-1.5 py-1 text-right font-bold text-slate-800 tabular-nums">
                        {amount > 0 ? fmtMoney(amount) : <span className="text-slate-300 font-normal">—</span>}
                      </td>
                      <td className="px-1.5 py-1 text-center">
                        <button type="button" onClick={() => removeRow(idx)} className="rounded p-1 text-slate-300 opacity-0 group-hover:opacity-100 hover:bg-red-50 hover:text-red-500 transition-all" title="Remove row">
                          <Trash2 size={12} />
                        </button>
                      </td>
                    </tr>
                  )
                })}
              </tbody>

              <tfoot>
                <tr className="border-t border-slate-200 bg-slate-50/50">
                  <td colSpan={7} className="px-2 py-1.5"></td>
                  <td className="px-1.5 py-1.5 text-right text-xs font-semibold text-slate-600 tabular-nums">{fmtMoney(totals.gross)}</td>
                  <td className="px-1.5 py-1.5 text-center text-xs font-bold text-amber-600 tabular-nums">-{fmtMoney(totals.disc)}</td>
                  <td className="px-1.5 py-1.5 text-right text-sm font-black text-slate-800 tabular-nums">{fmtMoney(totals.total)}</td>
                  <td></td>
                </tr>
                <tr className="bg-indigo-50 border-t border-indigo-100">
                  <td colSpan={9} className="px-3 py-1.5">
                    <div className="flex items-center gap-4 text-xs">
                      <span className="font-bold uppercase tracking-wider text-indigo-600">Summary</span>
                      <span className="text-slate-500">Gross: <Money value={totals.gross} className="font-bold text-slate-700" /></span>
                      <span className="text-amber-600">Disc: -<Money value={totals.disc} className="font-bold" /></span>
                    </div>
                  </td>
                  <td className="px-3 py-1.5 text-right">
                    <div className="flex flex-col items-end leading-tight">
                      <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-500">Net Total</span>
                      <Money value={totals.total} className="text-base font-black text-indigo-700" />
                    </div>
                  </td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>

          {err('items') && (
            <div className="border-t border-red-100 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600">
              {err('items')}
            </div>
          )}

          <div className="flex items-center justify-between gap-2 border-t border-slate-100 bg-white px-3 py-2">

            {/* Left: PDF download + Print (edit mode only) */}
            <div className="flex items-center gap-2">
              {isEdit && (
                <button
                  type="button"
                  onClick={handleDownloadPdf}
                  disabled={isDownloading}
                  className="flex items-center gap-1.5 rounded border border-red-200 bg-red-50 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors disabled:opacity-60"
                >
                  <Download size={12} />
                  {isDownloading ? 'Generating…' : 'Download PDF'}
                </button>
              )}
              {isEdit && (
                <button
                  type="button"
                  onClick={handlePrintPdf}
                  disabled={isPrinting}
                  className="flex items-center gap-1.5 rounded border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-100 transition-colors disabled:opacity-60"
                >
                  <Printer size={12} />
                  {isPrinting ? 'Preparing…' : 'Print'}
                </button>
              )}
            </div>

            <div className="flex items-center gap-2">

            {/* ── Status (always visible, before Clear) ── */}
            <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Status</span>
            <select
              value={form.status}
              onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
              style={STATUS_SELECT_STYLES[form.status] ?? {}}
              className="block rounded border px-2 py-1 text-xs font-semibold outline-none cursor-pointer transition-all"
            >
              {ALL_STATUSES.filter((s) => s.value !== 'completed').map((s) => (
                <option key={s.value} value={s.value}>{s.label}</option>
              ))}
            </select>
            <div className="h-5 w-px bg-slate-200" />

            <button type="button" onClick={handleClear} className="flex items-center gap-1 rounded border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition-all hover:bg-red-50 active:scale-95">
              <RefreshCw size={11} /> Clear
            </button>
            <button type="button" disabled={saveMutation.isPending} onClick={handleSubmit} className="flex items-center gap-1 rounded bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm hover:bg-indigo-700 disabled:opacity-60 transition-all active:scale-95">
              {saveMutation.isPending ? <><RefreshCw size={11} className="animate-spin" /> Saving…</> : isEdit ? <><Save size={11} /> Update PO</> : <><Save size={11} /> Create PO</>}
            </button>

            </div>

          </div>

        </div>

      </div>

      {/* Keyboard Shortcuts Reference */}
      <div className="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
        <p className="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Keyboard Shortcuts</p>
        <div className="flex flex-wrap gap-x-5 gap-y-1">
          {[
            { keys: ['Alt', 'N'],   desc: 'Add new item row' },
            { keys: ['Enter'],      desc: 'Move to next field in row (Color → Qty → Unit → Price → Disc)' },
            { keys: ['↑', '↓'],    desc: 'Navigate product search results' },
            { keys: ['Esc'],        desc: 'Close product search dropdown' },
          ].map(({ keys, desc }) => (
            <div key={desc} className="flex items-center gap-1.5">
              <div className="flex items-center gap-0.5">
                {keys.map((k, i) => (
                  <span key={i} className="inline-flex items-center justify-center rounded border border-slate-300 bg-white px-1 py-px text-[10px] font-mono font-semibold text-slate-600 shadow-sm">
                    {k}
                  </span>
                ))}
              </div>
              <span className="text-[10px] text-slate-500">{desc}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}
