import { computed, onUnmounted, ref } from 'vue'

/**
 * A countdown that announces itself at thresholds rather than on every tick —
 * a live region that fires once a second is unusable with a screen reader.
 */
export function useCountdown({ announceAt = [300, 60, 30, 0] } = {}) {
  const remaining = ref(0)
  const announcement = ref('')
  let handle = null

  const formatted = computed(() => {
    const minutes = Math.floor(remaining.value / 60)
    const seconds = String(remaining.value % 60).padStart(2, '0')
    return `${minutes}:${seconds}`
  })

  const running = computed(() => remaining.value > 0)

  const stop = () => {
    if (handle) clearInterval(handle)
    handle = null
  }

  const start = (seconds) => {
    stop()
    remaining.value = Math.max(0, Math.floor(seconds))

    handle = setInterval(() => {
      remaining.value = Math.max(0, remaining.value - 1)

      if (announceAt.includes(remaining.value)) {
        announcement.value =
          remaining.value === 0 ? 'You can try again now.' : `${formatted.value} remaining.`
      }

      if (remaining.value === 0) stop()
    }, 1000)
  }

  onUnmounted(stop)

  return { remaining, formatted, running, announcement, start, stop }
}
