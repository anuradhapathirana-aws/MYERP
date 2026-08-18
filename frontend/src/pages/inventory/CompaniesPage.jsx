import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus } from 'lucide-react'
import { deleteCompany, getCompanies } from '../../api/companies'
import Breadcrumb from '../../components/Breadcrumb'
import { confirmDelete, showError, showSuccess } from '../../utils/alerts'
import { usePermissions } from '../../hooks/usePermissions'
import { ViewBtn, EditBtn, DeleteBtn } from '../../components/ui/ActionButtons'
import Pagination from '../../components/ui/Pagination'

const CRUMBS = [
  { label: 'Inventory', to: '/inventory/products' },
  { label: 'Companies' },
]

export default function CompaniesPage() {
  const [page, setPage] = useState(1)
  const queryClient = useQueryClient()
  const { can } = usePermissions()

  const { data, isLoading, isError } = useQuery({
    queryKey: ['companies', page],
    queryFn:  () => getCompanies(page),
    placeholderData: (prev) => prev,
  })

  const deleteMutation = useMutation({
    mutationFn: deleteCompany,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['companies'] })
      showSuccess('Company deleted.')
    },
    onError: () => showError('Failed to delete. The company may be in use.'),
  })

  const handleDelete = async (id, name) => {
    const ok = await confirmDelete(name)
    if (ok) deleteMutation.mutate(id)
  }

  const meta = data?.meta
  const rows = data?.data ?? []

  return (
    <div className="w-full">
      <div className="flex items-start justify-between">
        <div>
          <h1 className="text-xl font-bold leading-none text-slate-800">Companies</h1>
          <Breadcrumb crumbs={CRUMBS} />
        </div>
        {can('create_companies') && (
          <Link
            to="/inventory/companies/create"
            className="flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-1.5 text-sm font-semibold text-white transition-colors hover:bg-indigo-700"
          >
            <Plus size={14} strokeWidth={2.5} />
            New Company
          </Link>
        )}
      </div>
      <div className="mt-2 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        {isLoading && (
          <div className="flex items-center justify-center py-14 text-sm text-slate-400">Loading…</div>
        )}
        {isError && (
          <div className="flex items-center justify-center py-14 text-sm text-red-500">
            Failed to load companies.
          </div>
        )}

        {!isLoading && !isError && (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b border-slate-200 bg-slate-50 text-left">
                    <th className="w-8 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">#</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Company Name</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Type</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Industry</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">City</th>
                    <th className="px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Mobile</th>
                    <th className="w-28 px-3 py-2 font-semibold uppercase tracking-wider text-slate-500">Created</th>
                    <th className="w-20 px-3 py-2 text-right font-semibold uppercase tracking-wider text-slate-500">Actions</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {rows.length === 0 ? (
                    <tr>
                      <td colSpan={8} className="px-4 py-8 text-center text-sm text-slate-400">
                        No companies yet.{' '}
                        {can('create_companies') && (
                          <Link to="/inventory/companies/create" className="font-medium text-indigo-600 hover:underline">
                            Create the first one.
                          </Link>
                        )}
                      </td>
                    </tr>
                  ) : (
                    rows.map((company, i) => (
                      <tr key={company.id} className="transition-colors hover:bg-slate-50">
                        <td className="px-3 py-2 text-slate-400">
                          {(page - 1) * (meta?.per_page ?? 25) + i + 1}
                        </td>
                        <td className="px-3 py-2 font-medium text-slate-800">
                          <Link
                            to={`/inventory/companies/${company.id}`}
                            className="hover:text-indigo-600 hover:underline"
                          >
                            {company.company_name}
                          </Link>
                        </td>
                        <td className="px-3 py-2 text-slate-500">{company.company_type ?? <span className="italic text-slate-300">—</span>}</td>
                        <td className="px-3 py-2 text-slate-500">
                          {company.industry?.name ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {company.city ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="px-3 py-2 text-slate-500">
                          {company.company_mobile ?? <span className="italic text-slate-300">—</span>}
                        </td>
                        <td className="whitespace-nowrap px-3 py-2 text-slate-400">
                          {new Date(company.created_at).toLocaleDateString()}
                        </td>
                        <td className="px-3 py-2">
                          <div className="flex items-center justify-end gap-1">
                            <ViewBtn to={`/inventory/companies/${company.id}`} />
                            {can('edit_companies') && (
                              <EditBtn to={`/inventory/companies/${company.id}/edit`} />
                            )}
                            {can('delete_companies') && (
                              <DeleteBtn onClick={() => handleDelete(company.id, company.company_name)} disabled={deleteMutation.isPending} />
                            )}
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>

            <Pagination meta={meta} page={page} onPageChange={setPage} />
          </>
        )}
      </div>
    </div>
  )
}
