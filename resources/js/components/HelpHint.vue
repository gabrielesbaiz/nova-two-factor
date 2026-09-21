<template>
  <!-- `aria-label` rather than visible text: the glyph is a question mark, and
       "?" read aloud is not a label. `type="button"` because this sits inside
       forms elsewhere, where a bare <button> submits them. -->
  <button
    ref="trigger"
    type="button"
    class="n2f-help"
    :aria-label="__('What is this?')"
    :aria-describedby="open ? id : undefined"
    @mouseenter="show"
    @mouseleave="hide"
    @focus="show"
    @blur="hide"
    @click.prevent
  >
    ?
  </button>

  <!-- Teleported to the body and positioned fixed.
       Inside the card it was clipped: the tile sits in a scroll container, and
       an absolutely positioned tooltip is cropped by the first ancestor that
       hides its overflow — which is how half the sentence went missing off the
       left edge. -->
  <Teleport to="body">
    <span v-if="open" :id="id" ref="bubble" role="tooltip" class="n2f-help-text" :style="position">
      {{ text }}
    </span>
  </Teleport>
</template>

<script setup>
import { nextTick, ref } from 'vue'
import { __ } from '../support/translate'

defineOptions({ name: 'NovaTwoFactorHelpHint' })

defineProps({
  text: { type: String, required: true },
})

// Unique per instance: `aria-describedby` points at an id, and a page with a
// dozen hints sharing one would describe every figure with the first tooltip.
const id = `n2f-hint-${Math.random().toString(36).slice(2, 9)}`

const trigger = ref(null)
const bubble = ref(null)
const open = ref(false)
const position = ref({})

const MARGIN = 8

const show = async () => {
  open.value = true

  await nextTick()

  const anchor = trigger.value?.getBoundingClientRect()
  const tip = bubble.value?.getBoundingClientRect()

  if (!anchor || !tip) return

  // Right-aligned to the button, then pulled back inside the viewport rather
  // than allowed to run off it — the tiles at either end of the row are exactly
  // where a fixed offset breaks.
  const left = Math.min(
    Math.max(MARGIN, anchor.right - tip.width),
    window.innerWidth - tip.width - MARGIN,
  )

  // Below by default, above when there is no room — the last row of tiles sits
  // near the bottom of the page.
  const below = anchor.bottom + 6
  const fits = below + tip.height < window.innerHeight - MARGIN

  position.value = {
    left: `${Math.round(left)}px`,
    top: fits ? `${Math.round(below)}px` : `${Math.round(anchor.top - tip.height - 6)}px`,
  }
}

const hide = () => {
  open.value = false
  position.value = {}
}
</script>
