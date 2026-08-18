/** Slate palette matching the web system's UI guidelines. */
export const C = {
  bg:          '#f1f5f9',
  card:        '#ffffff',
  border:      '#e2e8f0',
  text:        '#0f172a',
  sub:         '#64748b',
  faint:       '#94a3b8',
  primary:     '#1d4ed8',
  primarySoft: '#dbeafe',
  danger:      '#dc2626',
  dangerSoft:  '#fee2e2',
  green:       '#16a34a',
  greenSoft:   '#dcfce7',
  amber:       '#d97706',
  amberSoft:   '#fef3c7',
  slateSoft:   '#f8fafc',
}

/** Badge colors per document status (SO and DO share the vocabulary). */
export const STATUS_COLORS = {
  draft:     { bg: C.amberSoft, fg: C.amber },
  confirmed: { bg: C.greenSoft, fg: C.green },
  completed: { bg: C.primarySoft, fg: C.primary },
  cancelled: { bg: C.dangerSoft, fg: C.danger },
}
