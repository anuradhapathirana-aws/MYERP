import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Pie, PieChart,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts'
import Money from '../ui/Money'
import { ChartTooltip, EmptyState } from './DashboardUi'
import { SERIES, fmtCompact } from './dashboardTheme'

const AXIS = { fontSize: 10, fill: '#64748b' }

/**
 * Net sales (and collections, when visible) over the last 12 months.
 * The chart grows to fill its card (never below `minHeight`), so a taller
 * neighbour in the same row never leaves a blank band under it.
 */
export function SalesTrendChart({ rows, showCollections, minHeight = 165 }) {
  if (!rows?.some((r) => r.sales || r.collections)) return <EmptyState text="No sales in the last 12 months" height={minHeight} />

  const total = rows.reduce((s, r) => s + (r.sales || 0), 0)
  const best  = rows.reduce((b, r) => ((r.sales || 0) > (b.sales || 0) ? r : b), rows[0])

  return (
    <div className="flex h-full flex-1 flex-col">
      <div className="mb-1 flex items-center gap-3 text-[11px] text-slate-600">
        <LegendDot color={SERIES[0]} label="Net sales" />
        {showCollections && <LegendDot color={SERIES[2]} label="Collections" />}
      </div>
      <div className="relative flex-1" style={{ minHeight }}>
        <div className="absolute inset-0">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={rows} margin={{ top: 4, right: 6, left: -8, bottom: 0 }}>
          <defs>
            <linearGradient id="dashSales" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={SERIES[0]} stopOpacity={0.28} />
              <stop offset="100%" stopColor={SERIES[0]} stopOpacity={0.02} />
            </linearGradient>
            <linearGradient id="dashCollections" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={SERIES[2]} stopOpacity={0.22} />
              <stop offset="100%" stopColor={SERIES[2]} stopOpacity={0.02} />
            </linearGradient>
          </defs>
          <CartesianGrid vertical={false} stroke="#f1f5f9" />
          <XAxis dataKey="label" tick={AXIS} tickLine={false} axisLine={{ stroke: '#e2e8f0' }} interval="preserveStartEnd" minTickGap={6} />
          <YAxis tick={AXIS} tickLine={false} axisLine={false} tickFormatter={fmtCompact} width={40} />
          <Tooltip content={<ChartTooltip />} cursor={{ stroke: '#cbd5e1', strokeDasharray: '3 3' }} />
          <Area type="monotone" dataKey="sales" name="Net sales" stroke={SERIES[0]} strokeWidth={2}
                fill="url(#dashSales)" dot={false} activeDot={{ r: 4, strokeWidth: 2, stroke: '#fff' }} />
          {showCollections && (
            <Area type="monotone" dataKey="collections" name="Collections" stroke={SERIES[2]} strokeWidth={2}
                  fill="url(#dashCollections)" dot={false} activeDot={{ r: 4, strokeWidth: 2, stroke: '#fff' }} />
          )}
        </AreaChart>
      </ResponsiveContainer>
        </div>
      </div>
      <div className="mt-1.5 grid grid-cols-3 gap-1.5">
        <TrendStat label="12-month net sales" value={total} />
        <TrendStat label="Monthly average" value={total / rows.length} />
        <TrendStat label={`Best month · ${best.label}`} value={best.sales} />
      </div>
    </div>
  )
}

function TrendStat({ label, value }) {
  return (
    <div className="min-w-0 rounded-md bg-slate-50 px-2 py-1">
      <p className="truncate text-[10px] text-slate-500">{label}</p>
      <Money value={value} className="block truncate text-[11px] font-semibold text-slate-800" />
    </div>
  )
}

function LegendDot({ color, label }) {
  return (
    <span className="inline-flex items-center gap-1">
      <span className="h-2 w-2 rounded-full" style={{ background: color }} />
      {label}
    </span>
  )
}

/**
 * A donut with the total in the middle and a labelled list beside it — the list
 * carries every name and amount, so nothing depends on telling colours apart.
 */
export function DonutWithList({ rows, colorOf, centerLabel, emptyText }) {
  const data  = (rows ?? []).filter((r) => Number(r.amount) > 0)
  const total = data.reduce((s, r) => s + Number(r.amount), 0)

  if (!data.length) return <EmptyState text={emptyText} height={170} />

  return (
    <div className="flex flex-col items-center gap-3 sm:flex-row">
      <div className="relative h-[150px] w-[150px] shrink-0">
        <ResponsiveContainer width="100%" height="100%">
          <PieChart>
            <Pie data={data} dataKey="amount" nameKey="label" innerRadius={48} outerRadius={70}
                 paddingAngle={data.length > 1 ? 2 : 0} stroke="#fff" strokeWidth={2} isAnimationActive>
              {data.map((r, i) => <Cell key={r.key ?? r.label} fill={colorOf(r, i)} />)}
            </Pie>
            <Tooltip content={<ChartTooltip />} />
          </PieChart>
        </ResponsiveContainer>
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
          <span className="text-[9px] font-semibold uppercase tracking-wider text-slate-400">{centerLabel}</span>
          <span className="text-sm font-bold text-slate-800">{fmtCompact(total)}</span>
        </div>
      </div>
      <ul className="w-full min-w-0 flex-1 space-y-1">
        {(rows ?? []).map((r, i) => {
          const pct = total ? (Number(r.amount) / total) * 100 : 0
          return (
            <li key={r.key ?? r.label} className="flex items-center justify-between gap-2 text-xs">
              <span className="flex min-w-0 items-center gap-1.5">
                <span className="h-2.5 w-2.5 shrink-0 rounded-sm" style={{ background: colorOf(r, i) }} />
                <span className="truncate text-slate-600">{r.label}</span>
              </span>
              <span className="flex shrink-0 items-baseline gap-1.5">
                <Money value={r.amount} className="font-semibold text-slate-800" />
                <span className="w-9 text-right text-[10px] text-slate-400">{pct.toFixed(0)}%</span>
              </span>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

/** Monthly purchases (confirmed GRNs) — one series, so no legend; the title names it. */
export function PurchaseBars({ rows }) {
  if (!rows?.some((r) => r.amount)) return <EmptyState text="No goods received in the last 6 months" height={130} />

  return (
    <ResponsiveContainer width="100%" height={130}>
      <BarChart data={rows} margin={{ top: 4, right: 4, left: -12, bottom: 0 }} barCategoryGap="28%">
        <CartesianGrid vertical={false} stroke="#f1f5f9" />
        <XAxis dataKey="label" tick={AXIS} tickLine={false} axisLine={{ stroke: '#e2e8f0' }} />
        <YAxis tick={AXIS} tickLine={false} axisLine={false} tickFormatter={fmtCompact} width={44} />
        <Tooltip content={<ChartTooltip />} cursor={{ fill: '#f8fafc' }} />
        <Bar dataKey="amount" name="Purchases" fill={SERIES[6]} radius={[4, 4, 0, 0]} maxBarSize={28} />
      </BarChart>
    </ResponsiveContainer>
  )
}

function CountTooltip({ active, payload }) {
  if (!active || !payload?.length) return null
  const p = payload[0]
  return (
    <div className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-xs shadow-lg">
      <span className="mr-1 inline-block h-2 w-2 rounded-full" style={{ background: p.payload.color }} />
      {p.name}: <span className="font-semibold text-slate-800">{p.value}</span>
    </div>
  )
}

/**
 * A small donut of counts by status — the total in the middle, every status
 * named with its count underneath, so nothing depends on telling colours apart.
 *
 * @param {{ rows: Array<{ key: string, label: string, count: number, color: string, value?: number }>, centerLabel: string, emptyText: string }} props
 */
export function MiniDonut({ rows, centerLabel, emptyText }) {
  const data  = rows.filter((r) => r.count > 0)
  const total = data.reduce((s, r) => s + r.count, 0)

  return (
    <div className="flex min-w-0 flex-col items-center">
      <div className="relative h-[112px] w-[112px]">
        {total > 0 ? (
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie data={data} dataKey="count" nameKey="label" innerRadius={36} outerRadius={54}
                   paddingAngle={data.length > 1 ? 2 : 0} stroke="#fff" strokeWidth={2}>
                {data.map((r) => <Cell key={r.key} fill={r.color} />)}
              </Pie>
              <Tooltip content={<CountTooltip />} />
            </PieChart>
          </ResponsiveContainer>
        ) : (
          <div className="h-full w-full rounded-full border-[14px] border-slate-100" />
        )}
        <div className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
          <span className="text-lg font-bold leading-none text-slate-800">{total}</span>
          <span className="text-[9px] font-semibold uppercase tracking-wider text-slate-400">{centerLabel}</span>
        </div>
      </div>
      {total === 0 && <p className="mt-0.5 text-[10px] text-slate-400">{emptyText}</p>}
      <ul className="mt-1 w-full space-y-0.5">
        {rows.map((r) => (
          <li key={r.key} className="flex items-center justify-between gap-1 text-[11px]"
              title={r.value !== undefined ? `${r.label}: ${r.count} · ${Number(r.value).toLocaleString(undefined, { minimumFractionDigits: 2 })}` : undefined}>
            <span className="flex min-w-0 items-center gap-1">
              <span className="h-2 w-2 shrink-0 rounded-sm" style={{ background: r.color }} />
              <span className="truncate text-slate-600">{r.label}</span>
            </span>
            <span className={`shrink-0 font-semibold tabular-nums ${r.count ? 'text-slate-800' : 'text-slate-300'}`}>{r.count}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}
