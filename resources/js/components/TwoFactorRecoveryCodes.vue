<template>
  <div>
    <p class="mb-4 text-sm">
      {{
        __(
          'Each code works once, if you lose access to your other methods. This is the only time we can show them to you.',
        )
      }}
    </p>

    <div class="rounded-lg bg-gray-100 dark:bg-gray-900 p-4">
      <ol class="grid grid-cols-1 sm:grid-cols-2 gap-x-8 gap-y-1 font-mono text-sm" dir="ltr">
        <li v-for="(code, index) in codes" :key="code" class="flex gap-3">
          <span class="text-gray-400 text-xs n2f-tabular w-4 text-end">{{ index + 1 }}</span>
          <span class="select-all text-gray-900 dark:text-gray-100">{{ code }}</span>
        </li>
      </ol>
    </div>

    <div class="mt-4 flex flex-wrap gap-2">
      <Button variant="outline" @click="copy(codes)">
        {{ copied ? __('Copied') : __('Copy all') }}
      </Button>
      <Button variant="outline" @click="download(codes, appName)">{{ __('Download') }}</Button>
      <Button variant="outline" @click="print(codes, { appName, account })">{{
        __('Print')
      }}</Button>
    </div>

    <!-- A checkbox, never a timer. `aria-disabled` rather than `disabled` so the
         button stays focusable and a keyboard user can find out why it will not
         move — a disabled button is invisible to someone hunting for the reason
         they are stuck. -->
    <label class="mt-6 flex items-start gap-2 text-sm">
      <input v-model="acknowledged" type="checkbox" class="mt-1" />
      <span>{{ __('I have saved these codes somewhere safe') }}</span>
    </label>

    <Button
      class="mt-4"
      :aria-disabled="!acknowledged"
      :aria-describedby="acknowledged ? undefined : 'n2f-ack-hint'"
      @click="acknowledged && emit('acknowledged')"
    >
      {{ __('Continue') }}
    </Button>

    <p v-if="!acknowledged" id="n2f-ack-hint" class="mt-2 text-xs text-gray-400">
      {{ __('Confirm you have saved your codes first.') }}
    </p>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { Button } from 'laravel-nova-ui'
import { useRecoveryCodes } from '../composables/useRecoveryCodes'
import { __ } from '../support/translate'

defineOptions({ name: 'TwoFactorRecoveryCodes' })

defineProps({
  codes: { type: Array, required: true },
  appName: { type: String, default: '' },
  account: { type: String, default: '' },
})

const emit = defineEmits(['acknowledged'])

const acknowledged = ref(false)
const { copied, copy, download, print } = useRecoveryCodes()
</script>
