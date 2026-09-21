/**
 * Drives the one-real-input-under-N-boxes pattern.
 *
 * The single field is what makes iOS/Android and password-manager one-time-code
 * autofill work. Six inputs break all of them, and give a screen reader six
 * unlabelled fields to announce.
 */
export function mountOtpInput(root, { onComplete } = {}) {
  const input = root.querySelector('[data-n2f-otp] .n2f-otp-input, .n2f-otp-input')
  const group = root.querySelector('[data-n2f-otp]') ?? root
  const boxes = Array.from(group.querySelectorAll('.n2f-otp-box'))
  const length = Number(group.dataset.length || boxes.length || 6)

  if (!input) return null

  let armed = true

  const sanitize = (value) => (value || '').replace(/\D/g, '').slice(0, length)

  const paint = () => {
    const value = input.value
    boxes.forEach((box, index) => {
      box.textContent = value[index] ?? ''
      box.dataset.filled = String(index < value.length)
      box.dataset.active = String(
        group.dataset.focused === 'true' &&
          index === Math.min(value.length, length - 1) &&
          value.length < length,
      )
    })
  }

  const clear = () => {
    input.value = ''
    paint()
  }

  const markInvalid = () => {
    group.dataset.state = 'invalid'
    clear()
    // Re-arm only once the value changes, so a failed auto-submit does not
    // immediately fire again on the same input.
    armed = false
    input.focus()
  }

  const markValid = () => {
    group.dataset.state = 'valid'
  }

  // The caret is drawn on a decorative box while the real input sits invisible
  // on top. Without tracking focus the boxes kept blinking after a click
  // elsewhere on the page — a field advertising that it is ready to take keys
  // that were going nowhere.
  const setFocused = (focused) => {
    group.dataset.focused = String(focused)
    paint()
  }

  input.addEventListener('focus', () => setFocused(true))
  input.addEventListener('blur', () => setFocused(false))
  setFocused(document.activeElement === input)

  // Anywhere on the group is a hit target for the field it decorates.
  group.addEventListener('pointerdown', (event) => {
    if (event.target === input) return

    event.preventDefault()
    input.focus()
  })

  input.addEventListener('input', () => {
    const before = input.value
    input.value = sanitize(before)

    if (input.value !== before) armed = true
    if (group.dataset.state) delete group.dataset.state

    armed = true
    paint()

    if (input.value.length === length && armed && typeof onComplete === 'function') {
      armed = false
      // One frame, so the final digit is painted before the request starts.
      requestAnimationFrame(() => onComplete(input.value))
    }
  })

  // Paste anywhere in the field: "123 456" and "Your code is 123456" both work.
  input.addEventListener('paste', (event) => {
    event.preventDefault()
    const text = (event.clipboardData || window.clipboardData)?.getData('text') ?? ''
    input.value = sanitize(text)
    armed = true
    paint()
    input.dispatchEvent(new Event('input', { bubbles: true }))
  })

  paint()

  return {
    input,
    group,
    clear,
    markInvalid,
    markValid,
    paint,
    get value() {
      return input.value
    },
  }
}
