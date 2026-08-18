import { useEffect, useRef, useState } from 'react'
import { ChevronDown, ChevronRight, X } from 'lucide-react'

export function buildTree(flat, parentField) {
  const map = {}
  flat.forEach((item) => { map[item.id] = { ...item, children: [] } })
  const roots = []
  flat.forEach((item) => {
    const pid = item[parentField]
    if (pid && map[pid]) {
      map[pid].children.push(map[item.id])
    } else {
      roots.push(map[item.id])
    }
  })
  return roots
}

function TreeNode({ node, labelField, selectedValue, onSelect, depth }) {
  const [expanded, setExpanded] = useState(true)
  const hasChildren = node.children.length > 0
  const isSelected  = String(node.id) === String(selectedValue)

  return (
    <div>
      <div
        className={[
          'flex items-center gap-1 rounded cursor-pointer select-none',
          isSelected ? 'bg-indigo-50 text-indigo-700' : 'text-slate-700 hover:bg-slate-100',
        ].join(' ')}
        style={{ paddingLeft: `${6 + depth * 14}px`, paddingRight: 6, paddingTop: 3, paddingBottom: 3 }}
      >
        <button
          type="button"
          tabIndex={-1}
          onClick={() => hasChildren && setExpanded((v) => !v)}
          className="shrink-0 flex items-center justify-center w-3.5 h-3.5 text-slate-400"
        >
          {hasChildren
            ? expanded ? <ChevronDown size={11} /> : <ChevronRight size={11} />
            : <span className="w-3.5" />}
        </button>
        <span
          className={['flex-1 text-xs truncate', isSelected ? 'font-semibold' : ''].join(' ')}
          onClick={() => onSelect(String(node.id))}
        >
          {node[labelField]}
        </span>
      </div>

      {hasChildren && expanded && (
        <div>
          {node.children.map((child) => (
            <TreeNode
              key={child.id}
              node={child}
              labelField={labelField}
              selectedValue={selectedValue}
              onSelect={onSelect}
              depth={depth + 1}
            />
          ))}
        </div>
      )}
    </div>
  )
}

export default function TreeSelect({
  name,
  value,
  onChange,
  items,
  parentField,
  labelField,
  error,
  touched,
  placeholder,
  emptyText,
  className,
}) {
  const [open, setOpen]   = useState(false)
  const containerRef      = useRef(null)
  const tree              = buildTree(items, parentField)
  const selectedName      = items.find((item) => String(item.id) === String(value))?.[labelField]

  useEffect(() => {
    if (!open) return
    const handler = (e) => {
      if (containerRef.current && !containerRef.current.contains(e.target)) setOpen(false)
    }
    document.addEventListener('mousedown', handler)
    return () => document.removeEventListener('mousedown', handler)
  }, [open])

  const handleSelect = (id) => {
    onChange({ target: { name, value: id } })
    setOpen(false)
  }

  const handleClear = (e) => {
    e.stopPropagation()
    onChange({ target: { name, value: '' } })
  }

  return (
    <div className={`relative ${className ?? ''}`} ref={containerRef}>
      <button
        type="button"
        onClick={() => setOpen((v) => !v)}
        className={[
          'flex w-full items-center justify-between gap-1 rounded border px-2 py-1 text-sm text-left bg-white outline-none transition-all',
          'focus:ring-1 focus:ring-indigo-500/30',
          error && touched
            ? 'border-red-400 focus:border-red-400'
            : 'border-slate-300 focus:border-indigo-400',
        ].join(' ')}
      >
        <span className={['truncate text-xs', selectedName ? 'text-slate-800' : 'text-slate-400'].join(' ')}>
          {selectedName ?? placeholder ?? '— Select —'}
        </span>
        <div className="flex shrink-0 items-center gap-1">
          {value && (
            <span onClick={handleClear} title="Clear" className="cursor-pointer text-slate-400 hover:text-slate-600">
              <X size={11} />
            </span>
          )}
          <ChevronDown size={12} className={['text-slate-400 transition-transform duration-150', open ? 'rotate-180' : ''].join(' ')} />
        </div>
      </button>

      {open && (
        <div className="absolute left-0 z-50 mt-1 w-full min-w-44 rounded-lg border border-slate-200 bg-white shadow-lg max-h-56 overflow-y-auto py-1">
          {tree.length === 0
            ? <p className="px-3 py-2 text-xs italic text-slate-400">{emptyText ?? 'No options available.'}</p>
            : tree.map((node) => (
                <TreeNode
                  key={node.id}
                  node={node}
                  labelField={labelField}
                  selectedValue={value}
                  onSelect={handleSelect}
                  depth={0}
                />
              ))
          }
        </div>
      )}
    </div>
  )
}
