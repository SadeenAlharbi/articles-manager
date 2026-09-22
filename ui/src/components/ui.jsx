import { useEffect, useRef } from 'react'

/*
 * Shared interface elements.
 *
 * One file rather than a file per element: the pieces are small and closely
 * related, and pulling them apart is complexity for nothing. Their purpose is
 * that buttons, forms and states stay consistent across every page instead of
 * being written afresh, slightly differently, each time.
 */

/* -------------------------------- Buttons -------------------------------- */

const BUTTON_VARIANTS = {
  primary: 'bg-brand-600 text-white hover:bg-brand-700 disabled:bg-brand-600/60',
  secondary: 'border border-ink-200 bg-white text-ink-700 hover:bg-ink-50',
  danger: 'border border-red-200 bg-white text-red-700 hover:bg-red-50',
  ghost: 'text-ink-500 hover:bg-ink-50',
}

/*
 * The height is fixed, not derived from the padding.
 *
 * Padding on its own does not make a row line up: the browser draws a <select>
 * to its own metrics, so the dropdown comes out shorter than the input and the
 * button beside it even when their padding matches exactly. So the height is
 * pinned here, and in CONTROL_CLASS to the very same value.
 */
const BUTTON_SIZES = {
  sm: 'h-8 px-3 text-xs',
  md: 'h-10 px-4 text-sm',
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

/* ---------------------------- Toolbar controls ----------------------------- */

/**
 * The shared base for toolbar controls: exactly the height of the buttons (h-10).
 *
 * With no horizontal padding, deliberately — that is added at the call site,
 * since the search field needs room for its icon and the dropdown needs room
 * for its chevron, and a px-3 here would fight the pr/pl that override it.
 */
export const CONTROL_CLASS =
  'h-10 rounded-xl border border-ink-200 bg-white text-sm text-ink-800 outline-none ' +
  'transition-colors focus:border-brand-500'

/** A drawn chevron instead of the system one — Safari's arrow is what forces a different height. */
const CHEVRON =
  "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' " +
  "fill='none' stroke='%23a8a79f' stroke-width='2' stroke-linecap='round' " +
  "stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E\")"

/** A dropdown at the very same height as the fields. */
export function SelectControl({ className = '', children, ...props }) {
  return (
    <select
      className={`${CONTROL_CLASS} appearance-none pl-9 pr-3 ${className}`}
      style={{
        backgroundImage: CHEVRON,
        backgroundRepeat: 'no-repeat',
        backgroundPosition: 'left 0.75rem center',
        backgroundSize: '14px',
      }}
      {...props}
    >
      {children}
    </select>
  )
}

/* -------------------------------- Fields --------------------------------- */

const FIELD_BASE =
  'w-full rounded-xl border bg-white px-3 py-2 text-sm outline-none transition-colors ' +
  'focus:border-brand-500 disabled:bg-ink-50 disabled:text-ink-400'

/** A text field with a label and an optional error message. */
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

/* -------------------------------- States --------------------------------- */

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

/* -------------------------------- Modals --------------------------------- */

/**
 * A modal dialog.
 *
 * It closes on Escape and on a click outside it, stops the page behind it from
 * scrolling, and carries role="dialog" and aria-modal so a screen reader
 * announces it as a dialog rather than as running text.
 */
export function Modal({ open, title, onClose, children, footer, wide = false }) {
  const panelRef = useRef(null)

  /*
   * onClose is passed as an inline arrow function, so it is created anew on
   * every re-render of the parent page — which is to say on every character
   * typed into any field inside the modal. Left in the effect's dependency
   * array it would re-run the effect each time, and focus() would pull the
   * caret out of the field being typed in and back onto the modal panel. We
   * keep it in a ref, so the effect depends on open and nothing else.
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
   * On opening, focus moves to the first writable field inside the modal — so
   * the user can start typing straight away — or onto the modal panel itself
   * when it has no fields at all (the confirmation dialogs). Once, on open,
   * and no more than that.
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
 * A confirmation dialog for destructive operations.
 *
 * window.confirm is no substitute: it cannot be styled, does not support RTL,
 * does not explain what the operation entails, and offers no way to show an
 * in-progress state inside it.
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
