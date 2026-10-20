/**
 * Minimal JSON client for the Laravel API.
 *
 * Requests are same-origin (Vite proxies /api and /sanctum), so the Sanctum
 * session cookie is first-party and the Host header tells Laravel which
 * tenant this is. Mutating requests carry the XSRF token Laravel issues via
 * GET /sanctum/csrf-cookie.
 */

export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly errors: FieldErrors
  /** Machine-readable business rule from the API (e.g. `last_tenant_admin`). */
  readonly rule: string | null

  constructor(status: number, message: string, errors: FieldErrors = {}, rule: string | null = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
    this.rule = rule
  }

  get isValidation(): boolean {
    return this.status === 422 && Object.keys(this.errors).length > 0
  }
}

type Method = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

export type QueryParams = Record<string, string | number | boolean | null | undefined>

interface RequestOptions {
  body?: unknown
  params?: QueryParams
  signal?: AbortSignal
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`))

  return match ? decodeURIComponent(match.slice(name.length + 1)) : null
}

let csrfRequest: Promise<void> | null = null

async function ensureCsrfCookie(force = false): Promise<void> {
  if (!force && readCookie('XSRF-TOKEN')) {
    return
  }

  // Share one in-flight request between concurrent mutations.
  csrfRequest ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
    .then(() => undefined)
    .finally(() => {
      csrfRequest = null
    })

  return csrfRequest
}

export function buildQuery(params?: QueryParams): string {
  if (!params) {
    return ''
  }

  const search = new URLSearchParams()

  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') {
      continue
    }
    search.set(key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value))
  }

  const query = search.toString()

  return query ? `?${query}` : ''
}

async function toApiError(response: Response): Promise<ApiError> {
  let body: { message?: string; errors?: FieldErrors; rule?: string } = {}

  try {
    body = (await response.json()) as typeof body
  } catch {
    // Non-JSON error page (e.g. proxy failure); fall back to a generic message.
  }

  const fallback = response.status >= 500 ? 'The server could not complete the request.' : 'The request failed.'

  return new ApiError(response.status, body.message ?? fallback, body.errors ?? {}, body.rule ?? null)
}

async function request<T>(method: Method, url: string, options: RequestOptions = {}, retried = false): Promise<T> {
  const mutating = method !== 'GET'

  if (mutating) {
    await ensureCsrfCookie()
  }

  const headers: Record<string, string> = { Accept: 'application/json' }
  const xsrf = readCookie('XSRF-TOKEN')

  if (xsrf) {
    headers['X-XSRF-TOKEN'] = xsrf
  }
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  const response = await fetch(url + buildQuery(options.params), {
    method,
    headers,
    credentials: 'same-origin',
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    signal: options.signal,
  })

  // 419 = CSRF token expired (e.g. after logout or session timeout): refresh once.
  if (response.status === 419 && !retried) {
    await ensureCsrfCookie(true)

    return request<T>(method, url, options, true)
  }

  if (!response.ok) {
    throw await toApiError(response)
  }

  if (response.status === 204) {
    return undefined as T
  }

  return (await response.json()) as T
}

export interface ApiClient {
  get<T>(path: string, params?: QueryParams, signal?: AbortSignal): Promise<T>
  post<T>(path: string, body?: unknown): Promise<T>
  put<T>(path: string, body?: unknown): Promise<T>
  delete<T>(path: string, body?: unknown): Promise<T>
}

export function createApiClient(baseUrl: string): ApiClient {
  return {
    get: (path, params, signal) => request('GET', baseUrl + path, { params, signal }),
    post: (path, body) => request('POST', baseUrl + path, { body }),
    put: (path, body) => request('PUT', baseUrl + path, { body }),
    delete: (path, body) => request('DELETE', baseUrl + path, { body }),
  }
}

/** Tenant workspace API (the tenant is identified by the current subdomain). */
export const tenantApi = createApiClient('/api/v1')

/** Super admin API (only answers on central hosts). */
export const centralApi = createApiClient('/api/v1/central')
