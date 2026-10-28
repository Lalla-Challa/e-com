import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../services/api.js'
import { useAuth } from '../../stores/store.js'

/**
 * Attribute console: fully configurable attribute sets (fabric width, fiber,
 * weight, colour, size …) assigned to products. Products with a set become
 * variant-driven — each variant is a unique attribute combination with
 * independent stock and price adjustment.
 */
export function Attributes() {
  const [sets, setSets] = useState([])
  const [activeSet, setActiveSet] = useState(null)
  const [items, setItems] = useState([])
  const [newSet, setNewSet] = useState({ name: '', description: '' })
  const [newItem, setNewItem] = useState({ attributeName: '', position: 0, isRequired: true })
  const [editSet, setEditSet] = useState('')
  const [error, setError] = useState(null)
  const [notice, setNotice] = useState(null)

  const load = useCallback(() => {
    api.get('/attributes?sets=1').then(({ data }) => setSets(data.sets || [])).catch((e) => setError(e.message))
  }, [])

  useEffect(() => { load() }, [load])

  const flash = (text) => { setNotice(text); setError(null) }

  const openSet = (set) => {
    setActiveSet(set)
    api.get(`/attributes?set_id=${set.id}`).then(({ data }) => setItems(data.items || [])).catch(() => setItems([]))
  }

  const createSet = async (e) => {
    e.preventDefault()
    if (!newSet.name.trim()) return
    try {
      await api.post('/attributes?sets=1', { name: newSet.name.trim(), description: newSet.description.trim() })
      setNewSet({ name: '', description: '' })
      load()
      flash('Attribute set created')
    } catch (err) { setError(err.message) }
  }

  const addItem = async (e) => {
    e.preventDefault()
    if (!activeSet || !newItem.attributeName.trim()) return
    try {
      await api.post(`/attributes?set_id=${activeSet.id}&items=1`, {
        attribute_name: newItem.attributeName.trim(),
        position: Number(newItem.position) || 0,
        is_required: newItem.isRequired,
      })
      setNewItem({ attributeName: '', position: 0, isRequired: true })
      api.get(`/attributes?set_id=${activeSet.id}`).then(({ data }) => setItems(data.items || [])).catch(() => {})
      flash('Attribute added')
    } catch (err) { setError(err.message) }
  }

  const updateSet = async () => {
    if (!activeSet || !editSet.trim()) return
    try {
      await api.patch(`/attributes?set_id=${activeSet.id}`, { name: editSet.trim() })
      setEditSet('')
      load()
      flash('Set updated')
    } catch (err) { setError(err.message) }
  }

  const delSet = async (id) => {
    if (!window.confirm('Delete this attribute set? Products using it will lose variant configuration.')) return
    try {
      await api.delete(`/attributes?set_id=${id}`)
      setActiveSet(null)
      setItems([])
      load()
      flash('Attribute set deleted')
    } catch (err) { setError(err.message) }
  }

  const assignSet = async (productId, setId) => {
    try {
      await api.post(`/attributes?products=${productId}&set=1`, { set_id: Number(setId) })
      flash('Attribute set assigned to product')
    } catch (err) { setError(err.message) }
  }

  return (
    <div className="wrap">
      <nav className="breadcrumb" aria-label="Breadcrumb">
        <Link to="/admin">House console</Link>
        <span className="sep">/</span>
        <span aria-current="page">Attributes &amp; variants</span>
      </nav>

      <div className="admin-head">
        <div>
          <p className="eyebrow">Catalogue attributes</p>
          <h1 style={{ marginTop: 'var(--space-sm)' }}>Attribute sets &amp; variants</h1>
          <p className="t-body-sm muted">
            Define any attribute set (cloth width, fiber, weight, colour, size …). Assign it to a product and
            each variant becomes its own SKU with independent stock and price.
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
            <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Attribute sets</h3>
            <span className="eyebrow">{sets.length} sets</span>
          </div>
          <form onSubmit={createSet} className="field" style={{ marginBottom: 'var(--space-lg)' }}>
            <div className="row" style={{ gap: 'var(--space-sm)' }}>
              <input className="input grow" placeholder="Set name (e.g. Fabric)" value={newSet.name} onChange={(e) => setNewSet({ ...newSet, name: e.target.value })} />
              <input className="input grow" placeholder="Description" value={newSet.description} onChange={(e) => setNewSet({ ...newSet, description: e.target.value })} />
              <button className="btn btn-sm" type="submit" disabled={!newSet.name.trim()}>Create set</button>
            </div>
          </form>
          <table className="data-table">
            <thead><tr><th>Name</th><th className="num">Attributes</th><th className="muted">Active</th><th /></tr></thead>
            <tbody>
              {sets.map((s) => (
                <tr key={s.id}>
                  <td className="tabular">
                    {activeSet?.id === s.id ? (
                      <input value={editSet || s.name} onChange={(e) => setEditSet(e.target.value)} style={{ minWidth: 150 }} />
                    ) : s.name}
                  </td>
                  <td className="num">{items.filter((i) => activeSet?.id === s.id).length}</td>
                  <td className="num muted">{true}</td>
                  <td className="right">
                    {activeSet?.id === s.id ? (
                      <>
                        <button className="btn btn-sm" onClick={updateSet} disabled={!editSet.trim()}>Save</button>
                        <button className="btn btn-sm btn--outline" onClick={() => setEditSet('')}>Cancel</button>
                      </>
                    ) : (
                      <button className="btn btn-sm" onClick={() => openSet(s)}>Open</button>
                    )}
                    <button className="btn btn-sm" style={{ marginLeft: 'var(--space-xs)' }} onClick={() => delSet(s.id)}>Delete</button>
                  </td>
                </tr>
              ))}
              {sets.length === 0 && <tr><td colSpan={4} className="muted">No attribute sets yet.</td></tr>}
            </tbody>
          </table>
        </div>

        {activeSet && (
          <div className="panel">
            <div className="panel-head">
              <h3 style={{ margin: 0, fontSize: 'var(--title-lg)' }}>Attributes in “{activeSet.name}”</h3>
              <button className="eyebrow link-u" onClick={() => setActiveSet(null)}>← Back to sets</button>
            </div>
            <form onSubmit={addItem} className="grid" style={{ gridTemplateColumns: 'repeat(3, minmax(0, 1fr)) auto', gap: 'var(--space-sm)', marginBottom: 'var(--space-lg)' }}>
              <div className="field" style={{ marginBottom: 0 }}>
                <label>Attribute name</label>
                <input value={newItem.attributeName} onChange={(e) => setNewItem({ ...newItem, attributeName: e.target.value })} placeholder="width_cm" />
              </div>
              <div className="field" style={{ marginBottom: 0 }}>
                <label>Position</label>
                <input type="number" min="0" value={newItem.position} onChange={(e) => setNewItem({ ...newItem, position: e.target.value })} placeholder="0" />
              </div>
              <div className="field checkline" style={{ marginBottom: 0, alignItems: 'flex-end' }}>
                <input type="checkbox" checked={newItem.isRequired} onChange={(e) => setNewItem({ ...newItem, isRequired: e.target.checked })} />
                <label>Required</label>
              </div>
              <button className="btn btn-sm" type="submit" disabled={!newItem.attributeName.trim()}>Add attribute</button>
            </form>
            <table className="data-table">
              <thead><tr><th>Attribute</th><th className="num">Position</th><th className="muted">Required</th><th /></tr></thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id}>
                    <td className="tabular">{item.attribute_name}</td>
                    <td className="num">{item.position}</td>
                    <td className="num muted">{item.is_required ? 'yes' : 'no'}</td>
                    <td className="right"><button className="btn btn-sm">Delete</button></td>
                  </tr>
                ))}
                {items.length === 0 && <tr><td colSpan={4} className="muted">No attributes defined yet.</td></tr>}
              </tbody>
            </table>
            <p className="field-help" style={{ marginTop: 'var(--space-md)' }}>
              Assign this set to a product from the product editor, then add variants under that product.
            </p>
          </div>
        )}
      </div>
    </div>
  )
}
