/*
 * The logo of the Saudi knowledge platform — the very same logo file used in
 * the first project (public/images/logo.png), so the two interfaces read as
 * one system.
 *
 * Two variants:
 *   card  — the coloured logo inside a white square, for light surfaces
 *   white — a pure white logo with no frame, for dark surfaces
 *
 * The white variant is produced with a brightness(0) invert(1) filter: it
 * crushes every colour to black and then inverts it to white. That is cleaner
 * than leaving a coloured logo on a dark background, or boxing it into a white
 * square that cuts across the flow of the image.
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
