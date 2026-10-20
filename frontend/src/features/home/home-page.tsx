import { ArrowRight, ScrollText, Settings, Users } from 'lucide-react'
import { Link } from 'react-router'
import { PageHeader } from '@/components/states'
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { useCan, useSession } from '@/features/tenant-auth/hooks'
import { roleLabels } from '@/features/users/types'
import { weekdayNames } from '@/lib/format'

export function HomePage() {
  const { user, workspace } = useSession()
  const can = useCan()

  const shortcuts = [
    {
      to: '/users',
      title: 'Users',
      description: 'Invite people and manage their access.',
      icon: Users,
      visible: can('users.view'),
    },
    {
      to: '/settings',
      title: 'Settings',
      description: 'Timezone, date formats and week start.',
      icon: Settings,
      visible: can('settings.view'),
    },
    {
      to: '/audit-log',
      title: 'Audit log',
      description: 'Review changes made in this workspace.',
      icon: ScrollText,
      visible: can('audit-logs.view'),
    },
  ].filter((shortcut) => shortcut.visible)

  return (
    <>
      <PageHeader
        title={`Welcome, ${user.name.split(' ')[0] ?? user.name}`}
        description={`${workspace.name} · ${user.role ? roleLabels[user.role] : 'No role'}`}
      />

      <dl className="grid gap-4 rounded-lg border p-4 text-sm sm:grid-cols-3">
        <div>
          <dt className="text-muted-foreground">Timezone</dt>
          <dd className="font-medium">{workspace.timezone}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground">Week starts on</dt>
          <dd className="font-medium">{weekdayNames[workspace.week_start] ?? '—'}</dd>
        </div>
        <div>
          <dt className="text-muted-foreground">Signed in as</dt>
          <dd className="truncate font-medium">{user.email}</dd>
        </div>
      </dl>

      {shortcuts.length > 0 ? (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {shortcuts.map((shortcut) => (
            <Link
              key={shortcut.to}
              to={shortcut.to}
              className="group focus-visible:ring-ring rounded-xl focus-visible:ring-2 focus-visible:outline-none"
            >
              <Card className="group-hover:bg-accent/50 h-full transition-colors">
                <CardHeader>
                  <shortcut.icon className="text-muted-foreground mb-2 size-5" />
                  <CardTitle className="flex items-center justify-between text-base">
                    {shortcut.title}
                    <ArrowRight className="size-4 opacity-0 transition-opacity group-hover:opacity-100" />
                  </CardTitle>
                  <CardDescription>{shortcut.description}</CardDescription>
                </CardHeader>
              </Card>
            </Link>
          ))}
        </div>
      ) : (
        <p className="text-muted-foreground text-sm">
          Attendance features for your role arrive in upcoming modules. You are signed in and your account is active.
        </p>
      )}
    </>
  )
}
