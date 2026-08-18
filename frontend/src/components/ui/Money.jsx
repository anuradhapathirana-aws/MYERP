import { CURRENCY, fmtMoney } from '../../utils/currency'

/**
 * A money amount with a muted currency tag — every price reads "1,520.00 Rs".
 *
 * Only for money. A quantity or weight rendered through this reads as a price and is a
 * worse bug than showing no currency at all — those keep their own plain formatter.
 */
export default function Money({ value, className = '' }) {
  return (
    <span className={`tabular-nums ${className}`}>
      {fmtMoney(value)}
      <span className="ml-0.5 text-[9px] font-normal text-slate-400">{CURRENCY}</span>
    </span>
  )
}
