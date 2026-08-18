import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import {
  AlertTriangle, CalendarDays, ChevronDown, ClipboardList, Plus, RefreshCw, Save, Trash2, Warehouse, X,
} from 'lucide-react'
import {
  createPurchaseRequest,
  getNextPurchaseRequestReferenceNo,
  getPurchaseRequest,
  updatePurchaseRequest,
} from '../../api/purchaseRequests'
import { getAllLocations } from '../../api/locations'
import { getAllStores } from '../../api/stores'
import { getAllCustomers } from '../../api/customers'
import { getAllUnitTypesFlat } from '../../api/unitTypes'
import { getProducts, getProduct } from '../../api/products'
import { getAllAttributeTypes } from '../../api/attributeTypes'
import { getAllAttributes } from '../../api/attributes'
import Breadcrumb from '../../components/Breadcrumb'
import { showError, showSuccess } from '../../utils/alerts'
import api from '../../api/axios'

const TRANSPORT_MODES = ['Road', 'Rail', 'Air', 'Sea', 'Courier']

const INPUT_CLS =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'
const INPUT_ERR_CLS =
  'block w-full rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
const SELECT_CLS =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15 cursor-pointer'
const SELECT_DISABLED_CLS =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-100 px-2 py-1 text-xs text-slate-400 outline-none cursor-not-allowed'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function SectionHeader({ icon: Icon, title, colorClass, extra }) {
  return (
    <div className={`flex items-center justify-between gap-1.5 px-3 py-2 border-b ${colorClass}`}>
      <div className="flex items-center gap-1.5">
        {Icon && <Icon size={13} />}
        <h2 className="text-xs font-bold">{title}</h2>
      </div>
      {extra}
    </div>
  )
}

/* ── Product search cell (searchable dropdown, same style as PO/GRN's Product cell) ── */
function ProductSearchCell({ row, productSearch, onQueryChange, onSelect, onClear, onClose, onInputRef }) {
  const [highlightIdx, setHighlightIdx] = useState(0)
  const [dropPos, setDropPos]           = useState({ top: 0, left: 0, width: 280 })
  const inputRef = useRef(null)

  const results    = productSearch.key === row._key ? (productSearch.results ?? []) : []
  const isOpen     = productSearch.key === row._key && productSearch.open && results.length > 0
  const isSelected = Boolean(row.product_id)

  useEffect(() => { setHighlightIdx(0) }, [results.length])

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
        className={INPUT_CLS}
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
          className="rounded-md border border-slate-200 bg-white shadow-xl max-h-52 overflow-y-auto"
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

/* ── Custom dropdown (Color / UOM) — opens its option pad automatically on focus
   so the Enter-key chain can "show the pad" the way a native <select> can't
   (browsers block scripts from opening a native select's popup). ── */
function DropdownSelectCell({ groups, value, onChange, onNext, onBlur, placeholder = '—', disabled, error, cellRef }) {
  const [open, setOpen] = useState(false)
  const [highlightIdx, setHighlightIdx] = useState(0)
  const [dropPos, setDropPos] = useState({ top: 0, left: 0, width: 140 })
  const btnRef = useRef(null)
  const containerRef = useRef(null)

  const flatItems = groups.flatMap((g) => g.items)
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

  const btnClass = disabled
    ? 'flex w-full items-center justify-between gap-1 rounded-md border-2 border-slate-200 bg-slate-100 px-2 py-0.5 text-xs text-slate-400 outline-none cursor-not-allowed'
    : error
      ? 'flex w-full items-center justify-between gap-1 rounded-md border-2 border-red-300 bg-red-50/40 px-2 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15 cursor-pointer'
      : 'flex w-full items-center justify-between gap-1 rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15 cursor-pointer'

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
        <ChevronDown size={11} className="shrink-0 text-slate-400" />
      </button>
      {open && !disabled && createPortal(
        <div
          style={{ position: 'fixed', top: dropPos.top, left: dropPos.left, width: dropPos.width, zIndex: 9999 }}
          className="rounded-md border border-slate-200 bg-white shadow-xl max-h-56 overflow-y-auto"
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
                      className={`block w-full px-2 py-1.5 text-left text-xs transition-colors ${
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

// "Color" or "Colour" — attribute-type spelling varies by who entered the master data.
function isColorAttributeTypeName(name) {
  const n = (name || '').trim().toLowerCase()
  return n === 'color' || n === 'colour'
}

function emptyRow() {
  return {
    _key:          Date.now() + Math.random(),
    product_id:    '',
    product_code:  '',
    product_name:  '',
    unit_id:       '',
    attribute_id:  '',
    color_options: [],
    quantity:      '',
    stock_in_hand: null,
  }
}

export default function PurchaseRequestFormPage() {
  const { id }   = useParams()
  const isEdit   = Boolean(id)
  const navigate = useNavigate()

  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Purchase Requests', to: '/inventory/purchase-requests' },
    { label: isEdit ? 'Edit PR' : 'New PR' },
  ]

  const today = new Date().toISOString().slice(0, 10)

  const [form, setForm] = useState({
    request_date:        today,
    reference_no:        '',
    purpose:             '',
    source_location_id:  '',
    source_store_id:     '',
    target_location_id:  '',
    target_store_id:     '',
    customer_id:         '',
    required_date:       '',
    transport_mode:      '',
    remarks:             '',
    status:              'approved',
  })
  const [items, setItems]       = useState([emptyRow()])
  const [errors, setErrors]     = useState({})
  const [itemTouched, setItemTouched] = useState({})
  const stockCache = useRef({})
  const itemsRef   = useRef(items)

  /* ── Product search state (searchable dropdown for Request Items) ──── */
  const [productSearch, setProductSearch] = useState({ key: null, query: '', results: [], open: false })
  const searchTimerRef = useRef(null)

  /* ── Header field ref — Source Location → Source Store chain ──────── */
  const sourceStoreFieldRef = useRef(null)

  /* ── Cell refs for Enter-key row navigation ───────────────── */
  const cellRefs = useRef({}) // { [rowKey]: { product, color, uom, qty } }

  const setCellRef = (rowKey, field) => (el) => {
    if (!cellRefs.current[rowKey]) cellRefs.current[rowKey] = {}
    cellRefs.current[rowKey][field] = el
  }

  const focusCell = (rowKey, field) => {
    cellRefs.current[rowKey]?.[field]?.focus()
  }

  const { data: locations  = [] } = useQuery({ queryKey: ['locations-all'],   queryFn: getAllLocations })
  const { data: stores     = [] } = useQuery({ queryKey: ['stores-all'],      queryFn: getAllStores })
  const { data: customers  = [] } = useQuery({ queryKey: ['customers-all'],   queryFn: getAllCustomers })
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

  // Populate color_options for already-loaded rows (edit mode) without blocking the initial render
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

  const { data: nextRefNo } = useQuery({
    queryKey: ['purchase-requests-next-ref-no'],
    queryFn:  getNextPurchaseRequestReferenceNo,
    enabled:  !isEdit,
    staleTime: 0,
  })

  // Declared early so effects below can reference it without a TDZ crash
  const fetchStock = useCallback(async (productId, storeId) => {
    if (!productId || !storeId) return null
    const cacheKey = `${productId}_${storeId}`
    if (stockCache.current[cacheKey] !== undefined) return stockCache.current[cacheKey]
    try {
      const r   = await api.get('/api/v1/stock/product', { params: { product_id: productId, store_id: storeId } })
      const qty = r.data?.data?.total_stock ?? 0
      stockCache.current[cacheKey] = qty
      return qty
    } catch {
      return null
    }
  }, [])

  useEffect(() => {
    if (!isEdit && nextRefNo) {
      setForm((f) => ({ ...f, reference_no: nextRefNo }))
    }
  }, [nextRefNo, isEdit])

  // Keep itemsRef current so the stock effect reads the latest rows without a stale closure
  useEffect(() => { itemsRef.current = items }, [items])

  // Re-fetch stock for every product row whenever source store is selected or changed
  useEffect(() => {
    const storeId = form.source_store_id
    if (!storeId) {
      setItems((prev) => prev.map((row) => ({ ...row, stock_in_hand: null })))
      return
    }
    let cancelled = false
    const toFetch = itemsRef.current.filter((row) => row.product_id)
    if (!toFetch.length) return
    Promise.all(
      toFetch.map(async (row) => ({
        key:   row._key,
        stock: await fetchStock(Number(row.product_id), storeId),
      }))
    ).then((results) => {
      if (cancelled) return
      setItems((prev) =>
        prev.map((r) => {
          const hit = results.find((x) => x.key === r._key)
          return hit ? { ...r, stock_in_hand: hit.stock } : r
        })
      )
    })
    return () => { cancelled = true }
  }, [form.source_store_id, fetchStock])

  // Stores filtered by their location_id — resets child store when location changes
  const sourceStores = form.source_location_id
    ? stores.filter((s) => String(s.location_id) === String(form.source_location_id))
    : []

  const targetStores = form.target_location_id
    ? stores.filter((s) => String(s.location_id) === String(form.target_location_id))
    : []

  const { data: existingPR, isLoading: loadingPR } = useQuery({
    queryKey: ['purchase-request', id],
    queryFn:  () => getPurchaseRequest(id),
    enabled:  isEdit,
  })

  useEffect(() => {
    if (!existingPR?.data) return
    const pr = existingPR.data
    setForm({
      request_date:        pr.request_date        ?? today,
      reference_no:        pr.reference_no        ?? '',
      purpose:             pr.purpose             ?? '',
      source_location_id:  pr.source_location_id  ?? '',
      source_store_id:     pr.source_store_id     ?? '',
      target_location_id:  pr.target_location_id  ?? '',
      target_store_id:     pr.target_store_id     ?? '',
      customer_id:         pr.customer_id         ?? '',
      required_date:       pr.required_date       ?? '',
      transport_mode:      pr.transport_mode      ?? '',
      remarks:             pr.remarks             ?? '',
      status:              pr.status              ?? 'approved',
    })
    if (pr.items?.length) {
      const mappedItems = pr.items.map((it) => ({
        _key:          it.id,
        product_id:    it.product_id,
        product_code:  it.product?.product_code ?? '',
        product_name:  it.product?.name         ?? '',
        unit_id:       it.unit_id               ?? '',
        attribute_id:  it.attribute_id != null ? String(it.attribute_id) : '',
        color_options: [],
        quantity:      it.quantity,
        stock_in_hand: null,
      }))
      setItems(mappedItems)
      hydrateColorOptions(mappedItems)
    }
  }, [existingPR])

  /* ── Product search for Request Items ─────────────────────── */
  const handleProductQueryChange = (rowKey, query) => {
    setProductSearch({ key: rowKey, query, results: [], open: true })
    if (searchTimerRef.current) clearTimeout(searchTimerRef.current)

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
      doFetch('')
    } else {
      searchTimerRef.current = setTimeout(() => doFetch(query), 300)
    }
  }

  const selectProduct = async (rowKey, product) => {
    // Default UOM from the product's first sales channel (Product creation's "Unit of Measure").
    // Editable afterward — this is just a starting guess, not a lock.
    const defaultUnitTypeId = product.cost_details?.[0]?.unit_type_id
    const stock = await fetchStock(product.id, form.source_store_id)
    setItems((prev) => prev.map((row) =>
      row._key === rowKey
        ? {
            ...row,
            product_id:    product.id,
            product_code:  product.product_code ?? '',
            product_name:  product.name ?? '',
            unit_id:       defaultUnitTypeId != null ? String(defaultUnitTypeId) : '',
            attribute_id:  '',
            color_options: [],
            stock_in_hand: stock,
          }
        : row
    ))
    setProductSearch({ key: null, query: '', results: [], open: false })

    loadColorOptions(product.id).then((options) => {
      setItems((prev) => prev.map((row) => row._key === rowKey ? { ...row, color_options: options } : row))
      // Auto-focus Color once its options are ready — skip straight to UOM if this
      // product has none, since a disabled Color select can't receive focus.
      setTimeout(() => focusCell(rowKey, options.length > 0 ? 'color' : 'uom'), 50)
    })
  }

  const clearProductSelection = (rowKey) => {
    setItems((prev) => prev.map((row) =>
      row._key === rowKey
        ? { ...row, product_id: '', product_code: '', product_name: '', attribute_id: '', color_options: [], stock_in_hand: null }
        : row
    ))
    setProductSearch({ key: rowKey, query: '', results: [], open: false })
  }

  const addRows = (n = 1) => setItems((prev) => [...prev, ...Array.from({ length: n }, emptyRow)])

  const addManualRow = useCallback(() => {
    const row = emptyRow()
    setItems((prev) => [...prev, row])
    setTimeout(() => cellRefs.current[row._key]?.product?.focus(), 50)
  }, [])

  const removeRow = (idx) => setItems((prev) => prev.length > 1 ? prev.filter((_, i) => i !== idx) : prev)
  const setRowField = (idx, field, value) => setItems((prev) => prev.map((row, i) => i === idx ? { ...row, [field]: value } : row))
  const setField = (field) => (e) => setForm((f) => ({ ...f, [field]: e.target.value }))

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

  const totalQty = items.reduce((sum, r) => sum + (parseFloat(r.quantity) || 0), 0)

  const saveMutation = useMutation({
    mutationFn: (payload) => isEdit ? updatePurchaseRequest(id, payload) : createPurchaseRequest(payload),
    onSuccess: () => {
      showSuccess(isEdit ? 'Purchase request updated.' : 'Purchase request created.')
      navigate('/inventory/purchase-requests')
    },
    onError: (e) => {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save purchase request.')
    },
  })

  const handleSubmit = () => {
    const clientErrors = {}

    if (!form.source_location_id)   clientErrors.source_location_id  = ['Location is required.']
    if (!form.source_store_id)      clientErrors.source_store_id     = ['Store is required.']

    const validItems = items.filter((r) => r.product_id && parseFloat(r.quantity) > 0)
    if (validItems.length === 0) clientErrors.items = ['At least one product with a valid quantity is required.']

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
      source_location_id:  form.source_location_id || null,
      source_store_id:     form.source_store_id    || null,
      target_location_id:  form.target_location_id || null,
      target_store_id:     form.target_store_id    || null,
      customer_id:         form.customer_id        || null,
      items: validItems.map((r) => ({
        product_id:   parseInt(r.product_id),
        unit_id:      r.unit_id ? parseInt(r.unit_id) : null,
        attribute_id: r.attribute_id ? parseInt(r.attribute_id) : null,
        quantity:     parseFloat(r.quantity),
      })),
    })
  }

  const handleClear = () => {
    setForm({
      request_date: today, reference_no: '', purpose: '',
      source_location_id: '', source_store_id: '',
      target_location_id: '', target_store_id: '',
      customer_id: '',
      required_date: '', transport_mode: '', remarks: '',
      status: 'approved',
    })
    setItems([emptyRow()])
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

  if (isEdit && loadingPR) {
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
      <div className="mb-2 flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">
            {isEdit ? 'Edit Purchase Request' : 'New Purchase Request'}
          </h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>

      {/* Main 3-column grid: Items (2/3) | Detail cards (1/3) */}
      <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">

        {/* ══════════════ LEFT: Request Items ══════════════ */}
        <div className="lg:col-span-2">
          <div className="flex flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <SectionHeader
              icon={ClipboardList}
              title="Request Items"
              colorClass="text-indigo-700 bg-indigo-50 border-indigo-100"
              extra={
                <div className="flex items-center gap-2">
                  {items.length > 0 && (
                    <span className="text-[10px] text-slate-500">{items.length} line{items.length !== 1 ? 's' : ''}</span>
                  )}
                  <button
                    type="button"
                    onClick={addManualRow}
                    title="Add new item (Alt+N)"
                    className="flex items-center gap-1 rounded border-2 border-indigo-200 bg-white px-2 py-0.5 text-[10px] font-bold text-indigo-600 hover:bg-indigo-50 hover:border-indigo-300 transition-colors"
                  >
                    <Plus size={10} /> Add Row
                    <span className="ml-0.5 rounded bg-indigo-100 px-1 py-px text-[9px] font-mono text-indigo-500">Alt+N</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => addRows(5)}
                    className="flex items-center gap-1 rounded border-2 border-slate-200 bg-white px-2 py-0.5 text-[10px] font-bold text-slate-500 hover:bg-slate-50 hover:border-slate-300 transition-colors"
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
                    <th className="w-9 px-3 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">#</th>
                    <th className="w-28 px-3 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Code</th>
                    <th className="px-3 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Product Name</th>
                    <th className="w-24 px-3 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Color</th>
                    <th className="w-24 px-3 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">UOM</th>
                    <th className="w-28 px-3 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Qty</th>
                    <th className="w-32 px-3 py-1.5 text-center text-[10px] font-bold uppercase tracking-wider text-slate-500">Stock in Hand</th>
                    <th className="w-10"></th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {items.map((row, idx) => {
                    return (
                    <tr key={row._key} className="group hover:bg-slate-50/60 transition-colors">
                      <td className="px-3 py-1 text-slate-400 font-medium tabular-nums">{idx + 1}</td>
                      <td className="px-1.5 py-1">
                        <input
                          readOnly
                          value={row.product_code}
                          placeholder="—"
                          className="block w-full rounded-md border-2 border-slate-200 bg-slate-100 px-2 py-0.5 text-xs font-mono text-slate-500 outline-none cursor-not-allowed"
                        />
                      </td>
                      <td className="px-1.5 py-1 min-w-40">
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
                            onNext={() => focusCell(row._key, 'uom')}
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
                        <div className="flex flex-col gap-0.5">
                          <DropdownSelectCell
                            cellRef={setCellRef(row._key, 'uom')}
                            value={row.unit_id}
                            groups={Object.values(uomGroups).map((group) => ({
                              label: group.name,
                              items: group.items.map((u) => ({ value: String(u.id), label: u.symbol ?? u.name })),
                            }))}
                            onChange={(val) => setRowField(idx, 'unit_id', val)}
                            onNext={() => focusCell(row._key, 'qty')}
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
                          ref={setCellRef(row._key, 'qty')}
                          type="number"
                          min="0"
                          step="0.0001"
                          placeholder="0.00"
                          value={row.quantity}
                          onChange={(e) => setRowField(idx, 'quantity', e.target.value)}
                          className="block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15"
                        />
                      </td>
                      <td className="px-3 py-1 text-center">
                        {row.stock_in_hand !== null ? (
                          <span className={`inline-block rounded-full px-2 py-0.5 text-[10px] font-bold tabular-nums ${
                            Number(row.stock_in_hand) > 0
                              ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200'
                              : 'bg-red-50 text-red-600 ring-1 ring-red-200'
                          }`}>
                            {Number(row.stock_in_hand).toLocaleString()}
                          </span>
                        ) : (
                          <span className="text-slate-300 text-xs">—</span>
                        )}
                      </td>
                      <td className="px-1.5 py-1 text-center">
                        <button
                          type="button"
                          onClick={() => removeRow(idx)}
                          className="rounded-md p-1 text-slate-300 opacity-0 group-hover:opacity-100 hover:bg-red-50 hover:text-red-500 transition-all"
                          title="Remove"
                        >
                          <Trash2 size={12} />
                        </button>
                      </td>
                    </tr>
                  )})}
                </tbody>
                <tfoot>
                  <tr className="border-t-2 border-slate-200 bg-slate-50">
                    <td colSpan={5} className="px-3 py-1.5"></td>
                    <td className="px-3 py-1.5 text-left text-sm font-black text-slate-700 tabular-nums">
                      {totalQty > 0 ? totalQty.toLocaleString(undefined, { maximumFractionDigits: 4 }) : '—'}
                    </td>
                    <td colSpan={2} className="px-3 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-400">
                      Total Qty
                    </td>
                  </tr>
                </tfoot>
              </table>
            </div>

            {/* Items validation error */}
            {err('items') && (
              <div className="border-t border-red-100 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600">
                {err('items')}
              </div>
            )}

            {/* Bottom Actions Bar */}
            <div className="mt-auto flex items-center justify-end gap-2 border-t-2 border-slate-100 bg-white px-3 py-2">
              {/* Status dropdown */}
              <label className="text-[10px] font-bold uppercase tracking-wider text-slate-500 whitespace-nowrap">
                Status
              </label>
              <select
                value={form.status}
                onChange={(e) => setForm((f) => ({ ...f, status: e.target.value }))}
                className={`rounded-md border-2 px-2 py-1 text-xs font-semibold outline-none transition-all cursor-pointer ${
                  form.status === 'approved'
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-700 focus:border-emerald-500'
                    : 'border-slate-200 bg-slate-50 text-slate-700 focus:border-indigo-400'
                }`}
              >
                <option value="draft">Draft</option>
                <option value="approved">Approved</option>
              </select>

              <button
                type="button"
                onClick={handleClear}
                className="flex items-center gap-1 rounded border-2 border-red-200 bg-white px-3 py-1 text-xs font-bold text-red-500 transition-all hover:bg-red-50 hover:border-red-300 active:scale-95"
              >
                <RefreshCw size={11} /> Clear
              </button>
              <button
                type="button"
                disabled={saveMutation.isPending}
                onClick={handleSubmit}
                className={`flex items-center gap-1 rounded px-4 py-1 text-xs font-bold text-white shadow-sm transition-all disabled:opacity-60 active:scale-95 ${
                  form.status === 'approved'
                    ? 'bg-emerald-600 hover:bg-emerald-700 shadow-emerald-100'
                    : 'bg-indigo-600 hover:bg-indigo-700 shadow-indigo-100'
                }`}
              >
                {saveMutation.isPending ? (
                  <><RefreshCw size={11} className="animate-spin" /> Saving…</>
                ) : isEdit ? (
                  <><Save size={11} /> Update</>
                ) : form.status === 'draft' ? (
                  <><Save size={11} /> Save Draft</>
                ) : (
                  <><Save size={11} /> Save</>
                )}
              </button>
            </div>
          </div>
        </div>

        {/* ══════════════ RIGHT: Detail Cards ══════════════ */}
        <div className="space-y-2">

          {/* ── Request Details ── */}
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <SectionHeader
              icon={CalendarDays}
              title="Request Details"
              colorClass="text-indigo-700 bg-indigo-50 border-indigo-100"
            />
            <div className="space-y-2 p-2.5">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className={LABEL_CLS}>
                    Date <span className="text-red-500 normal-case font-bold">*</span>
                  </label>
                  <input
                    type="date"
                    className={INPUT_CLS}
                    value={form.request_date}
                    onChange={setField('request_date')}
                  />
                  {err('request_date') && <p className={ERR_CLS}>{err('request_date')}</p>}
                </div>
                <div>
                  <label className={LABEL_CLS}>
                    Reference No.
                    <span className="ml-1 normal-case font-medium text-indigo-400 text-[10px]">auto</span>
                  </label>
                  <input
                    readOnly
                    className="block w-full rounded-md border-2 border-slate-200 bg-slate-100 px-2 py-1 text-xs font-mono text-slate-600 outline-none cursor-not-allowed"
                    placeholder={!isEdit ? 'Generating…' : ''}
                    value={form.reference_no}
                  />
                </div>
              </div>

              <div>
                <label className={LABEL_CLS}>Purpose</label>
                <input
                  className={INPUT_CLS}
                  placeholder="Purpose of this request"
                  value={form.purpose}
                  onChange={setField('purpose')}
                />
              </div>

              <div>
                <label className={LABEL_CLS}>Customer</label>
                <select
                  className={SELECT_CLS}
                  value={form.customer_id}
                  onChange={setField('customer_id')}
                >
                  <option value="">— No customer (general purchase) —</option>
                  {customers.map((c) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className={LABEL_CLS}>
                    Source Location <span className="text-red-500 normal-case font-bold">*</span>
                  </label>
                  <DropdownSelectCell
                    value={form.source_location_id}
                    groups={[{ label: null, items: locations.map((l) => ({ value: String(l.id), label: l.location_name ?? l.name })) }]}
                    onChange={(val) => {
                      setForm((f) => ({ ...f, source_location_id: val, source_store_id: '' }))
                      if (errors.source_location_id) setErrors((prev) => ({ ...prev, source_location_id: undefined, source_store_id: undefined }))
                    }}
                    onNext={() => sourceStoreFieldRef.current?.focus()}
                    placeholder="— Select location —"
                    error={err('source_location_id')}
                  />
                  {err('source_location_id') && <p className={ERR_CLS}>{err('source_location_id')}</p>}
                </div>
                <div>
                  <label className={LABEL_CLS}>
                    Source Store <span className="text-red-500 normal-case font-bold">*</span>
                    {!form.source_location_id && (
                      <span className="ml-1 normal-case font-medium text-amber-500 text-[10px]">↑ first</span>
                    )}
                  </label>
                  <DropdownSelectCell
                    cellRef={(el) => { sourceStoreFieldRef.current = el }}
                    value={form.source_store_id}
                    groups={[{ label: null, items: sourceStores.map((s) => ({ value: String(s.id), label: s.store_name })) }]}
                    onChange={(val) => {
                      setForm((f) => ({ ...f, source_store_id: val }))
                      if (errors.source_store_id) setErrors((prev) => ({ ...prev, source_store_id: undefined }))
                    }}
                    disabled={!form.source_location_id}
                    placeholder={form.source_location_id ? '— Select store —' : '— Select location first —'}
                    error={err('source_store_id')}
                  />
                  {err('source_store_id') && <p className={ERR_CLS}>{err('source_store_id')}</p>}
                </div>
              </div>

              <div>
                <label className={LABEL_CLS}>Remarks</label>
                <textarea
                  rows={2}
                  className={INPUT_CLS + ' resize-none'}
                  placeholder="Optional remarks…"
                  value={form.remarks}
                  onChange={setField('remarks')}
                />
              </div>
            </div>
          </div>

          {/* ── Target Warehouse Details ── */}
          <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <SectionHeader
              icon={Warehouse}
              title="Target Warehouse Details"
              colorClass="text-emerald-700 bg-emerald-50 border-emerald-100"
            />
            <div className="space-y-2 p-2.5">
              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className={LABEL_CLS}>Target Location</label>
                  <select
                    className={SELECT_CLS}
                    value={form.target_location_id}
                    onChange={(e) =>
                      setForm((f) => ({ ...f, target_location_id: e.target.value, target_store_id: '' }))
                    }
                  >
                    <option value="">— Select location —</option>
                    {locations.map((l) => (
                      <option key={l.id} value={l.id}>{l.location_name ?? l.name}</option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className={LABEL_CLS}>
                    Target Store
                    {!form.target_location_id && (
                      <span className="ml-1 normal-case font-medium text-amber-500 text-[10px]">↑ first</span>
                    )}
                  </label>
                  {form.target_location_id ? (
                    <select
                      className={SELECT_CLS}
                      value={form.target_store_id}
                      onChange={setField('target_store_id')}
                    >
                      <option value="">— Select store —</option>
                      {targetStores.map((s) => (
                        <option key={s.id} value={s.id}>{s.store_name}</option>
                      ))}
                    </select>
                  ) : (
                    <select className={SELECT_DISABLED_CLS} disabled>
                      <option>— Select location first —</option>
                    </select>
                  )}
                </div>
              </div>

              <div className="grid grid-cols-2 gap-2">
                <div>
                  <label className={LABEL_CLS}>Required Date</label>
                  <input
                    type="date"
                    className={INPUT_CLS}
                    value={form.required_date}
                    onChange={setField('required_date')}
                  />
                  {err('required_date') && <p className={ERR_CLS}>{err('required_date')}</p>}
                </div>
                <div>
                  <label className={LABEL_CLS}>Transport Mode</label>
                  <select
                    className={SELECT_CLS}
                    value={form.transport_mode}
                    onChange={setField('transport_mode')}
                  >
                    <option value="">— Select —</option>
                    {TRANSPORT_MODES.map((m) => (
                      <option key={m} value={m}>{m}</option>
                    ))}
                  </select>
                </div>
              </div>
            </div>
          </div>

        </div>{/* end right column */}
      </div>{/* end main grid */}

      {/* Keyboard Shortcuts Reference */}
      <div className="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
        <p className="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Keyboard Shortcuts</p>
        <div className="flex flex-wrap gap-x-5 gap-y-1">
          {[
            { keys: ['Alt', 'N'],   desc: 'Add new item row' },
            { keys: ['Enter'],      desc: 'Move to next field in row (Color → UOM → Qty)' },
            { keys: ['↑', '↓'],    desc: 'Navigate product search results / dropdown options' },
            { keys: ['Esc'],        desc: 'Close product search or dropdown' },
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
