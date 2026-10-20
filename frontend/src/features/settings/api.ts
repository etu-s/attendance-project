import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { sessionKey } from '@/features/tenant-auth/api'
import { tenantApi } from '@/lib/api/client'

export type SettingValue = string | number | boolean

/** Mirrors SettingResource.php */
export interface Setting {
  key: string
  group: string
  type: 'string' | 'integer' | 'boolean'
  label: string
  description: string | null
  options: (string | number)[] | null
  default: SettingValue
  value: SettingValue | null
}

const settingsKey = ['tenant', 'settings'] as const

export function useSettings() {
  return useQuery({
    queryKey: settingsKey,
    queryFn: ({ signal }) => tenantApi.get<{ data: Setting[] }>('/settings', undefined, signal).then((r) => r.data),
  })
}

export function useUpdateSettings() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (changes: { key: string; value: SettingValue }[]) =>
      tenantApi.put<{ data: Setting[] }>('/settings', { settings: changes }).then((r) => r.data),
    onSuccess: (settings) => {
      queryClient.setQueryData(settingsKey, settings)
      // Timezone and formats are part of the session payload used for display.
      void queryClient.invalidateQueries({ queryKey: sessionKey })
    },
  })
}
