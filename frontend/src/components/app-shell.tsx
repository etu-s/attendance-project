import { useState, type ComponentType, type ReactNode } from 'react'
import { LogOut, Menu } from 'lucide-react'
import { NavLink, Outlet } from 'react-router'
import { ThemeToggle } from '@/components/theme-toggle'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { cn } from '@/lib/utils'

export interface NavItem {
  to: string
  label: string
  icon: ComponentType<{ className?: string }>
  end?: boolean
  /** Items with the same section are grouped under a small heading (in order of appearance). */
  section?: string
}

interface AppShellProps {
  brand: string
  subtitle: string
  nav: NavItem[]
  user: { name: string; email: string }
  onLogout: () => void
  loggingOut?: boolean
  children?: ReactNode
}

function initials(name: string): string {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? '')
    .join('')
}

function NavEntry({ item, onNavigate }: { item: NavItem; onNavigate?: () => void }) {
  return (
    <NavLink
      to={item.to}
      end={item.end}
      onClick={onNavigate}
      className={({ isActive }) =>
        cn(
          'text-sidebar-foreground/80 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:ring-ring flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
          isActive && 'bg-sidebar-accent text-sidebar-accent-foreground',
        )
      }
    >
      <item.icon className="size-4" />
      {item.label}
    </NavLink>
  )
}

function Navigation({ nav, onNavigate }: { nav: NavItem[]; onNavigate?: () => void }) {
  const groups: { section?: string; items: NavItem[] }[] = []
  for (const item of nav) {
    const last = groups[groups.length - 1]
    if (last && last.section === item.section) last.items.push(item)
    else groups.push({ section: item.section, items: [item] })
  }

  return (
    <nav aria-label="Main" className="grid gap-4">
      {groups.map((group, index) => (
        <div key={group.section ?? `group-${String(index)}`} className="grid gap-1">
          {group.section && (
            <p className="text-muted-foreground px-3 pb-1 text-xs font-medium tracking-wide uppercase">{group.section}</p>
          )}
          {group.items.map((item) => (
            <NavEntry key={item.to} item={item} onNavigate={onNavigate} />
          ))}
        </div>
      ))}
    </nav>
  )
}

function Brand({ brand, subtitle }: { brand: string; subtitle: string }) {
  return (
    <div className="px-3">
      <p className="truncate font-semibold tracking-tight">{brand}</p>
      <p className="text-muted-foreground truncate text-xs">{subtitle}</p>
    </div>
  )
}

export function AppShell({ brand, subtitle, nav, user, onLogout, loggingOut, children }: AppShellProps) {
  const [mobileOpen, setMobileOpen] = useState(false)

  return (
    <div className="flex min-h-svh">
      <a
        href="#main"
        className="bg-background sr-only z-50 rounded px-3 py-2 focus:not-sr-only focus:fixed focus:top-2 focus:left-2"
      >
        Skip to content
      </a>

      <aside className="bg-sidebar sticky top-0 hidden h-svh w-60 shrink-0 flex-col gap-6 border-r py-6 md:flex">
        <Brand brand={brand} subtitle={subtitle} />
        <div className="min-h-0 flex-1 overflow-y-auto px-3">
          <Navigation nav={nav} />
        </div>
      </aside>

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="bg-background/95 sticky top-0 z-30 flex h-14 items-center gap-2 border-b px-4 backdrop-blur md:px-8">
          <Sheet open={mobileOpen} onOpenChange={setMobileOpen}>
            <SheetTrigger asChild>
              <Button variant="ghost" size="icon" className="md:hidden" aria-label="Open navigation">
                <Menu />
              </Button>
            </SheetTrigger>
            <SheetContent side="left" className="bg-sidebar w-64 gap-6 overflow-y-auto p-4">
              <SheetHeader className="p-0">
                <SheetTitle className="sr-only">Navigation</SheetTitle>
                <Brand brand={brand} subtitle={subtitle} />
              </SheetHeader>
              <Navigation nav={nav} onNavigate={() => setMobileOpen(false)} />
            </SheetContent>
          </Sheet>

          <p className="truncate font-semibold md:hidden">{brand}</p>

          <div className="ml-auto flex items-center gap-1">
            <ThemeToggle />
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" className="h-9 gap-2 px-2" aria-label="Account menu">
                  <Avatar className="size-7">
                    <AvatarFallback className="text-xs">{initials(user.name)}</AvatarFallback>
                  </Avatar>
                  <span className="hidden max-w-40 truncate text-sm sm:inline">{user.name}</span>
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-56">
                <DropdownMenuLabel className="font-normal">
                  <p className="truncate text-sm font-medium">{user.name}</p>
                  <p className="text-muted-foreground truncate text-xs">{user.email}</p>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem onSelect={onLogout} disabled={loggingOut}>
                  <LogOut /> Sign out
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </header>

        <main id="main" tabIndex={-1} className="mx-auto w-full max-w-6xl flex-1 space-y-6 px-4 py-8 outline-none md:px-8">
          {children ?? <Outlet />}
        </main>
      </div>
    </div>
  )
}
