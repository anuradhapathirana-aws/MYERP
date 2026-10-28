import Swal from 'sweetalert2'

const Toast = Swal.mixin({
  toast: true,
  position: 'bottom-end',
  showConfirmButton: false,
  timer: 3000,
  timerProgressBar: true,
  showClass: { popup: 'swal2-toast-slide-in' },
  hideClass: { popup: 'swal2-toast-slide-out' },
  customClass: { popup: 'swal2-toast-mini' },
  didOpen: (toast) => {
    toast.onmouseenter = Swal.stopTimer
    toast.onmouseleave = Swal.resumeTimer
  },
})

export function showSuccess(message = 'Operation completed successfully.') {
  Toast.fire({ icon: 'success', title: message })
}

export function showError(message = 'Something went wrong. Please try again.') {
  Toast.fire({ icon: 'error', title: message, timer: 5000 })
}

export function showWarning(message) {
  Toast.fire({ icon: 'warning', title: message, timer: 4500 })
}

export async function confirmDelete(name) {
  const result = await Swal.fire({
    title: 'Delete Record?',
    html: `Are you sure you want to delete <strong>"${name}"</strong>?<br><span class="swal2-sub-text">This action cannot be undone.</span>`,
    icon: 'warning',
    showCancelButton: true,
    confirmButtonColor: '#ef4444',
    cancelButtonColor: '#64748b',
    confirmButtonText: '<span>Yes, Delete It</span>',
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    focusCancel: true,
    width: '360px',
    customClass: {
      popup:         'swal2-styled-popup',
      title:         'swal2-styled-title',
      htmlContainer: 'swal2-styled-html',
      confirmButton: 'swal2-styled-confirm-btn swal2-danger-btn',
      cancelButton:  'swal2-styled-cancel-btn',
      icon:          'swal2-styled-icon',
    },
  })
  return result.isConfirmed
}

export async function confirmAction({ title, message, confirmText = 'Yes, Confirm', cancelText = 'Cancel', confirmColor = '#16a34a', icon = 'question' }) {
  const result = await Swal.fire({
    title,
    html: `<span>${message}</span>`,
    icon,
    showCancelButton: true,
    confirmButtonColor: confirmColor,
    cancelButtonColor: '#64748b',
    confirmButtonText: `<span>${confirmText}</span>`,
    cancelButtonText: cancelText,
    reverseButtons: true,
    width: '360px',
    customClass: {
      popup:         'swal2-styled-popup',
      title:         'swal2-styled-title',
      htmlContainer: 'swal2-styled-html',
      confirmButton: 'swal2-styled-confirm-btn',
      cancelButton:  'swal2-styled-cancel-btn',
      icon:          'swal2-styled-icon',
    },
  })
  return result.isConfirmed
}

/**
 * Three-way prompt: Confirm / Deny / Close, each mapped to a distinct outcome
 * rather than collapsing to a plain yes/no. Returns 'confirm' | 'deny' | 'cancel'.
 * "Cancel" has no button of its own — it's the top-right ✕ close icon — so the
 * only way out is Yes, No, or ✕; outside-click and a bare Esc keypress do
 * nothing (Esc is instead rebound to trigger No, per keyboard shortcuts below).
 */
export async function confirmTriState({
  title, message,
  confirmText = 'Yes', confirmColor = '#16a34a',
  denyText = 'No', denyColor = '#64748b',
  closeNote = 'Save as draft',
  icon = 'question',
}) {
  const result = await Swal.fire({
    title,
    html: `<span>${message}</span>`,
    icon,
    showDenyButton: true,
    showCloseButton: true,
    allowOutsideClick: false,
    allowEscapeKey: false,
    confirmButtonColor: confirmColor,
    denyButtonColor: denyColor,
    confirmButtonText: `<span class="swal2-btn-label">${confirmText}<kbd class="swal2-kbd">Enter</kbd></span>`,
    denyButtonText: `<span class="swal2-btn-label">${denyText}<kbd class="swal2-kbd">Esc</kbd></span>`,
    reverseButtons: true,
    width: '400px',
    customClass: {
      popup:         'swal2-styled-popup',
      title:         'swal2-styled-title',
      htmlContainer: 'swal2-styled-html',
      confirmButton: 'swal2-styled-confirm-btn',
      denyButton:    'swal2-styled-confirm-btn',
      closeButton:   'swal2-styled-close-btn',
      icon:          'swal2-styled-icon',
    },
    didOpen: (popup) => {
      const closeBtn = popup.querySelector('.swal2-close')
      if (closeBtn) {
        const note = document.createElement('span')
        note.className = 'swal2-close-note'
        note.textContent = closeNote
        closeBtn.insertAdjacentElement('beforebegin', note)
      }
      popup.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
          e.preventDefault()
          Swal.clickDeny()
        }
      })
    },
  })
  if (result.isConfirmed) return 'confirm'
  if (result.isDenied) return 'deny'
  return 'cancel'
}

/**
 * Void prompt for accounting documents: a reason AND the date the reversal is
 * posted on. Dates on or before the lock date, before the document's own date,
 * or in the future are blocked here; the server checks the same rules again.
 * Returns { reason, reversal_date } or null.
 */
export async function confirmWithReasonAndDate({
  title,
  documentDate,
  lockDate = null,
  confirmText = 'Yes, Void',
  confirmColor = '#ef4444',
}) {
  const today = new Date().toLocaleDateString('en-CA')
  const dayAfter = (d) => {
    const x = new Date(`${d}T00:00:00`)
    x.setDate(x.getDate() + 1)
    return x.toLocaleDateString('en-CA')
  }
  const minDate = [documentDate, lockDate ? dayAfter(lockDate) : null].filter(Boolean).sort().pop() || ''
  // Original date if its period is still open, otherwise the first open day.
  const defaultDate = minDate && minDate <= today ? minDate : today
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])

  const result = await Swal.fire({
    title,
    html: `
      <div style="text-align:left">
        <label class="swal2-input-label" style="margin:0 0 4px;display:block;font-size:12px;font-weight:600">Reason <span style="color:#ef4444">*</span></label>
        <textarea id="swal-void-reason" class="swal2-textarea swal2-styled-textarea" style="margin:0 0 8px;width:100%" placeholder="e.g. Entered against the wrong supplier" maxlength="500"></textarea>
        <label class="swal2-input-label" style="margin:0 0 4px;display:block;font-size:12px;font-weight:600">Reversal Date <span style="color:#ef4444">*</span></label>
        <input id="swal-void-date" type="date" class="swal2-input" style="margin:0;width:100%;height:34px;font-size:13px" value="${esc(defaultDate)}" min="${esc(minDate)}" max="${esc(today)}" />
        ${lockDate ? `<p style="margin:4px 0 0;font-size:11px;color:#b45309">Period closed up to ${esc(lockDate)}.</p>` : ''}
      </div>`,
    showCancelButton: true,
    focusConfirm: false,
    confirmButtonColor: confirmColor,
    cancelButtonColor: '#64748b',
    confirmButtonText: `<span>${confirmText}</span>`,
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    width: '380px',
    customClass: {
      popup:         'swal2-styled-popup',
      title:         'swal2-styled-title',
      htmlContainer: 'swal2-styled-html',
      confirmButton: 'swal2-styled-confirm-btn swal2-danger-btn',
      cancelButton:  'swal2-styled-cancel-btn',
    },
    preConfirm: () => {
      const reason = document.getElementById('swal-void-reason')?.value?.trim() ?? ''
      const date = document.getElementById('swal-void-date')?.value ?? ''
      if (reason.length < 3) return Swal.showValidationMessage('Please give a reason (at least 3 characters).')
      if (!date) return Swal.showValidationMessage('Choose the reversal date.')
      if (date > today) return Swal.showValidationMessage('The reversal date cannot be in the future.')
      if (documentDate && date < documentDate) return Swal.showValidationMessage(`The reversal date cannot be before ${documentDate}.`)
      if (lockDate && date <= lockDate) return Swal.showValidationMessage(`Period closed up to ${lockDate}. Choose a later date.`)
      return { reason, reversal_date: date }
    },
  })
  return result.isConfirmed ? result.value : null
}

export async function confirmWithReason({ title, inputLabel, inputPlaceholder, confirmText = 'Confirm', confirmColor = '#ef4444' }) {
  const result = await Swal.fire({
    title,
    input: 'textarea',
    inputLabel,
    inputPlaceholder,
    showCancelButton: true,
    confirmButtonColor: confirmColor,
    cancelButtonColor: '#64748b',
    confirmButtonText: `<span>${confirmText}</span>`,
    cancelButtonText: 'Cancel',
    reverseButtons: true,
    width: '360px',
    customClass: {
      popup:         'swal2-styled-popup',
      title:         'swal2-styled-title',
      htmlContainer: 'swal2-styled-html',
      confirmButton: 'swal2-styled-confirm-btn swal2-danger-btn',
      cancelButton:  'swal2-styled-cancel-btn',
      input:         'swal2-styled-textarea',
    },
    preConfirm: (value) => {
      if (!value?.trim()) Swal.showValidationMessage('This field is required.')
      return value
    },
  })
  return result.isConfirmed ? result.value : null
}
