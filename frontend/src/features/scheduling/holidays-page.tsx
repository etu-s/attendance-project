import { useEffect, useState } from 'react'
import { zodResolver } from '@hookform/resolvers/zod'
import { ChevronLeft, ChevronRight, Loader2, MoreHorizontal, Pencil, Plus, Repeat, Trash2 } from 'lucide-react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { Field } from '@/components/form-field'
import { EmptyState, ErrorState, PageHeader } from '@/components/states'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useLocationOptions } from '@/features/organization/api'
import { useCan, useDisplayPreferences, useSession } from '@/features/tenant-auth/hooks'
import { formatLocalDate, todayIn } from '@/lib/format'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import { useDeleteHoliday, useHolidays, useSaveHoliday, type Holiday } from './api'
import { weekdayLabel } from './dates'

const EVERYWHERE = 'all'

// Mirrors HolidayRequest (duplicate date + scope checked by the API).
const schema = z.object({
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.'),
  name: z.string().trim().min(1, 'Enter a name.').max(120),
  location_id: z.string(),
  recurs_yearly: z.boolean(),
})

type Values = z.infer<typeof schema>

function HolidayDialog({ target, onClose, year }: { target: Holiday | 'new' | null; onClose: () => void; year: number }) {
  const save = useSaveHoliday()
  const canHaveLocations = useCan()('locations.view')
  const locations = useLocationOptions()
  const form = useForm<Values>({
    resolver: zodResolver(schema),
    defaultValues: { date: '', name: '', location_id: EVERYWHERE, recurs_yearly: false },
  })

  useEffect(() => {
    if (target === 'new') form.reset({ date: `${String(year)}-01-01`, name: '', location_id: EVERYWHERE, recurs_yearly: false })
    else if (target) {
      form.reset({
        date: target.date,
        name: target.name,
        location_id: target.location ? String(target.location.id) : EVERYWHERE,
        recurs_yearly: target.recurs_yearly,
      })
    }
  }, [target, year, form])

  const submit = form.handleSubmit(async (v) => {
    try {
      await save.mutateAsync({
        id: target && target !== 'new' ? target.id : undefined,
        payload: {
          date: v.date,
          name: v.name,
          recurs_yearly: v.recurs_yearly,
          location_id: v.location_id === EVERYWHERE ? null : Number(v.location_id),
        },
      })
      toast.success('Holiday saved.')
      onClose()
    } catch (error) {
      applyServerErrors(error, form.setError, ['date', 'name', 'location_id', 'recurs_yearly'])
    }
  })

  const errors = form.formState.errors

  return (
    <Dialog open={target !== null} onOpenChange={(o) => !o && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{target === 'new' ? 'New holiday' : 'Edit holiday'}</DialogTitle>
          <DialogDescription>
            Working days on a holiday are not expected to be worked. Rest days are unaffected.
          </DialogDescription>
        </DialogHeader>
        <form id="holiday-form" onSubmit={submit} className="grid gap-4" noValidate>
          <div className="grid gap-4 sm:grid-cols-[1fr_2fr]">
            <Field label="Date" error={errors.date?.message}>
              <Input type="date" {...form.register('date')} />
            </Field>
            <Field label="Name" error={errors.name?.message}>
              <Input {...form.register('name')} />
            </Field>
          </div>
          {canHaveLocations && (
            <Controller
              control={form.control}
              name="location_id"
              render={({ field }) => (
                <Field label="Applies to" error={errors.location_id?.message}>
                  {(controlProps) => (
                    <Select value={field.value} onValueChange={field.onChange}>
                      <SelectTrigger className="w-full" {...controlProps}>
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value={EVERYWHERE}>Everyone</SelectItem>
                        {(locations.data?.data ?? []).map((l) => (
                          <SelectItem key={l.id} value={String(l.id)}>
                            Only at {l.name}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  )}
                </Field>
              )}
            />
          )}
          <Controller
            control={form.control}
            name="recurs_yearly"
            render={({ field }) => (
              <label className="flex items-start gap-2 text-sm">
                <Checkbox className="mt-0.5" checked={field.value} onCheckedChange={(c) => field.onChange(c === true)} />
                <span>
                  Every year on this date
                  <span className="text-muted-foreground block text-xs">
                    For fixed-date holidays. Enter moving holidays (e.g. Eid) each year.
                  </span>
                </span>
              </label>
            )}
          />
        </form>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            Cancel
          </Button>
          <Button type="submit" form="holiday-form" disabled={form.formState.isSubmitting}>
            {form.formState.isSubmitting && <Loader2 className="animate-spin" />}
            Save
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function HolidaysPage() {
  const can = useCan()
  const prefs = useDisplayPreferences()
  const { workspace } = useSession()
  const manage = can('holidays.manage')
  const [year, setYear] = useState(() => Number(todayIn(workspace.timezone).slice(0, 4)))
  const holidays = useHolidays(year)
  const remove = useDeleteHoliday()
  const [editing, setEditing] = useState<Holiday | 'new' | null>(null)
  const [deleting, setDeleting] = useState<Holiday | null>(null)

  return (
    <>
      <PageHeader
        title="Holidays"
        description="Public and company holidays, for everyone or for one location."
        actions={
          manage && (
            <Button onClick={() => setEditing('new')}>
              <Plus /> New holiday
            </Button>
          )
        }
      />
      <div className="flex items-center gap-1">
        <Button variant="outline" size="icon" className="size-8" aria-label="Previous year" onClick={() => setYear((y) => y - 1)}>
          <ChevronLeft />
        </Button>
        <p className="w-16 text-center font-medium tabular-nums" aria-live="polite">
          {year}
        </p>
        <Button variant="outline" size="icon" className="size-8" aria-label="Next year" onClick={() => setYear((y) => y + 1)}>
          <ChevronRight />
        </Button>
      </div>

      {holidays.isError ? (
        <ErrorState error={holidays.error} onRetry={() => holidays.refetch()} />
      ) : holidays.isPending ? (
        <Skeleton className="h-64 w-full" />
      ) : holidays.data.length === 0 ? (
        <EmptyState
          title={`No holidays in ${String(year)}`}
          description="Add public holidays so nobody is expected to work on them."
        />
      ) : (
        <div className="overflow-x-auto rounded-lg border">
          <Table>
            <caption className="sr-only">Holidays in {year}</caption>
            <TableHeader>
              <TableRow>
                <TableHead>Date</TableHead>
                <TableHead>Holiday</TableHead>
                <TableHead className="hidden sm:table-cell">Applies to</TableHead>
                <TableHead className="w-12" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {holidays.data.map((h) => (
                <TableRow key={`${String(h.id)}-${h.occurs_on}`}>
                  <TableCell className="whitespace-nowrap tabular-nums">
                    <span className="text-muted-foreground mr-2">{weekdayLabel(h.occurs_on)}</span>
                    {formatLocalDate(h.occurs_on, prefs)}
                  </TableCell>
                  <TableCell>
                    <span className="font-medium">{h.name}</span>
                    {h.recurs_yearly && (
                      <span className="text-muted-foreground ml-2 inline-flex items-center gap-1 text-xs">
                        <Repeat className="size-3" aria-hidden /> yearly
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="hidden text-sm sm:table-cell">{h.location?.name ?? 'Everyone'}</TableCell>
                  <TableCell className="text-right">
                    {manage && (
                      <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                          <Button variant="ghost" size="icon" className="size-8" aria-label={`Actions for ${h.name}`}>
                            <MoreHorizontal />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                          <DropdownMenuItem onSelect={() => setEditing(h)}>
                            <Pencil /> Edit
                          </DropdownMenuItem>
                          <DropdownMenuItem variant="destructive" onSelect={() => setDeleting(h)}>
                            <Trash2 /> Delete
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      <HolidayDialog target={editing} onClose={() => setEditing(null)} year={year} />
      <ConfirmDialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
        title={`Delete ${deleting?.name ?? 'holiday'}?`}
        description={
          <p>{deleting?.recurs_yearly ? 'This removes it from every year.' : 'Those days become normal days again.'}</p>
        }
        confirmLabel="Delete holiday"
        destructive
        pending={remove.isPending}
        onConfirm={() => {
          if (!deleting) return
          remove.mutate(deleting.id, {
            onSuccess: () => {
              toast.success('Holiday deleted.')
              setDeleting(null)
            },
            onError: (e) => toast.error(errorMessage(e)),
          })
        }}
      />
    </>
  )
}
