import { useState } from 'react'
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Button } from '@/components/ui/button'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { dayOfMonth, weekdayLabel } from '@/features/scheduling/dates'
import { useDisplayPreferences } from '@/features/tenant-auth/hooks'
import { formatLocalDate } from '@/lib/format'
import type { Dashboard } from './api'
import { formatRate } from './format'

type TrendPoint = Dashboard['trend'][number]

/*
 * Status colors are the workspace's reserved attendance tokens (index.css).
 * In light mode late (amber) sits too close to present and absent for
 * colour-blind and even full-colour readers (checked with the palette
 * validator), so "late" also carries a hatch: identity never rests on colour
 * alone. Legend, tooltip and a table view carry the values.
 */
const series = [
  { key: 'on_time', label: 'On time', color: 'var(--status-present)', hatch: false },
  { key: 'late', label: 'Late', color: 'var(--status-late)', hatch: true },
  { key: 'absent', label: 'Absent', color: 'var(--status-absent)', hatch: false },
  { key: 'on_leave', label: 'On leave', color: 'var(--status-leave)', hatch: false },
] as const

const HATCH_ID = 'report-trend-late-hatch'

function Swatch({ color, hatch }: { color: string; hatch: boolean }) {
  return (
    <svg className="size-3 shrink-0 rounded-[3px]" viewBox="0 0 12 12" aria-hidden>
      <rect width="12" height="12" fill={color} />
      {hatch && <path d="M-3 3 L3 -3 M0 12 L12 0 M9 15 L15 9" stroke="var(--card)" strokeWidth="2" />}
    </svg>
  )
}

export function TrendLegend() {
  return (
    <ul className="text-muted-foreground flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Legend">
      {series.map((s) => (
        <li key={s.key} className="flex items-center gap-1.5">
          <Swatch color={s.color} hatch={s.hatch} />
          {s.label}
        </li>
      ))}
    </ul>
  )
}

function TrendTooltip({ point }: { point: TrendPoint }) {
  const prefs = useDisplayPreferences()

  return (
    <div className="bg-popover text-popover-foreground rounded-md border px-3 py-2 text-xs shadow-md">
      <p className="mb-1 font-medium">{formatLocalDate(point.date, prefs)}</p>
      <dl className="grid grid-cols-[auto_auto] gap-x-4 gap-y-0.5">
        {series.map((s) => (
          <div key={s.key} className="contents">
            <dt className="flex items-center gap-1.5">
              <Swatch color={s.color} hatch={s.hatch} />
              {s.label}
            </dt>
            <dd className="text-right tabular-nums">{point[s.key]}</dd>
          </div>
        ))}
        <dt className="text-muted-foreground">Attendance rate</dt>
        <dd className="text-right tabular-nums">{formatRate(point.attendance_rate)}</dd>
      </dl>
    </div>
  )
}

/**
 * Employees per day by outcome (stacked columns: one whole per day). One
 * y-axis, counts of people.
 */
export function TrendChart({ trend }: { trend: TrendPoint[] }) {
  const prefs = useDisplayPreferences()
  const [asTable, setAsTable] = useState(false)
  const dense = trend.length > 31

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <TrendLegend />
        <Button variant="ghost" size="sm" onClick={() => setAsTable((v) => !v)} aria-pressed={asTable}>
          {asTable ? 'Show chart' : 'Show table'}
        </Button>
      </div>
      {asTable ? (
        <div className="max-h-80 overflow-auto rounded-lg border">
          <Table>
            <caption className="sr-only">Employees per day by outcome</caption>
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                {series.map((s) => (
                  <TableHead key={s.key} className="text-right">
                    {s.label}
                  </TableHead>
                ))}
                <TableHead className="text-right">Rate</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {trend.map((p) => (
                <TableRow key={p.date}>
                  <TableCell>{formatLocalDate(p.date, prefs)}</TableCell>
                  {series.map((s) => (
                    <TableCell key={s.key} className="text-right tabular-nums">
                      {p[s.key]}
                    </TableCell>
                  ))}
                  <TableCell className="text-right tabular-nums">{formatRate(p.attendance_rate)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      ) : (
        <div className="h-72" role="img" aria-label="Stacked columns of employees per day: on time, late, absent and on leave">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={trend} margin={{ top: 8, right: 8, bottom: 0, left: -16 }} barCategoryGap="20%">
              <defs>
                <pattern id={HATCH_ID} width="6" height="6" patternUnits="userSpaceOnUse" patternTransform="rotate(45)">
                  <rect width="6" height="6" fill="var(--status-late)" />
                  <line x1="0" y1="0" x2="0" y2="6" stroke="var(--card)" strokeWidth="2" />
                </pattern>
              </defs>
              <CartesianGrid vertical={false} stroke="var(--border)" />
              <XAxis
                dataKey="date"
                tickLine={false}
                axisLine={{ stroke: 'var(--border)' }}
                tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }}
                interval={dense ? 'preserveStartEnd' : 0}
                minTickGap={4}
                tickFormatter={(d: string) =>
                  trend.length <= 14 ? `${weekdayLabel(d, 'narrow')} ${String(dayOfMonth(d))}` : String(dayOfMonth(d))
                }
              />
              <YAxis
                allowDecimals={false}
                tickLine={false}
                axisLine={false}
                tick={{ fill: 'var(--muted-foreground)', fontSize: 11 }}
              />
              <Tooltip
                cursor={{ fill: 'var(--muted)', opacity: 0.6 }}
                content={({ active, payload }) => {
                  const point = payload[0]?.payload as TrendPoint | undefined

                  return active && point ? <TrendTooltip point={point} /> : null
                }}
              />
              {series.map((s, i) => (
                <Bar
                  key={s.key}
                  dataKey={s.key}
                  name={s.label}
                  stackId="day"
                  fill={s.hatch ? `url(#${HATCH_ID})` : s.color}
                  // The 2px surface-coloured edge is the gap between stacked segments.
                  stroke="var(--card)"
                  strokeWidth={2}
                  maxBarSize={24}
                  radius={i === series.length - 1 ? [4, 4, 0, 0] : 0}
                  isAnimationActive={false}
                />
              ))}
            </BarChart>
          </ResponsiveContainer>
        </div>
      )}
    </div>
  )
}
