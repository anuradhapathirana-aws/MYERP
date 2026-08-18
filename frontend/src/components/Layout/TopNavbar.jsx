import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Bell, ChevronDown, LogOut, Menu } from 'lucide-react'

export default function TopNavbar({ onMenuClick }) {
  const navigate = useNavigate()
  const userName  = localStorage.getItem('user_name')  || ''
  const userEmail = localStorage.getItem('user_email') || ''
  const [dropdownOpen, setDropdownOpen] = useState(false)
  const [hasNotif,     setHasNotif]     = useState(true)

  const displayName = userName || userEmail || 'User'

  const initials = userName
    ? userName
        .split(/\s+/)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .slice(0, 2)
        .join('')
    : (userEmail[0]?.toUpperCase() ?? 'U')

  const handleLogout = () => {
    localStorage.removeItem('auth_token')
    localStorage.removeItem('user_name')
    localStorage.removeItem('user_email')
    localStorage.removeItem('active_modules')
    localStorage.removeItem('user_roles')
    localStorage.removeItem('user_permissions')
    navigate('/login')
  }

  return (
    <header className="flex h-16 shrink-0 items-center justify-between border-b border-slate-200 bg-white px-4 sm:px-6">
      {/* ── Left: hamburger + user name ── */}
      <div className="flex items-center gap-3">
        <button
          onClick={onMenuClick}
          className="rounded-lg p-2 text-slate-500 hover:bg-slate-100 lg:hidden"
          aria-label="Toggle sidebar"
        >
          <Menu size={20} />
        </button>

        <div className="flex flex-col leading-tight">
          <span className="text-[11px] font-medium uppercase tracking-wider text-slate-400">
            Logged in as
          </span>
          <span className="text-sm font-semibold text-slate-800">{displayName}</span>
        </div>
      </div>

      {/* ── Right: notifications + profile ── */}
      <div className="flex items-center gap-1">
        {/* Notifications bell */}
        <button
          onClick={() => setHasNotif(false)}
          className="relative rounded-lg p-2 text-slate-500 hover:bg-slate-100"
          aria-label="Notifications"
        >
          <Bell size={20} />
          {hasNotif && (
            <span className="absolute right-1.5 top-1.5 h-2 w-2 rounded-full bg-indigo-500 ring-2 ring-white" />
          )}
        </button>

        {/* Profile dropdown */}
        <div className="relative ml-1">
          <button
            onClick={() => setDropdownOpen((o) => !o)}
            className="flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-slate-700 hover:bg-slate-100"
          >
            <span className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-bold text-white">
              {initials}
            </span>
            <ChevronDown
              size={14}
              className={`text-slate-400 transition-transform duration-150 ${
                dropdownOpen ? 'rotate-180' : ''
              }`}
            />
          </button>

          {dropdownOpen && (
            <>
              {/* Click-outside backdrop */}
              <div
                className="fixed inset-0 z-10"
                onClick={() => setDropdownOpen(false)}
              />

              <div className="absolute right-0 z-20 mt-1 w-56 rounded-xl border border-slate-200 bg-white shadow-lg">
                <div className="border-b border-slate-100 px-4 py-3">
                  <p className="truncate text-sm font-semibold text-slate-800">{displayName}</p>
                  {userEmail && (
                    <p className="mt-0.5 truncate text-xs text-slate-400">{userEmail}</p>
                  )}
                </div>
                <div className="p-1">
                  <button
                    onClick={handleLogout}
                    className="flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-sm text-red-600 hover:bg-red-50"
                  >
                    <LogOut size={15} />
                    Sign out
                  </button>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </header>
  )
}
