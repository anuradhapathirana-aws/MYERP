import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { QrCode } from 'lucide-react'
import { downloadPieceLabelsPdf, getGrnNos, getPieceLabels, getShippingCodes } from '../../api/pieceLabels'
import { getAllProducts } from '../../api/products'
import { getAllAttributeTypes } from '../../api/attributeTypes'
import { getAllAttributes } from '../../api/attributes'
import { getAllCategories } from '../../api/categories'
import Breadcrumb from '../../components/Breadcrumb'
import TableFilter, { FilterField } from '../../components/TableFilter'
import FilterSearchSelect from '../../components/ui/FilterSearchSelect'
import { PdfBtn, PrintBtn } from '../../components/ui/ActionButtons'
import { useTableFilter } from '../../hooks/useTableFilter'
import { printPdfBlob } from '../../utils/pdf'
import { showError } from '../../utils/alerts'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/goods-received-notes' },
  { label: 'Print Piece Labels' },
]

// Opens empty on purpose: generating hundreds of QR data-URIs is heavy, so nothing
// loads until the user applies at least one filter. Picking "Not printed yet" shows
// the work queue — printing stamps printed_at, so that list empties itself.
const INITIAL_FILTERS = {
  product_id: '',
  shipping_code: '',
  attribute_id: '',
  grn_no: '',
  print_status: '',
}

const PRINT_STATUS_OPTIONS = [
  { value: 'pending', label: 'Not printed yet' },
  { value: 'printed', label: 'Already printed' },
]

// "Color" or "Colour" — attribute-type spelling varies by who entered the master data.
function isColorAttributeTypeName(name) {
  const n = (name || '').trim().toLowerCase()
  return n === 'color' || n === 'colour'
}

// Walk parent_category_id up to the root, returning [selectedId, ...ancestorIds].
// An attribute-type attached to any ancestor category applies to a descendant
// product (mirrors the scoping used in ProductFormPage). Guards against cycles.
function getCategoryAncestorIds(categoryId, categories) {
  const byId = new Map(categories.map((c) => [String(c.id), c]))
  const ids = []
  const visited = new Set()
  let current = categoryId != null ? String(categoryId) : null
  while (current && byId.has(current) && !visited.has(current)) {
    visited.add(current)
    ids.push(current)
    const parentId = byId.get(current).parent_category_id
    current = parentId != null ? String(parentId) : null
  }
  return ids
}

const hasAnyFilter = (f) => Boolean(f.product_id || f.shipping_code || f.attribute_id || f.grn_no || f.print_status)

// Strip empty filters so the backend's required_without_all validation sees only real values
const cleanFilters = (f) =>
  Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v != null))

export default function PrintPieceLabelsPage() {
  const [exportBusy, setExportBusy] = useState(null) // 'print' | 'pdf'

  const { open, toggle, draft, setDraft, applied, apply, clear, activeCount } =
    useTableFilter(INITIAL_FILTERS)

  const { data: productsData } = useQuery({
    queryKey: ['products-all'],
    queryFn: getAllProducts,
    staleTime: Infinity,
  })
  const productOptions = (productsData ?? []).map((p) => ({
    value: p.id,
    label: p.product_code ? `[${p.product_code}] ${p.name}` : p.name,
    category_id: p.category_id,
  }))
  const selectedProduct = productOptions.find((p) => String(p.value) === String(draft.product_id))
  const selectedCategoryId = selectedProduct?.category_id ?? null

  const { data: shippingCodesData } = useQuery({
    queryKey: ['piece-label-shipping-codes'],
    queryFn: getShippingCodes,
    staleTime: 5 * 60 * 1000,
  })
  const shippingCodeOptions = (shippingCodesData ?? []).map((c) => ({ value: c, label: c }))

  const { data: grnNosData } = useQuery({
    queryKey: ['piece-label-grn-nos'],
    queryFn: getGrnNos,
    staleTime: 5 * 60 * 1000,
  })
  const grnNoOptions = (grnNosData ?? []).map((n) => ({ value: n, label: n }))

  const { data: attributeTypes = [] } = useQuery({ queryKey: ['attribute-types-all'], queryFn: getAllAttributeTypes })
  const { data: allAttributes = [] }  = useQuery({ queryKey: ['attributes-all'],      queryFn: getAllAttributes })
  const { data: categories = [] }     = useQuery({ queryKey: ['categories-all'],      queryFn: getAllCategories })

  // Category chain of the selected product (leaf → root). A colour attribute-type
  // attached to any of these categories is valid for the product.
  const selectedCategoryChain = selectedCategoryId != null
    ? getCategoryAncestorIds(selectedCategoryId, categories)
    : null

  // Colour attribute-types keyed by id. When a Product is selected we narrow to
  // the colour types on its category chain; otherwise every colour is offered,
  // grouped by its category tree (Category → Colour attribute-type → colour).
  const colorTypeById = new Map(
    attributeTypes
      .filter((t) => isColorAttributeTypeName(t.attribute_type_name))
      .filter((t) => selectedCategoryChain == null || selectedCategoryChain.includes(String(t.category_id)))
      .map((t) => [String(t.id), t])
  )
  const colorOptions = allAttributes
    .filter((a) => colorTypeById.has(String(a.attribute_type_id)))
    .map((a) => {
      const type = colorTypeById.get(String(a.attribute_type_id))
      return {
        value: a.id,
        label: a.attribute_name,
        group: type?.category_name || type?.attribute_type_name || 'Colours',
      }
    })
    .sort((x, y) => x.group.localeCompare(y.group) || x.label.localeCompare(y.label))

  // Does colour `attributeId` still belong to product `productId`'s category chain?
  // True when nothing is picked or no product narrows the list.
  const colorFitsProduct = (attributeId, productId) => {
    if (!attributeId) return true
    const prod = productOptions.find((p) => String(p.value) === String(productId))
    const catId = prod?.category_id ?? null
    if (catId == null) return true
    const attr = allAttributes.find((a) => String(a.id) === String(attributeId))
    if (!attr) return true
    const type = attributeTypes.find((t) => String(t.id) === String(attr.attribute_type_id))
    if (type == null || !isColorAttributeTypeName(type.attribute_type_name)) return false
    return getCategoryAncestorIds(catId, categories).includes(String(type.category_id))
  }

  const { data, isLoading, isError } = useQuery({
    queryKey: ['piece-labels', applied],
    queryFn: () => getPieceLabels(cleanFilters(applied)),
    enabled: hasAnyFilter(applied),
    placeholderData: (prev) => prev,
  })
  const labels = data?.labels ?? []

  const pendingCount = labels.filter((l) => !l.printed_at).length

  const handleApply = () => {
    if (!hasAnyFilter(draft)) {
      showError('Select at least one filter — Label Status, Product, GRN No, Shipping Code or Color.')
      return
    }
    apply()
  }

  const handleExport = async (action) => {
    setExportBusy(action)
    try {
      const blob = await downloadPieceLabelsPdf(cleanFilters(applied))
      if (action === 'print') {
        printPdfBlob(blob)
      } else {
        const url = URL.createObjectURL(blob)
        const a   = document.createElement('a')
        a.href     = url
        a.download = 'Piece_Labels.pdf'
        a.click()
        URL.revokeObjectURL(url)
      }
    } catch {
      showError(`Failed to ${action === 'print' ? 'print' : 'download'} the piece labels.`)
    } finally {
      setExportBusy(null)
    }
  }

  const exportsDisabled = !hasAnyFilter(applied) || labels.length === 0 || Boolean(exportBusy)

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <div className="flex items-center gap-2">
            <QrCode size={18} className="text-indigo-500" />
            <h1 className="text-xl font-bold leading-none text-slate-800">Print Piece Labels</h1>
          </div>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        <div className="flex items-center gap-1.5">
          <PrintBtn onClick={() => handleExport('print')} disabled={exportsDisabled} title="Print labels" />
          <PdfBtn onClick={() => handleExport('pdf')} disabled={exportsDisabled} title="Download PDF" />
        </div>
      </div>

      <TableFilter open={open} onToggle={toggle} onApply={handleApply} onClear={() => clear()} activeCount={activeCount}>
        <FilterField label="Label Status">
          <FilterSearchSelect
            value={draft.print_status}
            onChange={(val) => setDraft((d) => ({ ...d, print_status: val }))}
            options={PRINT_STATUS_OPTIONS}
            placeholder="All labels"
          />
        </FilterField>
        <FilterField label="Product">
          <FilterSearchSelect
            value={draft.product_id}
            onChange={(val) => setDraft((d) => ({ ...d, product_id: val, attribute_id: colorFitsProduct(d.attribute_id, val) ? d.attribute_id : '' }))}
            options={productOptions} wide
            placeholder="All products"
          />
        </FilterField>
        <FilterField label="GRN No">
          <FilterSearchSelect
            value={draft.grn_no}
            onChange={(val) => setDraft((d) => ({ ...d, grn_no: val }))}
            options={grnNoOptions}
            placeholder="All GRNs"
          />
        </FilterField>
        <FilterField label="Shipping Code">
          <FilterSearchSelect
            value={draft.shipping_code}
            onChange={(val) => setDraft((d) => ({ ...d, shipping_code: val }))}
            options={shippingCodeOptions}
            placeholder="All shipping codes"
          />
        </FilterField>
        <FilterField label="Color">
          <FilterSearchSelect
            value={draft.attribute_id}
            onChange={(val) => setDraft((d) => ({ ...d, attribute_id: val }))}
            options={colorOptions} wide
            placeholder="All colors"
          />
        </FilterField>
      </TableFilter>

      {!hasAnyFilter(applied) ? (
        <div className="mt-3 rounded-xl border border-slate-200 bg-white py-16 text-center text-sm text-slate-400 shadow-sm">
          Select a Label Status, Product, GRN No, Shipping Code or Color in the filters above to load the QR piece labels.
        </div>
      ) : (
        <div className="mt-3 rounded-xl border border-slate-200 bg-white shadow-sm">
          {isLoading && <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading labels…</div>}
          {isError && <div className="flex items-center justify-center py-14 text-sm text-red-500">Failed to load piece labels.</div>}

          {!isLoading && !isError && (
            labels.length === 0 ? (
              <div className="py-16 text-center text-sm text-slate-400">
                {applied.print_status === 'pending' ? (
                  <>
                    <span className="font-semibold text-emerald-600">Every roll label is up to date.</span>
                    <span className="mt-1 block">Cut a roll on a delivery order and its new label appears here.</span>
                  </>
                ) : (
                  'No sealed pieces found for the selected filters. Only confirmed GRNs have printable piece labels.'
                )}
              </div>
            ) : (
              <>
                <div className="flex items-center justify-between border-b border-slate-100 px-3 py-2">
                  <span className="text-xs font-semibold text-slate-600">
                    {labels.length} label{labels.length !== 1 ? 's' : ''} found
                    {applied.print_status !== 'pending' && pendingCount > 0 && (
                      <span className="ml-1 font-normal text-amber-600">· {pendingCount} still to print</span>
                    )}
                    {data?.truncated && <span className="ml-1 font-normal text-amber-600">(showing first {labels.length} — narrow your filters)</span>}
                  </span>
                </div>
                <div className="grid grid-cols-2 gap-2 p-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                  {labels.map((label) => (
                    <div key={label.id} className={`relative flex flex-col items-center rounded border border-dashed p-2 text-center ${label.replaces ? 'border-amber-400 bg-amber-50/40' : 'border-slate-300'}`}>
                      {label.replaces && (
                        <span className="absolute right-1 top-1 rounded bg-amber-500 px-1 py-px text-[9px] font-bold uppercase tracking-wider text-white">
                          Cut
                        </span>
                      )}
                      <img src={label.qr_data_uri} alt={label.piece_code} className="h-24 w-24" />
                      <div className="mt-1 font-mono text-[10px] font-bold text-slate-800">{label.piece_code}</div>
                      <div className="text-[10px] text-slate-500">
                        {label.color && <span className="mr-1">Color: {label.color}</span>}
                        {label.roll_no && <span>Roll: {label.roll_no}</span>}
                      </div>
                      <div className="text-[10px] text-slate-500">
                        {label.weight != null && (
                          <span className="mr-1">
                            Wt: {Number(label.weight).toFixed(2)}{label.uom ? ` ${label.uom}` : ''}
                          </span>
                        )}
                        {label.batch_no && <span>Batch: {label.batch_no}</span>}
                      </div>
                      <div className="line-clamp-1 text-[10px] text-slate-500" title={label.product_name}>{label.product_name}</div>
                      <div className="text-[10px] text-slate-400">{label.grn_no}{label.shipping_code ? ` · ${label.shipping_code}` : ''}</div>
                      {/* The roll still wears the old sticker — say which one to peel off. */}
                      {label.replaces && (
                        <div className="mt-0.5 font-mono text-[9px] font-bold leading-tight text-amber-600" title={`Peel off sticker ${label.replaces}`}>
                          ↳ Replaces {label.replaces}
                        </div>
                      )}
                    </div>
                  ))}
                </div>
              </>
            )
          )}
        </div>
      )}
    </div>
  )
}
