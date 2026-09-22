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
 * The users page: accounts, roles, and individual permissions in one place.
 *
 * These are the admin system's users — they have nothing to do with the
 * knowledge platform's users.
 *
 * Every button here shows or hides based on what the server sends in the
 * can field for each row, not on a calculation the interface does itself.
 * Even if a button were hidden by mistake, the server refuses the action
 * anyway — hiding it is a UX improvement, not a security measure.
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

  // Modals: only one open at a time
  const [createOpen, setCreateOpen] = useState(false)
  const [editing, setEditing] = useState(null)
  const [access, setAccess] = useState(null)
  const [confirming, setConfirming] = useState(null)
  const [busy, setBusy] = useState(false)

  const [form, setForm] = useState(EMPTY_CREATE)
  const [errors, setErrors] = useState({})

  /* ------------------------------ Loading ------------------------------ */

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

  // Debounced search: we don't send a request on every keystroke
  useEffect(() => {
    const timer = setTimeout(() => load(search, status), 350)
    return () => clearTimeout(timer)
  }, [search, status, load])

  const assignableRoles = meta.roles.filter((role) => role.assignable)

  /*
   * The permissions of the role currently selected in the modal. Derived
   * from meta, not from the loaded row, so it updates immediately when the
   * role is switched, before saving.
   */
  const rolePermissions = permissionsOfRole(meta, access?.role)

  /* ------------------------------ Creating ------------------------------ */

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

  /* ------------------------------ Editing ------------------------------ */

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

  /* -------------------------- Role and permissions ------------------------- */

  /*
   * The state here is the "effective set": what the user should hold once
   * saving is done. A checked box means "holds it" regardless of source,
   * and an empty one means "doesn't hold it" even if their role grants it.
   * The server derives the grants and denials from this list.
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
   * Switching roles replaces the old role's permissions with the new
   * one's, and keeps any individual grant that doesn't come from a role —
   * so a manual grant isn't lost just by changing roles, and no permission
   * from a role the user no longer holds lingers behind.
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
      // Two separate calls because each is guarded by a different check on the server
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

  /* ------------------------------ Deactivation ------------------------------ */

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

  /* ------------------------------- Display ------------------------------- */

  return (
    <div>
      {/*
        No heading here: Layout already shows "Users" and its description
        at the top of the page, and repeating it would double the same
        line on screen.
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

                    {/* This account's exceptions to its role: what was added and what was removed */}
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

      {/* ------------------------------ Create ------------------------------ */}

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

      {/* ------------------------------ Edit ------------------------------ */}

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

      {/* ------------------------- Role and permissions ------------------------ */}

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
                    Removal is always available — lifting a permission is a
                    restriction, not an escalation. Adding one, though, is
                    governed by grantable: you can't grant what you don't
                    hold. So the lock only applies when the box is already
                    empty.
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

      {/* ----------------------------- Confirmation ----------------------------- */}

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

/** The permission names granted by a given role, as sent by the server in meta. */
function permissionsOfRole(meta, roleName) {
  return meta.roles.find((role) => role.name === roleName)?.permissions ?? []
}

/** Server validation errors -> a flat object: field name -> first message. */
function flatten(err) {
  const fields = err.data?.errors ?? {}

  return Object.fromEntries(
    Object.entries(fields).map(([field, messages]) => [field, messages[0]])
  )
}

/** A readable message based on the status code, instead of the raw text. */
function describe(err) {
  if (err.status === 403) return 'رُفضت العملية: لا تملك الصلاحية المطلوبة أو أن الحساب في مستواك أو أعلى (403).'
  if (err.status === 422) return err.message
  if (err.status === 401) return 'انتهت الجلسة. سجّل الدخول من جديد.'
  return err.message
}
