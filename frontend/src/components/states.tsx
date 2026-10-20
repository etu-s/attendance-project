import type { ReactNode } from 'react'
import { AlertTriangle, Inbox, RotateCw } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { errorMessage } from '@/lib/forms'
import { cn } from '@/lib/utils'

export function EmptyState({
  title,
  description,
  action,
  className,
}: {
  title: string
  description?: string
  action?: ReactNode
  className?: string
}) {
  return (
    <div className={cn('flex flex-col items-center justify-center gap-2 rounded-lg border px-6 py-14 text-center', className)}>
      <Inbox className="text-muted-foreground size-8" aria-hidden />
      <p className="font-medium">{title}</p>
      {description && <p className="text-muted-foreground max-w-sm text-sm">{description}</p>}
      {action && <div className="mt-2">{action}</div>}
    </div>
  )
}

export function ErrorState({ error, onRetry, className }: { error: unknown; onRetry?: () => void; className?: string }) {
  return (
    <div
      role="alert"
      className={cn(
        'border-destructive/40 flex flex-col items-center justify-center gap-2 rounded-lg border px-6 py-14 text-center',
        className,
      )}
    >
      <AlertTriangle className="text-destructive size-8" aria-hidden />
      <p className="font-medium">Could not load this page</p>
      <p className="text-muted-foreground max-w-sm text-sm">{errorMessage(error)}</p>
      {onRetry && (
        <Button variant="outline" size="sm" className="mt-2" onClick={onRetry}>
          <RotateCw /> Try again
        </Button>
      )}
    </div>
  )
}

/** Whole-screen message for states outside the app shell (workspace missing, suspended, ...). */
export function FullPageMessage({ title, description, action }: { title: string; description: string; action?: ReactNode }) {
  return (
    <main className="flex min-h-svh items-center justify-center p-6">
      <div className="max-w-md space-y-3 text-center">
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        <p className="text-muted-foreground">{description}</p>
        {action && <div className="pt-2">{action}</div>}
      </div>
    </main>
  )
}

export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
      <div className="space-y-1">
        <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>
        {description && <p className="text-muted-foreground text-sm">{description}</p>}
      </div>
      {actions && <div className="flex gap-2">{actions}</div>}
    </div>
  )
}
