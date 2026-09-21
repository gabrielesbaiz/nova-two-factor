import { computed } from 'vue'

/**
 * The single place endpoints are built.
 *
 * Everything goes through `Nova.url()`. 1.x hardcoded `/nova-vendor/...` with a
 * leading slash, which broke every installation not served from the domain root.
 */
export function useTwoFactorApi() {
  const prefix = Nova.config('novaTwoFactor')?.prefix ?? 'two-factor'

  const url = (path) => Nova.url(`/${prefix}/${path}`.replace(/\/+/g, '/'))

  return {
    endpoints: computed(() => ({
      methods: url('methods'),
      enroll: url('methods'),
      confirm: url('methods/confirm'),
      recoveryCodes: url('recovery-codes'),
      devices: url('devices'),
    })),

    fetchOverview: () =>
      Nova.request()
        .get(url('methods'))
        .then((r) => r.data),
    beginEnrollment: (payload) =>
      Nova.request()
        .post(url('methods'), payload)
        .then((r) => r.data),
    confirmEnrollment: (payload) =>
      Nova.request()
        .post(url('methods/confirm'), payload)
        .then((r) => r.data),
    renameMethod: (id, name) =>
      Nova.request()
        .patch(url(`methods/${id}`), { name })
        .then((r) => r.data),
    setDefaultMethod: (id) =>
      Nova.request()
        .put(url(`methods/${id}/default`))
        .then((r) => r.data),
    removeMethod: (id) =>
      Nova.request()
        .delete(url(`methods/${id}`))
        .then((r) => r.data),
    regenerateRecoveryCodes: () =>
      Nova.request()
        .post(url('recovery-codes'))
        .then((r) => r.data),
    fetchDevices: () =>
      Nova.request()
        .get(url('devices'))
        .then((r) => r.data),
    revokeDevice: (id) =>
      Nova.request()
        .delete(url(`devices/${id}`))
        .then((r) => r.data),
    revokeAllDevices: () =>
      Nova.request()
        .delete(url('devices'))
        .then((r) => r.data),
  }
}
