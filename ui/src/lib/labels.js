/*
 * The Arabic labels shared across the pages.
 *
 * The action map used to be duplicated verbatim in both the dashboard and the
 * audit log pages, and the status tones duplicated between articles and the
 * dashboard. Duplication of this kind does not produce a loud failure but a
 * silent one: a new action is added on the server, gets translated on one
 * page, and shows up as its technical identifier on the other.
 *
 * The server remains the real source of truth: this is presentation
 * translation only, and no decision is ever made from it.
 */

/** Action names as they are recorded in the audit log. */
export const ACTIONS = {
  'articles.create': 'إنشاء مقال',
  'articles.update': 'تعديل مقال',
  'articles.delete': 'حذف مقال',
  'articles.publish': 'نشر مقال',
  'articles.draft': 'سحب النشر',
  'users.create': 'إنشاء مستخدم',
  'users.update': 'تعديل مستخدم',
  'users.enable': 'تفعيل حساب',
  'users.disable': 'تعطيل حساب',
  'users.role': 'تغيير دور',
  'users.permissions': 'تغيير صلاحيات',
}

/** Translate an action, keeping the raw identifier visible if it is new and not translated yet. */
export const actionLabel = (action) => ACTIONS[action] ?? action

const STATUS_TONES = {
  published: 'bg-brand-50 text-brand-700',
  draft: 'bg-amber-50 text-amber-800',
  scheduled: 'bg-ink-100 text-ink-600',
}

/** The tone of the status badge. An unknown status takes grey rather than breaking the layout. */
export const statusTone = (status) => STATUS_TONES[status] ?? 'bg-ink-100 text-ink-600'
