import { useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { HelpCircle } from 'lucide-react'

const POPUP_WIDTH = 240
const VIEWPORT_GAP = 8
/** Room a note of a few lines needs; below this much space it opens upward. */
const POPUP_ROOM = 120

/**
 * A "?" beside a field label; hover or keyboard focus shows a small note.
 *
 * The note renders on document.body at a fixed position, so a scrolling table
 * or a card's overflow cannot clip it. It opens upward when there is no room
 * below the icon.
 *
 * Inside a <label> a click on the icon would toggle the label's checkbox, so
 * the icon swallows clicks.
 */
export default function HelpTip({ text, size = 11 }) {
  const [style, setStyle] = useState(null)
  const iconRef           = useRef(null)

  const show = () => {
    const box    = iconRef.current.getBoundingClientRect()
    const left   = Math.max(
      VIEWPORT_GAP,
      Math.min(box.left + box.width / 2 - POPUP_WIDTH / 2, window.innerWidth - POPUP_WIDTH - VIEWPORT_GAP),
    )
    const openUp = window.innerHeight - box.bottom < POPUP_ROOM && box.top > POPUP_ROOM

    setStyle({
      position: 'fixed',
      left,
      width: POPUP_WIDTH,
      ...(openUp ? { bottom: window.innerHeight - box.top + 6 } : { top: box.bottom + 6 }),
    })
  }

  const hide = () => setStyle(null)

  const swallow = (e) => { e.preventDefault(); e.stopPropagation() }

  return (
    <>
      <span
        ref={iconRef}
        role="img"
        aria-label={text}
        tabIndex={0}
        onMouseEnter={show}
        onMouseLeave={hide}
        onFocus={show}
        onBlur={hide}
        onClick={swallow}
        className="inline-flex shrink-0 cursor-help align-middle text-slate-400 outline-none hover:text-indigo-600 focus:text-indigo-600"
      >
        <HelpCircle size={size} />
      </span>
      {style && createPortal(
        <div
          role="tooltip"
          style={style}
          className="pointer-events-none z-70 rounded-md bg-slate-800 px-2.5 py-1.5 text-[11px] font-normal normal-case leading-snug tracking-normal text-white shadow-lg"
        >
          {text}
        </div>,
        document.body,
      )}
    </>
  )
}
