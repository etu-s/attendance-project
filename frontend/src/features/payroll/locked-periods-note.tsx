import { Lock } from 'lucide-react'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatLocalDate } from '@/lib/format'
import type { LockedPeriodRef } from './api'

/** Shown on reports whose range includes locked payroll days (their figures are final). */
export function LockedPeriodsNote({ periods }: { periods: LockedPeriodRef[] | undefined }) {
  const prefs = useDisplayPreferences()

  if (!periods || periods.length === 0) return null

  return (
    <p className="text-muted-foreground flex items-start gap-2 text-sm" role="note">
      <Lock className="mt-0.5 size-4 shrink-0" aria-hidden />
      <span>
        Includes the locked payroll {periods.length === 1 ? 'period' : 'periods'}{' '}
        {periods.map((p, i) => (
          <span key={p.id}>
            {i > 0 && ', '}
            <span className="text-foreground font-medium">{p.name}</span> ({formatLocalDate(p.start_date, prefs)} –{' '}
            {formatLocalDate(p.end_date, prefs)})
          </span>
        ))}
        : these days are final.
      </span>
    </p>
  )
}
