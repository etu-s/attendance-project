import { useState, type ReactNode } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { CheckCircle2, Loader2, MailCheck } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { ApiError, type ApiClient } from '@/lib/api/client'
import { applyServerErrors } from '@/lib/forms'

function Shell({ title, description, children }: { title: string; description: string; children: ReactNode }) {
  return (
    <main className="bg-muted/40 flex min-h-svh items-center justify-center p-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle className="text-xl">{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </CardHeader>
        <CardContent>{children}</CardContent>
      </Card>
    </main>
  )
}

const forgotSchema = z.object({ email: z.email('Enter a valid email address.').max(255) })

/** "Forgot password": always the same answer, so it never reveals which emails have accounts. */
export function ForgotPasswordPage({ api, description }: { api: ApiClient; description: string }) {
  const [sent, setSent] = useState(false)
  const form = useForm<z.infer<typeof forgotSchema>>({ resolver: zodResolver(forgotSchema), defaultValues: { email: '' } })

  const submit = form.handleSubmit(async (values) => {
    try {
      await api.post('/auth/forgot-password', values)
      setSent(true)
    } catch (error) {
      applyServerErrors(error, form.setError, ['email'])
    }
  })

  if (sent) {
    return (
      <Shell
        title="Check your email"
        description="If an account exists for this email, a link to reset the password is on its way."
      >
        <div className="grid gap-4 text-sm">
          <MailCheck className="text-status-present size-8" aria-hidden />
          <p className="text-muted-foreground">The link works for 60 minutes. Check your spam folder if it does not arrive.</p>
          <Link to="/login" className="underline underline-offset-4">
            Back to sign in
          </Link>
        </div>
      </Shell>
    )
  }

  return (
    <Shell title="Forgot your password?" description={description}>
      <form onSubmit={(e) => void submit(e)} className="grid gap-4" noValidate>
        <Field label="Email" error={form.formState.errors.email?.message}>
          <Input type="email" autoComplete="username" autoFocus {...form.register('email')} />
        </Field>
        <Button type="submit" disabled={form.formState.isSubmitting}>
          {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
          Send reset link
        </Button>
        <Link to="/login" className="text-center text-sm underline underline-offset-4">
          Back to sign in
        </Link>
      </form>
    </Shell>
  )
}

// Mirrors SetPasswordRequest (Password::min(8)->letters()->numbers(), confirmed).
const setSchema = z
  .object({
    password: z
      .string()
      .min(8, 'At least 8 characters.')
      .max(255)
      .regex(/[A-Za-z]/, 'Include at least one letter.')
      .regex(/\d/, 'Include at least one number.'),
    password_confirmation: z.string(),
  })
  .refine((v) => v.password === v.password_confirmation, {
    path: ['password_confirmation'],
    message: 'The passwords do not match.',
  })

/**
 * Choosing a password from an emailed link: a reset, or an invitation
 * (`endpoint` /auth/accept-invitation). Token and email come from the link.
 */
export function SetPasswordPage({
  api,
  endpoint,
  title,
  description,
  done,
}: {
  api: ApiClient
  endpoint: string
  title: string
  description: string
  done: string
}) {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const form = useForm<z.infer<typeof setSchema>>({
    resolver: zodResolver(setSchema),
    defaultValues: { password: '', password_confirmation: '' },
  })
  const [linkError, setLinkError] = useState<string | null>(
    token && email ? null : 'This link is incomplete. Open it again from the email.',
  )

  const submit = form.handleSubmit(async (values) => {
    try {
      await api.post(endpoint, { ...values, token, email })
      toast.success(done)
      void navigate('/login', { replace: true })
    } catch (error) {
      // An expired or used link is not a field problem: offer a new link instead of the form.
      const tokenError = error instanceof ApiError ? error.errors.token?.[0] : undefined
      if (tokenError) {
        setLinkError(tokenError)
      } else {
        applyServerErrors(error, form.setError, ['password', 'password_confirmation'])
      }
    }
  })

  return (
    <Shell title={title} description={email ? `${description} (${email})` : description}>
      {linkError ? (
        <div className="grid gap-4 text-sm" role="alert">
          <p className="text-destructive">{linkError}</p>
          <Link to="/forgot-password" className="underline underline-offset-4">
            Ask for a new link
          </Link>
        </div>
      ) : (
        <form onSubmit={(e) => void submit(e)} className="grid gap-4" noValidate>
          <Field
            label="New password"
            hint="At least 8 characters, with a letter and a number."
            error={form.formState.errors.password?.message}
          >
            <Input type="password" autoComplete="new-password" autoFocus {...form.register('password')} />
          </Field>
          <Field label="Repeat the password" error={form.formState.errors.password_confirmation?.message}>
            <Input type="password" autoComplete="new-password" {...form.register('password_confirmation')} />
          </Field>
          <Button type="submit" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting ? <Loader2 className="animate-spin" /> : <CheckCircle2 />}
            Save password
          </Button>
        </form>
      )}
    </Shell>
  )
}
