/*
 * المسمّيات العربية المشتركة بين الصفحات.
 *
 * كانت خريطة العمليات مكرّرة حرفياً في صفحتَي لوحة المعلومات وسجلّ العمليات،
 * ونغمات الحالة مكرّرة بين المقالات ولوحة المعلومات. والتكرار هنا لا يُنتج
 * خطأً صاخباً بل خطأً صامتاً: تُضاف عملية جديدة في الخادم فتُترجَم في صفحة
 * وتظهر بمعرّفها التقني في الأخرى.
 *
 * المصدر الحقيقي يبقى الخادم: هذي ترجمة عرضٍ فقط، ولا يُبنى عليها أي قرار.
 */

/** أسماء العمليات كما تُسجَّل في سجلّ التدقيق. */
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

/** ترجمة عملية، مع إبقاء المعرّف ظاهراً إن كانت جديدة ولم تُترجم بعد. */
export const actionLabel = (action) => ACTIONS[action] ?? action

const STATUS_TONES = {
  published: 'bg-brand-50 text-brand-700',
  draft: 'bg-amber-50 text-amber-800',
  scheduled: 'bg-ink-100 text-ink-600',
}

/** نغمة شارة الحالة. الحالة المجهولة تأخذ الرمادي لا تكسر التنسيق. */
export const statusTone = (status) => STATUS_TONES[status] ?? 'bg-ink-100 text-ink-600'
