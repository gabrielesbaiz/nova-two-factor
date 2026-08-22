/** base64url <-> ArrayBuffer. WebAuthn uses base64url everywhere, never base64. */
const toBuffer = value => {
  const padded = value.replace(/-/g, '+').replace(/_/g, '/')
  const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4))
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i)
  return bytes.buffer
}

const toBase64Url = buffer =>
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

const decodeOptions = options => ({
  ...options,
  challenge: toBuffer(options.challenge),
  ...(options.user ? { user: { ...options.user, id: toBuffer(options.user.id) } } : {}),
  ...(options.allowCredentials
    ? { allowCredentials: options.allowCredentials.map(c => ({ ...c, id: toBuffer(c.id) })) }
    : {}),
  ...(options.excludeCredentials
    ? { excludeCredentials: options.excludeCredentials.map(c => ({ ...c, id: toBuffer(c.id) })) }
    : {}),
})

const encodeCredential = credential => ({
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
      .map(([key, value]) => [key, toBase64Url(value)])
  ),
})

export async function createCredential(publicKey) {
  const credential = await navigator.credentials.create({ publicKey: decodeOptions(publicKey) })
  return encodeCredential(credential)
}

export async function getCredential(publicKey, { conditional = false } = {}) {
  const credential = await navigator.credentials.get({
    publicKey: decodeOptions(publicKey),
    ...(conditional ? { mediation: 'conditional' } : {}),
  })
  return credential ? encodeCredential(credential) : null
}

/**
 * Raw WebAuthn errors are hostile to users, so map them once, here.
 */
export function describeError(error) {
  switch (error?.name) {
    case 'NotAllowedError':
      return 'Cancelled, or it timed out. Try again.'
    case 'InvalidStateError':
      return 'This device already has a passkey on your account.'
    case 'SecurityError':
      return 'Passkeys need a secure (https) connection.'
    case 'AbortError':
      return null // the user navigated away; not worth an error message
    case 'NotSupportedError':
      return 'This browser cannot use passkeys.'
    default:
      return 'Your device could not complete the request.'
  }
}
