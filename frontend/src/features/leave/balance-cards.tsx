import { EmptyState, ErrorState } from '@/components/states'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import type { LeaveBalance } from './api'
import { formatDays } from './format'

function Swatch({ color }: { color: string | null }) {
  return (
    <span
      className="inline-block size-2.5 shrink-0 rounded-full"
      style={{ backgroundColor: color ?? 'var(--status-leave)' }}
      aria-hidden
    />
  )
}

/** One card per leave type: what is available, and how it adds up. */
export function BalanceCards({
  balances,
  loading,
  error,
  onRetry,
}: {
  balances: LeaveBalance[] | undefined
  loading: boolean
  error: unknown
  onRetry: () => void
}) {
  if (error) {
    return <ErrorState error={error} onRetry={onRetry} />
  }
  if (loading || !balances) {
    return (
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        {Array.from({ length: 3 }, (_, i) => (
          <Skeleton key={i} className="h-28 w-full" />
        ))}
      </div>
    )
  }
  if (balances.length === 0) {
    return <EmptyState title="No leave types yet" description="HR sets up leave types (annual, sick, …) in Leave types." />
  }

  return (
    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
      {balances.map((b) => (
        <Card key={b.type.id} className="gap-0 py-4">
          <CardContent className="space-y-2 px-4">
            <p className="flex items-center gap-2 text-sm font-medium">
              <Swatch color={b.type.color} />
              {b.type.name}
              {!b.type.is_paid && <span className="text-muted-foreground text-xs font-normal">unpaid</span>}
            </p>
            {b.available === null ? (
              <p className="text-2xl font-semibold">No limit</p>
            ) : (
              <p
                className={
                  b.available < 0
                    ? 'text-status-absent text-2xl font-semibold tabular-nums'
                    : 'text-2xl font-semibold tabular-nums'
                }
              >
                {formatDays(b.available)}
                <span className="text-muted-foreground ml-1 text-xs font-normal">available</span>
              </p>
            )}
            <dl className="text-muted-foreground grid grid-cols-2 gap-x-3 text-xs">
              {b.available !== null && (
                <>
                  <dt>Entitled</dt>
                  <dd className="text-right tabular-nums">{formatDays(b.entitled)}</dd>
                </>
              )}
              <dt>Taken</dt>
              <dd className="text-right tabular-nums">{formatDays(b.used)}</dd>
              {b.pending > 0 && (
                <>
                  <dt>Pending</dt>
                  <dd className="text-right tabular-nums">{formatDays(b.pending)}</dd>
                </>
              )}
              {b.adjusted !== 0 && (
                <>
                  <dt>Adjusted</dt>
                  <dd className="text-right tabular-nums">{formatDays(b.adjusted)}</dd>
                </>
              )}
              {b.expired > 0 && (
                <>
                  <dt>Expired</dt>
                  <dd className="text-right tabular-nums">{formatDays(b.expired)}</dd>
                </>
              )}
            </dl>
          </CardContent>
        </Card>
      ))}
    </div>
  )
}
