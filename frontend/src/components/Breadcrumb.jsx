import { Link } from 'react-router-dom'
import { ChevronRight } from 'lucide-react'

/**
 * @param {{ crumbs: Array<{ label: string, to?: string }> }} props
 * Omit `to` on the last crumb — it renders as plain text (current page).
 */
export default function Breadcrumb({ crumbs }) {
  return (
    <nav aria-label="Breadcrumb" className="mb-1 flex items-center gap-1 text-[11px]">
      {crumbs.map((crumb, i) => {
        const isLast = i === crumbs.length - 1
        return (
          <span key={i} className="flex items-center gap-1">
            {i > 0 && <ChevronRight size={13} className="text-slate-300" />}
            {isLast ? (
              <span className="font-medium text-slate-700">{crumb.label}</span>
            ) : (
              <Link
                to={crumb.to}
                className="text-slate-400 transition-colors hover:text-slate-600"
              >
                {crumb.label}
              </Link>
            )}
          </span>
        )
      })}
    </nav>
  )
}
