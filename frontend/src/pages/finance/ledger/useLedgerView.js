import { useCallback, useState } from 'react'

// Each user's own last choice; the company default (Finance Settings) applies until they pick one.
const VIEW_KEY = 'ledger.view'
export const LEDGER_VIEWS = ['list', 't_account']

const readView = () => {
  try {
    const v = localStorage.getItem(VIEW_KEY)
    return LEDGER_VIEWS.includes(v) ? v : null
  } catch { return null }
}

/**
 * List or T-account, shared by every ledger screen.
 *
 * The company default arrives with the first API response: call
 * adoptDefault(data?.default_view) while rendering and it applies until the
 * user picks a view themselves. Their pick is remembered in the browser, so
 * the popup and Ledger Details open the same way.
 *
 * @returns {{ view: 'list'|'t_account', changeView: Function, toggleView: Function, adoptDefault: Function }}
 */
export function useLedgerView() {
  const [userView, setUserView]       = useState(readView)
  const [defaultView, setDefaultView] = useState(null)

  const view = userView ?? defaultView ?? 'list'

  const changeView = useCallback((v) => {
    setUserView(v)
    try { localStorage.setItem(VIEW_KEY, v) } catch { /* storage unavailable: keep in memory */ }
  }, [])

  const toggleView = useCallback(() => changeView(view === 'list' ? 't_account' : 'list'), [changeView, view])

  // Safe to call during render: it only sets state once, the first time a
  // valid default arrives (React's "adjust state while rendering" pattern).
  const adoptDefault = (v) => {
    if (defaultView === null && LEDGER_VIEWS.includes(v)) setDefaultView(v)
  }

  return { view, changeView, toggleView, adoptDefault }
}
