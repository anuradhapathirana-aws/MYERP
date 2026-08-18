// ── Shared field styles ───────────────────────────────────────────────────────
// Apply across all pages for consistent, identifiable input fields.
// bg-slate-50 at rest + border-2 makes fields stand out from white card backgrounds.
// focus:bg-white + focus:border-indigo-500 provides clear active state feedback.

// ── Filter panel inputs (compact, for list-page search/filter bars) ───────────

export const FILTER_INPUT_CLS =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'

export const FILTER_SELECT_CLS =
  'block w-full rounded-md border-2 border-slate-200 bg-slate-50 px-2 py-1 text-xs text-slate-800 outline-none transition-all focus:border-indigo-400 focus:bg-white focus:ring-2 focus:ring-indigo-500/15 cursor-pointer'

// ── Form inputs (standard, for create/edit form pages) ───────────────────────

export const INPUT_CLS =
  'block w-full rounded-lg border-2 border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'

export const INPUT_ERR_CLS =
  'block w-full rounded-lg border-2 border-red-300 bg-red-50/40 px-3 py-1.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

export const SELECT_CLS =
  'block w-full rounded-lg border-2 border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-800 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15 cursor-pointer'

export const SELECT_ERR_CLS =
  'block w-full rounded-lg border-2 border-red-300 bg-red-50/40 px-3 py-1.5 text-sm text-slate-800 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15 cursor-pointer'

export const TEXTAREA_CLS =
  'block w-full resize-none rounded-lg border-2 border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-indigo-500 focus:bg-white focus:ring-2 focus:ring-indigo-500/15'

export const TEXTAREA_ERR_CLS =
  'block w-full resize-none rounded-lg border-2 border-red-300 bg-red-50/40 px-3 py-1.5 text-sm text-slate-800 placeholder-slate-400 outline-none transition-all focus:border-red-500 focus:bg-white focus:ring-2 focus:ring-red-500/15'

// ── Disabled variant (for read-only computed fields) ─────────────────────────
export const INPUT_DISABLED_CLS =
  'block w-full rounded-lg border-2 border-slate-100 bg-slate-100 px-3 py-1.5 text-sm text-slate-400 outline-none cursor-not-allowed'

// ── Label ────────────────────────────────────────────────────────────────────
export const LABEL_CLS = 'block text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1'

// ── Helpers ──────────────────────────────────────────────────────────────────
export const inputCls   = (hasError) => (hasError ? INPUT_ERR_CLS   : INPUT_CLS)
export const selectCls  = (hasError) => (hasError ? SELECT_ERR_CLS  : SELECT_CLS)
export const textareaCls = (hasError) => (hasError ? TEXTAREA_ERR_CLS : TEXTAREA_CLS)
