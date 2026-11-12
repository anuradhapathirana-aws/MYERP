import { Link } from 'react-router-dom'
import { ArrowDownRight, ArrowRight, ArrowUpRight, Inbox } from 'lucide-react'
import Money from '../ui/Money'
import { CURRENCY, fmtMoney } from '../../utils/currency'
import { SERIES } from './dashboardTheme'
import { InfoTip } from './CalcNote'

// ── Shared look of every dashboard widget ───────────────────────────────────

const TONES = {
  indigo:  'bg-indigo-50 text-indigo-600 ring-indigo-100',
  emerald: 'bg-emerald-50 text-emerald-600 ring-emerald-100',
  sky:     'bg-sky-50 text-sky-600 ring-sky-100',
  amber:   'bg-amber-50 text-amber-600 ring-amber-100',
  rose:    'bg-rose-50 text-rose-600 ring-rose-100',
  violet:  'bg-violet-50 text-violet-600 ring-violet-100',
}

/** Change against the comparison period, as a coloured pill. Hidden when there is nothing to compare. */
export function Delta({ current, previous, label }) {
  const prev = Number(previous ?? 0)
  const cur  = Number(current ?? 0)
  if (!prev) return <span className="text-[10px] text-slate-400">No figures for {label}</span>

  const pct  = ((cur - prev) / Math.abs(prev)) * 100
  const up   = pct >= 0
  const Icon = up ? ArrowUpRight : ArrowDownRight

  return (
    <span className="inline-flex items-center gap-1 text-[10px] text-slate-500">
      <span className={`inline-flex items-center rounded-full px-1.5 py-px font-semibold ${up ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
        <Icon size={11} />
        {Math.abs(pct).toFixed(1)}%
      </span>
      vs {label}
    </span>
  )
}

/** One headline number. Hovering its ⓘ shows `note`: how the figure is worked out. */
export function KpiCard({ icon: Icon, tone = 'indigo', label, value, plain = false, footer, to, loading, error, onRetry, note }) {
  // A section that failed to load must never read as a real 0.00.
  if (error) {
    return (
      <div className="flex h-full items-start gap-2.5 rounded-xl border border-rose-200 bg-white px-3 py-2.5 shadow-sm">
        <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ring-1 ${TONES[tone]}`}>
          <Icon size={18} />
        </span>
        <div className="min-w-0 flex-1">
          <p className="truncate text-[10px] font-semibold uppercase tracking-wider text-slate-500">{label}</p>
          <p className="text-lg font-bold leading-tight text-slate-300">—</p>
          <p className="text-[10px] text-rose-600">
            Could not load.{' '}
            {onRetry && <button type="button" onClick={onRetry} className="font-semibold underline">Try again</button>}
          </p>
        </div>
      </div>
    )
  }

  const body = (
    <div className="group relative flex h-full items-start gap-2.5 rounded-xl border border-slate-200 bg-white px-3 py-2.5 shadow-sm transition hover:border-indigo-200 hover:shadow-md">
      <span className={`flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ring-1 ${TONES[tone]}`}>
        <Icon size={18} />
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-[10px] font-semibold uppercase tracking-wider text-slate-500">{label}</p>
        {loading ? (
          <div className="mt-1 h-5 w-24 animate-pulse rounded bg-slate-100" />
        ) : (
          <p className="truncate text-lg font-bold leading-tight text-slate-900">
            {plain ? value : <Money value={value} className="" />}
          </p>
        )}
        <div className="mt-0.5 min-h-[16px] truncate">{!loading && footer}</div>
      </div>
    </div>
  )

  const card = to ? <Link to={to} className="block h-full">{body}</Link> : body

  // The ⓘ sits on top of the card but outside its link: hovering it shows the
  // note, and clicking it does not navigate away.
  return (
    <div className="relative h-full">
      {card}
      {note && !loading && (
        <span className="absolute right-1.5 top-1.5 rounded p-0.5">
          <InfoTip note={note} size={14} />
        </span>
      )}
    </div>
  )
}

/** Card shell every chart and list sits in. */
export function Panel({ title, subtitle, icon: Icon, to, linkLabel = 'View report', action, note, className = '', children }) {
  return (
    <section className={`flex min-w-0 flex-col rounded-xl border border-slate-200 bg-white shadow-sm ${className}`}>
      <header className="flex items-start justify-between gap-2 border-b border-slate-100 px-3 py-2">
        <div className="min-w-0">
          <h2 className="flex items-center gap-1.5 text-sm font-semibold text-slate-800">
            {Icon && <Icon size={15} className="text-indigo-500" />}
            {title}
            {note && <InfoTip note={note} />}
          </h2>
          {subtitle && <p className="truncate text-[11px] text-slate-400">{subtitle}</p>}
        </div>
        {action}
        {to && (
          <Link to={to} className="inline-flex shrink-0 items-center gap-0.5 rounded-md px-1.5 py-0.5 text-[11px] font-medium text-indigo-600 hover:bg-indigo-50">
            {linkLabel} <ArrowRight size={12} />
          </Link>
        )}
      </header>
      <div className="flex flex-1 flex-col px-3 py-2">{children}</div>
    </section>
  )
}

export function PanelSkeleton({ height = 220 }) {
  return (
    <div className="flex animate-pulse flex-col gap-2" style={{ height }}>
      <div className="h-3 w-1/3 rounded bg-slate-100" />
      <div className="flex-1 rounded-lg bg-slate-50" />
    </div>
  )
}

export function EmptyState({ text = 'Nothing to show for this period', height = 160 }) {
  return (
    <div className="flex flex-col items-center justify-center gap-1 text-slate-400" style={{ height }}>
      <Inbox size={22} className="text-slate-300" />
      <p className="text-xs">{text}</p>
    </div>
  )
}

export function ErrorState({ onRetry }) {
  return (
    <div className="flex h-32 flex-col items-center justify-center gap-2 text-xs text-rose-600">
      Could not load this section.
      <button type="button" onClick={onRetry} className="rounded-md border border-rose-200 px-2 py-0.5 font-medium hover:bg-rose-50">
        Try again
      </button>
    </div>
  )
}

/** Recharts tooltip in the app's style; money values always written in full. */
export function ChartTooltip({ active, payload, label }) {
  if (!active || !payload?.length) return null

  return (
    <div className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs shadow-lg">
      {label && <p className="mb-1 font-semibold text-slate-700">{label}</p>}
      {payload.map((p) => (
        <p key={p.dataKey ?? p.name} className="flex items-center gap-1.5 text-slate-600">
          <span className="h-2 w-2 rounded-full" style={{ background: p.color ?? p.payload?.fill }} />
          <span>{p.name}:</span>
          <span className="font-semibold tabular-nums text-slate-800">{fmtMoney(p.value)}</span>
          <span className="text-[9px] text-slate-400">{CURRENCY}</span>
        </p>
      ))}
    </div>
  )
}

/** A ranked list drawn as bars — the label always readable, the amount in full. */
export function BarList({ rows, color = SERIES[0], valueKey = 'amount', note }) {
  if (!rows?.length) return <EmptyState />
  const max = Math.max(...rows.map((r) => Number(r[valueKey]) || 0), 1)

  return (
    <ul className="flex flex-col gap-1.5">
      {rows.map((r, i) => (
        <li key={r.id ?? i} title={`${r.name}: ${fmtMoney(r[valueKey])} ${CURRENCY}`}>
          <div className="flex items-baseline justify-between gap-2 text-xs">
            <span className="flex min-w-0 items-center gap-1.5">
              <span className="w-3.5 shrink-0 text-[10px] font-semibold text-slate-400">{i + 1}</span>
              <span className="truncate font-medium text-slate-700">{r.name}</span>
            </span>
            <Money value={r[valueKey]} className="shrink-0 font-semibold text-slate-800" />
          </div>
          <div className="ml-5 mt-0.5 h-1.5 rounded-full bg-slate-100">
            <div className="h-1.5 rounded-full" style={{ width: `${Math.max(2, (Number(r[valueKey]) / max) * 100)}%`, background: color }} />
          </div>
          {note && <p className="ml-5 text-[10px] text-slate-400">{note(r)}</p>}
        </li>
      ))}
    </ul>
  )
}
