import { formatTime, type DisplayPreferences } from '@/lib/format'
import type { DayStatus, ResolvedDay } from './api'

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'holiday' | 'neutral'

/*
 * Scheduled state, not attendance: "working" is neutral until Module 5 turns
 * it into present/late/absent. Holiday and leave-like states reuse the shared
 * status colours.
 */
export const dayStatusLabel: Record<DayStatus, string> = {
  working: 'Working',
  rest: 'Rest day',
  holiday: 'Holiday',
  day_off: 'Day off',
  unscheduled: 'No schedule',
  not_employed: 'Not employed',
}

export const dayStatusTone: Record<DayStatus, Tone> = {
  working: 'success',
  rest: 'neutral',
  holiday: 'holiday',
  day_off: 'info',
  unscheduled: 'warning',
  not_employed: 'neutral',
}

/** Background classes for compact cells (calendar, roster). */
export const dayCellClass: Record<DayStatus, string> = {
  working: 'bg-card',
  rest: 'bg-muted/50 text-muted-foreground',
  holiday: 'bg-status-holiday/10',
  day_off: 'bg-status-leave/10',
  unscheduled: 'bg-status-late/10',
  not_employed: 'bg-muted/30 text-muted-foreground/60',
}

/** "Department · Operations (inherited)" */
export function sourceLabel(day: ResolvedDay): string {
  if (!day.source) return dayStatusLabel[day.status]
  const kind = {
    temporary: 'Temporary',
    employee: 'Personal',
    group: 'Group',
    department: 'Department',
    organization: 'Organization',
  }[day.source.type]
  const name = day.source.type === 'organization' || !day.source.name ? '' : ` · ${day.source.name}`
  const inherited = day.source.inherited_from ? ' (inherited)' : ''

  return `${kind}${name}${inherited}`
}

/** Accessible description of a day for screen readers. */
export function dayDescription(day: ResolvedDay, prefs: DisplayPreferences): string {
  const local = { ...prefs, timezone: day.timezone }
  const times = day.periods.map((p) => `${formatTime(p.starts_at, local)} to ${formatTime(p.ends_at, local)}`).join(', ')
  const state = day.status === 'holiday' ? `Holiday: ${day.holiday ?? ''}` : dayStatusLabel[day.status]

  return [state, day.status === 'working' ? times : '', day.source ? sourceLabel(day) : ''].filter(Boolean).join('. ')
}

/** Where a dated period (inclusive; null end = open) stands relative to today. */
export function periodState(from: string, to: string | null, today: string): { tone: Tone; label: string } {
  if (from > today) return { tone: 'info', label: 'planned' }
  if (to !== null && to < today) return { tone: 'neutral', label: 'ended' }
  return { tone: 'success', label: 'current' }
}
