/**
 * Opens a PDF Blob in its own tab and asks it to print.
 *
 * Previously this drove printing through a hidden/off-screen iframe and
 * `contentWindow.print()`. That path is unreliable for embedded PDFs — margin
 * handling and pagination for content synthesized this way differ across
 * Chrome versions, and in practice content near the page edges (like an
 * invoice's signature footer) could be missing from the print preview even
 * once the iframe itself was correctly laid out. Opening the PDF in a normal
 * tab lets the browser's own PDF viewer — the same one used for any other PDF
 * link on the web — own pagination and printing, which is the well-tested path.
 *
 * If the auto-print call is blocked (some browsers restrict scripted print()
 * on a PDF viewer tab), the tab still opens with the PDF visible, and the user
 * can print from its native toolbar — a normal, reliable fallback.
 */
export function printPdfBlob(blob) {
  const url = URL.createObjectURL(blob)
  const win = window.open(url, '_blank')

  if (win) {
    win.addEventListener('load', () => {
      try {
        win.print()
      } catch {
        // Auto-print unavailable — the user can still print from the PDF viewer's toolbar.
      }
    })
  }

  // Give the tab time to load and the print dialog to open before revoking the URL.
  setTimeout(() => URL.revokeObjectURL(url), 60000)
}
