import { Fragment } from 'react'
import { ArrowRight, CornerDownRight } from 'lucide-react'
import { TONES, toneForStatus } from './tones'
import RichText from './RichText'

/**
 * A document's life cycle.
 *
 * spec = {
 *   main:   ['Draft', 'Confirmed'],                       // the normal path
 *   side?:  [{ from: 'Draft', to: 'Cancelled', note? }],  // other moves
 *   states?: [{ name, meaning, tone? }],                  // legend
 *   auto?:  string,                                       // note on automatic changes
 * }
 */
function StatusChip({ name, tone }) {
  const t = TONES[tone ?? toneForStatus(name)] ?? TONES.blue
  return (
    <span className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold ${t.chip}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${t.dot}`} />
      {name}
    </span>
  )
}

export default function StatusDiagram({ spec }) {
  const { main = [], side = [], states = [], auto } = spec
  const toneOf = (name) => states.find((s) => s.name === name)?.tone

  return (
    <figure className="rounded-lg border border-slate-200 bg-white p-2.5">
      <figcaption className="mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500">Status life cycle</figcaption>

      <div className="flex flex-wrap items-center gap-1.5">
        {main.map((name, i) => (
          <Fragment key={name}>
            <StatusChip name={name} tone={toneOf(name)} />
            {i < main.length - 1 && <ArrowRight size={14} className="text-slate-400" />}
          </Fragment>
        ))}
      </div>

      {side.length > 0 && (
        <div className="mt-2 space-y-1">
          {side.map((s, i) => (
            <div key={i} className="flex flex-wrap items-center gap-1.5 text-[11px] text-slate-500">
              <CornerDownRight size={12} className="text-slate-400" />
              <StatusChip name={s.from} tone={toneOf(s.from)} />
              <ArrowRight size={12} className="text-slate-400" />
              <StatusChip name={s.to} tone={toneOf(s.to)} />
              {s.note && <span><RichText text={s.note} /></span>}
            </div>
          ))}
        </div>
      )}

      {states.length > 0 && (
        <dl className="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 border-t border-slate-100 pt-2 md:grid-cols-2">
          {states.map((s) => (
            <div key={s.name} className="flex items-start gap-1.5 text-xs">
              <dt className="shrink-0"><StatusChip name={s.name} tone={toneOf(s.name)} /></dt>
              <dd className="text-slate-600"><RichText text={s.meaning} /></dd>
            </div>
          ))}
        </dl>
      )}

      {auto && <p className="mt-2 text-[11px] italic text-slate-500"><RichText text={auto} /></p>}
    </figure>
  )
}
