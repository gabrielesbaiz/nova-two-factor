/**
 * The pre-authentication bundle: challenge, step-up, enforcement countdown.
 *
 * Deliberately dependency-free — no Vue, no Inertia, no axios. These pages are
 * always a cold load and they sit on the login path, so they get one request and
 * a few kilobytes. Everything here is progressive enhancement: with the script
 * absent or broken, the form still posts and still works.
 */
import { mountOtpInput } from './support/otp'
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

const firstError = payload => {
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
  const recoveryField = form.querySelector('[data-n2f-recovery-field]')
  const passkeyBlock = form.querySelector('[data-n2f-passkey]')
  const passkeyTrigger = form.querySelector('[data-n2f-passkey-trigger]')
  const chooserToggle = form.querySelector('[data-n2f-chooser-toggle]')
  const chooser = form.querySelector('[data-n2f-chooser]')

  let busy = false
  let lockTimer = null

  const say = message => {
    if (statusNode) statusNode.textContent = message ?? ''
  }

  const fail = message => {
    if (!errorNode) return
    errorNode.textContent = message ?? ''
    errorNode.classList.toggle('hidden', !message)
  }

  const otp = mountOtpInput(form, { onComplete: () => attempt() })

  /** Drive the lockout countdown from Retry-After, never from a guess. */
  const lockOut = seconds => {
    if (!submit) return
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
        say('You can try again now.')
        return
      }

      const minutes = Math.floor(remaining / 60)
      const secs = String(remaining % 60).padStart(2, '0')
      submit.textContent = `Try again in ${minutes}:${secs}`
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
    say('Verifying…')

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
      fail(firstError(payload) ?? 'Too many attempts.')
      lockOut(retryAfter ?? 60)
      return
    }

    if (!response.ok) {
      otp?.markInvalid()
      const message = firstError(payload) ?? 'That did not work. Try again.'
      fail(message)
      say(message)
      return
    }

    otp?.markValid()
    say('Verified.')

    // A full navigation, not a client-side route change: the session's
    // verification state changed on the server.
    window.location.assign(payload?.redirect || '/')
  }

  form.addEventListener('submit', event => {
    event.preventDefault()
    attempt()
  })

  chooserToggle?.addEventListener('click', () => {
    const open = chooser?.classList.toggle('hidden') === false
    chooserToggle.setAttribute('aria-expanded', String(open))
  })

  form.querySelectorAll('[data-n2f-choose]').forEach(button => {
    button.addEventListener('click', async () => {
      const methodId = button.dataset.methodId
      const methodType = button.dataset.methodType

      form.dataset.methodType = methodType
      if (methodIdInput && methodId) methodIdInput.value = methodId

      form.querySelectorAll('[data-n2f-choose]').forEach(other =>
        other.removeAttribute('aria-current')
      )
      button.setAttribute('aria-current', 'true')

      const usingRecovery = methodType === 'recovery_code'
      const usingPasskey = methodType === 'webauthn'

      codeField?.classList.toggle('hidden', usingRecovery || usingPasskey)
      recoveryField?.classList.toggle('hidden', !usingRecovery)
      passkeyBlock?.classList.toggle('hidden', !usingPasskey)

      fail(null)
      otp?.clear()

      if (usingRecovery) {
        recoveryField?.querySelector('input')?.focus()
        return
      }

      if (usingPasskey) return

      const payload = await prepare(methodId, methodType)

      if (payload?.sent === false && payload.retry_after) {
        say(`A code was already sent. You can ask for another in ${payload.retry_after} seconds.`)
      } else if (payload?.destination_hint) {
        say(`Code sent to ${payload.destination_hint}.`)
      }

      otp?.input.focus()
    })
  })

  async function runPasskey({ conditional = false } = {}) {
    if (!isSupported()) return false

    const methodId = methodIdInput?.value ? Number(methodIdInput.value) : null
    const payload = await prepare(methodId, 'webauthn')

    if (!payload?.public_key) return false

    try {
      const credential = await getCredential(payload.public_key, { conditional })
      if (!credential) return false

      await attempt({ credential })
      return true
    } catch (error) {
      const message = describeError(error)
      if (message) fail(message)
      return false
    }
  }

  passkeyTrigger?.addEventListener('click', () => runPasskey())

  ;(async () => {
    const passkeyMethod = Array.from(form.querySelectorAll('[data-n2f-choose]')).find(
      button => button.dataset.methodType === 'webauthn'
    )

    if (!passkeyMethod || !isSupported()) {
      // No passkey, or a browser that cannot use one: hide the affordance rather
      // than offering a button that cannot work.
      passkeyBlock?.classList.add('hidden')
      otp?.input.focus()
      return
    }

    if (await isPlatformAuthenticatorAvailable()) {
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
      node.textContent = 'Set up a method to continue.'
      node.classList.add('text-red-500')
      return
    }

    node.textContent =
      days > 0
        ? `${days} days ${hours} hours left to set this up.`
        : `${hours}:${String(minutes).padStart(2, '0')} left to set this up.`

    if (remaining < 3600) node.classList.add('text-red-500')
  }

  tick()
  setInterval(tick, 30000)
}

document.querySelectorAll('[data-n2f-challenge]').forEach(mountChallenge)
document.querySelectorAll('[data-n2f-countdown]').forEach(mountCountdown)

// Passkey management lives inside Nova's SPA and is registered from tool.js.
