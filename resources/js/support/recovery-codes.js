/**
 * Copy, download and print for a set of recovery codes.
 *
 * Framework-agnostic on purpose: the same three affordances are needed inside
 * Nova's SPA and on the pre-authentication enrollment screen, and the second of
 * those has no Vue to import.
 */

export const asText = (codes) => codes.join('\n')

export const copyCodes = (codes) => navigator.clipboard.writeText(asText(codes))

/**
 * A Blob URL rather than a data: URI — a data URI of a dozen codes is fine, but
 * the limit is browser-specific and there is no reason to sit near it.
 */
export function downloadCodes(codes, appName) {
  const stamp = new Date().toISOString().slice(0, 10)
  const slug = (appName || 'app').toLowerCase().replace(/[^a-z0-9]+/g, '-')
  const blob = new Blob([asText(codes)], { type: 'text/plain;charset=utf-8' })
  const href = URL.createObjectURL(blob)

  const link = document.createElement('a')
  link.href = href
  link.download = `${slug}-recovery-codes-${stamp}.txt`
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
  URL.revokeObjectURL(href)
}

export function printCodes(codes, { appName, account, title, note }) {
  const frame = document.createElement('iframe')
  frame.style.position = 'fixed'
  frame.style.right = '0'
  frame.style.bottom = '0'
  frame.style.width = '0'
  frame.style.height = '0'
  frame.style.border = '0'
  document.body.appendChild(frame)

  const doc = frame.contentDocument

  // Built as nodes, not interpolated markup: the application name and the
  // account come from data this package did not write.
  doc.open()
  doc.write(
    '<!doctype html><html><head><style>' +
      'body { font-family: ui-monospace, Menlo, monospace; padding: 2rem; color: #111; }' +
      'h1 { font-family: system-ui, sans-serif; font-size: 1rem; margin: 0 0 .25rem; }' +
      'p { font-family: system-ui, sans-serif; font-size: .75rem; color: #555; margin: 0 0 1.5rem; }' +
      'ol { columns: 2; gap: 2rem; font-size: .875rem; line-height: 1.9; }' +
      '</style></head><body></body></html>',
  )
  doc.close()

  doc.title = appName

  const heading = doc.createElement('h1')
  heading.textContent = title ?? appName

  const meta = doc.createElement('p')
  meta.textContent = [account, new Date().toLocaleDateString(), note].filter(Boolean).join(' · ')

  const list = doc.createElement('ol')
  codes.forEach((code) => {
    const item = doc.createElement('li')
    item.textContent = code
    list.append(item)
  })

  doc.body.append(heading, meta, list)

  frame.contentWindow.focus()
  frame.contentWindow.print()
  setTimeout(() => document.body.removeChild(frame), 1000)
}
