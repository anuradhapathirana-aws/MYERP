import { useEffect, useMemo, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Zap } from 'lucide-react'
import { getAllUnitCategories } from '../../api/unitCategories'
import { getUnitConversionsByCategory, saveUnitConversionRates } from '../../api/unitConversions'
import { computeStandardRates, isKnownUnit } from '../../utils/unitConversionStandards'
import Breadcrumb from '../../components/Breadcrumb'
import { usePermissions } from '../../hooks/usePermissions'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/unit-categories' },
  { label: 'Unit Conversions' },
]

export default function UnitConversionsPage() {
  const [selectedCategoryId, setSelectedCategoryId] = useState('')
  const [baseUnitId, setBaseUnitId]   = useState(null)
  const [rates, setRates]             = useState({})
  const [autoIds, setAutoIds]         = useState(new Set())
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data: categoriesData } = useQuery({
    queryKey: ['unit-categories-all'],
    queryFn: getAllUnitCategories,
  })

  const { data: conversionData, isLoading } = useQuery({
    queryKey: ['unit-conversions-by-category', selectedCategoryId],
    queryFn: () => getUnitConversionsByCategory(selectedCategoryId),
    enabled: !!selectedCategoryId,
  })

  // Load saved data from server — never mark these as auto-filled
  useEffect(() => {
    if (!conversionData?.data) return
    const { base_unit_id, units } = conversionData.data
    setBaseUnitId(base_unit_id)
    const rateMap = {}
    units.forEach((u) => {
      if (u.rate !== null && u.rate !== undefined) {
        rateMap[u.id] = String(u.rate)
      }
    })
    setRates(rateMap)
    setAutoIds(new Set())
  }, [conversionData])

  const saveMutation = useMutation({
    mutationFn: saveUnitConversionRates,
    onSuccess: () =>
      queryClient.invalidateQueries({ queryKey: ['unit-conversions-by-category', selectedCategoryId] }),
  })

  const categories = categoriesData?.data ?? []
  const units      = conversionData?.data?.units ?? []

  // Whether the selected base unit has any world-standard rates we can apply
  const standardsAvailable = useMemo(() => {
    if (!baseUnitId) return false
    const base = units.find((u) => u.id === baseUnitId)
    return base ? isKnownUnit(base.symbol) : false
  }, [baseUnitId, units])

  const applyStandards = (newBaseId = baseUnitId) => {
    const base  = units.find((u) => u.id === newBaseId)
    const others = units.filter((u) => u.id !== newBaseId)
    if (!base) return

    const computed = computeStandardRates(base, others)
    if (Object.keys(computed).length === 0) return

    setRates((prev) => {
      const next = { ...prev }
      delete next[newBaseId]
      Object.entries(computed).forEach(([id, rate]) => { next[id] = rate })
      return next
    })
    setAutoIds(new Set(Object.keys(computed)))
  }

  const handleCategoryChange = (e) => {
    setSelectedCategoryId(e.target.value)
    setBaseUnitId(null)
    setRates({})
    setAutoIds(new Set())
    saveMutation.reset()
  }

  const handleBaseUnitChange = (unitId) => {
    setBaseUnitId(unitId)
    setRates((prev) => {
      const next = { ...prev }
      delete next[unitId]
      return next
    })
    setAutoIds(new Set())
    saveMutation.reset()

    // Auto-fill from world standards
    const base   = units.find((u) => u.id === unitId)
    const others = units.filter((u) => u.id !== unitId)
    if (!base) return
    const computed = computeStandardRates(base, others)
    if (Object.keys(computed).length > 0) {
      setRates((prev) => {
        const next = { ...prev }
        delete next[unitId]
        Object.entries(computed).forEach(([id, rate]) => { next[id] = rate })
        return next
      })
      setAutoIds(new Set(Object.keys(computed)))
    }
  }

  const handleRateChange = (unitId, value) => {
    setRates((prev) => ({ ...prev, [unitId]: value }))
    // User manually edited — remove auto badge for this unit
    setAutoIds((prev) => {
      const next = new Set(prev)
      next.delete(String(unitId))
      return next
    })
  }

  const handleClear = () => {
    setBaseUnitId(null)
    setRates({})
    setAutoIds(new Set())
    saveMutation.reset()
  }

  const handleSave = () => {
    if (!selectedCategoryId || !baseUnitId) return
    const ratePayload = units
      .filter((u) => u.id !== baseUnitId)
      .map((u) => ({ unit_type_id: u.id, rate: parseFloat(rates[u.id] || '0') }))
      .filter((r) => r.rate > 0)

    saveMutation.mutate({
      category_id:        parseInt(selectedCategoryId, 10),
      base_unit_type_id:  baseUnitId,
      rates:              ratePayload,
    })
  }

  return (
    <div>
      <div className="flex items-start justify-between mb-3">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Unit Conversions</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
      </div>
      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {/* Category selector */}
        <div className="flex items-center gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3">
          <label className="text-sm font-medium text-slate-600 whitespace-nowrap">
            Select Unit Category:
          </label>
          <select
            value={selectedCategoryId}
            onChange={handleCategoryChange}
            className="min-w-55 rounded-lg border-2 border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-700 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15 cursor-pointer"
          >
            <option value="">— Select —</option>
            {categories.map((cat) => (
              <option key={cat.id} value={cat.id}>{cat.name}</option>
            ))}
          </select>
        </div>

        {/* Empty / loading states */}
        {!selectedCategoryId && (
          <div className="flex items-center justify-center py-16 text-sm text-slate-400">
            Select a unit category to view and set conversion rates.
          </div>
        )}

        {selectedCategoryId && isLoading && (
          <div className="flex items-center justify-center py-16 text-sm text-slate-400">Loading…</div>
        )}

        {selectedCategoryId && !isLoading && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-32 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                      Created Date
                    </th>
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                      Unit Type Name
                    </th>
                    <th className="w-20 px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                      Unit
                    </th>
                    <th className="w-28 px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider text-slate-500">
                      Base Unit
                    </th>
                    <th className="px-4 py-3 text-xs font-semibold uppercase tracking-wider text-slate-500">
                      <span>Rate </span>
                      <span className="font-normal normal-case text-slate-400">(1 base = X this unit)</span>
                    </th>
                  </tr>
                </thead>

                <tbody className="divide-y divide-slate-100">
                  {units.length === 0 ? (
                    <tr>
                      <td colSpan={5} className="px-4 py-14 text-center text-sm text-slate-400">
                        No unit types found for this category.
                      </td>
                    </tr>
                  ) : (
                    units.map((unit) => {
                      const isBase    = unit.id === baseUnitId
                      const isAutoFilled = autoIds.has(String(unit.id))

                      return (
                        <tr
                          key={unit.id}
                          className={[
                            'transition-colors hover:bg-slate-50',
                            isBase ? 'bg-indigo-50/40' : '',
                          ].join(' ')}
                        >
                          <td className="px-4 py-2 text-xs text-slate-400">{unit.created_at}</td>
                          <td className="px-4 py-2 font-medium text-slate-800">{unit.name}</td>
                          <td className="px-4 py-2 font-mono text-slate-500">{unit.symbol}</td>

                          {/* Base Unit radio */}
                          <td className="px-4 py-2 text-center">
                            <input
                              type="radio"
                              name="base_unit"
                              checked={isBase}
                              onChange={() => handleBaseUnitChange(unit.id)}
                              className="h-4 w-4 cursor-pointer accent-indigo-600"
                            />
                          </td>

                          {/* Rate input */}
                          <td className="px-4 py-2">
                            {isBase ? (
                              <span className="text-xs italic text-slate-400">Base (reference = 1)</span>
                            ) : (
                              <div className="flex items-center gap-2">
                                <input
                                  type="number"
                                  min="0"
                                  step="any"
                                  value={rates[unit.id] ?? ''}
                                  onChange={(e) => handleRateChange(unit.id, e.target.value)}
                                  onKeyDown={(e) => e.key === 'Enter' && handleSave()}
                                  placeholder="Enter rate"
                                  disabled={!baseUnitId}
                                  className="w-40 rounded-md border-2 border-slate-200 bg-slate-50 px-2.5 py-1 text-sm text-slate-700 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15 disabled:border-slate-100 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed"
                                />
                                {isAutoFilled && (
                                  <span className="flex items-center gap-0.5 rounded bg-indigo-50 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-500 ring-1 ring-indigo-200">
                                    <Zap size={9} strokeWidth={2.5} />
                                    auto
                                  </span>
                                )}
                              </div>
                            )}
                          </td>
                        </tr>
                      )
                    })
                  )}
                </tbody>
              </table>
            </div>

            {/* Footer */}
            <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3">
              <div className="flex items-center gap-3">
                {autoIds.size > 0 && (
                  <p className="flex items-center gap-1 text-xs text-indigo-500">
                    <Zap size={11} strokeWidth={2.5} />
                    Rates auto-filled from world standards — review before saving.
                  </p>
                )}
                {saveMutation.isSuccess && !autoIds.size && (
                  <p className="text-xs font-medium text-green-600">Rates saved successfully.</p>
                )}
                {saveMutation.isError && (
                  <p className="text-xs font-medium text-red-500">Failed to save. Please try again.</p>
                )}
              </div>

              <div className="flex items-center gap-2">
                {standardsAvailable && can('edit_unit_conversions') && (
                  <button
                    type="button"
                    onClick={() => applyStandards()}
                    className="flex items-center gap-1.5 rounded-md border border-indigo-300 bg-indigo-50 px-3 py-1.5 text-xs font-semibold text-indigo-600 transition-colors hover:bg-indigo-100"
                  >
                    <Zap size={12} strokeWidth={2.5} />
                    Reapply Standards
                  </button>
                )}
                {can('edit_unit_conversions') && (
                  <>
                    <button
                      type="button"
                      onClick={handleClear}
                      className="rounded-md bg-red-500 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-red-600"
                    >
                      CLEAR
                    </button>
                    <button
                      type="button"
                      onClick={handleSave}
                      disabled={!baseUnitId || saveMutation.isPending}
                      className="rounded-md bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                      {saveMutation.isPending ? 'Saving…' : 'Set Rates'}
                    </button>
                  </>
                )}
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  )
}
