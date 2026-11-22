import { useEffect, useState } from 'react'

/** `value`, but only after it has stopped changing for `delay` ms. For typed filters. */
export function useDebouncedValue(value, delay = 300) {
  const [debounced, setDebounced] = useState(value)

  useEffect(() => {
    const t = setTimeout(() => setDebounced(value), delay)
    return () => clearTimeout(t)
  }, [value, delay])

  return debounced
}
