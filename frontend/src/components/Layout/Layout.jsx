import { useEffect, useState } from 'react'
import { Outlet, useLocation } from 'react-router-dom'
import { Menu } from 'lucide-react'
import Sidebar from './Sidebar'

// Matches Tailwind's `lg` breakpoint — below this the sidebar becomes an off-canvas drawer.
const DESKTOP_QUERY = '(min-width: 1024px)'

function useIsDesktop() {
  const [isDesktop, setIsDesktop] = useState(() => window.matchMedia(DESKTOP_QUERY).matches)

  useEffect(() => {
    const mql = window.matchMedia(DESKTOP_QUERY)
    const onChange = (e) => setIsDesktop(e.matches)
    mql.addEventListener('change', onChange)
    return () => mql.removeEventListener('change', onChange)
  }, [])

  return isDesktop
}

export default function Layout() {
  const [collapsed,  setCollapsed]  = useState(false)
  const [mobileOpen, setMobileOpen] = useState(false)
  const isDesktop = useIsDesktop()
  const { pathname } = useLocation()

  // Close the mobile drawer after navigating to another page.
  useEffect(() => {
    setMobileOpen(false)
  }, [pathname])

  // Close the drawer on Escape, and when the viewport grows to desktop size.
  useEffect(() => {
    if (!mobileOpen) return
    if (isDesktop) {
      setMobileOpen(false)
      return
    }
    const onKey = (e) => e.key === 'Escape' && setMobileOpen(false)
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [mobileOpen, isDesktop])

  return (
    <div className="flex h-screen overflow-hidden bg-slate-50">
      {mobileOpen && (
        <div
          className="fixed inset-0 z-20 bg-black/40 lg:hidden"
          onClick={() => setMobileOpen(false)}
        />
      )}

      <Sidebar
        // The icon-only collapsed mode is desktop-only; the mobile drawer always shows labels.
        collapsed={collapsed && isDesktop}
        onToggleCollapse={() => setCollapsed((c) => !c)}
        mobileOpen={mobileOpen}
        onClose={() => setMobileOpen(false)}
      />

      <div className="flex min-w-0 flex-1 flex-col">
        {/* ── Mobile-only top bar ── */}
        <header className="flex h-11 shrink-0 items-center gap-2 border-b border-slate-700/60 bg-slate-900 px-2 lg:hidden">
          <button
            type="button"
            onClick={() => setMobileOpen(true)}
            className="rounded-lg p-1.5 text-slate-300 hover:bg-slate-700 hover:text-white"
            aria-label="Open menu"
          >
            <Menu size={20} />
          </button>
          <span className="text-sm font-bold uppercase tracking-widest text-white">ERP</span>
        </header>

        <main className="min-w-0 flex-1 overflow-y-auto" style={{ padding: '0.75%' }}>
          <Outlet />
        </main>
      </div>
    </div>
  )
}
