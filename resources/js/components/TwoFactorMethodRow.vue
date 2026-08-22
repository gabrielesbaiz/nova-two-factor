<template>
  <div class="flex items-center gap-3 py-3">
    <span
      class="w-9 h-9 rounded-lg grid place-items-center flex-none bg-gray-100 dark:bg-gray-900 text-gray-500"
      aria-hidden="true"
    >
      <Icon :name="method.icon" type="micro" />
    </span>

    <div class="flex-1 min-w-0">
      <div class="flex items-center gap-2">
        <p class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate">{{ method.name }}</p>

        <span v-if="method.is_default" class="n2f-badge n2f-badge-ok">{{ __('Default') }}</span>

        <!-- A synced passkey exists on the user's other devices; a device-bound
             one does not. That is a real distinction, so it is stated. -->
        <span v-if="method.synced === true" class="n2f-badge n2f-badge-ok">{{ __('Synced') }}</span>
        <span v-else-if="method.synced === false" class="text-xs text-gray-400">
          {{ __('This device only') }}
        </span>
      </div>

      <p class="text-xs text-gray-400">
        {{ method.label }}
        <template v-if="method.destination_hint"> &middot; {{ method.destination_hint }}</template>
        &middot;
        <!-- "Never used" is the honest signal that a method is unproven, and it
             reads as such without having to say so. -->
        <span v-if="method.last_used_at" :title="method.last_used_at">
          {{ __('Last used :time', { time: relative(method.last_used_at) }) }}
        </span>
        <span v-else class="italic">{{ __('Never used') }}</span>
      </p>
    </div>

    <Dropdown>
      <DropdownTrigger class="text-gray-400 px-2" :aria-label="__('Actions for :name', { name: method.name })">
        &hellip;
      </DropdownTrigger>

      <template #menu>
        <DropdownMenu width="220">
          <DropdownMenuItem as="button" @click="emit('rename', method)">{{ __('Rename') }}</DropdownMenuItem>
          <DropdownMenuItem v-if="!method.is_default" as="button" @click="emit('default', method)">
            {{ __('Make default') }}
          </DropdownMenuItem>
          <DropdownMenuItem as="button" class="text-red-500" @click="emit('remove', method)">
            {{ __('Remove') }}
          </DropdownMenuItem>
        </DropdownMenu>
      </template>
    </Dropdown>
  </div>
</template>

<script setup>
import { Icon } from 'laravel-nova-ui'

defineOptions({ name: 'TwoFactorMethodRow' })

defineProps({
  method: { type: Object, required: true },
})

const emit = defineEmits(['rename', 'default', 'remove'])

/** Localised relative time, rather than a hand-rolled "3 hours ago". */
const relative = iso => {
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
