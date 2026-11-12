import { useQuery } from '@tanstack/react-query'
import { AlertTriangle } from 'lucide-react'
import { getCustomerReturnedCheques } from '../../api/customers'
import { fmtMoney } from '../../utils/currency'

const fmtDate = (d) => (d ? d.split('-').reverse().join('-') : '')

/**
 * Red flag shown when a customer with returned (bounced) cheques is picked.
 * Renders nothing for a customer whose cheques all cleared.
 *
 * chequeNos: cheque numbers typed on the current form. A match means the same
 * cheque is being presented again, which is called out on its own line.
 */
export default function ReturnedChequesAlert({ customerId, chequeNos = [] }) {
  const { data: cheques = [] } = useQuery({
    queryKey: ['customer-returned-cheques', String(customerId)],
    queryFn: () => getCustomerReturnedCheques(customerId),
    enabled: Boolean(customerId),
    staleTime: 60 * 1000,
  })

  if (!customerId || cheques.length === 0) return null

  const typed = new Set(chequeNos.map((n) => String(n ?? '').trim()).filter(Boolean))
  const again = cheques.filter((c) => c.cheque_no && typed.has(String(c.cheque_no).trim()))
  const total = cheques.reduce((sum, c) => sum + Number(c.amount || 0), 0)

  return (
    <div className="mt-1.5 rounded-md border border-red-200 bg-red-50 px-2.5 py-1.5 text-[11px] text-red-700">
      <div className="flex items-center gap-1.5 font-semibold">
        <AlertTriangle size={13} className="shrink-0" />
        {cheques.length} returned {cheques.length === 1 ? 'cheque' : 'cheques'} from this customer ({fmtMoney(total)})
      </div>
      <div className="mt-0.5 flex flex-wrap gap-1">
        {cheques.slice(0, 5).map((c, i) => (
          <span
            key={`${c.cheque_no}-${i}`}
            className="inline-flex rounded-full bg-white px-1.5 py-0.5 text-[10px] font-medium text-red-600 ring-1 ring-red-200"
            title={`${c.document_no} · returned ${fmtDate(c.return_date)} in ${c.reference}${c.journal_no ? ` (${c.journal_no})` : ''}`}
          >
            #{c.cheque_no || '—'} · {fmtMoney(c.amount)} · {fmtDate(c.return_date)}
          </span>
        ))}
        {cheques.length > 5 && <span className="text-[10px] text-red-500">+{cheques.length - 5} more</span>}
      </div>
      {again.length > 0 && (
        <div className="mt-1 font-semibold">
          Cheque {again.map((c) => `#${c.cheque_no}`).join(', ')} was returned before. Re-presenting it is a new receipt; confirm with the customer first.
        </div>
      )}
    </div>
  )
}
