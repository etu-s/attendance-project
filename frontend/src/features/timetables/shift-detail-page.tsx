import { useMemo, useState } from 'react'
import { ArrowLeft, Loader2, Lock, Pencil, Plus, X } from 'lucide-react'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { ErrorState, PageHeader } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { useCan, useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { ApiError } from '@/lib/api/client'
import { formatLocalDate, formatTime, todayIn } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useSaveShiftDays, useShift, useShiftPreview, useTimetableOptions, type Shift, type ShiftDayTimetable } from './api'
import { ShiftFormDialog } from './shift-form-dialog'
import { cycleDayLabel, cycleLabel, formatDuration, timeRange } from './time'
import { ColorDot } from './timetables-page'

const MAX_PER_DAY = 3
const PREVIEW_DAYS = 28

type Draft = Record<number, ShiftDayTimetable[]>

function draftFrom(shift: Shift): Draft {
  return Object.fromEntries((shift.days ?? []).map((d) => [d.day_index, d.timetables]))
}

function addDays(date: string, days: number): string {
  const d = new Date(`${date}T12:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)

  return d.toISOString().slice(0, 10)
}

function CycleEditor({ shift, locked, editable }: { shift: Shift; locked: boolean; editable: boolean }) {
  const options = useTimetableOptions()
  const save = useSaveShiftDays(shift.id)
  const [draft, setDraft] = useState<Draft>(() => draftFrom(shift))
  const [source, setSource] = useState(shift)
  const [errors, setErrors] = useState<Record<number, string>>({})

  // A new server version (after save or refetch) replaces the draft.
  if (shift !== source) {
    setSource(shift)
    setDraft(draftFrom(shift))
  }

  const canEdit = editable && !locked
  const dirty = JSON.stringify(draft) !== JSON.stringify(draftFrom(shift))

  const update = (index: number, next: ShiftDayTimetable[]) => {
    setDraft((d) => ({ ...d, [index]: [...next].sort((a, b) => a.start_minute - b.start_minute) }))
    setErrors((e) => Object.fromEntries(Object.entries(e).filter(([key]) => Number(key) !== index)))
  }

  const submit = () => {
    const days = Object.entries(draft)
      .filter(([, timetables]) => timetables.length > 0)
      .map(([index, timetables]) => ({ day_index: Number(index), timetable_ids: timetables.map((t) => t.id) }))

    save.mutate(days, {
      onSuccess: () => {
        setErrors({})
        toast.success('Cycle saved.')
      },
      onError: (error) => {
        if (error instanceof ApiError && error.isValidation) {
          const mapped: Record<number, string> = {}
          for (const [path, messages] of Object.entries(error.errors)) {
            const index = Number(/^days\.(\d+)$/.exec(path)?.[1] ?? NaN)
            if (!Number.isNaN(index)) mapped[index] = messages[0] ?? error.message
          }
          setErrors(mapped)
          toast.error(Object.keys(mapped).length ? 'Some days need attention.' : error.message)
        } else {
          toast.error(errorMessage(error))
        }
      },
    })
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Cycle</CardTitle>
        <CardDescription>
          {shift.cycle_unit === 'week'
            ? 'Each week starts on Monday. Days without a timetable are rest days.'
            : 'Day 1 is the date the shift starts for an employee. Days without a timetable are rest days.'}
        </CardDescription>
      </CardHeader>
      <CardContent className="divide-y p-0">
        {Array.from({ length: shift.cycle_days }, (_, index) => {
          const timetables = draft[index] ?? []
          const available = (options.data?.data ?? []).filter((t) => !timetables.some((x) => x.id === t.id))

          return (
            <div key={index} className="flex flex-col gap-2 px-6 py-3 sm:flex-row sm:items-center">
              <p className="w-32 shrink-0 text-sm font-medium">{cycleDayLabel(index, shift.cycle_unit, shift.cycle_length)}</p>
              <div className="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                {timetables.length === 0 && <span className="text-muted-foreground text-sm">Rest day</span>}
                {timetables.map((t) => (
                  <span key={t.id} className="inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-sm">
                    <ColorDot color={t.color} />
                    {t.name}
                    <span className="text-muted-foreground text-xs tabular-nums">
                      {timeRange(t.start_minute, t.duration_minutes)}
                    </span>
                    {canEdit && (
                      <button
                        type="button"
                        className="text-muted-foreground hover:bg-accent hover:text-foreground focus-visible:ring-ring rounded p-0.5 focus-visible:ring-2 focus-visible:outline-none"
                        aria-label={`Remove ${t.name} from ${cycleDayLabel(index, shift.cycle_unit, shift.cycle_length)}`}
                        onClick={() =>
                          update(
                            index,
                            timetables.filter((x) => x.id !== t.id),
                          )
                        }
                      >
                        <X className="size-3.5" />
                      </button>
                    )}
                  </span>
                ))}
                {canEdit && timetables.length < MAX_PER_DAY && available.length > 0 && (
                  <Select
                    value=""
                    onValueChange={(id) => {
                      const t = available.find((x) => String(x.id) === id)
                      if (t) update(index, [...timetables, t])
                    }}
                  >
                    <SelectTrigger
                      size="sm"
                      className="text-muted-foreground w-auto gap-1 border-dashed"
                      aria-label={`Add a timetable to ${cycleDayLabel(index, shift.cycle_unit, shift.cycle_length)}`}
                    >
                      <Plus className="size-3.5" />
                      <SelectValue placeholder="Add" />
                    </SelectTrigger>
                    <SelectContent>
                      {available.map((t) => (
                        <SelectItem key={t.id} value={String(t.id)}>
                          {t.name} · {timeRange(t.start_minute, t.duration_minutes)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </div>
              {errors[index] && <p className="text-destructive text-xs font-medium sm:max-w-64">{errors[index]}</p>}
            </div>
          )
        })}
      </CardContent>
      {canEdit && (
        <CardFooter className="justify-end gap-2 border-t pt-6">
          <Button
            variant="outline"
            disabled={!dirty || save.isPending}
            onClick={() => {
              setDraft(draftFrom(shift))
              setErrors({})
            }}
          >
            Discard
          </Button>
          <Button disabled={!dirty || save.isPending} onClick={submit}>
            {save.isPending && <Loader2 className="animate-spin" />}
            Save cycle
          </Button>
        </CardFooter>
      )}
    </Card>
  )
}

function Preview({ shift }: { shift: Shift }) {
  const { workspace } = useSession()
  const prefs = useDisplayPreferences()
  const [anchor, setAnchor] = useState(() => todayIn(workspace.timezone))
  const params = useMemo(() => ({ anchor, from: anchor, to: addDays(anchor, PREVIEW_DAYS - 1) }), [anchor])
  const preview = useShiftPreview(shift.id, params)

  return (
    <Card>
      <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div className="space-y-1.5">
          <CardTitle>Preview</CardTitle>
          <CardDescription>
            The next {PREVIEW_DAYS} days if the cycle starts on the chosen date, in{' '}
            {preview.data?.meta.timezone ?? workspace.timezone}.
          </CardDescription>
        </div>
        <div className="grid gap-1.5">
          <Label htmlFor="preview-anchor">Cycle starts</Label>
          <Input
            id="preview-anchor"
            type="date"
            value={anchor}
            onChange={(e) => e.target.value && setAnchor(e.target.value)}
            className="w-44"
          />
        </div>
      </CardHeader>
      <CardContent>
        {preview.isError ? (
          <ErrorState error={preview.error} onRetry={() => preview.refetch()} />
        ) : preview.isPending ? (
          <Skeleton className="h-48 w-full" />
        ) : (
          <ul className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
            {preview.data.data.map((day) => {
              const weekday = new Date(`${day.date}T12:00:00Z`).toLocaleDateString('en-GB', { weekday: 'short', timeZone: 'UTC' })

              return (
                <li key={day.date} className={`rounded-md border p-2.5 text-sm ${day.periods.length === 0 ? 'bg-muted/40' : ''}`}>
                  <p className="flex justify-between font-medium">
                    <span>
                      {weekday} {formatLocalDate(day.date, prefs)}
                    </span>
                    <span className="text-muted-foreground text-xs font-normal">
                      {cycleDayLabel(day.day_index, shift.cycle_unit, shift.cycle_length)}
                    </span>
                  </p>
                  {day.periods.length === 0 ? (
                    <p className="text-muted-foreground text-xs">Rest day</p>
                  ) : (
                    day.periods.map((p, i) => (
                      <div key={i} className="mt-1 text-xs">
                        <p className="flex items-center gap-1.5">
                          <ColorDot color={p.timetable.color} />
                          <span className="truncate">{p.timetable.name}</span>
                        </p>
                        <p className="text-muted-foreground pl-4 tabular-nums">
                          {formatTime(p.starts_at, prefs)}–{formatTime(p.ends_at, prefs)} · {formatDuration(p.minutes)}
                        </p>
                      </div>
                    ))
                  )}
                </li>
              )
            })}
          </ul>
        )}
      </CardContent>
    </Card>
  )
}

export function ShiftDetailPage() {
  const { id } = useParams()
  const can = useCan()
  const query = useShift(Number(id))
  const [editing, setEditing] = useState(false)

  if (query.isError) return <ErrorState error={query.error} onRetry={() => query.refetch()} />
  if (query.isPending) {
    return (
      <div className="space-y-6">
        <Skeleton className="h-8 w-64" />
        <Skeleton className="h-72 w-full" />
      </div>
    )
  }

  const shift = query.data.data
  const locked = query.data.meta.locked
  const manage = can('shifts.manage')

  return (
    <>
      <Link to="/shifts" className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-sm">
        <ArrowLeft className="size-4" /> Shifts
      </Link>
      <PageHeader
        title={shift.name}
        description={[shift.code, `repeats every ${cycleLabel(shift)}`, shift.description].filter(Boolean).join(' · ')}
        actions={
          <>
            <StatusBadge tone={shift.is_active ? 'success' : 'neutral'} className="self-center">
              {shift.is_active ? 'active' : 'inactive'}
            </StatusBadge>
            {manage && (
              <Button variant="outline" onClick={() => setEditing(true)}>
                <Pencil /> Edit
              </Button>
            )}
          </>
        }
      />
      {locked && (
        <Alert>
          <Lock className="size-4" />
          <AlertDescription>
            A schedule uses this shift, so its cycle is read-only. Duplicate it to make changes.
          </AlertDescription>
        </Alert>
      )}
      <CycleEditor shift={shift} locked={locked} editable={manage} />
      <Preview shift={shift} />
      <ShiftFormDialog open={editing} onOpenChange={setEditing} shift={shift} cycleLocked={locked} />
    </>
  )
}
