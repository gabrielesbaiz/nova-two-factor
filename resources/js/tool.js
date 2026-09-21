/**
 * Runs inside Nova's SPA.
 *
 * Note what this file does *not* do: register Inertia pages for the primary
 * screens. Nova resolves the initial Inertia component inside `Nova.countdown()`,
 * which runs before any tool script executes, so `Nova.inertia()` always lands
 * too late for a cold page load. Instead the management UI replaces Nova's own
 * globally registered security card, which lives on a page Nova itself resolves.
 */
import '../css/tool.css'

import { createApp, h } from 'vue'

import TwoFactorComplianceCard from './components/TwoFactorComplianceCard.vue'
import TwoFactorSecurityCard from './components/TwoFactorSecurityCard.vue'
import TwoFactorSettingsCard from './components/TwoFactorSettingsCard.vue'
import StepUpModal from './components/StepUpModal.vue'
import { installStepUpInterceptor } from './support/step-up'

Nova.booting((app) => {
  // `boot()` runs booting callbacks before `registerViews()`, and
  // `Nova.component()` is a no-op for an already-registered name — so
  // registering here wins permanently over Nova's own component.
  app.component('UserSecurityTwoFactorAuthentication', TwoFactorSecurityCard)
  app.component('NovaTwoFactorStepUpModal', StepUpModal)

  // Dashboard cards are resolved by name at render time, on a page Nova itself
  // owns — so unlike a tool-registered Inertia page, this one survives a cold
  // load and a bookmarked URL.
  app.component('nova-two-factor-compliance', TwoFactorComplianceCard)
  app.component('nova-two-factor-settings', TwoFactorSettingsCard)

  installStepUpInterceptor(Nova)
})

/**
 * The step-up prompt has to exist somewhere in the DOM, and no page renders it:
 * it answers a 423 that can arrive from any screen, including ones this package
 * knows nothing about. Registering the component was not enough — a component
 * nothing mounts never appears, so every protected action used to hang on a
 * request that had already been refused.
 *
 * Mounted after Nova's own app, in a node of its own, with Nova's component
 * registry, mixins and globals shared into it — that is what lets the modal keep
 * using Nova's `Modal`, `Heading` and `HelpText` rather than shipping a second
 * copy of the design system.
 */
Nova.booted((app) => {
  const host = document.createElement('div')
  host.id = 'nova-two-factor-step-up'
  document.body.appendChild(host)

  const modal = createApp({ render: () => h(StepUpModal) })

  modal._context.components = app._context.components
  modal._context.directives = app._context.directives
  modal._context.provides = app._context.provides
  modal._context.mixins = app._context.mixins
  modal.config.globalProperties = app.config.globalProperties

  modal.mount(host)
})
