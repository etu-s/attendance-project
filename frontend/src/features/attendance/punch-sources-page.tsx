import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Copy, KeyRound, Loader2, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Field } from '@/components/form-field'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import { useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { formatDateTime } from '@/lib/format'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import {
  useDeletePunchSource,
  usePunchSources,
  useRotatePunchSourceKey,
  useSavePunchSource,
  type PunchSource,
  type PunchSourceSecrets,
} from './api'

const ipOrCidr = /^(\d{1,3}(\.\d{1,3}){3}(\/\d{1,2})?|[0-9a-f:]+(\/\d{1,3})?)$/i

// Mirrors PunchSourceRequest (the API checks addresses and CIDR ranges exactly).
const schema = z.object({
  name: z.string().trim().min(1, 'Name the device or system.').max(120),
  kind: z.enum(['device', 'integration']),
  allowlist: z
    .string()
    .refine(
      (v) =>
        v
          .split(/\s+/)
          .filter(Boolean)
          .every((entry) => ipOrCidr.test(entry)),
      'One IP address or CIDR range per line.',
    )
    .refine((v) => v.split(/\s+/).filter(Boolean).length <= 20, 'At most 20 entries.'),
  signed: z.boolean(),
  is_active: z.boolean(),
})

type Values = z.infer<typeof schema>

function SourceDialog({
  target,
  onClose,
  onSecrets,
}: {
  target: PunchSource | 'new' | null
  onClose: () => void
  onSecrets: (secrets: PunchSourceSecrets) => void
}) {
  const save = useSavePunchSource()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { name: '', kind: 'device', allowlist: '', signed: false, is_active: true },
  })
  const creating = target === 'new'

  useEffect(() => {
    if (target === 'new') form.reset({ name: '', kind: 'device', allowlist: '', signed: false, is_active: true })
    else if (target)
      form.reset({
        name: target.name,
        kind: target.kind,
        allowlist: target.ip_allowlist.join('\n'),
        signed: target.signed,
        is_active: target.is_active,
      })
  }, [target, form])

  const submit = form.handleSubmit(async (v) => {
    const ip_allowlist = v.allowlist.split(/\s+/).filter(Boolean)
    try {
      const result = await save.mutateAsync(
        creating
          ? { payload: { name: v.name, kind: v.kind, ip_allowlist, signed: v.signed } }
          : { id: (target as PunchSource).id, payload: { name: v.name, kind: v.kind, ip_allowlist, is_active: v.is_active } },
      )
      onClose()
      if (result) {
        onSecrets(result)
      } else {
        toast.success('Source saved.')
      }
    } catch (error) {
      applyServerErrors(error, form.setError, ['name', 'kind'], { ip_allowlist: 'allowlist', 'ip_allowlist.0': 'allowlist' })
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{creating ? 'New punch source' : 'Edit punch source'}</DialogTitle>
          <DialogDescription>A clock-in terminal or a system that posts punches to the API.</DialogDescription>
        </DialogHeader>
        <form id="source-form" onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Name" error={errors.name?.message}>
              <Input placeholder="e.g. Main entrance" {...form.register('name')} />
            </Field>
            <Controller
              control={form.control}
              name="kind"
              render={({ field }) => (
                <Field label="Kind">
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="device">Device</SelectItem>
                        <SelectItem value="integration">Integration</SelectItem>
                      </SelectContent>
                    </Select>
                  )}
                </Field>
              )}
            />
          </div>
          <Field
            label="Allowed addresses"
            error={errors.allowlist?.message}
            hint="Optional. One IP or CIDR range per line; empty allows any address."
          >
            <Textarea
              rows={3}
              placeholder={'10.0.0.0/24\n203.0.113.7'}
              className="font-mono text-sm"
              {...form.register('allowlist')}
            />
          </Field>
          {creating ? (
            <Controller
              control={form.control}
              name="signed"
              render={({ field }) => (
                <label className="flex items-start gap-2 text-sm">
                  <Checkbox className="mt-0.5" checked={field.value} onCheckedChange={(c) => field.onChange(c === true)} />
                  <span>
                    Require signed requests (HMAC)
                    <span className="text-muted-foreground block text-xs">
                      For integrations that can sign each request with a shared secret.
                    </span>
                  </span>
                </label>
              )}
            />
          ) : (
            <Controller
              control={form.control}
              name="is_active"
              render={({ field }) => (
                <label className="flex items-center gap-2 text-sm">
                  <Switch checked={field.value} onCheckedChange={field.onChange} />
                  Active (inactive sources are refused)
                </label>
              )}
            />
          )}
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="source-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {creating ? 'Create' : 'Save'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function CopyField({ label, value }: { label: string; value: string }) {
  return (
    <div className="grid gap-1.5">
      <p className="text-sm font-medium">{label}</p>
      <div className="flex gap-2">
        <Input readOnly value={value} className="font-mono text-xs" aria-label={label} onFocus={(e) => e.target.select()} />
        <Button
          variant="outline"
          size="icon"
          aria-label={`Copy ${label.toLowerCase()}`}
          onClick={() =>
            void navigator.clipboard.writeText(value).then(
              () => toast.success('Copied.'),
              () => toast.error('Copy failed; select the text and copy it.'),
            )
          }
        >
          <Copy />
        </Button>
      </div>
    </div>
  )
}

/** Shown once after create / rotate: the key cannot be retrieved again. */
function SecretsDialog({ secrets, onClose }: { secrets: PunchSourceSecrets | null; onClose: () => void }) {
  const { workspace } = useSession()

  if (!secrets) {
    return null
  }

  const endpoint = `${window.location.origin}/api/v1/punches`

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Key for {secrets.data.name}</DialogTitle>
          <DialogDescription>Configure the device or system with this key now.</DialogDescription>
        </DialogHeader>
        <Alert>
          <KeyRound />
          <AlertTitle>Shown only once</AlertTitle>
          <AlertDescription>
            {workspace.name} stores only a fingerprint of the key. If it is lost, rotate it to get a new one.
          </AlertDescription>
        </Alert>
        <CopyField label="API key" value={secrets.key} />
        {secrets.signing_secret && <CopyField label="Signing secret" value={secrets.signing_secret} />}
        <div className="grid gap-1.5">
          <p className="text-sm font-medium">Example</p>
          <pre className="bg-muted overflow-x-auto rounded-md p-3 text-xs">
            {`curl -X POST ${endpoint} \\
  -H "Authorization: Bearer ${secrets.key}" \\
  -H "Content-Type: application/json" \\
  -d '{"employee_identifier":"A-001","punched_at":"2026-03-02T08:55:00+05:00"}'`}
          </pre>
          {secrets.signing_secret && (
            <p className="text-muted-foreground text-xs">
              Signed requests also send X-Timestamp (unix seconds) and X-Signature: sha256=HMAC-SHA256 of
              &quot;timestamp.body&quot; with the signing secret.
            </p>
          )}
        </div>
        <DialogFooter>
          <Button onClick={onClose}>Done</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** Devices and integrations allowed to post punches (Tenant Admin). */
export function PunchSourcesPage() {
  const prefs = useDisplayPreferences()
  const sources = usePunchSources()
  const rotate = useRotatePunchSourceKey()
  const remove = useDeletePunchSource()
  const [editing, setEditing] = useState<PunchSource | 'new' | null>(null)
  const [secrets, setSecrets] = useState<PunchSourceSecrets | null>(null)
  const [rotating, setRotating] = useState<PunchSource | null>(null)
  const [deleting, setDeleting] = useState<PunchSource | null>(null)

  return (
    <>
      <PageHeader
        title="Punch sources"
        description="Each device or system has its own key. Punches are sent to POST /api/v1/punches on this workspace's address."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus /> New source
          </Button>
        }
      />
      {sources.isError ? (
        <ErrorState error={sources.error} onRetry={() => sources.refetch()} />
      ) : sources.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : sources.data.length === 0 ? (
        <EmptyState
          title="No punch sources"
          description="Add a terminal or integration to start receiving punches."
          action={<Button onClick={() => setEditing('new')}>New source</Button>}
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <Table>
            <caption className="sr-only">Punch sources</caption>
            <TableHeader>
              <TableRow className="hover:bg-transparent">
                <TableHead>Name</TableHead>
                <TableHead>Key</TableHead>
                <TableHead className="hidden md:table-cell">Security</TableHead>
                <TableHead className="hidden sm:table-cell">Last used</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-12" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {sources.data.map((s) => (
                <TableRow key={s.id}>
                  <TableCell>
                    <p className="font-medium">{s.name}</p>
                    <p className="text-muted-foreground text-xs capitalize">{s.kind}</p>
                  </TableCell>
                  <TableCell className="font-mono text-xs">{s.key_hint}</TableCell>
                  <TableCell className="hidden text-xs md:table-cell">
                    {s.signed ? 'Signed' : 'Key only'}
                    {s.ip_allowlist.length > 0 && ` · ${String(s.ip_allowlist.length)} address range(s)`}
                  </TableCell>
                  <TableCell className="hidden text-xs tabular-nums sm:table-cell">
                    {s.last_used_at ? formatDateTime(s.last_used_at, prefs) : 'Never'}
                  </TableCell>
                  <TableCell>
                    <StatusBadge tone={s.is_active ? 'success' : 'neutral'}>{s.is_active ? 'active' : 'inactive'}</StatusBadge>
                  </TableCell>
                  <TableCell className="text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${s.name}`}>
                          <MoreHorizontal />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => setEditing(s)}>
                          <Pencil /> Edit
                        </DropdownMenuItem>
                        <DropdownMenuItem onSelect={() => setRotating(s)}>
                          <KeyRound /> Rotate key
                        </DropdownMenuItem>
                        <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(s)}>
                          <Trash2 /> Delete
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <SourceDialog target={editing} onClose={() => setEditing(null)} onSecrets={setSecrets} />
      <SecretsDialog secrets={secrets} onClose={() => setSecrets(null)} />
      <ConfirmDialog
        open={rotating !== null}
        onOpenChange={(open) => !open && setRotating(null)}
        title={`Rotate the key of ${rotating?.name ?? 'this source'}?`}
        description={<p>The current key stops working immediately. Update the device with the new key.</p>}
        confirmLabel="Rotate key"
        pending={rotate.isPending}
        onConfirm={() => {
          if (!rotating) return
          rotate.mutate(rotating.id, {
            onSuccess: (result) => {
              setRotating(null)
              setSecrets(result)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'source'}?`}
        description={<p>Only sources that never sent a punch can be deleted; deactivate the others.</p>}
        confirmLabel="Delete"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Source deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
