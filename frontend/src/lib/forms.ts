import type { FieldValues, Path, UseFormSetError } from 'react-hook-form'
import { toast } from 'sonner'
import { ApiError } from '@/lib/api/client'

/**
 * Maps a Laravel 422 response onto react-hook-form fields so server-side
 * rules (unique email, reserved subdomain, ...) appear next to the input.
 * Anything that is not a field error is shown as a toast.
 */
export function applyServerErrors<T extends FieldValues>(
  error: unknown,
  setError: UseFormSetError<T>,
  fields: readonly Path<T>[],
): void {
  if (error instanceof ApiError && error.isValidation) {
    let unmatched: string | null = null

    for (const [key, messages] of Object.entries(error.errors)) {
      const message = messages[0] ?? error.message
      if ((fields as readonly string[]).includes(key)) {
        setError(key as Path<T>, { type: 'server', message })
      } else {
        unmatched ??= message
      }
    }

    if (unmatched) {
      toast.error(unmatched)
    }

    return
  }

  toast.error(error instanceof Error ? error.message : 'Something went wrong.')
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Something went wrong.'
}
