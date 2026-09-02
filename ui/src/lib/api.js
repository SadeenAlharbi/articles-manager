/**
 * طبقة الاتصال الوحيدة مع خادم لوحة الإدارة.
 *
 * لا يوجد هنا أي مفتاح لمنصّة المعرفة — المتصفّح لا يعرف بوجودها أصلاً.
 * كل طلب يذهب إلى لوحة الإدارة، وهي التي تفحص الصلاحية ثم تنقل الطلب.
 */

const BASE = import.meta.env.VITE_API_URL ?? 'http://localhost:8001/api/v1'
const TOKEN_KEY = 'admin_token'

/** التخزين مغلّف بـtry/catch: بعض المتصفّحات ترفض localStorage في التصفّح الخاص. */
export const tokenStore = {
  get() {
    try { return localStorage.getItem(TOKEN_KEY) } catch { return null }
  },
  set(value) {
    try { localStorage.setItem(TOKEN_KEY, value) } catch { /* تجاهل */ }
  },
  clear() {
    try { localStorage.removeItem(TOKEN_KEY) } catch { /* تجاهل */ }
  },
}

/** خطأ يحمل رمز الحالة، حتى تميّز الواجهة بين 403 و422 و502. */
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

  const response = await fetch(url, {
    method,
    headers: {
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  })

  const data = await response.json().catch(() => ({}))

  if (!response.ok) {
    throw new ApiError(data.message ?? 'حدث خطأ غير متوقّع.', response.status, data)
  }

  return data
}
