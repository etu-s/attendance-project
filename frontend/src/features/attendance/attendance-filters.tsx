import { SearchInput } from '@/components/search-input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useDepartments } from '@/features/organization/api'
import type { ListState } from '@/hooks/use-list-params'
import { attendanceStatuses } from './api'
import { attendanceStatusLabel } from './status'

/** Search, department and status filters bound to the list's URL state. */
export function AttendanceFilters({ list, status = true }: { list: ListState; status?: boolean }) {
  const departments = useDepartments(true)

  return (
    <>
      <SearchInput value={list.search} onChange={list.setSearch} label="Search employees" />
      <div className="grid gap-1.5">
        <Label htmlFor="attendance-department">Department</Label>
        <Select
          value={list.filter('department_id') || 'all'}
          onValueChange={(v) => list.setFilter('department_id', v === 'all' ? '' : v)}
        >
          <SelectTrigger id="attendance-department" className="w-48">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All departments</SelectItem>
            {(departments.data ?? []).map((d) => (
              <SelectItem key={d.id} value={String(d.id)}>
                {d.path ?? d.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      {status && (
        <div className="grid gap-1.5">
          <Label htmlFor="attendance-status">Status</Label>
          <Select value={list.filter('status') || 'all'} onValueChange={(v) => list.setFilter('status', v === 'all' ? '' : v)}>
            <SelectTrigger id="attendance-status" className="w-44">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All statuses</SelectItem>
              {attendanceStatuses.map((s) => (
                <SelectItem key={s} value={s}>
                  {attendanceStatusLabel[s]}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      )}
    </>
  )
}
