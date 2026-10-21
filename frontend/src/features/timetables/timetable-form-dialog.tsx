import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Copy, Loader2, Lock, Plus, Trash2 } from 'lucide-react'
import { Controller, useFieldArray, useForm, useWatch, type DeepPartial } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Separator } from '@/components/ui/separator'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { ApiError } from '@/lib/api/client'
import { errorMessage } from '@/lib/forms'
import {
  useDuplicateTimetable,
  useSaveTimetable,
  useTimetable,
  type EffectiveRules,
  type Timetable,
  type TimetablePayload,
} from './api'
import { durationToMinutes, minutesToDuration, minutesToTime, spanMinutes, timeToMinutes } from './time'
import { TimelineBar } from './timeline-bar'

const MAX_BREAKS = 4
const time = z.string().refine((v) => timeToMinutes(v) !== null, 'Enter a time (HH:MM).')
const optionalTime = z.string().refine((v) => v === '' || timeToMinutes(v) !== null, 'Enter a time (HH:MM).')
const optionalMinutes = (max: number) =>
  z.string().refine((v) => v === '' || (/^\d+$/.test(v) && Number(v) <= max), `Whole minutes, up to ${String(max)}.`)

// Mirrors TimetableRequest + the cross-field rules of TimetableService.
const schema = z
  .object({
    name: z.string().trim().min(1, 'Enter a name.').max(120),
    code: z
      .string()
      .trim()
      .min(1, 'Enter a code.')
      .max(30)
      .regex(/^[A-Za-z0-9._-]+$/, 'Letters, numbers, dots, dashes or underscores only.'),
    color: z.string().regex(/^#[0-9a-fA-F]{6}$/),
    type: z.enum(['fixed', 'flexible']),
    start: time,
    end: time,
    required: z.string(),
    core_start: optionalTime,
    core_end: optionalTime,
    late_grace_minutes: optionalMinutes(720),
    early_leave_grace_minutes: optionalMinutes(720),
    check_in_before_minutes: optionalMinutes(720),
    check_out_after_minutes: optionalMinutes(720),
    min_work_minutes: optionalMinutes(1440),
    is_active: z.boolean(),
    breaks: z
      .array(
        z.object({
          kind: z.enum(['fixed', 'floating']),
          start: optionalTime,
          duration: z.string().refine((v) => /^\d+$/.test(v) && Number(v) >= 1 && Number(v) <= 720, '1–720 minutes.'),
          is_paid: z.boolean(),
        }),
      )
      .max(MAX_BREAKS),
  })
  .superRefine((v, ctx) => {
    const start = timeToMinutes(v.start)
    const end = timeToMinutes(v.end)
    if (start === null || end === null) return
    const duration = spanMinutes(start, end)

    if (v.type === 'flexible') {
      const required = durationToMinutes(v.required)
      if (required === null || required < 1) ctx.addIssue({ code: 'custom', path: ['required'], message: 'Enter hours as H:MM.' })
      else if (required > duration) ctx.addIssue({ code: 'custom', path: ['required'], message: 'Longer than the window.' })

      if ((v.core_start === '') !== (v.core_end === ''))
        ctx.addIssue({ code: 'custom', path: ['core_end'], message: 'Set both core start and end, or neither.' })
    }

    v.breaks.forEach((b, i) => {
      if (b.kind === 'fixed' && v.type === 'flexible')
        ctx.addIssue({ code: 'custom', path: ['breaks', i, 'kind'], message: 'Flexible timetables only have floating breaks.' })
      if (b.kind === 'fixed' && timeToMinutes(b.start) === null)
        ctx.addIssue({ code: 'custom', path: ['breaks', i, 'start'], message: 'Set when the break starts.' })
    })
  })

type Values = z.infer<typeof schema>

const empty: Values = {
  name: '',
  code: '',
  color: '#3b82f6',
  type: 'fixed',
  start: '09:00',
  end: '18:00',
  required: '8:00',
  core_start: '',
  core_end: '',
  late_grace_minutes: '',
  early_leave_grace_minutes: '',
  check_in_before_minutes: '',
  check_out_after_minutes: '',
  min_work_minutes: '',
  is_active: true,
  breaks: [],
}

function toValues(t: Timetable): Values {
  const str = (n: number | null) => (n === null ? '' : String(n))

  return {
    name: t.name,
    code: t.code,
    color: t.color ?? '#3b82f6',
    type: t.type,
    start: minutesToTime(t.start_minute),
    end: minutesToTime(t.end_minute),
    required: t.required_minutes === null ? '8:00' : minutesToDuration(t.required_minutes),
    core_start: t.core_start_offset === null ? '' : minutesToTime(t.start_minute + t.core_start_offset),
    core_end:
      t.core_start_offset === null || t.core_duration_minutes === null
        ? ''
        : minutesToTime(t.start_minute + t.core_start_offset + t.core_duration_minutes),
    late_grace_minutes: str(t.late_grace_minutes),
    early_leave_grace_minutes: str(t.early_leave_grace_minutes),
    check_in_before_minutes: str(t.check_in_before_minutes),
    check_out_after_minutes: str(t.check_out_after_minutes),
    min_work_minutes: str(t.min_work_minutes),
    is_active: t.is_active,
    breaks: (t.breaks ?? []).map((b) => ({
      kind: b.kind,
      start: b.start_offset === null ? '' : minutesToTime(t.start_minute + b.start_offset),
      duration: String(b.duration_minutes),
      is_paid: b.is_paid,
    })),
  }
}

function toPayload(v: Values): TimetablePayload {
  const start = timeToMinutes(v.start) ?? 0
  const duration = spanMinutes(start, timeToMinutes(v.end) ?? 0)
  const num = (s: string) => (s === '' ? null : Number(s))
  const coreStart = timeToMinutes(v.core_start)
  const coreEnd = timeToMinutes(v.core_end)
  const flexible = v.type === 'flexible'

  return {
    name: v.name,
    code: v.code,
    color: v.color,
    type: v.type,
    start_minute: start,
    duration_minutes: duration,
    required_minutes: flexible ? durationToMinutes(v.required) : null,
    core_start_offset: flexible && coreStart !== null ? (coreStart - start + 1440) % 1440 : null,
    core_duration_minutes: flexible && coreStart !== null && coreEnd !== null ? spanMinutes(coreStart, coreEnd) : null,
    late_grace_minutes: num(v.late_grace_minutes),
    early_leave_grace_minutes: num(v.early_leave_grace_minutes),
    check_in_before_minutes: num(v.check_in_before_minutes),
    check_out_after_minutes: num(v.check_out_after_minutes),
    min_work_minutes: num(v.min_work_minutes),
    is_active: v.is_active,
    breaks: v.breaks.map((b) => {
      const at = timeToMinutes(b.start)

      return {
        kind: b.kind,
        start_offset: b.kind === 'fixed' && at !== null ? (at - start + 1440) % 1440 : null,
        duration_minutes: Number(b.duration),
        is_paid: b.is_paid,
      }
    }),
  }
}

/**
 * Payload for the live timeline while the form is being edited: null until
 * start and end are valid; incomplete breaks are left out.
 */
function previewOf(w: DeepPartial<Values>): TimetablePayload | null {
  if (timeToMinutes(w.start ?? '') === null || timeToMinutes(w.end ?? '') === null) return null

  const breaks = (w.breaks ?? []).flatMap((b) =>
    b && /^\d+$/.test(b.duration ?? '') && Number(b.duration) > 0
      ? [{ kind: b.kind ?? 'floating', start: b.start ?? '', duration: b.duration ?? '0', is_paid: b.is_paid ?? false }]
      : [],
  )

  return toPayload({ ...empty, ...w, breaks })
}

/** API field (minutes) -> form field (clock text). */
function formField(path: string): string {
  const map: Record<string, string> = {
    start_minute: 'start',
    duration_minutes: 'end',
    required_minutes: 'required',
    core_start_offset: 'core_start',
    core_duration_minutes: 'core_end',
  }
  const breakMatch = /^breaks\.(\d+)\.(start_offset|duration_minutes|kind)$/.exec(path)
  if (breakMatch) {
    const sub = { start_offset: 'start', duration_minutes: 'duration', kind: 'kind' }[
      breakMatch[2] as 'start_offset' | 'duration_minutes' | 'kind'
    ]
    return `breaks.${breakMatch[1] ?? '0'}.${sub}`
  }

  return map[path] ?? path
}

interface TimetableFormDialogProps {
  /** null = closed, 'new' = create, number = edit that timetable */
  target: number | 'new' | null
  onClose: () => void
  onDuplicated?: (id: number) => void
}

export function TimetableFormDialog({ target, onClose, onDuplicated }: TimetableFormDialogProps) {
  const editingId = typeof target === 'number' ? target : null
  const detail = useTimetable(editingId)
  const save = useSaveTimetable()
  const duplicate = useDuplicateTimetable()
  const form = useForm<Values>({ resolver: zodResolver(schema), defaultValues: empty })
  const breaks = useFieldArray({ control: form.control, name: 'breaks' })
  const locked = detail.data?.meta.locked ?? false
  const effective: EffectiveRules | undefined = detail.data?.meta.effective

  useEffect(() => {
    if (target === 'new') form.reset(empty)
    else if (detail.data) form.reset(toValues(detail.data.data))
  }, [target, detail.data, form])

  const watched = useWatch({ control: form.control })
  const preview = previewOf(watched)

  const submit = form.handleSubmit(async (values) => {
    try {
      const payload = toPayload(values)
      await save.mutateAsync({ id: editingId ?? undefined, payload })
      toast.success(editingId ? 'Timetable saved.' : 'Timetable created.')
      onClose()
    } catch (error) {
      if (error instanceof ApiError && error.isValidation) {
        for (const [path, messages] of Object.entries(error.errors)) {
          form.setError(formField(path) as keyof Values, { type: 'server', message: messages[0] ?? error.message })
        }
      } else {
        toast.error(errorMessage(error))
      }
    }
  })

  const errors = form.formState.errors
  const type = watched.type ?? 'fixed'
  const disabledTimes = locked

  return (
    <Dialog open={target !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="max-h-[92svh] overflow-y-auto sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>{editingId ? 'Edit timetable' : 'New timetable'}</DialogTitle>
          <DialogDescription>A work period. Shifts repeat timetables over days or weeks.</DialogDescription>
        </DialogHeader>

        {editingId !== null && detail.isPending ? (
          <div className="space-y-3">
            <Skeleton className="h-9 w-full" />
            <Skeleton className="h-24 w-full" />
          </div>
        ) : (
          <form id="timetable-form" onSubmit={submit} className="grid gap-4" noValidate>
            {locked && (
              <Alert>
                <Lock className="size-4" />
                <AlertDescription className="flex flex-wrap items-center justify-between gap-2">
                  <span>A schedule uses this timetable, so its times are read-only. Duplicate it to make changes.</span>
                  <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={duplicate.isPending}
                    onClick={() =>
                      editingId &&
                      duplicate.mutate(editingId, {
                        onSuccess: (copy) => {
                          toast.success(`Created ${copy.data.name}.`)
                          onDuplicated?.(copy.data.id)
                        },
                        onError: (e) => toast.error(errorMessage(e)),
                      })
                    }
                  >
                    <Copy /> Duplicate
                  </Button>
                </AlertDescription>
              </Alert>
            )}

            <div className="grid gap-4 sm:grid-cols-[2fr_1fr_auto]">
              <Field label="Name" error={errors.name?.message}>
                <Input {...form.register('name')} />
              </Field>
              <Field label="Code" error={errors.code?.message}>
                <Input className="font-mono" {...form.register('code')} />
              </Field>
              <Field label="Colour" error={errors.color?.message}>
                <Input type="color" className="w-14 p-1" {...form.register('color')} />
              </Field>
            </div>

            <fieldset disabled={disabledTimes} className="grid gap-4">
              <div className="grid gap-4 sm:grid-cols-3">
                <Controller
                  control={form.control}
                  name="type"
                  render={({ field }) => (
                    <Field label="Type" error={errors.type?.message}>
                      {(controlProps) => (
                        <Select value={field.value} onValueChange={field.onChange} disabled={disabledTimes}>
                          <SelectTrigger className="w-full" {...controlProps}>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            <SelectItem value="fixed">Fixed hours</SelectItem>
                            <SelectItem value="flexible">Flexible</SelectItem>
                          </SelectContent>
                        </Select>
                      )}
                    </Field>
                  )}
                />
                <Field label={type === 'flexible' ? 'Window from' : 'Start'} error={errors.start?.message}>
                  <Input type="time" {...form.register('start')} />
                </Field>
                <Field
                  label={type === 'flexible' ? 'Window until' : 'End'}
                  error={errors.end?.message}
                  hint={preview && preview.start_minute + preview.duration_minutes > 1440 ? 'Ends the next day.' : undefined}
                >
                  <Input type="time" {...form.register('end')} />
                </Field>
              </div>

              {type === 'flexible' && (
                <div className="grid gap-4 sm:grid-cols-3">
                  <Field label="Required time (H:MM)" error={errors.required?.message} hint="Worked anywhere in the window.">
                    <Input inputMode="numeric" placeholder="8:00" {...form.register('required')} />
                  </Field>
                  <Field label="Core hours from" error={errors.core_start?.message} hint="Optional.">
                    <Input type="time" {...form.register('core_start')} />
                  </Field>
                  <Field label="Core hours until" error={errors.core_end?.message}>
                    <Input type="time" {...form.register('core_end')} />
                  </Field>
                </div>
              )}

              {preview && (
                <TimelineBar
                  type={preview.type}
                  start={preview.start_minute}
                  duration={preview.duration_minutes}
                  breaks={preview.breaks}
                  coreStartOffset={preview.core_start_offset}
                  coreDuration={preview.core_duration_minutes}
                  checkInBefore={preview.check_in_before_minutes ?? effective?.check_in_before_minutes}
                  checkOutAfter={preview.check_out_after_minutes ?? effective?.check_out_after_minutes}
                  color={watched.color}
                />
              )}

              <Separator />

              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <p className="text-sm font-medium">Breaks</p>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={breaks.fields.length >= MAX_BREAKS || disabledTimes}
                    onClick={() =>
                      breaks.append({
                        kind: type === 'flexible' ? 'floating' : 'fixed',
                        start: '',
                        duration: '60',
                        is_paid: false,
                      })
                    }
                  >
                    <Plus /> Add break
                  </Button>
                </div>
                {errors.breaks?.root?.message || errors.breaks?.message ? (
                  <p className="text-destructive text-xs font-medium">{errors.breaks.root?.message ?? errors.breaks.message}</p>
                ) : null}
                {breaks.fields.length === 0 && <p className="text-muted-foreground text-sm">No breaks.</p>}
                {breaks.fields.map((item, i) => {
                  const kind = watched.breaks?.[i]?.kind ?? item.kind
                  const rowErrors = errors.breaks?.[i]

                  return (
                    <div
                      key={item.id}
                      className="grid items-start gap-3 rounded-md border p-3 sm:grid-cols-[1.2fr_1fr_1fr_auto_auto]"
                    >
                      <Controller
                        control={form.control}
                        name={`breaks.${i}.kind`}
                        render={({ field }) => (
                          <Field label="Kind" error={rowErrors?.kind?.message}>
                            {(controlProps) => (
                              <Select value={field.value} onValueChange={field.onChange} disabled={disabledTimes}>
                                <SelectTrigger className="w-full" {...controlProps}>
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="fixed">At a set time</SelectItem>
                                  <SelectItem value="floating">Anytime</SelectItem>
                                </SelectContent>
                              </Select>
                            )}
                          </Field>
                        )}
                      />
                      <Field label="Starts" error={rowErrors?.start?.message}>
                        <Input
                          type="time"
                          disabled={kind === 'floating' || disabledTimes}
                          {...form.register(`breaks.${i}.start`)}
                        />
                      </Field>
                      <Field label="Minutes" error={rowErrors?.duration?.message}>
                        <Input inputMode="numeric" {...form.register(`breaks.${i}.duration`)} />
                      </Field>
                      <Controller
                        control={form.control}
                        name={`breaks.${i}.is_paid`}
                        render={({ field }) => (
                          <label className="flex h-9 items-center gap-2 self-end text-sm">
                            <Checkbox
                              checked={field.value}
                              onCheckedChange={(c) => field.onChange(c === true)}
                              disabled={disabledTimes}
                            />
                            Paid
                          </label>
                        )}
                      />
                      <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="self-end"
                        aria-label={`Remove break ${String(i + 1)}`}
                        onClick={() => breaks.remove(i)}
                      >
                        <Trash2 />
                      </Button>
                    </div>
                  )
                })}
              </div>

              <Separator />

              <div className="space-y-2">
                <p className="text-sm font-medium">Grace and punch windows</p>
                <p className="text-muted-foreground text-xs">Leave empty to use the workspace defaults from Settings.</p>
                <div className="grid gap-4 sm:grid-cols-3">
                  {(
                    [
                      ['late_grace_minutes', 'Late grace (min)'],
                      ['early_leave_grace_minutes', 'Early-leave grace (min)'],
                      ['check_in_before_minutes', 'Check-in from (min before)'],
                      ['check_out_after_minutes', 'Check-out until (min after)'],
                      ['min_work_minutes', 'Minimum worked (min)'],
                    ] as const
                  ).map(([name, label]) => (
                    <Field key={name} label={label} error={errors[name]?.message}>
                      <Input
                        inputMode="numeric"
                        placeholder={
                          name === 'min_work_minutes'
                            ? 'None'
                            : effective && form.getValues(name) === ''
                              ? `Default (${String(effective[name])})`
                              : 'Default'
                        }
                        {...form.register(name)}
                      />
                    </Field>
                  ))}
                </div>
              </div>
            </fieldset>

            <Controller
              control={form.control}
              name="is_active"
              render={({ field }) => (
                <div className="flex items-center justify-between rounded-lg border p-3">
                  <div>
                    <Label htmlFor="timetable-active">Active</Label>
                    <p className="text-muted-foreground text-xs">Inactive timetables cannot be added to shifts.</p>
                  </div>
                  <Switch id="timetable-active" checked={field.value} onCheckedChange={field.onChange} />
                </div>
              )}
            />
          </form>
        )}

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button
            type="submit"
            form="timetable-form"
            disabled={form.formState.isSubmitting || (editingId !== null && detail.isPending)}
          >
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            {editingId ? 'Save changes' : 'Create timetable'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
