<template>
  <Card class="n2f-compliance p-6 flex flex-col gap-6">
    <!-- ── Which world are we in ───────────────────────────────────────
         "Overdue" under `encouraged` means a date passed with no consequence;
         under `required` it means locked out of Nova. A red number means two
         different things, so the mode is stated rather than implied — with the
         env var that sets it, because the next question is always "where is
         that configured?". -->
    <component
      :is="mode?.settings_url ? 'a' : 'div'"
      v-if="mode"
      class="n2f-mode"
      :class="{ 'n2f-mode-link': mode.settings_url }"
      :href="mode.settings_url"
      :title="mode.settings_url ? `${mode.summary} — ${__('Change it')}` : mode.summary"
    >
      <span class="n2f-mode-dot" :class="mode.blocks ? 'n2f-mode-blocks' : ''" />
      {{ __('Mode') }}: <b>{{ mode.label }}</b>
      <!-- Reading the mode and wanting to change it is one thought, so the chip
           is the route rather than a menu group somebody has to remember. -->
      <Icon v-if="mode.settings_url" name="cog-6-tooth" type="micro" />
    </component>

    <!-- While paused the coverage figures are still true but not currently
         acting, and a live-looking "13 overdue" invites chasing people nobody
         is blocking. The security signals below stay fully lit: an attack
         during a pause is more interesting, not less. -->
    <div v-if="paused.until" class="n2f-banner">
      <span class="n2f-banner-icon" aria-hidden="true">
        <Icon name="exclamation-triangle" type="micro" />
      </span>
      <div class="flex-1 min-w-0">
        <b>{{
          __('Enforcement is paused for another :count minutes', { count: paused.minutes_left })
        }}</b>
        <p>
          {{ __('Nobody is being challenged.') }}
          <template v-if="paused.by">{{ __('Paused by :name.', { name: paused.by }) }}</template>
        </p>
      </div>
    </div>

    <!-- ── Headline ────────────────────────────────────────────────────
         One number, not a donut of four slices: the percentage is the thing an
         administrator repeats to somebody else. The strip beside it carries the
         shape the percentage leaves out. -->
    <div class="n2f-hero">
      <div class="n2f-ring-wrap">
        <svg class="n2f-ring" viewBox="0 0 120 120" role="img" :aria-label="ringLabel">
          <circle cx="60" cy="60" r="50" fill="none" class="n2f-ring-track" stroke-width="12" />
          <circle
            cx="60"
            cy="60"
            r="50"
            fill="none"
            class="n2f-ring-value"
            stroke-width="12"
            stroke-linecap="round"
            :stroke-dasharray="`${(summary.enrolled_percent / 100) * 314} 314`"
            transform="rotate(-90 60 60)"
          />
          <text x="60" y="58" text-anchor="middle" class="n2f-ring-figure">
            {{ summary.enrolled_percent }}%
          </text>
          <text x="60" y="76" text-anchor="middle" class="n2f-ring-caption">
            {{ __('Enrolled') }}
          </text>
        </svg>

        <div class="n2f-hero-read">
          <p class="n2f-tile-label">{{ __('In scope') }}</p>
          <!-- The number alone: the label above already says what it counts,
               and "14 nel perimetro" under "NEL PERIMETRO" said it twice in
               a column too narrow for either. -->
          <p class="n2f-hero-count">{{ summary.in_scope }}</p>
          <p class="n2f-tile-foot">
            {{
              __(':covered covered · :missing with no second factor', {
                covered: summary.enrolled,
                missing: summary.not_enrolled,
              })
            }}
          </p>

          <!-- Each mode has a mechanism, and each has a number that says
               whether it is working. On its own row, because it answers a
               different question from the ring beside it. -->
          <p v-if="spotlight" class="n2f-spotlight">
            <b>{{ spotlight.value }}</b>
            <span
              >{{ spotlight.label }}<em>{{ spotlight.caption }}</em></span
            >
          </p>
        </div>
      </div>

      <div>
        <div class="n2f-strip-head">
          <b>{{ __('Coverage') }}</b>
          <span v-if="sections.grace && summary.expiring_today" class="n2f-tile-foot-warn">
            {{ __(':count expire today', { count: summary.expiring_today }) }}
          </span>
        </div>

        <!-- Every person in scope as one bar. Status colours, with the count
             beside each label, so identity never rests on colour alone. -->
        <div class="n2f-strip" role="img" :aria-label="coverageLabel">
          <i
            v-for="band in coverage"
            :key="band.key"
            :class="`n2f-strip-${band.key}`"
            :style="{ width: `${band.percent}%` }"
          />
        </div>

        <div class="n2f-legend">
          <span v-for="band in coverageAll" :key="band.key">
            <s :class="`n2f-swatch-${band.key}`" />
            {{ band.label }} <b>{{ band.count }}</b>
          </span>
        </div>
      </div>
    </div>

    <!-- ── Shape ───────────────────────────────────────────────────────── -->
    <div class="n2f-panels">
      <div class="n2f-panel">
        <div class="n2f-panel-head">
          <b>{{ __('Methods in use') }}</b>
          <em>{{ __('strongest first') }}</em>
        </div>

        <!-- Never sorted by count: sorting by size invites reading the tallest
             block as the best outcome, and that block is usually email. -->
        <div v-if="methods.length" class="n2f-bar">
          <i
            v-for="method in methods"
            :key="method.type"
            :class="`n2f-fill-${method.type}`"
            :style="{ width: `${(method.count / methodTotal) * 100}%` }"
          />
        </div>
        <p v-else class="n2f-tile-foot">{{ __('No methods yet.') }}</p>

        <div class="n2f-legend">
          <span v-for="type in ['webauthn', 'totp', 'email']" :key="type">
            <s :class="`n2f-swatch-${type}`" />
            {{ methodLabel(type) }} <b>{{ methodCount(type) }}</b>
          </span>
        </div>

        <p class="n2f-tile-foot mt-2">
          <strong :class="health.phishing_resistant_percent ? '' : 'n2f-text-bad'">
            {{
              __(':percent% phishing-resistant.', { percent: health.phishing_resistant_percent })
            }}
          </strong>
          {{
            health.phishing_resistant_percent
              ? __('Passkeys as a share of covered accounts.')
              : __('Every covered account can be reached through an inbox.')
          }}
        </p>
      </div>

      <div class="n2f-panel">
        <div class="n2f-panel-head">
          <b>{{ __('Failed attempts') }}</b>
          <em>{{ __('30 days') }}</em>
        </div>

        <!-- The shape is the signal: flat near zero with a spike is what
             credential stuffing looks like from the inside. Every day is
             plotted, zeroes included — a series that omits its empty days
             draws a straight line through the gap and hides the spike. -->
        <svg
          ref="sparkBox"
          class="n2f-spark"
          :viewBox="`0 0 ${plotWidth} 76`"
          role="img"
          :aria-label="failuresLabel"
        >
          <defs>
            <linearGradient :id="sparkId" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" class="n2f-spark-from" />
              <stop offset="100%" class="n2f-spark-to" />
            </linearGradient>
          </defs>
          <path :d="sparkArea" :fill="`url(#${sparkId})`" />
          <path :d="sparkLine" fill="none" class="n2f-spark-line" stroke-width="2" />
          <circle
            :cx="sparkLast.x"
            :cy="sparkLast.y"
            r="4"
            class="n2f-spark-dot"
            stroke-width="2"
          />
        </svg>

        <p class="n2f-tile-foot">
          {{ __('Peak :peak · :total in 30 days', { peak: failuresPeak, total: failuresTotal }) }}
        </p>
      </div>
    </div>

    <!-- Side by side: the trend is the programme, the list is the exceptions
         to it. At full width each one claimed the weight of a section, and a
         two-row list is not a section. `auto-fit` keeps the trend full width
         on its own when nobody has spent a recovery code. -->
    <div class="n2f-panels n2f-panels-fit">
      <!-- Trend, not a snapshot: the only series here that says whether the
           programme is working rather than merely growing. -->
      <div v-if="resistantTrend.length" class="n2f-panel">
        <div class="n2f-panel-head">
          <b>{{ __('Phishing-resistant share') }}</b>
          <em>{{ __('12 months') }}</em>
        </div>
        <svg class="n2f-trend" :viewBox="`0 0 ${plotWidth} 60`" role="img" :aria-label="trendLabel">
          <path :d="trendLine" fill="none" class="n2f-trend-line" stroke-width="2" />
          <circle
            :cx="trendLast.x"
            :cy="trendLast.y"
            r="4"
            class="n2f-trend-dot"
            stroke-width="2"
          />
        </svg>
        <p class="n2f-tile-foot">
          {{
            __(':percent% today, :first% a year ago', {
              percent: resistantTrend[resistantTrend.length - 1].percent,
              first: resistantTrend[0].percent,
            })
          }}
        </p>
      </div>

      <!-- Legitimate and rare, which is what makes a run of them worth seeing. -->
      <div v-if="recoverySignIns.length" class="n2f-panel">
        <div class="n2f-panel-head">
          <b>{{ __('Recent recovery-code sign-ins') }}</b>
          <em>{{ __('last 5') }}</em>
        </div>
        <ul class="n2f-events">
          <li v-for="(event, index) in recoverySignIns" :key="index">
            <span>{{ event.name }}</span>
            <time :datetime="event.at" :title="event.at">{{ relative(event.at) }}</time>
          </li>
        </ul>
      </div>
    </div>

    <!-- ── Resilience ──────────────────────────────────────────────────
         Enrolled is not the same as resilient. Each of these answers a
         question the percentage above cannot. -->
    <div class="n2f-stats">
      <div
        class="n2f-stat n2f-help-host n2f-stat-filter"
        :class="[
          { 'n2f-stat-warn': health.single_factor > 0 },
          { 'n2f-stat-on': filter === 'single_factor' },
        ]"
        role="button"
        tabindex="0"
        :aria-pressed="filter === 'single_factor'"
        @click="toggleFilter('single_factor')"
        @keydown.enter.prevent="toggleFilter('single_factor')"
        @keydown.space.prevent="toggleFilter('single_factor')"
      >
        <p class="n2f-tile-label">{{ __('Single factor') }}</p>
        <HelpHint :text="hints.single_factor" />
        <p class="n2f-stat-value">{{ health.single_factor }}</p>
        <p class="n2f-tile-foot">{{ __('one lost device from a lockout') }}</p>
      </div>

      <div
        class="n2f-stat n2f-help-host n2f-stat-filter"
        :class="[
          { 'n2f-stat-warn': health.no_recovery + health.low_recovery > 0 },
          { 'n2f-stat-on': filter === 'recovery' },
        ]"
        role="button"
        tabindex="0"
        :aria-pressed="filter === 'recovery'"
        @click="toggleFilter('recovery')"
        @keydown.enter.prevent="toggleFilter('recovery')"
        @keydown.space.prevent="toggleFilter('recovery')"
      >
        <p class="n2f-tile-label">{{ __('Recovery codes') }}</p>
        <HelpHint :text="hints.recovery" />
        <p class="n2f-stat-value">{{ health.no_recovery + health.low_recovery }}</p>
        <p class="n2f-tile-foot">
          {{
            __(':none with none left, :low with three or fewer', {
              none: health.no_recovery,
              low: health.low_recovery,
            })
          }}
        </p>
      </div>

      <div v-if="sections.time_to_enrol" class="n2f-stat n2f-help-host">
        <p class="n2f-tile-label">{{ __('Time to enrol') }}</p>
        <HelpHint :text="hints.time_to_enrol" />
        <p class="n2f-stat-value">
          {{ health.time_to_enrol_days === null ? '—' : health.time_to_enrol_days }}
        </p>
        <p class="n2f-tile-foot">{{ __('median days from account to first factor') }}</p>
      </div>

      <div
        class="n2f-stat n2f-help-host n2f-stat-filter"
        :class="[{ 'n2f-stat-warn': health.stale > 0 }, { 'n2f-stat-on': filter === 'stale' }]"
        role="button"
        tabindex="0"
        :aria-pressed="filter === 'stale'"
        @click="toggleFilter('stale')"
        @keydown.enter.prevent="toggleFilter('stale')"
        @keydown.space.prevent="toggleFilter('stale')"
      >
        <p class="n2f-tile-label">{{ __('Stale') }}</p>
        <HelpHint :text="hints.stale" />
        <p class="n2f-stat-value">{{ health.stale }}</p>
        <p class="n2f-tile-foot">{{ __('no verification in 90 days') }}</p>
      </div>

      <div
        v-if="sections.trusted_devices"
        class="n2f-stat n2f-help-host n2f-stat-filter"
        :class="[{ 'n2f-stat-warn': devices.active > 0 }, { 'n2f-stat-on': filter === 'devices' }]"
        role="button"
        tabindex="0"
        :aria-pressed="filter === 'devices'"
        @click="toggleFilter('devices')"
        @keydown.enter.prevent="toggleFilter('devices')"
        @keydown.space.prevent="toggleFilter('devices')"
      >
        <p class="n2f-tile-label">{{ __('Trusted devices') }}</p>
        <HelpHint :text="hints.devices" />
        <p class="n2f-stat-value">{{ devices.enabled ? devices.active : '—' }}</p>
        <!-- The number that quietly undoes enforcement: each one is a browser
             that will not be challenged until it expires. -->
        <p class="n2f-tile-foot">
          {{ devices.enabled ? __('currently skipping the challenge') : __('turned off') }}
        </p>
      </div>

      <div
        class="n2f-stat n2f-help-host n2f-stat-filter"
        :class="[{ 'n2f-stat-bad': lockouts.count > 0 }, { 'n2f-stat-on': filter === 'lockouts' }]"
        role="button"
        tabindex="0"
        :aria-pressed="filter === 'lockouts'"
        @click="toggleFilter('lockouts')"
        @keydown.enter.prevent="toggleFilter('lockouts')"
        @keydown.space.prevent="toggleFilter('lockouts')"
      >
        <p class="n2f-tile-label">{{ __('Locked out') }}</p>
        <HelpHint :text="hints.lockouts" />
        <p class="n2f-stat-value">{{ lockouts.count }}</p>
        <p class="n2f-tile-foot" :title="lockouts.users.join(', ')">
          {{ lockouts.count ? lockouts.users.slice(0, 2).join(', ') : __('nobody in 24 hours') }}
        </p>
      </div>
    </div>

    <div class="flex items-center gap-3">
      <!-- Cleared from here as well as by clicking the tile again: a filter you
           can only undo by finding the control that set it is a trap. -->
      <button v-if="filter" type="button" class="n2f-filter-chip" @click="filter = null">
        {{ filterLabel }}
        <span aria-hidden="true">&times;</span>
      </button>

      <input
        v-model="search"
        type="search"
        class="form-control form-input form-control-bordered n2f-search text-sm"
        :placeholder="__('Search by name or email')"
        @input="debouncedLoad"
      />
      <span v-if="loading" class="text-xs text-gray-400">{{ __('Loading…') }}</span>
    </div>

    <div class="overflow-x-auto">
      <table class="n2f-table">
        <thead>
          <tr>
            <th>{{ __('User') }}</th>
            <th>{{ __('Status') }}</th>
            <th>{{ __('Methods') }}</th>
            <th>{{ __('Last verified') }}</th>
            <th class="w-8"></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in visibleRows" :key="`${row.status}-${row.id}-${row.name}`">
            <td>
              <span class="flex items-center gap-2">
                <span class="n2f-avatar" aria-hidden="true">{{ row.initials }}</span>
                <span class="min-w-0">
                  <span class="block truncate">{{ row.name }}</span>
                  <span
                    v-if="row.email && row.email !== row.name"
                    class="block truncate text-xs text-gray-400"
                  >
                    {{ row.email }}
                  </span>
                </span>
              </span>
            </td>

            <td>
              <span class="n2f-badge" :class="badgeClass(row.status)">{{
                statusLabel(row.status)
              }}</span>
            </td>

            <!-- The method cluster is the real signal: who has only an email
                 code is who a determined attacker goes through first. -->
            <td>
              <span v-if="row.methods.length" class="flex gap-1">
                <span
                  v-for="method in row.methods"
                  :key="method.type"
                  class="n2f-method-chip"
                  :title="method.label"
                >
                  <Icon :name="icon(method.type)" type="micro" />
                </span>
              </span>
              <span v-else class="text-gray-400">&mdash;</span>
            </td>

            <td>
              <!-- Relative on screen, absolute in the title: "3 hours ago" is
                   readable, an ISO stamp is evidence. -->
              <span v-if="row.last_verified_at" :title="row.last_verified_at">
                {{ relative(row.last_verified_at) }}
              </span>
              <span v-else class="italic text-gray-400">{{ __('never') }}</span>
            </td>

            <td>
              <!-- Always present. A menu that appears on some rows and not others
                   makes the admin test each row to find out what it can do. -->
              <Dropdown>
                <DropdownTrigger
                  as="button"
                  type="button"
                  class="n2f-row-menu"
                  :aria-label="__('Actions for :name', { name: row.name })"
                >
                  &hellip;
                </DropdownTrigger>

                <template #menu>
                  <DropdownMenu width="240">
                    <DropdownMenuItem v-if="row.resource_url" :href="row.resource_url">
                      {{ __('Open user') }}
                    </DropdownMenuItem>

                    <!-- Only for someone who has not complied: a reminder to a
                         user who already has a factor is the fastest way to
                         teach people to ignore the next one. -->
                    <DropdownMenuItem
                      v-if="row.status !== 'enrolled'"
                      as="button"
                      @click="remind(row)"
                    >
                      {{ __('Send reminder') }}
                    </DropdownMenuItem>

                    <!-- Nothing to clear for someone with no methods. -->
                    <DropdownMenuItem
                      v-if="row.status === 'enrolled'"
                      as="button"
                      class="text-red-500"
                      @click="askReset(row)"
                    >
                      {{ __('Reset two-factor authentication') }}
                    </DropdownMenuItem>
                  </DropdownMenu>
                </template>
              </Dropdown>
            </td>
          </tr>

          <tr v-if="!visibleRows.length && !loading">
            <td colspan="5" class="text-center text-gray-400 py-6">
              {{
                filter
                  ? __('Nobody in the queue matches that.')
                  : search
                    ? __('Nobody matches that search.')
                    : __('Nobody is in scope yet.')
              }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- A cap that says nothing reads as "this is everyone", which is the one
         thing a compliance list must never imply when it is not. -->
    <p v-if="truncated" class="text-xs text-gray-400">
      {{
        __('Showing the first :count. Narrow the search to see the rest.', { count: rows.length })
      }}
    </p>

    <!-- ── What administrators have done ───────────────────────────────
         Scoped to the actions this page offers — reminders, resets,
         exemptions — because a list that also carries every sign-in buries
         the three rows somebody came to check. -->
    <div v-if="events.length" class="n2f-panel">
      <div class="n2f-panel-head">
        <b>{{ __('Recent admin actions') }}</b>
        <a v-if="eventsUrl" :href="eventsUrl" class="n2f-see-all">{{ __('See all') }} &rarr;</a>
      </div>

      <ul class="n2f-events">
        <li v-for="(event, index) in events" :key="index">
          <span class="min-w-0">
            <b>{{ event.label }}</b>
            &middot; {{ event.user }}
            <template v-if="event.by">
              &middot;
              <span class="n2f-tile-foot">{{ __('by :name', { name: event.by }) }}</span>
            </template>
            <span v-if="event.detail" class="block n2f-tile-foot">“{{ event.detail }}”</span>
          </span>
          <time :datetime="event.at" :title="event.at">{{ relative(event.at) }}</time>
        </li>
      </ul>
    </div>

    <!-- ── Reset ────────────────────────────────────────────────────────
         Destructive, cross-account and irreversible, so it asks for the
         address typed out and a written reason. Typing the address is what
         stops a reset landing on the row above the intended one — a confirm
         button cannot tell those two rows apart. -->
    <Modal :show="resetting !== null" role="alertdialog" size="md" @close-via-escape="cancelReset">
      <div v-if="resetting" class="bg-white dark:bg-gray-800 rounded-lg shadow-lg p-6">
        <!-- Icon and title on one line, name beneath the pair: the icon
             belongs to the whole dialog, so hanging it beside a wrapping
             heading left it floating next to the first line of text. -->
        <!-- Title and name share one column beside the icon, so the name
             lines up under the title instead of starting back at the card
             edge as if it belonged to something else. -->
        <div class="flex items-start gap-2.5">
          <span class="n2f-danger-icon" aria-hidden="true">
            <Icon name="exclamation-triangle" type="micro" />
          </span>
          <div class="min-w-0">
            <p class="text-[15px] font-bold leading-tight">
              {{ __('Reset two-factor authentication') }}
            </p>
            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
              {{ __('for :name', { name: resetting.name }) }}
            </p>
          </div>
        </div>

        <ul class="n2f-consequences">
          <li>{{ __('Removes every method and recovery code') }}</li>
          <li>{{ __('Forgets every trusted device') }}</li>
          <li>{{ __('Signs out their existing sessions') }}</li>
          <li>{{ __('Cannot be undone') }}</li>
        </ul>

        <div class="n2f-field">
          <label class="n2f-field-label" for="n2f-reset-confirm">
            {{ __('Type their email address to confirm') }}
          </label>

          <!-- Copyable, because this step proves which row — not anyone's
               typing. Retyping an address by hand is where the wrong account
               gets reset. -->
          <div class="n2f-copy-row">
            <span class="n2f-confirm-target">{{ targetAddress }}</span>
            <button
              type="button"
              class="n2f-copy-button"
              :aria-label="__('Copy the address')"
              :title="copied ? __('Copied') : __('Copy the address')"
              @click="copyAddress"
            >
              <Icon :name="copied ? 'check' : 'clipboard'" type="micro" />
            </button>
          </div>

          <input
            id="n2f-reset-confirm"
            v-model="confirmation"
            type="text"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="off"
            spellcheck="false"
            data-1p-ignore
            data-lpignore="true"
            data-form-type="other"
            class="form-control form-input form-control-bordered w-full text-sm"
            :class="{ 'n2f-input-ok': confirmationMatches }"
          />
          <p class="n2f-field-hint" :class="{ 'n2f-text-ok': confirmationMatches }">
            {{ confirmationMatches ? __('Matches.') : __('Must match exactly.') }}
          </p>
        </div>

        <div class="n2f-field">
          <label class="n2f-field-label" for="n2f-reset-reason">{{ __('Reason') }}</label>
          <textarea
            id="n2f-reset-reason"
            v-model="reason"
            rows="2"
            :placeholder="__('e.g. lost their phone, verified by video call')"
            class="form-control form-input form-control-bordered w-full text-sm"
          />
          <p class="n2f-field-hint" :class="{ 'n2f-text-bad': reasonShort }">
            {{
              reasonShort
                ? __('At least :count characters — :missing to go.', {
                    count: REASON_MIN,
                    missing: REASON_MIN - reason.trim().length,
                  })
                : __('Recorded in the audit log against your account.')
            }}
          </p>
        </div>

        <!-- Unticked by default: a reset is often part of a support call the
             user is already on. Where it is wanted, the mail is also how
             somebody notices a reset they did not ask for. -->
        <label class="n2f-field flex items-start gap-2 text-sm">
          <input v-model="notify" type="checkbox" class="form-checkbox mt-0.5" />
          <span>
            {{ __('Tell the user by email') }}
            <span class="block n2f-field-hint mt-0">
              {{
                __(
                  'Explains what was removed and how to set it up again. It does not include your reason.',
                )
              }}
            </span>
          </span>
        </label>

        <div class="mt-5 flex items-center gap-2">
          <span v-if="!resetReady" class="n2f-field-hint flex-1 mt-0">{{ blockingStep }}</span>
          <span v-else class="flex-1"></span>

          <Button type="button" variant="ghost" @click="cancelReset">{{ __('Cancel') }}</Button>

          <!-- Wrapped, like every other destructive write: the route sits
               behind `RequirePassword`, which answers 423 rather than running
               the controller, and nothing in Nova opens the password modal on
               its own. Unwrapped, the button posted, got 423, and appeared to
               do nothing at all. -->
          <ConfirmsPassword @confirmed="confirmReset">
            <Button type="button" state="danger" :loading="busy" :disabled="!resetReady">
              {{ __('Reset') }}
            </Button>
          </ConfirmsPassword>
        </div>
      </div>
    </Modal>
  </Card>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { Button, Icon } from 'laravel-nova-ui'
import HelpHint from './HelpHint.vue'
import { __ } from '../support/translate'

defineOptions({ name: 'NovaTwoFactorCompliance' })

const props = defineProps({
  card: { type: Object, default: () => ({}) },
})

const summary = ref({
  in_scope: 0,
  enrolled: 0,
  enrolled_percent: 0,
  not_enrolled: 0,
  grace: 0,
  overdue: 0,
  optional: 0,
  expiring_today: 0,
})
const health = ref({
  covered: 0,
  single_factor: 0,
  phishing_resistant: 0,
  phishing_resistant_percent: 0,
  no_recovery: 0,
  low_recovery: 0,
  stale: 0,
  time_to_enrol_days: null,
})
const methods = ref([])
const failures = ref([])
const devices = ref({ active: 0, oldest: null, enabled: true })
const lockouts = ref({ count: 0, users: [] })
const recoverySignIns = ref([])
const resistantTrend = ref([])
const mode = ref(null)
const sections = ref({
  coverage: true,
  grace: true,
  time_to_enrol: true,
  trusted_devices: true,
  resilience: true,
  signals: true,
})
const spotlight = ref(null)
const paused = ref({ until: null, by: null, reason: null, minutes_left: null })
const events = ref([])
const eventsUrl = ref(null)
const rows = ref([])
const truncated = ref(false)
const loading = ref(true)
const search = ref('')

// Gradient ids are global to the document, and Nova renders several cards on one
// page — a fixed id would have two cards fighting over the same definition.
const sparkId = `n2f-spark-${Math.random().toString(36).slice(2, 9)}`

// One sentence per figure: what it answers, and why it is worth a slot. Held
// together rather than scattered through the markup, so the page's explanations
// can be read — and translated — as a set.
const hints = computed(() => ({
  single_factor: __(
    'Accounts with exactly one method. Enrolled is not the same as resilient: one lost device and they are a support ticket.',
  ),
  recovery: __(
    'Accounts with three or fewer unused recovery codes, or none at all. The usual cause is codes that were never saved.',
  ),
  time_to_enrol: __(
    'Median days from account creation to the first confirmed method. Measures the onboarding process rather than the people.',
  ),
  stale: __(
    'Confirmed methods not used in 90 days. Often a device nobody has any more, and it is discovered at the worst moment.',
  ),
  devices: __(
    'Live “remember this device” grants. Each one is a browser that will not be challenged until it expires — the number that quietly undoes enforcement.',
  ),
  lockouts: __(
    'People rate-limited at the challenge in the last 24 hours. The names are what tell an attack from someone fighting their own clock.',
  ),
}))

// Under a policy, the four states are distinct and worth separating. Without
// one they collapse: nothing is due, so nothing can be overdue or in grace, and
// the honest split is simply covered or not. Hiding the strip entirely was the
// earlier answer and it left half the headline empty for no gain.
const coverageAll = computed(() =>
  sections.value.coverage
    ? [
        { key: 'ok', label: __('Enrolled'), count: summary.value.enrolled },
        { key: 'warn', label: __('In grace period'), count: summary.value.grace },
        { key: 'bad', label: __('Overdue'), count: summary.value.overdue },
        { key: 'idle', label: __('Not required'), count: summary.value.optional },
      ]
    : [
        { key: 'ok', label: __('Enrolled'), count: summary.value.enrolled },
        { key: 'idle', label: __('Not set up'), count: summary.value.not_enrolled },
      ],
)

// Only the bands that exist get a segment: a zero-width sliver still paints its
// 2px gap, which reads as a band that is there but tiny.
const coverage = computed(() => {
  const total = summary.value.in_scope || 1

  return coverageAll.value
    .filter((band) => band.count > 0)
    .map((band) => ({ ...band, percent: (band.count / total) * 100 }))
})

const coverageLabel = computed(() =>
  coverageAll.value.map((band) => `${band.label}: ${band.count}`).join(', '),
)

const ringLabel = computed(() =>
  __(':percent% of :count in scope are enrolled', {
    percent: summary.value.enrolled_percent,
    count: summary.value.in_scope,
  }),
)

const methodTotal = computed(() => methods.value.reduce((sum, m) => sum + m.count, 0) || 1)
const methodCount = (type) => methods.value.find((m) => m.type === type)?.count ?? 0
const methodLabel = (type) =>
  methods.value.find((m) => m.type === type)?.label ??
  { webauthn: __('Passkey'), totp: __('Authenticator app'), email: __('Email code') }[type]

const failuresPeak = computed(() => Math.max(0, ...failures.value.map((d) => d.count)))
const failuresTotal = computed(() => failures.value.reduce((sum, d) => sum + d.count, 0))
const failuresLabel = computed(() =>
  __('Failed challenges per day. Peak :peak, :total in total.', {
    peak: failuresPeak.value,
    total: failuresTotal.value,
  }),
)

/*
 * The plot box is measured rather than fixed.
 *
 * A fixed viewBox with `preserveAspectRatio="none"` was stretching one user
 * unit to roughly seven pixels across, which is harmless for a path and not for
 * the endpoint marker: the circle came out as a flattened wedge pointing right.
 * Measuring means one unit is one pixel, so round things stay round.
 */
const sparkBox = ref(null)
const plotWidth = ref(300)

let observer = null

onMounted(() => {
  if (!sparkBox.value || typeof ResizeObserver === 'undefined') return

  observer = new ResizeObserver(([entry]) => {
    plotWidth.value = Math.max(120, Math.round(entry.contentRect.width))
  })

  observer.observe(sparkBox.value)
})

onBeforeUnmount(() => observer?.disconnect())

const plot = (series, value, height, top = 6) => {
  if (!series.length) return []

  const max = Math.max(1, ...series.map(value))
  const width = plotWidth.value
  const step = series.length === 1 ? 0 : width / (series.length - 1)

  return series.map((point, index) => ({
    x: index * step,
    y: height - top - (value(point) / max) * (height - top * 2),
  }))
}

const sparkPoints = computed(() => plot(failures.value, (d) => d.count, 76))
const sparkLine = computed(() =>
  sparkPoints.value.map((p, i) => `${i ? 'L' : 'M'}${p.x},${p.y}`).join(' '),
)
const sparkArea = computed(() =>
  sparkPoints.value.length ? `${sparkLine.value} L${plotWidth.value},76 L0,76 Z` : '',
)
const sparkLast = computed(() => sparkPoints.value[sparkPoints.value.length - 1] ?? { x: 0, y: 0 })

const trendPoints = computed(() => plot(resistantTrend.value, (d) => d.percent, 60))
const trendLine = computed(() =>
  trendPoints.value.map((p, i) => `${i ? 'L' : 'M'}${p.x},${p.y}`).join(' '),
)
const trendLast = computed(() => trendPoints.value[trendPoints.value.length - 1] ?? { x: 0, y: 0 })
const trendLabel = computed(() =>
  __('Phishing-resistant share over twelve months, now :percent%', {
    percent: resistantTrend.value[resistantTrend.value.length - 1]?.percent ?? 0,
  }),
)

const endpoint = () => props.card?.endpoint ?? '/two-factor/compliance'

const load = async () => {
  loading.value = true

  try {
    const { data } = await Nova.request().get(endpoint(), { params: { search: search.value } })

    summary.value = data.summary
    health.value = data.health
    methods.value = data.methods
    failures.value = data.failures
    devices.value = data.devices
    lockouts.value = data.lockouts
    recoverySignIns.value = data.recovery_sign_ins
    resistantTrend.value = data.resistant_trend
    mode.value = data.mode
    sections.value = data.sections ?? sections.value
    spotlight.value = data.spotlight
    paused.value = data.paused ?? paused.value
    events.value = data.events ?? []
    eventsUrl.value = data.events_url ?? null
    rows.value = data.rows
    truncated.value = data.truncated
  } catch (e) {
    Nova.error(e.response?.data?.message ?? __('That could not be loaded.'))
  } finally {
    loading.value = false
  }
}

// Typing should narrow the list, not fire a chunked pass over the user table on
// every keystroke.
let timer = null
const debouncedLoad = () => {
  clearTimeout(timer)
  timer = setTimeout(load, 350)
}

// Tiles filter the queue rather than merely counting: the row that answers
// "who is this?" is already on the page, and a number nobody can act on is
// half a figure.
const filter = ref(null)

const toggleFilter = (key) => {
  filter.value = filter.value === key ? null : key
}

const visibleRows = computed(() =>
  filter.value ? rows.value.filter((row) => (row.flags ?? []).includes(filter.value)) : rows.value,
)

const filterLabel = computed(
  () =>
    ({
      single_factor: __('Single factor'),
      recovery: __('Recovery codes'),
      stale: __('Stale'),
      devices: __('Trusted devices'),
      lockouts: __('Locked out'),
    })[filter.value] ?? '',
)

const target = (row) => ({ model: row.model, id: row.id })

const remind = async (row) => {
  try {
    const { data } = await Nova.request().post(`${endpoint()}/remind`, target(row))

    Nova.success(data.message ?? __('Reminder sent.'))
  } catch (e) {
    Nova.error(e.response?.data?.message ?? __('That could not be sent.'))
  }
}

const resetting = ref(null)
const notify = ref(false)
const confirmation = ref('')
const reason = ref('')
const busy = ref(false)

// Both fields, not either: the address proves which row, the reason is what the
// audit log has to show afterwards.
// Compared case-insensitively but otherwise exactly: an address is not a
// password, and rejecting "GABRIELE@…" would only teach people to paste.
const confirmationMatches = computed(
  () =>
    confirmation.value.trim().length > 0 &&
    confirmation.value.trim().toLowerCase() ===
      String(resetting.value?.email ?? resetting.value?.name ?? '').toLowerCase(),
)

// Matches the endpoint's own rule, so the form cannot look satisfied while the
// request would be refused.
const REASON_MIN = 5

const reasonShort = computed(() => reason.value.trim().length < REASON_MIN)
const resetReady = computed(() => confirmationMatches.value && !reasonShort.value)

// Names the step that is outstanding, and for the reason field says how much is
// missing: "a reason is required" beside a box with four characters in it reads
// as a bug rather than a rule.
const blockingStep = computed(() => {
  if (!confirmationMatches.value) return __('Type the address above to continue.')

  if (reasonShort.value) {
    return __('Write a reason of at least :count characters.', { count: REASON_MIN })
  }

  return ''
})

const targetAddress = computed(() => String(resetting.value?.email ?? resetting.value?.name ?? ''))

const copied = ref(false)

const copyAddress = async () => {
  await navigator.clipboard.writeText(targetAddress.value)

  copied.value = true
  setTimeout(() => (copied.value = false), 2000)
}

const askReset = (row) => {
  copied.value = false
  notify.value = false
  confirmation.value = ''
  reason.value = ''
  resetting.value = row
}

const cancelReset = () => {
  resetting.value = null
}

const confirmReset = async () => {
  busy.value = true

  try {
    const { data } = await Nova.request().post(`${endpoint()}/reset`, {
      ...target(resetting.value),
      confirmation: confirmation.value,
      reason: reason.value,
      notify: notify.value,
    })

    resetting.value = null
    Nova.success(data.message ?? __('Two-factor authentication reset.'))
    await load()
  } catch (e) {
    // Only reachable if the confirmation expired between the modal and the
    // request; the wrapper above is what normally satisfies the guard.
    if (e.response?.status !== 423) {
      Nova.error(e.response?.data?.message ?? __('That could not be reset.'))
    }
  } finally {
    busy.value = false
  }
}

const statusLabel = (status) =>
  ({
    enrolled: __('Enrolled'),
    grace: __('In grace period'),
    overdue: __('Overdue'),
    optional: __('Not required'),
  })[status] ?? status

const badgeClass = (status) =>
  ({
    enrolled: 'n2f-badge-ok',
    grace: 'n2f-badge-warn',
    overdue: 'n2f-badge-bad',
    optional: 'n2f-badge-neutral',
  })[status] ?? 'n2f-badge-neutral'

const icon = (type) =>
  ({ totp: 'device-phone-mobile', webauthn: 'key', email: 'envelope' })[type] ?? 'shield-check'

const relative = (iso) => {
  const formatter = new Intl.RelativeTimeFormat(Nova.config('locale') ?? 'en', { numeric: 'auto' })
  const seconds = (new Date(iso) - Date.now()) / 1000

  for (const [unit, size] of [
    ['year', 31536000],
    ['month', 2592000],
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
