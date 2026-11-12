// Colours and number formatting shared by the dashboard widgets.

/** Chart palette: validated categorical order (blue, orange, aqua, yellow, magenta, green, violet, red). */
export const SERIES = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948']

/** Aging is a severity scale, so it reads green → red, always with its label beside it. */
export const AGING_COLORS = {
  current: '#16a34a',
  '1_30':  '#eab308',
  '31_60': '#f97316',
  '61_90': '#ef4444',
  over_90: '#991b1b',
  no_due:  '#94a3b8',   // grey: not late, just no due date to measure against
}

export const agingColor  = (r) => AGING_COLORS[r.key] ?? '#94a3b8'
export const seriesColor = (_r, i) => SERIES[i % SERIES.length]

/** "1.25M", "480K" — axis ticks and donut centres only; amounts a user reads in full use <Money />. */
export function fmtCompact(n) {
  const v = Number(n ?? 0)
  const abs = Math.abs(v)
  if (abs >= 1e9) return `${(v / 1e9).toFixed(1)}B`
  if (abs >= 1e6) return `${(v / 1e6).toFixed(abs >= 1e7 ? 0 : 1)}M`
  if (abs >= 1e3) return `${(v / 1e3).toFixed(abs >= 1e4 ? 0 : 1)}K`
  return String(Math.round(v))
}
