/**
 * The same SPA serves two apps, chosen by hostname (mirrors the backend's
 * CENTRAL_DOMAINS): the super admin console on a central host, and a tenant
 * workspace on <subdomain>.<central host>.
 */

const centralHosts = (import.meta.env.VITE_CENTRAL_DOMAINS ?? 'localhost,127.0.0.1')
  .split(',')
  .map((host: string) => host.trim().toLowerCase())
  .filter(Boolean)

export type AppContext = { kind: 'central' } | { kind: 'tenant'; subdomain: string }

export function resolveAppContext(hostname: string = window.location.hostname): AppContext {
  const host = hostname.toLowerCase()

  if (centralHosts.includes(host)) {
    return { kind: 'central' }
  }

  const base = centralHosts.find((central: string) => host.endsWith(`.${central}`))
  const subdomain = base ? host.slice(0, -(base.length + 1)).split('.')[0] : undefined

  return subdomain ? { kind: 'tenant', subdomain } : { kind: 'central' }
}

/** URL of a tenant workspace, e.g. http://acme.localhost:5173 */
export function tenantWorkspaceUrl(subdomain: string): string {
  const { protocol, port } = window.location
  const base = centralHosts[0] ?? 'localhost'

  return `${protocol}//${subdomain}.${base}${port ? `:${port}` : ''}`
}

/** The host label shown next to the subdomain input, e.g. ".localhost" */
export function tenantHostSuffix(): string {
  return `.${centralHosts[0] ?? 'localhost'}`
}
