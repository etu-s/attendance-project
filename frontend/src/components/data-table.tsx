import type { ReactNode } from 'react'
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { EmptyState, ErrorState } from '@/components/states'
import type { ListState } from '@/hooks/use-list-params'
import type { Paginated } from '@/lib/api/types'
import { cn } from '@/lib/utils'

export interface Column<T> {
  key: string
  header: string
  cell: (row: T) => ReactNode
  /** Server-side sort key; omit for unsortable columns. */
  sortKey?: string
  className?: string
  /** Hide on small screens to keep tables readable on mobile. */
  hideBelow?: 'sm' | 'md' | 'lg'
}

interface DataTableProps<T> {
  columns: Column<T>[]
  list: ListState
  query: {
    data: Paginated<T> | undefined
    isPending: boolean
    isError: boolean
    error: unknown
    refetch: () => unknown
    isPlaceholderData?: boolean
  }
  rowKey: (row: T) => string | number
  emptyTitle: string
  emptyDescription?: string
  emptyAction?: ReactNode
  onRowClick?: (row: T) => void
  caption: string
}

const hideClass = { sm: 'hidden sm:table-cell', md: 'hidden md:table-cell', lg: 'hidden lg:table-cell' }

function SortIcon({ active, descending }: { active: boolean; descending: boolean }) {
  if (!active) {
    return <ArrowUpDown className="size-3.5 opacity-40" aria-hidden />
  }

  return descending ? <ArrowDown className="size-3.5" aria-hidden /> : <ArrowUp className="size-3.5" aria-hidden />
}

export function DataTable<T>({
  columns,
  list,
  query,
  rowKey,
  emptyTitle,
  emptyDescription,
  emptyAction,
  onRowClick,
  caption,
}: DataTableProps<T>) {
  if (query.isError) {
    return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  }

  const rows = query.data?.data ?? []
  const meta = query.data?.meta
  const sortColumn = list.sort.replace(/^-/, '')
  const descending = list.sort.startsWith('-')

  return (
    <div className="space-y-3">
      <div className={cn('overflow-x-auto rounded-lg border', query.isPlaceholderData && 'opacity-60 transition-opacity')}>
        <Table>
          <caption className="sr-only">{caption}</caption>
          <TableHeader>
            <TableRow className="hover:bg-transparent">
              {columns.map((column) => {
                const active = column.sortKey === sortColumn
                return (
                  <TableHead
                    key={column.key}
                    className={cn(column.className, column.hideBelow && hideClass[column.hideBelow])}
                    aria-sort={active ? (descending ? 'descending' : 'ascending') : undefined}
                  >
                    {column.sortKey ? (
                      <button
                        type="button"
                        onClick={() => list.toggleSort(column.sortKey as string)}
                        className="hover:bg-accent focus-visible:ring-ring -ml-2 inline-flex items-center gap-1.5 rounded px-2 py-1 focus-visible:ring-2 focus-visible:outline-none"
                      >
                        {column.header}
                        <SortIcon active={active} descending={descending} />
                      </button>
                    ) : (
                      column.header
                    )}
                  </TableHead>
                )
              })}
            </TableRow>
          </TableHeader>
          <TableBody>
            {query.isPending
              ? Array.from({ length: Math.min(list.perPage, 8) }, (_, index) => (
                  <TableRow key={`skeleton-${index}`}>
                    {columns.map((column) => (
                      <TableCell key={column.key} className={cn(column.hideBelow && hideClass[column.hideBelow])}>
                        <Skeleton className="h-4 w-full max-w-40" />
                      </TableCell>
                    ))}
                  </TableRow>
                ))
              : rows.map((row) => (
                  <TableRow
                    key={rowKey(row)}
                    onClick={onRowClick ? () => onRowClick(row) : undefined}
                    className={cn(onRowClick && 'cursor-pointer')}
                  >
                    {columns.map((column) => (
                      <TableCell
                        key={column.key}
                        className={cn(column.className, column.hideBelow && hideClass[column.hideBelow])}
                      >
                        {column.cell(row)}
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
          </TableBody>
        </Table>

        {!query.isPending && rows.length === 0 && (
          <EmptyState
            className="border-0 border-t"
            title={list.hasFilters ? 'No matches' : emptyTitle}
            description={list.hasFilters ? 'Try a different search or clear the filters.' : emptyDescription}
            action={
              list.hasFilters ? (
                <Button variant="outline" size="sm" onClick={list.reset}>
                  Clear filters
                </Button>
              ) : (
                emptyAction
              )
            }
          />
        )}
      </div>

      {meta && meta.total > 0 && (
        <div className="text-muted-foreground flex flex-col-reverse items-center justify-between gap-3 text-sm sm:flex-row">
          <p aria-live="polite">
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total}
          </p>
          <div className="flex items-center gap-2">
            <Select value={String(list.perPage)} onValueChange={(value) => list.setPerPage(Number(value))}>
              <SelectTrigger size="sm" className="w-[110px]" aria-label="Rows per page">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {[15, 25, 50, 100].map((size) => (
                  <SelectItem key={size} value={String(size)}>
                    {size} / page
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              variant="outline"
              size="icon"
              className="size-8"
              onClick={() => list.setPage(meta.current_page - 1)}
              disabled={meta.current_page <= 1}
              aria-label="Previous page"
            >
              <ChevronLeft />
            </Button>
            <span className="min-w-16 text-center tabular-nums">
              {meta.current_page} / {meta.last_page}
            </span>
            <Button
              variant="outline"
              size="icon"
              className="size-8"
              onClick={() => list.setPage(meta.current_page + 1)}
              disabled={meta.current_page >= meta.last_page}
              aria-label="Next page"
            >
              <ChevronRight />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
