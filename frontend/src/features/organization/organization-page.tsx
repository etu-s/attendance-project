import { useEffect } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { Loader2 } from 'lucide-react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { Field } from '@/components/form-field'
import { ErrorState, PageHeader } from '@/components/states'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardFooter } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Textarea } from '@/components/ui/textarea'
import { useCan } from '@/features/tenant-auth/hooks'
import { applyServerErrors } from '@/lib/forms'
import { useOrganizationProfile, useUpdateOrganizationProfile } from './api'

// Mirrors UpdateOrganizationRequest.php
const schema = z.object({
  legal_name: z.string().trim().min(1, 'Enter the legal name.').max(160),
  display_name: z.string().max(120),
  registration_number: z.string().max(60),
  address: z.string().max(500),
  phone: z.string().max(30),
  email: z.union([z.literal(''), z.email('Enter a valid email address.').max(255)]),
})

type Values = z.infer<typeof schema>
const fields = ['legal_name', 'display_name', 'registration_number', 'address', 'phone', 'email'] as const

export function OrganizationPage() {
  const can = useCan()
  const editable = can('organization.update')
  const profile = useOrganizationProfile()
  const update = useUpdateOrganizationProfile()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { legal_name: '', display_name: '', registration_number: '', address: '', phone: '', email: '' },
  })

  useEffect(() => {
    if (profile.data) {
      form.reset({
        legal_name: profile.data.legal_name,
        display_name: profile.data.display_name ?? '',
        registration_number: profile.data.registration_number ?? '',
        address: profile.data.address ?? '',
        phone: profile.data.phone ?? '',
        email: profile.data.email ?? '',
      })
    }
  }, [profile.data, form])

  const submit = form.handleSubmit(async (values) => {
    try {
      // Empty optional fields are cleared (null), not stored as "".
      const payload = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, k === 'legal_name' ? v : v || null]))
      await update.mutateAsync(payload)
      toast.success('Organization profile saved.')
    } catch (error) {
      applyServerErrors(error, form.setError, fields)
    }
  })

  const errors = form.formState.errors

  return (
    <>
      <PageHeader title="Organization" description="Your organization's business details." />
      {!editable && (
        <Alert>
          <AlertDescription>Only a Tenant Admin can change these details.</AlertDescription>
        </Alert>
      )}
      {profile.isError ? (
        <ErrorState error={profile.error} onRetry={() => profile.refetch()} />
      ) : profile.isPending ? (
        <Card>
          <CardContent className="grid gap-6 pt-6 sm:grid-cols-2">
            {Array.from({ length: 6 }, (_, i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-4 w-24" />
                <Skeleton className="h-9 w-full" />
              </div>
            ))}
          </CardContent>
        </Card>
      ) : (
        <Card>
          <form onSubmit={submit} noValidate>
            <fieldset disabled={!editable || form.formState.isSubmitting} className="contents">
              <CardContent className="grid gap-6 pt-6 sm:grid-cols-2">
                <Field label="Legal name" error={errors.legal_name?.message}>
                  <Input {...form.register('legal_name')} />
                </Field>
                <Field
                  label="Display name"
                  error={errors.display_name?.message}
                  hint="Shown in the app if different from the legal name."
                >
                  <Input {...form.register('display_name')} />
                </Field>
                <Field label="Registration number" error={errors.registration_number?.message}>
                  <Input {...form.register('registration_number')} />
                </Field>
                <Field label="Phone" error={errors.phone?.message}>
                  <Input type="tel" {...form.register('phone')} />
                </Field>
                <Field label="Email" error={errors.email?.message}>
                  <Input type="email" {...form.register('email')} />
                </Field>
                <Field label="Address" error={errors.address?.message} className="sm:col-span-2">
                  <Textarea rows={3} {...form.register('address')} />
                </Field>
              </CardContent>
              {editable && (
                <CardFooter className="justify-end border-t pt-6">
                  <Button type="submit" disabled={!form.formState.isDirty}>
                    {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
                    Save changes
                  </Button>
                </CardFooter>
              )}
            </fieldset>
          </form>
        </Card>
      )}
    </>
  )
}
