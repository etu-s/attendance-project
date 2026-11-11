import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { tenantApi } from '@/lib/api/client'
import type { ListParams, Paginated, Resource } from '@/lib/api/types'
import type { User } from './types'
import type { UserPayload } from './schemas'

const usersKey = ['tenant', 'users'] as const

export function useUsers(params: ListParams) {
  return useQuery({
    queryKey: [...usersKey, params],
    queryFn: ({ signal }) => tenantApi.get<Paginated<User>>('/users', params, signal),
    placeholderData: keepPreviousData,
  })
}

function useInvalidateUsers() {
  const queryClient = useQueryClient()

  return () => queryClient.invalidateQueries({ queryKey: usersKey })
}

export function useCreateUser() {
  const invalidate = useInvalidateUsers()

  return useMutation({
    mutationFn: (payload: UserPayload) => tenantApi.post<Resource<User>>('/users', payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useUpdateUser() {
  const invalidate = useInvalidateUsers()

  return useMutation({
    mutationFn: ({ id, payload }: { id: number; payload: Partial<UserPayload> }) =>
      tenantApi.put<Resource<User>>(`/users/${id}`, payload).then((r) => r.data),
    onSuccess: invalidate,
  })
}

export function useDeleteUser() {
  const invalidate = useInvalidateUsers()

  return useMutation({
    mutationFn: (id: number) => tenantApi.delete<undefined>(`/users/${id}`),
    onSuccess: invalidate,
  })
}

/** (Re)sends an invitation email to set a password; the new link replaces earlier ones. */
export function useInviteUser() {
  return useMutation({
    mutationFn: (id: number) => tenantApi.post<{ message: string }>(`/users/${String(id)}/invitation`),
  })
}
