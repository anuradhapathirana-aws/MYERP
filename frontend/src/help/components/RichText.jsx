import { Fragment } from 'react'
import { highlightParts } from '../search'

/**
 * Renders manual text with light markup: **bold** and `code` (shown as a
 * key-cap / field chip). Optional `terms` highlight search words.
 */
export default function RichText({ text, terms = [] }) {
  const tokens = String(text ?? '').split(/(\*\*[^*]+\*\*|`[^`]+`)/g).filter(Boolean)

  const mark = (s) =>
    highlightParts(s, terms).map((p, i) =>
      p.hit ? <mark key={i} className="rounded bg-yellow-200/80 px-0.5 text-inherit">{p.text}</mark> : <Fragment key={i}>{p.text}</Fragment>,
    )

  return (
    <>
      {tokens.map((tok, i) => {
        if (tok.startsWith('**') && tok.endsWith('**')) {
          return <strong key={i} className="font-semibold text-slate-800">{mark(tok.slice(2, -2))}</strong>
        }
        if (tok.startsWith('`') && tok.endsWith('`')) {
          return (
            <code key={i} className="rounded border border-slate-300 bg-slate-100 px-1 py-px font-mono text-[11px] font-semibold text-slate-700">
              {tok.slice(1, -1)}
            </code>
          )
        }
        return <Fragment key={i}>{mark(tok)}</Fragment>
      })}
    </>
  )
}
