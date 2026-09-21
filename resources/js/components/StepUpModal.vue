<template>
  <Modal :show="show" role="alertdialog" size="sm" @close-via-escape="cancel">
    <form class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6" @submit.prevent="confirm()">
      <div class="flex items-start gap-3 mb-4">
        <span
          class="w-9 h-9 rounded-lg grid place-items-center flex-none bg-red-100 dark:bg-red-900/40 text-red-600"
        >
          <Icon name="lock-closed" type="micro" />
        </span>
        <div>
          <Heading :level="4" class="text-base" v-text="__('Confirm your identity')" />
          <!-- Naming the action is what stops this becoming a prompt people
               approve reflexively. -->
          <p class="mt-1 text-sm">{{ __('This action needs a fresh check: :scope', { scope }) }}</p>
        </div>
      </div>

      <div v-if="usesPasskey" class="text-center py-2">
        <Button :loading="busy" @click="confirmWithPasskey">{{
          __('Confirm with your passkey')
        }}</Button>
      </div>

      <div v-else>
        <TwoFactorCodeInput
          v-model="code"
          :invalid="Boolean(error)"
          :readonly="busy"
          @complete="confirm"
        />
      </div>

      <HelpText v-if="error" class="mt-2 text-red-500" role="alert">{{ error }}</HelpText>

      <div class="mt-6 flex justify-end gap-2">
        <!-- Cancelling is a normal choice, not a failure: no error toast. -->
        <Button type="button" variant="ghost" @click="cancel">{{ __('Cancel') }}</Button>
        <Button type="submit" :loading="busy">{{ __('Confirm') }}</Button>
      </div>

      <p class="mt-4 text-xs text-gray-400 text-center">{{ __('Valid for this action only.') }}</p>
    </form>
  </Modal>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Button, Icon } from 'laravel-nova-ui'
import TwoFactorCodeInput from './TwoFactorCodeInput.vue'
import { getCredential, describeError } from '../support/webauthn'
import { __ } from '../support/translate'

defineOptions({ name: 'NovaTwoFactorStepUpModal' })

const show = ref(false)
const scope = ref('')
const factors = ref([])
const code = ref('')
const error = ref(null)
const busy = ref(false)

let onConfirmed = null
let onCancelled = null

const usesPasskey = computed(() => factors.value.length === 1 && factors.value[0] === 'webauthn')

const close = () => {
  show.value = false
  code.value = ''
  error.value = null
  busy.value = false
}

const cancel = () => {
  close()
  onCancelled?.()
}

const post = (path, body) =>
  Nova.request()
    .post(Nova.url(`/${Nova.config('novaTwoFactor')?.prefix ?? 'two-factor'}/step-up${path}`), body)
    .then((r) => r.data)

const confirm = async (value) => {
  if (busy.value) return
  busy.value = true
  error.value = null

  try {
    await post('', { scope: scope.value, code: value ?? code.value, method_id: methodId.value })
    close()
    onConfirmed?.()
  } catch (e) {
    code.value = ''
    error.value = e.response?.data?.errors?.code?.[0] ?? __('That code is not correct.')
  } finally {
    busy.value = false
  }
}

const methodId = ref(null)

const confirmWithPasskey = async () => {
  busy.value = true
  error.value = null

  try {
    const options = await post('/prepare', { method_id: methodId.value })
    // Purpose is step-up server-side, so user verification is required here
    // regardless of the configured preference.
    const credential = await getCredential(options.public_key)
    await post('', { scope: scope.value, method_id: methodId.value, credential })
    close()
    onConfirmed?.()
  } catch (e) {
    error.value = describeError(e) ?? __('That could not be confirmed.')
  } finally {
    busy.value = false
  }
}

onMounted(() => {
  Nova.$on('nova-two-factor:step-up', (payload) => {
    scope.value = payload.scope
    factors.value = payload.factors ?? []
    methodId.value = payload.methodId ?? null
    onConfirmed = payload.onConfirmed
    onCancelled = payload.onCancelled
    show.value = true
  })
})
</script>
