import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import {
  Boxes, CalendarDays, ChevronDown, ChevronRight, LayoutList, Package, Plus, QrCode,
  RefreshCw, Save, Table as TableIcon, Trash2, User, X,
} from 'lucide-react'
import {
  createSalesOrder,
  getAvailablePieces,
  getNextSoNo,
  getOrderSources,
  getProductSalePrice,
  getSalesOrder,
  scanSalesPiece,
  updateSalesOrder,
} from '../../api/salesOrders'
import SalesOrderRollPickerModal from '../../components/inventory/SalesOrderRollPickerModal'
import { getProducts, getProduct } from '../../api/products'
import { getProductStock } from '../../api/stock'
import { getAllCustomers } from '../../api/customers'
import { getUsersAll } from '../../api/users'
import { getAllUnitTypesFlat } from '../../api/unitTypes'
import { getAllAttributeTypes } from '../../api/attributeTypes'
import { getAllAttributes } from '../../api/attributes'
import Breadcrumb from '../../components/Breadcrumb'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import Money from '../../components/ui/Money'
import { confirmAction, showError, showSuccess, showWarning } from '../../utils/alerts'
import { CURRENCY, fmtMoney, fmtMoneyWithSymbol } from '../../utils/currency'

const CUSTOMER_TYPES = ['Trade', 'Retail', 'Wholesale', 'Corporate']

const INPUT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20'
const INPUT_ERR_CLS =
  'block w-full rounded border border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-1 focus:ring-red-500/20'
const INPUT_RO_CLS =
  'block w-full rounded border border-slate-200 bg-slate-100 px-2 py-1 text-xs font-mono text-slate-500 outline-none cursor-not-allowed'
const SELECT_CLS =
  'block w-full rounded border border-slate-300 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-1 focus:ring-indigo-500/20 cursor-pointer'
const SELECT_ERR_CLS =
  'block w-full rounded border border-red-300 bg-red-50/40 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-1 focus:ring-red-500/20 cursor-pointer'
const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'text-[10px] text-red-500 leading-tight'
// Quantity inputs accept 4 decimals, so half a tick is the smallest difference a user can
// express. Comparisons against roll capacity must forgive anything below it — converting
// Yard to metres rounds, and 2 yd off a 1.828799 m remnant is a valid sale, not an
// over-sell. Mirrors Quantity::toleranceFor() on the backend.
const QTY_TOLERANCE = 0.00005
const TABLE_INPUT =
  'block w-full rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white'
const TABLE_INPUT_RO =
  'block w-full rounded border border-slate-200 bg-slate-100 px-1.5 py-0.5 text-xs text-slate-500 outline-none cursor-not-allowed'
const TABLE_INPUT_WARN =
  'block w-full rounded border border-amber-400 bg-amber-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-amber-500 focus:bg-white'
const TABLE_DROPDOWN_BTN =
  'flex w-full items-center justify-between gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white cursor-pointer'
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

/* ── Custom checkbox (same markup as PO's Consignment checkbox) ── */
function CheckToggle({ checked, onChange, label, title }) {
  return (
    <label className="flex cursor-pointer select-none items-center gap-1.5 group" title={title}>
      <input type="checkbox" checked={checked} onChange={(e) => onChange(e.target.checked)} className="sr-only" />
      <span className={`flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border-2 transition-all ${checked ? 'border-indigo-500 bg-indigo-500' : 'border-slate-300 bg-white group-hover:border-indigo-400'}`}>
        {checked && (
          <svg className="h-2 w-2 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={3.5}>
            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        )}
      </span>
      <span className="text-[11px] font-semibold text-slate-600 group-hover:text-slate-800 transition-colors">{label}</span>
    </label>
  )
}

/* ── Product search cell (same pattern as the PO form's Product cell) ── */
function ProductSearchCell({ row, productSearch, onQueryChange, onSelect, onClear, onClose, onInputRef }) {
  const [highlightIdx, setHighlightIdx] = useState(0)
  const [dropPos, setDropPos]           = useState({ top: 0, left: 0, width: 420 })
  const inputRef = useRef(null)

  const results    = productSearch.key === row._key ? (productSearch.results ?? []) : []
  const isOpen     = productSearch.key === row._key && productSearch.open && results.length > 0
  const isSelected = Boolean(row.product_id)

  useEffect(() => { setHighlightIdx(0) }, [results.length])

  useEffect(() => {
    if (isOpen && inputRef.current) {
      const rect = inputRef.current.getBoundingClientRect()
      // Wide pad so long product names stay fully readable; clamp to the viewport edge
      const width = Math.min(Math.max(rect.width, 420), window.innerWidth - rect.left - 16)
      setDropPos({ top: rect.bottom + 2, left: rect.left, width })
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
        {row.pieces.length === 0 && (
          <button
            type="button"
            title="Clear product"
            onClick={() => onClear(row._key)}
            className="shrink-0 text-slate-300 hover:text-red-500 transition-colors"
          >
            <X size={10} />
          </button>
        )}
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
                i === highlightIdx ? 'bg-indigo-100 text-indigo-900' : 'hover:bg-slate-50 text-slate-700'
              }`}
              onMouseDown={(e) => { e.preventDefault(); onSelect(row._key, p) }}
              onMouseEnter={() => setHighlightIdx(i)}
              title={p.name}
            >
              <span className="font-mono text-[10px] text-indigo-400 shrink-0 w-24 truncate">{p.product_code}</span>
              <span className="truncate font-medium">{p.name}</span>
            </button>
          ))}
        </div>,
        document.body
      )}
    </div>
  )
}

/* ── Custom dropdown cell (Unit) — opens on focus for keyboard-first entry ── */
function DropdownSelectCell({ groups, value, onChange, onNext, placeholder = '—', disabled, cellRef }) {
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

  return (
    <div className="relative" ref={containerRef}>
      <button
        type="button"
        ref={(el) => { btnRef.current = el; cellRef?.(el) }}
        disabled={disabled}
        onFocus={openDropdown}
        onClick={() => { if (!disabled) { if (open) setOpen(false); else openDropdown() } }}
        onKeyDown={handleKeyDown}
        className={disabled ? TABLE_DROPDOWN_BTN_DISABLED : TABLE_DROPDOWN_BTN}
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
    _key:            Date.now() + Math.random(),
    product_id:      '',
    product_code:    '',
    product_name:    '',
    unit_id:         '',
    // The unit the PRICE is quoted per — defaults to the stocking UOM and may differ
    // from unit_id: qty in Yard at the per-Metre price bills qty x price, unconverted.
    price_unit_id:   '',
    base_unit_type_id:     null, // the product's stocking UOM — roll weights and stock are in it
    base_unit_category_id: null, // limits the UOM dropdown to units convertible to the stocking UOM
    quantity:        '',
    unit_price:      '',
    discount:        '',
    tax:             '',
    pieces:          [], // [{ piece_code, weight, roll_no, grn_unit_price, attribute_id, color }] — non-empty = roll-backed line
    attribute_id:    '', // colour — derived from rolls, or picked manually on no-roll lines
    color_name:      '',
    color_options:   [], // manual-line colour choices from the product's own attributes
    available_count: null, // in-stock rolls of the product (null = not fetched yet)
    available_weight: 0,
    available_stock: null, // total current stock — only checked when available_count is 0
    cost_hint:       null, // latest GRN cost — below-cost warning basis on manual lines
    no_selling_price: false, // product has no price-list selling price configured
    expanded:        false,
  }
}

// "Color" or "Colour" — attribute-type spelling varies by who entered the master data.
function isColorAttributeTypeName(name) {
  const n = (name || '').trim().toLowerCase()
  return n === 'color' || n === 'colour'
}

// A roll line no longer derives its quantity from the rolls: the customer may buy 50 yd
// off a 100 m roll, and the roll gets cut at dispatch. Attaching rolls auto-fills the
// quantity with their full capacity, and the user is free to lower it.
function rowQty(row) {
  return parseFloat(row.quantity) || 0
}

/** What the attached rolls hold, in the product's stocking UOM (roll weights are base). */
function rollCapacityBase(row) {
  return row.pieces.reduce((sum, p) => sum + (parseFloat(p.weight) || 0), 0)
}

function calcItem(row, discountEnabled, taxEnabled) {
  const qty     = rowQty(row)
  const price   = parseFloat(row.unit_price) || 0
  const discPct = discountEnabled ? (parseFloat(row.discount) || 0) : 0
  const taxPct  = taxEnabled      ? (parseFloat(row.tax)      || 0) : 0
  const gross   = qty * price
  const discAmt = gross * (discPct / 100)
  const taxAmt  = gross * (taxPct / 100)
  const amount  = gross - discAmt + taxAmt
  return { qty, gross, discAmt, taxAmt, amount }
}

// Below-cost guard basis: the dearest attached roll's GRN cost, or the latest confirmed
// GRN cost (cost_hint) for manual no-roll lines. Null = unknown.
//
// Every cost the API returns is PER THE STOCKING UOM (see ProductPricingService), while
// the line is priced in the UOM the customer buys in — so the two must be brought into
// one unit before they can be compared, or a per-yard price would be judged against a
// per-metre cost and the warning would fire at random.
function rowCostBasis(row) {
  if (row.pieces.length > 0) {
    const costs = row.pieces.map((p) => Number(p.grn_unit_price) || 0).filter((c) => c > 0)
    return costs.length ? Math.max(...costs) : null
  }
  return row.cost_hint != null ? Number(row.cost_hint) : null
}

/**
 * The line's EFFECTIVE revenue per stocking UOM: amount / stock deducted =
 * (qty x price) / (qty x factor) = price / factor, where `factor` converts the
 * QUANTITY's unit -> base. This holds whatever unit the price is labelled per
 * (price_unit_id), because the amount is always qty x price, unconverted —
 * a yard line billed at the per-metre price simply earns more per metre.
 */
function priceInBase(row, factor) {
  const price = parseFloat(row.unit_price)
  return price > 0 && factor > 0 ? price / factor : 0
}

function isBelowCost(row, factor = 1) {
  const cost  = rowCostBasis(row) // per base UOM
  const price = priceInBase(row, factor)
  return cost != null && price > 0 && price < cost
}

/** QR labels encode "{frontend_url}/inventory/pieces/{piece_code}" — accept both the raw code and the full URL. */
function normalizeScannedCode(raw) {
  const value = raw.trim()
  if (!value) return ''
  if (value.includes('/')) {
    const segments = value.split('/').filter(Boolean)
    return segments[segments.length - 1] ?? ''
  }
  return value
}

/** Money only. Quantities go through fmtQty — a weight tagged "Rs" is worse than no tag at all. */
const fmt = fmtMoney

const EMPTY_FORM = {
  order_date:       new Date().toISOString().slice(0, 10),
  reference_no:     '',
  expected_date:    '',
  transaction_date: '',
  customer_type:    '',
  customer_id:      '',
  order_source:     '',
  order_taken_by:   '',
  sales_person_id:  '',
  delivery_address: '',
  remarks:          '',
  transport_charge: '',
}

export default function SalesOrderFormPage() {
  const { id }   = useParams()
  const isEdit   = Boolean(id)
  const navigate = useNavigate()
  const CRUMBS = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Sales Orders', to: '/inventory/sales-orders' },
    { label: isEdit ? 'Edit Sales Order' : 'New Sales Order' },
  ]

  const today = new Date().toISOString().slice(0, 10)

  const [form, setForm]     = useState({ ...EMPTY_FORM, order_date: today })
  const [items, setItems]   = useState([emptyItem()])
  const [errors, setErrors] = useState({})
  const [status, setStatus] = useState('draft')

  const [viewMode, setViewMode]               = useState('table') // 'table' | 'form'
  const [discountEnabled, setDiscountEnabled] = useState(false)
  const [taxEnabled, setTaxEnabled]           = useState(false)

  /* ── Product search state ─────────────────────────────────── */
  const [productSearch, setProductSearch] = useState({ key: null, query: '', results: [], open: false })
  const searchTimerRef = useRef(null)

  /* ── Piece scan state ─────────────────────────────────────── */
  const [scanValue, setScanValue] = useState('')
  const scanPendingRef = useRef(false)
  const scanInputRef   = useRef(null)

  /* ── Roll picker state ────────────────────────────────────── */
  const [rollPickerKey, setRollPickerKey] = useState(null) // _key of the row being picked

  /* ── Cell refs for Enter-key row navigation ───────────────── */
  const cellRefs = useRef({}) // { [rowKey]: { product, qty, unit, price, disc, tax } }

  const setCellRef = (rowKey, field) => (el) => {
    if (!cellRefs.current[rowKey]) cellRefs.current[rowKey] = {}
    cellRefs.current[rowKey][field] = el
  }

  const focusCell = (rowKey, field) => {
    cellRefs.current[rowKey]?.[field]?.focus()
  }

  /* ── Reference data ───────────────────────────────────────── */
  const { data: customers    = [] } = useQuery({ queryKey: ['customers-all'],     queryFn: getAllCustomers,     staleTime: 5 * 60 * 1000 })
  const { data: users        = [] } = useQuery({ queryKey: ['users-all'],         queryFn: getUsersAll,         staleTime: 5 * 60 * 1000 })
  const { data: orderSources = [] } = useQuery({ queryKey: ['so-order-sources'],  queryFn: getOrderSources,     staleTime: Infinity })
  const { data: unitTypes    = [] } = useQuery({ queryKey: ['unit-types-flat'],   queryFn: getAllUnitTypesFlat, staleTime: 5 * 60 * 1000 })

  // Colour options for manual (no-roll) lines — same source as the PO form:
  // the product's own "Product Attributes" filtered to Color/Colour types.
  const { data: attributeTypes = [] } = useQuery({ queryKey: ['attribute-types-all'], queryFn: getAllAttributeTypes })
  const { data: allAttributes  = [] } = useQuery({ queryKey: ['attributes-all'],      queryFn: getAllAttributes })
  const colorOptionsCache = useRef({})

  const loadColorOptions = (productId) => {
    if (!productId) return Promise.resolve([])
    if (colorOptionsCache.current[productId]) return colorOptionsCache.current[productId]

    const promise = getProduct(productId)
      .then((res) => {
        const colorTypeIds = new Set(
          attributeTypes.filter((t) => isColorAttributeTypeName(t.attribute_type_name)).map((t) => String(t.id))
        )
        return (res.data.product_attributes ?? [])
          .filter((pa) => colorTypeIds.has(String(pa.attribute_type_id)))
          .map((pa) => allAttributes.find((a) => String(a.id) === String(pa.attribute_id)))
          .filter(Boolean)
          .map((a) => ({ id: a.id, name: a.attribute_name }))
      })
      .catch(() => [])

    colorOptionsCache.current[productId] = promise
    return promise
  }

  const uomGroups = unitTypes.reduce((acc, u) => {
    const key = String(u.unit_category_id)
    if (!acc[key]) acc[key] = { name: u.unit_category_name ?? 'Other', items: [] }
    acc[key].items.push(u)
    return acc
  }, {})

  // Only units convertible to the product's stocking UOM may be sold in — anything
  // outside its category has no conversion rate and the backend rejects it.
  const uomGroupsFor = (categoryId) => {
    const groups = categoryId != null && uomGroups[String(categoryId)]
      ? [uomGroups[String(categoryId)]]
      : Object.values(uomGroups)

    return groups.map((group) => ({
      label: group.name,
      items: group.items.map((u) => ({ value: String(u.id), label: u.symbol ?? u.name })),
    }))
  }

  const unitById = new Map(unitTypes.map((u) => [String(u.id), u]))
  const symbolOf = (u) => u?.symbol ?? u?.name ?? ''

  /**
   * Fabric is sold by the yard, so a new line's selling UOM defaults to the
   * "Yard" unit of the product's own category when one exists — matched by
   * name/symbol, not a hard-coded id, so it follows the user's unit master.
   * Products in other categories (Kg, Pcs, …) keep their stocking UOM.
   * The PRICE unit is unaffected: it stays the stocking UOM (per-metre).
   */
  const defaultSellingUnitId = (baseUnitTypeId, categoryId) => {
    const isYard = (u) => {
      const n = (u.name || '').trim().toLowerCase()
      const s = (u.symbol || '').trim().toLowerCase()
      return n === 'yard' || n === 'yards' || s === 'yd'
    }
    const yard = categoryId != null
      ? unitTypes.find((u) => String(u.unit_category_id) === String(categoryId) && isYard(u))
      : null

    return yard ? String(yard.id) : (baseUnitTypeId != null ? String(baseUnitTypeId) : '')
  }

  /**
   * How the line's selling UOM (Yard) relates to the product's stocking UOM (m).
   * base_rate is each unit's rate from its category's reference unit, so within a
   * category:  factor(line -> base) = base_rate[base] / base_rate[line].
   * Roll weights and stock are in base; the line's quantity and price are not.
   */
  const conversionFor = (row) => {
    const line = unitById.get(String(row.unit_id ?? ''))
    const base = unitById.get(String(row.base_unit_type_id ?? ''))
    if (!line || !base) return null

    const same   = String(line.id) === String(base.id)
    const factor = same
      ? 1
      : (line.base_rate && base.base_rate ? base.base_rate / line.base_rate : null)

    return { same, factor, lineSymbol: symbolOf(line), baseSymbol: symbolOf(base) }
  }

  /** The rolls' full capacity, expressed in the UOM the customer is buying in. */
  const capacityInLineUom = (row) => {
    const conv = conversionFor(row)
    const base = rollCapacityBase(row)

    return conv?.factor ? base / conv.factor : base
  }

  /**
   * How the line's PRICE unit relates to the stocking UOM — the mirror of
   * conversionFor for the price side. The price may be quoted per a different unit
   * than the quantity (qty in Yard, price per Metre): the amount is still
   * qty x price with NO conversion — that gap is deliberate margin, not a bug.
   * Legacy lines without a price unit quote per the quantity's unit.
   */
  const priceConversionFor = (row) => {
    const pu   = unitById.get(String(row.price_unit_id || row.unit_id || ''))
    const base = unitById.get(String(row.base_unit_type_id ?? ''))
    if (!pu || !base) return null

    const same   = String(pu.id) === String(base.id)
    const factor = same
      ? 1
      : (pu.base_rate && base.base_rate ? base.base_rate / pu.base_rate : null)

    return { same, factor, priceSymbol: symbolOf(pu), baseSymbol: symbolOf(base) }
  }

  /**
   * A price stored per the stocking UOM (500 /m), shown per the line's PRICE unit.
   * The price unit defaults to the stocking UOM itself, so the per-metre figure fills
   * in unchanged even when the quantity is in yards.
   *
   * A price per unit U is worth `factor(U -> base)` base units, so it scales by the
   * same factor as a quantity — never the inverse. 43.74/m x 0.9144 m/yd = 40/yd.
   */
  const priceInPriceUom = (basePrice, row) => {
    const factor = priceConversionFor(row)?.factor
    if (basePrice == null) return null

    return factor ? Number(basePrice) * factor : Number(basePrice)
  }

  /** Re-price a line when its PRICE unit changes: 400/m becomes 365.76/yd, not 400/yd. */
  const repriceForPriceUnit = (row, previousUnitId) => {
    const from = unitById.get(String(previousUnitId ?? ''))
    const to   = unitById.get(String(row.price_unit_id ?? ''))
    const price = parseFloat(row.unit_price)

    if (!from || !to || !from.base_rate || !to.base_rate || !(price > 0)) return row

    return { ...row, unit_price: String(Number((price * (from.base_rate / to.base_rate)).toFixed(4))) }
  }

  /** Attaching or removing rolls re-fills the quantity with their full capacity. */
  const fillQuantityFromRolls = (row) => ({
    ...row,
    quantity: row.pieces.length > 0
      ? String(Number(capacityInLineUom(row).toFixed(4)))
      : '',
  })

  const fmtQty = (n, max = 4) => Number(n).toLocaleString(undefined, { maximumFractionDigits: max })

  // Strictly the customers of the selected Customer Type — customers without a
  // type on their master record only appear before a type is chosen.
  const filteredCustomers = useMemo(
    () => form.customer_type
      ? customers.filter((c) => c.customer_type === form.customer_type)
      : customers,
    [customers, form.customer_type]
  )
  const customerOptions = filteredCustomers.map((c) => ({
    value: String(c.id),
    label: c.customer_code ? `${c.customer_code} — ${c.name}` : c.name,
  }))
  const userOptions = users.map((u) => ({ value: String(u.id), label: u.name }))

  const { data: nextSoNo, isLoading: loadingSoNo } = useQuery({
    queryKey: ['next-so-no'],
    queryFn:  getNextSoNo,
    enabled:  !isEdit,
    staleTime: 0,
  })

  // Default the Sales Person to the logged-in user (create mode only).
  // Falls back to a name match for sessions logged in before user_id was stored.
  const salesPersonSeeded = useRef(false)
  useEffect(() => {
    if (isEdit || salesPersonSeeded.current || users.length === 0) return
    const storedId   = localStorage.getItem('user_id')
    const storedName = localStorage.getItem('user_name') || ''
    const me = (storedId && users.find((u) => String(u.id) === storedId))
      || users.find((u) => u.name === storedName)
      || users[0] // no match — default to the first user in the list
    setForm((f) => f.sales_person_id ? f : ({ ...f, sales_person_id: String(me.id) }))
    salesPersonSeeded.current = true
  }, [isEdit, users])

  /* ── Edit-mode hydration ──────────────────────────────────── */
  const { data: existingSO, isLoading: loadingSO } = useQuery({
    queryKey: ['sales-order', id],
    queryFn:  () => getSalesOrder(id),
    enabled:  isEdit,
  })

  useEffect(() => {
    if (!existingSO?.data) return
    const so = existingSO.data
    setForm({
      order_date:       so.order_date       ?? today,
      reference_no:     so.reference_no     ?? '',
      expected_date:    so.expected_date    ?? '',
      transaction_date: so.transaction_date ?? '',
      customer_type:    so.customer_type    ?? '',
      customer_id:      so.customer_id != null ? String(so.customer_id) : '',
      order_source:     so.order_source     ?? '',
      order_taken_by:   so.order_taken_by != null ? String(so.order_taken_by) : '',
      sales_person_id:  so.sales_person_id != null ? String(so.sales_person_id) : '',
      delivery_address: so.delivery_address ?? '',
      remarks:          so.remarks          ?? '',
      transport_charge: so.transport_charge ? String(so.transport_charge) : '',
    })
    setStatus(so.status ?? 'draft')
    if (so.items?.length) {
      const newItems = so.items.map((it) => ({
        _key:         it.id,
        product_id:   it.product_id,
        product_code: it.product?.product_code ?? '',
        product_name: it.product?.name         ?? '',
        unit_id:      it.unit_id != null ? String(it.unit_id) : '',
        // Legacy lines (saved before the price-unit column) quoted per the qty's unit
        price_unit_id: it.price_unit_id != null
          ? String(it.price_unit_id)
          : (it.unit_id != null ? String(it.unit_id) : ''),
        base_unit_type_id:     it.product?.base_unit_type_id ?? null,
        base_unit_category_id: it.product?.base_unit_category_id ?? null,
        quantity:     it.quantity,
        unit_price:   it.unit_price,
        discount:     it.discount || '',
        tax:          it.tax      || '',
        attribute_id: it.attribute_id != null ? String(it.attribute_id) : '',
        color_name:   it.attribute?.name ?? '',
        color_options: [],
        available_count: null,
        available_weight: 0,
        available_stock: null,
        pieces:       (it.pieces ?? []).map((p) => ({
          piece_code:     p.piece_code,
          weight:         p.weight,
          roll_no:        p.roll_no ?? '',
          grn_unit_price: p.grn_unit_price,
          attribute_id:   it.attribute_id ?? null,
          color:          it.attribute?.name ?? '',
        })),
        expanded:     false,
      }))
      setItems(newItems)
      // Manual lines keep an editable colour dropdown — hydrate its options
      newItems.filter((r) => r.pieces.length === 0 && r.product_id).forEach((r) => {
        loadColorOptions(r.product_id).then((options) => {
          setItems((prev) => prev.map((row) => row._key === r._key ? { ...row, color_options: options } : row))
        })
      })
      // Re-check current stock for existing manual lines too, so an item that sold
      // out since this SO was drafted shows the same warning as a fresh pick.
      // allow_minus products are exempt, same as on a fresh pick.
      const allowMinusIds = new Set(
        so.items.filter((it) => it.product?.allow_minus).map((it) => it.product_id)
      )
      newItems.filter((r) => r.pieces.length === 0 && r.product_id && !allowMinusIds.has(r.product_id)).forEach((r) => {
        getProductStock(r.product_id).then(({ total_stock }) => {
          const stock = Number(total_stock) || 0
          setItems((prev) => prev.map((row) => row._key === r._key ? { ...row, available_stock: stock } : row))
        }).catch(() => { /* silent — server guard still applies on save */ })
      })
      if (so.items.some((it) => parseFloat(it.discount) > 0)) setDiscountEnabled(true)
      if (so.items.some((it) => parseFloat(it.tax) > 0))      setTaxEnabled(true)
    }
  }, [existingSO])

  /* ── Field helpers ────────────────────────────────────────── */
  const clearFieldError = (field) => {
    if (errors[field]) setErrors((prev) => ({ ...prev, [field]: undefined }))
  }

  const setField = (field) => (e) => {
    setForm((f) => ({ ...f, [field]: e.target.value }))
    clearFieldError(field)
  }

  const handleCustomerChange = (customerId) => {
    clearFieldError('customer_id')
    const customer = customers.find((c) => String(c.id) === String(customerId))
    setForm((f) => ({
      ...f,
      customer_id:      customerId,
      customer_type:    customer?.customer_type || f.customer_type,
      // Delivery address always follows the selected customer's shipping address
      delivery_address: customer ? (customer.shipping_address || '') : f.delivery_address,
    }))
  }

  /* ── Piece scanning (keyboard-wedge: scanner types the code + Enter) ── */
  const mergePieceIntoLines = (scan) => {
    const piece = {
      piece_code:     scan.piece.piece_code,
      weight:         scan.piece.weight,
      roll_no:        scan.piece.roll_no ?? '',
      grn_unit_price: scan.grn_unit_price,
      selling_price:  scan.selling_price ?? null,
      price_source:   scan.price_source ?? null,
      attribute_id:   scan.piece.attribute_id ?? null,
      color:          scan.piece.color ?? '',
    }
    setItems((prev) => {
      // One line per product + colour + SELLING price — a roll whose shipment
      // was costed at a different price starts a new line, so old stock sells
      // at its old price and new stock at its new price.
      const existing = prev.find((r) =>
        r.pieces.length > 0 &&
        String(r.product_id) === String(scan.product.id) &&
        String(r.attribute_id || '') === String(scan.piece.attribute_id ?? '') &&
        Number(r.pieces[0]?.selling_price ?? 0) === Number(scan.selling_price ?? 0))
      if (existing) {
        return prev.map((r) => r._key === existing._key
          ? fillQuantityFromRolls({ ...r, pieces: [...r.pieces, piece] })
          : r)
      }
      // A scanned line defaults to the Yard selling UOM (stocking UOM when the
      // category has no yard) with the roll's full length auto-converted into it.
      // The user can lower the quantity — the roll is cut at dispatch.
      const line = fillQuantityFromRolls({
        ...emptyItem(),
        product_id:   scan.product.id,
        product_code: scan.product.product_code ?? '',
        product_name: scan.product.name ?? '',
        unit_id:      defaultSellingUnitId(
          scan.product.base_unit_type_id ?? scan.product.unit?.id,
          scan.product.base_unit_category_id,
        ),
        // Price is quoted per the stocking UOM and STAYS there when the user
        // switches the selling UOM to Yard — qty(yd) x price(/m) is the intent.
        price_unit_id: scan.product.base_unit_type_id != null ? String(scan.product.base_unit_type_id) : '',
        base_unit_type_id:     scan.product.base_unit_type_id ?? null,
        base_unit_category_id: scan.product.base_unit_category_id ?? null,
        unit_price:   scan.selling_price != null ? String(scan.selling_price) : '',
        no_selling_price: scan.selling_price == null,
        attribute_id: scan.piece.attribute_id != null ? String(scan.piece.attribute_id) : '',
        color_name:   scan.piece.color ?? '',
        pieces:       [piece],
      })
      // Replace a single untouched empty row instead of appending below it
      const isBlank = (r) => !r.product_id && r.pieces.length === 0 && !r.quantity && !r.unit_price
      if (prev.length === 1 && isBlank(prev[0])) return [line]
      return [...prev, line]
    })
    clearFieldError('items')
  }

  const handleScan = async () => {
    const code = normalizeScannedCode(scanValue)
    if (!code || scanPendingRef.current) return
    scanPendingRef.current = true

    try {
      const alreadyScanned = items.some((r) => r.pieces.some((p) => p.piece_code === code))
      if (alreadyScanned) {
        showWarning(`Piece ${code} is already on this order.`)
        return
      }

      const scan = await scanSalesPiece(code)

      if (!scan.available) {
        showError(scan.unavailable_reason || `Piece ${code} is not available.`)
        return
      }

      mergePieceIntoLines(scan)
      const colorTag = scan.piece.color ? ` (${scan.piece.color})` : ''
      const priceTag = scan.selling_price != null ? ` @ ${fmtMoneyWithSymbol(scan.selling_price)}` : ''
      showSuccess(`${scan.product.name}${colorTag} — ${Number(scan.piece.weight).toLocaleString()}${priceTag} added.`)
    } catch (e) {
      showError(e.response?.status === 404 ? `Piece ${code} not found.` : 'Failed to resolve the scanned piece.')
    } finally {
      scanPendingRef.current = false
      setScanValue('')
      scanInputRef.current?.focus()
    }
  }

  const removePiece = (rowKey, pieceCode) => {
    setItems((prev) => prev
      .map((r) => r._key === rowKey
        ? fillQuantityFromRolls({ ...r, pieces: r.pieces.filter((p) => p.piece_code !== pieceCode) })
        : r)
      // A scanned line with no pieces left has no quantity — drop it (keep at least one row)
      .filter((r, _, arr) => !(r._key === rowKey && r.pieces.length === 0 && arr.length > 1))
      .map((r) => r._key === rowKey && r.pieces.length === 0 ? emptyItem() : r)
    )
  }

  /* ── Product search for manual lines ──────────────────────── */
  const handleProductQueryChange = (rowKey, query) => {
    setProductSearch({ key: rowKey, query, results: [], open: false })
    clearTimeout(searchTimerRef.current)

    const doFetch = async (search) => {
      try {
        const params = search ? { search, per_page: 30 } : { per_page: 100 }
        const res = await getProducts(1, params)
        setProductSearch((prev) =>
          prev.key === rowKey ? { ...prev, results: res.data ?? [], open: true } : prev
        )
      } catch { /* silent */ }
    }

    if (query === '') {
      doFetch('')
    } else {
      searchTimerRef.current = setTimeout(() => doFetch(query), 300)
    }
  }

  const selectProduct = (rowKey, product) => {
    const defaultUnitTypeId = product.base_unit_type_id ?? product.cost_details?.[0]?.unit_type_id
    setItems((prev) => prev.map((row) =>
      row._key === rowKey
        ? {
            ...row,
            product_id:   product.id,
            product_code: product.product_code ?? '',
            product_name: product.name ?? '',
            // Selling UOM defaults to Yard (when the category has one) — what the
            // customer asks for; falls back to the stocking UOM otherwise.
            unit_id:      defaultSellingUnitId(defaultUnitTypeId, product.base_unit_category_id),
            // Prices default to per the stocking UOM regardless of the selling UOM
            price_unit_id: product.base_unit_type_id != null
              ? String(product.base_unit_type_id)
              : (defaultUnitTypeId != null ? String(defaultUnitTypeId) : ''),
            base_unit_type_id:     product.base_unit_type_id ?? null,
            base_unit_category_id: product.base_unit_category_id ?? null,
            attribute_id: '',
            color_name:   '',
            color_options: [],
            available_count: null,
            available_stock: null,
          }
        : row
    ))
    setProductSearch({ key: null, query: '', results: [], open: false })

    // Colour choices for no-roll lines come from the product's own attributes.
    // Roll-tracked lines get theirs from the availability fetch below instead,
    // so never overwrite once in-stock colours are known.
    loadColorOptions(product.id).then((options) => {
      setItems((prev) => prev.map((row) =>
        row._key === rowKey && !(row.available_count > 0) ? { ...row, color_options: options } : row
      ))
    })

    // Prefill unit price from the product's price-list selling price (editable);
    // keep the latest GRN cost on the row as the below-cost warning basis.
    getProductSalePrice(product.id)
      .then(({ unit_price, cost_price }) => {
        setItems((prev) => prev.map((row) => {
          if (row._key !== rowKey) return row

          // Both prices arrive per the stocking UOM. The selling price is shown per the
          // line's PRICE unit (the stocking UOM by default, so the per-metre figure fills
          // unchanged); the cost stays in base, which is what isBelowCost compares in.
          const listPrice = priceInPriceUom(unit_price, row)

          return {
            ...row,
            unit_price:       !row.unit_price && listPrice != null ? String(Number(listPrice.toFixed(4))) : row.unit_price,
            cost_hint:        cost_price ?? null,
            no_selling_price: unit_price == null,
          }
        }))
      })
      .catch(() => { /* silent — price stays manual */ })

    // Roll availability decides whether this line types a qty or picks rolls. This is
    // NOT gated by any static product flag — every product gets roll/weight capture at
    // GRN, so "roll-tracked" just means "has rolls in stock right now". For roll-tracked
    // products the colour choices are the colours that actually have rolls in stock
    // (not the full attribute master).
    getAvailablePieces(product.id)
      .then(({ pieces, count, total_weight }) => {
        if (count === 0) {
          showWarning(`No rolls available in stock for ${product.name}.`)
        }
        const stockColors = new Map()
        pieces.forEach((p) => {
          if (p.attribute_id != null) stockColors.set(String(p.attribute_id), p.color || `Colour #${p.attribute_id}`)
        })
        setItems((prev) => prev.map((row) =>
          row._key === rowKey
            ? {
                ...row,
                available_count:  count,
                available_weight: total_weight,
                color_options: count > 0
                  ? [...stockColors.entries()].map(([cid, name]) => ({ id: cid, name }))
                  : row.color_options,
              }
            : row
        ))

        // No rolls on hand — this line takes a manual quantity instead, so also check
        // the plain stock ledger (rolls sold out looks identical here to "never had
        // any"). allow_minus products are exempt — that flag is the existing, deliberate
        // way to let a product be sold below zero stock.
        if (count === 0 && !product.allow_minus) {
          getProductStock(product.id)
            .then(({ total_stock }) => {
              const stock = Number(total_stock) || 0
              setItems((prev) => prev.map((row) =>
                row._key === rowKey ? { ...row, available_stock: stock } : row
              ))
            })
            .catch(() => { /* silent — server guard still applies on save */ })
        }
      })
      .catch(() => { /* silent — server guard still applies on save */ })

    setTimeout(() => focusCell(rowKey, 'qty'), 50)
  }

  const applyPickedRolls = (rowKey, pieces) => {
    setItems((prev) => {
      const idx = prev.findIndex((r) => r._key === rowKey)
      if (idx === -1) return prev
      const base = prev[idx]

      if (pieces.length === 0) {
        return prev.map((r) => r._key === rowKey ? fillQuantityFromRolls({ ...r, pieces: [] }) : r)
      }

      // One line per colour + SELLING price — rolls from shipments costed at
      // different prices split into separate lines (old stock at old price,
      // new stock at new price). Cost stays tracked per roll (grn_unit_price).
      const groups = new Map()
      for (const piece of pieces) {
        const key = `${piece.attribute_id ?? ''}|${piece.selling_price != null ? Number(piece.selling_price) : 'none'}`
        if (!groups.has(key)) groups.set(key, [])
        groups.get(key).push(piece)
      }

      // Changing which rolls are on a line re-fills its quantity with their full
      // capacity — the user then lowers it if the customer wants only part.
      const lines = [...groups.values()].map((groupPieces, i) => {
        const line = i === 0 ? base : { ...emptyItem(), ...base, _key: Date.now() + Math.random() + i }
        // The roll's shipment price is per the stocking UOM — shown per the line's
        // PRICE unit (the stocking UOM by default, so it fills unchanged).
        const rollPrice = priceInPriceUom(groupPieces[0].selling_price, line)

        return fillQuantityFromRolls({
          ...line,
          pieces:       groupPieces,
          // Each group sells at its rolls' own shipment price (still editable);
          // rolls without a resolved price keep the line's existing price.
          unit_price:   rollPrice != null ? String(Number(rollPrice.toFixed(4))) : base.unit_price,
          attribute_id: groupPieces[0].attribute_id != null ? String(groupPieces[0].attribute_id) : '',
          color_name:   groupPieces[0].color ?? '',
          expanded:     false,
        })
      })

      const next = [...prev]
      next.splice(idx, 1, ...lines)
      return next
    })
    setRollPickerKey(null)
    clearFieldError('items')
  }

  const clearProductSelection = (rowKey) => {
    setItems((prev) => prev.map((row) =>
      row._key === rowKey ? { ...row, product_id: '', product_code: '', product_name: '' } : row
    ))
    setProductSearch({ key: rowKey, query: '', results: [], open: false })
  }

  /* ── Row helpers ──────────────────────────────────────────── */
  const addManualRow = useCallback(() => {
    const row = emptyItem()
    setItems((prev) => [...prev, row])
    setTimeout(() => cellRefs.current[row._key]?.product?.focus(), 50)
  }, [])

  const removeRow   = (idx)   => setItems((prev) => prev.length > 1 ? prev.filter((_, i) => i !== idx) : [emptyItem()])

  const setRowField = (idx, field, value) => setItems((prev) => prev.map((row, i) => {
    if (i !== idx) return row

    let next = { ...row, [field]: value }

    if (field === 'unit_id') {
      // The price is pinned to its own unit (price_unit_id), so switching the selling
      // UOM re-expresses only the quantity — 500/m stays 500/m whether the customer
      // buys metres or yards, and a yard line billed at 500/m is the intended margin.
      if (next.pieces.length > 0) next = fillQuantityFromRolls(next)
    }

    if (field === 'price_unit_id') {
      // Changing the PRICE unit re-expresses the price so its value keeps meaning
      // the same thing: 400/m becomes 365.76/yd, not 400/yd.
      next = repriceForPriceUnit(next, row.price_unit_id)
    }

    return next
  }))
  const toggleExpanded = (idx) => setItems((prev) => prev.map((row, i) => i === idx ? { ...row, expanded: !row.expanded } : row))

  /* ── Alt+N shortcut ───────────────────────────────────────── */
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

  /* ── Totals ───────────────────────────────────────────────── */
  const totals = items.reduce(
    (acc, row) => {
      const { qty, gross, discAmt, taxAmt, amount } = calcItem(row, discountEnabled, taxEnabled)
      return {
        qty:   acc.qty   + qty,
        gross: acc.gross + gross,
        disc:  acc.disc  + discAmt,
        tax:   acc.tax   + taxAmt,
        total: acc.total + amount,
      }
    },
    { qty: 0, gross: 0, disc: 0, tax: 0, total: 0 },
  )
  const transportCharge = parseFloat(form.transport_charge) || 0
  const netAmount       = totals.total + transportCharge

  /* ── Save ─────────────────────────────────────────────────── */
  const saveMutation = useMutation({
    mutationFn: (payload) => isEdit ? updateSalesOrder(id, payload) : createSalesOrder(payload),
    onSuccess: () => {
      showSuccess(isEdit ? 'Sales order updated.' : 'Sales order created.')
      navigate('/inventory/sales-orders')
    },
    onError: (e) => {
      const data = e.response?.data
      if (data?.errors) setErrors(data.errors)
      showError(data?.message ?? 'Failed to save sales order.')
    },
  })

  const handleSubmit = async (saveStatus) => {
    const clientErrors = {}

    if (!form.order_date)
      clientErrors.order_date = ['Date is required.']

    if (form.expected_date && form.order_date && form.expected_date < form.order_date)
      clientErrors.expected_date = ['Expected date must be on or after the order date.']

    if (!form.customer_id)
      clientErrors.customer_id = ['Customer is required.']

    if (!form.sales_person_id)
      clientErrors.sales_person_id = ['Sales person is required.']

    // Roll lines carry a real quantity now (auto-filled from the rolls, then editable),
    // so every line needs one — a blank roll line is no longer "sell them whole".
    const validItems = items.filter((r) => r.product_id && parseFloat(r.quantity) > 0)
    if (validItems.length === 0)
      clientErrors.items = ['Scan a piece or add at least one product with a valid quantity.']

    // Products with rolls in stock must have specific rolls attached (scan or picker)
    const unpickedLine = items.find((r) => r.product_id && r.pieces.length === 0 && r.available_count > 0)
    if (unpickedLine)
      clientErrors.items = [`${unpickedLine.product_name} has rolls in stock — use Select Rolls to choose which rolls to sell.`]

    // Lines with no rolls in stock have no roll picker to guard them — block the line
    // outright when the product has zero stock anywhere. (Roll-tracked lines with rolls
    // attached are already covered above.)
    const outOfStockLine = items.find((r) =>
      r.product_id && parseFloat(r.quantity) > 0 && r.available_stock === 0)
    if (outOfStockLine)
      clientErrors.items = [`${outOfStockLine.product_name} has no available stock and cannot be added to a sales order.`]

    // You cannot sell more than the attached rolls physically hold — but the comparison
    // must forgive the rounding of the unit conversion itself, or selling exactly the
    // remainder of a roll (2 yd off 1.828799 m) would be rejected as an over-sell.
    const overSold = items.find((r) =>
      r.pieces.length > 0 && rowQty(r) > capacityInLineUom(r) + QTY_TOLERANCE)
    if (overSold)
      clientErrors.items = [
        `${overSold.product_name}: the selected rolls hold only ${fmtQty(capacityInLineUom(overSold))} ` +
        `${conversionFor(overSold)?.lineSymbol ?? ''} — add another roll or reduce the quantity.`,
      ]

    if (Object.keys(clientErrors).length) {
      setErrors(clientErrors)
      showError('Please fill in all required fields.')
      return
    }

    // Warn-but-allow loss guard: selling below the rolls' GRN cost is sometimes
    // intentional (clearance), so confirm instead of blocking.
    const belowCostLines = items.filter((r) => r.product_id && isBelowCost(r))
    if (belowCostLines.length > 0) {
      const names = belowCostLines.map((r) => r.product_name).filter(Boolean).join(', ')
      const ok = await confirmAction({
        title: 'Selling below cost',
        message: `${belowCostLines.length} line(s) are priced below the GRN cost${names ? ` (${names})` : ''}. Save anyway?`,
        confirmText: 'Yes, Save',
        confirmColor: '#d97706',
        icon: 'warning',
      })
      if (!ok) return
    }

    setErrors({})
    saveMutation.mutate({
      order_date:       form.order_date,
      reference_no:     form.reference_no.trim()     || null,
      expected_date:    form.expected_date           || null,
      transaction_date: form.transaction_date        || null,
      customer_id:      parseInt(form.customer_id),
      customer_type:    form.customer_type           || null,
      order_source:     form.order_source            || null,
      order_taken_by:   form.order_taken_by  ? parseInt(form.order_taken_by)  : null,
      sales_person_id:  parseInt(form.sales_person_id),
      delivery_address: form.delivery_address.trim() || null,
      transport_charge: transportCharge,
      remarks:          form.remarks.trim()          || null,
      status:           saveStatus,
      items: validItems.map((r) => ({
        product_id:   parseInt(r.product_id),
        unit_id:      r.unit_id ? parseInt(r.unit_id) : null,
        price_unit_id: r.price_unit_id ? parseInt(r.price_unit_id) : null,
        attribute_id: r.attribute_id ? parseInt(r.attribute_id) : null,
        quantity:     rowQty(r),
        unit_price:   parseFloat(r.unit_price) || 0,
        discount:     discountEnabled ? (parseFloat(r.discount) || 0) : 0,
        tax:          taxEnabled      ? (parseFloat(r.tax)      || 0) : 0,
        piece_codes:  r.pieces.map((p) => p.piece_code),
      })),
    })
  }

  const handleClear = () => {
    setForm({ ...EMPTY_FORM, order_date: today })
    setItems([emptyItem()])
    setErrors({})
    setDiscountEnabled(false)
    setTaxEnabled(false)
    scanInputRef.current?.focus()
  }

  const err = (f) => errors[f]?.[0]
  // #, Code, Product, Colour, UOM, Qty, Price, Gross, Amount, delete = 10 base columns
  const itemColSpan = 10 + (discountEnabled ? 1 : 0) + (taxEnabled ? 1 : 0)

  // In edit mode a confirmed SO stays confirmed on save; a draft can be saved
  // as draft again or confirmed ("Save & Confirm").
  const showDraftButton = !isEdit || status === 'draft'
  const primaryStatus   = 'confirmed'
  const primaryLabel    = isEdit
    ? (status === 'draft' ? 'Save & Confirm' : 'Update Order')
    : 'Create & Close'

  if (isEdit && loadingSO) {
    return (
      <div className="flex items-center justify-center py-20">
        <div className="flex items-center gap-2 text-sm text-slate-400">
          <RefreshCw size={14} className="animate-spin" /> Loading…
        </div>
      </div>
    )
  }

  /* ── Shared line-item renderers ───────────────────────────── */

  /**
   * Mirrors the server's allocation (RollService::distribute): the quantity fills the
   * rolls in order, so only the last one it reaches is left partly used — and that one
   * gets cut at dispatch. Shown here so the cut is never a surprise at the warehouse.
   */
  const rollTakes = (row) => {
    const factor = conversionFor(row)?.factor ?? 1
    // Never ask for more than the rolls hold: the conversion cannot land exactly on the
    // roll's length, and the server snaps that difference away rather than stranding it.
    let remaining = Math.min(rowQty(row) * factor, rollCapacityBase(row))

    return row.pieces.map((p) => {
      const held = parseFloat(p.weight) || 0
      let take = Math.max(0, Math.min(remaining, held))

      // A leftover too small to type is dust, not a remnant — the whole roll goes.
      if (held - take < QTY_TOLERANCE) take = held
      remaining -= take

      return { ...p, take, cut: take > 0 && held - take >= QTY_TOLERANCE }
    })
  }

  /**
   * What this line actually takes out of stock, in the stocking UOM.
   *
   * On a roll line the rolls are the physical truth, so it is the sum of what each roll
   * gives up — the figure SalesOrderService posts to the ledger. Re-deriving it from the
   * typed quantity instead would read 510.000016 m for 557.7428 yd: 510 m has no exact
   * 4-decimal equivalent in yards, and the quantity box only holds 4. The rolls hold
   * 510 m and 510 m is what leaves, so that is what we show.
   */
  const baseQtyOf = (row) => {
    const factor = conversionFor(row)?.factor ?? 1

    return row.pieces.length > 0
      ? rollTakes(row).reduce((sum, p) => sum + p.take, 0)
      : rowQty(row) * factor
  }

  const renderPieceList = (row) => {
    const takes    = rollTakes(row)
    const cutCount = takes.filter((t) => t.cut).length
    const baseUom  = conversionFor(row)?.baseSymbol ?? ''

    return (
      <div className="rounded border border-indigo-100 bg-indigo-50/40 px-2 py-1">
        {cutCount > 0 && (
          <p className={`mb-1 text-[10px] font-semibold ${cutCount > 1 ? 'text-amber-600' : 'text-indigo-500'}`}>
            {cutCount === 1
              ? '1 roll will be cut — 1 new remnant label to print.'
              : `${cutCount} rolls will be cut — ${cutCount} new remnant labels to print.`}
          </p>
        )}
        <table className="w-full text-[11px]">
          <thead>
            <tr className="text-left text-[9px] font-bold uppercase tracking-wider text-indigo-400">
              <th className="py-0.5 pr-2">Piece Code</th>
              <th className="py-0.5 pr-2">Roll No</th>
              <th className="py-0.5 pr-2 text-right">Holds ({baseUom})</th>
              <th className="py-0.5 pr-2 text-right">Selling ({baseUom})</th>
              <th className="py-0.5 pr-2 text-right">GRN Cost ({CURRENCY}{baseUom && `/${baseUom}`})</th>
              <th className="w-6"></th>
            </tr>
          </thead>
          <tbody>
            {takes.map((p) => (
              <tr key={p.piece_code} className="text-slate-600">
                <td className="py-0.5 pr-2 font-mono">{p.piece_code}</td>
                <td className="py-0.5 pr-2">{p.roll_no || '—'}</td>
                <td className="py-0.5 pr-2 text-right tabular-nums">{fmtQty(p.weight)}</td>
                <td className="py-0.5 pr-2 text-right tabular-nums">
                  <span className={p.cut ? 'font-bold text-amber-600' : ''}>{fmtQty(p.take)}</span>
                  {p.cut && <span className="ml-1 text-[9px] font-bold text-amber-500">CUT</span>}
                </td>
                <td className="py-0.5 pr-2 text-right tabular-nums">{p.grn_unit_price ? fmt(p.grn_unit_price) : '—'}</td>
                <td className="py-0.5 text-center">
                  <button
                    type="button"
                    onClick={() => removePiece(row._key, p.piece_code)}
                    className="rounded p-0.5 text-slate-300 hover:bg-red-50 hover:text-red-500 transition-colors"
                    title="Remove piece"
                  >
                    <X size={10} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    )
  }

  const renderItemsTable = () => (
    <div className="overflow-x-auto">
      <table className="w-full text-xs">
        <thead>
          <tr className="bg-slate-50 border-b border-slate-200">
            <th className="w-7 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-400">#</th>
            <th className="w-32 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Code</th>
            <th className="px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Product</th>
            <th className="w-20 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Colour</th>
            <th className="w-16 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">UOM</th>
            {/* Quantity sits right after UOM so a line reads naturally — "100 Yd at 500/m". */}
            <th className="w-32 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Quantity</th>
            {/* Price is wider than Quantity by one step: its cell also carries the inline
                Price-UOM dropdown, so the input itself ends up the same size. */}
            <th className="w-36 px-1.5 py-1.5 text-left text-[10px] font-bold uppercase tracking-wider text-slate-500">Unit Price ({CURRENCY})</th>
            <th className="w-24 px-1.5 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-500">Gross ({CURRENCY})</th>
            {discountEnabled && <th className="w-16 px-1.5 py-1.5 text-center text-[10px] font-bold uppercase tracking-wider text-amber-500">Disc%</th>}
            {taxEnabled && <th className="w-16 px-1.5 py-1.5 text-center text-[10px] font-bold uppercase tracking-wider text-sky-500">Tax%</th>}
            <th className="w-24 px-1.5 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-700">Amount ({CURRENCY})</th>
            <th className="w-8"></th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {items.map((row, idx) => {
            const { qty, gross, amount } = calcItem(row, discountEnabled, taxEnabled)
            const isScanned = row.pieces.length > 0
            return (
              <FragmentRow key={row._key}>
                <tr className="group hover:bg-slate-50/60 transition-colors">
                  <td className="px-1.5 py-1 text-slate-400 font-medium tabular-nums">{idx + 1}</td>
                  <td className="px-1.5 py-1 min-w-32">
                    <input readOnly value={row.product_code} placeholder="—" title={row.product_code} className={`${INPUT_RO_CLS} px-1.5 py-0.5 text-[11px]`} />
                  </td>
                  <td className="px-1.5 py-1 min-w-56">
                    <div className="flex items-center gap-1">
                      {isScanned && (
                        <button
                          type="button"
                          onClick={() => toggleExpanded(idx)}
                          className="shrink-0 rounded p-0.5 text-indigo-400 hover:bg-indigo-50 transition-colors"
                          title={row.expanded ? 'Hide pieces' : 'Show pieces'}
                        >
                          {row.expanded ? <ChevronDown size={11} /> : <ChevronRight size={11} />}
                        </button>
                      )}
                      <ProductSearchCell
                        row={row}
                        productSearch={productSearch}
                        onQueryChange={handleProductQueryChange}
                        onSelect={selectProduct}
                        onClear={clearProductSelection}
                        onClose={() => setProductSearch((prev) => ({ ...prev, open: false }))}
                        onInputRef={setCellRef(row._key, 'product')}
                      />
                      {isScanned && (
                        <button
                          type="button"
                          onClick={() => setRollPickerKey(row._key)}
                          title="Adjust the rolls on this line"
                          className="ml-auto shrink-0 rounded-full bg-indigo-100 px-1.5 py-px text-[9px] font-bold text-indigo-600 hover:bg-indigo-200 transition-colors"
                        >
                          <QrCode size={8} className="mr-0.5 inline" />
                          {row.pieces.length} pc{row.pieces.length !== 1 ? 's' : ''}
                        </button>
                      )}
                    </div>
                    {row.product_id && !isScanned && row.available_count === 0 && row.available_stock !== 0 && (
                      <span className="mt-0.5 inline-block rounded bg-red-50 px-1.5 py-px text-[9px] font-bold text-red-500">
                        No rolls available in stock
                      </span>
                    )}
                    {row.product_id && !isScanned && row.available_stock === 0 && (
                      <span className="mt-0.5 inline-block rounded bg-red-50 px-1.5 py-px text-[9px] font-bold text-red-500">
                        No available stock
                      </span>
                    )}
                  </td>
                  <td className="px-1.5 py-1">
                    {isScanned ? (
                      <span className="text-xs text-slate-600">{row.color_name || <span className="italic text-slate-300">—</span>}</span>
                    ) : (
                      <DropdownSelectCell
                        cellRef={setCellRef(row._key, 'color')}
                        value={row.attribute_id}
                        groups={[{ label: null, items: row.color_options.map((c) => ({ value: String(c.id), label: c.name })) }]}
                        onChange={(val) => setRowField(idx, 'attribute_id', val)}
                        onNext={() => focusCell(row._key, 'unit')}
                        placeholder={!row.product_id ? '—' : row.color_options.length === 0 ? 'No colours' : '—'}
                        disabled={!row.product_id || row.color_options.length === 0}
                      />
                    )}
                  </td>
                  <td className="px-1.5 py-1">
                    <DropdownSelectCell
                      cellRef={setCellRef(row._key, 'unit')}
                      value={row.unit_id}
                      groups={uomGroupsFor(row.base_unit_category_id)}
                      onChange={(val) => setRowField(idx, 'unit_id', val)}
                      // Quantity is the next column; lines showing "Select Rolls" instead
                      // of a quantity input fall through to the price field.
                      onNext={() => focusCell(row._key, cellRefs.current[row._key]?.qty ? 'qty' : 'price')}
                    />
                  </td>
                  {/* Quantity — right after UOM, so a line reads "100 Yd at 500/m". */}
                  <td className="px-1.5 py-1">
                    {isScanned ? (() => {
                      // Editable: the customer may take less than the rolls hold, and the
                      // last roll reached is cut at dispatch. Capacity is the ceiling.
                      const conv     = conversionFor(row)
                      const capacity = capacityInLineUom(row)
                      const over     = qty > capacity + QTY_TOLERANCE

                      return (
                        <div className="flex flex-col gap-0.5">
                          <input
                            ref={setCellRef(row._key, 'qty')}
                            type="number" min="0" step="0.0001" value={row.quantity}
                            onChange={(e) => setRowField(idx, 'quantity', e.target.value)}
                            onKeyDown={(e) => {
                              if (e.key === 'Enter') { e.preventDefault(); focusCell(row._key, 'price') }
                            }}
                            className={`${over ? TABLE_INPUT_WARN : TABLE_INPUT} text-right tabular-nums`}
                            title={`Rolls hold ${fmtQty(capacity)} ${conv?.lineSymbol ?? ''} — sell all or part`}
                          />
                          {over ? (
                            <span className="text-[9px] font-medium text-red-500 leading-none">
                              Rolls hold only {fmtQty(capacity)} {conv?.lineSymbol}
                            </span>
                          ) : (
                            <span className="text-[9px] text-slate-400 leading-none">
                              of {fmtQty(capacity)} {conv?.lineSymbol}
                            </span>
                          )}
                          {/* What actually leaves stock */}
                          {conv && !conv.same && conv.factor && qty > 0 && (
                            <span className="text-[9px] font-medium text-indigo-500 leading-none">
                              = {fmtQty(baseQtyOf(row), 6)} {conv.baseSymbol}
                            </span>
                          )}
                        </div>
                      )
                    })() : row.product_id && row.available_count > 0 ? (
                      <button
                        type="button"
                        onClick={() => setRollPickerKey(row._key)}
                        title={`${row.available_count} rolls (${fmtQty(row.available_weight)} ${conversionFor(row)?.baseSymbol ?? ''}) in stock — select which rolls to sell`}
                        className="flex w-full items-center justify-center gap-1 rounded border border-violet-300 bg-violet-50 px-1.5 py-0.5 text-[10px] font-bold text-violet-700 hover:bg-violet-100 transition-colors"
                      >
                        <Boxes size={10} /> Select Rolls ({row.available_count})
                      </button>
                    ) : (
                      <input
                        ref={setCellRef(row._key, 'qty')}
                        type="number" min="0" step="0.0001" placeholder="0" value={row.quantity}
                        onChange={(e) => setRowField(idx, 'quantity', e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') { e.preventDefault(); focusCell(row._key, 'price') }
                        }}
                        className={TABLE_INPUT} />
                    )}
                  </td>
                  {/* Unit Price — quoted per its OWN unit (the Price UOM dropdown), which may
                      differ from the selling UOM: qty in Yard at the per-Metre price bills
                      qty x price, unconverted. The hint shows the effective per-base figure. */}
                  <td className="px-1.5 py-1">
                    {(() => {
                      const conv      = conversionFor(row)
                      const factor    = conv?.factor ?? 1
                      const belowCost = isBelowCost(row, factor)
                      const price     = parseFloat(row.unit_price) || 0

                      return (
                        <div className="flex flex-col gap-0.5">
                          <div className="flex items-center gap-0.5">
                            <input
                              ref={setCellRef(row._key, 'price')}
                              type="number" min="0" step="0.01" placeholder="0.00" value={row.unit_price}
                              onChange={(e) => setRowField(idx, 'unit_price', e.target.value)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter') {
                                  e.preventDefault()
                                  if (discountEnabled) focusCell(row._key, 'disc')
                                  else if (isScanned) scanInputRef.current?.focus()
                                  else focusCell(row._key, 'product')
                                }
                              }}
                              className={belowCost ? TABLE_INPUT_WARN : TABLE_INPUT} />
                            <div className="w-14 shrink-0" title="Unit the price is quoted per — independent of the selling UOM">
                              <DropdownSelectCell
                                value={row.price_unit_id}
                                groups={uomGroupsFor(row.base_unit_category_id)}
                                onChange={(val) => setRowField(idx, 'price_unit_id', val)}
                                placeholder="/—"
                                disabled={!row.product_id}
                              />
                            </div>
                          </div>

                          {/* The effective per-stocking-UOM revenue (amount / stock deducted),
                              so a yard quantity billed per metre shows what it really earns. */}
                          {conv && !conv.same && conv.factor && price > 0 && (
                            <span className="text-[9px] font-medium leading-none text-indigo-500">
                              = {fmtQty(price / conv.factor, 4)} /{conv.baseSymbol} effective
                            </span>
                          )}
                          {belowCost && (
                            <p className="text-[9px] font-semibold leading-tight text-amber-600">
                              Below cost ({fmtMoneyWithSymbol(rowCostBasis(row) * factor)}/{conv?.lineSymbol ?? ''})
                            </p>
                          )}
                        </div>
                      )
                    })()}
                    {row.no_selling_price && !row.unit_price && (
                      <p className="mt-0.5 text-[9px] font-semibold leading-tight text-amber-600">No selling price on product</p>
                    )}
                    {row.pieces.length > 0 && row.pieces[0]?.price_source === 'price_list' && (
                      <p className="mt-0.5 text-[9px] leading-tight text-slate-400" title="This shipment has no confirmed costing — the product price-list price was used">Price-list price (no costing)</p>
                    )}
                  </td>
                  <td className="px-1.5 py-1 text-right font-medium text-slate-600 tabular-nums">
                    {gross > 0 ? fmt(gross) : <span className="text-slate-300">—</span>}
                  </td>
                  {discountEnabled && (
                    <td className="px-1.5 py-1">
                      <input
                        ref={setCellRef(row._key, 'disc')}
                        type="number" min="0" max="100" step="0.01" placeholder="0" value={row.discount}
                        onChange={(e) => setRowField(idx, 'discount', e.target.value)}
                        onKeyDown={(e) => {
                          if (e.key === 'Enter') { e.preventDefault(); if (taxEnabled) focusCell(row._key, 'tax') }
                        }}
                        className="block w-full rounded border border-amber-200 bg-amber-50/50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-amber-400 focus:bg-white" />
                    </td>
                  )}
                  {taxEnabled && (
                    <td className="px-1.5 py-1">
                      <input
                        ref={setCellRef(row._key, 'tax')}
                        type="number" min="0" max="100" step="0.01" placeholder="0" value={row.tax}
                        onChange={(e) => setRowField(idx, 'tax', e.target.value)}
                        className="block w-full rounded border border-sky-200 bg-sky-50/50 px-1.5 py-0.5 text-xs text-slate-800 outline-none transition-all focus:border-sky-400 focus:bg-white" />
                    </td>
                  )}
                  <td className="px-1.5 py-1 text-right font-bold text-slate-800 tabular-nums">
                    {amount > 0 ? fmt(amount) : <span className="text-slate-300 font-normal">—</span>}
                  </td>
                  <td className="px-1.5 py-1 text-center">
                    <button type="button" onClick={() => removeRow(idx)} className="rounded p-1 text-slate-300 opacity-0 group-hover:opacity-100 hover:bg-red-50 hover:text-red-500 transition-all" title="Remove row">
                      <Trash2 size={12} />
                    </button>
                  </td>
                </tr>
                {isScanned && row.expanded && (
                  <tr>
                    <td></td>
                    <td colSpan={itemColSpan - 1} className="px-1.5 pb-1.5">
                      {renderPieceList(row)}
                    </td>
                  </tr>
                )}
              </FragmentRow>
            )
          })}
        </tbody>

        <tfoot>
          <tr className="border-t border-slate-200 bg-slate-50/50">
            <td colSpan={itemColSpan - 3} className="px-2 py-1.5 text-right text-[10px] font-bold uppercase tracking-wider text-slate-400">
              Transport Charge :
            </td>
            <td className="px-1.5 py-1.5">
              <input
                type="number" min="0" step="0.01" placeholder="0.00" value={form.transport_charge}
                onChange={setField('transport_charge')}
                className={`${TABLE_INPUT} text-right`}
              />
              {err('transport_charge') && <p className={ERR_CLS}>{err('transport_charge')}</p>}
            </td>
            <td colSpan={2}></td>
          </tr>
          <tr className="bg-indigo-50 border-t border-indigo-100">
            <td colSpan={itemColSpan - 2} className="px-3 py-1.5">
              <div className="flex items-center gap-4 text-xs">
                <span className="font-bold uppercase tracking-wider text-indigo-600">Summary</span>
                <span className="text-slate-500">Gross: <Money value={totals.gross} className="font-bold text-slate-700" /></span>
                {discountEnabled && <span className="text-amber-600">Disc: -<Money value={totals.disc} className="font-bold" /></span>}
                {taxEnabled && <span className="text-sky-600">Tax: +<Money value={totals.tax} className="font-bold" /></span>}
                {transportCharge > 0 && <span className="text-slate-500">Transport: <Money value={transportCharge} className="font-bold text-slate-700" /></span>}
              </div>
            </td>
            <td className="px-3 py-1.5 text-right">
              <div className="flex flex-col items-end leading-tight">
                <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-500">Net Total</span>
                <Money value={netAmount} className="text-base font-black text-indigo-700" />
              </div>
            </td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  )

  const renderItemsForm = () => (
    <div className="grid grid-cols-1 gap-2 p-3 md:grid-cols-2 xl:grid-cols-3">
      {items.map((row, idx) => {
        const { qty, gross, amount } = calcItem(row, discountEnabled, taxEnabled)
        const isScanned = row.pieces.length > 0
        return (
          <div key={row._key} className="rounded-lg border border-slate-200 bg-slate-50/50 p-2">
            <div className="mb-1.5 flex items-center justify-between">
              <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Line {idx + 1}</span>
              <div className="flex items-center gap-1">
                {isScanned && (
                  <span className="rounded-full bg-indigo-100 px-1.5 py-px text-[9px] font-bold text-indigo-600">
                    <QrCode size={8} className="mr-0.5 inline" />
                    {row.pieces.length} pc{row.pieces.length !== 1 ? 's' : ''}
                  </span>
                )}
                <button type="button" onClick={() => removeRow(idx)} className="rounded p-1 text-slate-300 hover:bg-red-50 hover:text-red-500 transition-colors" title="Remove line">
                  <Trash2 size={12} />
                </button>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-1.5">
              <div className="col-span-2">
                <label className={LABEL_CLS}>Product</label>
                <ProductSearchCell
                  row={row}
                  productSearch={productSearch}
                  onQueryChange={handleProductQueryChange}
                  onSelect={selectProduct}
                  onClear={clearProductSelection}
                  onClose={() => setProductSearch((prev) => ({ ...prev, open: false }))}
                />
              </div>
              <div>
                <label className={LABEL_CLS}>Colour</label>
                {isScanned ? (
                  <input readOnly value={row.color_name || '—'} className={TABLE_INPUT_RO} />
                ) : (
                  <DropdownSelectCell
                    value={row.attribute_id}
                    groups={[{ label: null, items: row.color_options.map((c) => ({ value: String(c.id), label: c.name })) }]}
                    onChange={(val) => setRowField(idx, 'attribute_id', val)}
                    placeholder={!row.product_id ? '—' : row.color_options.length === 0 ? 'No colours' : '—'}
                    disabled={!row.product_id || row.color_options.length === 0}
                  />
                )}
              </div>
              <div>
                <label className={LABEL_CLS}>UOM</label>
                <DropdownSelectCell
                  value={row.unit_id}
                  groups={uomGroupsFor(row.base_unit_category_id)}
                  onChange={(val) => setRowField(idx, 'unit_id', val)}
                />
              </div>
              {/* Quantity right after UOM, so the card reads "100 Yd at 500/m" */}
              <div>
                <label className={LABEL_CLS}>Quantity</label>
                {isScanned ? (
                  <>
                    <input
                      type="number" min="0" step="0.0001" value={row.quantity}
                      onChange={(e) => setRowField(idx, 'quantity', e.target.value)}
                      className={`${TABLE_INPUT} text-right tabular-nums`}
                    />
                    <p className="mt-0.5 text-[9px] leading-tight text-slate-400">
                      of {fmtQty(capacityInLineUom(row))} {conversionFor(row)?.lineSymbol} on the rolls
                    </p>
                  </>
                ) : row.product_id && row.available_count > 0 ? (
                  <button
                    type="button"
                    onClick={() => setRollPickerKey(row._key)}
                    className="flex w-full items-center justify-center gap-1 rounded border border-violet-300 bg-violet-50 px-1.5 py-0.5 text-[10px] font-bold text-violet-700 hover:bg-violet-100 transition-colors"
                  >
                    <Boxes size={10} /> Select Rolls ({row.available_count})
                  </button>
                ) : (
                  <input type="number" min="0" step="0.0001" value={row.quantity} onChange={(e) => setRowField(idx, 'quantity', e.target.value)} className={TABLE_INPUT} />
                )}
              </div>
              <div>
                <label className={LABEL_CLS}>
                  Unit Price ({CURRENCY}{priceConversionFor(row)?.priceSymbol ? `/${priceConversionFor(row).priceSymbol}` : ''})
                </label>
                <div className="flex items-center gap-0.5">
                  <input
                    type="number" min="0" step="0.01" value={row.unit_price}
                    onChange={(e) => setRowField(idx, 'unit_price', e.target.value)}
                    className={isBelowCost(row, conversionFor(row)?.factor ?? 1) ? TABLE_INPUT_WARN : TABLE_INPUT}
                  />
                  <div className="w-14 shrink-0" title="Unit the price is quoted per — independent of the selling UOM">
                    <DropdownSelectCell
                      value={row.price_unit_id}
                      groups={uomGroupsFor(row.base_unit_category_id)}
                      onChange={(val) => setRowField(idx, 'price_unit_id', val)}
                      placeholder="/—"
                      disabled={!row.product_id}
                    />
                  </div>
                </div>
                {isBelowCost(row, conversionFor(row)?.factor ?? 1) && (
                  <p className="mt-0.5 text-[9px] font-semibold leading-tight text-amber-600">
                    Below cost ({fmtMoneyWithSymbol(rowCostBasis(row) * (conversionFor(row)?.factor ?? 1))})
                  </p>
                )}
                {row.no_selling_price && !row.unit_price && (
                  <p className="mt-0.5 text-[9px] font-semibold leading-tight text-amber-600">No selling price on product</p>
                )}
              </div>
              <div>
                <label className={LABEL_CLS}>Gross ({CURRENCY})</label>
                <input readOnly value={gross > 0 ? fmt(gross) : ''} className={`${TABLE_INPUT_RO} text-right tabular-nums`} />
              </div>
              {discountEnabled && (
                <div>
                  <label className={LABEL_CLS}>Disc %</label>
                  <input type="number" min="0" max="100" step="0.01" value={row.discount} onChange={(e) => setRowField(idx, 'discount', e.target.value)} className={TABLE_INPUT} />
                </div>
              )}
              {taxEnabled && (
                <div>
                  <label className={LABEL_CLS}>Tax %</label>
                  <input type="number" min="0" max="100" step="0.01" value={row.tax} onChange={(e) => setRowField(idx, 'tax', e.target.value)} className={TABLE_INPUT} />
                </div>
              )}
              <div>
                <label className={LABEL_CLS}>Amount ({CURRENCY})</label>
                <input readOnly value={amount > 0 ? fmt(amount) : ''} className={`${TABLE_INPUT_RO} text-right font-bold tabular-nums`} />
              </div>
              {isScanned && (
                <div className="col-span-2">
                  {renderPieceList(row)}
                </div>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )

  return (
    <div className="w-full">
      {/* Page Header + summary tiles */}
      <div className="mb-2 flex items-start justify-between gap-2">
        <div>
          <h1 className="text-base font-bold leading-none text-slate-800">
            {isEdit ? 'Edit Sales Order' : 'New Sales Order'}
          </h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <div className="flex gap-2">
          <div className="rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-1.5 text-center">
            <Money value={netAmount} className="block text-sm font-black leading-tight text-indigo-700" />
            <div className="text-[9px] font-bold uppercase tracking-wider text-indigo-400">Net Amount</div>
          </div>
          <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-1.5 text-center">
            <div className="text-sm font-black leading-tight text-slate-700 tabular-nums">{totals.qty.toLocaleString(undefined, { maximumFractionDigits: 2 })}</div>
            <div className="text-[9px] font-bold uppercase tracking-wider text-slate-400">Quantity</div>
          </div>
        </div>
      </div>

      <div className="flex flex-col gap-2">

        {/* ── Sales Order Details ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader icon={CalendarDays} title="Sales Order Details" colorClass="text-indigo-700 bg-indigo-50 border-indigo-100" />
          <div className="space-y-2 p-3">

            {/* Row 1: Date | SO Code | Reference No | Transaction Date | Expected Date */}
            <div className="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-5">
              <div>
                <label className={LABEL_CLS}>Date <span className="text-red-500 normal-case font-bold">*</span></label>
                <input type="date" className={err('order_date') ? INPUT_ERR_CLS : INPUT_CLS} value={form.order_date} onChange={setField('order_date')} />
                {err('order_date') && <p className={ERR_CLS}>{err('order_date')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Sales Order Code</label>
                <input
                  readOnly
                  className={INPUT_RO_CLS}
                  value={isEdit ? (existingSO?.data?.so_no ?? '') : (loadingSoNo ? 'Generating…' : (nextSoNo ?? ''))}
                  title={isEdit ? '' : 'Preview — the final number is assigned at save time'}
                />
              </div>
              <div>
                <label className={LABEL_CLS}>Reference Number</label>
                <input className={INPUT_CLS} placeholder="e.g. REF-001" value={form.reference_no} onChange={setField('reference_no')} />
              </div>
              <div>
                <label className={LABEL_CLS}>Transaction Date</label>
                <input type="date" className={INPUT_CLS} value={form.transaction_date} onChange={setField('transaction_date')} />
              </div>
              <div>
                <label className={LABEL_CLS}>Expected Date</label>
                <input type="date" className={err('expected_date') ? INPUT_ERR_CLS : INPUT_CLS} value={form.expected_date} onChange={setField('expected_date')} />
                {err('expected_date') && <p className={ERR_CLS}>{err('expected_date')}</p>}
              </div>
            </div>

          </div>
        </div>

        {/* ── Customer & Sales Details ── */}
        <div className="rounded-lg border border-slate-200 bg-white shadow-sm">
          <SectionHeader icon={User} title="Customer & Sales Details" colorClass="text-emerald-700 bg-emerald-50 border-emerald-100" />
          <div className="p-3">
            <div className="grid grid-cols-2 gap-2 md:grid-cols-3 lg:grid-cols-4">
              <div>
                <label className={LABEL_CLS}>Customer Type</label>
                <select
                  className={err('customer_type') ? SELECT_ERR_CLS : SELECT_CLS}
                  value={form.customer_type}
                  onChange={(e) => {
                    setForm((f) => ({ ...f, customer_type: e.target.value, customer_id: '' }))
                    clearFieldError('customer_type')
                  }}
                >
                  <option value="">— Select —</option>
                  {CUSTOMER_TYPES.map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
                {err('customer_type') && <p className={ERR_CLS}>{err('customer_type')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Customer Name/Code <span className="text-red-500 normal-case font-bold">*</span></label>
                <FilterSearchSelect
                  value={form.customer_id}
                  onChange={handleCustomerChange}
                  options={customerOptions}
                  placeholder="Select customer…"
                />
                {err('customer_id') && <p className={ERR_CLS}>{err('customer_id')}</p>}
              </div>
              <div>
                <label className={LABEL_CLS}>Order Source</label>
                <select className={SELECT_CLS} value={form.order_source} onChange={setField('order_source')}>
                  <option value="">— Select —</option>
                  {orderSources.map((s) => <option key={s.value} value={s.value}>{s.label}</option>)}
                </select>
              </div>
              <div>
                <label className={LABEL_CLS}>Order Taken By</label>
                <FilterSearchSelect
                  value={form.order_taken_by}
                  onChange={(val) => setForm((f) => ({ ...f, order_taken_by: val }))}
                  options={userOptions}
                  placeholder="Select user…"
                />
              </div>
              <div>
                <label className={LABEL_CLS}>Sales Person <span className="text-red-500 normal-case font-bold">*</span></label>
                <FilterSearchSelect
                  value={form.sales_person_id}
                  onChange={(val) => { setForm((f) => ({ ...f, sales_person_id: val })); clearFieldError('sales_person_id') }}
                  options={userOptions}
                  placeholder="Select sales person…"
                />
                {err('sales_person_id') && <p className={ERR_CLS}>{err('sales_person_id')}</p>}
              </div>
              <div className="col-span-2">
                <label className={LABEL_CLS}>Delivery Address</label>
                <input className={INPUT_CLS} placeholder="Delivery address" value={form.delivery_address} onChange={setField('delivery_address')} />
              </div>
              <div>
                <label className={LABEL_CLS}>Remark</label>
                <input className={INPUT_CLS} placeholder="Notes…" value={form.remarks} onChange={setField('remarks')} />
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
              <div className="flex items-center gap-3">
                <CheckToggle checked={discountEnabled} onChange={setDiscountEnabled} label="Discount %" title="Show/hide the line discount column" />
                <CheckToggle checked={taxEnabled} onChange={setTaxEnabled} label="Tax %" title="Show/hide the line tax column" />
                <div className="h-4 w-px bg-violet-200" />
                {/* Form / Table view toggle */}
                <div className="flex overflow-hidden rounded border border-violet-200">
                  <button
                    type="button"
                    onClick={() => setViewMode('form')}
                    className={`flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold transition-colors ${viewMode === 'form' ? 'bg-violet-600 text-white' : 'bg-white text-violet-600 hover:bg-violet-50'}`}
                  >
                    <LayoutList size={10} /> Form View
                  </button>
                  <button
                    type="button"
                    onClick={() => setViewMode('table')}
                    className={`flex items-center gap-1 px-2 py-0.5 text-[10px] font-bold transition-colors ${viewMode === 'table' ? 'bg-violet-600 text-white' : 'bg-white text-violet-600 hover:bg-violet-50'}`}
                  >
                    <TableIcon size={10} /> Table View
                  </button>
                </div>
                <div className="h-4 w-px bg-violet-200" />
                <button
                  type="button"
                  onClick={addManualRow}
                  title="Add new item (Alt+N)"
                  className="flex items-center gap-1 rounded border border-violet-200 bg-white px-2 py-0.5 text-[10px] font-bold text-violet-700 hover:bg-violet-100 transition-colors"
                >
                  <Plus size={10} /> Add Row
                  <span className="ml-0.5 rounded bg-violet-100 px-1 py-px text-[9px] font-mono text-violet-500">Alt+N</span>
                </button>
              </div>
            }
          />

          {/* ── QR scan bar ── */}
          <div className="flex items-center gap-2 border-b border-indigo-100 bg-indigo-50/60 px-3 py-1.5">
            <QrCode size={14} className="shrink-0 text-indigo-500" />
            <input
              ref={scanInputRef}
              type="text"
              autoFocus
              placeholder="Scan a piece QR (or type the piece code and press Enter)…"
              value={scanValue}
              onChange={(e) => setScanValue(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') { e.preventDefault(); handleScan() }
              }}
              className="block w-full max-w-md rounded border border-indigo-200 bg-white px-2 py-1 text-xs text-slate-800 placeholder-indigo-300 outline-none transition-all focus:border-indigo-400 focus:ring-1 focus:ring-indigo-500/20"
            />
            <span className="hidden text-[10px] text-indigo-400 sm:block">
              Each scanned roll is reserved for this order — same product merges into one line.
            </span>
          </div>

          {viewMode === 'table' ? renderItemsTable() : (
            <>
              {renderItemsForm()}
              {/* Form view keeps the same transport + net summary strip */}
              <div className="flex items-center justify-between gap-3 border-t border-indigo-100 bg-indigo-50 px-3 py-1.5">
                <div className="flex items-center gap-2">
                  <span className="text-[10px] font-bold uppercase tracking-wider text-slate-400">Transport Charge :</span>
                  <input
                    type="number" min="0" step="0.01" placeholder="0.00" value={form.transport_charge}
                    onChange={setField('transport_charge')}
                    className={`${TABLE_INPUT} w-28 text-right`}
                  />
                </div>
                <div className="flex flex-col items-end leading-tight">
                  <span className="text-[10px] font-bold uppercase tracking-wider text-indigo-500">Net Total</span>
                  <Money value={netAmount} className="text-base font-black text-indigo-700" />
                </div>
              </div>
            </>
          )}

          {err('items') && (
            <div className="border-t border-red-100 bg-red-50 px-3 py-1.5 text-xs font-medium text-red-600">
              {err('items')}
            </div>
          )}

          {/* ── Action bar ── */}
          <div className="flex items-center justify-end gap-2 border-t border-slate-100 bg-white px-3 py-2">
            <button
              type="button"
              onClick={handleClear}
              className="flex items-center gap-1 rounded border border-red-200 bg-white px-3 py-1.5 text-xs font-bold text-red-500 transition-all hover:bg-red-50 active:scale-95"
            >
              <RefreshCw size={11} /> Clear
            </button>
            {showDraftButton && (
              <button
                type="button"
                disabled={saveMutation.isPending}
                onClick={() => handleSubmit('draft')}
                className="flex items-center gap-1 rounded border border-slate-300 bg-white px-4 py-1.5 text-xs font-bold text-slate-600 transition-all hover:bg-slate-50 disabled:opacity-60 active:scale-95"
              >
                <Save size={11} /> Save as Draft
              </button>
            )}
            <button
              type="button"
              disabled={saveMutation.isPending}
              onClick={() => handleSubmit(primaryStatus)}
              className="flex items-center gap-1 rounded bg-indigo-600 px-4 py-1.5 text-xs font-bold text-white shadow-sm transition-all hover:bg-indigo-700 disabled:opacity-60 active:scale-95"
            >
              {saveMutation.isPending ? <><RefreshCw size={11} className="animate-spin" /> Saving…</> : <><Save size={11} /> {primaryLabel}</>}
            </button>
          </div>

        </div>

      </div>

      {/* Roll picker for manual lines / adjusting scanned lines */}
      {rollPickerKey != null && (() => {
        const pickerRow = items.find((r) => r._key === rollPickerKey)
        if (!pickerRow?.product_id) return null
        const otherLineCodes = items
          .filter((r) => r._key !== rollPickerKey)
          .flatMap((r) => r.pieces.map((p) => p.piece_code))
        return (
          <SalesOrderRollPickerModal
            product={{ id: pickerRow.product_id, name: pickerRow.product_name, product_code: pickerRow.product_code }}
            initialPieces={pickerRow.pieces}
            initialAttributeId={pickerRow.attribute_id}
            disabledCodes={otherLineCodes}
            onApply={(pieces) => applyPickedRolls(rollPickerKey, pieces)}
            onClose={() => setRollPickerKey(null)}
          />
        )
      })()}

      {/* Keyboard Shortcuts Reference */}
      <div className="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
        <p className="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Keyboard Shortcuts</p>
        <div className="flex flex-wrap gap-x-5 gap-y-1">
          {[
            { keys: ['Scan', '⏎'], desc: 'Scan a piece QR into the scan bar to add/merge a line' },
            { keys: ['Alt', 'N'],  desc: 'Add manual item row — use Select Rolls when the product has rolls in stock' },
            { keys: ['Enter'],     desc: 'Move to next field in row (Price → Qty → Disc)' },
            { keys: ['↑', '↓'],   desc: 'Navigate product search results' },
            { keys: ['Esc'],       desc: 'Close product search dropdown' },
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

/* Tables can't nest a Fragment with a key directly in JSX map without a wrapper */
function FragmentRow({ children }) {
  return <>{children}</>
}
