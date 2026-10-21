import { useState } from 'react'
import { Plus, Upload } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useDepartments, useGroupOptions, useLocationOptions } from '@/features/organization/api'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatLocalDate } from '@/lib/format'
import { employeeStatusTone, employmentTypeLabels, useEmployees, type Employee } from './api'
import { CreateEmployeeDialog } from './employee-form-dialog'
import { ImportEmployeesDialog } from './import-dialog'

const ALL = 'all'

function FilterSelect({
  label,
  value,
  onChange,
  options,
}: {
  label: string
  value: string
  onChange: (value: string) => void
  options: { value: string; label: string }[]
}) {
  return (
    <Select value={value || ALL} onValueChange={(v) => onChange(v === ALL ? '' : v)}>
      <SelectTrigger className="w-full sm:w-44" aria-label={label}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={ALL}>{label}: all</SelectItem>
        {options.map((o) => (
          <SelectItem key={o.value} value={o.value}>
            {o.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

export function EmployeesPage() {
  const can = useCan()
  const navigate = useNavigate()
  const prefs = useDisplayPreferences()
  const list = useListParams({
    defaultSort: 'name',
    filters: ['status', 'department_id', 'location_id', 'group_id', 'employment_type'],
  })
  const employees = useEmployees(list.params)
  const departments = useDepartments()
  const locations = useLocationOptions()
  const groups = useGroupOptions()
  const [creating, setCreating] = useState(false)
  const [importing, setImporting] = useState(false)

  const columns: Column<Employee>[] = [
    {
      key: 'name',
      header: 'Employee',
      sortKey: 'name',
      cell: (e) => (
        <div className="min-w-0">
          <Link
            to={`/employees/${String(e.id)}`}
            className="block truncate font-medium underline-offset-4 hover:underline focus-visible:underline"
            onClick={(event) => event.stopPropagation()}
          >
            {e.full_name}
          </Link>
          <p className="text-muted-foreground truncate text-xs">{e.job_title ?? employmentTypeLabels[e.employment_type]}</p>
        </div>
      ),
    },
    {
      key: 'code',
      header: 'Code',
      sortKey: 'employee_code',
      hideBelow: 'sm',
      cell: (e) => <span className="font-mono text-sm">{e.employee_code}</span>,
    },
    {
      key: 'department',
      header: 'Department',
      hideBelow: 'md',
      cell: (e) => e.department?.name ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'location',
      header: 'Location',
      hideBelow: 'lg',
      cell: (e) => e.location?.name ?? <span className="text-muted-foreground">—</span>,
    },
    {
      key: 'status',
      header: 'Status',
      sortKey: 'status',
      cell: (e) => <StatusBadge tone={employeeStatusTone[e.status]}>{e.status}</StatusBadge>,
    },
    {
      key: 'hire_date',
      header: 'Hired',
      sortKey: 'hire_date',
      hideBelow: 'lg',
      cell: (e) => <span className="text-muted-foreground text-sm tabular-nums">{formatLocalDate(e.hire_date, prefs)}</span>,
    },
  ]

  return (
    <>
      <PageHeader
        title="Employees"
        description={
          can('employees.view-all') ? 'Everyone in your organization.' : 'Employees in the departments and groups you manage.'
        }
        actions={
          <>
            {can('employees.import') && (
              <Button variant="outline" onClick={() => setImporting(true)}>
                <Upload /> Import CSV
              </Button>
            )}
            {can('employees.create') && (
              <Button onClick={() => setCreating(true)}>
                <Plus /> Add employee
              </Button>
            )}
          </>
        }
      />

      <div className="flex flex-col flex-wrap gap-2 sm:flex-row">
        <SearchInput value={list.search} onChange={list.setSearch} label="Search name, code or email" />
        <FilterSelect
          label="Department"
          value={list.filter('department_id')}
          onChange={(v) => list.setFilter('department_id', v)}
          options={(departments.data ?? []).map((d) => ({ value: String(d.id), label: d.path ?? d.name }))}
        />
        <FilterSelect
          label="Location"
          value={list.filter('location_id')}
          onChange={(v) => list.setFilter('location_id', v)}
          options={(locations.data?.data ?? []).map((l) => ({ value: String(l.id), label: l.name }))}
        />
        <FilterSelect
          label="Group"
          value={list.filter('group_id')}
          onChange={(v) => list.setFilter('group_id', v)}
          options={(groups.data?.data ?? []).map((g) => ({ value: String(g.id), label: g.name }))}
        />
        <FilterSelect
          label="Status"
          value={list.filter('status')}
          onChange={(v) => list.setFilter('status', v)}
          options={[
            { value: 'active', label: 'Active' },
            { value: 'inactive', label: 'Inactive' },
            { value: 'terminated', label: 'Terminated' },
          ]}
        />
      </div>

      <DataTable
        caption="Employees"
        columns={columns}
        list={list}
        query={employees}
        rowKey={(e) => e.id}
        onRowClick={(e) => void navigate(`/employees/${String(e.id)}`)}
        emptyTitle="No employees yet"
        emptyDescription={
          can('employees.create')
            ? 'Add employees one by one or import a CSV file.'
            : 'Nobody is assigned to what you manage yet.'
        }
        emptyAction={can('employees.create') ? <Button onClick={() => setCreating(true)}>Add employee</Button> : undefined}
      />

      <CreateEmployeeDialog open={creating} onOpenChange={setCreating} />
      <ImportEmployeesDialog open={importing} onOpenChange={setImporting} />
    </>
  )
}
