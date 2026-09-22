import { useCallback, useEffect, useState } from 'react'
import { api } from '../lib/api'
import { useAuth } from '../auth/AuthContext'
import {
  Alert,
  Badge,
  Button,
  CONTROL_CLASS,
  ConfirmDialog,
  EmptyState,
  Input,
  Modal,
  Select,
  SelectControl,
  Spinner,
} from '../components/ui'

/*
 * صفحة المستخدمين: الحسابات والأدوار والصلاحيات الفردية في مكان واحد.
 *
 * هؤلاء مستخدمو نظام الإدارة — لا علاقة لهم بمستخدمي منصّة المعرفة.
 *
 * كل زر هنا يظهر أو يختفي حسب ما يُرسله الخادم في حقل can لكل صف،
 * لا حسب حساب تجريه الواجهة بنفسها. ولو أخفينا زراً بالخطأ فالخادم
 * يرفض العملية على أي حال — الإخفاء تحسين للتجربة لا إجراء أمني.
 */

const EMPTY_CREATE = { name: '', email: '', password: '', role: '', permissions: [] }

export default function UsersPage() {
  const { user: currentUser } = useAuth()

  const [users, setUsers] = useState([])
  const [meta, setMeta] = useState({ roles: [], permissions: [] })
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [loading, setLoading] = useState(true)
  const [notice, setNotice] = useState(null)

  // النوافذ: واحدة مفتوحة في كل مرة
  const [createOpen, setCreateOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [access, setAccess] = useState(null)
  const [confirming, setConfirming] = useState(null)
  const [busy, setBusy] = useState(false)

  const [form, setForm] = useState(EMPTY_CREATE)
  const [errors, setErrors] = useState({})

  /* ------------------------------ التحميل ------------------------------ */

  const load = useCallback(async (term, statusFilter) => {
    setLoading(true)
    try {
      const response = await api('/users', { params: { search: term, status: statusFilter } })
      setUsers(response.data ?? [])
    } catch (err) {
      setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    api('/users/meta')
      .then(setMeta)
      .catch(() => setMeta({ roles: [], permissions: [] }))
  }, [])

  // بحث مؤجّل: لا نرسل طلباً مع كل حرف
  useEffect(() => {
    const timer = setTimeout(() => load(search, status), 350)
    return () => clearTimeout(timer)
  }, [search, status, load])

  const assignableRoles = meta.roles.filter((role) => role.assignable)

  /*
   * صلاحيات الدور المختار حالياً في النافذة. تُشتق من meta لا من الصف
   * المحمَّل، فتتحدّث فوراً عند تبديل الدور قبل الحفظ.
   */
  const rolePermissions = permissionsOfRole(meta, access?.role)

  /* ------------------------------ الإنشاء ------------------------------ */

  function openCreate() {
    setForm({ ...EMPTY_CREATE, role: assignableRoles[0]?.name ?? '' })
    setErrors({})
    setCreateOpen(true)
  }

  async function submitCreate(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})

    try {
      await api('/users', { method: 'POST', body: form })
      setCreateOpen(false)
      setNotice({ tone: 'success', text: `تم إنشاء حساب ${form.name}.` })
      await load(search, status)
    } catch (err) {
      setErrors(flatten(err))
      if (!err.data?.errors) setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setBusy(false)
    }
  }

  /* ------------------------------ التعديل ------------------------------ */

  function openEdit(row) {
    setEditing({ ...row, password: '' })
    setErrors({})
  }

  async function submitEdit(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})

    try {
      const body = { name: editing.name, email: editing.email }
      if (editing.password) body.password = editing.password

      await api(`/users/${editing.id}`, { method: 'PUT', body })
      setEditing(null)
      setNotice({ tone: 'success', text: 'تم حفظ التعديلات.' })
      await load(search, status)
    } catch (err) {
      setErrors(flatten(err))
      if (!err.data?.errors) setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setBusy(false)
    }
  }

  /* -------------------------- الدور والصلاحيات ------------------------- */

  /*
   * الحالة هنا هي «المجموعة الفعّالة»: ما ينبغي أن يملكه المستخدم بعد
   * الحفظ. الصندوق المؤشَّر يعني «يملكها» أياً كان مصدرها، والخالي يعني
   * «لا يملكها» ولو منحها دوره. الخادم يشتق من هذي القائمة المنحَ والحجب.
   */
  function openAccess(row) {
    setAccess({
      id: row.id,
      name: row.name,
      role: row.role ?? '',
      permissions: [...(row.permissions ?? [])],
    })
    setErrors({})
  }

  function togglePermission(name) {
    setAccess((current) => ({
      ...current,
      permissions: current.permissions.includes(name)
        ? current.permissions.filter((item) => item !== name)
        : [...current.permissions, name],
    }))
  }

  /*
   * تبديل الدور يستبدل صلاحيات الدور القديم بصلاحيات الجديد، ويُبقي المنح
   * الفردي الذي لا يأتي من أي دور — فلا يضيع ما مُنح يدوياً بمجرد تغيير
   * الدور، ولا تبقى صلاحيات دور لم يعد له.
   */
  function changeRole(name) {
    setAccess((current) => {
      const previousRolePermissions = permissionsOfRole(meta, current.role)
      const nextRolePermissions = permissionsOfRole(meta, name)
      const kept = current.permissions.filter((item) => !previousRolePermissions.includes(item))

      return { ...current, role: name, permissions: [...new Set([...nextRolePermissions, ...kept])] }
    })
  }

  async function submitAccess(event) {
    event.preventDefault()
    setBusy(true)
    setErrors({})

    try {
      // مساران منفصلان لأن كلاً منهما يحرسه فحص مختلف على الخادم
      await api(`/users/${access.id}/role`, { method: 'PUT', body: { role: access.role } })
      await api(`/users/${access.id}/permissions`, { method: 'PUT', body: { permissions: access.permissions } })

      setAccess(null)
      setNotice({ tone: 'success', text: `تم تحديث صلاحيات ${access.name}.` })
      await load(search, status)
    } catch (err) {
      setErrors(flatten(err))
      if (!err.data?.errors) setNotice({ tone: 'error', text: describe(err) })
    } finally {
      setBusy(false)
    }
  }

  /* ------------------------------ التعطيل ------------------------------ */

  async function confirmToggle() {
    setBusy(true)

    try {
      await api(`/users/${confirming.id}/toggle-active`, { method: 'POST' })
      setNotice({
        tone: 'success',
        text: confirming.is_active
          ? `تم تعطيل حساب ${confirming.name}، ولم تُحذف أي بيانات.`
          : `تم تفعيل حساب ${confirming.name}.`,
      })
      setConfirming(null)
      await load(search, status)
    } catch (err) {
      setNotice({ tone: 'error', text: describe(err) })
      setConfirming(null)
    } finally {
      setBusy(false)
    }
  }

  /* ------------------------------- العرض ------------------------------- */

  return (
    <div>
      {/*
        لا عنوان هنا: Layout يعرض «المستخدمون» ووصفها في صدر الصفحة أصلاً،
        وتكراره يضاعف نفس السطر مرتين على الشاشة.
      */}
      <div className="mb-5 flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap gap-2">
          <input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="ابحث بالاسم أو البريد..."
            aria-label="بحث"
            className={`${CONTROL_CLASS} w-64 px-3`}
          />

          <SelectControl
            value={status}
            onChange={(event) => setStatus(event.target.value)}
            aria-label="تصفية حسب الحالة"
          >
            <option value="">كل الحالات</option>
            <option value="active">مفعّل</option>
            <option value="inactive">معطَّل</option>
          </SelectControl>
        </div>

        <Button onClick={openCreate} disabled={assignableRoles.length === 0}>
          مستخدم جديد
        </Button>
      </div>

      <Alert tone={notice?.tone} onDismiss={() => setNotice(null)}>
        {notice?.text}
      </Alert>

      {loading ? (
        <Spinner />
      ) : users.length === 0 ? (
        <EmptyState
          title="لا توجد نتائج"
          description={search || status ? 'جرّب تعديل البحث أو التصفية.' : 'لم يُنشأ أي حساب بعد.'}
        />
      ) : (
        <div className="overflow-x-auto rounded-2xl border border-ink-100 bg-white">
          <table className="w-full text-right text-sm">
            <thead className="border-b border-ink-100 bg-ink-50 text-xs text-ink-500">
              <tr>
                <th className="px-4 py-2.5 font-semibold">المستخدم</th>
                <th className="px-4 py-2.5 font-semibold">الدور</th>
                <th className="px-4 py-2.5 font-semibold">الحالة</th>
                <th className="px-4 py-2.5 font-semibold">الصلاحيات</th>
                <th className="px-4 py-2.5 font-semibold">إجراءات</th>
              </tr>
            </thead>

            <tbody>
              {users.map((row) => (
                <tr key={row.id} className="border-b border-ink-50 last:border-0">
                  <td className="px-4 py-3">
                    <div className="font-semibold text-ink-900">
                      {row.name}
                      {row.id === currentUser?.id && (
                        <span className="mr-2 text-xs font-normal text-ink-400">(أنت)</span>
                      )}
                    </div>
                    <div className="text-xs text-ink-400">{row.email}</div>
                  </td>

                  <td className="px-4 py-3">
                    <Badge tone="neutral">{row.role_label ?? row.role ?? '—'}</Badge>
                  </td>

                  <td className="px-4 py-3">
                    <Badge tone={row.is_active ? 'success' : 'danger'}>
                      {row.is_active ? 'مفعّل' : 'معطَّل'}
                    </Badge>
                  </td>

                  <td className="px-4 py-3">
                    <span className="text-xs text-ink-500">{row.permissions.length} صلاحية</span>

                    {/* استثناءات هذا الحساب عن دوره: ما زاد وما نقص */}
                    {(row.extra_permissions?.length ?? 0) > 0 && (
                      <span className="mr-1.5 text-xs font-semibold text-amber-700">
                        +{row.extra_permissions.length} منحة
                      </span>
                    )}

                    {(row.denied_permissions?.length ?? 0) > 0 && (
                      <span className="mr-1.5 text-xs font-semibold text-red-600">
                        −{row.denied_permissions.length} محجوبة
                      </span>
                    )}
                  </td>

                  <td className="px-4 py-3">
                    <div className="flex flex-wrap gap-1.5">
                      {row.can.update && (
                        <Button variant="secondary" size="sm" onClick={() => openEdit(row)}>
                          تعديل
                        </Button>
                      )}

                      {row.can.manage_access && (
                        <Button variant="secondary" size="sm" onClick={() => openAccess(row)}>
                          الصلاحيات
                        </Button>
                      )}

                      {row.can.toggle_active && (
                        <Button
                          variant={row.is_active ? 'danger' : 'secondary'}
                          size="sm"
                          onClick={() => setConfirming(row)}
                        >
                          {row.is_active ? 'تعطيل' : 'تفعيل'}
                        </Button>
                      )}

                      {!row.can.update && !row.can.manage_access && !row.can.toggle_active && (
                        <span className="text-xs text-ink-300">لا صلاحية</span>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* ------------------------------ إنشاء ------------------------------ */}

      <Modal
        open={createOpen}
        title="مستخدم جديد"
        onClose={() => setCreateOpen(false)}
        footer={
          <>
            <Button onClick={submitCreate} disabled={busy}>
              {busy ? 'جارِ الإنشاء...' : 'إنشاء الحساب'}
            </Button>
            <Button variant="secondary" onClick={() => setCreateOpen(false)} disabled={busy}>
              إلغاء
            </Button>
          </>
        }
      >
        <form onSubmit={submitCreate} className="flex flex-col gap-3.5">
          <Input
            name="name"
            label="الاسم"
            value={form.name}
            onChange={(event) => setForm({ ...form, name: event.target.value })}
            error={errors.name}
            required
          />

          <Input
            name="email"
            type="email"
            label="البريد الإلكتروني"
            value={form.email}
            onChange={(event) => setForm({ ...form, email: event.target.value })}
            error={errors.email}
            required
          />

          <Input
            name="password"
            type="password"
            label="كلمة المرور"
            hint="8 أحرف على الأقل"
            value={form.password}
            onChange={(event) => setForm({ ...form, password: event.target.value })}
            error={errors.password}
            required
          />

          <Select
            name="role"
            label="الدور"
            value={form.role}
            onChange={(event) => setForm({ ...form, role: event.target.value })}
            error={errors.role}
          >
            {assignableRoles.map((role) => (
              <option key={role.name} value={role.name}>
                {role.label}
              </option>
            ))}
          </Select>
        </form>
      </Modal>

      {/* ------------------------------ تعديل ------------------------------ */}

      <Modal
        open={Boolean(editing)}
        title={editing ? `تعديل: ${editing.name}` : ''}
        onClose={() => setEditing(null)}
        footer={
          <>
            <Button onClick={submitEdit} disabled={busy}>
              {busy ? 'جارِ الحفظ...' : 'حفظ'}
            </Button>
            <Button variant="secondary" onClick={() => setEditing(null)} disabled={busy}>
              إلغاء
            </Button>
          </>
        }
      >
        {editing && (
          <form onSubmit={submitEdit} className="flex flex-col gap-3.5">
            <Input
              name="name"
              label="الاسم"
              value={editing.name}
              onChange={(event) => setEditing({ ...editing, name: event.target.value })}
              error={errors.name}
            />

            <Input
              name="email"
              type="email"
              label="البريد الإلكتروني"
              value={editing.email}
              onChange={(event) => setEditing({ ...editing, email: event.target.value })}
              error={errors.email}
            />

            <Input
              name="password"
              type="password"
              label="كلمة مرور جديدة"
              hint="اتركه فارغاً للإبقاء على كلمة المرور الحالية"
              value={editing.password}
              onChange={(event) => setEditing({ ...editing, password: event.target.value })}
              error={errors.password}
            />
          </form>
        )}
      </Modal>

      {/* ------------------------- الدور والصلاحيات ------------------------ */}

      <Modal
        open={Boolean(access)}
        title={access ? `صلاحيات: ${access.name}` : ''}
        onClose={() => setAccess(null)}
        wide
        footer={
          <>
            <Button onClick={submitAccess} disabled={busy}>
              {busy ? 'جارِ الحفظ...' : 'حفظ الصلاحيات'}
            </Button>
            <Button variant="secondary" onClick={() => setAccess(null)} disabled={busy}>
              إلغاء
            </Button>
          </>
        }
      >
        {access && (
          <form onSubmit={submitAccess} className="flex flex-col gap-4">
            <Select
              name="role"
              label="الدور"
              value={access.role}
              onChange={(event) => changeRole(event.target.value)}
              error={errors.role}
            >
              {assignableRoles.map((role) => (
                <option key={role.name} value={role.name}>
                  {role.label}
                </option>
              ))}
            </Select>

            <div>
              <p className="mb-2.5 text-sm font-semibold text-ink-700">صلاحيات فردية</p>

              <div className="grid gap-1.5 sm:grid-cols-2">
                {meta.permissions.map((permission) => {
                  const held = access.permissions.includes(permission.name)
                  const fromRole = rolePermissions.includes(permission.name)

                  /*
                    الإزالة متاحة دائماً — رفعُ صلاحية تقييد لا تصعيد. أما
                    الإضافة فمحكومة بـgrantable: لا تمنح ما لا تملك. ولهذا
                    القفل مشروط بأن يكون الصندوق خالياً أصلاً.
                  */
                  const cannotGrant = !permission.grantable && !held

                  const state = held
                    ? (fromRole ? 'role' : 'granted')
                    : (fromRole ? 'denied' : 'off')

                  const STYLES = {
                    role: 'border-brand-100 bg-brand-50 text-brand-700',
                    granted: 'border-amber-200 bg-amber-50 text-amber-800',
                    denied: 'border-red-200 bg-red-50 text-red-700',
                    off: 'border-ink-200',
                  }

                  const CHIPS = { role: 'من الدور', granted: 'منحة', denied: 'محجوبة' }

                  return (
                    <label
                      key={permission.name}
                      className={`flex items-center justify-between gap-2 rounded-xl border px-3 py-2 text-sm
                        transition-colors ${
                          cannotGrant
                            ? 'cursor-not-allowed border-ink-100 bg-ink-50 text-ink-300'
                            : `cursor-pointer hover:opacity-85 ${STYLES[state]}`
                        }`}
                    >
                      <span className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          className="accent-brand-600"
                          disabled={cannotGrant}
                          checked={held}
                          onChange={() => togglePermission(permission.name)}
                        />
                        <span>{permission.label}</span>
                      </span>

                      {!cannotGrant && CHIPS[state] && (
                        <span className="shrink-0 rounded-full bg-white/80 px-2 py-0.5 text-[10px] font-semibold">
                          {CHIPS[state]}
                        </span>
                      )}
                    </label>
                  )
                })}
              </div>

              {errors.permissions && <p className="mt-2 text-xs text-red-600">{errors.permissions}</p>}
            </div>
          </form>
        )}
      </Modal>

      {/* ----------------------------- التأكيد ----------------------------- */}

      <ConfirmDialog
        open={Boolean(confirming)}
        busy={busy}
        title={confirming?.is_active ? 'تعطيل الحساب' : 'تفعيل الحساب'}
        confirmLabel={confirming?.is_active ? 'تعطيل' : 'تفعيل'}
        tone={confirming?.is_active ? 'danger' : 'primary'}
        message={
          confirming?.is_active
            ? `سيُمنع ${confirming?.name} من تسجيل الدخول، وتُلغى جلساته المفتوحة فوراً. لا تُحذف بياناته ولا سجلّ عملياته، ويمكن إعادة تفعيله في أي وقت.`
            : `سيُعاد تفعيل حساب ${confirming?.name} ويستطيع تسجيل الدخول من جديد.`
        }
        onConfirm={confirmToggle}
        onClose={() => setConfirming(null)}
      />
    </div>
  )
}

/* -------------------------------------------------------------------------- */

/** أسماء الصلاحيات التي يمنحها دور بعينه، حسب ما أرسله الخادم في meta. */
function permissionsOfRole(meta, roleName) {
  return meta.roles.find((role) => role.name === roleName)?.permissions ?? []
}

/** أخطاء التحقّق من الخادم ← كائن مسطّح: اسم الحقل ← أول رسالة. */
function flatten(err) {
  const fields = err.data?.errors ?? {}

  return Object.fromEntries(
    Object.entries(fields).map(([field, messages]) => [field, messages[0]])
  )
}

/** رسالة مفهومة حسب رمز الحالة، بدل النصّ الخام. */
function describe(err) {
  if (err.status === 403) return 'رُفضت العملية: لا تملك الصلاحية المطلوبة أو أن الحساب في مستواك أو أعلى (403).'
  if (err.status === 422) return err.message
  if (err.status === 401) return 'انتهت الجلسة. سجّل الدخول من جديد.'
  return err.message
}
