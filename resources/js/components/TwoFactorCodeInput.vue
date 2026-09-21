<template>
  <div class="n2f-otp" :data-state="state || undefined" dir="ltr">
    <label :for="id" class="sr-only">{{ label || __('Verification code') }}</label>

    <input
      :id="id"
      ref="input"
      v-model="model"
      class="n2f-otp-input"
      type="text"
      inputmode="numeric"
      pattern="[0-9]*"
      autocomplete="one-time-code"
      autocapitalize="off"
      autocorrect="off"
      spellcheck="false"
      :maxlength="length"
      :readonly="readonly"
      :aria-describedby="`${id}-hint`"
      @paste.prevent="onPaste"
    />

    <!-- Decoration only. The single input above is the accessible object: six
         real inputs break autofill and give a screen reader six unlabelled
         fields to read out. -->
    <div class="n2f-otp-boxes" aria-hidden="true">
      <span
        v-for="index in length"
        :key="index"
        class="n2f-otp-box"
        :data-filled="String(index <= model.length)"
        :data-active="String(index === Math.min(model.length + 1, length) && model.length < length)"
      >
        {{ model[index - 1] ?? '' }}
      </span>
    </div>

    <p :id="`${id}-hint`" class="sr-only">{{ __(':count digits', { count: length }) }}</p>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { __ } from '../support/translate'

defineOptions({ name: 'TwoFactorCodeInput' })

const props = defineProps({
  modelValue: { type: String, default: '' },
  length: { type: Number, default: 6 },
  label: { type: String, default: null },
  autofocus: { type: Boolean, default: true },
  // `readonly`, not `disabled`: a disabled input loses focus and drops its
  // screen-reader context mid-verification.
  readonly: { type: Boolean, default: false },
  invalid: { type: Boolean, default: false },
  autoSubmit: { type: Boolean, default: true },
})

const emit = defineEmits(['update:modelValue', 'complete'])

const input = ref(null)
const id = `n2f-code-${Math.random().toString(36).slice(2, 8)}`
const armed = ref(true)

const sanitize = (value) => (value ?? '').replace(/\D/g, '').slice(0, props.length)

const model = computed({
  get: () => sanitize(props.modelValue),
  set: (value) => emit('update:modelValue', sanitize(value)),
})

const state = computed(() => (props.invalid ? 'invalid' : null))

const onPaste = (event) => {
  const text = event.clipboardData?.getData('text') ?? ''
  armed.value = true
  model.value = text
}

watch(
  () => props.modelValue,
  (value) => {
    if (sanitize(value).length < props.length) {
      armed.value = true
      return
    }

    if (!props.autoSubmit || !armed.value) return

    armed.value = false
    // One frame, so the last digit paints before the request begins.
    requestAnimationFrame(() => emit('complete', sanitize(value)))
  },
)

// Clearing after a failure must not silently re-fire the same submission.
watch(
  () => props.invalid,
  (value) => {
    if (value) {
      armed.value = false
      nextTick(() => input.value?.focus())
    }
  },
)

onMounted(() => {
  if (props.autofocus) input.value?.focus()
})

defineExpose({ focus: () => input.value?.focus() })
</script>
