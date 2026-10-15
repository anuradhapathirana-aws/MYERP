/**
 * ERP standard: every page with keyboard shortcuts lists them in this panel at
 * the bottom of the page.
 *
 * @param {{ shortcuts: Array<{ keys: string[], desc: string }> }} props
 */
export default function KeyboardShortcuts({ shortcuts }) {
  if (!shortcuts?.length) return null

  return (
    <div className="mt-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
      <p className="mb-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Keyboard Shortcuts</p>
      <div className="flex flex-wrap gap-x-5 gap-y-1">
        {shortcuts.map(({ keys, desc }) => (
          <div key={desc} className="flex items-center gap-1.5">
            <div className="flex items-center gap-0.5">
              {keys.map((k, i) => (
                <span key={i} className="inline-flex items-center justify-center rounded border border-slate-300 bg-white px-1 py-px text-[10px] font-mono font-semibold text-slate-600 shadow-sm">
                  {k}
                </span>
              ))}
            </div>
            <span className="text-[10px] text-slate-500">{desc}</span>
          </div>
        ))}
      </div>
    </div>
  )
}
