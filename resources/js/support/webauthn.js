import { __ } from './translate'

/** base64url <-> ArrayBuffer. WebAuthn uses base64url everywhere, never base64. */
const toBuffer = (value) => {
  const padded = value.replace(/-/g, '+').replace(/_/g, '/')
  const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4))
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i)
  return bytes.buffer
}

const toBase64Url = (buffer) =>
  btoa(String.fromCharCode(...new Uint8Array(buffer)))
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '')

export const isSupported = () =>
  typeof window.PublicKeyCredential !== 'undefined' &&
  typeof navigator.credentials?.get === 'function'

export const isPlatformAuthenticatorAvailable = async () => {
  if (!isSupported()) return false
  try {
    return await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()
  } catch {
    return false
  }
}

export const isConditionalMediationAvailable = async () => {
  if (!isSupported()) return false
  try {
    return (await window.PublicKeyCredential.isConditionalMediationAvailable?.()) ?? false
  } catch {
    return false
  }
}

const decodeOptions = (options) => ({
  ...options,
  challenge: toBuffer(options.challenge),
  ...(options.user ? { user: { ...options.user, id: toBuffer(options.user.id) } } : {}),
  ...(options.allowCredentials
    ? { allowCredentials: options.allowCredentials.map((c) => ({ ...c, id: toBuffer(c.id) })) }
    : {}),
  ...(options.excludeCredentials
    ? { excludeCredentials: options.excludeCredentials.map((c) => ({ ...c, id: toBuffer(c.id) })) }
    : {}),
})

const encodeCredential = (credential) => ({
  id: credential.id,
  rawId: toBase64Url(credential.rawId),
  type: credential.type,
  authenticatorAttachment: credential.authenticatorAttachment ?? null,
  clientExtensionResults: credential.getClientExtensionResults?.() ?? {},
  response: Object.fromEntries(
    Object.entries({
      clientDataJSON: credential.response.clientDataJSON,
      attestationObject: credential.response.attestationObject,
      authenticatorData: credential.response.authenticatorData,
      signature: credential.response.signature,
      userHandle: credential.response.userHandle,
    })
      .filter(([, value]) => value)
      .map(([key, value]) => [key, toBase64Url(value)]),
  ),
})

/**
 * Refuse a ceremony the browser is guaranteed to reject.
 *
 * A credential is bound to a relying-party id, and the browser only allows one
 * that equals the page's host or is a registrable parent of it. Configure
 * `app.url` for production and browse a staging host, and every passkey fails
 * with a `SecurityError` the user reads as "your device could not complete the
 * request" — while the server logs an origin it never saw. Checking here names
 * both halves of the mismatch.
 */
function assertRelyingParty(rpId) {
  if (!rpId) return

  const host = window.location.hostname

  if (host === rpId || host.endsWith('.' + rpId)) return

  console.error(
    `[nova-two-factor] This page is ${host}, but passkeys are configured for the relying party ` +
      `${rpId}. A browser will not sign for a relying party that is not its own host or a parent ` +
      `of it. Set app.url to this host, or NOVA_TWO_FACTOR_WEBAUTHN_RP_ID and ` +
      `NOVA_TWO_FACTOR_WEBAUTHN_ORIGINS to match it.`,
  )

  const error = new Error('Relying party [' + rpId + '] does not match host [' + host + '].')
  error.name = 'NovaTwoFactorRelyingPartyError'

  throw error
}

export async function createCredential(publicKey) {
  assertRelyingParty(publicKey?.rp?.id)

  const credential = await navigator.credentials.create({ publicKey: decodeOptions(publicKey) })
  return encodeCredential(credential)
}

export async function getCredential(publicKey, { conditional = false, signal } = {}) {
  assertRelyingParty(publicKey?.rpId)

  const credential = await navigator.credentials.get({
    publicKey: decodeOptions(publicKey),
    ...(conditional ? { mediation: 'conditional' } : {}),
    ...(signal ? { signal } : {}),
  })
  return credential ? encodeCredential(credential) : null
}

/**
 * Raw WebAuthn errors are hostile to users, so map them once, here.
 */
export function describeError(error) {
  // The user gets a sentence; whoever is debugging gets the exception. Without
  // this, an error the switch does not recognise reads as "your device could
  // not complete the request" with nothing behind it — which is exactly how a
  // misconfigured relying party looks from the browser.
  if (error && error.name !== 'AbortError') {
    console.error('[nova-two-factor] WebAuthn ceremony failed', error)
  }

  if (error?.name === 'OperationError') {
    return __('Another sign-in request is still open. Close the other tab, or reload this page.')
  }

  switch (error?.name) {
    case 'NotAllowedError':
      return __('Cancelled, or it timed out. Try again.')
    case 'InvalidStateError':
      return __('This device already has a passkey on your account.')
    case 'SecurityError':
      return __('Passkeys need a secure (https) connection.')
    case 'AbortError':
      return null // the user navigated away; not worth an error message
    case 'NotSupportedError':
      return __('This browser cannot use passkeys.')
    case 'NovaTwoFactorRelyingPartyError':
      return __('Passkeys are not set up for this address. Ask your administrator.')
    default:
      return __('Your device could not complete the request.')
  }
}
