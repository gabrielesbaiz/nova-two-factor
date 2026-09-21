/**
 * Turns a 423 response into a step-up prompt, once, for every request.
 *
 * The middleware answers XHR with 423 plus `step_up_required`, deliberately
 * mirroring Laravel's own `RequirePassword`. Handling it in one interceptor
 * means no call site has to know that a given action might need re-authorisation
 * — and the original request is replayed on success, so the user does not lose
 * what they were doing.
 */
export function installStepUpInterceptor(Nova) {
  const client = Nova.request()

  client.interceptors.response.use(
    (response) => response,
    (error) => {
      const { response, config } = error

      if (response?.status !== 423 || !response.data?.step_up_required) {
        return Promise.reject(error)
      }

      if (config.__n2fRetried) {
        return Promise.reject(error)
      }

      return new Promise((resolve, reject) => {
        Nova.$emit('nova-two-factor:step-up', {
          scope: response.data.scope,
          factors: response.data.factors ?? [],
          redirect: response.data.redirect,
          onConfirmed: () => {
            config.__n2fRetried = true
            client.request(config).then(resolve).catch(reject)
          },
          onCancelled: () => reject(error),
        })
      })
    },
  )
}
