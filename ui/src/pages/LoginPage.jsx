import { useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { Alert } from '../components/ui'
import Logo from '../components/Logo'
import { IconArticles, IconAudit, IconEye, IconEyeOff, IconLock, IconMail, IconShield, IconUsers } from '../components/icons'

/*
 * صفحة الدخول.
 *
 * لوحتان: الهوية على اليمين (اتجاه القراءة العربي يبدأ منه)، والنموذج
 * على اليسار. الخلفية صورة الرياض بطبقة خضراء تُخفض تباينها حتى يبقى
 * النص مقروءاً فوقها — الصورة خلفية لا بطلة الصفحة.
 *
 * لا تسجيل دخول عبر Google هنا: مستخدمو نظام الإدارة يُنشئهم مديرو
 * النظام، ولا يسجّلون أنفسهم بحساب خارجي.
 */

const FEATURES = [
  { icon: IconArticles, label: 'إدارة المقالات' },
  { icon: IconUsers, label: 'إدارة المستخدمين' },
  { icon: IconShield, label: 'صلاحيات دقيقة' },
  { icon: IconAudit, label: 'سجلّ العمليات' },
]

export default function LoginPage() {
  const { login } = useAuth()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')
    setBusy(true)

    try {
      await login(email, password)
    } catch (err) {
      setError(err.message)
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="grid min-h-screen lg:grid-cols-[1fr_30rem]">
      {/* ------------------------------ الهوية ------------------------------ */}
      <section className="relative hidden overflow-hidden lg:block">
        <img
          src="/riyadh.jpg"
          alt=""
          aria-hidden="true"
          className="absolute inset-0 h-full w-full object-cover"
        />

        {/*
          التعتيم أفقي لا رأسي: معتم بالكامل على اليسار حيث يقع النص فيقرأ
          على أرضية نظيفة، ويتلاشى تماماً نحو اليمين فيظهر برج المملكة
          وأضواء المدينة بألوانها كاملة. هذا هو أساس تصميم المرجع.
        */}
        <div className="absolute inset-0 bg-gradient-to-r from-flag-950 via-flag-950/72 to-transparent" />

        {/* شريط علوي خفيف يثبّت الشعار فوق السماء دون تعتيم الصورة كلها */}
        <div className="absolute inset-x-0 top-0 h-44 bg-gradient-to-b from-flag-950/55 to-transparent" />

        {/*
          المنحنى الأخضر يملأ الزاوية السفلى اليمنى وترتفع حافّته نحو اليمين،
          وتقف العبارة الختامية فوقه. طبقتان: حافّة عليا فاتحة شفّافة، وكتلة
          سفلى صلبة تعطي النص أرضيةً واضحة.
        */}
        <svg
          className="absolute bottom-0 right-0 h-[55%] w-[85%]"
          viewBox="0 0 100 100"
          preserveAspectRatio="none"
          aria-hidden="true"
        >
          <path d="M0 100 C 33 88 64 72 100 46 L100 100 Z" fill="var(--color-brand-500)" fillOpacity="0.32" />
          <path d="M9 100 C 40 92 68 78 100 60 L100 100 Z" fill="var(--color-brand-700)" fillOpacity="0.92" />
        </svg>

        <div className="relative flex h-full flex-col justify-between p-12 text-white">
          {/*
            ml-auto فيزيائية تدفع الكتلة إلى يمين اللوحة. لا نستخدم items-end
            هنا لأن الصفحة RTL فتُترجم flex-end إلى اليسار — وهي سبب ظهور
            الشعار على اليسار سابقاً.
          */}
          <div className="ml-auto flex w-fit flex-col items-center gap-2">
            <Logo size={44} variant="white" className="drop-shadow-[0_2px_10px_rgba(0,0,0,0.5)]" />
            <span className="text-xs font-bold tracking-wide drop-shadow-[0_1px_10px_rgba(0,0,0,0.6)]">
              منصّة المعرفة السعودية
            </span>
          </div>

          {/*
            mr-auto (فيزيائية لا منطقية) تُبقي الكتلة في يسار اللوحة فوق
            الجزء المعتم، ويبقى برج المملكة على اليمين بلا نص فوقه.
          */}
          <div className="mr-auto w-full max-w-[24rem]">
            <h1 className="text-[1.9rem] font-bold leading-[1.45]">
              إدارة محتوى المنصّة
            </h1>

            <p className="mt-4 text-[0.85rem] leading-loose text-white/80">
              نظام إدارة مستقل لإدارة محتوى المنصّة، يتيح التحكّم الكامل بالمقالات
              والمستخدمين والصلاحيات بسهولة وأمان.
            </p>

            {/* الميزات بفواصل رأسية بينها — كما في المرجع */}
            <div className="mt-7 flex divide-x divide-white/20">
              {FEATURES.map(({ icon: Icon, label }) => (
                <div
                  key={label}
                  className="flex flex-col items-center gap-1.5 px-3 text-white/90 first:pr-0 last:pl-0"
                >
                  <Icon width={18} height={18} />
                  <span className="text-[0.65rem] font-medium">{label}</span>
                </div>
              ))}
            </div>
          </div>

          {/* العبارة الختامية فوق الكتلة الخضراء مباشرة */}
          <p className="text-right text-sm font-semibold leading-loose text-white">
            معاً نحو محتوى معرفي
            <br />
            يساهم في بناء مستقبل أفضل
          </p>
        </div>
      </section>

      {/* ------------------------------ النموذج ----------------------------- */}
      <section className="flex flex-col justify-center bg-ink-25 px-6 py-12 sm:px-12">
        <div className="mx-auto w-full max-w-sm">
          <div className="mb-9 flex flex-col items-center text-center">
            <Logo size={60} className="shadow-sm" />
            <h2 className="mt-4 text-lg font-bold text-ink-900">نظام إدارة المقالات</h2>
            <p className="text-xs text-ink-400">منصّة المعرفة السعودية</p>
          </div>

          <h3 className="text-2xl font-bold text-ink-900">حيّاك الله</h3>
          <p className="mt-1 text-sm text-ink-400">سجّل الدخول للوصول إلى لوحة إدارة المحتوى</p>

          <form onSubmit={handleSubmit} className="mt-7 flex flex-col gap-4">
            <div>
              <label htmlFor="email" className="mb-1.5 block text-sm font-semibold text-ink-700">
                البريد الإلكتروني
              </label>

              <div className="relative">
                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-300">
                  <IconMail />
                </span>
                <input
                  id="email"
                  type="email"
                  value={email}
                  onChange={(event) => setEmail(event.target.value)}
                  placeholder="أدخل بريدك الإلكتروني"
                  autoComplete="username"
                  required
                  className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pr-10 pl-3 text-sm
                    outline-none transition-colors placeholder:text-ink-300 focus:border-brand-500"
                />
              </div>
            </div>

            <div>
              <label htmlFor="password" className="mb-1.5 block text-sm font-semibold text-ink-700">
                كلمة المرور
              </label>

              <div className="relative">
                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-ink-300">
                  <IconLock />
                </span>

                <input
                  id="password"
                  type={showPassword ? 'text' : 'password'}
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                  placeholder="أدخل كلمة المرور"
                  autoComplete="current-password"
                  required
                  className="w-full rounded-xl border border-ink-200 bg-white py-2.5 pr-10 pl-10 text-sm
                    outline-none transition-colors placeholder:text-ink-300 focus:border-brand-500"
                />

                <button
                  type="button"
                  onClick={() => setShowPassword((current) => !current)}
                  aria-label={showPassword ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'}
                  className="absolute left-3 top-1/2 -translate-y-1/2 text-ink-300 transition-colors hover:text-ink-500"
                >
                  {showPassword ? <IconEyeOff /> : <IconEye />}
                </button>
              </div>
            </div>

            {error && <Alert tone="error">{error}</Alert>}

            <button
              type="submit"
              disabled={busy}
              className="mt-1 w-full rounded-xl bg-brand-600 py-2.5 text-sm font-bold text-white
                transition-colors hover:bg-brand-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {busy ? 'جارِ الدخول...' : 'تسجيل الدخول'}
            </button>
          </form>
        </div>
      </section>
    </div>
  )
}
