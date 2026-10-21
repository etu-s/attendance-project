import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ApiError, tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

/** Mirrors App\Modules\Organization\Enums\EmploymentType */
export const employmentTypes = ['full_time', 'part_time', 'contract', 'temporary', 'intern'] as const
export type EmploymentType = (typeof employmentTypes)[number]

export const employmentTypeLabels: Record<EmploymentType, string> = {
  full_time: 'Full time',
  part_time: 'Part time',
  contract: 'Contract',
  temporary: 'Temporary',
  intern: 'Intern',
}

/** Mirrors App\Modules\Organization\Enums\EmployeeStatus */
export type EmployeeStatus = 'active' | 'inactive' | 'terminated'

export const employeeStatusTone: Record<EmployeeStatus, 'success' | 'warning' | 'neutral'> = {
  active: 'success',
  inactive: 'warning',
  terminated: 'neutral',
}

/** Mirrors EmployeeResource.php — department/location/groups are as of today. */
export interface Employee {
  id: number
  employee_code: string
  first_name: string
  last_name: string
  full_name: string
  email: string | null
  phone: string | null
  job_title: string | null
  employment_type: EmploymentType
  status: EmployeeStatus
  hire_date: string
  termination_date: string | null
  user_id: number | null
  department: { id: number; name: string } | null
  location: { id: number; name: string; timezone: string | null } | null
  groups: { id: number; name: string; priority: number; since: string }[]
  created_at: string
  updated_at: string
}

/** Mirrors EmployeeHistoryResource.php */
export interface EmployeeHistory {
  assignments: {
    id: number
    department: { id: number; name: string }
    location: { id: number; name: string } | null
    effective_from: string
    effective_to: string | null
  }[]
  memberships: {
    id: number
    group: { id: number; name: string; priority: number }
    effective_from: string
    effective_to: string | null
  }[]
}

/** Mirrors EmployeeImportResource.php */
export interface EmployeeImport {
  id: number
  status: 'pending' | 'processing' | 'completed' | 'failed'
  original_name: string | null
  total_rows: number
  created_count: number
  failed_count: number
  errors: { row: number; code: string | null; messages: string[] }[]
  started_at: string | null
  finished_at: string | null
  created_at: string
}

const employeesKey = ['tenant', 'employees'] as const

export function useEmployees(params: ListParams) {
  return useQuery({
    queryKey: [...employeesKey, 'list', params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<Employee>>('/employees', params, signal),
    placeholderData: keepPreviousData,
  })
}

export function useEmployee(id: number) {
  return useQuery({
    queryKey: [...employeesKey, 'detail', id],
    queryFn: ({ signal }) => tenantApi.get<Resource<Employee>>(`/employees/${id}`, undefined, signal).then((r) => r.data),
  })
}

export function useEmployeeHistory(id: number) {
  return useQuery({
    queryKey: [...employeesKey, 'history', id],
    queryFn: ({ signal }) =>
      tenantApi.get<Resource<EmployeeHistory>>(`/employees/${id}/history`, undefined, signal).then((r) => r.data),
  })
}

/** The signed-in user's own record; null when the login is not linked to an employee. */
export function useMyEmployee() {
  return useQuery({
    queryKey: [...employeesKey, 'me'],
    queryFn: async ({ signal }) => {
      try {
        return (await tenantApi.get<Resource<Employee>>('/me/employee', undefined, signal)).data
      } catch (error) {
        if (error instanceof ApiError && error.status === 404) return null
        throw error
      }
    },
  })
}

function useInvalidateEmployees() {
  const queryClient = useQueryClient()

  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: employeesKey }),
      // Headcounts on departments and groups change with employees.
      queryClient.invalidateQueries({ queryKey: ['tenant', 'departments'] }),
      queryClient.invalidateQueries({ queryKey: ['tenant', 'groups'] }),
    ])
}

export interface CreateEmployeePayload {
  employee_code: string
  first_name: string
  last_name: string
  email: string | null
  phone: string | null
  job_title: string | null
  employment_type: EmploymentType
  hire_date: string
  department_id: number
  location_id: number | null
  group_ids: number[]
  create_login: boolean
  login_password?: string
}

export type UpdateEmployeePayload = Partial<
  Pick<
    CreateEmployeePayload,
    'employee_code' | 'first_name' | 'last_name' | 'email' | 'phone' | 'job_title' | 'employment_type' | 'hire_date'
  >
> & { status?: 'active' | 'inactive' }

export function useCreateEmployee() {
  const invalidate = useInvalidateEmployees()

  return useMutation({
    mutationFn: (payload: CreateEmployeePayload) => tenantApi.post<Resource<Employee>>('/employees', payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useUpdateEmployee(id: number) {
  const invalidate = useInvalidateEmployees()

  return useMutation({
    mutationFn: (payload: UpdateEmployeePayload) =>
      tenantApi.put<Resource<Employee>>(`/employees/${id}`, payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeleteEmployee() {
  const invalidate = useInvalidateEmployees()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/employees/${id}`),
    onSuccess: invalidate,
  })
}

/** Dated lifecycle actions on one employee (transfer, terminate, groups, ...). */
export function useEmployeeAction(id: number) {
  const invalidate = useInvalidateEmployees()

  return useMutation({
    mutationFn: ({ path, body, method = 'post' }: { path: string; body: Record<string, unknown>; method?: 'post' | 'put' }) =>
      (method === 'put'
        ? tenantApi.put<Resource<Employee>>(`/employees/${id}/${path}`, body)
        : tenantApi.post<Resource<Employee>>(`/employees/${id}/${path}`, body)
      ).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function uploadEmployeeImport(file: File): Promise<EmployeeImport> {
  const form = new FormData()
  form.append('file', file)

  return tenantApi.post<Resource<EmployeeImport>>('/employees/import', form).then((r) => r.data)
}

/** Polls an import until it is finished. */
export function useEmployeeImport(id: number | null) {
  const invalidate = useInvalidateEmployees()

  return useQuery({
    queryKey: [...employeesKey, 'import', id],
    enabled: id !== null,
    queryFn: async ({ signal }) => {
      const data = (await tenantApi.get<Resource<EmployeeImport>>(`/employee-imports/${String(id)}`, undefined, signal)).data
      if (data.status === 'completed') void invalidate()
      return data
    },
    refetchInterval: (query) => (query.state.data && ['completed', 'failed'].includes(query.state.data.status) ? false : 1500),
  })
}
