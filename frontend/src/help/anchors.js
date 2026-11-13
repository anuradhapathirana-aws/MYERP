/** The anchors a topic shows in "On this page", in display order. */
export function topicAnchors(t) {
  return [
    t.flow && { id: 'diagram', label: 'Diagram' },
    t.before?.length && { id: 'before', label: 'Before you start' },
    (t.steps?.length || t.sketch) && { id: 'steps', label: 'Step by step' },
    t.example && { id: 'example', label: 'Example' },
    t.howItWorks?.length && { id: 'how', label: 'How it works' },
    t.statuses && { id: 'statuses', label: 'Statuses' },
    t.tables?.length && { id: 'tables', label: 'Reference' },
    t.rules?.length && { id: 'rules', label: 'Rules & limits' },
    t.tips?.length && { id: 'tips', label: 'Tips' },
    t.faq?.length && { id: 'faq', label: 'Questions' },
  ].filter(Boolean)
}
