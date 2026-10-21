import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'

/** A user shown as a department/group manager (from UserService::summaries). */
export interface ManagerSummary {
  id: number
  name: string
  email: string
  status: 'active' | 'inactive'
}

/** Mirrors OrganizationProfileResource.php */
export interface OrganizationProfile {
  legal_name: string
  display_name: string | null
  registration_number: string | null
  address: string | null
  phone: string | null
  email: string | null
  updated_at: string | null
}

/** Mirrors LocationResource.php */
export interface Location {
  id: number
  name: string
  code: string
  address: string | null
  /** null = workspace timezone */
  timezone: string | null
  is_active: boolean
}

/** Mirrors DepartmentResource.php (tree listing adds depth/path/employees_count). */
export interface Department {
  id: number
  parent_id: number | null
  name: string
  code: string
  is_active: boolean
  depth?: number
  path?: string
  employees_count?: number
  managers?: ManagerSummary[]
}

/** Mirrors GroupResource.php */
export interface Group {
  id: number
  name: string
  code: string
  /** Lower number wins when an employee is in several groups. */
  priority: number
  description: string | null
  is_active: boolean
  members_count?: number
  managers?: ManagerSummary[]
}

const keys = {
  profile: ['tenant', 'organization'] as const,
  locations: ['tenant', 'locations'] as const,
  departments: ['tenant', 'departments'] as const,
  groups: ['tenant', 'groups'] as const,
}

// ---------------------------------------------------------------- profile

export function useOrganizationProfile() {
  return useQuery({
    queryKey: keys.profile,
    queryFn: ({ signal }) => tenantApi.get<Resource<OrganizationProfile>>('/organization', undefined, signal).then((r) => r.data),
  })
}

export function useUpdateOrganizationProfile() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (payload: Partial<OrganizationProfile>) =>
      tenantApi.put<Resource<OrganizationProfile>>('/organization', payload).then((r) => r.data),
    onSuccess: (profile) => queryClient.setQueryData(keys.profile, profile),
  })
}

// ---------------------------------------------------------------- locations

export function useLocations(params: ListParams) {
  return useQuery({
    queryKey: [...keys.locations, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<Location>>('/locations', params, signal),
    placeholderData: keepPreviousData,
  })
}

/** Active locations for pickers. */
export function useLocationOptions() {
  return useLocations({ is_active: 1, per_page: 100, sort: 'name' })
}

export type LocationPayload = Omit<Location, 'id'>

export function useSaveLocation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: LocationPayload }) =>
      (id
        ? tenantApi.put<Resource<Location>>(`/locations/${id}`, payload)
        : tenantApi.post<Resource<Location>>('/locations', payload)
      ).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.locations }),
  })
}

export function useDeleteLocation() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/locations/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.locations }),
  })
}

// ---------------------------------------------------------------- departments

/** The whole department tree in display order (not paginated). */
export function useDepartments(active?: boolean) {
  return useQuery({
    queryKey: [...keys.departments, { active }],
    queryFn: ({ signal }) =>
      tenantApi
        .get<{ data: Department[] }>('/departments', active === undefined ? undefined : { is_active: active }, signal)
        .then((r) => r.data),
  })
}

export interface DepartmentPayload {
  name: string
  code: string
  parent_id: number | null
  is_active: boolean
}

export function useSaveDepartment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: DepartmentPayload }) =>
      (id
        ? tenantApi.put<Resource<Department>>(`/departments/${id}`, payload)
        : tenantApi.post<Resource<Department>>('/departments', payload)
      ).then((r) => r.data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.departments }),
  })
}

export function useDeleteDepartment() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/departments/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.departments }),
  })
}

// ---------------------------------------------------------------- groups

export function useGroups(params: ListParams) {
  return useQuery({
    queryKey: [...keys.groups, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<Group>>('/groups', params, signal),
    placeholderData: keepPreviousData,
  })
}

/** Active groups for pickers, in precedence order. */
export function useGroupOptions() {
  return useGroups({ is_active: 1, per_page: 100, sort: 'priority' })
}

export type GroupPayload = Pick<Group, 'name' | 'code' | 'priority' | 'description' | 'is_active'>

export function useSaveGroup() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ id, payload }: { id?: number; payload: GroupPayload }) =>
      (id ? tenantApi.put<Resource<Group>>(`/groups/${id}`, payload) : tenantApi.post<Resource<Group>>('/groups', payload)).then(
        (r) => r.data,
      ),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.groups }),
  })
}

export function useDeleteGroup() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/groups/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: keys.groups }),
  })
}

// ---------------------------------------------------------------- managers

export function useSetManagers() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({ kind, id, userIds }: { kind: 'departments' | 'groups'; id: number; userIds: number[] }) =>
      tenantApi.put<Resource<Department | Group>>(`/${kind}/${id}/managers`, { user_ids: userIds }).then((r) => r.data),
    onSuccess: (_data, { kind }) =>
      queryClient.invalidateQueries({ queryKey: kind === 'departments' ? keys.departments : keys.groups }),
  })
}
