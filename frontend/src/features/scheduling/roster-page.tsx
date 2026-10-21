import { useState } from 'react'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { Link } from 'react-router'
import { SearchInput } from '@/components/search-input'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { useDepartments, useGroupOptions } from '@/features/organization/api'
import { useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { formatLocalDate, todayIn } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useRoster } from './api'
import { DayContent } from './day-cell'
import { dayCellClass, dayDescription } from './day-status'
import { addDays, dayOfMonth, weekdayLabel, weekStartOf } from './dates'

const ALL = 'all'
const RANGES = [7, 14] as const

/** Employees × days: who is scheduled when (read-only). */
export function RosterPage() {
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const [from, setFrom] = useState(() => weekStartOf(today, workspace.week_start))
  const [days, setDays] = useState<(typeof RANGES)[number]>(7)
  const [page, setPage] = useState(1)
  const [department, setDepartment] = useState('')
  const [group, setGroup] = useState('')
  const [search, setSearch] = useState('')
  const departments = useDepartments()
  const groups = useGroupOptions()
  const roster = useRoster({ from, days, page, department_id: department || undefined, group_id: group || undefined, search })

  const shift = (delta: number) => {
    setFrom((f) => addDays(f, delta))
    setPage(1)
  }

  return (
    <>
      <PageHeader title="Roster" description="Who is scheduled when, for the people you can see." />

      <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
        <div className="flex items-center gap-1">
          <Button variant="outline" size="icon" className="size-8" aria-label="Earlier" onClick={() => shift(-days)}>
            <ChevronLeft />
          </Button>
          <p className="min-w-48 text-center text-sm font-medium tabular-nums" aria-live="polite">
            {formatLocalDate(from, prefs)} – {formatLocalDate(addDays(from, days - 1), prefs)}
          </p>
          <Button variant="outline" size="icon" className="size-8" aria-label="Later" onClick={() => shift(days)}>
            <ChevronRight />
          </Button>
          <Button variant="ghost" size="sm" onClick={() => setFrom(weekStartOf(today, workspace.week_start))}>
            This week
          </Button>
          <Select value={String(days)} onValueChange={(v) => setDays(Number(v) as (typeof RANGES)[number])}>
            <SelectTrigger size="sm" className="w-28" aria-label="Days shown">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {RANGES.map((r) => (
                <SelectItem key={r} value={String(r)}>
                  {r} days
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="flex flex-col gap-2 sm:flex-row lg:ml-auto">
          <SearchInput
            value={search}
            onChange={(v) => {
              setSearch(v)
              setPage(1)
            }}
            label="Search employees"
          />
          <Select
            value={department || ALL}
            onValueChange={(v) => {
              setDepartment(v === ALL ? '' : v)
              setPage(1)
            }}
          >
            <SelectTrigger className="w-full sm:w-44" aria-label="Filter by department">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All departments</SelectItem>
              {(departments.data ?? []).map((d) => (
                <SelectItem key={d.id} value={String(d.id)}>
                  {d.path ?? d.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select
            value={group || ALL}
            onValueChange={(v) => {
              setGroup(v === ALL ? '' : v)
              setPage(1)
            }}
          >
            <SelectTrigger className="w-full sm:w-40" aria-label="Filter by group">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value={ALL}>All groups</SelectItem>
              {(groups.data?.data ?? []).map((g) => (
                <SelectItem key={g.id} value={String(g.id)}>
                  {g.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {roster.isError ? (
        <ErrorState error={roster.error} onRetry={() => roster.refetch()} />
      ) : roster.isPending ? (
        <Skeleton className="h-96 w-full" />
      ) : roster.data.data.length === 0 ? (
        <EmptyState title="Nobody to show" description="No visible employees match these filters in this period." />
      ) : (
        <>
          <div className={cn('overflow-x-auto rounded-lg border', roster.isPlaceholderData && 'opacity-60')}>
            <table className="w-full min-w-max border-collapse text-sm">
              <caption className="sr-only">Roster</caption>
              <thead>
                <tr className="bg-muted/40 border-b">
                  <th
                    scope="col"
                    className="bg-muted/90 sticky left-0 z-10 min-w-48 px-3 py-2 text-left font-medium backdrop-blur"
                  >
                    Employee
                  </th>
                  {roster.data.dates.map((date) => (
                    <th
                      key={date}
                      scope="col"
                      className={cn('min-w-28 px-2 py-2 text-left text-xs font-medium', date === today && 'text-primary')}
                    >
                      {weekdayLabel(date)} {dayOfMonth(date)}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {roster.data.data.map((row) => (
                  <tr key={row.employee.id} className="border-b last:border-0">
                    <th scope="row" className="bg-background/95 sticky left-0 z-10 px-3 py-2 text-left font-normal backdrop-blur">
                      <Link
                        to={`/employees/${String(row.employee.id)}`}
                        className="font-medium underline-offset-4 hover:underline"
                      >
                        {row.employee.name}
                      </Link>
                      <p className="text-muted-foreground font-mono text-xs">{row.employee.employee_code}</p>
                    </th>
                    {row.days.map((day) => (
                      <td
                        key={day.date}
                        className={cn('border-l px-2 py-1.5 align-top', dayCellClass[day.status])}
                        title={dayDescription(day, prefs)}
                      >
                        <span className="sr-only">{dayDescription(day, prefs)}</span>
                        <div aria-hidden>
                          <DayContent day={day} prefs={prefs} />
                        </div>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="text-muted-foreground flex items-center justify-between text-sm">
            <p>
              {roster.data.meta.from}–{roster.data.meta.to} of {roster.data.meta.total} employees
            </p>
            <div className="flex items-center gap-2">
              <Button
                variant="outline"
                size="icon"
                className="size-8"
                aria-label="Previous page"
                disabled={page <= 1}
                onClick={() => setPage((p) => p - 1)}
              >
                <ChevronLeft />
              </Button>
              <span className="tabular-nums">
                {roster.data.meta.current_page} / {roster.data.meta.last_page}
              </span>
              <Button
                variant="outline"
                size="icon"
                className="size-8"
                aria-label="Next page"
                disabled={page >= roster.data.meta.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                <ChevronRight />
              </Button>
            </div>
          </div>
        </>
      )}
    </>
  )
}
