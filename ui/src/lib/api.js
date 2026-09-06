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

  let response

  /*
   * FormData يُرسَل كما هو بلا تحويل، و**بلا** ترويسة Content-Type: المتصفّح
   * يضبطها بنفسه ويضيف الحدّ الفاصل (boundary) الذي لا يعرفه أحد سواه. تحديدها
   * يدوياً يُنتج طلباً بلا فاصل، فيصل الخادمَ جسمٌ فارغ بلا خطأ ظاهر.
   */
  const isForm = body instanceof FormData

  /*
   * فشل الشبكة يُلقي TypeError لا استجابة، ونصّه من المتصفّح لا منّا:
   * Safari يقول «Load failed» وChrome «Failed to fetch» — كلاهما إنجليزي
   * ولا يدلّ المستخدم على شيء. نلتقطه هنا ونحوّله إلى ApiError برمز 0
   * (لا استجابة أصلاً) ورسالة عربية تقول ما يجب فعله.
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
