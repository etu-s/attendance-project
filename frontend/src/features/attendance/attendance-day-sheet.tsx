import { useState } from 'react'
import { AlertTriangle, PencilLine } from 'lucide-react'
import { ErrorState } from '@/components/states'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Separator } from '@/components/ui/separator'
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet'
import { Skeleton } from '@/components/ui/skeleton'
import { DayOvertime } from '@/features/overtime/day-overtime'
import { useCan, useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatDateTime, formatLocalDate, formatTime } from '@/lib/format'
import { cn } from '@/lib/utils'
import { useAttendanceDay, type AttendanceDay, type PunchCorrection } from './api'
import { CorrectionDialog, type CorrectionTarget } from './correction-dialog'
import {
  attendanceStatusLabel,
  attendanceStatusTone,
  correctionStatusTone,
  flagLabel,
  formatMinutes,
  punchRoleLabel,
} from './status'
import { zoneLabel } from './time'

function Stat({ label, value, tone }: { label: string; value: string; tone?: 'late' | 'absent' }) {
  return (
    <div className="space-y-0.5">
      <p className="text-muted-foreground text-xs">{label}</p>
      <p
        className={cn(
          'text-sm font-medium tabular-nums',
          tone === 'late' && 'text-status-late',
          tone === 'absent' && 'text-status-absent',
        )}
      >
        {value}
      </p>
    </div>
  )
}

function CorrectionItem({ correction, timezone }: { correction: PunchCorrection; timezone: string }) {
  const prefs = useDisplayPreferences()
  const local = { ...prefs, timezone }

  return (
    <li className="space-y-1 py-2 text-sm">
      <div className="flex flex-wrap items-center gap-2">
        <span className="font-medium">
          {correction.kind === 'add'
            ? `Add punch at ${formatTime(correction.punched_at, local)}`
            : `Ignore punch at ${formatTime(correction.raw_punch?.punched_at, local)}`}
        </span>
        <StatusBadge tone={correctionStatusTone[correction.status]}>{correction.status}</StatusBadge>
      </div>
      <p className="text-muted-foreground">{correction.reason}</p>
      <p className="text-muted-foreground text-xs">
        {correction.requested_by?.name ?? 'Unknown'} · {formatDateTime(correction.created_at, prefs)}
        {correction.decided_by && ` · decided by ${correction.decided_by.name}`}
      </p>
    </li>
  )
}

function DayBody({
  day,
  corrections,
  onCorrect,
}: {
  day: AttendanceDay
  corrections: PunchCorrection[]
  onCorrect?: () => void
}) {
  const prefs = useDisplayPreferences()
  const local = { ...prefs, timezone: day.timezone }

  return (
    <div className="space-y-6 px-4 pb-6">
      <div className="flex flex-wrap items-center gap-2">
        <StatusBadge tone={attendanceStatusTone[day.status]}>{attendanceStatusLabel[day.status]}</StatusBadge>
        {day.holiday_name && <span className="text-status-holiday text-sm">{day.holiday_name}</span>}
        {day.leave_name && (
          <span className="text-status-leave text-sm">
            {day.leave_name}
            {day.leave_portion === 'first_half' && ' (morning)'}
            {day.leave_portion === 'second_half' && ' (afternoon)'}
          </span>
        )}
        <span className="text-muted-foreground text-xs">Times in {zoneLabel(day.timezone)}</span>
      </div>

      <div className="grid grid-cols-3 gap-4">
        <Stat label="In" value={formatTime(day.first_in_at, local)} />
        <Stat label="Out" value={formatTime(day.last_out_at, local)} />
        <Stat label="Worked" value={formatMinutes(day.worked_minutes)} />
        <Stat label="Scheduled" value={formatMinutes(day.scheduled_minutes)} />
        <Stat label="Late" value={formatMinutes(day.late_minutes)} tone={day.late_minutes > 0 ? 'late' : undefined} />
        <Stat
          label="Left early"
          value={formatMinutes(day.early_leave_minutes)}
          tone={day.early_leave_minutes > 0 ? 'late' : undefined}
        />
      </div>

      <DayOvertime employeeId={day.employee_id} workDate={day.work_date} />

      {(day.flags.length > 0 || day.unmatched_punches > 0) && (
        <ul className="space-y-1">
          {day.flags.map((flag) => (
            <li key={flag} className="text-status-late flex items-center gap-2 text-sm">
              <AlertTriangle className="size-4" aria-hidden />
              {flagLabel[flag] ?? flag}
            </li>
          ))}
          {day.unmatched_punches > 0 && (
            <li className="text-muted-foreground flex items-center gap-2 text-sm">
              <AlertTriangle className="size-4" aria-hidden />
              {day.unmatched_punches} punch{day.unmatched_punches === 1 ? '' : 'es'} outside the scheduled times (not counted)
            </li>
          )}
        </ul>
      )}

      {onCorrect && (
        <Button variant="outline" size="sm" onClick={onCorrect}>
          <PencilLine /> Request correction
        </Button>
      )}

      {(day.periods ?? []).length > 0 && (
        <section className="space-y-2" aria-labelledby="periods-title">
          <h3 id="periods-title" className="text-sm font-medium">
            Scheduled
          </h3>
          <ul className="divide-y rounded-lg border">
            {(day.periods ?? []).map((period) => (
              <li key={period.id} className="space-y-1 px-3 py-2 text-sm">
                <div className="flex items-center justify-between gap-2">
                  <span className="font-medium">{period.timetable_name}</span>
                  <span className="text-muted-foreground tabular-nums">
                    {formatTime(period.scheduled_start_at, local)}–{formatTime(period.scheduled_end_at, local)}
                    {period.flexible && ' (flexible)'}
                  </span>
                </div>
                <p className="text-muted-foreground tabular-nums">
                  {formatTime(period.in_at, local)} → {formatTime(period.out_at, local)} · worked{' '}
                  {formatMinutes(period.worked_minutes)}
                  {period.break_minutes > 0 && ` (break ${formatMinutes(period.break_minutes)})`}
                </p>
              </li>
            ))}
          </ul>
        </section>
      )}

      <section className="space-y-2" aria-labelledby="punches-title">
        <h3 id="punches-title" className="text-sm font-medium">
          Punches
        </h3>
        {(day.punches ?? []).length === 0 ? (
          <p className="text-muted-foreground text-sm">No punches.</p>
        ) : (
          <ul className="divide-y rounded-lg border">
            {(day.punches ?? []).map((punch) => (
              <li key={punch.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                <span className="w-14 font-medium tabular-nums">{formatTime(punch.punched_at, local)}</span>
                <StatusBadge
                  tone={punch.role === 'in' || punch.role === 'out' ? 'success' : 'neutral'}
                  className={cn((punch.role === 'ignored' || punch.role === 'duplicate') && 'line-through')}
                >
                  {punchRoleLabel[punch.role]}
                </StatusBadge>
                <span className="text-muted-foreground truncate text-xs">
                  {punch.punch_correction_id ? 'Correction' : punch.source}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>

      {corrections.length > 0 && (
        <section className="space-y-1" aria-labelledby="corrections-title">
          <h3 id="corrections-title" className="text-sm font-medium">
            Corrections
          </h3>
          <ul className="divide-y">
            {corrections.map((c) => (
              <CorrectionItem key={c.id} correction={c} timezone={day.timezone} />
            ))}
          </ul>
        </section>
      )}

      <Separator />
      <p className="text-muted-foreground text-xs">Calculated {formatDateTime(day.calculated_at, prefs)}</p>
    </div>
  )
}

/**
 * One attendance day: schedule vs. punches, and its corrections. Opened from
 * every attendance list and calendar.
 */
export function AttendanceDaySheet({
  dayId,
  employeeName,
  onClose,
}: {
  dayId: number | null
  employeeName?: string
  onClose: () => void
}) {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const day = useAttendanceDay(dayId)
  const [correcting, setCorrecting] = useState<CorrectionTarget | null>(null)
  const data = day.data?.data
  const name = data?.employee?.full_name ?? employeeName ?? 'Attendance'

  return (
    <>
      <Sheet open={dayId !== null} onOpenChange={(open) => !open && onClose()}>
        <SheetContent className="w-full overflow-y-auto sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{name}</SheetTitle>
            <SheetDescription>{data ? formatLocalDate(data.work_date, prefs) : 'Loading…'}</SheetDescription>
          </SheetHeader>
          {day.isError ? (
            <div className="px-4">
              <ErrorState error={day.error} onRetry={() => day.refetch()} />
            </div>
          ) : !data ? (
            <div className="space-y-3 px-4">
              <Skeleton className="h-6 w-32" />
              <Skeleton className="h-24 w-full" />
              <Skeleton className="h-32 w-full" />
            </div>
          ) : (
            <DayBody
              day={data}
              corrections={day.data?.corrections ?? []}
              onCorrect={
                can('corrections.request')
                  ? () =>
                      setCorrecting({
                        employeeId: data.employee_id,
                        employeeName: name,
                        workDate: data.work_date,
                        timezone: data.timezone,
                        punches: data.punches ?? [],
                      })
                  : undefined
              }
            />
          )}
        </SheetContent>
      </Sheet>
      <CorrectionDialog target={correcting} onClose={() => setCorrecting(null)} />
    </>
  )
}
