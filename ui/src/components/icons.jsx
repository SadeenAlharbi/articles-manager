/*
 * Icons drawn by hand as SVG.
 *
 * No icon library: we need six of them, and pulling in a whole package for
 * that weighs the final bundle down for nothing. All are sized 20 with a
 * uniform stroke, so they read as a single family.
 */

const base = {
  width: 20,
  height: 20,
  viewBox: '0 0 24 24',
  fill: 'none',
  stroke: 'currentColor',
  strokeWidth: 1.75,
  strokeLinecap: 'round',
  strokeLinejoin: 'round',
  'aria-hidden': true,
}

export const IconArticles = (props) => (
  <svg {...base} {...props}>
    <path d="M4 5.5A1.5 1.5 0 0 1 5.5 4h9A1.5 1.5 0 0 1 16 5.5V19a1 1 0 0 1-1.6.8L4 12z" opacity="0" />
    <rect x="4" y="3.5" width="13" height="17" rx="2" />
    <path d="M7.5 8h6M7.5 12h6M7.5 16h3.5" />
    <path d="M17 7h2.5a1.5 1.5 0 0 1 1.5 1.5V19a1.5 1.5 0 0 1-1.5 1.5H17" />
  </svg>
)

export const IconUsers = (props) => (
  <svg {...base} {...props}>
    <circle cx="9" cy="8" r="3.25" />
    <path d="M3.5 19.5c0-3 2.5-5 5.5-5s5.5 2 5.5 5" />
    <path d="M16 5.6a3.25 3.25 0 0 1 0 4.8M17.5 14.9c2 .6 3.5 2.4 3.5 4.6" />
  </svg>
)

export const IconChart = (props) => (
  <svg {...base} {...props}>
    <path d="M4 4v15.5h16" />
    <path d="M7.5 15.5l3.5-4.5 3 3 4.5-6" />
  </svg>
)

export const IconAudit = (props) => (
  <svg {...base} {...props}>
    <rect x="4" y="3.5" width="16" height="17" rx="2" />
    <path d="M8 8.5h8M8 12.5h8M8 16.5h4.5" />
  </svg>
)

export const IconLogout = (props) => (
  <svg {...base} {...props}>
    <path d="M14 4.5H6.5A1.5 1.5 0 0 0 5 6v12a1.5 1.5 0 0 0 1.5 1.5H14" />
    <path d="M17.5 15.5 21 12l-3.5-3.5M21 12h-9" />
  </svg>
)

export const IconExternal = (props) => (
  <svg {...base} width={16} height={16} {...props}>
    <path d="M13.5 4.5H19.5V10.5" />
    <path d="M19.5 4.5 11 13" />
    <path d="M18 14.5V18a1.5 1.5 0 0 1-1.5 1.5h-10A1.5 1.5 0 0 1 5 18V8a1.5 1.5 0 0 1 1.5-1.5H10" />
  </svg>
)

export const IconSearch = (props) => (
  <svg {...base} width={16} height={16} {...props}>
    <circle cx="10.5" cy="10.5" r="6" />
    <path d="m15 15 4.5 4.5" />
  </svg>
)

export const IconMenu = (props) => (
  <svg {...base} {...props}>
    <path d="M4 7h16M4 12h16M4 17h16" />
  </svg>
)

export const IconMail = (props) => (
  <svg {...base} width={18} height={18} {...props}>
    <rect x="3" y="5" width="18" height="14" rx="2.5" />
    <path d="m3.5 7 8.5 6 8.5-6" />
  </svg>
)

export const IconLock = (props) => (
  <svg {...base} width={18} height={18} {...props}>
    <rect x="4.5" y="10" width="15" height="10" rx="2.5" />
    <path d="M8 10V7.5a4 4 0 0 1 8 0V10" />
  </svg>
)

export const IconEye = (props) => (
  <svg {...base} width={18} height={18} {...props}>
    <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" />
    <circle cx="12" cy="12" r="2.75" />
  </svg>
)

export const IconEyeOff = (props) => (
  <svg {...base} width={18} height={18} {...props}>
    <path d="M9.9 5.8A9.6 9.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-3 3.9" />
    <path d="M6.4 7.6A16.7 16.7 0 0 0 2.5 12S6 18.5 12 18.5c1.2 0 2.3-.2 3.3-.6" />
    <path d="m4 4 16 16" />
  </svg>
)

export const IconShield = (props) => (
  <svg {...base} {...props}>
    <path d="M12 3.5 19.5 6v6c0 4.2-3 7.4-7.5 8.5C7.5 19.4 4.5 16.2 4.5 12V6z" />
    <path d="m9 12 2 2 4-4" />
  </svg>
)

/* ------------------------- Article status icons ------------------------- */
/* Used in the dashboard cards, in the style of the indicator cards on the main platform. */

export const IconCheck = (props) => (
  <svg {...base} {...props}>
    <circle cx="12" cy="12" r="8.5" />
    <path d="M8.5 12.2l2.4 2.4 4.6-5" />
  </svg>
)

export const IconPencil = (props) => (
  <svg {...base} {...props}>
    <path d="M4 20h4L19.5 8.5a2.12 2.12 0 0 0-3-3L5 17v3z" />
    <path d="M14.5 6.5l3 3" />
  </svg>
)

export const IconClock = (props) => (
  <svg {...base} {...props}>
    <circle cx="12" cy="12" r="8.5" />
    <path d="M12 7.5V12l3 1.8" />
  </svg>
)
