import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { Building2, Clock, Globe, Headset, Mail, MessageCircle, Phone, User } from 'lucide-react'
import { getSupportContact } from '../../api/system'
import { readProfile } from '../../utils/session'

const waLink = (n) => `https://wa.me/${String(n).replace(/\D/g, '')}`

/** "Need help?" — the IT support contact a super admin sets in System Settings. */
export default function SupportCard({ compact = false }) {
  const { data = {}, isLoading } = useQuery({
    queryKey: ['support-contact'],
    queryFn: getSupportContact,
    staleTime: 5 * 60_000,
  })
  const isSuperAdmin = readProfile().is_super_admin

  const rows = [
    { icon: Building2,     value: data.company },
    { icon: User,          value: data.contact_person },
    { icon: Phone,         value: data.phone,    href: data.phone && `tel:${data.phone.replace(/\s/g, '')}` },
    { icon: MessageCircle, value: data.whatsapp, href: data.whatsapp && waLink(data.whatsapp), label: 'WhatsApp' },
    { icon: Mail,          value: data.email,    href: data.email && `mailto:${data.email}` },
    { icon: Globe,         value: data.website,  href: data.website },
    { icon: Clock,         value: data.hours },
  ].filter((r) => r.value)

  return (
    <div className="rounded-xl border border-indigo-200 bg-indigo-50/60 p-2.5">
      <div className="flex items-center gap-1.5 text-sm font-semibold text-indigo-800">
        <Headset size={15} /> Need help?
      </div>
      {!compact && (
        <p className="mt-1 text-xs text-slate-600">
          Couldn&apos;t find the answer here? Contact your IT support team.
        </p>
      )}

      {isLoading ? (
        <p className="mt-2 text-xs text-slate-400">Loading…</p>
      ) : rows.length === 0 ? (
        <p className="mt-2 text-xs text-slate-500">
          Support contact details have not been added yet. Please ask your system administrator.
          {isSuperAdmin && (
            <> <Link to="/admin/modules" className="font-semibold text-indigo-600 hover:underline">Add them in System Settings.</Link></>
          )}
        </p>
      ) : (
        <ul className="mt-2 space-y-1">
          {rows.map(({ icon: Icon, value, href, label }, i) => (
            <li key={i} className="flex items-start gap-1.5 text-xs text-slate-700">
              <Icon size={13} className="mt-0.5 shrink-0 text-indigo-500" />
              {href ? (
                <a href={href} target={href.startsWith('http') ? '_blank' : undefined} rel="noreferrer" className="break-all font-medium text-indigo-700 hover:underline">
                  {label ? `${label}: ${value}` : value}
                </a>
              ) : (
                <span className="break-words">{value}</span>
              )}
            </li>
          ))}
        </ul>
      )}

      {data.notes && <p className="mt-2 border-t border-indigo-100 pt-1.5 text-[11px] text-slate-600">{data.notes}</p>}
    </div>
  )
}
