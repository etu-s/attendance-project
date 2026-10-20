import { useMemo, useState } from 'react'
import { MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DataTable, type Column } from '@/components/data-table'
import { SearchInput } from '@/components/search-input'
import { PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useCan, useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { useListParams } from '@/hooks/use-list-params'
import { formatDate, formatDateTime } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useDeleteUser, useUsers } from './api'
import { UserFormDialog } from './user-form-dialog'
import { roleLabels, roles, type Role, type User } from './types'

const ALL = 'all'

export function UsersPage() {
  const can = useCan()
  const { user: me } = useSession()
  const prefs = useDisplayPreferences()
  const list = useListParams({ defaultSort: 'name', filters: ['role', 'status'] })
  const users = useUsers(list.params)
  const deleteUser = useDeleteUser()

  const [editing, setEditing] = useState<User | null>(null)
  const [creating, setCreating] = useState(false)
  const [deleting, setDeleting] = useState<User | null>(null)

  const iAmAdmin = me.role === 'tenant-admin'
  // Mirrors ValidatesRoleAssignment: only a Tenant Admin may hand out Tenant Admin.
  const assignableRoles = useMemo<Role[]>(() => (iAmAdmin ? [...roles] : roles.filter((r) => r !== 'tenant-admin')), [iAmAdmin])

  // Mirrors UserPolicy: admin accounts are managed by admins only; nobody deletes themselves.
  const canEdit = (user: User) => can('users.update') && (user.role !== 'tenant-admin' || iAmAdmin)
  const canDelete = (user: User) => can('users.delete') && user.id !== me.id && (user.role !== 'tenant-admin' || iAmAdmin)

  const columns: Column<User>[] = [
    {
      key: 'name',
      header: 'Name',
      sortKey: 'name',
      cell: (user) => (
        <div className="min-w-0">
          <p className="truncate font-medium">
            {user.name}
            {user.id === me.id && <span className="text-muted-foreground ml-2 text-xs font-normal">(you)</span>}
          </p>
          <p className="text-muted-foreground truncate text-xs">{user.email}</p>
        </div>
      ),
    },
    {
      key: 'role',
      header: 'Role',
      hideBelow: 'sm',
      cell: (user) => <span className="text-sm">{user.role ? roleLabels[user.role] : '—'}</span>,
    },
    {
      key: 'status',
      header: 'Status',
      sortKey: 'status',
      cell: (user) => <StatusBadge tone={user.status === 'active' ? 'success' : 'neutral'}>{user.status}</StatusBadge>,
    },
    {
      key: 'last_login_at',
      header: 'Last sign-in',
      sortKey: 'last_login_at',
      hideBelow: 'md',
      cell: (user) => <span className="text-muted-foreground text-sm">{formatDateTime(user.last_login_at, prefs)}</span>,
    },
    {
      key: 'created_at',
      header: 'Created',
      sortKey: 'created_at',
      hideBelow: 'lg',
      cell: (user) => <span className="text-muted-foreground text-sm">{formatDate(user.created_at, prefs)}</span>,
    },
    {
      key: 'actions',
      header: '',
      className: 'w-12 text-right',
      cell: (user) =>
        canEdit(user) || canDelete(user) ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${user.name}`}>
                <MoreHorizontal />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {canEdit(user) && (
                <DropdownMenuItem onSelect={() => setEditing(user)}>
                  <Pencil /> Edit
                </DropdownMenuItem>
              )}
              {canEdit(user) && canDelete(user) && <DropdownMenuSeparator />}
              {canDelete(user) && (
                <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(user)}>
                  <Trash2 /> Delete
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        ) : null,
    },
  ]

  return (
    <>
      <PageHeader
        title="Users"
        description="People who can sign in to this workspace and what they can do."
        actions={
          can('users.create') && (
            <Button onClick={() => setCreating(true)}>
              <Plus /> Add user
            </Button>
          )
        }
      />

      <div className="flex flex-col gap-2 sm:flex-row">
        <SearchInput value={list.search} onChange={list.setSearch} label="Search name or email" />
        <Select value={list.filter('role') || ALL} onValueChange={(value) => list.setFilter('role', value === ALL ? '' : value)}>
          <SelectTrigger className="w-full sm:w-48" aria-label="Filter by role">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>All roles</SelectItem>
            {roles.map((role) => (
              <SelectItem key={role} value={role}>
                {roleLabels[role]}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        <Select
          value={list.filter('status') || ALL}
          onValueChange={(value) => list.setFilter('status', value === ALL ? '' : value)}
        >
          <SelectTrigger className="w-full sm:w-40" aria-label="Filter by status">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={ALL}>Any status</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <DataTable
        caption="Users"
        columns={columns}
        list={list}
        query={users}
        rowKey={(user) => user.id}
        emptyTitle="No users yet"
        emptyDescription="Add the people who should be able to sign in."
      />

      <UserFormDialog open={creating} onOpenChange={setCreating} assignableRoles={assignableRoles} />
      <UserFormDialog
        open={editing !== null}
        onOpenChange={(open) => !open && setEditing(null)}
        user={editing ?? undefined}
        assignableRoles={assignableRoles}
        isSelf={editing?.id === me.id}
      />

      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'user'}?`}
        description={<p>They will no longer be able to sign in. Their history is kept.</p>}
        confirmLabel="Delete user"
        destructive
        pending={deleteUser.isPending}
        onConfirm={() => {
          if (!deleting) return
          deleteUser.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('User deleted.')
              setDeleting(null)
            },
            onError: (error) => toast.error(errorMessage(error)),
          })
        }}
      />
    </>
  )
}
