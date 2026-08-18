import { ChevronLeft, ChevronRight } from 'lucide-react'

/**
 * Reusable numbered pagination component.
 *
 * Props:
 *   meta         — { current_page, last_page, per_page, total } from API response
 *   page         — current page number (controlled)
 *   onPageChange — callback(newPage: number)
 */
export default function Pagination({ meta, page, onPageChange }) {
  if (!meta || meta.last_page <= 1) return null

  const { last_page, per_page, total } = meta
  const start = (page - 1) * per_page + 1
  const end   = Math.min(page * per_page, total)

  const pages = buildPageList(page, last_page)

  return (
    <div className="flex items-center justify-between border-t border-slate-200 px-4 py-2">
      <p className="text-xs text-slate-500">
        Showing{' '}
        <span className="font-medium text-slate-700">{start}–{end}</span>{' '}
        of <span className="font-medium text-slate-700">{total}</span>
      </p>

      <div className="flex items-center gap-0.5">
        {/* Prev */}
        <button
          onClick={() => onPageChange(page - 1)}
          disabled={page === 1}
          className="flex items-center gap-0.5 rounded px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
        >
          <ChevronLeft size={13} />
          Prev
        </button>

        {/* Page numbers */}
        {pages.map((p, i) =>
          p === '...' ? (
            <span key={`ellipsis-${i}`} className="px-1.5 text-xs text-slate-400">…</span>
          ) : (
            <button
              key={p}
              onClick={() => onPageChange(p)}
              className={`min-w-[28px] rounded px-1.5 py-1 text-xs font-medium transition-colors ${
                p === page
                  ? 'bg-blue-600 text-white'
                  : 'text-slate-600 hover:bg-slate-100'
              }`}
            >
              {p}
            </button>
          )
        )}

        {/* Next */}
        <button
          onClick={() => onPageChange(page + 1)}
          disabled={page === last_page}
          className="flex items-center gap-0.5 rounded px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-40"
        >
          Next
          <ChevronRight size={13} />
        </button>
      </div>
    </div>
  )
}

/**
 * Builds the page number list with ellipsis for large page counts.
 * e.g. for page=6 of 20: [1, '...', 4, 5, 6, 7, 8, '...', 20]
 */
function buildPageList(current, total) {
  if (total <= 7) {
    return Array.from({ length: total }, (_, i) => i + 1)
  }

  const delta = 2
  const left  = current - delta
  const right = current + delta

  const pages = []
  let prev = null

  for (let i = 1; i <= total; i++) {
    if (i === 1 || i === total || (i >= left && i <= right)) {
      if (prev !== null && i - prev > 1) pages.push('...')
      pages.push(i)
      prev = i
    }
  }

  return pages
}
