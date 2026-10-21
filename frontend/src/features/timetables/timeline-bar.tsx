import type { TimetableBreak, TimetableType } from './api'
import { formatDuration, timeRange } from './time'

interface TimelineBarProps {
  type: TimetableType
  start: number
  duration: number
  breaks: TimetableBreak[]
  coreStartOffset?: number | null
  coreDuration?: number | null
  checkInBefore?: number
  checkOutAfter?: number
  color?: string | null
}

const DAY = 1440

/** Splits [from, to) on a 0–24 h axis into at most two pieces (wrapping past midnight). */
function pieces(from: number, to: number): [number, number][] {
  const a = ((from % DAY) + DAY) % DAY
  const length = Math.min(to - from, DAY)
  const b = a + length

  return b <= DAY
    ? [[a, b]]
    : [
        [a, DAY],
        [0, b - DAY],
      ]
}

function Segment({ from, to, className, style }: { from: number; to: number; className: string; style?: React.CSSProperties }) {
  return (
    <>
      {pieces(from, to).map(([a, b]) => (
        <div
          key={`${String(a)}-${String(b)}`}
          className={`absolute inset-y-0 ${className}`}
          style={{ left: `${String((a / DAY) * 100)}%`, width: `${String(((b - a) / DAY) * 100)}%`, ...style }}
        />
      ))}
    </>
  )
}

/**
 * A 24-hour strip: punch windows (light), work or flexible window (solid),
 * core hours (outlined) and fixed breaks (striped). An overnight timetable
 * wraps from the right edge back to the left.
 */
export function TimelineBar({
  type,
  start,
  duration,
  breaks,
  coreStartOffset,
  coreDuration,
  checkInBefore,
  checkOutAfter,
  color,
}: TimelineBarProps) {
  const fixedBreaks = breaks.filter((b) => b.kind === 'fixed' && b.start_offset !== null)
  const floating = breaks.filter((b) => b.kind === 'floating')
  const summary = [
    `${type === 'flexible' ? 'Window' : 'Work'} ${timeRange(start, duration)}`,
    ...fixedBreaks.map((b) => `break ${timeRange(start + (b.start_offset ?? 0), b.duration_minutes)}`),
    ...floating.map((b) => `${formatDuration(b.duration_minutes)} floating break`),
    coreStartOffset != null && coreDuration ? `core hours ${timeRange(start + coreStartOffset, coreDuration)}` : null,
  ]
    .filter(Boolean)
    .join(', ')

  return (
    <div className="space-y-1">
      <div role="img" aria-label={summary} className="bg-muted/40 relative h-7 overflow-hidden rounded-md border">
        {checkInBefore !== undefined && <Segment from={start - checkInBefore} to={start} className="bg-primary/10" />}
        {checkOutAfter !== undefined && (
          <Segment from={start + duration} to={start + duration + checkOutAfter} className="bg-primary/10" />
        )}
        <Segment
          from={start}
          to={start + duration}
          className={type === 'flexible' ? 'opacity-40' : 'opacity-90'}
          style={{ backgroundColor: color ?? 'var(--primary)' }}
        />
        {coreStartOffset != null && coreDuration ? (
          <Segment
            from={start + coreStartOffset}
            to={start + coreStartOffset + coreDuration}
            className="opacity-90"
            style={{ backgroundColor: color ?? 'var(--primary)' }}
          />
        ) : null}
        {fixedBreaks.map((b, i) => (
          <Segment
            key={i}
            from={start + (b.start_offset ?? 0)}
            to={start + (b.start_offset ?? 0) + b.duration_minutes}
            className="bg-[repeating-linear-gradient(45deg,var(--background)_0_3px,transparent_3px_6px)]"
          />
        ))}
        {[6, 12, 18].map((h) => (
          <div key={h} className="bg-border absolute inset-y-0 w-px" style={{ left: `${String((h / 24) * 100)}%` }} />
        ))}
      </div>
      <div className="text-muted-foreground flex justify-between text-[10px] tabular-nums" aria-hidden>
        {[0, 6, 12, 18, 24].map((h) => (
          <span key={h}>{`${String(h).padStart(2, '0')}:00`}</span>
        ))}
      </div>
    </div>
  )
}
