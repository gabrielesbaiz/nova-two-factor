<template>
  <div class="mt-10 sm:mt-0 mb-6" id="two-factor">
    <div class="md:grid md:grid-cols-3 md:gap-6">
      <div class="md:col-span-1 px-4 sm:px-0">
        <Heading :level="3" v-text="__('Two-factor authentication')" />
        <p class="my-3 text-sm text-gray-600 dark:text-gray-400">
          {{ __('A second step at sign-in, so a stolen password is not enough on its own.') }}
        </p>
      </div>

      <Card class="md:col-span-2 p-6">
        <LoadingView :loading="loading">
          <!-- Status first, in words as well as colour. -->
          <div class="flex items-center gap-3 mb-1">
            <Heading :level="4" class="text-lg font-medium" v-text="statusHeading" />
            <span class="n2f-badge" :class="statusBadgeClass">{{ statusLabel }}</span>
          </div>

          <p class="mb-6 text-sm">{{ statusDescription }}</p>

          <!-- Grace-period banner. Turns urgent as the deadline approaches, and
               the deadline itself is absolute, not a vague "soon". -->
          <div
            v-if="graceMessage"
            class="mb-6 rounded-lg px-4 py-3 text-sm"
            :class="graceUrgent
              ? 'bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300'
              : 'bg-yellow-50 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300'"
            role="status"
          >
            {{ graceMessage }}
          </div>

          <!-- ── Enrolled methods ─────────────────────────────────────────── -->
          <div v-if="methods.length" class="divide-y divide-gray-100 dark:divide-gray-800">
            <TwoFactorMethodRow
              v-for="method in methods"
              :key="method.id"
              :method="method"
              @rename="startRename"
              @default="makeDefault"
              @remove="confirmRemove"
            />
          </div>

          <!-- ── Add a method ─────────────────────────────────────────────── -->
          <div v-if="!enrolling" class="mt-6 flex flex-wrap gap-2">
            <ConfirmsPassword
              v-for="driver in available"
              :key="driver.type"
              @confirmed="startEnrollment(driver.type)"
            >
              <Button variant="outline" :dusk="`add-${driver.type}`">
                {{ __('Add :method', { method: driver.label.toLowerCase() }) }}
                <span v-if="driver.phishing_resistant" class="n2f-badge n2f-badge-ok ms-2">
                  {{ __('Strongest') }}
                </span>
              </Button>
            </ConfirmsPassword>
          </div>

          <!-- ── TOTP enrollment ─────────────────────────────────────────── -->
          <div v-if="enrolling === 'totp'" class="mt-6">
            <div class="flex items-center gap-2 mb-6 text-xs font-bold uppercase tracking-wide">
              <span :class="step === 1 ? 'text-primary-500' : 'text-gray-400'">{{ __('1 Scan') }}</span>
              <hr class="flex-1 n2f-divider" />
              <span :class="step === 2 ? 'text-primary-500' : 'text-gray-400'">{{ __('2 Verify') }}</span>
            </div>

            <div class="flex flex-col sm:flex-row gap-6">
              <TwoFactorQrCode
                :svg="intent.qr_code"
                :secret="intent.secret"
                :secret-groups="intent.secret_groups"
                :otpauth-uri="intent.otpauth_uri"
              />

              <div class="flex-1">
                <p class="mb-3 text-sm">{{ __('Then enter the six-digit code your app shows.') }}</p>

                <TwoFactorCodeInput
                  v-model="code"
                  :invalid="Boolean(error)"
                  :readonly="verifying"
                  @complete="confirmEnrollment"
                />

                <HelpText v-if="error" class="mt-2 text-red-500" role="alert">{{ error }}</HelpText>

                <!-- Two failures is where clock skew becomes the likely cause,
                     and repeating "invalid code" a third time does not help. -->
                <HelpText v-if="failures >= 2" class="mt-2">
                  {{ __("Codes rotate every 30 seconds. If this keeps failing, check that your phone's clock is set automatically.") }}
                </HelpText>

                <div class="mt-4 flex gap-2">
                  <Button :loading="verifying" @click="confirmEnrollment(code)">{{ __('Turn on') }}</Button>
                  <Button variant="ghost" @click="cancelEnrollment">{{ __('Cancel') }}</Button>
                </div>
              </div>
            </div>
          </div>

          <!-- ── Passkey enrollment ──────────────────────────────────────── -->
          <div v-if="enrolling === 'webauthn'" class="mt-6 text-center">
            <p class="mb-4 text-sm">
              {{ waitingForDevice
                ? __('Waiting for your device…')
                : __('Your browser will ask for your fingerprint, face, or security key.') }}
            </p>
            <Loader v-if="waitingForDevice" class="mx-auto text-primary-500" />
            <HelpText v-if="error" class="mt-3 text-red-500" role="alert">{{ error }}</HelpText>
            <Button v-if="!waitingForDevice" class="mt-2" @click="runPasskeyEnrollment">
              {{ __('Try again') }}
            </Button>
            <Button variant="ghost" class="mt-2 ms-2" @click="cancelEnrollment">{{ __('Cancel') }}</Button>
          </div>

          <!-- ── Email enrollment ────────────────────────────────────────── -->
          <div v-if="enrolling === 'email'" class="mt-6">
            <p class="mb-3 text-sm">
              {{ __('We sent a code to :destination.', { destination: intent.destination_hint }) }}
            </p>
            <TwoFactorCodeInput v-model="code" :invalid="Boolean(error)" @complete="confirmEnrollment" />
            <HelpText v-if="error" class="mt-2 text-red-500" role="alert">{{ error }}</HelpText>
            <HelpText class="mt-2">
              {{ __('Email codes are weaker than an authenticator app or a passkey — anyone with your inbox has your second factor.') }}
            </HelpText>
            <div class="mt-4 flex gap-2">
              <Button :loading="verifying" @click="confirmEnrollment(code)">{{ __('Confirm') }}</Button>
              <Button variant="ghost" @click="cancelEnrollment">{{ __('Cancel') }}</Button>
            </div>
          </div>

          <!-- ── Recovery codes ─────────────────────────────────────────── -->
          <div v-if="freshCodes.length" class="mt-8">
            <DividerLine class="mb-6" />
            <Heading :level="4" class="text-base mb-3" v-text="__('Save your recovery codes')" />
            <TwoFactorRecoveryCodes
              :codes="freshCodes"
              :app-name="appName"
              :account="account"
              @acknowledged="freshCodes = []"
            />
          </div>

          <div v-else-if="methods.length" class="mt-8">
            <DividerLine class="mb-6" />
            <div class="flex items-baseline justify-between">
              <Heading :level="4" class="text-base" v-text="__('Recovery codes')" />
              <span class="text-xs" :class="recovery.running_low ? 'text-yellow-600' : 'text-gray-400'">
                {{ __(':remaining of :total remaining', recovery) }}
              </span>
            </div>

            <div class="mt-3 flex gap-1" aria-hidden="true">
              <span
                v-for="index in recovery.total"
                :key="index"
                class="h-1 flex-1 rounded"
                :class="index <= recovery.remaining ? 'bg-green-500' : 'bg-gray-200 dark:bg-gray-700'"
              />
            </div>

            <p class="mt-3 text-xs text-gray-400">
              {{ __('Stored hashed, so they cannot be shown to you again. Using one signs you in; it does not turn off two-factor authentication.') }}
            </p>

            <ConfirmsPassword @confirmed="regenerate">
              <Button variant="outline" state="danger" class="mt-4">{{ __('Generate new codes') }}</Button>
            </ConfirmsPassword>
          </div>
        </LoadingView>
      </Card>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Button } from 'laravel-nova-ui'
import TwoFactorCodeInput from './TwoFactorCodeInput.vue'
import TwoFactorMethodRow from './TwoFactorMethodRow.vue'
import TwoFactorQrCode from './TwoFactorQrCode.vue'
import TwoFactorRecoveryCodes from './TwoFactorRecoveryCodes.vue'
import { useTwoFactorApi } from '../composables/useTwoFactorApi'
import { createCredential, describeError, isSupported } from '../support/webauthn'

defineOptions({ name: 'UserSecurityTwoFactorAuthentication' })

// Nova passes these to its own card; accepted so the replacement is a drop-in.
defineProps({
  options: { type: Object, default: () => ({}) },
  user: { type: Object, default: () => ({}) },
})

const api = useTwoFactorApi()

const loading = ref(true)
const methods = ref([])
const available = ref([])
const recovery = ref({ remaining: 0, total: 8, running_low: false })
const enforcement = ref({ mode: 'optional', applies: false, grace_ends_at: null })

const enrolling = ref(null)
const intent = ref({})
const code = ref('')
const error = ref(null)
const failures = ref(0)
const verifying = ref(false)
const waitingForDevice = ref(false)
const freshCodes = ref([])
const step = ref(1)

const appName = computed(() => Nova.config('appName') ?? 'Nova')
const account = computed(() => Nova.config('userEmail') ?? '')

const statusLabel = computed(() => {
  if (!methods.value.length) return enforcement.value.applies ? __('Action required') : __('Not set up')
  return methods.value.length > 1 ? __('Protected') : __('Protected')
})

const statusBadgeClass = computed(() =>
  methods.value.length
    ? 'n2f-badge-ok'
    : 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300'
)

const statusHeading = computed(() =>
  methods.value.length
    ? __('You have two-factor authentication on')
    : __('You have not set up two-factor authentication')
)

const statusDescription = computed(() => {
  if (!methods.value.length) {
    return __('Add a method to protect your account.')
  }

  return methods.value.length > 1
    ? __('Two methods are active. A lost phone is an inconvenience rather than a lockout.')
    : __('One method is active. Add a second so a lost device does not lock you out.')
})

const graceUrgent = ref(false)

const graceMessage = computed(() => {
  const { applies, grace_ends_at: deadline } = enforcement.value

  if (!applies || methods.value.length || !deadline) return null

  const remaining = new Date(deadline) - Date.now()

  if (remaining <= 0) return __('Two-factor authentication is now required for your account.')

  const hours = Math.floor(remaining / 3600000)
  graceUrgent.value = hours < 24

  return hours < 24
    ? __('Your organization requires two-factor authentication. :hours hours left.', { hours })
    : __('Your organization requires two-factor authentication by :date.', {
        date: new Date(deadline).toLocaleDateString(),
      })
})

const refresh = async () => {
  const data = await api.fetchOverview()
  methods.value = data.methods
  available.value = data.available.filter(
    driver => !methods.value.some(m => m.type === driver.type && driver.type !== 'webauthn')
  )
  recovery.value = data.recovery_codes
  enforcement.value = data.enforcement
  loading.value = false
}

const resetEnrollment = () => {
  enrolling.value = null
  intent.value = {}
  code.value = ''
  error.value = null
  failures.value = 0
  step.value = 1
  waitingForDevice.value = false
}

const startEnrollment = async type => {
  resetEnrollment()

  if (type === 'webauthn' && !isSupported()) {
    Nova.error(__('This browser cannot use passkeys. Try Chrome, Safari, or Edge.'))
    return
  }

  enrolling.value = type

  try {
    intent.value = await api.beginEnrollment({ type })
    step.value = 2
    if (type === 'webauthn') await runPasskeyEnrollment()
  } catch (e) {
    error.value = e.response?.data?.message ?? __('That method could not be set up.')
  }
}

const runPasskeyEnrollment = async () => {
  error.value = null
  waitingForDevice.value = true

  try {
    const credential = await createCredential(intent.value.public_key)
    await finish({ type: 'webauthn', credential })
  } catch (e) {
    const message = describeError(e)
    if (message) error.value = message
  } finally {
    waitingForDevice.value = false
  }
}

const confirmEnrollment = async value => {
  if (verifying.value) return
  await finish({ type: enrolling.value, code: value ?? code.value })
}

const finish = async payload => {
  verifying.value = true
  error.value = null

  try {
    const data = await api.confirmEnrollment(payload)

    // A first factor with no backup is a future lockout, so the server issues
    // recovery codes with it and we hand them straight over.
    if (data.recovery_codes) freshCodes.value = data.recovery_codes

    resetEnrollment()
    await refresh()
    Nova.success(__('Two-factor method added.'))
  } catch (e) {
    failures.value += 1
    code.value = ''
    error.value =
      e.response?.data?.errors?.code?.[0] ?? e.response?.data?.message ?? __('That code is not correct.')
  } finally {
    verifying.value = false
  }
}

const cancelEnrollment = () => resetEnrollment()

const startRename = async method => {
  const name = window.prompt(__('Name this method'), method.name)
  if (!name) return

  await api.renameMethod(method.id, name)
  await refresh()
}

// Optimistic: the radio flips at once and reverts if the write fails. Nothing
// destructive is ever optimistic.
const makeDefault = async method => {
  const previous = methods.value.map(m => ({ ...m }))
  methods.value = methods.value.map(m => ({ ...m, is_default: m.id === method.id }))

  try {
    await api.setDefaultMethod(method.id)
  } catch {
    methods.value = previous
    Nova.error(__('That could not be saved.'))
  }
}

const confirmRemove = method => {
  Nova.$emit('nova-two-factor:confirm-remove', method)

  if (!window.confirm(__('Remove :name? You will have :count methods left.', {
    name: method.name,
    count: methods.value.length - 1,
  }))) {
    return
  }

  remove(method)
}

const remove = async method => {
  try {
    await api.removeMethod(method.id)
    await refresh()
    Nova.success(__('Method removed.'))
  } catch (e) {
    Nova.error(
      e.response?.data?.errors?.method?.[0] ?? e.response?.data?.message ?? __('That could not be removed.')
    )
  }
}

const regenerate = async () => {
  const data = await api.regenerateRecoveryCodes()
  freshCodes.value = data.codes
  await refresh()
}

onMounted(() => {
  refresh()
  Nova.$on('nova-two-factor:changed', refresh)
})
</script>
