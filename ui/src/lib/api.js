/**
 * The only layer that talks to the admin panel's server.
 *
 * There is no key for the knowledge platform anywhere in here — the browser
 * does not even know the platform exists. Every request goes to the admin
 * panel, which checks the permission and then forwards the request onward.
 */

const BASE = import.meta.env.VITE_API_URL ?? 'http://localhost:8001/api/v1'
const TOKEN_KEY = 'admin_token'

/** Storage is wrapped in try/catch: some browsers refuse localStorage in private browsing. */
export const tokenStore = {
  get() {
    try { return localStorage.getItem(TOKEN_KEY) } catch { return null }
  },
  set(value) {
    try { localStorage.setItem(TOKEN_KEY, value) } catch { /* ignore */ }
  },
  clear() {
    try { localStorage.removeItem(TOKEN_KEY) } catch { /* ignore */ }
  },
}

/** An error that carries the status code, so the interface can tell 403 from 422 from 502. */
export class ApiError extends Error {
  constructor(message, status, data) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.data = data
  }
}

export async function api(path, { method = 'GET', body, params } = {}) {
  const url = new URL(BASE + path)

  if (params) {
    Object.entries(params).forEach(([key, value]) => {
      if (value !== '' && value !== null && value !== undefined) {
        url.searchParams.set(key, value)
      }
    })
  }

  const token = tokenStore.get()

  let response

  /*
   * FormData is sent as-is with no conversion, and **without** a Content-Type
   * header: the browser sets it itself and appends the boundary that nobody
   * else knows. Setting it by hand produces a request with no boundary, so the
   * server receives an empty body with no visible error.
   */
  const isForm = body instanceof FormData

  /*
   * A network failure throws a TypeError rather than returning a response, and
   * its text comes from the browser, not from us: Safari says "Load failed"
   * and Chrome "Failed to fetch" — both English, and neither tells the user
   * anything. We catch it here and turn it into an ApiError with status 0 (no
   * response at all) and an Arabic message that says what to do about it.
   */
  try {
    response = await fetch(url, {
      method,
      headers: {
        Accept: 'application/json',
        ...(body && !isForm ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: isForm ? body : body ? JSON.stringify(body) : undefined,
    })
  } catch {
    throw new ApiError(
      'تعذّر الاتصال بخادم لوحة الإدارة. تأكّد من أنه يعمل، ثم أعد المحاولة.',
      0,
      null
    )
  }

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError(data.message ?? 'حدث خطأ غير متوقّع.', response.status, data)
  }

  return data
}
