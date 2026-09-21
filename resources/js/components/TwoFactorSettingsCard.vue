<template>
  <Card class="n2f-compliance p-6 flex flex-col gap-6">
    <!-- ── State ───────────────────────────────────────────────────────
         Three states, not two: active, paused (nobody challenged, this page
         still works), and off — which only an environment variable can do. -->
    <div class="flex items-center gap-3 flex-wrap">
      <span class="n2f-state" :class="stateClass">
        <span class="n2f-state-dot" />
        {{ stateLabel }}
      </span>

      <span v-if="!editable" class="n2f-mode">
        {{ __('Read-only — changing settings from Nova is turned off.') }}
      </span>
    </div>

    <!-- ── Paused banner ───────────────────────────────────────────────
         Loud, attributed, and it ends by itself. A pause you can only discover
         by visiting this page is a pause somebody forgets. -->
    <div v-if="pause.until" class="n2f-banner">
      <span class="n2f-banner-icon" aria-hidden="true">
        <Icon name="exclamation-triangle" type="micro" />
      </span>
      <div class="flex-1 min-w-0">
        <b>{{
          __('Enforcement is paused for another :count minutes', { count: pause.minutes_left })
        }}</b>
        <p>
          {{ __('Nobody is being challenged.') }}
          <template v-if="pause.by">{{ __('Paused by :name.', { name: pause.by }) }}</template>
          <template v-if="pause.reason">“{{ pause.reason }}”</template>
          {{ __('It resumes on its own.') }}
        </p>
      </div>
      <ConfirmsPassword v-if="editable" @confirmed="resume">
        <Button :loading="busy">{{ __('Resume now') }}</Button>
      </ConfirmsPassword>
    </div>

    <!-- ── Pause ───────────────────────────────────────────────────────── -->
    <div v-else-if="editable" class="n2f-panel flex flex-wrap items-start gap-4">
      <div class="flex-1 min-w-[18rem]">
        <p class="font-bold text-sm">{{ __('Pause enforcement') }}</p>
        <p class="n2f-tile-foot">
          {{
            __(
              'Nobody is challenged and nobody is blocked, while this page keeps working — so you can change the rules without locking yourself out of the panel you are changing them from.',
            )
          }}
        </p>
      </div>
      <Button variant="outline" @click="pausing = true">{{ __('Pause for a while…') }}</Button>
    </div>

    <!-- ── Sections ───────────────────────────────────────────────────── -->
    <!-- A section whose every field is irrelevant in this mode disappears with
         them, rather than leaving an empty titled box. -->
    <div
      v-for="(fields, name) in sections"
      v-show="relevant(fields).length"
      :key="name"
      class="n2f-panel"
    >
      <div class="n2f-panel-head">
        <b>{{ sectionLabel(name) }}</b>
      </div>

      <div v-for="field in relevant(fields)" :key="field.key" class="n2f-setting">
        <div class="min-w-0">
          <p class="text-sm font-bold flex items-center gap-2 flex-wrap">
            {{ label(field.key) }}
          </p>
          <p class="n2f-tile-foot">{{ help(field.key) }}</p>
        </div>

        <div class="n2f-setting-control">
          <div v-if="field.type === 'enum'" class="n2f-seg" role="group">
            <button
              v-for="option in field.options"
              :key="option"
              type="button"
              :class="{ 'n2f-seg-on': draft[field.key] === option }"
              :disabled="!editable"
              @click="draft[field.key] = option"
            >
              {{ field.option_labels?.[option] ?? option }}
            </button>
          </div>

          <label v-else-if="field.type === 'bool'" class="flex items-center gap-2">
            <input
              v-model="draft[field.key]"
              type="checkbox"
              class="form-checkbox"
              :disabled="!editable"
            />
            <span class="text-sm">{{ draft[field.key] ? __('On') : __('Off') }}</span>
          </label>

          <input
            v-else-if="field.type === 'int'"
            v-model.number="draft[field.key]"
            type="number"
            :min="field.min"
            :max="field.max"
            :disabled="!editable"
            class="form-control form-input form-control-bordered w-28 text-sm"
          />

          <input
            v-else
            v-model="draft[field.key]"
            type="date"
            :disabled="!editable"
            class="form-control form-input form-control-bordered text-sm"
          />
        </div>
      </div>

      <!-- The sentence that has to appear before the save, not after it: on a
           panel where most administrators have no second factor, the obvious
           click is the one that locks out the person making it. -->
      <div v-if="name === 'methods' && noMethods" class="n2f-impact">
        <b>{{ __('At least one method has to stay switched on.') }}</b>
        <p>
          {{
            __(
              'Turning them all off does not switch two-factor off — it leaves the policy in force with nothing anyone can enrol.',
            )
          }}
        </p>
      </div>

      <div v-if="name === 'enforcement' && blocking" class="n2f-impact">
        <b>
          {{
            __('Switching to Required would block :count of :total administrators', {
              count: impact.without_factor,
              total: impact.in_scope,
            })
          }}
        </b>
        <p>
          {{
            impact.grace_days > 0
              ? __(
                  'They have no confirmed method. With the grace window at :days days they keep access until it closes; at zero they are locked out at their next request.',
                  { days: draft['enforcement.grace_days'] ?? impact.grace_days },
                )
              : __(
                  'They have no confirmed method and there is no grace window — they are locked out at their next request. Send them a reminder first.',
                )
          }}
        </p>
      </div>
    </div>

    <div v-if="editable" class="flex items-center gap-3 flex-wrap">
      <span class="n2f-tile-foot flex-1" :class="{ 'n2f-text-bad': noMethods }">
        {{
          noMethods
            ? __('At least one method has to stay switched on.')
            : overriddenKeys.length
              ? changedNotice
              : __('Saving asks for your password, and writes an audit row.')
        }}
      </span>
      <Button variant="ghost" :disabled="!dirty" @click="reset">{{ __('Discard') }}</Button>

      <!-- One button rather than a chip per field: an administrator who wants
           the deployment's values back wants all of them, and hunting for
           badges to click is not a way to ask for that. -->
      <ConfirmsPassword v-if="overriddenKeys.length" @confirmed="restoreDefaults">
        <Button variant="outline" :loading="busy">
          {{ __('Restore defaults') }}
        </Button>
      </ConfirmsPassword>

      <!-- Wrapped, not just posted. These routes sit behind `RequirePassword`,
           which answers 423 rather than running the controller — and nothing in
           Nova opens the password modal on its own. Without this wrapper the
           button posted, got 423, and appeared to do nothing at all. -->
      <ConfirmsPassword @confirmed="save">
        <Button :loading="busy" :disabled="!savable">{{ __('Save changes') }}</Button>
      </ConfirmsPassword>
    </div>

    <!-- ── History ─────────────────────────────────────────────────────── -->
    <div v-if="history.length" class="n2f-panel">
      <div class="n2f-panel-head">
        <b>{{ __('Recent changes') }}</b>
        <a v-if="historyUrl" :href="historyUrl" class="n2f-see-all">{{ __('See all') }} &rarr;</a>
      </div>
      <ul class="n2f-events">
        <li v-for="(entry, index) in history" :key="index">
          <span>{{ describe(entry) }}</span>
          <time :datetime="entry.at" :title="entry.at">{{ relative(entry.at) }}</time>
        </li>
      </ul>
    </div>

    <!-- ── Pause dialog ────────────────────────────────────────────────── -->
    <Modal :show="pausing" role="dialog" size="sm" @close-via-escape="pausing = false">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6">
        <Heading :level="4" class="text-base" v-text="__('Pause two-factor enforcement')" />
        <p class="mt-2 text-sm">
          {{
            __(
              'Nobody will be challenged or blocked while this is paused. Existing sessions keep working; new sign-ins skip the second factor entirely.',
            )
          }}
        </p>

        <div class="n2f-seg mt-4" role="group">
          <button
            v-for="option in durations"
            :key="option"
            type="button"
            :class="{ 'n2f-seg-on': minutes === option }"
            @click="minutes = option"
          >
            {{ duration(option) }}
          </button>
        </div>

        <label class="block text-sm font-bold mt-4 mb-1">{{ __('Why are you pausing?') }}</label>
        <textarea
          v-model="reason"
          rows="2"
          class="form-control form-input form-control-bordered w-full text-sm"
        />
        <HelpText class="mt-1">
          {{ __('Recorded in the audit log, and shown to whoever sees the pause.') }}
        </HelpText>

        <div class="mt-6 flex justify-end gap-2">
          <Button type="button" variant="ghost" @click="pausing = false">{{ __('Cancel') }}</Button>
          <ConfirmsPassword @confirmed="startPause">
            <Button
              type="button"
              state="danger"
              :loading="busy"
              :disabled="reason.trim().length < 3"
            >
              {{ __('Pause enforcement') }}
            </Button>
          </ConfirmsPassword>
        </div>
      </div>
    </Modal>
  </Card>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { Button, Icon } from 'laravel-nova-ui'
import { __ } from '../support/translate'

defineOptions({ name: 'NovaTwoFactorSettings' })

const props = defineProps({
  card: { type: Object, default: () => ({}) },
})

const editable = ref(false)
const enabled = ref(true)
const sections = ref({})
const impact = ref({ in_scope: 0, without_factor: 0, grace_days: 0, mode: 'optional' })
const history = ref([])
const pause = ref({ until: null, by: null, reason: null, minutes_left: null })
const maxMinutes = ref(120)
const historyUrl = ref(null)
const busy = ref(false)

// The edit buffer, separate from what the server said. Saving sends only what
// actually moved, so two administrators working on different sections do not
// overwrite each other with values they never touched.
const draft = ref({})
const original = ref({})

const endpoint = () => props.card?.endpoint ?? '/two-factor/settings'

const load = async () => {
  const { data } = await Nova.request().get(endpoint())

  editable.value = data.editable
  enabled.value = data.enabled
  sections.value = data.sections
  impact.value = data.impact
  history.value = data.history
  pause.value = data.pause
  maxMinutes.value = data.pause_max_minutes
  historyUrl.value = data.history_url

  original.value = Object.fromEntries(
    Object.values(data.sections)
      .flat()
      .map((field) => [field.key, field.value]),
  )
  draft.value = { ...original.value }
}

const changes = computed(() =>
  Object.fromEntries(
    Object.entries(draft.value).filter(([key, value]) => value !== original.value[key]),
  ),
)

const dirty = computed(() => Object.keys(changes.value).length > 0)

// Turning off the last method does not disable two-factor: it leaves the policy
// in force with nothing to satisfy it. Under Required that locks everyone out
// of Nova, including whoever is on this page.
const methodKeys = ['methods.webauthn.enabled', 'methods.totp.enabled', 'methods.email.enabled']

const noMethods = computed(
  () =>
    methodKeys.every((key) => key in draft.value) && methodKeys.every((key) => !draft.value[key]),
)

const savable = computed(() => dirty.value && !noMethods.value)

// Everything the panel has written, as opposed to what the installation ships.
// `stored` is the server's word for "there is a row for this key".
const overriddenKeys = computed(() =>
  Object.values(sections.value)
    .flat()
    .filter((field) => field.stored)
    .map((field) => field.key),
)

// Pluralised here rather than with `trans_choice`, which is a PHP helper: the
// SPA only has the key/replacement lookup.
const changedNotice = computed(() =>
  overriddenKeys.value.length === 1
    ? __('1 setting was changed here, rather than by this installation.')
    : __(':count settings were changed here, rather than by this installation.', {
        count: overriddenKeys.value.length,
      }),
)

/**
 * Clear every stored value at once.
 *
 * Cleared rather than rewritten with today's defaults: a later deploy that
 * changes one of them should be followed again, which writing the current
 * value back would silently prevent.
 */
const restoreDefaults = async () => {
  busy.value = true

  try {
    const cleared = Object.fromEntries(overriddenKeys.value.map((key) => [key, null]))
    const { data } = await Nova.request().patch(endpoint(), { settings: cleared })

    Nova.success(data.message)
    await load()
  } catch (e) {
    if (e.response?.status !== 423) {
      Nova.error(e.response?.data?.message ?? __('That could not be saved.'))
    }
  } finally {
    busy.value = false
  }
}
const blocking = computed(() => draft.value['enforcement.mode'] === 'required')

/**
 * Only the settings the chosen mode actually uses.
 *
 * Driven by the draft rather than the saved value, so picking Required reveals
 * the grace window before saving — the two decisions belong together, and
 * making one of them invisible until after the other is committed is how a
 * policy ships with a grace window nobody set.
 */
const relevant = (fields) =>
  fields.filter((field) => {
    if (field.modes && !field.modes.includes(draft.value['enforcement.mode'])) return false

    // A setting can also depend on another: how a grace window is measured is a
    // question with no answer while grace itself is off. Compared loosely on
    // purpose — a boolean arrives as `true` from the panel and can be `1` from
    // a cast config value, and a strict compare quietly shows everything.
    return Object.entries(field.requires ?? {}).every(([key, expected]) =>
      typeof expected === 'boolean'
        ? Boolean(draft.value[key]) === expected
        : draft.value[key] === expected,
    )
  })

const reset = () => {
  draft.value = { ...original.value }
}

const save = async () => {
  busy.value = true

  try {
    const { data } = await Nova.request().patch(endpoint(), { settings: changes.value })

    sections.value = data.sections
    impact.value = data.impact
    history.value = data.history
    original.value = { ...draft.value }

    Nova.success(data.message)
  } catch (e) {
    // Only reachable if a confirmation expired between the modal and the
    // request; the wrapper above is what normally satisfies the guard.
    if (e.response?.status !== 423) {
      Nova.error(e.response?.data?.message ?? __('That could not be saved.'))
    }
  } finally {
    busy.value = false
  }
}

const pausing = ref(false)
const minutes = ref(30)
const reason = ref('')
const durations = computed(() =>
  [10, 30, 120, maxMinutes.value].filter(
    (m, i, all) => m <= maxMinutes.value && all.indexOf(m) === i,
  ),
)

const duration = (value) =>
  value < 60
    ? __(':count minutes', { count: value })
    : __(':count hours', { count: Math.round(value / 60) })

const startPause = async () => {
  busy.value = true

  try {
    const { data } = await Nova.request().post(`${endpoint()}/pause`, {
      minutes: minutes.value,
      reason: reason.value,
    })

    pause.value = data.pause
    pausing.value = false
    reason.value = ''
    Nova.success(data.message)
    await load()
  } catch (e) {
    if (e.response?.status !== 423) {
      Nova.error(e.response?.data?.message ?? __('That could not be saved.'))
    }
  } finally {
    busy.value = false
  }
}

const resume = async () => {
  busy.value = true

  try {
    const { data } = await Nova.request().post(`${endpoint()}/resume`)

    pause.value = data.pause
    Nova.success(data.message)
    await load()
  } catch (e) {
    if (e.response?.status !== 423) {
      Nova.error(e.response?.data?.message ?? __('That could not be saved.'))
    }
  } finally {
    busy.value = false
  }
}

const stateLabel = computed(() => {
  if (!enabled.value) return __('Off')

  return pause.value.until ? __('Paused') : __('Active')
})

const stateClass = computed(() => {
  if (!enabled.value) return 'n2f-state-off'

  return pause.value.until ? 'n2f-state-paused' : 'n2f-state-active'
})

const sectionLabel = (name) =>
  ({
    enforcement: __('Enforcement'),
    methods: __('Methods'),
    convenience: __('Trusted devices & step-up'),
    interface: __('Interface'),
  })[name] ?? name

const label = (key) =>
  ({
    'enforcement.mode': __('Mode'),
    'enforcement.grace_enabled': __('Grace period'),
    'enforcement.grace_mode': __('Measured as'),
    'enforcement.grace_days': __('Days from when the account was created'),
    'enforcement.enforced_from': __('Everyone must comply by'),
    'enforcement.remind_every_days': __('Remind again after'),
    'methods.webauthn.enabled': __('Passkeys'),
    'methods.totp.enabled': __('Authenticator app'),
    'methods.email.enabled': __('Email codes'),
    'methods.email.ttl': __('Email code lifetime'),
    'methods.email.resend_after': __('Resend allowed after'),
    'trusted_devices.enabled': __('Remember this device'),
    'trusted_devices.days': __('Remembered for'),
    'step_up.ttl': __('Step-up lasts'),
    'ui.show_method_tradeoffs': __('Show what each method costs'),
    'nova.menu.badge': __('Overdue count on the menu'),
  })[key] ?? key

const help = (key) =>
  ({
    'enforcement.mode': __(
      'Optional asks nobody. Encouraged prompts and can be dismissed. Required blocks Nova once the grace window closes.',
    ),
    'enforcement.grace_enabled': __(
      'Give people time before Required starts blocking. Off means the wall appears at their next request.',
    ),
    'enforcement.grace_mode': __(
      'The same runway for every new account, or one deadline everybody shares.',
    ),
    'enforcement.grace_days': __('Each account gets this long from the day it was created.'),
    'enforcement.enforced_from': __(
      'Everyone is blocked from this date, whenever their account was created.',
    ),
    'enforcement.remind_every_days': __('How long “don’t remind me” lasts under Encouraged.'),
    'methods.webauthn.enabled': __(
      'Phishing-resistant. Needs HTTPS and a relying-party id, both set at deploy time.',
    ),
    'methods.totp.enabled': __('Works offline. The safe default.'),
    'methods.email.enabled': __('Weakest option — anyone with the inbox has the second factor.'),
    'methods.email.ttl': __('Seconds. Shorter is safer and less forgiving of slow mail.'),
    'methods.email.resend_after': __('Seconds before another code can be sent.'),
    'trusted_devices.enabled': __(
      'Every grant is a browser that skips the challenge until it expires.',
    ),
    'trusted_devices.days': __('Days before a trusted browser is challenged again.'),
    'step_up.ttl': __('Seconds a fresh check covers before a protected action asks again.'),
    'ui.show_method_tradeoffs': __('The trade-off line under each factor while enrolling.'),
    'nova.menu.badge': __('Costs one pass over the user table per menu render.'),
  })[key] ?? ''

const describe = (entry) => {
  if (entry.event === 'admin.enforcement_paused') {
    return __(':name paused enforcement for :count minutes — “:reason”', {
      name: entry.by ?? __('Unknown'),
      count: entry.context?.minutes ?? '?',
      reason: entry.context?.reason ?? '',
    })
  }

  if (entry.event === 'admin.enforcement_resumed') {
    return __(':name resumed enforcement', { name: entry.by ?? __('Unknown') })
  }

  return __(':name set :key from :from to :to', {
    name: entry.by ?? __('Unknown'),
    key: label(entry.context?.key ?? ''),
    from: String(entry.context?.from ?? '—'),
    to: String(entry.context?.to ?? '—'),
  })
}

const relative = (iso) => {
  if (!iso) return ''

  const formatter = new Intl.RelativeTimeFormat(Nova.config('locale') ?? 'en', { numeric: 'auto' })
  const seconds = (new Date(iso) - Date.now()) / 1000

  for (const [unit, size] of [
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ]) {
    if (Math.abs(seconds) >= size) return formatter.format(Math.round(seconds / size), unit)
  }

  return formatter.format(Math.round(seconds), 'second')
}

onMounted(load)
</script>
