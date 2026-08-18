import { useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { BarChart2, Check, ChevronDown, HelpCircle, Package, Plus, Save, Settings, ShoppingCart, Tag, Trash2, X } from 'lucide-react'
import { createProduct, getNextProductCode, getProduct, updateProduct } from '../../api/products'
import { getAllAttributes } from '../../api/attributes'
import { getAllAttributeTypes } from '../../api/attributeTypes'
import { getAllCategories } from '../../api/categories'
import { getAllLocations } from '../../api/locations'
import { getAllSalesChannels } from '../../api/salesChannels'
import { getAllStores } from '../../api/stores'
import { getAllSuppliers } from '../../api/suppliers'
import { getAllUnitTypesFlat } from '../../api/unitTypes'
import Breadcrumb from '../../components/Breadcrumb'
import TreeSelect from '../../components/TreeSelect'
import { showError, showSuccess } from '../../utils/alerts'

const PRODUCT_TYPES   = ['Inventory', 'Raw Material', 'Service']
const STOCK_METHODS   = ['FIFO', 'LIFO', 'FEFO']
const TRACKING_TYPES  = ['Batch', 'Serial']

const BOOL_OPTIONS = [
  { key: 'lock_purchase',             label: 'Lock Purchase' },
  { key: 'allow_complimentary_items', label: 'Allow Complementary' },
  { key: 'free_issue',                label: 'Free Issue' },
  { key: 'allow_minus',               label: 'Allow Minus Stock' },
  { key: 'not_allow_direct_sale',     label: 'No Direct Sale' },
  { key: 'non_returnable',            label: 'Non-Returnable' },
  { key: 'is_empty',                  label: 'Is Empty' },
  { key: 'service_charge',            label: 'Service Charge' },
  { key: 'loyalty',                   label: 'Loyalty' },
  { key: 'is_batch',                  label: 'Batch Tracked' },
  { key: 'is_serial',                 label: 'Serial Tracked' },
]

const EMPTY_ATTRIBUTE_ROW      = { attribute_type_id: '', attribute_ids: [] }
const EMPTY_LOCATION_STORE_ROW = { location_id: '', store_id: '' }

const EMPTY_COST_ROW = {
  sales_channel_id:               '',
  unit_category_id:               '',
  unit_type_id:                   '',
  num_of_units:                   '1',
  cost_price:                     '',
  margin:                         '',
  margin_type:                    'percentage',
  selling_price:                  '',
  max_price:                      '',
  min_price:                      '',
  wholesale_price:                '',
  sale_privileges_discount:       '',
  purchasing_privileges_discount: '',
}

const EMPTY_FORM = {
  product_code:              '',
  reference_no:              '',
  ean_13:                    '',
  name:                      '',
  display_name:              '',
  product_type:              'Inventory',
  description:               '',
  category_id:               '',
  base_unit_type_id:         '',
  location_stores:           [],
  reorder_level:             '',
  reorder_qty:               '',
  reorder_period:            '',
  stock_releasing_method:    'FIFO',
  tracking_type:             '',
  lock_purchase:              false,
  allow_complimentary_items:  false,
  free_issue:                 false,
  allow_minus:                false,
  not_allow_direct_sale:      false,
  non_returnable:             false,
  is_empty:                   false,
  service_charge:             false,
  loyalty:                    false,
  is_batch:                   false,
  is_serial:                  false,
  supplier_ids:               [],
  cost_details:               [],
  product_attributes:         [],
}

function validateField(field, value) {
  if (field === 'name') {
    if (!String(value).trim()) return 'Required.'
    if (String(value).length > 100) return 'Max 100 chars.'
  }
  if (field === 'product_code') {
    if (!String(value ?? '').trim()) return 'Required.'
    if (String(value).length > 50) return 'Max 50 chars.'
  }
  if (field === 'display_name') {
    if (!String(value ?? '').trim()) return 'Required.'
    if (String(value).length > 100) return 'Max 100 chars.'
  }
  if (field === 'product_type' && !value) return 'Required.'
  if (field === 'category_id'  && !value) return 'Required.'
  if (field === 'base_unit_type_id' && !value) return 'Required — stock is held in this unit.'
  if (field === 'supplier_ids' && (!value || value.length === 0)) return 'At least one supplier required.'
  if (field === 'reference_no' && value && String(value).length > 50) return 'Max 50 chars.'
  if (field === 'ean_13' && value && String(value).length > 50) return 'Max 50 chars.'
  if ((field === 'reorder_level' || field === 'reorder_qty') && value !== '' && (isNaN(Number(value)) || Number(value) < 0)) {
    return 'Must be a positive number.'
  }
  return ''
}

// Walk parent_category_id up to the root, returning [selectedId, ...ancestorIds].
// Guards against circular references by tracking visited ids.
function getCategoryAncestorIds(categoryId, categories) {
  const byId = new Map(categories.map((c) => [String(c.id), c]))
  const ids = []
  const visited = new Set()
  let current = categoryId ? String(categoryId) : null

  while (current && byId.has(current) && !visited.has(current)) {
    visited.add(current)
    ids.push(current)
    const parentId = byId.get(current).parent_category_id
    current = parentId != null ? String(parentId) : null
  }

  return ids
}

// Compute average price = (cost_price + selling_price) / 2
function computeAverage(costPrice, sellingPrice) {
  const cp = parseFloat(costPrice)
  const sp = parseFloat(sellingPrice)
  if (isNaN(cp) && isNaN(sp)) return ''
  if (isNaN(cp)) return sp.toFixed(4)
  if (isNaN(sp)) return cp.toFixed(4)
  return ((cp + sp) / 2).toFixed(4)
}

const LABEL_CLS = 'block text-[10px] font-bold uppercase tracking-wider text-slate-500 mb-0.5'
const ERR_CLS   = 'mt-0.5 text-[10px] text-red-500'

function Field({ label, required, helpText, error, touched, className, children }) {
  return (
    <div className={className}>
      <label className={`${LABEL_CLS} flex items-center gap-1`}>
        {label}
        {required && <span className="text-red-500">*</span>}
        {helpText && (
          <span title={helpText} className="cursor-help text-slate-400">
            <HelpCircle size={11} />
          </span>
        )}
      </label>
      {children}
      {error && touched && <p className={ERR_CLS}>{error}</p>}
    </div>
  )
}

function Input({ name, value, onChange, onBlur, error, touched, ...props }) {
  return (
    <input
      name={name}
      value={value}
      onChange={onChange}
      onBlur={onBlur}
      autoComplete="off"
      className={[
        'block w-full rounded-md border-2 px-2 py-1 text-xs text-slate-800',
        'placeholder-slate-400 outline-none transition-all',
        'focus:ring-2',
        error && touched
          ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:bg-white focus:ring-red-500/15'
          : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:bg-white focus:ring-indigo-500/15',
      ].join(' ')}
      {...props}
    />
  )
}

// Default width for a short/empty value — matches the field's old fixed-column look.
const AUTO_WIDTH_MIN = 200
// Padding (px-2 = 8px * 2) + border (border-2 = 2px * 2) + a little caret breathing room.
const AUTO_WIDTH_EXTRA = 28

// An Input that grows to fit its typed content (min: AUTO_WIDTH_MIN, max: 100% of its row),
// so the row's other fields wrap to the next line once it runs out of space.
function AutoWidthInput({ value, minWidth = AUTO_WIDTH_MIN, ...props }) {
  const measureRef = useRef(null)
  const [width, setWidth] = useState(minWidth)

  useLayoutEffect(() => {
    if (measureRef.current) {
      setWidth(Math.max(minWidth, measureRef.current.scrollWidth + AUTO_WIDTH_EXTRA))
    }
  }, [value, minWidth])

  return (
    <>
      <Input value={value} style={{ width, maxWidth: '100%' }} {...props} />
      <span ref={measureRef} aria-hidden="true" className="invisible absolute left-0 top-0 whitespace-pre text-xs">
        {value}
      </span>
    </>
  )
}

function SelectField({ name, value, onChange, onBlur, error, touched, placeholder, options }) {
  return (
    <select
      name={name}
      value={value}
      onChange={onChange}
      onBlur={onBlur}
      className={[
        'block w-full rounded-md border-2 px-2 py-1 text-xs text-slate-800',
        'outline-none transition-all cursor-pointer',
        'focus:ring-2',
        error && touched
          ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:bg-white focus:ring-red-500/15'
          : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:bg-white focus:ring-indigo-500/15',
      ].join(' ')}
    >
      <option value="">{placeholder ?? '— Select —'}</option>
      {options.map((o) => (
        <option key={typeof o === 'string' ? o : o.value} value={typeof o === 'string' ? o : o.value}>
          {typeof o === 'string' ? o : o.label}
        </option>
      ))}
    </select>
  )
}

function validateCostRow(row) {
  return {
    sales_channel_id: row.sales_channel_id ? '' : 'Required.',
    num_of_units:     (row.num_of_units !== '' && row.num_of_units != null) ? '' : 'Required.',
    cost_price:       (row.cost_price    !== '' && row.cost_price    != null) ? '' : 'Required.',
    selling_price:    (row.selling_price !== '' && row.selling_price  != null) ? '' : 'Required.',
  }
}

// Inline input for cost detail rows — no Field wrapper, smaller
function CostInput({ value, onChange, onBlur, disabled, placeholder, type = 'number', error, touched, ...props }) {
  return (
    <input
      type={type}
      value={value}
      onChange={onChange}
      onBlur={onBlur}
      disabled={disabled}
      placeholder={placeholder}
      autoComplete="off"
      className={[
        'block w-full rounded-md border-2 px-2 py-1 text-xs outline-none transition-all',
        disabled
          ? 'border-slate-100 bg-slate-100 text-slate-400 cursor-not-allowed'
          : error && touched
            ? 'border-red-300 bg-red-50/40 text-slate-800 focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
            : 'border-slate-200 bg-slate-50 text-slate-800 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15',
      ].join(' ')}
      {...props}
    />
  )
}

function CostSelect({ value, onChange, onBlur, children, placeholder, error, touched, disabled }) {
  return (
    <select
      value={value}
      onChange={onChange}
      onBlur={onBlur}
      disabled={disabled}
      className={[
        'block w-full rounded-md border-2 px-2 py-1 text-xs text-slate-800 outline-none transition-all cursor-pointer',
        'disabled:cursor-not-allowed disabled:border-slate-100 disabled:bg-slate-100 disabled:text-slate-400',
        error && touched
          ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
          : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15',
      ].join(' ')}
    >
      <option value="">{placeholder ?? '— Select —'}</option>
      {children}
    </select>
  )
}

function MultiSelectDropdown({
  items, selectedIds, onChange, getId = (i) => i.id, getLabel,
  placeholder = 'Select...', searchPlaceholder = 'Search...', error, touched, disabled,
}) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const ref = useRef(null)

  useEffect(() => {
    function handleOutside(e) {
      if (ref.current && !ref.current.contains(e.target)) setOpen(false)
    }
    if (open) document.addEventListener('mousedown', handleOutside)
    return () => document.removeEventListener('mousedown', handleOutside)
  }, [open])

  const filtered = items.filter((i) =>
    getLabel(i).toLowerCase().includes(search.toLowerCase())
  )

  const toggle = (id) => {
    const next = selectedIds.includes(id)
      ? selectedIds.filter((x) => x !== id)
      : [...selectedIds, id]
    onChange(next)
  }

  const selectedLabels = items
    .filter((i) => selectedIds.includes(getId(i)))
    .map(getLabel)

  return (
    <div ref={ref} className="relative min-w-0">
      <button
        type="button"
        disabled={disabled}
        onClick={() => !disabled && setOpen((o) => !o)}
        className={[
          'flex w-full min-w-0 items-center justify-between rounded-md border-2 px-2 py-1 text-left outline-none transition-all',
          'focus:ring-2 disabled:cursor-not-allowed disabled:border-slate-100 disabled:bg-slate-100 disabled:text-slate-400',
          error && touched
            ? 'border-red-300 bg-red-50/40 focus:border-red-500 focus:bg-white focus:ring-red-500/15'
            : 'border-slate-200 bg-slate-50 focus:border-indigo-500 focus:bg-white focus:ring-indigo-500/15',
        ].join(' ')}
      >
        <span className={`min-w-0 flex-1 truncate text-xs ${selectedLabels.length === 0 ? 'text-slate-400' : 'text-slate-800'}`}>
          {selectedLabels.length === 0 ? placeholder : selectedLabels.join(', ')}
        </span>
        <ChevronDown size={14} className={`ml-1 shrink-0 text-slate-400 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div className="absolute z-50 mt-1 w-full rounded border border-slate-200 bg-white shadow-lg">
          <div className="border-b border-slate-100 p-1.5">
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={searchPlaceholder}
              autoFocus
              className="block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15"
            />
          </div>
          <div className="max-h-48 overflow-y-auto">
            {filtered.length === 0 ? (
              <p className="px-3 py-2 text-xs italic text-slate-400">No results found.</p>
            ) : (
              filtered.map((i) => {
                const id = getId(i)
                const checked = selectedIds.includes(id)
                return (
                  <button
                    key={id}
                    type="button"
                    onClick={() => toggle(id)}
                    className="flex w-full items-center gap-2 px-2.5 py-1.5 text-left hover:bg-slate-50"
                  >
                    <span className={[
                      'flex h-3.5 w-3.5 shrink-0 items-center justify-center rounded border',
                      checked ? 'bg-indigo-600 border-indigo-600' : 'border-slate-300 bg-white',
                    ].join(' ')}>
                      {checked && <Check size={10} className="text-white" strokeWidth={3} />}
                    </span>
                    <span className="truncate text-xs text-slate-700" title={getLabel(i)}>{getLabel(i)}</span>
                  </button>
                )
              })
            )}
          </div>
          {selectedIds.length > 0 && (
            <div className="flex items-center justify-between border-t border-slate-100 px-2.5 py-1.5">
              <span className="text-xs text-slate-500">{selectedIds.length} selected</span>
              <button
                type="button"
                onClick={() => onChange([])}
                className="text-xs text-red-500 hover:text-red-700"
              >
                Clear all
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  )
}

function AttributeTypeRow({ row, idx, attributeTypes, allAttributes, usedAttributeTypeIds, onTypeChange, onAttributesChange, onRemove }) {
  const availableTypes = attributeTypes.filter(
    (t) => !usedAttributeTypeIds.includes(String(t.id)) || String(t.id) === String(row.attribute_type_id)
  )
  const filteredAttrs = allAttributes.filter(
    (a) => String(a.attribute_type_id) === String(row.attribute_type_id)
  )

  return (
    <div className="flex items-end gap-1.5">
      <div className="min-w-0 flex-1">
        {idx === 0 && <label className={LABEL_CLS}>Attribute Type</label>}
        <select
          value={row.attribute_type_id}
          onChange={(e) => onTypeChange(idx, e.target.value)}
          className="w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15 cursor-pointer"
        >
          <option value="">— Type —</option>
          {availableTypes.map((t) => (
            <option key={t.id} value={t.id}>{t.attribute_type_name}</option>
          ))}
        </select>
      </div>
      <div className="min-w-0 flex-1">
        {idx === 0 && <label className={LABEL_CLS}>Attributes</label>}
        <MultiSelectDropdown
          items={filteredAttrs}
          selectedIds={row.attribute_ids}
          onChange={(ids) => onAttributesChange(idx, ids)}
          getId={(a) => String(a.id)}
          getLabel={(a) => a.attribute_name}
          placeholder="Select attributes..."
          searchPlaceholder="Search attributes..."
          disabled={!row.attribute_type_id}
        />
      </div>
      <button
        type="button"
        onClick={() => onRemove(idx)}
        className="shrink-0 rounded p-0.5 text-slate-400 hover:bg-red-100 hover:text-red-600 transition-colors"
      >
        <Trash2 size={13} />
      </button>
    </div>
  )
}

function calcSellingPrice(costPrice, margin, marginType) {
  const cp = parseFloat(costPrice)
  const m  = parseFloat(margin)
  if (isNaN(cp) || isNaN(m)) return ''
  return marginType === 'percentage'
    ? (cp * (1 + m / 100)).toFixed(4)
    : (cp + m).toFixed(4)
}

function calcMarginValue(costPrice, sellingPrice, marginType) {
  const cp = parseFloat(costPrice)
  const sp = parseFloat(sellingPrice)
  if (isNaN(cp) || isNaN(sp) || cp === 0) return ''
  return marginType === 'percentage'
    ? (((sp - cp) / cp) * 100).toFixed(4)
    : (sp - cp).toFixed(4)
}

// Single cost detail card
function MarginField({ row, handle, toggleMarginType }) {
  return (
    <Field label={row.margin_type === 'percentage' ? 'Margin %' : 'Margin (Amt)'}>
      <div className="flex overflow-hidden rounded-md border-2 border-slate-200 bg-slate-50 focus-within:border-indigo-500 focus-within:bg-white focus-within:ring-2 focus-within:ring-indigo-500/15">
        <input
          type="number"
          value={row.margin}
          onChange={handle('margin')}
          step="0.01"
          placeholder="0.00"
          autoComplete="off"
          className="min-w-0 flex-1 bg-transparent px-2 py-1 text-xs text-slate-800 outline-none"
        />
        <div className="flex shrink-0 border-l border-slate-200">
          <button
            type="button"
            onClick={() => toggleMarginType('percentage')}
            title="Percentage margin"
            className={[
              'px-1.5 text-xs font-semibold transition-colors',
              row.margin_type === 'percentage'
                ? 'bg-indigo-600 text-white'
                : 'text-slate-500 hover:bg-slate-100',
            ].join(' ')}
          >%</button>
          <button
            type="button"
            onClick={() => toggleMarginType('amount')}
            title="Fixed amount margin"
            className={[
              'border-l border-slate-200 px-1.5 text-xs font-semibold transition-colors',
              row.margin_type === 'amount'
                ? 'bg-indigo-600 text-white'
                : 'text-slate-500 hover:bg-slate-100',
            ].join(' ')}
          >$</button>
        </div>
      </div>
    </Field>
  )
}

function CostDetailCard({ row, idx, salesChannels, allUnitTypes, usedChannelIds, onChange, onRemove, isSingle, rowErrors, rowTouched, onFieldBlur }) {
  const avgPrice = computeAverage(row.cost_price, row.selling_price)
  const re = rowErrors  ?? {}
  const rt = rowTouched ?? {}

  // Group unit types by category for <optgroup> rendering
  const uomGroups = allUnitTypes.reduce((acc, u) => {
    const key = String(u.unit_category_id)
    if (!acc[key]) acc[key] = { name: u.unit_category_name ?? 'Other', items: [] }
    acc[key].items.push(u)
    return acc
  }, {})

  const handle = (field) => (e) => {
    const value = e.target.value
    if (field === 'cost_price') {
      const sp = calcSellingPrice(value, row.margin, row.margin_type)
      onChange(idx, sp !== '' ? { cost_price: value, selling_price: sp } : { cost_price: value })
    } else if (field === 'margin') {
      const sp = calcSellingPrice(row.cost_price, value, row.margin_type)
      onChange(idx, sp !== '' ? { margin: value, selling_price: sp } : { margin: value })
    } else if (field === 'selling_price') {
      const m = calcMarginValue(row.cost_price, value, row.margin_type)
      onChange(idx, m !== '' ? { selling_price: value, margin: m } : { selling_price: value })
    } else if (field === 'unit_type_id') {
      const unit = allUnitTypes.find((u) => String(u.id) === value)
      onChange(idx, { unit_type_id: value, unit_category_id: unit ? String(unit.unit_category_id) : '' })
    } else {
      onChange(idx, field, value)
    }
  }

  const toggleMarginType = (newType) => {
    if (newType === row.margin_type) return
    const newMargin = calcMarginValue(row.cost_price, row.selling_price, newType)
    onChange(idx, { margin_type: newType, ...(newMargin !== '' ? { margin: newMargin } : {}) })
  }

  const channelSelect = (
    <Field label="Price List / Sale Channel" required error={re.sales_channel_id} touched={rt.sales_channel_id}>
      <CostSelect
        value={row.sales_channel_id}
        onChange={handle('sales_channel_id')}
        onBlur={() => onFieldBlur?.('sales_channel_id')}
        placeholder="Channel..."
        error={re.sales_channel_id}
        touched={rt.sales_channel_id}
      >
        {salesChannels.map((c) => (
          <option
            key={c.id}
            value={c.id}
            disabled={usedChannelIds.includes(String(c.id)) && row.sales_channel_id !== String(c.id)}
          >
            {c.name}
          </option>
        ))}
      </CostSelect>
    </Field>
  )

  const uomGroupedField = (
    <Field label="Unit of Measure">
      <CostSelect
        value={row.unit_type_id}
        onChange={handle('unit_type_id')}
        placeholder="Select UOM..."
      >
        {Object.entries(uomGroups).map(([catId, group]) => (
          <optgroup key={catId} label={group.name}>
            {group.items.map((u) => (
              <option key={u.id} value={u.id}>
                {u.symbol ? `${u.name} (${u.symbol})` : u.name}
              </option>
            ))}
          </optgroup>
        ))}
      </CostSelect>
    </Field>
  )

  const numUnitsField = (
    <Field label="Number Of Units" required error={re.num_of_units} touched={rt.num_of_units}>
      <CostInput
        value={row.num_of_units}
        onChange={handle('num_of_units')}
        onBlur={() => onFieldBlur?.('num_of_units')}
        min="0" step="0.0001" placeholder="0"
        error={re.num_of_units} touched={rt.num_of_units}
      />
    </Field>
  )

  const costPriceField = (
    <Field label="Cost Price" required error={re.cost_price} touched={rt.cost_price}>
      <CostInput
        value={row.cost_price}
        onChange={handle('cost_price')}
        onBlur={() => onFieldBlur?.('cost_price')}
        min="0" step="0.0001" placeholder="0.00"
        error={re.cost_price} touched={rt.cost_price}
      />
    </Field>
  )

  const sellingPriceField = (
    <Field label="Selling Price" required error={re.selling_price} touched={rt.selling_price}>
      <CostInput
        value={row.selling_price}
        onChange={handle('selling_price')}
        onBlur={() => onFieldBlur?.('selling_price')}
        min="0" step="0.0001" placeholder="0.00"
        error={re.selling_price} touched={rt.selling_price}
      />
    </Field>
  )

  const maxPriceField = (
    <Field label="Maximum Price">
      <CostInput value={row.max_price} onChange={handle('max_price')} min="0" step="0.0001" placeholder="0.00" />
    </Field>
  )

  const minPriceField = (
    <Field label="Minimum Price">
      <CostInput value={row.min_price} onChange={handle('min_price')} min="0" step="0.0001" placeholder="0.00" />
    </Field>
  )

  const wholesalePriceField = (
    <Field label="Wholesale Price">
      <CostInput value={row.wholesale_price} onChange={handle('wholesale_price')} min="0" step="0.0001" placeholder="0.00" />
    </Field>
  )

  const saleDiscField = (
    <Field label="Sale Disc %">
      <CostInput value={row.sale_privileges_discount} onChange={handle('sale_privileges_discount')} min="0" max="100" step="0.01" placeholder="0.00" />
    </Field>
  )

  const purchaseDiscField = (
    <Field label="Purchase Disc %">
      <CostInput value={row.purchasing_privileges_discount} onChange={handle('purchasing_privileges_discount')} min="0" max="100" step="0.01" placeholder="0.00" />
    </Field>
  )

  const avgPriceField = (
    <Field label="Average Price">
      <CostInput value={avgPrice} disabled placeholder="—" />
    </Field>
  )

  return (
    <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2.5">
      {/* Card header */}
      <div className="flex items-center justify-between">
        <span className="text-xs font-semibold text-slate-500 uppercase tracking-wider">
          Channel {idx + 1}
        </span>
        <button
          type="button"
          onClick={() => onRemove(idx)}
          className="rounded p-0.5 text-slate-400 hover:bg-red-100 hover:text-red-600 transition-colors"
          title="Remove this channel"
        >
          <X size={14} />
        </button>
      </div>

      {isSingle ? (
        <>
          {/* Single channel: 3 fields per row */}
          <div className="grid grid-cols-3 gap-2.5">
            {channelSelect}
            {uomGroupedField}
            {numUnitsField}
          </div>
          <div className="grid grid-cols-6 gap-2.5">
            {costPriceField}
            <MarginField row={row} handle={handle} toggleMarginType={toggleMarginType} />
            {sellingPriceField}
            {maxPriceField}
            {minPriceField}
            {wholesalePriceField}
          </div>
          <div className="grid grid-cols-3 gap-2.5">
            {saleDiscField}
            {purchaseDiscField}
            {avgPriceField}
          </div>
        </>
      ) : (
        <>
          {/* Multi-channel */}
          <div className="grid grid-cols-3 gap-2.5">
            {channelSelect}
            {uomGroupedField}
            {numUnitsField}
          </div>
          <div className="grid grid-cols-3 gap-2.5">
            {costPriceField}
            <MarginField row={row} handle={handle} toggleMarginType={toggleMarginType} />
            {sellingPriceField}
          </div>
          <div className="grid grid-cols-3 gap-2.5">
            {maxPriceField}
            {minPriceField}
            {wholesalePriceField}
          </div>
          <div className="grid grid-cols-3 gap-2.5">
            {saleDiscField}
            {purchaseDiscField}
            {avgPriceField}
          </div>
        </>
      )}
    </div>
  )
}

export default function ProductFormPage() {
  const { id }      = useParams()
  const isEditing   = Boolean(id)
  const navigate    = useNavigate()
  const queryClient = useQueryClient()

  const [form,       setForm]       = useState(() => ({
    ...EMPTY_FORM,
    cost_details: isEditing ? [] : [{ ...EMPTY_COST_ROW }],
  }))
  const [errors,          setErrors]          = useState({})
  const [touched,         setTouched]         = useState({})
  const [channelErrors,   setChannelErrors]   = useState(() => isEditing ? [] : [{}])
  const [channelTouched,  setChannelTouched]  = useState(() => isEditing ? [] : [{}])

  const { data: nextCode } = useQuery({
    queryKey: ['product-next-code'],
    queryFn:  getNextProductCode,
    enabled:  !isEditing,
  })

  const { data: categoriesData } = useQuery({
    queryKey: ['categories-all'],
    queryFn:  getAllCategories,
  })
  const { data: locationsData } = useQuery({
    queryKey: ['locations-all'],
    queryFn:  getAllLocations,
  })
  const { data: storesData } = useQuery({
    queryKey: ['stores-all'],
    queryFn:  getAllStores,
  })
  const { data: suppliersData } = useQuery({
    queryKey: ['suppliers-all'],
    queryFn:  getAllSuppliers,
  })
  const { data: channelsData } = useQuery({
    queryKey: ['sales-channels-all'],
    queryFn:  getAllSalesChannels,
  })
  const { data: allUnitTypesData } = useQuery({
    queryKey: ['unit-types-flat'],
    queryFn:  getAllUnitTypesFlat,
    staleTime: 5 * 60 * 1000,
  })
  const { data: attributeTypesData } = useQuery({
    queryKey: ['attribute-types-all'],
    queryFn:  getAllAttributeTypes,
  })
  const { data: allAttributesData } = useQuery({
    queryKey: ['attributes-all'],
    queryFn:  getAllAttributes,
  })

  const categories    = categoriesData     ?? []
  const locations     = locationsData      ?? []
  const allStores     = storesData         ?? []
  const suppliers     = suppliersData      ?? []
  const salesChannels  = channelsData      ?? []
  const defaultChannelId = salesChannels[0]?.id ?? null
  const allUnitTypes   = allUnitTypesData  ?? []
  const attributeTypes = attributeTypesData ?? []
  const allAttributes  = allAttributesData  ?? []

  // Unit types grouped by category, for the Stocking UOM <optgroup> list
  const unitGroups = allUnitTypes.reduce((acc, u) => {
    const key = String(u.unit_category_id)
    if (!acc[key]) acc[key] = { name: u.unit_category_name ?? 'Other', items: [] }
    acc[key].items.push(u)
    return acc
  }, {})

  const { isLoading: isFetching, isError: isFetchError, data: fetchedData } = useQuery({
    queryKey: ['product', id],
    queryFn:  () => getProduct(id),
    enabled:  isEditing,
    retry:    1,
  })

  const initialized = useRef(false)
  useLayoutEffect(() => {
    if (fetchedData?.data && !initialized.current) {
      const p = fetchedData.data
      setForm({
        product_code:              p.product_code             ?? '',
        reference_no:              p.reference_no             ?? '',
        ean_13:                    p.ean_13                   ?? '',
        name:                      p.name                     ?? '',
        display_name:              p.display_name             ?? '',
        product_type:              p.product_type             ?? '',
        description:               p.description              ?? '',
        category_id:               p.category_id  != null ? String(p.category_id)  : '',
        base_unit_type_id:         p.base_unit_type_id != null ? String(p.base_unit_type_id) : '',
        location_stores:           (p.location_stores ?? []).map((ls) => ({
          location_id: ls.location_id != null ? String(ls.location_id) : '',
          store_id:    ls.store_id    != null ? String(ls.store_id)    : '',
        })),
        reorder_level:             p.reorder_level            != null ? String(p.reorder_level) : '',
        reorder_qty:               p.reorder_qty              != null ? String(p.reorder_qty) : '',
        reorder_period:            p.reorder_period            != null ? String(p.reorder_period) : '',
        stock_releasing_method:    p.stock_releasing_method   ?? '',
        tracking_type:             p.tracking_type            ?? '',
        lock_purchase:              Boolean(p.lock_purchase),
        allow_complimentary_items:  Boolean(p.allow_complimentary_items),
        free_issue:                 Boolean(p.free_issue),
        allow_minus:                Boolean(p.allow_minus),
        not_allow_direct_sale:      Boolean(p.not_allow_direct_sale),
        non_returnable:             Boolean(p.non_returnable),
        is_empty:                   Boolean(p.is_empty),
        service_charge:             Boolean(p.service_charge),
        loyalty:                    Boolean(p.loyalty),
        is_batch:                   Boolean(p.is_batch),
        is_serial:                  Boolean(p.is_serial),
        supplier_ids:               (p.suppliers ?? []).map((s) => s.id),
        product_attributes:         Object.values(
          (p.product_attributes ?? []).reduce((acc, pa) => {
            const typeId = pa.attribute_type_id != null ? String(pa.attribute_type_id) : ''
            if (!acc[typeId]) acc[typeId] = { attribute_type_id: typeId, attribute_ids: [] }
            if (pa.attribute_id != null) acc[typeId].attribute_ids.push(String(pa.attribute_id))
            return acc
          }, {})
        ),
        cost_details:               (p.cost_details ?? []).map((c) => ({
          sales_channel_id:               String(c.sales_channel_id),
          unit_category_id:              c.unit_category_id != null ? String(c.unit_category_id) : '',
          unit_type_id:                   c.unit_type_id      != null ? String(c.unit_type_id)      : '',
          num_of_units:                   c.num_of_units                   != null ? String(c.num_of_units) : '',
          cost_price:                     c.cost_price                     != null ? String(c.cost_price) : '',
          margin:                         c.margin                         != null ? String(c.margin) : '',
          margin_type:                    c.margin_type                    ?? 'percentage',
          selling_price:                  c.selling_price                  != null ? String(c.selling_price) : '',
          max_price:                      c.max_price                      != null ? String(c.max_price) : '',
          min_price:                      c.min_price                      != null ? String(c.min_price) : '',
          wholesale_price:                c.wholesale_price                != null ? String(c.wholesale_price) : '',
          sale_privileges_discount:       c.sale_privileges_discount       != null ? String(c.sale_privileges_discount) : '',
          purchasing_privileges_discount: c.purchasing_privileges_discount != null ? String(c.purchasing_privileges_discount) : '',
        })),
      })
      setChannelErrors((p.cost_details ?? []).map(() => ({})))
      setChannelTouched((p.cost_details ?? []).map(() => ({})))
      initialized.current = true
    }
  }, [fetchedData])

  // Mirror the backend-generated preview code (read-only field, create mode only).
  // Re-syncs whenever nextCode changes value — not just once — so a stale
  // cached value from an earlier visit gets corrected once the background
  // refetch resolves, instead of being seeded once and left stale forever.
  const appliedCode = useRef(null)
  useLayoutEffect(() => {
    if (!isEditing && nextCode && appliedCode.current !== nextCode) {
      setForm((prev) => ({ ...prev, product_code: nextCode }))
      appliedCode.current = nextCode
    }
  }, [isEditing, nextCode])

  // Stock is denominated in the Stocking UOM, so changing it after stock exists
  // would silently reinterpret every balance. The backend rejects it too.
  const stockingUomLocked = Boolean(fetchedData?.data?.has_stock)

  // Seed the Stocking UOM from the reference unit of the price list's unit
  // category (set on the Unit Conversions page), so it rarely needs touching.
  const defaultUnitSeeded = useRef(false)
  useLayoutEffect(() => {
    if (isEditing || defaultUnitSeeded.current || allUnitTypes.length === 0) return

    const categoryId = form.cost_details.find((r) => r.unit_category_id)?.unit_category_id
    if (!categoryId) return

    const base = allUnitTypes.find(
      (u) => String(u.unit_category_id) === String(categoryId) && u.is_category_base
    )
    if (!base) return

    setForm((prev) => (prev.base_unit_type_id
      ? prev
      : { ...prev, base_unit_type_id: String(base.id) }))
    defaultUnitSeeded.current = true
  }, [isEditing, allUnitTypes, form.cost_details])

  // Pre-select the first created sales channel on the initial row (create mode only)
  const defaultChannelSeeded = useRef(false)
  useLayoutEffect(() => {
    if (!isEditing && defaultChannelId && !defaultChannelSeeded.current) {
      setForm((prev) => ({
        ...prev,
        cost_details: prev.cost_details.map((row, i) =>
          i === 0 && !row.sales_channel_id ? { ...row, sales_channel_id: String(defaultChannelId) } : row
        ),
      }))
      defaultChannelSeeded.current = true
    }
  }, [isEditing, defaultChannelId])

  const handleChange = (e) => {
    const { name, value } = e.target
    setForm((prev) => {
      const updates = { [name]: value }
      // Mirror product name → display name while they are still in sync
      if (name === 'name' && (prev.display_name === '' || prev.display_name === prev.name)) {
        updates.display_name = value
      }
      return { ...prev, ...updates, ...(name === 'category_id' ? { product_attributes: [] } : {}) }
    })
    if (touched[name]) {
      setErrors((prev) => ({ ...prev, [name]: validateField(name, value) }))
    }
    // Keep display_name error in sync when it was auto-filled
    if (name === 'name' && touched.display_name) {
      setErrors((prev) => ({ ...prev, display_name: validateField('display_name', value) }))
    }
  }

  const handleBlur = (e) => {
    const { name, value } = e.target
    setTouched((prev) => ({ ...prev, [name]: true }))
    setErrors((prev) => ({ ...prev, [name]: validateField(name, value) }))
  }

  const handleCheck = (key) => setForm((prev) => ({ ...prev, [key]: !prev[key] }))

  const addCostRow = () => {
    setForm((prev) => ({
      ...prev,
      cost_details: [
        ...prev.cost_details,
        { ...EMPTY_COST_ROW },
      ],
    }))
    setChannelErrors((prev)  => [...prev, {}])
    setChannelTouched((prev) => [...prev, {}])
  }

  const removeCostRow = (idx) => {
    setForm((prev)          => ({ ...prev, cost_details: prev.cost_details.filter((_, i) => i !== idx) }))
    setChannelErrors((prev)  => prev.filter((_, i) => i !== idx))
    setChannelTouched((prev) => prev.filter((_, i) => i !== idx))
  }

  const handleCostChange = (idx, fieldOrUpdates, value) => {
    setForm((prev) => {
      const newDetails = prev.cost_details.map((row, i) => {
        if (i !== idx) return row
        return typeof fieldOrUpdates === 'object'
          ? { ...row, ...fieldOrUpdates }
          : { ...row, [fieldOrUpdates]: value }
      })
      // Re-validate row inline if any field has been touched already
      const anyTouched = channelTouched[idx] && Object.values(channelTouched[idx]).some(Boolean)
      if (anyTouched) {
        setChannelErrors((errs) => {
          const next = [...errs]
          next[idx] = validateCostRow(newDetails[idx])
          return next
        })
      }
      return { ...prev, cost_details: newDetails }
    })
  }

  const handleCostFieldBlur = (idx, field) => {
    const row = form.cost_details[idx]
    setChannelTouched((prev) => {
      const next = [...prev]
      next[idx] = { ...(next[idx] ?? {}), [field]: true }
      return next
    })
    setChannelErrors((prev) => {
      const next = [...prev]
      next[idx] = validateCostRow(row)
      return next
    })
  }

  const addLocationStoreRow = () => {
    setForm((prev) => ({ ...prev, location_stores: [...prev.location_stores, { ...EMPTY_LOCATION_STORE_ROW }] }))
  }

  const removeLocationStoreRow = (idx) => {
    setForm((prev) => ({ ...prev, location_stores: prev.location_stores.filter((_, i) => i !== idx) }))
  }

  const handleLocationStoreChange = (idx, field, value) => {
    setForm((prev) => ({
      ...prev,
      location_stores: prev.location_stores.map((row, i) =>
        i === idx
          ? { ...row, [field]: value, ...(field === 'location_id' ? { store_id: '' } : {}) }
          : row
      ),
    }))
  }

  const addAttributeRow = () => {
    setForm((prev) => ({ ...prev, product_attributes: [...prev.product_attributes, { ...EMPTY_ATTRIBUTE_ROW }] }))
  }

  const removeAttributeRow = (idx) => {
    setForm((prev) => ({ ...prev, product_attributes: prev.product_attributes.filter((_, i) => i !== idx) }))
  }

  const handleAttributeTypeChange = (idx, value) => {
    setForm((prev) => ({
      ...prev,
      product_attributes: prev.product_attributes.map((row, i) =>
        i === idx ? { ...row, attribute_type_id: value, attribute_ids: [] } : row
      ),
    }))
  }

  const handleAttributeIdsChange = (idx, ids) => {
    setForm((prev) => ({
      ...prev,
      product_attributes: prev.product_attributes.map((row, i) =>
        i === idx ? { ...row, attribute_ids: ids } : row
      ),
    }))
  }

  const mutation = useMutation({
    mutationFn: (payload) =>
      isEditing ? updateProduct(id, payload) : createProduct(payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['products'] })
      if (isEditing) queryClient.invalidateQueries({ queryKey: ['product', id] })
      showSuccess(isEditing ? 'Product updated successfully.' : 'Product created successfully.')
      navigate('/inventory/products')
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
    const fieldErrors = {}
    const allTouched  = {}
    const validateFields = ['name', 'product_code', 'display_name', 'product_type', 'category_id', 'base_unit_type_id', 'supplier_ids', 'reference_no', 'ean_13', 'reorder_level', 'reorder_qty']
    validateFields.forEach((f) => {
      fieldErrors[f] = validateField(f, form[f])
      allTouched[f]  = true
    })

    // Channel validation
    if (form.cost_details.length === 0) {
      fieldErrors.cost_details = 'At least one sales channel is required.'
      allTouched.cost_details  = true
    }
    const newChannelErrors  = form.cost_details.map(validateCostRow)
    const newChannelTouched = form.cost_details.map(() => ({
      sales_channel_id: true, num_of_units: true, cost_price: true, selling_price: true,
    }))
    const hasChannelErrors = newChannelErrors.some((ce) => Object.values(ce).some(Boolean))

    setErrors(fieldErrors)
    setTouched(allTouched)
    setChannelErrors(newChannelErrors)
    setChannelTouched(newChannelTouched)

    if (Object.values(fieldErrors).some(Boolean) || hasChannelErrors) return

    const toNum = (v) => v !== '' ? Number(v) : null
    const payload = {
      product_code:           form.product_code.trim(),
      reference_no:           form.reference_no.trim() || null,
      ean_13:                 form.ean_13.trim() || null,
      name:                   form.name.trim(),
      display_name:           form.display_name.trim(),
      product_type:           form.product_type,
      description:            form.description.trim() || null,
      category_id:            form.category_id !== '' ? Number(form.category_id) : null,
      base_unit_type_id:      form.base_unit_type_id !== '' ? Number(form.base_unit_type_id) : null,
      location_stores:        form.location_stores
        .filter((r) => r.location_id || r.store_id)
        .map((r) => ({
          location_id: r.location_id !== '' ? Number(r.location_id) : null,
          store_id:    r.store_id    !== '' ? Number(r.store_id)    : null,
        })),
      reorder_level:          toNum(form.reorder_level),
      reorder_qty:            toNum(form.reorder_qty),
      reorder_period:         toNum(form.reorder_period),
      stock_releasing_method: form.stock_releasing_method || null,
      tracking_type:          form.tracking_type || null,
      supplier_ids:                form.supplier_ids,
      lock_purchase:               form.lock_purchase,
      allow_complimentary_items:   form.allow_complimentary_items,
      free_issue:                  form.free_issue,
      allow_minus:                 form.allow_minus,
      not_allow_direct_sale:       form.not_allow_direct_sale,
      non_returnable:              form.non_returnable,
      is_empty:                    form.is_empty,
      service_charge:              form.service_charge,
      loyalty:                     form.loyalty,
      is_batch:                    form.is_batch,
      is_serial:                   form.is_serial,
      product_attributes:          form.product_attributes
        .filter((r) => r.attribute_type_id && r.attribute_ids.length > 0)
        .flatMap((r) => r.attribute_ids.map((attributeId) => ({
          attribute_type_id: Number(r.attribute_type_id),
          attribute_id:      Number(attributeId),
        }))),
      cost_details:                form.cost_details
        .filter((r) => r.sales_channel_id)
        .map((r) => ({
          sales_channel_id:               Number(r.sales_channel_id),
          unit_type_id:                   r.unit_type_id !== '' ? Number(r.unit_type_id) : null,
          num_of_units:                   toNum(r.num_of_units),
          cost_price:                     toNum(r.cost_price),
          margin:                         toNum(r.margin),
          margin_type:                    r.margin_type || 'percentage',
          selling_price:                  toNum(r.selling_price),
          max_price:                      toNum(r.max_price),
          min_price:                      toNum(r.min_price),
          wholesale_price:                toNum(r.wholesale_price),
          sale_privileges_discount:       toNum(r.sale_privileges_discount),
          purchasing_privileges_discount: toNum(r.purchasing_privileges_discount),
        })),
    }
    mutation.mutate(payload)
  }

  const crumbs = [
    { label: 'Inventory', to: '/inventory/products' },
    { label: 'Products',  to: '/inventory/products' },
    { label: isEditing ? 'Edit Product' : 'New Product' },
  ]

  if (isEditing && isFetching) {
    return <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>
  }

  if (isEditing && isFetchError) {
    return (
      <div className="flex flex-col items-center justify-center py-16 gap-2">
        <p className="text-sm font-medium text-red-600">Failed to load product. Please try again.</p>
        <Link to="/inventory/products" className="text-xs text-indigo-600 hover:underline">
          Back to Products
        </Link>
      </div>
    )
  }

  const f = form
  const e = errors
  const t = touched
  const usedChannelIds   = f.cost_details.map((r) => r.sales_channel_id).filter(Boolean)
  const usedAttributeTypeIds = f.product_attributes.map((r) => r.attribute_type_id).filter(Boolean)
  const categoryAncestorIds = getCategoryAncestorIds(f.category_id, categories)
  const availableAttributeTypes = attributeTypes.filter(
    (t) => !f.category_id || categoryAncestorIds.includes(String(t.category_id))
  )
  const canAddAttributeRow = f.category_id && usedAttributeTypeIds.length < availableAttributeTypes.length

  return (
    <div className="w-full">
      <div className="mb-2 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">
            {isEditing ? 'Edit Product' : 'New Product'}
          </h1>
          <Breadcrumb crumbs={crumbs} />
        </div>
      </div>
      <form onSubmit={handleSubmit} noValidate className="space-y-2">

        {/* ── Top grid: Basic Info (wide) + Supplier & Stock (narrow) ── */}
        <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">

          {/* Basic Information — 2/3 width */}
          <section className="rounded-lg border border-slate-200 bg-white lg:col-span-2">
            <div className="flex items-center gap-1.5 border-b border-indigo-100 bg-indigo-50 px-3 py-2 text-indigo-700">
              <Package size={13} />
              <h2 className="text-xs font-bold">Basic Information</h2>
            </div>
            <div className="space-y-2 p-3">
              <div className="grid grid-cols-2 gap-2 xl:grid-cols-4">
                <Field label="Product Code" required error={e.product_code} touched={t.product_code}>
                  <Input name="product_code" value={f.product_code} readOnly
                    error={e.product_code} touched={t.product_code}
                    placeholder={isEditing ? '' : 'Generating…'}
                    maxLength={50}
                    className="block w-full cursor-not-allowed rounded-md border-2 border-slate-200 bg-slate-100 px-2 py-1 text-xs text-slate-500 outline-none" />
                  <p className="mt-0.5 text-[10px] text-slate-400">Auto-generated, sequential.</p>
                </Field>
                <Field label="Reference No" error={e.reference_no} touched={t.reference_no}>
                  <Input name="reference_no" value={f.reference_no} onChange={handleChange} onBlur={handleBlur}
                    error={e.reference_no} touched={t.reference_no} placeholder="Supplier / internal ref" maxLength={50} />
                </Field>
                <Field label="EAN / Barcode" error={e.ean_13} touched={t.ean_13}>
                  <Input name="ean_13" value={f.ean_13} onChange={handleChange} onBlur={handleBlur}
                    error={e.ean_13} touched={t.ean_13} placeholder="EAN-13 barcode" maxLength={50} />
                </Field>
                <Field label="Product Type" required error={e.product_type} touched={t.product_type}>
                  <SelectField name="product_type" value={f.product_type} onChange={handleChange} onBlur={handleBlur}
                    error={e.product_type} touched={t.product_type} options={PRODUCT_TYPES} placeholder="— Select type —" />
                </Field>
              </div>

              <div className="flex flex-wrap gap-2">
                <Field label="Product Name" required error={e.name} touched={t.name} className="shrink-0">
                  <AutoWidthInput name="name" value={f.name} onChange={handleChange} onBlur={handleBlur}
                    error={e.name} touched={t.name} placeholder="Full product name" maxLength={100} />
                </Field>
                <Field label="Display Name" required error={e.display_name} touched={t.display_name} className="shrink-0">
                  <AutoWidthInput name="display_name" value={f.display_name} onChange={handleChange} onBlur={handleBlur}
                    error={e.display_name} touched={t.display_name} placeholder="Short / POS name" maxLength={100} />
                </Field>
                <Field label="Category" required error={e.category_id} touched={t.category_id} className="min-w-[200px] flex-1 shrink-0">
                  <TreeSelect
                    name="category_id"
                    value={f.category_id}
                    onChange={handleChange}
                    items={categories}
                    parentField="parent_category_id"
                    labelField="category_name"
                    error={e.category_id}
                    touched={t.category_id}
                    placeholder="— Select category —"
                    emptyText="No categories available."
                  />
                </Field>
                <Field label="Stocking UOM" required error={e.base_unit_type_id} touched={t.base_unit_type_id} className="min-w-[160px] shrink-0">
                  <select
                    name="base_unit_type_id"
                    value={f.base_unit_type_id}
                    onChange={handleChange}
                    onBlur={handleBlur}
                    disabled={stockingUomLocked}
                    className={[
                      'block w-full rounded-md border-2 px-2 py-1 text-xs outline-none transition-all',
                      stockingUomLocked
                        ? 'cursor-not-allowed border-slate-200 bg-slate-100 text-slate-500'
                        : e.base_unit_type_id && t.base_unit_type_id
                          ? 'border-red-300 bg-red-50/40 text-slate-800 focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'
                          : 'border-slate-200 bg-slate-50 text-slate-800 focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15',
                    ].join(' ')}
                  >
                    <option value="">— Select unit —</option>
                    {Object.entries(unitGroups).map(([key, group]) => (
                      <optgroup key={key} label={group.name}>
                        {group.items.map((u) => (
                          <option key={u.id} value={String(u.id)}>{u.symbol ?? u.name}</option>
                        ))}
                      </optgroup>
                    ))}
                  </select>
                  <p className="mt-0.5 text-[10px] text-slate-400">
                    {stockingUomLocked
                      ? 'Locked — stock already recorded.'
                      : 'Stock is held in this unit. GRN & SO may use any unit of the same category.'}
                  </p>
                </Field>
              </div>

              <Field label="Description">
                <textarea name="description" value={f.description} onChange={handleChange} rows={2}
                  placeholder="Optional product description…"
                  className="block w-full resize-none rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15" />
              </Field>

              {/* ── Product Attributes + Location & Store side-by-side ── */}
              <div className="grid grid-cols-1 gap-2 lg:grid-cols-2">

                {/* Left: Product Attributes */}
                <div className="rounded border border-slate-100 bg-slate-50/50 p-2 space-y-1.5">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-semibold uppercase tracking-wider text-slate-500">Product Attributes</span>
                    <button
                      type="button"
                      onClick={addAttributeRow}
                      disabled={!canAddAttributeRow}
                      title={f.category_id ? 'Add attribute type' : 'Select a category first'}
                      className="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-40 transition-colors"
                    >
                      <Plus size={11} strokeWidth={2.5} />
                    </button>
                  </div>
                  {f.product_attributes.length === 0 ? (
                    <p className="text-[10px] italic text-slate-400">
                      {f.category_id ? 'No attributes. Click + to add.' : 'Select a category to add attributes.'}
                    </p>
                  ) : (
                    <div className="space-y-1.5">
                      {f.product_attributes.map((row, idx) => (
                        <AttributeTypeRow
                          key={idx}
                          row={row}
                          idx={idx}
                          attributeTypes={availableAttributeTypes}
                          allAttributes={allAttributes}
                          usedAttributeTypeIds={usedAttributeTypeIds}
                          onTypeChange={handleAttributeTypeChange}
                          onAttributesChange={handleAttributeIdsChange}
                          onRemove={removeAttributeRow}
                        />
                      ))}
                    </div>
                  )}
                </div>

                {/* Right: Location & Store */}
                <div className="rounded border border-slate-100 bg-slate-50/50 p-2 space-y-1.5">
                  <div className="flex items-center justify-between">
                    <span className="text-xs font-semibold uppercase tracking-wider text-slate-500">Location &amp; Store</span>
                    <button
                      type="button"
                      onClick={addLocationStoreRow}
                      className="flex h-5 w-5 items-center justify-center rounded-full bg-indigo-600 text-white shadow-sm hover:bg-indigo-700 transition-colors"
                    >
                      <Plus size={11} strokeWidth={2.5} />
                    </button>
                  </div>
                  {f.location_stores.length === 0 ? (
                    <p className="text-[10px] italic text-slate-400">No locations added. Click + to add.</p>
                  ) : (
                    <div className="space-y-1.5">
                      {f.location_stores.map((row, idx) => {
                        const rowStores = row.location_id
                          ? allStores.filter((s) => String(s.location_id) === String(row.location_id))
                          : allStores
                        return (
                          <div key={idx} className="grid grid-cols-2 gap-1.5 items-end">
                            <div>
                              {idx === 0 && <label className={LABEL_CLS}>Location</label>}
                              <TreeSelect
                                name={`location_stores_${idx}_location_id`}
                                value={row.location_id}
                                onChange={(e) => handleLocationStoreChange(idx, 'location_id', e.target.value)}
                                items={locations}
                                parentField="parent_location_id"
                                labelField="location_name"
                                placeholder="— Location —"
                                emptyText="No locations."
                              />
                            </div>
                            <div className="flex items-end gap-1">
                              <div className="flex-1">
                                {idx === 0 && <label className={LABEL_CLS}>Store</label>}
                                <select
                                  value={row.store_id}
                                  onChange={(e) => handleLocationStoreChange(idx, 'store_id', e.target.value)}
                                  className="block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15 cursor-pointer"
                                >
                                  <option value="">{row.location_id ? '— Store —' : '— Select location first —'}</option>
                                  {rowStores.map((s) => (
                                    <option key={s.id} value={String(s.id)}>{s.store_name}</option>
                                  ))}
                                </select>
                              </div>
                              <button
                                type="button"
                                onClick={() => removeLocationStoreRow(idx)}
                                className={['shrink-0 rounded p-0.5 text-slate-400 hover:bg-red-100 hover:text-red-600 transition-colors', idx === 0 ? '' : ''].join(' ')}
                              >
                                <Trash2 size={13} />
                              </button>
                            </div>
                          </div>
                        )
                      })}
                    </div>
                  )}
                </div>

              </div>
            </div>
          </section>

          {/* Right column: Supplier + Stock & Reorder — 1/3 width */}
          <div className="space-y-2">

            {/* Supplier */}
            <section className="overflow-visible rounded-lg border border-slate-200 bg-white">
              <div className="flex items-center gap-1.5 border-b border-sky-100 bg-sky-50 px-3 py-2 text-sky-700">
                <Tag size={13} />
                <h2 className="text-xs font-bold">Supplier <span className="text-red-500">*</span></h2>
              </div>
              <div className="p-2.5">
                <MultiSelectDropdown
                  items={suppliers}
                  getLabel={(s) => s.name}
                  placeholder="Select suppliers..."
                  searchPlaceholder="Search suppliers..."
                  selectedIds={f.supplier_ids}
                  onChange={(ids) => {
                    setForm((prev) => ({ ...prev, supplier_ids: ids }))
                    setTouched((prev) => ({ ...prev, supplier_ids: true }))
                  }}
                  error={e.supplier_ids}
                  touched={t.supplier_ids}
                />
                {e.supplier_ids && t.supplier_ids && (
                  <p className={`mt-1 ${ERR_CLS}`}>{e.supplier_ids}</p>
                )}
              </div>
            </section>

            {/* Stock & Reorder Settings */}
            <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
              <div className="flex items-center gap-1.5 border-b border-emerald-100 bg-emerald-50 px-3 py-2 text-emerald-700">
                <Settings size={13} />
                <h2 className="text-xs font-bold">Stock &amp; Reorder</h2>
              </div>
              <div className="space-y-2 p-3">
                <div className="grid grid-cols-2 gap-2">
                  <Field label="Reorder Level" error={e.reorder_level} touched={t.reorder_level}>
                    <Input name="reorder_level" value={f.reorder_level} onChange={handleChange} onBlur={handleBlur}
                      error={e.reorder_level} touched={t.reorder_level} type="number" min="0" step="0.0001" placeholder="0.0000" />
                  </Field>
                  <Field label="Reorder Qty" error={e.reorder_qty} touched={t.reorder_qty}>
                    <Input name="reorder_qty" value={f.reorder_qty} onChange={handleChange} onBlur={handleBlur}
                      error={e.reorder_qty} touched={t.reorder_qty} type="number" min="0" step="0.0001" placeholder="0.0000" />
                  </Field>
                </div>
                <div className="grid grid-cols-1 gap-2">
                  <Field label="Reorder Period (Days)">
                    <Input name="reorder_period" value={f.reorder_period} onChange={handleChange} onBlur={handleBlur}
                      type="number" min="1" step="1" placeholder="e.g. 30" />
                  </Field>
                  <Field label="Stock Method">
                    <SelectField name="stock_releasing_method" value={f.stock_releasing_method} onChange={handleChange} onBlur={handleBlur}
                      options={STOCK_METHODS} placeholder="— Select —" />
                  </Field>

                </div>
              </div>
            </section>

          </div>
        </div>

        {/* ── Channels — full width ── */}
        <section className={`overflow-hidden rounded-lg border bg-white ${e.cost_details && t.cost_details ? 'border-red-300' : 'border-slate-200'}`}>
          <div className="flex items-center justify-between border-b border-amber-100 bg-amber-50 px-3 py-2 text-amber-700">
            <div className="flex items-center gap-1.5">
              <ShoppingCart size={13} />
              <h2 className="text-xs font-bold">
                Channels <span className="text-red-500">*</span>
              </h2>
            </div>
            <button
              type="button"
              onClick={addCostRow}
              disabled={salesChannels.length === 0 || f.cost_details.length >= salesChannels.length}
              title="Add channel"
              className="flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50 transition-colors"
            >
              <Plus size={13} strokeWidth={2.5} />
            </button>
          </div>
          <div className="p-3">
            {e.cost_details && t.cost_details && (
              <p className="mb-2 text-xs font-medium text-red-600">{e.cost_details}</p>
            )}
            {f.cost_details.length === 0 ? (
              <p className="text-xs italic text-slate-400">
                No channels added. Click <strong>+</strong> to configure pricing per sales channel.
              </p>
            ) : (
              <div className={f.cost_details.length === 1 ? '' : 'grid grid-cols-1 gap-2.5 xl:grid-cols-2'}>
                {f.cost_details.map((row, idx) => (
                  <CostDetailCard
                    key={idx}
                    row={row}
                    idx={idx}
                    salesChannels={salesChannels}
                    allUnitTypes={allUnitTypes}
                    usedChannelIds={usedChannelIds}
                    onChange={handleCostChange}
                    onRemove={removeCostRow}
                    isSingle={f.cost_details.length === 1}
                    rowErrors={channelErrors[idx]  ?? {}}
                    rowTouched={channelTouched[idx] ?? {}}
                    onFieldBlur={(field) => handleCostFieldBlur(idx, field)}
                  />
                ))}
              </div>
            )}
          </div>
        </section>

        {/* ── Product Options — full width ── */}
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white">
          <div className="flex items-center gap-1.5 border-b border-violet-100 bg-violet-50 px-3 py-2 text-violet-700">
            <BarChart2 size={13} />
            <h2 className="text-xs font-bold">Product Options</h2>
          </div>
          <div className="grid grid-cols-3 gap-x-4 gap-y-2 p-3 md:grid-cols-6 lg:grid-cols-11">
            {BOOL_OPTIONS.map(({ key, label }) => (
              <label key={key} className="flex cursor-pointer items-center gap-1.5">
                <input type="checkbox" checked={f[key]} onChange={() => handleCheck(key)}
                  className="h-3.5 w-3.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 focus:ring-offset-0" />
                <span className="text-xs text-slate-700">{label}</span>
              </label>
            ))}
          </div>
        </section>

        {/* ── Footer ── */}
        <div className="mt-2 flex items-center justify-end gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5">
          {mutation.isError && !Object.keys(mutation.error?.response?.data?.errors ?? {}).length && (
            <p className={`mr-auto ${ERR_CLS}`}>
              {mutation.error?.response?.data?.message ?? 'An unexpected error occurred.'}
            </p>
          )}
          <Link to="/inventory/products"
            className="rounded px-3 py-1 text-xs font-medium text-slate-600 transition-colors hover:bg-slate-100">
            Cancel
          </Link>
          <button type="submit" disabled={mutation.isPending}
            className="flex items-center gap-1.5 rounded bg-indigo-600 px-3 py-1 text-xs font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-60">
            <Save size={12} strokeWidth={2.5} />
            {mutation.isPending ? 'Saving…' : isEditing ? 'Save Changes' : 'Create Product'}
          </button>
        </div>

      </form>
    </div>
  )
}
