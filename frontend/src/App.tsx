import { QueryClientProvider } from '@tanstack/react-query'
import { ThemeProvider } from 'next-themes'
import { lazy, Suspense, useState } from 'react'
import { Toaster } from '@/components/ui/sonner'
import { TooltipProvider } from '@/components/ui/tooltip'
import { adminSessionKey } from '@/features/central-auth/api'
import { sessionKey } from '@/features/tenant-auth/api'
import { resolveAppContext } from '@/lib/host'
import { createQueryClient } from '@/lib/query-client'

const context = resolveAppContext()

// Each host only downloads its own app: tenants never load the super admin console.
const CentralApp = lazy(() => import('@/apps/central-app').then((m) => ({ default: m.CentralApp })))
const TenantApp = lazy(() => import('@/apps/tenant-app').then((m) => ({ default: m.TenantApp })))

export default function App() {
  const [queryClient] = useState(() => createQueryClient(context.kind === 'central' ? adminSessionKey : sessionKey))

  return (
    <ThemeProvider attribute="class" defaultTheme="system" enableSystem disableTransitionOnChange>
      <QueryClientProvider client={queryClient}>
        <TooltipProvider>
          <Suspense fallback={null}>
            {context.kind === 'central' ? <CentralApp /> : <TenantApp subdomain={context.subdomain} />}
          </Suspense>
          <Toaster richColors closeButton position="top-right" />
        </TooltipProvider>
      </QueryClientProvider>
    </ThemeProvider>
  )
}
