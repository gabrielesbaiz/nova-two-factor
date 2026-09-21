<template>
  <div class="flex flex-col items-center gap-3">
    <!-- The plate stays white in both themes on purpose: scanners need the
         contrast, and a themed QR that inverts in dark mode will not scan.
         Fixed dimensions so the layout never jumps as the SVG arrives. -->
    <div
      class="n2f-qr"
      role="img"
      :aria-label="__('QR code for setting up your authenticator app')"
    >
      <div v-if="svg" v-html="svg" />
      <Loader v-else class="text-gray-300" />
    </div>

    <button
      type="button"
      class="text-xs font-bold text-primary-500"
      @click="revealed = !revealed"
      :aria-expanded="revealed"
    >
      {{ revealed ? __('Hide setup key') : __("Can't scan? Enter the key by hand") }}
    </button>

    <!-- Always reachable, never QR-only: without this the flow is unusable on a
         desktop with no camera, and unusable with a screen reader. -->
    <div v-if="revealed" class="w-full text-center">
      <p class="n2f-key text-sm text-gray-900 dark:text-gray-100 select-all">{{ grouped }}</p>

      <div class="mt-2 flex items-center justify-center gap-3">
        <button type="button" class="text-xs font-bold text-primary-500" @click="copy">
          {{ copied ? __('Copied') : __('Copy setup key') }}
        </button>

        <!-- One tap on a phone opens the authenticator app directly, which is
             where transcribing a base32 string is most painful. -->
        <a v-if="otpauthUri" :href="otpauthUri" class="text-xs font-bold text-primary-500">
          {{ __('Open in app') }}
        </a>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { __ } from '../support/translate'

defineOptions({ name: 'TwoFactorQrCode' })

const props = defineProps({
  svg: { type: String, default: null },
  secret: { type: String, default: null },
  secretGroups: { type: String, default: null },
  otpauthUri: { type: String, default: null },
})

const revealed = ref(false)
const copied = ref(false)

const grouped = computed(() => props.secretGroups ?? props.secret ?? '')

const copy = async () => {
  await navigator.clipboard.writeText(props.secret ?? '')
  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}
</script>
