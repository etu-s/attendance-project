import { cloneElement, isValidElement, useId, type ReactElement, type ReactNode } from 'react'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

/** Attributes that tie a control to its label, hint and error text. */
export interface ControlProps {
  id: string
  'aria-invalid': true | undefined
  'aria-describedby': string | undefined
}

interface FieldProps {
  label: string
  error?: string
  hint?: ReactNode
  className?: string
  /**
   * Either the control element itself (an input; it receives the props), or a
   * render function for composite controls (Select, input + addon) that must
   * place the props on the inner focusable element, e.g. the SelectTrigger.
   */
  children: ReactElement<Record<string, unknown>> | ((control: ControlProps) => ReactNode)
}

/**
 * Label + control + hint/error, wired for screen readers: the control gets an
 * id, aria-invalid and aria-describedby pointing at the hint and error text.
 */
export function Field({ label, error, hint, className, children }: FieldProps) {
  const id = useId()
  const hintId = `${id}-hint`
  const errorId = `${id}-error`
  const controlProps: ControlProps = {
    id,
    'aria-invalid': error ? true : undefined,
    'aria-describedby': [hint && !error ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined,
  }

  const control =
    typeof children === 'function'
      ? children(controlProps)
      : isValidElement(children)
        ? cloneElement(children, { ...controlProps })
        : children

  return (
    // content-start: in a row next to a taller field (hint/error), keep label and control at the top.
    <div className={cn('grid content-start gap-1.5', className)}>
      <Label htmlFor={id}>{label}</Label>
      {control}
      {hint && !error && (
        <p id={hintId} className="text-muted-foreground text-xs">
          {hint}
        </p>
      )}
      {error && (
        <p id={errorId} className="text-destructive text-xs font-medium">
          {error}
        </p>
      )}
    </div>
  )
}
