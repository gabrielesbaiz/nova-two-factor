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

import TwoFactorSecurityCard from './components/TwoFactorSecurityCard.vue'
import StepUpModal from './components/StepUpModal.vue'
import { installStepUpInterceptor } from './support/step-up'

Nova.booting(app => {
  // `boot()` runs booting callbacks before `registerViews()`, and
  // `Nova.component()` is a no-op for an already-registered name — so
  // registering here wins permanently over Nova's own component.
  app.component('UserSecurityTwoFactorAuthentication', TwoFactorSecurityCard)
  app.component('NovaTwoFactorStepUpModal', StepUpModal)

  installStepUpInterceptor(Nova)
})
