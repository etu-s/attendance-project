import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'
import type { ListParams } from '@/lib/api/types'

export interface ListState {
  params: ListParams
  page: number
  perPage: number
  sort: string
  search: string
  filter: (name: string) => string
  setPage: (page: number) => void
  setPerPage: (perPage: number) => void
  setSearch: (search: string) => void
  setFilter: (name: string, value: string) => void
  toggleSort: (column: string) => void
  reset: () => void
  hasFilters: boolean
}

/**
 * Server-side table state (page, per_page, sort, search, filters) kept in the
 * URL, so lists survive reloads and can be shared. Changing anything other
 * than the page sends the user back to page 1.
 */
export function useListParams(options: { defaultSort: string; filters?: string[]; defaultPerPage?: number }): ListState {
  const [searchParams, setSearchParams] = useSearchParams()
  const { defaultSort, filters = [], defaultPerPage = 15 } = options

  const page = Math.max(1, Number(searchParams.get('page') ?? 1) || 1)
  const perPage = Number(searchParams.get('per_page') ?? defaultPerPage) || defaultPerPage
  const sort = searchParams.get('sort') ?? defaultSort
  const search = searchParams.get('search') ?? ''
  const filterKey = filters.join(',')

  const update = useCallback(
    (changes: Record<string, string | null>, keepPage = false) => {
      setSearchParams(
        (current) => {
          const next = new URLSearchParams(current)
          for (const [key, value] of Object.entries(changes)) {
            if (value === null || value === '') {
              next.delete(key)
            } else {
              next.set(key, value)
            }
          }
          if (!keepPage) {
            next.delete('page')
          }

          return next
        },
        { replace: true },
      )
    },
    [setSearchParams],
  )

  const params = useMemo<ListParams>(() => {
    const result: ListParams = { page, per_page: perPage, sort }
    if (search) {
      result.search = search
    }
    for (const name of filterKey ? filterKey.split(',') : []) {
      const value = searchParams.get(name)
      if (value) {
        result[name] = value
      }
    }

    return result
  }, [page, perPage, sort, search, filterKey, searchParams])

  return {
    params,
    page,
    perPage,
    sort,
    search,
    filter: (name) => searchParams.get(name) ?? '',
    setPage: (next) => update({ page: next > 1 ? String(next) : null }, true),
    setPerPage: (next) => update({ per_page: String(next) }),
    setSearch: (next) => update({ search: next }),
    setFilter: (name, value) => update({ [name]: value }),
    toggleSort: (column) => {
      const next = sort === column ? `-${column}` : column
      update({ sort: next === defaultSort ? null : next })
    },
    reset: () => update(Object.fromEntries(['search', ...filters].map((name) => [name, null]))),
    hasFilters: search !== '' || filters.some((name) => searchParams.has(name)),
  }
}
