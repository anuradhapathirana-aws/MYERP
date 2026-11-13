import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Headset, Loader2, Save } from 'lucide-react'
import { getSupportContact, updateSupportContact } from '../../api/system'
import { INPUT_CLS, INPUT_ERR_CLS, LABEL_CLS, TEXTAREA_CLS } from '../../utils/fieldStyles'
import { showError, showSuccess } from '../../utils/alerts'

const EMPTY = { company: '', contact_person: '', phone: '', whatsapp: '', email: '', website: '', hours: '', notes: '' }

const FIELDS = [
  { key: 'company',        label: 'IT Company',          placeholder: 'e.g. Acme IT Solutions' },
  { key: 'contact_person', label: 'Contact Person / Desk', placeholder: 'e.g. Support Desk' },
  { key: 'phone',          label: 'Phone',               placeholder: 'e.g. +94 11 234 5678' },
  { key: 'whatsapp',       label: 'WhatsApp',            placeholder: 'e.g. +94 77 123 4567' },
  { key: 'email',          label: 'Email',               placeholder: 'e.g. support@acme.lk', type: 'email' },
  { key: 'website',        label: 'Website',             placeholder: 'https://…' },
  { key: 'hours',          label: 'Support Hours',       placeholder: 'e.g. Mon–Fri 8.30am – 5.30pm' },
]

/**
 * System Settings > Support Contact (super admin). Who users should contact
 * for IT help; shown on every page of the User Manual.
 */
export default function SupportContactCard() {
  const { data, isLoading } = useQuery({ queryKey: ['support-contact'], queryFn: getSupportContact })

  // Re-key on the saved values so the form starts from them after each load.
  return <SupportContactForm key={JSON.stringify(data ?? {})} saved={data ?? {}} isLoading={isLoading} />
}

function SupportContactForm({ saved, isLoading }) {
  const queryClient = useQueryClient()
  const [form, setForm]     = useState(() => Object.fromEntries(Object.keys(EMPTY).map((k) => [k, saved[k] ?? ''])))
  const [errors, setErrors] = useState({})

  const mutation = useMutation({
    mutationFn: updateSupportContact,
    onSuccess: () => {
      setErrors({})
      queryClient.invalidateQueries({ queryKey: ['support-contact'] })
      showSuccess('Support contact saved. Users see it in the User Manual.')
    },
    onError: (err) => {
      if (err.response?.status === 422) setErrors(err.response.data?.errors ?? {})
      showError(err.response?.data?.message ?? 'Could not save the support contact.')
    },
  })

  const set = (key) => (e) => {
    setForm((f) => ({ ...f, [key]: e.target.value }))
    if (errors[key]) setErrors((p) => ({ ...p, [key]: undefined }))
  }

  const submit = (e) => {
    e.preventDefault()
    mutation.mutate(form)
  }

  return (
    <form onSubmit={submit} className="mt-3 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
      <div className="flex items-center gap-2">
        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-50 text-indigo-600">
          <Headset size={16} />
        </span>
        <div>
          <h2 className="text-sm font-semibold text-slate-800">Support Contact</h2>
          <p className="text-xs text-slate-500">Shown to every user in the User Manual&apos;s &ldquo;Need help?&rdquo; card.</p>
        </div>
      </div>

      {isLoading ? (
        <div className="mt-3 flex items-center gap-2 text-sm text-slate-400">
          <Loader2 size={14} className="animate-spin" /> Loading…
        </div>
      ) : (
        <>
          <div className="mt-3 grid grid-cols-1 gap-x-3 gap-y-2 md:grid-cols-2 xl:grid-cols-4">
            {FIELDS.map((f) => (
              <div key={f.key}>
                <label className={LABEL_CLS} htmlFor={`sc-${f.key}`}>{f.label}</label>
                <input
                  id={`sc-${f.key}`}
                  type={f.type ?? 'text'}
                  value={form[f.key]}
                  onChange={set(f.key)}
                  placeholder={f.placeholder}
                  className={errors[f.key] ? INPUT_ERR_CLS : INPUT_CLS}
                />
                {errors[f.key] && <p className="mt-0.5 text-xs text-red-600">{errors[f.key][0]}</p>}
              </div>
            ))}
            <div className="md:col-span-2 xl:col-span-4">
              <label className={LABEL_CLS} htmlFor="sc-notes">Notes for users</label>
              <textarea
                id="sc-notes"
                rows={2}
                value={form.notes}
                onChange={set('notes')}
                placeholder="e.g. For urgent issues call; for everything else email with the document number and a screenshot."
                className={TEXTAREA_CLS}
              />
              {errors.notes && <p className="mt-0.5 text-xs text-red-600">{errors.notes[0]}</p>}
            </div>
          </div>

          <div className="mt-3 flex justify-end">
            <button
              type="submit"
              disabled={mutation.isPending}
              className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700 disabled:opacity-60"
            >
              {mutation.isPending ? <Loader2 size={13} className="animate-spin" /> : <Save size={13} />}
              Save Support Contact
            </button>
          </div>
        </>
      )}
    </form>
  )
}
