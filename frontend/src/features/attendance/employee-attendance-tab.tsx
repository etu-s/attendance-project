import { useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2, Plus, RotateCw, Trash2 } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Field } from '@/components/form-field'
import { ErrorState } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { monthEnd, monthStart } from '@/features/scheduling/dates'
import { MonthNav } from '@/features/scheduling/schedule-calendar'
import { useCan, useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { todayIn } from '@/lib/format'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import {
  useAddPunchIdentifier,
  useDeletePunchIdentifier,
  useEmployeeAttendance,
  usePunchIdentifiers,
  useRecalculate,
  type AttendanceDay,
  type PunchIdentifier,
} from './api'
import { AttendanceCalendar } from './attendance-calendar'
import { AttendanceDaySheet } from './attendance-day-sheet'
import { datesBetween } from './time'

// Mirrors PunchIdentifierRequest (uniqueness is checked by the API).
const identifierSchema = z.object({
  identifier: z
    .string()
    .trim()
    .min(1, 'Enter the badge or card number.')
    .max(64)
    .regex(/^[^\p{Cc}]+$/u, 'No control characters.'),
  label: z.string().trim().max(60),
})

type IdentifierValues = z.infer<typeof identifierSchema>

/** Badge / card numbers this employee's punches may arrive with, besides the employee code. */
function PunchIdentifiers({ employeeId, employeeCode }: { employeeId: number; employeeCode: string }) {
  const can = useCan()
  const manage = can('punches.manage')
  const identifiers = usePunchIdentifiers(employeeId)
  const add = useAddPunchIdentifier(employeeId)
  const remove = useDeletePunchIdentifier()
  const [deleting, setDeleting] = useState<PunchIdentifier | null>(null)
  const form = useForm<IdentifierValues>({
    resolver: zodResolver(identifierSchema),
    defaultValues: { identifier: '', label: '' },
  })

  const submit = form.handleSubmit(async (v) => {
    try {
      await add.mutateAsync({ identifier: v.identifier, label: v.label || null })
      toast.success('Identifier added. Matching unmatched punches were linked.')
      form.reset()
    } catch (error) {
      applyServerErrors(error, form.setError, ['identifier', 'label'])
    }
  })

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Punch identifiers</CardTitle>
        <CardDescription>
          Punches arriving with the employee code <span className="font-medium">{employeeCode}</span> or one of these belong to
          this employee.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {identifiers.isError ? (
          <ErrorState error={identifiers.error} onRetry={() => identifiers.refetch()} />
        ) : identifiers.isPending ? (
          <Skeleton className="h-10 w-full" />
        ) : identifiers.data.length === 0 ? (
          <p className="text-muted-foreground text-sm">No extra identifiers.</p>
        ) : (
          <ul className="divide-y rounded-lg border">
            {identifiers.data.map((i) => (
              <li key={i.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                <span className="font-medium">{i.identifier}</span>
                {i.label && <span className="text-muted-foreground">{i.label}</span>}
                {manage && (
                  <Button
                    variant="ghost"
                    size="icon"
                    className="ml-auto size-8"
                    aria-label={`Remove ${i.identifier}`}
                    onClick={() => setDeleting(i)}
                  >
                    <Trash2 />
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
        {manage && (
          <form onSubmit={submit} className="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start" noValidate>
            <Field label="Identifier" error={form.formState.errors.identifier?.message}>
              <Input placeholder="e.g. 004512" {...form.register('identifier')} />
            </Field>
            <Field label="Label" error={form.formState.errors.label?.message}>
              <Input placeholder="e.g. Access card" {...form.register('label')} />
            </Field>
            <Button type="submit" className="sm:mt-[1.375rem]" disabled={form.formState.isSubmitting}>
              {form.formState.isSubmitting ? <Loader2 className="animate-spin" /> : <Plus />} Add
            </Button>
          </form>
        )}
      </CardContent>
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Remove ${deleting?.identifier ?? 'identifier'}?`}
        description={<p>Punches already matched stay with this employee. New punches with it will be unmatched.</p>}
        confirmLabel="Remove"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Identifier removed.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </Card>
  )
}

/** "Attendance" tab of an employee profile. */
export function EmployeeAttendanceTab({
  employeeId,
  employeeCode,
  employeeName,
}: {
  employeeId: number
  employeeCode: string
  employeeName: string
}) {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const [month, setMonth] = useState(monthStart(today))
  const attendance = useEmployeeAttendance(employeeId, month, monthEnd(month))
  const recalculate = useRecalculate()
  const [open, setOpen] = useState<AttendanceDay | null>(null)

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <MonthNav month={month} onChange={setMonth} today={today} />
        {can('attendance.manage') && (
          <Button
            variant="outline"
            size="sm"
            disabled={recalculate.isPending || month > today}
            onClick={() =>
              recalculate.mutate(
                { from: month, to: monthEnd(month) < today ? monthEnd(month) : today, employee_id: employeeId },
                {
                  onSuccess: () => toast.success('Recalculation queued.'),
                  onError: (e) => toast.error(errorMessage(e)),
                },
              )
            }
          >
            <RotateCw className={recalculate.isPending ? 'animate-spin' : undefined} /> Recalculate month
          </Button>
        )}
      </div>
      {attendance.isError ? (
        <ErrorState error={attendance.error} onRetry={() => attendance.refetch()} />
      ) : (
        <AttendanceCalendar
          dates={datesBetween(month, monthEnd(month))}
          days={attendance.data}
          loading={attendance.isPending}
          weekStart={workspace.week_start}
          today={today}
          prefs={prefs}
          onOpen={setOpen}
        />
      )}
      {can('punches.view') && <PunchIdentifiers employeeId={employeeId} employeeCode={employeeCode} />}
      <AttendanceDaySheet dayId={open?.id ?? null} employeeName={employeeName} onClose={() => setOpen(null)} />
    </div>
  )
}
