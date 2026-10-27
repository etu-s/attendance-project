import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, MoreHorizontal, Pencil, Plus, Trash2 } from 'lucide-react'
import { Controller, useForm, useWatch } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Field } from '@/components/form-field'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import { useDeleteLeaveType, useLeaveTypes, useSaveLeaveType, type LeaveType } from './api'
import { accrualLabel, formatDays } from './format'

const halfDays = z
  .string()
  .regex(/^\d{1,3}(\.[05])?$/, 'Whole or half days, e.g. 14 or 1.5.')
  .refine((v) => Number(v) <= 366, 'At most 366 days.')

// Mirrors LeaveTypeRequest.
const schema = z.object({
  name: z.string().trim().min(1, 'Name the leave type.').max(80),
  code: z
    .string()
    .trim()
    .min(1, 'Give it a short code.')
    .max(20)
    .regex(/^[A-Za-z0-9_-]+$/, 'Letters, digits, - and _ only.'),
  color: z.string().regex(/^#[0-9a-fA-F]{6}$/),
  is_paid: z.boolean(),
  unlimited: z.boolean(),
  accrual: z.enum(['none', 'yearly', 'monthly']),
  entitlement_days: halfDays,
  carry_forward_max_days: z.union([z.literal(''), halfDays]),
  negative_limit_days: halfDays,
  is_active: z.boolean(),
})

type Values = z.infer<typeof schema>

function toValues(type: LeaveType | null): Values {
  return {
    name: type?.name ?? '',
    code: type?.code ?? '',
    color: type?.color ?? '#2563eb',
    is_paid: type?.is_paid ?? true,
    unlimited: type?.unlimited ?? false,
    accrual: type?.accrual ?? 'yearly',
    entitlement_days: String(type?.entitlement_days ?? 14),
    carry_forward_max_days: type?.carry_forward_max_days === null || type === null ? '' : String(type.carry_forward_max_days),
    negative_limit_days: String(type?.negative_limit_days ?? 0),
    is_active: type?.is_active ?? true,
  }
}

function TypeDialog({ target, onClose }: { target: LeaveType | 'new' | null; onClose: () => void }) {
  const save = useSaveLeaveType()
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: toValues(null) })
  const unlimited = useWatch({ control: form.control, name: 'unlimited' })

  useEffect(() => {
    if (target !== null) form.reset(toValues(target === 'new' ? null : target))
  }, [target, form])

  const submit = form.handleSubmit(async (v) => {
    try {
      await save.mutateAsync({
        id: target !== null && target !== 'new' ? target.id : undefined,
        payload: {
          name: v.name,
          code: v.code,
          color: v.color,
          is_paid: v.is_paid,
          unlimited: v.unlimited,
          accrual: v.accrual,
          entitlement_days: Number(v.entitlement_days),
          carry_forward_max_days: v.carry_forward_max_days === '' ? null : Number(v.carry_forward_max_days),
          negative_limit_days: Number(v.negative_limit_days),
          is_active: v.is_active,
        },
      })
      toast.success('Leave type saved.')
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, [
        'name',
        'code',
        'color',
        'accrual',
        'entitlement_days',
        'carry_forward_max_days',
        'negative_limit_days',
      ])
    }
  })

  const errors = form.formState.errors
  const check = (name: 'is_paid' | 'unlimited' | 'is_active', label: string, hint?: string) => (
    <Controller
      control={form.control}
      name={name}
      render={({ field }) => (
        <label className="flex items-start gap-2 text-sm">
          <Checkbox className="mt-0.5" checked={field.value} onCheckedChange={(c) => field.onChange(c === true)} />
          <span>
            {label}
            {hint && <span className="text-muted-foreground block text-xs">{hint}</span>}
          </span>
        </label>
      )}
    />
  )

  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{target === 'new' ? 'New leave type' : 'Edit leave type'}</DialogTitle>
          <DialogDescription>Changes apply from now on; entries already in balances stay as they are.</DialogDescription>
        </DialogHeader>
        <form id="leave-type-form" onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-[1fr_8rem_4rem]">
            <Field label="Name" error={errors.name?.message}>
              <Input placeholder="Annual leave" {...form.register('name')} />
            </Field>
            <Field label="Code" error={errors.code?.message}>
              <Input placeholder="AL" {...form.register('code')} />
            </Field>
            <Field label="Colour" error={errors.color?.message}>
              <Input type="color" className="p-1" {...form.register('color')} />
            </Field>
          </div>
          <div className="grid gap-2">
            {check('is_paid', 'Paid leave')}
            {check('unlimited', 'Unlimited', 'No balance is kept or checked, e.g. unpaid leave.')}
          </div>
          {!unlimited && (
            <>
              <div className="grid gap-4 sm:grid-cols-2">
                <Controller
                  control={form.control}
                  name="accrual"
                  render={({ field }) => (
                    <Field label="How it is earned">
                      {(controlProps) => (
                        <Select value={field.value} onValueChange={field.onChange}>
                          <SelectTrigger className="w-full" {...controlProps}>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {(['yearly', 'monthly', 'none'] as const).map((a) => (
                              <SelectItem key={a} value={a}>
                                {accrualLabel[a]}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      )}
                    </Field>
                  )}
                />
                <Field label="Days per year" error={errors.entitlement_days?.message} hint="Joiners get a share.">
                  <Input inputMode="decimal" {...form.register('entitlement_days')} />
                </Field>
              </div>
              <div className="grid gap-4 sm:grid-cols-2">
                <Field
                  label="Carry over at most (days)"
                  error={errors.carry_forward_max_days?.message}
                  hint="Empty: nothing carries over."
                >
                  <Input inputMode="decimal" {...form.register('carry_forward_max_days')} />
                </Field>
                <Field label="May go below zero by (days)" error={errors.negative_limit_days?.message}>
                  <Input inputMode="decimal" {...form.register('negative_limit_days')} />
                </Field>
              </div>
            </>
          )}
          {target !== 'new' && check('is_active', 'Active', 'Inactive types cannot be requested; existing balances stay.')}
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="leave-type-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

/** Leave types and how they are earned (leave.manage). */
export function LeaveTypesPage() {
  const types = useLeaveTypes(true)
  const remove = useDeleteLeaveType()
  const [editing, setEditing] = useState<LeaveType | 'new' | null>(null)
  const [deleting, setDeleting] = useState<LeaveType | null>(null)

  return (
    <>
      <PageHeader
        title="Leave types"
        description="Balances are earned automatically each day by the scheduled leave job."
        actions={
          <Button onClick={() => setEditing('new')}>
            <Plus /> New type
          </Button>
        }
      />
      {types.isError ? (
        <ErrorState error={types.error} onRetry={() => types.refetch()} />
      ) : types.isPending ? (
        <Skeleton className="h-40 w-full" />
      ) : types.data.length === 0 ? (
        <EmptyState
          title="No leave types"
          description="Add annual, sick or unpaid leave to let people request time off."
          action={<Button onClick={() => setEditing('new')}>New type</Button>}
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <Table>
            <caption className="sr-only">Leave types</caption>
            <TableHeader>
              <TableRow className="hover:bg-transparent">
                <TableHead>Type</TableHead>
                <TableHead>Earned</TableHead>
                <TableHead className="hidden md:table-cell">Carry over</TableHead>
                <TableHead className="hidden md:table-cell">Below zero</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="w-12" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {types.data.map((t) => (
                <TableRow key={t.id}>
                  <TableCell>
                    <p className="flex items-center gap-2 font-medium">
                      <span
                        className="size-2.5 rounded-full"
                        style={{ backgroundColor: t.color ?? 'var(--status-leave)' }}
                        aria-hidden
                      />
                      {t.name}
                    </p>
                    <p className="text-muted-foreground text-xs">
                      {t.code} · {t.is_paid ? 'paid' : 'unpaid'}
                    </p>
                  </TableCell>
                  <TableCell className="text-sm">
                    {t.unlimited ? 'Unlimited' : `${formatDays(t.entitlement_days)} · ${accrualLabel[t.accrual].toLowerCase()}`}
                  </TableCell>
                  <TableCell className="hidden text-sm md:table-cell">
                    {t.unlimited || t.carry_forward_max_days === null ? '—' : formatDays(t.carry_forward_max_days)}
                  </TableCell>
                  <TableCell className="hidden text-sm md:table-cell">
                    {t.unlimited || t.negative_limit_days === 0 ? '—' : formatDays(t.negative_limit_days)}
                  </TableCell>
                  <TableCell>
                    <StatusBadge tone={t.is_active ? 'success' : 'neutral'}>{t.is_active ? 'active' : 'inactive'}</StatusBadge>
                  </TableCell>
                  <TableCell className="text-right">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${t.name}`}>
                          <MoreHorizontal />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => setEditing(t)}>
                          <Pencil /> Edit
                        </DropdownMenuItem>
                        <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(t)}>
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
      <TypeDialog target={editing} onClose={() => setEditing(null)} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'leave type'}?`}
        description={<p>Only types never requested and without balances can be deleted; deactivate the others.</p>}
        confirmLabel="Delete"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Leave type deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
