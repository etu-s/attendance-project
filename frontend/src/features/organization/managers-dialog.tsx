import { useState } from 'react'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { SearchInput } from '@/components/search-input'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { useUsers } from '@/features/users/api'
import { roleLabels } from '@/features/users/types'
import { applyServerErrors } from '@/lib/forms'
import { useSetManagers, type ManagerSummary } from './api'

interface ManagersDialogProps {
  kind: 'departments' | 'groups'
  target: { id: number; name: string; managers?: ManagerSummary[] } | null
  onOpenChange: (open: boolean) => void
}

/**
 * Picks the users who manage a department or group. Managers see the
 * employees of what they manage (EmployeeVisibility on the API).
 */
export function ManagersDialog({ kind, target, onOpenChange }: ManagersDialogProps) {
  const [search, setSearch] = useState('')
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [targetId, setTargetId] = useState<number | null>(null)
  const users = useUsers({ search, status: 'active', per_page: 50, sort: 'name' })
  const save = useSetManagers()

  // Reset the selection when the dialog opens for another department/group.
  if (target && target.id !== targetId) {
    setTargetId(target.id)
    setSelected(new Set((target.managers ?? []).map((m) => m.id)))
    setSearch('')
  }

  const toggle = (id: number, checked: boolean) =>
    setSelected((current) => {
      const next = new Set(current)
      if (checked) next.add(id)
      else next.delete(id)
      return next
    })

  const submit = () => {
    if (!target) return
    save.mutate(
      { kind, id: target.id, userIds: [...selected] },
      {
        onSuccess: () => {
          toast.success('Managers updated.')
          onOpenChange(false)
        },
        onError: (error) => applyServerErrors(error, () => undefined, []),
      },
    )
  }

  return (
    <Dialog
      open={target !== null}
      onOpenChange={(open) => {
        if (!open) setTargetId(null)
        onOpenChange(open)
      }}
    >
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>Managers of {target?.name}</DialogTitle>
          <DialogDescription>
            Managers can see the employees{' '}
            {kind === 'departments' ? 'in this department and its sub-departments' : 'in this group'}.
          </DialogDescription>
        </DialogHeader>

        <SearchInput value={search} onChange={setSearch} label="Search users" />

        <fieldset className="max-h-72 space-y-1 overflow-y-auto rounded-md border p-2">
          <legend className="sr-only">Users</legend>
          {users.isPending ? (
            Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-9 w-full" />)
          ) : (users.data?.data ?? []).length === 0 ? (
            <p className="text-muted-foreground p-3 text-center text-sm">No active users match.</p>
          ) : (
            (users.data?.data ?? []).map((user) => (
              <label key={user.id} className="hover:bg-accent flex cursor-pointer items-center gap-3 rounded px-2 py-1.5">
                <Checkbox checked={selected.has(user.id)} onCheckedChange={(checked) => toggle(user.id, checked === true)} />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-medium">{user.name}</span>
                  <span className="text-muted-foreground block truncate text-xs">
                    {user.email} · {user.role ? roleLabels[user.role] : 'No role'}
                  </span>
                </span>
              </label>
            ))
          )}
        </fieldset>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancel
          </Button>
          <Button onClick={submit} disabled={save.isPending}>
            {save.isPending && <Loader2 className="animate-spin" />}
            Save ({selected.size})
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
