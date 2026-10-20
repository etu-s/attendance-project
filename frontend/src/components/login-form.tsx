import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { Field } from '@/components/form-field'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { applyServerErrors } from '@/lib/forms'
import { loginSchema, type LoginValues } from '@/lib/login-schema'

interface LoginFormProps {
  title: string
  description: string
  onSubmit: (values: LoginValues) => Promise<unknown>
}

export function LoginForm({ title, description, onSubmit }: LoginFormProps) {
  const form = useForm<LoginValues>({
    resolver: zodResolver(loginSchema),
    defaultValues: { email: '', password: '' },
  })

  const submit = form.handleSubmit(async (values) => {
    try {
      await onSubmit(values)
    } catch (error) {
      applyServerErrors(error, form.setError, ['email', 'password'])
    }
  })

  return (
    <main className="bg-muted/40 flex min-h-svh items-center justify-center p-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle className="text-xl">{title}</CardTitle>
          <CardDescription>{description}</CardDescription>
        </CardHeader>
        <CardContent>
          <form onSubmit={submit} className="grid gap-4" noValidate>
            <Field label="Email" error={form.formState.errors.email?.message}>
              <Input type="email" autoComplete="username" autoFocus {...form.register('email')} />
            </Field>
            <Field label="Password" error={form.formState.errors.password?.message}>
              <Input type="password" autoComplete="current-password" {...form.register('password')} />
            </Field>
            <Button type="submit" disabled={form.formState.isSubmitting} className="mt-2">
              {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
              Sign in
            </Button>
          </form>
        </CardContent>
      </Card>
    </main>
  )
}
