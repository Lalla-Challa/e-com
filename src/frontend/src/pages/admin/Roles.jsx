import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'
import { titleCase } from '../../lib/format.js'

const MODULES = ['customers', 'products', 'orders', 'returns', 'promotions', 'settings', 'invoices', 'system']

/**
 * Role & permission console. Roles are the store's access buckets
 * (customer / admin), permissions are the granular actions (products:edit,
 * orders:fulfill, settings:manage, …). Every assignment is persisted to
 * app_user_roles / app_role_permissions on the server.
 */
export function Roles() {
  const navigate = useNavigate()
  const [roles, setRoles] = useState([])
  const [permissions, setPermissions] = useState([])
  const [userRoles, setUserRoles] = useState([])
  const [grantDraft, setGrantDraft] = useState({ roleId: '', permissionId: '' })
  const [roleNameDraft, setRoleNameDraft] = useState('')
  const [permCodeDraft, setPermCodeDraft] = useState('')
  const [permLabelDraft, setPermLabelDraft] = useState('')
  const [permModuleDraft, setPermModuleDraft] = useState('core')
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get('/settings?store=1').then(() => {}).catch(() => {})
    Promise.all([
      api.get('/rbac?roles').catch(() => ({ data: { roles: [] } })),
      api.get('/rbac?permissions').catch(() => ({ data: { permissions: [] } })),
      api.get('/rbac?user-roles').catch(() => ({ data: [] })),
    ]).then(([r, p, ur]) => {
      setRoles(r.data.roles || [])
      setPermissions(p.data.permissions || [])
      setUserRoles(ur.data || [])
    }).catch((e) => setError(e.message))
  }, [])

  useEffect(() => { load() }, [load])

  const permFor = (roleId) => userRoles.filter((ur) => ur.role_id === Number(roleId)).map((ur) => ur.permission_id)

  const addRole = async (e) => {
    e.preventDefault()
    if (!roleNameDraft.trim()) return
    try {
      await api.post('/rbac?roles', { name: roleNameDraft.trim(), label: roleNameDraft.trim() })
      setRoleNameDraft('')
      load()
      setNotice('Role created')
    } catch (err) { setError(err.message) }
  }

  const addPermission = async (e) => {
    e.preventDefault()
    if (!permCodeDraft.trim() || !permLabelDraft.trim()) return
    try {
      await api.post('/rbac?permissions', {
        code: permCodeDraft.trim(),
        label: permLabelDraft.trim(),
        module: permModuleDraft || 'core',
      })
      setPermCodeDraft('')
      setPermLabelDraft('')
      load()
      setNotice('Permission created')
    } catch (err) { setError(err.message) }
  }

  const grant = async (e) => {
    e.preventDefault()
    if (!grantDraft.roleId || !grantDraft.permissionId) return
    try {
      await api.post('/rbac?grant', { role_id: Number(grantDraft.roleId), permission_id: Number(grantDraft.permissionId) })
      setGrantDraft({ roleId: '', permissionId: '' })
      load()
      setNotice('Permission granted')
    } catch (err) { setError(err.message) }
  }

  const revoke = async (roleId, permissionId) => {
    try {
      await api.delete(`/rbac?grant&role_id=${roleId}&permission_id=${permissionId}`)
      load()
      setNotice('Permission revoked')
    } catch (err) { setError(err.message) }
  }

  const flash = (text) => { setNotice(text); setError(null) }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Roles &amp; permissions</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Access control</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Roles &amp; permissions</h1>
          <p className="t-body-sm muted">
            Roles are the store's access buckets; permissions are granular actions. A user holding
            a role inherits every permission attached to it.
          </p>
        </div>
        <div className="right">
          <Link className="eyebrow link-u" to="/admin">Back to console</Link>
        </div>
      </div>

      {error && <p className="alert alert-error" style={{ marginBottom: 'var(--space-md)' }}>{error}</p>}
      {notice && <p className="alert alert-ok" style={{ marginBottom: 'var(--space-md)' }}>{notice}</p>}

      <div className="grid grid--split">
        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Roles</h3>
            <span className="eyebrow">{roles.length} roles</span>
          </div>
          <form onSubmit={addRole} className="field" style={{ marginBottom: 'var(--space-lg)' }}>
            <input
              className="input grow"
              placeholder="New role name (e.g. staff, accountant)"
              value={roleNameDraft}
              onChange={(e) => setRoleNameDraft(e.target.value)}
            />
            <button className="btn btn-sm" type="submit" disabled={!roleNameDraft.trim()}>Create role</button>
          </form>
          <table className="data-table">
            <thead><tr><th>Role</th><th className="num">Permissions</th><th className="num">Users</th></tr></thead>
            <tbody>
              {roles.map((role) => {
                const perms = permissions.filter((p) => permFor(role.id).includes(p.id))
                const userCount = userRoles.filter((ur) => ur.role_id === role.id).length
                return (
                  <tr key={role.id}>
                    <td className="tabular">{role.name}</td>
                    <td className="num">{perms.length}</td>
                    <td className="num">{userCount}</td>
                  </tr>
                )
              })}
              {roles.length === 0 && <tr><td colSpan={3} className="muted">No roles defined.</td></tr>}
            </tbody>
          </table>
        </div>

        <div className="panel">
          <div className="panel-head">
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Permissions</h3>
            <span className="eyebrow">{permissions.length} actions</span>
          </div>
          <form onSubmit={addPermission} className="grid" style={{ gridTemplateColumns: 'repeat(4, minmax(0, 1fr))', gap: 'var(--space-sm)', marginBottom: 'var(--space-lg)' }}>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Code</label>
              <input value={permCodeDraft} onChange={(e) => setPermCodeDraft(e.target.value)} placeholder="orders:fulfill" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Label</label>
              <input value={permLabelDraft} onChange={(e) => setPermLabelDraft(e.target.value)} placeholder="Fulfil orders" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Module</label>
              <select value={permModuleDraft} onChange={(e) => setPermModuleDraft(e.target.value)}>
                {MODULES.map((m) => <option key={m} value={m}>{m}</option>)}
              </select>
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <button className="btn btn-sm" type="submit" disabled={!permCodeDraft.trim() || !permLabelDraft.trim()}>Add</button>
            </div>
          </form>
          <table className="data-table">
            <thead><tr><th>Code</th><th>Label</th><th className="muted">Module</th></tr></thead>
            <tbody>
              {permissions.map((p) => (
                <tr key={p.id}>
                  <td className="tabular">{p.code}</td>
                    <td>{p.label}</td>
                  <td className="muted">{p.module}</td>
                </tr>
              ))}
              {permissions.length === 0 && <tr><td colSpan={3} className="muted">No permissions defined.</td></tr>}
            </tbody>
          </table>
        </div>
      </div>

      <div className="panel" style={{ marginTop: 'var(--space-lg)' }}>
        <div className="panel-head">
          <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Grant permission to role</h3>
          <span className="eyebrow">Attach an action to a role</span>
        </div>
        <form onSubmit={grant} className="grid" style={{ gridTemplateColumns: 'repeat(3, minmax(0, 1fr)) auto', gap: 'var(--space-sm)', marginBottom: 'var(--space-lg)' }}>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Role</label>
            <select value={grantDraft.roleId} onChange={(e) => setGrantDraft({ ...grantDraft, roleId: e.target.value })}>
              <option value="">— select role —</option>
              {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
            </select>
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Permission</label>
            <select value={grantDraft.permissionId} onChange={(e) => setGrantDraft({ ...grantDraft, permissionId: e.target.value })}>
              <option value="">— select permission —</option>
              {permissions.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}
            </select>
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <button className="btn btn-sm" type="submit" disabled={!grantDraft.roleId || !grantDraft.permissionId}>Grant</button>
          </div>
        </form>

        <div className="grid grid--split" style={{ marginTop: 'var(--space-lg)' }}>
          {roles.map((role) => {
            const granted = permissions.filter((p) => permFor(role.id).includes(p.id))
            const available = permissions.filter((p) => !permFor(role.id).includes(p.id))
            return (
              <div key={role.id} className="panel panel--flush">
                <div className="panel-head">
                  <h4 style={{ margin: 0 }}>{role.name}</h4>
                  <span className="eyebrow">{granted.length} granted</span>
                </div>
                <div className="grid grid--2">
                  <div style={{ padding: 'var(--space-sm)' }}>
                    <span className="eyebrow" style={{ display: 'block', marginBottom: 'var(--space-sm)' }}>Granted</span>
                    <ul>
                      {granted.map((p) => (
                        <li key={p.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--space-sm)', fontSize: 'var(--body-sm)', marginBottom: 'var(--space-xs)' }}>
                          <span>{p.code}</span>
                          <button className="link-u t-body-sm" onClick={() => revoke(role.id, p.id)}>Revoke</button>
                        </li>
                      ))}
                      {granted.length === 0 && <p className="muted t-body-sm">None granted.</p>}
                    </ul>
                  </div>
                  <div style={{ padding: 'var(--space-sm)' }}>
                    <span className="eyebrow" style={{ display: 'block', marginBottom: 'var(--space-sm)' }}>Available</span>
                    <ul>
                      {available.map((p) => (
                        <li key={p.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 'var(--space-sm)', fontSize: 'var(--body-sm)', marginBottom: 'var(--space-xs)' }}>
                          <span>{p.code}</span>
                          <button className="link-u t-body-sm" onClick={() => {
                            setGrantDraft({ roleId: String(role.id), permissionId: String(p.id) })
                            grant(new Event('submit'))
                          }}>Grant</button>
                        </li>
                      ))}
                      {available.length === 0 && <p className="muted t-body-sm">All permissions granted.</p>}
                    </ul>
                  </div>
                </div>
              </div>
            )
          })}
        </div>
      </div>
    </div>
  )
}
