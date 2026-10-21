import { useMemo, useState } from 'react'
import { Loader2 } from 'lucide-react'
import { toast } from 'sonner'
import { Field, type ControlProps } from '@/components/form-field'
import { ErrorState, PageHeader } from '@/components/states'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Switch } from '@/components/ui/switch'
import { useCan } from '@/features/tenant-auth/hooks'
import { ApiError } from '@/lib/api/client'
import { exampleDate, exampleTime, weekdayNames } from '@/lib/format'
import { errorMessage } from '@/lib/forms'
import { useSettings, useUpdateSettings, type Setting, type SettingValue } from './api'

const groupTitles: Record<string, { title: string; description: string }> = {
  attendance: {
    title: 'Attendance defaults',
    description: 'Grace minutes and punch windows for timetables that do not set their own.',
  },
  general: {
    title: 'General',
    description: 'Regional settings used to show dates and to decide which day a punch belongs to.',
  },
}

function optionLabel(setting: Setting, option: string | number): string {
  switch (setting.key) {
    case 'general.week_start':
      return weekdayNames[Number(option)] ?? String(option)
    case 'general.date_format':
      return `${exampleDate(String(option))}  (${option})`
    case 'general.time_format':
      return exampleTime(String(option))
    default:
      return String(option)
  }
}

const timezones: string[] = typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : []

function SettingControl({
  setting,
  value,
  disabled,
  onChange,
  controlProps,
}: {
  setting: Setting
  value: SettingValue
  disabled: boolean
  onChange: (value: SettingValue) => void
  controlProps: ControlProps
}) {
  if (setting.options) {
    return (
      <Select
        value={String(value)}
        disabled={disabled}
        onValueChange={(next) => onChange(setting.type === 'integer' ? Number(next) : next)}
      >
        <SelectTrigger className="w-full" {...controlProps}>
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          {setting.options.map((option) => (
            <SelectItem key={option} value={String(option)}>
              {optionLabel(setting, option)}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    )
  }

  if (setting.type === 'boolean') {
    return <Switch {...controlProps} checked={Boolean(value)} disabled={disabled} onCheckedChange={onChange} />
  }

  if (setting.key === 'general.timezone') {
    // A searchable native list: ~400 zones is too long for a dropdown.
    return (
      <>
        <Input
          {...controlProps}
          list="timezones"
          value={String(value)}
          disabled={disabled}
          onChange={(event) => onChange(event.target.value)}
        />
        <datalist id="timezones">
          {timezones.map((zone) => (
            <option key={zone} value={zone} />
          ))}
        </datalist>
      </>
    )
  }

  return (
    <Input
      {...controlProps}
      type={setting.type === 'integer' ? 'number' : 'text'}
      value={String(value)}
      disabled={disabled}
      onChange={(event) => onChange(setting.type === 'integer' ? Number(event.target.value) : event.target.value)}
    />
  )
}

export function SettingsPage() {
  const can = useCan()
  const settings = useSettings()
  const update = useUpdateSettings()
  const editable = can('settings.update')

  // Only the user's unsaved edits; everything else shows the server value.
  const [edits, setEdits] = useState<Record<string, SettingValue>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})

  const current = (setting: Setting): SettingValue => setting.value ?? setting.default
  const valueOf = (setting: Setting): SettingValue => edits[setting.key] ?? current(setting)

  const groups = useMemo(() => {
    const byGroup = new Map<string, Setting[]>()
    for (const setting of settings.data ?? []) {
      byGroup.set(setting.group, [...(byGroup.get(setting.group) ?? []), setting])
    }

    return [...byGroup.entries()]
  }, [settings.data])

  const changes = (settings.data ?? [])
    .filter((s) => s.key in edits && valueOf(s) !== current(s))
    .map((s) => ({ key: s.key, value: valueOf(s) }))

  const save = () => {
    setErrors({})
    update.mutate(changes, {
      onSuccess: () => {
        setEdits({})
        toast.success('Settings saved.')
      },
      onError: (error) => {
        if (error instanceof ApiError && error.isValidation) {
          // Errors come back as settings.<index>.value; map them to setting keys.
          const mapped: Record<string, string> = {}
          for (const [path, messages] of Object.entries(error.errors)) {
            const index = Number(path.split('.')[1])
            const key = changes[index]?.key
            if (key) mapped[key] = messages[0] ?? error.message
          }
          setErrors(mapped)
        } else {
          toast.error(errorMessage(error))
        }
      },
    })
  }

  return (
    <>
      <PageHeader title="Settings" description="Workspace-wide preferences." />

      {!editable && (
        <Alert>
          <AlertDescription>You can view these settings. Only a Tenant Admin can change them.</AlertDescription>
        </Alert>
      )}

      {settings.isError ? (
        <ErrorState error={settings.error} onRetry={() => settings.refetch()} />
      ) : settings.isPending ? (
        <Card>
          <CardHeader>
            <Skeleton className="h-5 w-32" />
            <Skeleton className="h-4 w-72" />
          </CardHeader>
          <CardContent className="grid gap-6 sm:grid-cols-2">
            {Array.from({ length: 4 }, (_, i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-4 w-24" />
                <Skeleton className="h-9 w-full" />
              </div>
            ))}
          </CardContent>
        </Card>
      ) : (
        groups.map(([group, items]) => (
          <Card key={group}>
            <CardHeader>
              <CardTitle>{groupTitles[group]?.title ?? group}</CardTitle>
              {groupTitles[group] && <CardDescription>{groupTitles[group].description}</CardDescription>}
            </CardHeader>
            <CardContent className="grid gap-6 sm:grid-cols-2">
              {items.map((setting) => (
                <Field
                  key={setting.key}
                  label={setting.label}
                  hint={setting.description ?? undefined}
                  error={errors[setting.key]}
                >
                  {(controlProps) => (
                    <SettingControl
                      setting={setting}
                      value={valueOf(setting)}
                      disabled={!editable || update.isPending}
                      onChange={(value) => setEdits((previous) => ({ ...previous, [setting.key]: value }))}
                      controlProps={controlProps}
                    />
                  )}
                </Field>
              ))}
            </CardContent>
            {editable && (
              <CardFooter className="justify-end gap-2 border-t pt-6">
                <Button
                  variant="outline"
                  disabled={changes.length === 0 || update.isPending}
                  onClick={() => {
                    setErrors({})
                    setEdits({})
                  }}
                >
                  Discard
                </Button>
                <Button disabled={changes.length === 0 || update.isPending} onClick={save}>
                  {update.isPending && <Loader2 className="animate-spin" />}
                  Save changes
                </Button>
              </CardFooter>
            )}
          </Card>
        ))
      )}
    </>
  )
}
