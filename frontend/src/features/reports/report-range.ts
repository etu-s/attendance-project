import { addDays, monthEnd, monthStart } from '@/features/scheduling/dates'
import { useSession } from '@/features/tenant-auth/hooks'
import type { ListState } from '@/hooks/use-list-params'
import { todayIn } from '@/lib/format'
import type { ReportParams } from './api'

/** Longest range on screen; mirrors ams.reports.max_view_days. */
export const MAX_VIEW_DAYS = 92

export const reportFilterNames = ['from', 'to', 'department_id', 'location_id', 'group_id']

function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T12:00:00Z`) - Date.parse(`${from}T12:00:00Z`)) / 86_400_000) + 1
}

/**
 * The range and filters of a report, from the URL (shareable). Defaults to
 * this month; a range longer than the server allows is reported instead of
 * being sent.
 */
export function useReportRange(list: ListState, fallback: 'month' | 'last30' = 'month') {
  const { workspace } = useSession()
  const today = todayIn(workspace.timezone)
  const from = list.filter('from') || (fallback === 'month' ? monthStart(today) : addDays(today, -29))
  const to = list.filter('to') || (fallback === 'month' ? monthEnd(monthStart(today)) : today)
  const days = daysBetween(from, to)
  const error =
    days < 1
      ? 'The end date is before the start date.'
      : days > MAX_VIEW_DAYS
        ? `Pick at most ${String(MAX_VIEW_DAYS)} days.`
        : null

  const params: ReportParams = { from, to }
  for (const name of ['department_id', 'location_id', 'group_id']) {
    const value = list.filter(name)
    if (value) params[name] = value
  }

  return { from, to, today, error, params }
}
