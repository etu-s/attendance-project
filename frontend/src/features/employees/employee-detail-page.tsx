import { useState, type ReactNode } from 'react'
import { ArrowLeft, ArrowRightLeft, KeyRound, MoreHorizontal, Pencil, Plus, RotateCcw, Trash2, UserMinus } from 'lucide-react'
import { Link, useNavigate, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { EmptyState, ErrorState } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { EmployeeAttendanceTab } from '@/features/attendance/employee-attendance-tab'
import { EmployeeLeaveTab } from '@/features/leave/employee-leave-tab'
import { EmployeeScheduleTab } from '@/features/scheduling/employee-schedule-tab'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatLocalDate, type DisplayPreferences } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { EmployeeActionDialog, LeaveGroupDialog, type EmployeeAction } from './action-dialogs'
import {
  employeeStatusTone,
  employmentTypeLabels,
  useDeleteEmployee,
  useEmployee,
  useEmployeeHistory,
  type Employee,
} from './api'
import { EditEmployeeDialog } from './employee-form-dialog'

function Detail({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div>
      <dt className="text-muted-foreground text-xs">{label}</dt>
      <dd className="mt-0.5 text-sm font-medium break-words">{children}</dd>
    </div>
  )
}

function period(from: string, to: string | null, prefs: DisplayPreferences): string {
  return `${formatLocalDate(from, prefs)} → ${to ? formatLocalDate(to, prefs) : 'present'}`
}

export function EmployeeDetails({ employee, prefs }: { employee: Employee; prefs: DisplayPreferences }) {
  return (
    <dl className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
      <Detail label="Employee code">
        <span className="font-mono">{employee.employee_code}</span>
      </Detail>
      <Detail label="Job title">{employee.job_title ?? '—'}</Detail>
      <Detail label="Employment type">{employmentTypeLabels[employee.employment_type]}</Detail>
      <Detail label="Department">{employee.department?.name ?? '—'}</Detail>
      <Detail label="Location">
        {employee.location
          ? `${employee.location.name}${employee.location.timezone ? ` (${employee.location.timezone})` : ''}`
          : '—'}
      </Detail>
      <Detail label="Groups">{employee.groups.map((g) => g.name).join(', ') || '—'}</Detail>
      <Detail label="Email">{employee.email ?? '—'}</Detail>
      <Detail label="Phone">{employee.phone ?? '—'}</Detail>
      <Detail label="Hire date">{formatLocalDate(employee.hire_date, prefs)}</Detail>
      {employee.termination_date && <Detail label="Termination date">{formatLocalDate(employee.termination_date, prefs)}</Detail>}
    </dl>
  )
}

function HistoryTab({ employeeId, prefs }: { employeeId: number; prefs: DisplayPreferences }) {
  const history = useEmployeeHistory(employeeId)

  if (history.isError) return <ErrorState error={history.error} onRetry={() => history.refetch()} />
  if (history.isPending) return <Skeleton className="h-40 w-full" />

  return (
    <div className="overflow-x-auto rounded-lg border">
      <Table>
        <caption className="sr-only">Department and location history</caption>
        <TableHeader>
          <TableRow>
            <TableHead>Period</TableHead>
            <TableHead>Department</TableHead>
            <TableHead>Location</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {history.data.assignments.map((a) => (
            <TableRow key={a.id}>
              <TableCell className="whitespace-nowrap tabular-nums">{period(a.effective_from, a.effective_to, prefs)}</TableCell>
              <TableCell>{a.department.name}</TableCell>
              <TableCell>{a.location?.name ?? '—'}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function GroupsTab({
  employee,
  prefs,
  editable,
  onAdd,
  onRemove,
}: {
  employee: Employee
  prefs: DisplayPreferences
  editable: boolean
  onAdd: () => void
  onRemove: (group: { id: number; name: string }) => void
}) {
  const history = useEmployeeHistory(employee.id)

  if (history.isError) return <ErrorState error={history.error} onRetry={() => history.refetch()} />
  if (history.isPending) return <Skeleton className="h-40 w-full" />

  const current = new Set(employee.groups.map((g) => g.id))

  return (
    <div className="space-y-3">
      {editable && employee.status !== 'terminated' && (
        <Button variant="outline" size="sm" onClick={onAdd}>
          <Plus /> Add to group
        </Button>
      )}
      {history.data.memberships.length === 0 ? (
        <EmptyState title="Not in any group" description="Groups are optional. They can carry their own schedule." />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <Table>
            <caption className="sr-only">Group memberships</caption>
            <TableHeader>
              <TableRow>
                <TableHead>Group</TableHead>
                <TableHead>Priority</TableHead>
                <TableHead>Period</TableHead>
                <TableHead className="w-24" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {history.data.memberships.map((m) => (
                <TableRow key={m.id}>
                  <TableCell className="font-medium">{m.group.name}</TableCell>
                  <TableCell className="tabular-nums">{m.group.priority}</TableCell>
                  <TableCell className="whitespace-nowrap tabular-nums">
                    {period(m.effective_from, m.effective_to, prefs)}
                  </TableCell>
                  <TableCell className="text-right">
                    {editable && m.effective_to === null && current.has(m.group.id) && (
                      <Button variant="ghost" size="sm" onClick={() => onRemove(m.group)}>
                        <UserMinus /> Remove
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}
    </div>
  )
}

export function EmployeeDetailPage() {
  const { id } = useParams()
  const employeeId = Number(id)
  const navigate = useNavigate()
  const can = useCan()
  const prefs = useDisplayPreferences()
  const query = useEmployee(employeeId)
  const remove = useDeleteEmployee()
  const [action, setAction] = useState<EmployeeAction | null>(null)
  const [editing, setEditing] = useState(false)
  const [leaving, setLeaving] = useState<{ id: number; name: string } | null>(null)
  const [deleting, setDeleting] = useState(false)

  if (query.isError) {
    return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  }

  if (query.isPending) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-48 w-full" />
      </div>
    )
  }

  const employee = query.data
  const editable = can('employees.update')
  const terminated = employee.status === 'terminated'

  return (
    <>
      <Link to="/employees" className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm">
        <ArrowLeft className="size-4" /> Employees
      </Link>

      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="space-y-1">
          <div className="flex flex-wrap items-center gap-3">
            <h1 className="text-2xl font-semibold tracking-tight">{employee.full_name}</h1>
            <StatusBadge tone={employeeStatusTone[employee.status]}>{employee.status}</StatusBadge>
          </div>
          <p className="text-muted-foreground text-sm">
            {[employee.job_title, employee.department?.name, employee.location?.name].filter(Boolean).join(' · ') ||
              employee.employee_code}
          </p>
        </div>
        {(editable || can('employees.terminate') || can('employees.delete')) && (
          <div className="flex gap-2">
            {editable && (
              <Button variant="outline" onClick={() => setEditing(true)}>
                <Pencil /> Edit
              </Button>
            )}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="icon" aria-label="More actions">
                  <MoreHorizontal />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                {editable && !terminated && (
                  <DropdownMenuItem onSelect={() => setAction('transfer')}>
                    <ArrowRightLeft /> Transfer
                  </DropdownMenuItem>
                )}
                {editable && (
                  <DropdownMenuItem onSelect={() => setAction('link-user')}>
                    <KeyRound /> Login
                  </DropdownMenuItem>
                )}
                {can('employees.terminate') &&
                  (terminated ? (
                    <DropdownMenuItem onSelect={() => setAction('reinstate')}>
                      <RotateCcw /> Rehire
                    </DropdownMenuItem>
                  ) : (
                    <DropdownMenuItem variant="destructive" onSelect={() => setAction('terminate')}>
                      <UserMinus /> Terminate
                    </DropdownMenuItem>
                  ))}
                {can('employees.delete') && (
                  <>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(true)}>
                      <Trash2 /> Delete record
                    </DropdownMenuItem>
                  </>
                )}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        )}
      </div>

      <Tabs defaultValue="details">
        <TabsList>
          <TabsTrigger value="details">Details</TabsTrigger>
          <TabsTrigger value="history">History</TabsTrigger>
          <TabsTrigger value="groups">Groups</TabsTrigger>
          {can('schedules.view') && <TabsTrigger value="schedule">Schedule</TabsTrigger>}
          {can('attendance.view') && <TabsTrigger value="attendance">Attendance</TabsTrigger>}
          {can('leave.view') && <TabsTrigger value="leave">Leave</TabsTrigger>}
        </TabsList>
        <TabsContent value="details" className="pt-4">
          <Card>
            <CardContent className="pt-6">
              <EmployeeDetails employee={employee} prefs={prefs} />
            </CardContent>
          </Card>
        </TabsContent>
        <TabsContent value="history" className="pt-4">
          <HistoryTab employeeId={employee.id} prefs={prefs} />
        </TabsContent>
        <TabsContent value="groups" className="pt-4">
          <GroupsTab
            employee={employee}
            prefs={prefs}
            editable={editable}
            onAdd={() => setAction('join-group')}
            onRemove={setLeaving}
          />
        </TabsContent>
        {can('schedules.view') && (
          <TabsContent value="schedule" className="pt-4">
            <EmployeeScheduleTab employeeId={employee.id} />
          </TabsContent>
        )}
        {can('attendance.view') && (
          <TabsContent value="attendance" className="pt-4">
            <EmployeeAttendanceTab
              employeeId={employee.id}
              employeeCode={employee.employee_code}
              employeeName={employee.full_name}
            />
          </TabsContent>
        )}
        {can('leave.view') && (
          <TabsContent value="leave" className="pt-4">
            <EmployeeLeaveTab employeeId={employee.id} />
          </TabsContent>
        )}
      </Tabs>

      <EmployeeActionDialog employee={employee} action={action} onClose={() => setAction(null)} />
      <LeaveGroupDialog employee={employee} group={leaving} onClose={() => setLeaving(null)} />
      <EditEmployeeDialog employee={editing ? employee : null} onOpenChange={(open) => !open && setEditing(false)} />
      <ConfirmDialog
        open={deleting}
        onOpenChange={setDeleting}
        title={`Delete ${employee.full_name}?`}
        description={
          <p>
            Use this only for records created by mistake. To record that someone left, terminate them instead. Their code stays
            reserved.
          </p>
        }
        confirmLabel="Delete record"
        destructive
        confirmText={employee.employee_code}
        pending={remove.isPending}
        onConfirm={() =>
          remove.mutate(employee.id, {
            onSuccess: () => {
              toast.success('Employee record deleted.')
              void navigate('/employees', { replace: true })
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }
      />
    </>
  )
}
