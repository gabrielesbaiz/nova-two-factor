import { ref } from 'vue'
import { copyCodes, downloadCodes, printCodes } from '../support/recovery-codes'

/**
 * Copy, download and print for a set of recovery codes.
 *
 * The behaviour lives in `support/recovery-codes`, shared with the pre-auth
 * bundle, which has no Vue to import. This adds only the bit that is reactive:
 * the transient "copied" state the button label reads.
 */
export function useRecoveryCodes() {
  const copied = ref(false)

  const copy = async (codes) => {
    await copyCodes(codes)

    copied.value = true
    setTimeout(() => (copied.value = false), 2000)
  }

  const download = (codes, appName) => downloadCodes(codes, appName)

  const print = (codes, { appName, account }) => printCodes(codes, { appName, account })

  return { copied, copy, download, print }
}
