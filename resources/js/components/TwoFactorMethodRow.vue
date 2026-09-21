<template>
  <div class="flex items-center gap-4 py-3">
    <span class="n2f-method-icon" aria-hidden="true">
      <Icon :name="method.icon" type="micro" />
    </span>

    <!-- ── Renaming ──────────────────────────────────────────────────────
         In place, with the name already in the field. A `window.prompt` is a
         system dialog with no styling, no validation and no cancel that means
         anything — and it hides the one piece of this row a user actually
         owns. -->
    <form v-if="renaming" class="flex flex-1 items-center gap-2" @submit.prevent="saveName">
      <input
        ref="nameInput"
        v-model="draft"
        type="text"
        maxlength="100"
        class="form-control form-input form-control-bordered w-full text-sm"
        :aria-label="__('Name this method')"
        @keydown.esc="cancelRename"
      />

      <Button type="submit" variant="solid" size="small" :disabled="!draft.trim()">
        {{ __('Save') }}
      </Button>
      <Button type="button" variant="outline" size="small" @click="cancelRename">
        {{ __('Cancel') }}
      </Button>
    </form>

    <!-- ── Confirming removal ────────────────────────────────────────────
         The question stays on the row it is about, rather than in a browser
         dialog that names nothing and looks like every other alert. -->
    <div v-else-if="confirmingRemoval" class="flex flex-1 flex-wrap items-center gap-2">
      <p class="flex-1 text-sm text-gray-600 dark:text-gray-400">
        {{ __('Remove :name?', { name: method.name }) }}
      </p>

      <!-- Password-confirmed, like every other destructive or enrolling action
           on this card. Without it the request reached a route guarded by
           `RequirePassword` and came back 423 with a bare JSON message, which
           the card could only show as an error — the modal that would have
           unblocked it was never opened. -->
      <ConfirmsPassword @confirmed="emit('remove', method)">
        <Button size="small" state="danger">
          {{ __('Remove') }}
        </Button>
      </ConfirmsPassword>
      <Button variant="outline" size="small" @click="confirmingRemoval = false">
        {{ __('Cancel') }}
      </Button>
    </div>

    <template v-else>
      <div class="flex-1 min-w-0">
        <p class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate">
          {{ method.name }}
        </p>

        <p class="text-[11.5px] leading-tight text-gray-400">
          {{ method.label }}
          <template v-if="method.destination_hint">
            &middot; {{ method.destination_hint }}</template
          >
          <!-- A synced passkey exists on the user's other devices; a device-bound
               one does not. That is a real distinction, so it is stated. -->
          <template v-if="method.synced === true"> &middot; {{ __('Synced') }}</template>
          <template v-else-if="method.synced === false">
            &middot; {{ __('This device only') }}</template
          >
          <!-- When it was added answers "which key is this?" for someone looking
               at two passkeys with similar names. -->
          <template v-if="method.created_at">
            &middot;
            <span :title="method.created_at">
              {{ __('added :time', { time: relative(method.created_at) }) }}
            </span>
          </template>
          &middot;
          <!-- "Never used" is the honest signal that a method is unproven, and it
               reads as such without having to say so. Relative on screen,
               absolute in the title, because "3 hours ago" is not evidence. -->
          <span v-if="method.last_used_at" :title="method.last_used_at">
            {{ __('last used :time', { time: relative(method.last_used_at) }) }}
          </span>
          <span v-else class="italic">{{ __('never used') }}</span>
        </p>
      </div>

      <span v-if="method.is_default" class="n2f-badge n2f-badge-neutral">{{ __('Default') }}</span>

      <!-- The controls are one group, set apart from the badge: a badge states
           what this row *is*, the buttons change it, and running all three at
           one spacing made the badge read as a third button. -->
      <div class="flex items-center gap-2 ms-2">
        <!-- Rename is one click, not two: it is the action a user comes to this
             row for, and burying it in a menu is what made it feel absent. Same
             outline button as "Aggiungi" on the rows below, so the two read as
             the same kind of control. -->
        <Button variant="outline" size="small" @click="startRename">{{ __('Rename') }}</Button>

        <Dropdown>
          <!-- A bordered button like the one next to it, not bare text: the
               remaining actions are as real as "Rinomina", and a floating
               ellipsis reads as decoration until someone hovers it. -->
          <DropdownTrigger
            as="button"
            type="button"
            class="n2f-row-menu"
            :aria-label="__('Actions for :name', { name: method.name })"
          >
            &hellip;
          </DropdownTrigger>

          <template #menu>
            <DropdownMenu width="220">
              <DropdownMenuItem
                v-if="!method.is_default"
                as="button"
                @click="emit('default', method)"
              >
                {{ __('Make default') }}
              </DropdownMenuItem>
              <DropdownMenuItem as="button" class="text-red-500" @click="confirmingRemoval = true">
                {{ __('Remove') }}
              </DropdownMenuItem>
            </DropdownMenu>
          </template>
        </Dropdown>
      </div>
    </template>
  </div>
</template>

<script setup>
import { nextTick, ref } from 'vue'
import { Button, Icon } from 'laravel-nova-ui'
import { __ } from '../support/translate'

defineOptions({ name: 'TwoFactorMethodRow' })

const props = defineProps({
  method: { type: Object, required: true },
})

const emit = defineEmits(['rename', 'default', 'remove'])

const renaming = ref(false)
const confirmingRemoval = ref(false)
const draft = ref('')
const nameInput = ref(null)

const startRename = async () => {
  confirmingRemoval.value = false
  draft.value = props.method.name
  renaming.value = true

  await nextTick()
  nameInput.value?.select()
}

const cancelRename = () => {
  renaming.value = false
}

const saveName = () => {
  const name = draft.value.trim()

  if (!name || name === props.method.name) {
    renaming.value = false

    return
  }

  emit('rename', props.method, name)
  renaming.value = false
}

/** Localised relative time, rather than a hand-rolled "3 hours ago". */
const relative = (iso) => {
  const formatter = new Intl.RelativeTimeFormat(Nova.config('locale') ?? 'en', { numeric: 'auto' })
  const seconds = (new Date(iso) - Date.now()) / 1000

  const units = [
    ['year', 31536000],
    ['month', 2592000],
    ['day', 86400],
    ['hour', 3600],
    ['minute', 60],
  ]

  for (const [unit, size] of units) {
    if (Math.abs(seconds) >= size) return formatter.format(Math.round(seconds / size), unit)
  }

  return formatter.format(Math.round(seconds), 'second')
}
</script>
