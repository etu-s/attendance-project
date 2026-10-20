import { MutationCache, QueryCache, QueryClient, type QueryKey } from '@tanstack/react-query'
import { ApiError } from '@/lib/api/client'

/**
 * @param sessionKey  query holding the signed-in session. Any 401 from another
 *                    request (session expired, signed out elsewhere) resets it
 *                    to null, which sends the router back to the login page.
 */
export function createQueryClient(sessionKey: QueryKey): QueryClient {
  const onError = (error: unknown) => {
    if (error instanceof ApiError && error.status === 401) {
      queryClient.setQueryData(sessionKey, null)
    }
  }

  const queryClient: QueryClient = new QueryClient({
    queryCache: new QueryCache({ onError }),
    mutationCache: new MutationCache({ onError }),
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        refetchOnWindowFocus: false,
        // Client errors (401/403/404/422) will not fix themselves; only retry server/network failures.
        retry: (failureCount, error) => {
          if (error instanceof ApiError && error.status < 500) {
            return false
          }

          return failureCount < 2
        },
      },
      mutations: {
        retry: false,
      },
    },
  })

  return queryClient
}
