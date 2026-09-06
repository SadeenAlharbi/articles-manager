import { useEffect, useRef } from 'react'

/*
 * عناصر واجهة مشتركة.
 *
 * ملف واحد لا ملف لكل عنصر: العناصر صغيرة ومترابطة، وتفريقها تعقيد
 * بلا مقابل. الغرض منها أن تكون الأزرار والنماذج والحالات متّسقة في
 * كل الصفحات بدل أن تُكتب من جديد في كل مرة بشكل مختلف قليلاً.
 */

/* -------------------------------- الأزرار -------------------------------- */

const BUTTON_VARIANTS = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-600/60',
  secondary: 'border border-ink-200 bg-white text-ink-700 hover:bg-ink-50',
  danger: 'border border-red-200 bg-white text-red-700 hover:bg-red-50',
  ghost: 'text-ink-500 hover:bg-ink-50',
}

const BUTTON_SIZES = {
  sm: 'px-3 py-1.5 text-xs',
  md: 'px-4 py-2 text-sm',
}

export function Button({
  variant = 'primary',
  size = 'md',
  type = 'button',
  className = '',
  children,
  ...props
}) {
  return (
    <button
      type={type}
      className={`inline-flex items-center justify-center gap-1.5 rounded-xl font-semibold
        transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500
        focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60
        ${BUTTON_VARIANTS[variant]} ${BUTTON_SIZES[size]} ${className}`}
      {...props}
    >
      {children}
    </button>
  )
}

/* -------------------------------- الحقول --------------------------------- */

const FIELD_BASE =
  'w-full rounded-xl border bg-white px-3 py-2 text-sm outline-none transition-colors ' +
  'focus:border-brand-500 disabled:bg-ink-50 disabled:text-ink-400'

/** حقل نصّي مع تسمية ورسالة خطأ اختيارية. */
export function Input({ label, error, hint, id, ...props }) {
  const inputId = id ?? props.name

  return (
    <div>
      {label && (
        <label htmlFor={inputId} className="mb-1.5 block text-sm font-semibold text-ink-700">
          {label}
        </label>
      )}

      <input
        id={inputId}
        aria-invalid={Boolean(error)}
        aria-describedby={error ? `${inputId}-error` : undefined}
        className={`${FIELD_BASE} ${error ? 'border-red-300' : 'border-ink-200'}`}
        {...props}
      />

      {hint && !error && <p className="mt-1 text-xs text-ink-400">{hint}</p>}
      {error && (
        <p id={`${inputId}-error`} className="mt-1 text-xs text-red-600">
          {error}
        </p>
      )}
    </div>
  )
}

export function Select({ label, error, id, children, ...props }) {
  const selectId = id ?? props.name

  return (
    <div>
      {label && (
        <label htmlFor={selectId} className="mb-1.5 block text-sm font-semibold text-ink-700">
          {label}
        </label>
      )}

      <select
        id={selectId}
        aria-invalid={Boolean(error)}
        className={`${FIELD_BASE} ${error ? 'border-red-300' : 'border-ink-200'}`}
        {...props}
      >
        {children}
      </select>

      {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  )
}

/* -------------------------------- الحالات -------------------------------- */

const ALERT_TONES = {
  success: 'bg-brand-50 text-brand-700',
  error: 'bg-red-50 text-red-700',
  info: 'bg-ink-50 text-ink-600',
}

export function Alert({ tone = 'info', children, onDismiss }) {
  if (!children) return null

  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={`mb-4 flex items-start justify-between gap-3 rounded-xl px-3 py-2 text-sm ${ALERT_TONES[tone]}`}
    >
      <span>{children}</span>
      {onDismiss && (
        <button type="button" onClick={onDismiss} aria-label="إغلاق" className="shrink-0 opacity-60 hover:opacity-100">
          ✕
        </button>
      )}
    </div>
  )
}

export function Spinner({ label = 'جارِ التحميل...' }) {
  return (
    <div className="flex items-center justify-center gap-2 py-10 text-sm text-ink-400" role="status">
      <span className="h-4 w-4 animate-spin rounded-full border-2 border-ink-200 border-t-brand-600" />
      {label}
    </div>
  )
}

export function EmptyState({ title, description, action }) {
  return (
    <div className="rounded-2xl border border-dashed border-ink-200 bg-white px-6 py-12 text-center">
      <p className="font-semibold text-ink-700">{title}</p>
      {description && <p className="mt-1 text-sm text-ink-400">{description}</p>}
      {action && <div className="mt-4 flex justify-center">{action}</div>}
    </div>
  )
}

const BADGE_TONES = {
  neutral: 'bg-ink-50 text-ink-600',
  success: 'bg-brand-50 text-brand-700',
  danger: 'bg-red-50 text-red-700',
  brand: 'bg-brand-600 text-white',
}

export function Badge({ tone = 'neutral', children, mono = false }) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
        ${mono ? 'font-mono text-[11px]' : ''} ${BADGE_TONES[tone]}`}
    >
      {children}
    </span>
  )
}

/* ------------------------------- النوافذ --------------------------------- */

/**
 * نافذة منبثقة.
 *
 * تُغلق بمفتاح Escape وبالنقر خارجها، وتمنع تمرير الصفحة خلفها،
 * وتحمل role="dialog" و aria-modal ليقرأها قارئ الشاشة نافذةً لا نصاً.
 */
export function Modal({ open, title, onClose, children, footer, wide = false }) {
  const panelRef = useRef(null)

  /*
   * onClose تُمرَّر دالةً سهمية مضمّنة، فتُنشأ من جديد مع كل إعادة رسم
   * للصفحة الأم — أي مع كل حرف يُكتب في أي حقل داخل النافذة. لو بقيت في
   * مصفوفة اعتماديات الأثر لأُعيد تشغيله في كل مرة، ولسحب focus() التركيز
   * من الحقل الذي تكتب فيه إلى جسم النافذة. نحفظها في ref فيبقى الأثر
   * معتمداً على open وحده.
   */
  const closeRef = useRef(onClose)
  closeRef.current = onClose

  useEffect(() => {
    if (!open) return undefined

    const onKeyDown = (event) => {
      if (event.key === 'Escape') closeRef.current()
    }

    document.addEventListener('keydown', onKeyDown)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKeyDown)
      document.body.style.overflow = previousOverflow
    }
  }, [open])

  /*
   * عند الفتح ينتقل التركيز إلى أول حقل قابل للكتابة داخل النافذة — فيبدأ
   * المستخدم بالكتابة مباشرة — أو إلى جسم النافذة نفسه إن لم يكن فيها حقول
   * (حوارات التأكيد). مرة واحدة عند الفتح فقط.
   */
  useEffect(() => {
    if (!open) return

    const panel = panelRef.current
    const firstField = panel?.querySelector('input:not([type="hidden"]), textarea, select')

    ;(firstField ?? panel)?.focus()
  }, [open])

  if (!open) return null

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink-900/40 p-4 pt-16"
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) onClose()
      }}
    >
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        className={`w-full rounded-2xl border border-ink-200 bg-white outline-none ${wide ? 'max-w-2xl' : 'max-w-md'}`}
      >
        <div className="flex items-center justify-between border-b border-ink-100 px-5 py-3.5">
          <h2 className="font-bold text-ink-900">{title}</h2>
          <button
            type="button"
            onClick={onClose}
            aria-label="إغلاق"
            className="rounded-lg px-2 py-1 text-ink-400 transition-colors hover:bg-ink-50"
          >
            ✕
          </button>
        </div>

        <div className="px-5 py-4">{children}</div>

        {footer && <div className="flex justify-start gap-2 border-t border-ink-100 px-5 py-3.5">{footer}</div>}
      </div>
    </div>
  )
}

/**
 * حوار تأكيد للعمليات الخطرة.
 *
 * لا يُستبدل بـwindow.confirm: ذاك لا يُنسَّق ولا يدعم RTL ولا يشرح
 * تبعات العملية، ولا يمكن إظهار حالة "جارِ التنفيذ" فيه.
 */
export function ConfirmDialog({ open, title, message, confirmLabel = 'تأكيد', tone = 'danger', busy = false, onConfirm, onClose }) {
  return (
    <Modal
      open={open}
      title={title}
      onClose={busy ? () => {} : onClose}
      footer={
        <>
          <Button variant={tone} onClick={onConfirm} disabled={busy}>
            {busy ? 'جارِ التنفيذ...' : confirmLabel}
          </Button>
          <Button variant="secondary" onClick={onClose} disabled={busy}>
            إلغاء
          </Button>
        </>
      }
    >
      <p className="text-sm leading-relaxed text-ink-600">{message}</p>
    </Modal>
  )
}
