import { useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { Info } from 'lucide-react'
import Money from '../ui/Money'

const WIDTH = 300
const GAP   = 8

/**
 * Shows `note` in a small popup while the pointer is over (or keyboard focus is
 * inside) the wrapped element. Rendered on document.body at a fixed position so
 * no card or grid can clip it; flips upward / leftward near the screen edges.
 */
export function HoverNote({ note, children, className = '' }) {
  const [style, setStyle] = useState(null)
  const ref = useRef(null)

  const show = () => {
    if (!note || !ref.current) return
    const box  = ref.current.getBoundingClientRect()
    const left = Math.max(GAP, Math.min(box.left, window.innerWidth - WIDTH - GAP))
    const roomBelow = window.innerHeight - box.bottom
    const openUp    = roomBelow < 260 && box.top > roomBelow

    setStyle({
      position: 'fixed',
      left,
      width: WIDTH,
      ...(openUp ? { bottom: window.innerHeight - box.top + 6 } : { top: box.bottom + 6 }),
    })
  }
  const hide = () => setStyle(null)

  return (
    <div
      ref={ref}
      className={className}
      onMouseEnter={show}
      onMouseLeave={hide}
      onFocus={show}
      onBlur={hide}
    >
      {children}
      {style && createPortal(
        <div role="tooltip" style={style} className="pointer-events-none z-70">
          {note}
        </div>,
        document.body,
      )}
    </div>
  )
}

/** A small ⓘ that opens the note on hover or keyboard focus. */
export function InfoTip({ note, size = 13 }) {
  return (
    <HoverNote note={note} className="inline-flex">
      <span tabIndex={0} aria-label="How this is calculated"
            onClick={(e) => { e.preventDefault(); e.stopPropagation() }}
            className="inline-flex cursor-help text-slate-400 outline-none hover:text-indigo-600 focus:text-indigo-600">
        <Info size={size} />
      </span>
    </HoverNote>
  )
}

/**
 * The note itself: what the figure is, the sum that produced it (with the
 * user's own numbers, so it can be checked by hand), and what is left out.
 *
 * @param {{
 *   title: string,
 *   formula?: string,
 *   lines?: Array<{ label: string, value: number, op?: '+'|'−'|'=', count?: string, money?: boolean }>,
 *   points?: string[],
 *   source?: string,
 * }} props
 */
export function CalcNote({ title, formula, lines = [], points = [], source }) {
  return (
    <div className="rounded-lg border border-slate-200 bg-white text-[11px] leading-snug text-slate-600 shadow-xl">
      <div className="rounded-t-lg border-b border-slate-100 bg-slate-50 px-3 py-1.5">
        <p className="text-[10px] font-semibold uppercase tracking-wider text-indigo-600">How this is calculated</p>
        <p className="font-semibold text-slate-800">{title}</p>
      </div>

      <div className="space-y-1.5 px-3 py-2">
        {formula && (
          <p className="rounded bg-indigo-50 px-2 py-1 font-medium text-indigo-800">{formula}</p>
        )}

        {lines.length > 0 && (
          <table className="w-full">
            <tbody>
              {lines.map((l, i) => {
                const total = l.op === '='
                return (
                  <tr key={i} className={total ? 'border-t border-slate-200' : ''}>
                    <td className={`w-3 py-0.5 text-center font-bold ${total ? 'text-slate-800' : 'text-slate-400'}`}>{l.op ?? ''}</td>
                    <td className={`py-0.5 ${total ? 'font-semibold text-slate-800' : ''}`}>
                      {l.label}
                      {l.count && <span className="ml-1 text-[10px] text-slate-400">({l.count})</span>}
                    </td>
                    <td className={`py-0.5 text-right tabular-nums ${total ? 'font-bold text-slate-900' : 'font-medium text-slate-700'}`}>
                      {l.money === false ? Number(l.value ?? 0).toLocaleString() : <Money value={l.value} />}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}

        {points.length > 0 && (
          <ul className="list-disc space-y-0.5 pl-4 text-slate-500">
            {points.map((p) => <li key={p}>{p}</li>)}
          </ul>
        )}

        {source && (
          <p className="border-t border-slate-100 pt-1.5 text-[10px] text-slate-400">
            Check it in: <span className="font-medium text-slate-600">{source}</span>
          </p>
        )}
      </div>
    </div>
  )
}
