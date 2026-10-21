import { useState, type ReactNode } from 'react'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useDepartments, useGroupOptions, useLocationOptions } from '@/features/organization/api'
import { useSession } from '@/features/tenant-auth/hooks'
import { useUsers } from '@/features/users/api'
import { ApiError } from '@/lib/api/client'
import { todayIn } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useEmployeeAction, type Employee } from './api'

export type EmployeeAction = 'transfer' | 'terminate' | 'reinstate' | 'join-group' | 'link-user'

const NONE = 'none'

/**
 * Small labelled control. These dialogs are simple enough not to need
 * react-hook-form; server validation errors are shown per field.
 */
function Control({
  id,
  label,
  error,
  hint,
  children,
}: {
  id: string
  label: string
  error?: string
  hint?: string
  children: ReactNode
}) {
  return (
    <div className="grid gap-1.5">
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint && !error && <p className="text-muted-foreground text-xs">{hint}</p>}
      {error && <p className="text-destructive text-xs font-medium">{error}</p>}
    </div>
  )
}

/** Mounted only for the link-user action: listing users needs users.view. */
function UserSelect({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const users = useUsers({ status: 'active', per_page: 100, sort: 'name' })

  return (
    <Select value={value} onValueChange={onChange}>
      <SelectTrigger id="action-user" className="w-full">
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value={NONE}>No login</SelectItem>
        {(users.data?.data ?? []).map((u) => (
          <SelectItem key={u.id} value={String(u.id)}>
            {u.name} · {u.email}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}

interface ActionDialogProps {
  employee: Employee
  action: EmployeeAction | null
  onClose: () => void
}

export function EmployeeActionDialog({ employee, action, onClose }: ActionDialogProps) {
  const { workspace } = useSession()
  const run = useEmployeeAction(employee.id)
  const today = todayIn(workspace.timezone)
  const departments = useDepartments(true)
  const locations = useLocationOptions()
  const groups = useGroupOptions()

  const [date, setDate] = useState(today)
  const [departmentId, setDepartmentId] = useState('')
  const [locationId, setLocationId] = useState(NONE)
  const [groupId, setGroupId] = useState('')
  const [userId, setUserId] = useState(NONE)
  const [deactivateLogin, setDeactivateLogin] = useState(true)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [opened, setOpened] = useState<EmployeeAction | null>(null)

  // Prefill when the dialog opens for a different action.
  if (action !== opened) {
    setOpened(action)
    setErrors({})
    setDate(today)
    setDepartmentId(action === 'transfer' && employee.department ? String(employee.department.id) : '')
    setLocationId(employee.location ? String(employee.location.id) : NONE)
    setGroupId('')
    setUserId(employee.user_id ? String(employee.user_id) : NONE)
    setDeactivateLogin(true)
  }

  const memberGroupIds = new Set(employee.groups.map((g) => g.id))

  const content: Record<EmployeeAction, { title: string; description: string; confirm: string; destructive?: boolean }> = {
    transfer: {
      title: 'Transfer',
      description: 'Move to another department or location from a date. Past days keep the old assignment.',
      confirm: 'Transfer',
    },
    terminate: {
      title: `Terminate ${employee.full_name}`,
      description: 'Their history ends on this date (inclusive). They stop counting toward the plan limit.',
      confirm: 'Terminate',
      destructive: true,
    },
    reinstate: {
      title: `Rehire ${employee.full_name}`,
      description: 'Starts a new period after the termination gap. Department and location default to the last ones held.',
      confirm: 'Rehire',
    },
    'join-group': { title: 'Add to group', description: 'Membership starts on the chosen date.', confirm: 'Add to group' },
    'link-user': { title: 'Login', description: 'Link the user account this employee signs in with.', confirm: 'Save' },
  }

  const submit = () => {
    if (!action) return
    const location = locationId === NONE ? null : Number(locationId)
    const request = {
      transfer: { path: 'transfer', body: { department_id: Number(departmentId), location_id: location, effective_from: date } },
      terminate: { path: 'terminate', body: { termination_date: date, deactivate_login: deactivateLogin } },
      reinstate: {
        path: 'reinstate',
        body: { effective_from: date, ...(departmentId ? { department_id: Number(departmentId), location_id: location } : {}) },
      },
      'join-group': { path: 'groups', body: { group_id: Number(groupId), effective_from: date } },
      'link-user': { path: 'user', method: 'put' as const, body: { user_id: userId === NONE ? null : Number(userId) } },
    }[action]

    run.mutate(request, {
      onSuccess: () => {
        toast.success(`${content[action].title} saved.`)
        onClose()
      },
      onError: (error) => {
        if (error instanceof ApiError && error.isValidation) {
          setErrors(Object.fromEntries(Object.entries(error.errors).map(([k, v]) => [k, v[0] ?? error.message])))
        } else {
          toast.error(errorMessage(error))
        }
      },
    })
  }

  const dateField = (key: string, label: string) => (
    <Control id="action-date" label={label} error={errors[key]}>
      <Input id="action-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
    </Control>
  )

  const departmentSelect = (optional: boolean) => (
    <Control id="action-department" label={optional ? 'Department (optional)' : 'Department'} error={errors.department_id}>
      <Select value={departmentId || NONE} onValueChange={(v) => setDepartmentId(v === NONE ? '' : v)}>
        <SelectTrigger id="action-department" className="w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {optional && <SelectItem value={NONE}>Same as before</SelectItem>}
          {(departments.data ?? []).map((d) => (
            <SelectItem key={d.id} value={String(d.id)}>
              {d.path ?? d.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </Control>
  )

  const locationSelect = (
    <Control id="action-location" label="Location" error={errors.location_id}>
      <Select value={locationId} onValueChange={setLocationId}>
        <SelectTrigger id="action-location" className="w-full">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value={NONE}>No location</SelectItem>
          {(locations.data?.data ?? []).map((l) => (
            <SelectItem key={l.id} value={String(l.id)}>
              {l.name}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    </Control>
  )

  const body: Record<EmployeeAction, ReactNode> = {
    transfer: (
      <>
        {departmentSelect(false)}
        {locationSelect}
        {dateField('effective_from', 'Effective from')}
      </>
    ),
    terminate: (
      <>
        {dateField('termination_date', 'Last working day')}
        {employee.user_id !== null && (
          <label className="flex items-center gap-2 text-sm">
            <Checkbox checked={deactivateLogin} onCheckedChange={(c) => setDeactivateLogin(c === true)} />
            Also deactivate their login
          </label>
        )}
      </>
    ),
    reinstate: (
      <>
        {dateField('effective_from', 'Rehire date')}
        {departmentSelect(true)}
        {departmentId && locationSelect}
      </>
    ),
    'join-group': (
      <>
        <Control id="action-group" label="Group" error={errors.group_id}>
          <Select value={groupId} onValueChange={setGroupId}>
            <SelectTrigger id="action-group" className="w-full">
              <SelectValue placeholder="Choose a group" />
            </SelectTrigger>
            <SelectContent>
              {(groups.data?.data ?? [])
                .filter((g) => !memberGroupIds.has(g.id))
                .map((g) => (
                  <SelectItem key={g.id} value={String(g.id)}>
                    {g.name} (priority {g.priority})
                  </SelectItem>
                ))}
            </SelectContent>
          </Select>
        </Control>
        {dateField('effective_from', 'Member from')}
      </>
    ),
    'link-user': (
      <Control id="action-user" label="User account" error={errors.user_id} hint="Each login can belong to one employee.">
        <UserSelect value={userId} onChange={setUserId} />
      </Control>
    ),
  }

  const disabled =
    run.isPending ||
    (action === 'transfer' && !departmentId) ||
    (action === 'join-group' && !groupId) ||
    (action !== 'link-user' && !date)

  return (
    <Dialog open={action !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        {action && (
          <>
            <DialogHeader>
              <DialogTitle>{content[action].title}</DialogTitle>
              <DialogDescription>{content[action].description}</DialogDescription>
            </DialogHeader>
            <div className="grid gap-4">{body[action]}</div>
            <DialogFooter>
              <Button variant="outline" onClick={onClose}>
                Cancel
              </Button>
              <Button variant={content[action].destructive ? 'destructive' : 'default'} onClick={submit} disabled={disabled}>
                {run.isPending && <Loader2 className="animate-spin" />}
                {content[action].confirm}
              </Button>
            </DialogFooter>
          </>
        )}
      </DialogContent>
    </Dialog>
  )
}

/** Ends a group membership on a date. */
export function LeaveGroupDialog({
  employee,
  group,
  onClose,
}: {
  employee: Employee
  group: { id: number; name: string } | null
  onClose: () => void
}) {
  const { workspace } = useSession()
  const run = useEmployeeAction(employee.id)
  const [date, setDate] = useState(todayIn(workspace.timezone))
  const [error, setError] = useState<string | null>(null)

  return (
    <Dialog open={group !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-sm">
        <DialogHeader>
          <DialogTitle>Remove from {group?.name}</DialogTitle>
          <DialogDescription>The membership ends on this date (inclusive).</DialogDescription>
        </DialogHeader>
        <Control id="leave-date" label="Last day in group" error={error ?? undefined}>
          <Input id="leave-date" type="date" value={date} onChange={(e) => setDate(e.target.value)} />
        </Control>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            disabled={run.isPending || !date}
            onClick={() => {
              if (!group) return
              run.mutate(
                { path: `groups/${String(group.id)}/end`, body: { effective_to: date } },
                {
                  onSuccess: () => {
                    toast.success(`Removed from ${group.name}.`)
                    onClose()
                  },
                  onError: (e) => setError(errorMessage(e)),
                },
              )
            }}
          >
            {run.isPending && <Loader2 className="animate-spin" />}
            Remove
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
