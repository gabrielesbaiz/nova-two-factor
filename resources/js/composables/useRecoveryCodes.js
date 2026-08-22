import { ref } from 'vue'

/**
 * Copy, download and print for a set of recovery codes.
 */
export function useRecoveryCodes() {
  const copied = ref(false)

  const asText = codes => codes.join('\n')

  const copy = async codes => {
    await navigator.clipboard.writeText(asText(codes))
    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  }

  /**
   * A Blob URL rather than a data: URI — a data URI of a dozen codes is fine,
   * but the limit is browser-specific and there is no reason to sit near it.
   */
  const download = (codes, appName) => {
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

  const print = (codes, { appName, account }) => {
    const frame = document.createElement('iframe')
    frame.style.position = 'fixed'
    frame.style.right = '0'
    frame.style.bottom = '0'
    frame.style.width = '0'
    frame.style.height = '0'
    frame.style.border = '0'
    document.body.appendChild(frame)

    const doc = frame.contentDocument
    doc.open()
    doc.write(`<!doctype html><html><head><title>${appName}</title><style>
      body { font-family: ui-monospace, Menlo, monospace; padding: 2rem; color: #111; }
      h1 { font-family: system-ui, sans-serif; font-size: 1rem; margin: 0 0 .25rem; }
      p { font-family: system-ui, sans-serif; font-size: .75rem; color: #555; margin: 0 0 1.5rem; }
      ol { columns: 2; gap: 2rem; font-size: .875rem; line-height: 1.9; }
    </style></head><body>
      <h1>${appName} &mdash; recovery codes</h1>
      <p>${account} &middot; generated ${new Date().toLocaleDateString()} &middot; each code works once</p>
      <ol>${codes.map(code => `<li>${code}</li>`).join('')}</ol>
    </body></html>`)
    doc.close()

    frame.contentWindow.focus()
    frame.contentWindow.print()
    setTimeout(() => document.body.removeChild(frame), 1000)
  }

  return { copied, copy, download, print }
}
