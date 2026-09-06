/*
 * شعار منصّة المعرفة السعودية — نفس ملف الشعار المستخدم في المشروع
 * الأول (public/images/logo.png)، فتبدو الواجهتان نظاماً واحداً.
 *
 * صيغتان:
 *   card  — الشعار الملوّن داخل مربّع أبيض، للأرضيات الفاتحة
 *   white — الشعار أبيض خالصاً بلا إطار، للأرضيات الداكنة
 *
 * الصيغة البيضاء تُنفَّذ بمرشّح brightness(0) invert(1): يُسقط كل
 * الألوان إلى الأسود ثم يعكسه أبيض. أنظف من إبقاء شعار ملوّن على
 * خلفية داكنة أو حبسه في مربّع أبيض يقطع انسياب الصورة.
 */
export default function Logo({ size = 40, variant = 'card', className = '' }) {
  if (variant === 'white') {
    return (
      <img
        src="/logo.png"
        alt="منصّة المعرفة السعودية"
        style={{ width: size, height: size }}
        className={`shrink-0 object-contain brightness-0 invert ${className}`}
      />
    )
  }

  return (
    <span
      className={`inline-flex shrink-0 items-center justify-center overflow-hidden
        rounded-xl bg-white ring-1 ring-black/5 ${className}`}
      style={{ width: size, height: size }}
    >
      <img
        src="/logo.png"
        alt="منصّة المعرفة السعودية"
        className="h-full w-full object-contain p-1"
      />
    </span>
  )
}
