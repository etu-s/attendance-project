import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral'

/*
 * Tones reuse the attendance status tokens (index.css) so every module shares
 * one palette: success = present, warning = late, danger = absent, info = leave.
 */
const tones: Record<Tone, string> = {
  success: 'border-status-present/30 bg-status-present/10 text-status-present',
  warning: 'border-status-late/30 bg-status-late/10 text-status-late',
  danger: 'border-status-absent/30 bg-status-absent/10 text-status-absent',
  info: 'border-status-leave/30 bg-status-leave/10 text-status-leave',
  neutral: 'border-border bg-muted text-muted-foreground',
}

export function StatusBadge({ tone, children, className }: { tone: Tone; children: string; className?: string }) {
  return (
    <Badge variant="outline" className={cn('font-medium capitalize', tones[tone], className)}>
      {children}
    </Badge>
  )
}
