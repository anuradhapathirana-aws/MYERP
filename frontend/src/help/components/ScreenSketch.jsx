/**
 * A simplified drawing of a screen with numbered markers that match the
 * numbered steps below it. Not a screenshot: it shows where things are, so it
 * stays true when colours or small details change.
 *
 * spec = {
 *   title: 'New GRN',
 *   crumbs?: 'Inventory › GRN › New',
 *   buttons?: [{ label, n?, tone? }],          // top-right buttons
 *   cards?: [{ title, n?, fields: [{ label, n?, value?, wide? }] }],
 *   fields?: [{ label, n?, value?, wide? }],   // single card shortcut
 *   table?: { n?, cols: string[], rows?: string[][], addRow?: { label, n? } },
 *   footer?: [{ label, n?, tone? }],           // bottom buttons / totals
 *   note?: string,
 * }
 */

const BTN_TONE = {
  indigo: 'bg-indigo-600 text-white',
  green:  'bg-green-600 text-white',
  red:    'bg-red-600 text-white',
  amber:  'bg-amber-500 text-white',
  slate:  'bg-white text-slate-600 ring-1 ring-slate-300',
  blue:   'bg-blue-600 text-white',
}

function Marker({ n }) {
  if (!n) return null
  return (
    <span className="absolute -right-1.5 -top-1.5 z-10 flex h-4 w-4 items-center justify-center rounded-full bg-rose-600 text-[9px] font-bold text-white shadow ring-2 ring-white">
      {n}
    </span>
  )
}

function Field({ f }) {
  return (
    <div className={`relative ${f.wide ? 'col-span-2' : ''}`}>
      <Marker n={f.n} />
      <div className="text-[8.5px] font-bold uppercase tracking-wide text-slate-400">{f.label}</div>
      <div className="mt-0.5 h-5 truncate rounded border-2 border-slate-200 bg-slate-50 px-1 text-[10px] leading-4 text-slate-600">
        {f.value ?? ''}
      </div>
    </div>
  )
}

function Btn({ b }) {
  return (
    <span className={`relative inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold ${BTN_TONE[b.tone ?? 'indigo']}`}>
      <Marker n={b.n} />
      {b.label}
    </span>
  )
}

export default function ScreenSketch({ spec }) {
  const cards = spec.cards ?? (spec.fields ? [{ fields: spec.fields }] : [])

  return (
    <figure className="overflow-hidden rounded-lg border-2 border-slate-300 bg-slate-100 shadow-sm">
      {/* window bar */}
      <div className="flex items-center gap-1 border-b border-slate-300 bg-slate-200 px-2 py-1">
        <span className="h-2 w-2 rounded-full bg-red-400" />
        <span className="h-2 w-2 rounded-full bg-amber-400" />
        <span className="h-2 w-2 rounded-full bg-green-400" />
        <span className="ml-2 text-[10px] font-medium text-slate-500">Screen sketch (not to scale)</span>
      </div>

      <div className="flex">
        {/* sidebar */}
        <div className="hidden w-14 shrink-0 space-y-1 bg-slate-800 p-1.5 sm:block">
          {Array.from({ length: 7 }).map((_, i) => (
            <div key={i} className={`h-1.5 rounded ${i === 2 ? 'bg-indigo-400' : 'bg-slate-600'}`} />
          ))}
        </div>

        <div className="min-w-0 flex-1 space-y-1.5 p-2">
          <div className="flex flex-wrap items-start justify-between gap-1">
            <div>
              <div className="text-xs font-bold text-slate-800">{spec.title}</div>
              {spec.crumbs && <div className="text-[9px] text-slate-400">{spec.crumbs}</div>}
            </div>
            <div className="flex flex-wrap gap-1.5">
              {(spec.buttons ?? []).map((b, i) => <Btn key={i} b={b} />)}
            </div>
          </div>

          {cards.length > 0 && (
            <div className={`grid grid-cols-1 gap-1.5 ${cards.length > 1 ? 'md:grid-cols-2' : ''}`}>
              {cards.map((c, i) => (
                <div key={i} className="relative rounded border border-slate-200 bg-white p-1.5">
                  <Marker n={c.n} />
                  {c.title && <div className="mb-1 text-[9.5px] font-bold text-slate-600">{c.title}</div>}
                  <div className="grid grid-cols-2 gap-x-2 gap-y-1 lg:grid-cols-3">
                    {c.fields.map((f, j) => <Field key={j} f={f} />)}
                  </div>
                </div>
              ))}
            </div>
          )}

          {spec.table && (
            <div className="relative rounded border border-slate-200 bg-white">
              <Marker n={spec.table.n} />
              <div className="overflow-x-auto">
                <table className="w-full text-[9.5px]">
                  <thead className="bg-slate-50 text-slate-500">
                    <tr>{spec.table.cols.map((c) => <th key={c} className="px-1.5 py-0.5 text-left font-semibold">{c}</th>)}</tr>
                  </thead>
                  <tbody>
                    {(spec.table.rows ?? [spec.table.cols.map(() => '')]).map((r, i) => (
                      <tr key={i} className="border-t border-slate-100">
                        {r.map((cell, j) => <td key={j} className="h-4 px-1.5 py-0.5 text-slate-600">{cell}</td>)}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {spec.table.addRow && (
                <div className="border-t border-slate-100 px-1.5 py-0.5">
                  <span className="relative inline-block text-[9.5px] font-semibold text-indigo-600">
                    <Marker n={spec.table.addRow.n} />
                    + {spec.table.addRow.label}
                  </span>
                </div>
              )}
            </div>
          )}

          {spec.footer && (
            <div className="flex flex-wrap justify-end gap-1.5">
              {spec.footer.map((b, i) => <Btn key={i} b={b} />)}
            </div>
          )}
        </div>
      </div>
      {spec.note && <figcaption className="border-t border-slate-200 bg-white px-2 py-1 text-[10px] text-slate-500">{spec.note}</figcaption>}
    </figure>
  )
}
