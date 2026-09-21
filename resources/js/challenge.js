/**
 * The pre-authentication bundle: challenge, step-up, enforcement countdown.
 *
 * Deliberately dependency-free — no Vue, no Inertia, no axios. These pages are
 * always a cold load and they sit on the login path, so they get one request and
 * a few kilobytes. Everything here is progressive enhancement: with the script
 * absent or broken, the form still posts and still works.
 */
import { mountOtpInput } from './support/otp'
import { copyCodes, downloadCodes, printCodes } from './support/recovery-codes'
import { __ } from './support/translate'
import {
  createCredential,
  describeError,
  getCredential,
  isConditionalMediationAvailable,
  isPlatformAuthenticatorAvailable,
  isSupported,
} from './support/webauthn'

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? ''

const post = async (url, body) => {
  const response = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrf(),
      'X-Requested-With': 'XMLHttpRequest',
    },
    credentials: 'same-origin',
    body: JSON.stringify(body),
  })

  let payload = null
  try {
    payload = await response.json()
  } catch {
    payload = null
  }

  return { response, payload }
}

const firstError = (payload) => {
  if (!payload) return null
  if (payload.errors) {
    const first = Object.values(payload.errors)[0]
    return Array.isArray(first) ? first[0] : first
  }
  return payload.message ?? null
}

function mountChallenge(form) {
  const prepareUrl = form.dataset.prepareUrl
  const isStepUp = form.dataset.stepUp === '1'

  const errorNode = form.querySelector('[data-n2f-error]')
  const statusNode = form.querySelector('[data-n2f-status]')
  const submit = form.querySelector('[data-n2f-submit]')
  const methodIdInput = form.querySelector('[data-n2f-method-id]')
  const codeField = form.querySelector('[data-n2f-code-field]')
  const codeHint = form.querySelector('[data-n2f-code-hint]')
  const recoveryField = form.querySelector('[data-n2f-recovery-field]')
  const passkeyBlock = form.querySelector('[data-n2f-passkey]')
  const passkeyTrigger = form.querySelector('[data-n2f-passkey-trigger]')
  const resendButton = form.querySelector('[data-n2f-resend]')
  const heading = form.querySelector('[data-n2f-heading]')
  const chooserToggle = form.querySelector('[data-n2f-chooser-toggle]')
  const chooser = form.querySelector('[data-n2f-chooser]')

  let busy = false
  let lockTimer = null

  const say = (message) => {
    if (statusNode) statusNode.textContent = message ?? ''
  }

  const fail = (message) => {
    if (!errorNode) return
    errorNode.textContent = message ?? ''
    errorNode.classList.toggle('hidden', !message)

    // An error ends the attempt; leaving "verifying…" beside it is a lie.
    if (message) say('')
  }

  const otp = mountOtpInput(form, { onComplete: () => attempt() })

  let resendTimer = null

  /**
   * Offer another code once the server would actually send one.
   *
   * The cooldown is the server's, read from the prepare response rather than
   * assumed here; asking early only earns a refusal the user cannot act on.
   */
  const armResend = (seconds) => {
    if (!resendButton) return

    let remaining = Math.max(0, Number(seconds) || 0)

    clearInterval(resendTimer)
    resendButton.classList.remove('hidden')

    const tick = () => {
      if (remaining <= 0) {
        clearInterval(resendTimer)
        resendButton.disabled = false
        resendButton.textContent = form.dataset.labelResend ?? ''
        return
      }

      const minutes = Math.floor(remaining / 60)
      const secs = String(remaining % 60).padStart(2, '0')

      resendButton.disabled = true
      resendButton.textContent = fill(form.dataset.labelResendWait, { time: `${minutes}:${secs}` })
      remaining -= 1
    }

    tick()
    resendTimer = setInterval(tick, 1000)
  }

  // `data-heading-recovery_code` reads back as `dataset.headingRecovery_code`:
  // dataset camel-cases hyphens, never underscores. Munging the name in code
  // got that wrong, so the page stayed titled "enter the code we emailed you"
  // above a recovery-code field.
  const HEADINGS = {
    totp: 'headingTotp',
    email: 'headingEmail',
    webauthn: 'headingWebauthn',
    recovery_code: 'headingRecovery_code',
  }

  function retitle(methodType) {
    const text = heading?.dataset[HEADINGS[methodType] ?? '']

    if (text) heading.textContent = text
  }

  /**
   * The list offers everything except what is in use.
   *
   * Filtered server-side instead, it could only lead *away* from the default:
   * a user who switched to a recovery code was left with a chooser that no
   * longer contained the email factor they had come from.
   */
  function refreshChooser(methodType) {
    const rows = Array.from(form.querySelectorAll('[data-n2f-choose]'))
    const currentId = methodIdInput?.value ?? ''

    let offered = 0

    rows.forEach((row) => {
      const isRecoveryRow = row.dataset.methodType === 'recovery_code'

      const inUse =
        methodType === 'recovery_code'
          ? isRecoveryRow
          : !isRecoveryRow && row.dataset.methodId === currentId

      row.classList.toggle('hidden', inUse)

      if (!inUse) offered += 1
    })

    // One label for the control whatever it happens to list: a recovery code
    // is another method, and naming it specifically only made the button
    // change under the user between one visit and the next.
    chooserToggle?.classList.toggle('hidden', offered === 0)
  }

  /**
   * Keep hidden fields out of the browser's own validation.
   *
   * A declaration rather than a `const` arrow: it is called at mount, above
   * this point, and a `const` there is in the temporal dead zone — which shows
   * up as "Cannot access 'O' before initialization" in the minified bundle and
   * takes the whole challenge script down with it.
   *
   * The code input is `required`, which is right when it is the thing being
   * asked for — and fatal when it is not: switching to a recovery code left a
   * required field inside a `display:none` container, and submitting answered
   * "An invalid form control with name='code' is not focusable", with no error
   * on screen and nothing sent. Disabled fields are skipped by validation and
   * left out of the payload, which is exactly the behaviour wanted.
   */
  function setFieldActive(field, active) {
    field?.querySelectorAll('input').forEach((input) => {
      input.disabled = !active
    })
  }

  const hideResend = () => {
    clearInterval(resendTimer)
    resendButton?.classList.add('hidden')
  }

  /** Report what prepare() did, and offer the next send when it is due. */
  const reportPrepared = (payload) => {
    if (!payload) return

    if (payload.sent === false && payload.destination_hint) {
      say(__('A code was already sent to :destination.', { destination: payload.destination_hint }))
    } else if (payload.destination_hint) {
      say(__('We sent a code to :destination.', { destination: payload.destination_hint }))
    }

    if (payload.destination_hint) armResend(payload.retry_after ?? 0)
  }

  /** Drive the lockout countdown from Retry-After, never from a guess. */
  const lockOut = (seconds) => {
    if (!submit || submit.classList.contains('hidden')) return
    let remaining = Number(seconds) || 0
    const label = submit.textContent

    submit.setAttribute('aria-disabled', 'true')
    if (otp) otp.input.readOnly = true

    clearInterval(lockTimer)
    lockTimer = setInterval(() => {
      if (remaining <= 0) {
        clearInterval(lockTimer)
        submit.removeAttribute('aria-disabled')
        submit.textContent = label
        if (otp) {
          otp.input.readOnly = false
          otp.input.focus()
        }
        say(__('You can try again now.'))
        return
      }

      const minutes = Math.floor(remaining / 60)
      const secs = String(remaining % 60).padStart(2, '0')
      submit.textContent = __('Try again in :time', { time: `${minutes}:${secs}` })
      remaining -= 1
    }, 1000)
  }

  const currentMethodType = () => form.dataset.methodType || ''

  async function prepare(methodId, methodType) {
    if (!prepareUrl || methodType === 'recovery_code') return null

    const { payload } = await post(prepareUrl, { method_id: methodId })
    return payload
  }

  async function attempt(extra = {}) {
    if (busy) return
    busy = true
    fail(null)
    say(__('Verifying…'))

    const body = {
      scope: form.querySelector('[name="scope"]')?.value,
      intended: form.querySelector('[name="intended"]')?.value,
      method_id: methodIdInput?.value ? Number(methodIdInput.value) : null,
      trust_device: form.querySelector('[name="trust_device"]')?.checked ?? false,
      ...extra,
    }

    if (!extra.credential) {
      const recovery = recoveryField?.querySelector('input')
      if (recovery && !recoveryField.classList.contains('hidden') && recovery.value) {
        body.recovery_code = recovery.value
      } else if (otp) {
        body.code = otp.value
      }
    }

    const { response, payload } = await post(form.action, body)
    busy = false

    if (response.status === 429) {
      const retryAfter = response.headers.get('Retry-After')
      otp?.markInvalid()
      fail(firstError(payload) ?? __('Too many attempts.'))
      lockOut(retryAfter ?? 60)
      return
    }

    if (!response.ok) {
      otp?.markInvalid()
      const message = firstError(payload) ?? __('That did not work. Try again.')
      fail(message)
      say(message)
      return
    }

    otp?.markValid()
    say(__('Verified.'))

    // The same on the challenge: between "verified" and the page changing, the
    // submit button and the code field were still live.
    busy = true
    clearInterval(lockTimer)
    clearInterval(resendTimer)
    if (resendButton) resendButton.disabled = true
    if (otp) otp.input.readOnly = true
    if (submit) {
      submit.disabled = true
      submit.setAttribute('aria-disabled', 'true')
    }

    // A full navigation, not a client-side route change: the session's
    // verification state changed on the server.
    window.location.assign(payload?.redirect || '/')
  }

  form.addEventListener('submit', (event) => {
    event.preventDefault()
    attempt()
  })

  chooserToggle?.addEventListener('click', () => {
    const open = chooser?.classList.toggle('hidden') === false
    chooserToggle.setAttribute('aria-expanded', String(open))
  })

  form.querySelectorAll('[data-n2f-choose]').forEach((button) => {
    button.addEventListener('click', async () => {
      const methodId = button.dataset.methodId
      const methodType = button.dataset.methodType

      form.dataset.methodType = methodType
      if (methodIdInput && methodId) methodIdInput.value = methodId

      form
        .querySelectorAll('[data-n2f-choose]')
        .forEach((other) => other.removeAttribute('aria-current'))
      button.setAttribute('aria-current', 'true')

      const usingRecovery = methodType === 'recovery_code'
      const usingPasskey = methodType === 'webauthn'

      codeField?.classList.toggle('hidden', usingRecovery || usingPasskey)
      recoveryField?.classList.toggle('hidden', !usingRecovery)
      passkeyBlock?.classList.toggle('hidden', !usingPasskey)

      setFieldActive(codeField, !usingRecovery && !usingPasskey)
      setFieldActive(recoveryField, usingRecovery)

      // The passkey ceremony is the submission; the form button has nothing to
      // post while it is the active factor.
      submit?.classList.toggle('hidden', usingPasskey)

      // The page is now about the factor that was picked: retitle it, refresh
      // what the list offers, and close it — leaving it open pushed the field
      // the user has to type into below the options they chose from.
      retitle(methodType)
      refreshChooser(methodType)

      chooser?.classList.add('hidden')
      chooserToggle?.setAttribute('aria-expanded', 'false')

      // An emailed code does not rotate every 30 seconds, and saying it does
      // invites the user to sit waiting for a replacement that never comes.
      if (codeHint) {
        const hint =
          codeHint.dataset[`hint${methodType.replace(/_(.)/g, (_, c) => c.toUpperCase())}`]
        if (hint) codeHint.textContent = hint
      }

      fail(null)
      otp?.clear()

      if (usingRecovery || usingPasskey) hideResend()

      if (usingRecovery) {
        recoveryField?.querySelector('input')?.focus()
        return
      }

      if (usingPasskey) return

      const payload = await prepare(methodId, methodType)

      reportPrepared(payload)

      otp?.input.focus()
    })
  })

  // A browser allows exactly one credential request at a time. Conditional
  // mediation opens one as the page loads and holds it, waiting for the autofill
  // picker, so clicking "use your passkey" while it is pending fails with
  // `OperationError: A request is already pending.`
  //
  // The slot is claimed synchronously, before the `prepare()` round-trip: a
  // guard set after an await is not a guard at all — the click and the ambient
  // ceremony both passed it and then raced into the API.
  let current = null

  async function runPasskey({ conditional = false, retried = false } = {}) {
    if (!isSupported()) return false

    const previous = current
    const controller = new AbortController()
    const slot = { controller, ceremony: null }

    current = slot

    if (previous) {
      previous.controller.abort()

      // Wait for the browser to actually tear the old request down. Starting
      // the next one in the same tick races it, which is the same error again.
      await Promise.resolve(previous.ceremony).catch(() => {})
    }

    const methodId = methodIdInput?.value ? Number(methodIdInput.value) : null
    const payload = await prepare(methodId, 'webauthn')

    // Superseded while we were fetching options, or already cancelled.
    if (current !== slot || controller.signal.aborted) return false

    if (!payload?.public_key) {
      current = null
      return false
    }

    slot.ceremony = getCredential(payload.public_key, { conditional, signal: controller.signal })

    try {
      const credential = await slot.ceremony
      if (!credential) return false

      await attempt({ credential })
      return true
    } catch (error) {
      // A ceremony we cancelled on purpose is not a failure to report.
      if (controller.signal.aborted) return false

      // Something outside this page still holds a credential request — a
      // conditional ceremony left pending by the previous page, most often a
      // back/forward restore. It resolves itself once that page is discarded,
      // so give it one turn and try again rather than reporting a wall.
      if (error?.name === 'OperationError' && !retried) {
        current = null
        await new Promise((resolve) => setTimeout(resolve, 400))

        return runPasskey({ conditional, retried: true })
      }

      // The ambient ceremony is an offer, not an action: the user did not ask
      // for it and there is nothing for them to do about it failing. The code
      // field and the button both still work.
      if (conditional) {
        console.debug('[nova-two-factor] conditional passkey mediation unavailable', error)
        return false
      }

      const message = describeError(error)
      if (message) fail(message)
      return false
    } finally {
      if (current === slot) current = null
    }
  }

  resendButton?.addEventListener('click', async () => {
    if (busy) return

    resendButton.disabled = true
    fail(null)
    otp?.clear()

    const methodId = methodIdInput?.value ? Number(methodIdInput.value) : null
    const payload = await prepare(methodId, currentMethodType())

    if (payload?.sent === false) {
      reportPrepared(payload)
    } else if (payload) {
      say(form.dataset.labelResent ?? '')
      armResend(payload.retry_after ?? 0)
    }

    otp?.input.focus()
  })

  // Initial state, set down here rather than at the top of the mount: these
  // helpers read consts declared above this point, and calling them earlier put
  // those consts in the temporal dead zone — which takes the whole bundle down.
  setFieldActive(codeField, !codeField?.classList.contains('hidden'))
  setFieldActive(recoveryField, !recoveryField?.classList.contains('hidden'))
  refreshChooser(currentMethodType())

  passkeyTrigger?.addEventListener('click', () => runPasskey())

  // The default factor has to be prepared on arrival, not only when the user
  // picks something from the chooser. Without this the page said "enter the
  // code we emailed you" above a field no code had been sent for — and the
  // only way to get one was to open the chooser and re-pick the method you
  // were already on.
  ;(async () => {
    if (currentMethodType() !== 'email') return

    const methodId = methodIdInput?.value ? Number(methodIdInput.value) : null
    reportPrepared(await prepare(methodId, 'email'))
  })()

  ;(async () => {
    const passkeyMethod = Array.from(form.querySelectorAll('[data-n2f-choose]')).find(
      (button) => button.dataset.methodType === 'webauthn',
    )

    if (!passkeyMethod || !isSupported()) {
      // No passkey, or a browser that cannot use one: hide the affordance rather
      // than offering a button that cannot work.
      passkeyBlock?.classList.add('hidden')
      otp?.input.focus()
      return
    }

    // Only when the passkey is the factor in play. Revealing it unconditionally
    // put "Usa la tua passkey" between an emailed code field and the button
    // that submits it — two primary actions for one screen, and neither of them
    // obviously the one being asked for.
    if (currentMethodType() === 'webauthn' && (await isPlatformAuthenticatorAvailable())) {
      passkeyBlock?.classList.remove('hidden')
    }

    // Conditional mediation offers passkey sign-in alongside the code field, so
    // a passkey user resolves the whole screen with one touch and no typing.
    // Never on a step-up: that must be a deliberate act.
    if (!isStepUp && (await isConditionalMediationAvailable())) {
      runPasskey({ conditional: true })
    }
  })()
}

/** Fill `:name` placeholders in a server-translated template. */
function fill(template, replacements) {
  return Object.entries(replacements).reduce(
    (carry, [key, value]) => carry.replaceAll(`:${key}`, String(value)),
    template ?? '',
  )
}

/** Grace-period countdown on the enforcement page. */
function mountCountdown(node) {
  const deadline = new Date(node.dataset.deadline)
  if (Number.isNaN(deadline.getTime())) return

  const tick = () => {
    const remaining = Math.max(0, Math.floor((deadline - Date.now()) / 1000))
    const days = Math.floor(remaining / 86400)
    const hours = Math.floor((remaining % 86400) / 3600)
    const minutes = Math.floor((remaining % 3600) / 60)

    if (remaining <= 0) {
      node.textContent = node.dataset.labelExpired ?? ''
      node.classList.add('text-red-500')
      return
    }

    node.textContent =
      days > 0
        ? fill(node.dataset.labelDays, { days, hours })
        : fill(node.dataset.labelTime, {
            time: `${hours}:${String(minutes).padStart(2, '0')}`,
          })

    if (remaining < 3600) node.classList.add('text-red-500')
  }

  tick()
  setInterval(tick, 30000)
}

/**
 * In-place enrollment on the mandatory-enrollment page.
 *
 * The method rows used to link to Nova's own user-security page. That page is
 * inside Nova's SPA and behind Nova's authorization middleware, so the one
 * screen built to work without Nova handed the user straight back to it — and a
 * user who cannot reach the SPA had no way to satisfy the requirement at all.
 *
 * Everything needed is already here: the enrollment endpoints are plain JSON,
 * and a passkey ceremony is a browser API call. The links remain real links, so
 * with no script the old path still works.
 */
function mountEnrollment(root) {
  const panel = document.querySelector('[data-n2f-enroll-panel]')
  if (!panel) return

  const storeUrl = root.dataset.storeUrl
  const confirmUrl = root.dataset.confirmUrl
  const doneUrl = root.dataset.doneUrl || '/'

  // Page-level: it survives the panel being hidden, and sits above the list.
  const errorNode = document.querySelector('[data-n2f-enroll-error]')
  const statusNode = panel.querySelector('[data-n2f-enroll-status]')
  const qrNode = panel.querySelector('[data-n2f-enroll-qr]')
  const secretNode = panel.querySelector('[data-n2f-enroll-secret]')
  const codeNode = panel.querySelector('[data-n2f-enroll-code]')
  const confirmButton = panel.querySelector('[data-n2f-enroll-confirm]')
  const resendButton = panel.querySelector('[data-n2f-enroll-resend]')
  const codesBlock = panel.querySelector('[data-n2f-enroll-codes]')
  const codesList = panel.querySelector('[data-n2f-enroll-codes-list]')
  const codesDone = panel.querySelector('[data-n2f-enroll-codes-done]')
  const codesActions = panel.querySelector('[data-n2f-enroll-codes-actions]')
  const pageActions = document.querySelector('[data-n2f-page-actions]')
  const cancelButton = panel.querySelector('[data-n2f-enroll-cancel]')

  let type = null
  let busy = false

  const show = (node, visible) => node?.classList.toggle('hidden', !visible)

  const say = (message) => {
    if (!statusNode) return

    statusNode.className = 'mb-4 text-sm text-gray-500 dark:text-gray-400'
    statusNode.textContent = message ?? ''
  }

  const fail = (message) => {
    if (!errorNode) return
    errorNode.textContent = message ?? ''
    show(errorNode, Boolean(message))

    // A failed step is not still in progress. Leaving "setting this up…" under
    // an error is how the enforcement screen ended up reading
    // "Too Many Attempts." above "Working…".
    if (message) say('')
  }

  // `onComplete` hands over the digits; dropping them posted `{type}` with no
  // code at all, so typing the sixth digit always answered "that code is not
  // correct" — with the right code, freshly issued, sitting in the field.
  const otp = mountOtpInput(panel, { onComplete: (code) => confirm({ code }) })

  const reset = () => {
    type = null
    busy = false
    fail(null)
    say('')
    if (qrNode) qrNode.innerHTML = ''
    if (secretNode) secretNode.textContent = ''
    show(qrNode, false)
    show(secretNode, false)
    show(codeNode, false)
    show(panel, false)
    show(root, true)
  }

  cancelButton?.addEventListener('click', reset)

  async function confirm(body = {}) {
    if (busy || !type) return

    // Belt and braces: whatever route got here, a code ceremony posts the field
    // the user typed into rather than an empty body.
    if (type !== 'webauthn' && !body.code) {
      body = { ...body, code: otp?.value ?? '' }
    }

    busy = true
    fail(null)

    const { response, payload } = await post(confirmUrl, { type, ...body })

    busy = false

    if (!response.ok) {
      otp?.markInvalid()
      fail(firstError(payload) ?? '')
      return
    }

    otp?.markValid()
    say(root.dataset.labelSaved ?? '')

    // Success is terminal: the navigation takes a moment, and for that moment
    // every control on screen was still live — a second confirm, or a resend
    // that would have replaced the code of a method already enrolled.
    settle()

    // Recovery codes come back exactly once, with the first confirmed factor.
    // Navigating past them is how a user ends up owning eight codes they have
    // never seen — and the server cannot show them again, they are hashed.
    if (Array.isArray(payload?.recovery_codes) && payload.recovery_codes.length && codesList) {
      showRecoveryCodes(payload.recovery_codes)

      return
    }

    window.location.assign(doneUrl)
  }

  /** The one and only showing. */
  function showRecoveryCodes(codes) {
    codesList.textContent = ''

    codes.forEach((code) => {
      const item = document.createElement('li')
      item.className = 'select-all text-gray-900 dark:text-gray-100'
      item.textContent = code
      codesList.append(item)
    })

    show(codeNode, false)
    show(qrNode, false)
    show(secretNode, false)
    show(cancelButton, false)
    show(resendButton, false)
    say('')
    show(codesBlock, true)

    codesDone?.focus()
  }

  /** Freeze the panel once there is nothing left to do on it. */
  function settle() {
    busy = true

    clearInterval(resendTimer)

    if (otp) otp.input.readOnly = true

    ;[confirmButton, resendButton, cancelButton].forEach((button) => {
      if (!button) return
      button.disabled = true
      button.setAttribute('aria-disabled', 'true')
    })

    root.querySelectorAll('[data-n2f-enroll-start]').forEach((link) => {
      link.setAttribute('aria-disabled', 'true')
    })
  }

  let lockTimer = null

  /**
   * Take the list away while the limiter is closed.
   *
   * Dimmed-but-present cards invite a click that cannot work, and a page of
   * greyed text reads as broken rather than paused. The choice disappears, the
   * countdown says when it returns — and it returns on its own, read from
   * `Retry-After` rather than guessed.
   */
  function lockList(seconds) {
    let remaining = Math.max(0, Number(seconds) || 0)

    clearInterval(lockTimer)

    if (remaining === 0) return

    show(root, false)

    // The countdown is the server's answer from a moment ago, not a fact about
    // now: an administrator clearing the lockout (or resetting the user) leaves
    // this tab counting down against a bucket that no longer exists. A reload
    // asks again — and if the session was revoked with the reset, it is also
    // what takes the user to the login screen instead of stranding them here.
    offerRecheck()

    const tick = () => {
      if (remaining <= 0) {
        clearInterval(lockTimer)
        show(root, true)
        fail(null)
        say(__('You can try again now.'))
        return
      }

      const minutes = Math.floor(remaining / 60)
      const secs = String(remaining % 60).padStart(2, '0')
      fail(__('Too many attempts. Try again in :time.', { time: `${minutes}:${secs}` }))
      remaining -= 1
    }

    tick()
    lockTimer = setInterval(tick, 1000)
  }

  /**
   * The address is the one word in that sentence the user has to read.
   *
   * Built as nodes rather than markup: the destination is server-masked but
   * still user-derived, and a template string dropped into `innerHTML` is how
   * an address becomes a script tag.
   */
  function sayWithDestination(lead, destination, note) {
    if (!statusNode) return

    statusNode.textContent = ''
    statusNode.className = 'mb-4 text-center'

    const line = (text, className) => {
      const el = document.createElement('p')
      el.textContent = text ?? ''
      el.className = className
      return el
    }

    const address = document.createElement('p')
    address.textContent = destination
    address.dir = 'ltr'
    address.className = 'n2f-destination'

    statusNode.append(
      line(lead, 'text-sm text-gray-500 dark:text-gray-400'),
      address,
      line(note, 'text-xs text-gray-400 dark:text-gray-500'),
    )
  }

  let resendTimer = null

  /**
   * The way out of "it never arrived".
   *
   * Counts down the server's own cooldown rather than guessing at it, and the
   * button only appears once there is something it can actually do — an
   * always-visible control that answers "not yet" is worse than none.
   */
  function armResend(seconds) {
    if (!resendButton) return

    let remaining = Math.max(0, Number(seconds) || 0)

    clearInterval(resendTimer)
    show(resendButton, true)

    const tick = () => {
      if (remaining <= 0) {
        clearInterval(resendTimer)
        resendButton.disabled = false
        resendButton.textContent = root.dataset.labelResend ?? ''
        return
      }

      const minutes = Math.floor(remaining / 60)
      const secs = String(remaining % 60).padStart(2, '0')

      resendButton.disabled = true
      resendButton.textContent = fill(root.dataset.labelResendWait, { time: `${minutes}:${secs}` })
      remaining -= 1
    }

    tick()
    resendTimer = setInterval(tick, 1000)
  }

  async function resend() {
    if (busy || !type) return

    busy = true
    resendButton.disabled = true
    fail(null)

    const { response, payload } = await post(storeUrl, { type, resend: true })

    busy = false

    if (!response.ok) {
      fail(firstError(payload) ?? '')
      armResend(payload?.retry_after ?? 60)
      return
    }

    otp?.clear?.()

    if (payload.sent === false) {
      sayWithDestination(
        root.dataset.labelAlreadySent,
        payload.destination_hint ?? '',
        root.dataset.labelAlreadyNote,
      )
    } else {
      sayWithDestination(
        root.dataset.labelSent,
        payload.destination_hint ?? '',
        root.dataset.labelResent,
      )
    }

    armResend(payload.resend_after ?? 0)
    otp?.input?.focus()
  }

  /** A way to ask the server again without waiting out a stale countdown. */
  function offerRecheck() {
    if (!statusNode) return

    statusNode.className = 'mb-4 text-sm text-gray-500 dark:text-gray-400'
    statusNode.textContent = ''

    const hint = document.createElement('span')
    hint.textContent = __('The list comes back by itself when the wait is over.') + ' '

    const button = document.createElement('button')
    button.type = 'button'
    button.className = 'underline font-bold'
    button.textContent = __('Check again now')
    button.addEventListener('click', () => window.location.reload())

    statusNode.append(hint, button)
  }

  /** Return to the list of factors, optionally with a reason above it. */
  function backToList({ error = null, restoreList = true } = {}) {
    busy = false
    type = null

    clearInterval(resendTimer)
    show(resendButton, false)

    show(panel, false)
    if (restoreList) show(root, true)
    show(pageActions, true)
    show(qrNode, false)
    show(secretNode, false)
    show(codeNode, false)

    if (otp) otp.clear?.()

    say('')
    fail(error)
  }

  async function start(chosen) {
    if (busy) return

    type = chosen
    busy = true

    show(root, false)
    show(panel, true)
    show(pageActions, false)
    fail(null)

    // Name the work, not the waiting: "sending a code to your inbox" tells the
    // user what to expect next, which "Working…" never did.
    say(
      root.dataset['labelWorking' + chosen.charAt(0).toUpperCase() + chosen.slice(1)] ??
        root.dataset.labelWorking ??
        '',
    )

    if (type === 'webauthn' && !isSupported()) {
      busy = false
      fail(root.dataset.labelUnsupported ?? '')
      return
    }

    const { response, payload } = await post(storeUrl, { type })

    busy = false

    if (!response.ok) {
      // Throttled, or the method refused: put the choice back on screen with
      // the reason above it. A panel showing only an error is a dead end.
      backToList({ error: firstError(payload) ?? '', restoreList: response.status !== 429 })

      if (response.status === 429) lockList(payload?.retry_after ?? 60)

      return
    }

    if (type === 'webauthn') {
      say(root.dataset.labelPasskey ?? '')

      try {
        const credential = await createCredential(payload.public_key)
        await confirm({ credential })
      } catch (error) {
        const message = describeError(error)
        if (message) backToList({ error: message })
      }

      return
    }

    if (payload.qr_code && qrNode) {
      qrNode.innerHTML = payload.qr_code
      show(qrNode, true)
    }

    if (payload.secret_groups && secretNode) {
      secretNode.textContent = payload.secret_groups
      show(secretNode, true)
    }

    if (payload.destination_hint) {
      // "We sent" and "we already sent" are different facts, and the second one
      // is the one that stops a user hunting for a newer mail that will not
      // come — because arriving here again no longer sends one.
      const already = payload.sent === false

      sayWithDestination(
        already ? root.dataset.labelAlreadySent : root.dataset.labelSent,
        payload.destination_hint,
        already ? root.dataset.labelAlreadyNote : root.dataset.labelSentNote,
      )

      armResend(payload.resend_after ?? 0)
    } else {
      say(root.dataset.labelScan ?? '')
    }
    show(codeNode, true)
    otp?.input?.focus()
  }

  root.querySelectorAll('[data-n2f-enroll-start]').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault()

      if (link.getAttribute('aria-disabled') === 'true') return

      start(link.dataset.n2fEnrollStart)
    })
  })

  confirmButton?.addEventListener('click', () => confirm({ code: otp?.value ?? '' }))

  // Back to the list. The cancel button was queried but never wired, so
  // "choose a different method" left the user on a panel with no way out.
  cancelButton?.addEventListener('click', () => backToList())
  codesDone?.addEventListener('click', () => window.location.assign(doneUrl))

  // Somewhere to put the codes. Shown once with no way out of the browser, they
  // are codes nobody keeps.
  if (codesActions) {
    const settings = codesActions.dataset
    const shown = () => Array.from(codesList?.children ?? []).map((item) => item.textContent)

    codesActions
      .querySelector('[data-n2f-codes-copy]')
      ?.addEventListener('click', async (event) => {
        const button = event.currentTarget

        try {
          await copyCodes(shown())
        } catch {
          // Clipboard permission refused, or an insecure context: the codes are
          // on screen and selectable, so this is a convenience, not the path.
          return
        }

        button.textContent = settings.labelCopied ?? ''
        setTimeout(() => (button.textContent = settings.labelCopy ?? ''), 2000)
      })

    codesActions
      .querySelector('[data-n2f-codes-download]')
      ?.addEventListener('click', () => downloadCodes(shown(), settings.appName))

    codesActions.querySelector('[data-n2f-codes-print]')?.addEventListener('click', () =>
      printCodes(shown(), {
        appName: settings.appName,
        account: settings.account,
        title: settings.printTitle,
        note: settings.printNote,
      }),
    )
  }
  resendButton?.addEventListener('click', () => resend())
}

document.querySelectorAll('[data-n2f-challenge]').forEach(mountChallenge)
document.querySelectorAll('[data-n2f-countdown]').forEach(mountCountdown)
document.querySelectorAll('[data-n2f-enroll]').forEach(mountEnrollment)

// Passkey management lives inside Nova's SPA and is registered from tool.js.
