<template>
  <div id="two-factor" class="mt-10 sm:mt-0 mb-6">
    <LoadingView :loading="loading">
      <div class="flex flex-col gap-4">
        <!-- Nova's own section heading, borrowed rather than invented: `Heading`
             level 3 is what its pages use above a card, so this sits under the
             page title at the weight the rest of the panel expects. The badge
             rides the same line — status is part of the heading, not a note
             under it. -->
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
          <Heading :level="3" v-text="__('Two-factor authentication')" />
          <span class="n2f-badge" :class="statusBadgeClass">{{ statusLabel }}</span>
        </div>

        <!-- Grace-period banner. Turns urgent as the deadline approaches, and
             the deadline itself is absolute, not a vague "soon". -->
        <div
          v-if="graceMessage"
          class="rounded-lg px-4 py-3 text-sm"
          :class="
            graceUrgent
              ? 'bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300'
              : 'bg-yellow-50 dark:bg-yellow-900/30 text-yellow-700 dark:text-yellow-300'
          "
          role="status"
        >
          {{ graceMessage }}
        </div>

        <!-- ── One decision per view: while a ceremony is running, or codes are
             waiting to be saved, the overview steps out of the way. ───────── -->
        <template v-if="!enrolling && !freshCodes.length">
          <!-- ── Enrolled methods, and the ones still on offer ───────────── -->
          <Card class="px-4 py-1">
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
              <TwoFactorMethodRow
                v-for="method in methods"
                :key="method.id"
                :method="method"
                @rename="rename"
                @default="makeDefault"
                @remove="confirmRemove"
              />

              <!-- A factor you have not set up is still worth naming: the gap is
                   the point of the list. -->
              <div
                v-for="driver in unconfigured"
                :key="driver.type"
                class="flex items-center gap-4 py-3"
              >
                <span class="n2f-method-icon" aria-hidden="true">
                  <Icon :name="driver.icon" type="micro" />
                </span>

                <div class="flex-1 min-w-0">
                  <p class="text-sm font-bold text-gray-400 truncate">{{ driver.label }}</p>
                  <p class="text-[11.5px] leading-tight text-gray-400">{{ driver.note }}</p>
                </div>

                <ConfirmsPassword @confirmed="startEnrollment(driver.type)">
                  <Button variant="outline" size="small" :dusk="`add-${driver.type}`">
                    {{ __('Add') }}
                  </Button>
                </ConfirmsPassword>
              </div>
            </div>
          </Card>

          <!-- ── Recovery codes are a row of their own, not a footnote ────── -->
          <Card v-if="methods.length" class="p-4">
            <div class="flex items-baseline gap-2">
              <p
                class="flex-1 text-sm font-bold text-gray-900 dark:text-gray-100"
                v-text="__('Recovery codes')"
              />
              <span
                class="text-xs"
                :class="
                  recovery.running_low ? 'text-yellow-600 dark:text-yellow-400' : 'text-gray-400'
                "
              >
                {{ __(':remaining of :total remaining', recovery) }}
              </span>
            </div>

            <div class="n2f-ticks mt-2.5" :data-low="recovery.running_low" aria-hidden="true">
              <span
                v-for="index in recovery.total"
                :key="index"
                class="n2f-tick"
                :data-used="index > recovery.remaining"
              />
            </div>

            <p class="mt-2 text-[11.5px] leading-tight text-gray-400">
              {{
                __(
                  'Use one if you cannot get to your usual method. Each works a single time, and we cannot show them to you again — so keep them somewhere safe.',
                )
              }}
            </p>

            <!-- Regeneration is offered where the shortage is visible, rather
                 than parked permanently next to codes that are fine. -->
            <ConfirmsPassword v-if="recovery.running_low" @confirmed="regenerate">
              <Button variant="outline" state="danger" size="small" class="mt-3">
                {{ __('Generate new codes') }}
              </Button>
            </ConfirmsPassword>
          </Card>
        </template>

        <!-- ── TOTP enrollment ─────────────────────────────────────────── -->
        <Card v-if="enrolling === 'totp'" class="p-6">
          <div class="flex items-center gap-2 mb-6 text-xs font-bold uppercase tracking-wide">
            <span :class="step === 1 ? 'text-primary-500' : 'text-gray-400'">{{
              __('1 Scan')
            }}</span>
            <hr class="flex-1 n2f-divider" />
            <span :class="step === 2 ? 'text-primary-500' : 'text-gray-400'">{{
              __('2 Verify')
            }}</span>
          </div>

          <div class="flex flex-col sm:flex-row gap-6">
            <TwoFactorQrCode
              :svg="intent.qr_code"
              :secret="intent.secret"
              :secret-groups="intent.secret_groups"
              :uri="intent.uri"
            />

            <div class="flex-1">
              <p class="mb-3 text-sm">
                {{ __('Then enter the six-digit code your app shows.') }}
              </p>

              <TwoFactorCodeInput
                v-model="code"
                :invalid="Boolean(error)"
                @complete="confirmEnrollment"
              />

              <HelpText v-if="error" class="mt-2 text-red-500" role="alert">{{ error }}</HelpText>

              <!-- Two failures is where clock skew becomes the likely cause,
                   and repeating "invalid code" a third time does not help. -->
              <HelpText v-if="failures >= 2" class="mt-2">
                {{
                  __(
                    "Codes rotate every 30 seconds. If this keeps failing, check that your phone's clock is set automatically.",
                  )
                }}
              </HelpText>

              <div class="mt-4 flex gap-2">
                <Button :loading="verifying" @click="confirmEnrollment(code)">{{
                  __('Turn on')
                }}</Button>
                <Button variant="ghost" @click="cancelEnrollment">{{ __('Cancel') }}</Button>
              </div>
            </div>
          </div>
        </Card>

        <!-- ── Passkey enrollment ──────────────────────────────────────── -->
        <Card v-if="enrolling === 'webauthn'" class="p-6 text-center">
          <p class="mb-4 text-sm">
            {{
              waitingForDevice
                ? __('Waiting for your device…')
                : __('Your browser will ask for your fingerprint, face, or security key.')
            }}
          </p>
          <Loader v-if="waitingForDevice" class="mx-auto text-primary-500" />
          <HelpText v-if="error" class="mt-3 text-red-500" role="alert">{{ error }}</HelpText>
          <Button v-if="!waitingForDevice" class="mt-2" @click="runPasskeyEnrollment">
            {{ __('Try again') }}
          </Button>
          <Button variant="ghost" class="mt-2 ms-2" @click="cancelEnrollment">{{
            __('Cancel')
          }}</Button>
        </Card>

        <!-- ── Email enrollment ────────────────────────────────────────── -->
        <Card v-if="enrolling === 'email'" class="p-6">
          <p class="mb-3 text-sm">
            {{ __('We sent a code to :destination.', { destination: intent.destination_hint }) }}
          </p>
          <TwoFactorCodeInput
            v-model="code"
            :invalid="Boolean(error)"
            @complete="confirmEnrollment"
          />
          <HelpText v-if="error" class="mt-2 text-red-500" role="alert">{{ error }}</HelpText>
          <HelpText class="mt-2">
            {{
              __(
                'Email codes are weaker than an authenticator app or a passkey — anyone with your inbox has your second factor.',
              )
            }}
          </HelpText>
          <div class="mt-4 flex gap-2">
            <Button :loading="verifying" @click="confirmEnrollment(code)">{{
              __('Confirm')
            }}</Button>
            <Button variant="ghost" @click="cancelEnrollment">{{ __('Cancel') }}</Button>
          </div>
        </Card>

        <!-- ── Fresh recovery codes: the only time they can be shown ────── -->
        <Card v-if="freshCodes.length" class="p-6">
          <Heading :level="4" class="text-base mb-3" v-text="__('Save your recovery codes')" />
          <TwoFactorRecoveryCodes
            :codes="freshCodes"
            :app-name="appName"
            :account="account"
            @acknowledged="freshCodes = []"
          />
        </Card>
      </div>
    </LoadingView>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Button, Icon } from 'laravel-nova-ui'
import TwoFactorCodeInput from './TwoFactorCodeInput.vue'
import TwoFactorMethodRow from './TwoFactorMethodRow.vue'
import TwoFactorQrCode from './TwoFactorQrCode.vue'
import TwoFactorRecoveryCodes from './TwoFactorRecoveryCodes.vue'
import { useTwoFactorApi } from '../composables/useTwoFactorApi'
import { createCredential, describeError, isSupported } from '../support/webauthn'
import { __ } from '../support/translate'

defineOptions({ name: 'UserSecurityTwoFactorAuthentication' })

// Nova passes these to its own card; accepted so the replacement is a drop-in.
defineProps({
  options: { type: Object, default: () => ({}) },
  user: { type: Object, default: () => ({}) },
})

const api = useTwoFactorApi()

const loading = ref(true)
const methods = ref([])
const drivers = ref([])
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

/**
 * The rows under the enrolled ones: factors to set up, plus another passkey.
 *
 * A second passkey is a normal thing to own — a laptop and a security key, or a
 * phone and a backup — so it is offered as its own row rather than hidden
 * behind a generic "add a method" button that happened to target it.
 */
const unconfigured = computed(() =>
  drivers.value
    .filter(
      (driver) =>
        driver.phishing_resistant || !methods.value.some((method) => method.type === driver.type),
    )
    .map((driver) => {
      const enrolled = methods.value.some((method) => method.type === driver.type)

      return {
        ...driver,
        enrolled,
        label: enrolled ? __('Another passkey') : driver.label,
        note: enrolled ? __('For a second device, or a backup key.') : __('Not set up'),
      }
    }),
)

const statusLabel = computed(() => {
  if (methods.value.length) return __('Protected')
  return enforcement.value.applies ? __('Action required') : __('Not set up')
})

const statusBadgeClass = computed(() => {
  if (methods.value.length) return 'n2f-badge-ok'
  return enforcement.value.applies ? 'n2f-badge-warn' : 'n2f-badge-neutral'
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
  drivers.value = data.available
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

const startEnrollment = async (type) => {
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

const confirmEnrollment = async (value) => {
  if (verifying.value) return
  await finish({ type: enrolling.value, code: value ?? code.value })
}

const finish = async (payload) => {
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
      e.response?.data?.errors?.code?.[0] ??
      e.response?.data?.message ??
      __('That code is not correct.')
  } finally {
    verifying.value = false
  }
}

const cancelEnrollment = () => resetEnrollment()

/**
 * Optimistic, like the default switch: the row shows the new name immediately
 * and puts the old one back if the write fails. Renaming a key is not a
 * destructive act, and a round trip before the text changes feels broken.
 */
const rename = async (method, name) => {
  const previous = method.name

  methods.value = methods.value.map((m) => (m.id === method.id ? { ...m, name } : m))

  try {
    await api.renameMethod(method.id, name)
  } catch {
    methods.value = methods.value.map((m) => (m.id === method.id ? { ...m, name: previous } : m))
    Nova.error(__('That could not be saved.'))
  }
}

// Optimistic: the radio flips at once and reverts if the write fails. Nothing
// destructive is ever optimistic.
const makeDefault = async (method) => {
  const previous = methods.value.map((m) => ({ ...m }))
  methods.value = methods.value.map((m) => ({ ...m, is_default: m.id === method.id }))

  try {
    await api.setDefaultMethod(method.id)
  } catch {
    methods.value = previous
    Nova.error(__('That could not be saved.'))
  }
}

// The confirmation lives on the row now — it can name the method and say what
// is left, where a browser dialog names nothing.
const confirmRemove = (method) => {
  Nova.$emit('nova-two-factor:confirm-remove', method)

  remove(method)
}

const remove = async (method) => {
  try {
    await api.removeMethod(method.id)
    await refresh()
    Nova.success(__('Method removed.'))
  } catch (e) {
    Nova.error(
      e.response?.data?.errors?.method?.[0] ??
        e.response?.data?.message ??
        __('That could not be removed.'),
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
