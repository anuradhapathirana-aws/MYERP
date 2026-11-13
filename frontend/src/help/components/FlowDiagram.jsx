import { Fragment } from 'react'
import { ArrowDown, ArrowRight, CornerDownRight } from 'lucide-react'
import { TONES } from './tones'
import RichText from './RichText'

/**
 * A left-to-right process diagram that wraps on narrow screens.
 *
 * spec = {
 *   title?: string,
 *   steps: [{ label, sub?, tone?, tag? }],          // tag: small badge e.g. 'Finance'
 *   branches?: [{ at: stepIndex, label, sub?, tone? }],   // side paths shown under a step
 *   legend?: string,
 * }
 */
export default function FlowDiagram({ spec }) {
  const { title, steps = [], branches = [], legend } = spec

  return (
    <figure className="rounded-lg border border-slate-200 bg-white p-2.5">
      {title && <figcaption className="mb-2 text-[11px] font-bold uppercase tracking-wider text-slate-500">{title}</figcaption>}

      <div className="flex flex-col items-stretch gap-1 sm:flex-row sm:flex-wrap sm:items-start sm:gap-y-3">
        {steps.map((step, i) => {
          const tone = TONES[step.tone ?? 'blue'] ?? TONES.blue
          const side = branches.filter((b) => b.at === i)
          return (
            <Fragment key={i}>
              <div className="flex flex-col items-stretch sm:w-36">
                <div className={`relative rounded-lg border-2 px-2 py-1.5 text-center shadow-sm ${tone.box}`}>
                  <span className="absolute -left-2 -top-2 flex h-4 w-4 items-center justify-center rounded-full bg-slate-800 text-[9px] font-bold text-white">
                    {i + 1}
                  </span>
                  {step.tag && (
                    <span className="absolute -right-1.5 -top-2 rounded bg-white px-1 text-[9px] font-semibold text-slate-500 ring-1 ring-slate-300">
                      {step.tag}
                    </span>
                  )}
                  <div className="text-xs font-semibold leading-tight">{step.label}</div>
                  {step.sub && <div className="mt-0.5 text-[10px] leading-tight opacity-80"><RichText text={step.sub} /></div>}
                </div>
                {side.map((b, j) => {
                  const bt = TONES[b.tone ?? 'amber'] ?? TONES.amber
                  return (
                    <div key={j} className="mt-1 flex items-start gap-0.5">
                      <CornerDownRight size={12} className="mt-1 shrink-0 text-slate-400" />
                      <div className={`flex-1 rounded-md border border-dashed px-1.5 py-1 text-center ${bt.box}`}>
                        <div className="text-[10px] font-semibold leading-tight">{b.label}</div>
                        {b.sub && <div className="text-[9.5px] leading-tight opacity-80"><RichText text={b.sub} /></div>}
                      </div>
                    </div>
                  )
                })}
              </div>
              {i < steps.length - 1 && (
                <div className="flex items-center justify-center text-slate-400 sm:h-10 sm:px-0.5">
                  <ArrowDown size={14} className="sm:hidden" />
                  <ArrowRight size={16} className="hidden sm:block" />
                </div>
              )}
            </Fragment>
          )
        })}
      </div>

      {legend && <p className="mt-2 text-[11px] text-slate-500"><RichText text={legend} /></p>}
    </figure>
  )
}
