/** Colour sets for diagram boxes and status chips (Tailwind classes). */
export const TONES = {
  slate:  { box: 'border-slate-300 bg-slate-50 text-slate-700',    chip: 'bg-slate-100 text-slate-700 border-slate-300',    dot: 'bg-slate-500' },
  blue:   { box: 'border-blue-300 bg-blue-50 text-blue-800',       chip: 'bg-blue-100 text-blue-700 border-blue-300',       dot: 'bg-blue-600' },
  indigo: { box: 'border-indigo-300 bg-indigo-50 text-indigo-800', chip: 'bg-indigo-100 text-indigo-700 border-indigo-300', dot: 'bg-indigo-600' },
  violet: { box: 'border-violet-300 bg-violet-50 text-violet-800', chip: 'bg-violet-100 text-violet-700 border-violet-300', dot: 'bg-violet-600' },
  teal:   { box: 'border-teal-300 bg-teal-50 text-teal-800',       chip: 'bg-teal-100 text-teal-700 border-teal-300',       dot: 'bg-teal-600' },
  green:  { box: 'border-green-300 bg-green-50 text-green-800',    chip: 'bg-green-100 text-green-700 border-green-300',    dot: 'bg-green-600' },
  amber:  { box: 'border-amber-300 bg-amber-50 text-amber-800',    chip: 'bg-amber-100 text-amber-800 border-amber-300',    dot: 'bg-amber-500' },
  red:    { box: 'border-red-300 bg-red-50 text-red-800',          chip: 'bg-red-100 text-red-700 border-red-300',          dot: 'bg-red-600' },
}

/** A sensible colour for a status name when the topic doesn't give one. */
export function toneForStatus(name) {
  const n = String(name).toLowerCase()
  if (/(cancel|reject|void|bounce|return|recall|expired)/.test(n)) return 'red'
  if (/(draft|open)/.test(n)) return 'slate'
  if (/(pending|submit|partial|sent|quarantine|hold|allocated|not presented)/.test(n)) return 'amber'
  if (/(confirm|approv|posted|paid|complete|issued|realiz|in stock|active|delivered|exhausted|converted|invoiced)/.test(n)) return 'green'
  return 'blue'
}
