import { SearchInput } from '@/components/search-input'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useDepartments, useGroupOptions, useLocationOptions } from '@/features/organization/api'
import type { ListState } from '@/hooks/use-list-params'

function OptionFilter({
  id,
  label,
  value,
  onChange,
  all,
  options,
}: {
  id: string
  label: string
  value: string
  onChange: (value: string) => void
  all: string
  options: { value: string; label: string }[]
}) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      <Select value={value || 'all'} onValueChange={(v) => onChange(v === 'all' ? '' : v)}>
        <SelectTrigger id={id} className="w-full sm:w-48">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="all">{all}</SelectItem>
          {options.map((o) => (
            <SelectItem key={o.value} value={o.value}>
              {o.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </div>
  )
}

/**
 * One row of report filters above the content: range, department,
 * location, group and (optionally) a name search. Which employees appear
 * is always decided by the server from the viewer's role.
 */
export function ReportFilters({
  list,
  range,
  search = true,
  location = true,
  group = true,
  month,
}: {
  list: ListState
  range?: { from: string; to: string; error: string | null }
  search?: boolean
  location?: boolean
  group?: boolean
  /** For monthly reports: a month picker instead of the range. */
  month?: { value: string; onChange: (value: string) => void }
}) {
  const departments = useDepartments(true)
  const locations = useLocationOptions()
  const groups = useGroupOptions()

  return (
    <div className="space-y-2">
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
        {month && (
          <div className="grid gap-1.5">
            <Label htmlFor="report-month">Month</Label>
            <Input
              id="report-month"
              type="month"
              className="w-full sm:w-44"
              value={month.value}
              onChange={(e) => {
                if (e.target.value) month.onChange(e.target.value)
              }}
            />
          </div>
        )}
        {range && (
          <>
            <div className="grid gap-1.5">
              <Label htmlFor="report-from">From</Label>
              <Input
                id="report-from"
                type="date"
                className="w-full sm:w-40"
                value={range.from}
                onChange={(e) => list.setFilter('from', e.target.value)}
                aria-invalid={range.error !== null}
              />
            </div>
            <div className="grid gap-1.5">
              <Label htmlFor="report-to">To</Label>
              <Input
                id="report-to"
                type="date"
                className="w-full sm:w-40"
                value={range.to}
                min={range.from}
                onChange={(e) => list.setFilter('to', e.target.value)}
                aria-invalid={range.error !== null}
                aria-describedby={range.error ? 'report-range-error' : undefined}
              />
            </div>
          </>
        )}
        <OptionFilter
          id="report-department"
          label="Department"
          all="All departments"
          value={list.filter('department_id')}
          onChange={(v) => list.setFilter('department_id', v)}
          options={(departments.data ?? []).map((d) => ({ value: String(d.id), label: d.path ?? d.name }))}
        />
        {location && (
          <OptionFilter
            id="report-location"
            label="Location"
            all="All locations"
            value={list.filter('location_id')}
            onChange={(v) => list.setFilter('location_id', v)}
            options={(locations.data?.data ?? []).map((l) => ({ value: String(l.id), label: l.name }))}
          />
        )}
        {group && (
          <OptionFilter
            id="report-group"
            label="Group"
            all="All groups"
            value={list.filter('group_id')}
            onChange={(v) => list.setFilter('group_id', v)}
            options={(groups.data?.data ?? []).map((g) => ({ value: String(g.id), label: g.name }))}
          />
        )}
        {search && <SearchInput value={list.search} onChange={list.setSearch} label="Search employees" />}
      </div>
      {range?.error && (
        <p id="report-range-error" role="alert" className="text-destructive text-sm font-medium">
          {range.error}
        </p>
      )}
    </div>
  )
}
