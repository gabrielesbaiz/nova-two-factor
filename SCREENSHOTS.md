# Screenshots

Light and dark side by side — click either one for the full-resolution image.
Every screen is the real thing, captured from the package's own workbench
against `DemoSeeder`; every person, address and event in them is invented.

The screens fall into three groups, and the split matters:

| Group | Where it renders | When the user sees it |
| --- | --- | --- |
| [Signing in](#1-signing-in) | Standalone pages, **outside** Nova's SPA | Between the password and the dashboard |
| [User settings](#2-user-settings) | **Inside** Nova, on the user-security page | Any time, from Nova's user menu |
| [Admin oversight](#3-admin-oversight) | **Inside** Nova: two dashboards and a resource | Whenever an administrator looks |

[← Back to the README](README.md)

---

## 1. Signing in

Nobody is signed in to Nova yet. These pages are server-rendered Blade,
deliberately not Inertia pages: Nova resolves its initial component before any
tool script runs, so a tool-registered page would spin forever on a cold load —
and a challenge is always a cold load. They load Nova's own stylesheet and read
the same `novaTheme` key, so they look like the dashboard the user is heading
for.

### Setting up the first factor

A user with no second factor lands here right after the password. Factors are
ranked strongest first, each with an honest one-line tradeoff — so the choice is
informed rather than alphabetical.

Enforcement mode decides the tone. Under **`encouraged`** it is a reminder: the
same list, plus **Not now** and a snooze that is recorded against the account,
not the browser — clearing cookies does not restart the nagging.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/enrollment-light.png"><img width="320" src="art/screenshots/enrollment-light.png" alt="The enrolment screen offering a passkey, an authenticator app or an email code, each with its tradeoff — light theme"></a></td>
  <td><a href="art/screenshots/enrollment-dark.png"><img width="320" src="art/screenshots/enrollment-dark.png" alt="The enrolment screen offering a passkey, an authenticator app or an email code, each with its tradeoff — dark theme"></a></td>
</tr>
</table>

Under **`required`**, once the grace window has expired, it is a wall: a
different heading, no deferral, and Nova is unreachable until a factor is set
up. The way out never disappears, though — a dead end with no explanation is
what makes enforcement screens hated.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/enrollment-required-light.png"><img width="320" src="art/screenshots/enrollment-required-light.png" alt="The same enrolment screen in required mode: your organization requires two-factor authentication, set up a method to continue, with no way to defer — light theme"></a></td>
  <td><a href="art/screenshots/enrollment-required-dark.png"><img width="320" src="art/screenshots/enrollment-required-dark.png" alt="The same enrolment screen in required mode: your organization requires two-factor authentication, set up a method to continue, with no way to defer — dark theme"></a></td>
</tr>
</table>

### The challenge

What an enrolled user meets on every sign-in. One page, one layout: the
heading, the input and the hint change with the factor in use, and the
trusted-device opt-in and the way out stay where they were.

#### Email code

The code is sent when the page loads, never by the link that reaches it — a
prefetch would spend it before the user read the mail. The resend countdown is
enforced server-side; the timer on screen is only its shadow.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/challenge-email-light.png"><img width="320" src="art/screenshots/challenge-email-light.png" alt="The challenge asking for the six-digit code sent by email, with a resend countdown — light theme"></a></td>
  <td><a href="art/screenshots/challenge-email-dark.png"><img width="320" src="art/screenshots/challenge-email-dark.png" alt="The challenge asking for the six-digit code sent by email, with a resend countdown — dark theme"></a></td>
</tr>
</table>

#### Authenticator app

Same six-digit field, different hint — and every accepted timestep is recorded,
so a code cannot be replayed even inside its own window.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/challenge-totp-light.png"><img width="320" src="art/screenshots/challenge-totp-light.png" alt="The challenge asking for the code from an authenticator app — light theme"></a></td>
  <td><a href="art/screenshots/challenge-totp-dark.png"><img width="320" src="art/screenshots/challenge-totp-dark.png" alt="The challenge asking for the code from an authenticator app — dark theme"></a></td>
</tr>
</table>

#### Passkey

No code to type or phish: the browser asks for a fingerprint, a face or a PIN.
The challenge screen also offers conditional mediation, so a passkey can be
autofilled before the button is ever pressed.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/challenge-passkey-light.png"><img width="320" src="art/screenshots/challenge-passkey-light.png" alt="The challenge offering to confirm with a passkey — light theme"></a></td>
  <td><a href="art/screenshots/challenge-passkey-dark.png"><img width="320" src="art/screenshots/challenge-passkey-dark.png" alt="The challenge offering to confirm with a passkey — dark theme"></a></td>
</tr>
</table>

#### Recovery code

The way back in when the phone is gone. Each code works once — and using one
signs you in without turning two-factor authentication off, which is the
difference between a recovery code and a back door.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/challenge-recovery-light.png"><img width="320" src="art/screenshots/challenge-recovery-light.png" alt="The challenge asking for a recovery code — light theme"></a></td>
  <td><a href="art/screenshots/challenge-recovery-dark.png"><img width="320" src="art/screenshots/challenge-recovery-dark.png" alt="The challenge asking for a recovery code — dark theme"></a></td>
</tr>
</table>

#### Picking another factor

**Use another method** opens the chooser inline — never a separate page, and
never shown first: one extra click on every single login is not a price worth
paying. Every enrolled factor is listed with its tradeoff and when it was last
used, plus recovery codes while any are left.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/challenge-methods-light.png"><img width="320" src="art/screenshots/challenge-methods-light.png" alt="The challenge with the method chooser open, listing a passkey, an authenticator app and recovery codes — light theme"></a></td>
  <td><a href="art/screenshots/challenge-methods-dark.png"><img width="320" src="art/screenshots/challenge-methods-dark.png" alt="The challenge with the method chooser open, listing a passkey, an authenticator app and recovery codes — dark theme"></a></td>
</tr>
</table>

---

## 2. User settings

Signed in, inside Nova. The package replaces Nova's own security card in place
rather than adding a tool page — so it sits where people already look for it,
and survives a bookmarked or refreshed URL. Everything destructive here is
behind a password confirmation.

### The security card

Every enrolled factor, which one is the default, and how many recovery codes
are left.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/security-card-light.png"><img width="430" src="art/screenshots/security-card-light.png" alt="Two-factor security card listing email code, passkey and authenticator app — light theme"></a></td>
  <td><a href="art/screenshots/security-card-dark.png"><img width="430" src="art/screenshots/security-card-dark.png" alt="Two-factor security card listing email code, passkey and authenticator app — dark theme"></a></td>
</tr>
</table>

### Adding an authenticator app

Scan, then verify — in the card, without leaving the page. The QR is generated
locally, so no secret ever leaves the application, and the setup key is always
reachable for desktops without a camera and for screen readers.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/totp-enrollment-light.png"><img width="430" src="art/screenshots/totp-enrollment-light.png" alt="Authenticator app enrolment showing the QR code and the six-digit verification field — light theme"></a></td>
  <td><a href="art/screenshots/totp-enrollment-dark.png"><img width="430" src="art/screenshots/totp-enrollment-dark.png" alt="Authenticator app enrolment showing the QR code and the six-digit verification field — dark theme"></a></td>
</tr>
</table>

### Step-up re-authentication

One dangerous action, one fresh proof. A protected request answers **423** and
the prompt opens over whatever page asked for it — here the user's own security
page. The scope is named, because a prompt that does not say what it is
approving is one people approve reflexively, and the grant it issues is bound to
that scope alone.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/step-up-light.png"><img width="430" src="art/screenshots/step-up-light.png" alt="The step-up prompt asking for a code before a protected action — light theme"></a></td>
  <td><a href="art/screenshots/step-up-dark.png"><img width="430" src="art/screenshots/step-up-dark.png" alt="The step-up prompt asking for a code before a protected action — dark theme"></a></td>
</tr>
</table>

---

## 3. Admin oversight

Three pages under one **Security** menu section, deliberately not three tabs
on one screen: they are different jobs with different risk. Each carries the
admin gate with it, so placing the menu entry by hand cannot widen who sees
them.

### Overview

The morning check, at `/dashboards/two-factor-compliance`. Enrolment coverage
and the enforcement mode in force, the method mix ranked strongest first with
the phishing-resistant share called out, failed attempts and recovery-code
sign-ins over the last 30 days — then the counts that mean somebody has to do
something: accounts one lost device from a lockout, recovery codes running out,
factors nobody has verified in 90 days, and anyone locked out right now. The
search below the tiles finds a single account and offers a reset.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/admin-overview-light.png"><img width="430" src="art/screenshots/admin-overview-light.png" alt="The 2FA overview dashboard: enrolment coverage, methods in use, failed attempts, recovery-code sign-ins and the accounts needing attention — light theme"></a></td>
  <td><a href="art/screenshots/admin-overview-dark.png"><img width="430" src="art/screenshots/admin-overview-dark.png" alt="The 2FA overview dashboard: enrolment coverage, methods in use, failed attempts, recovery-code sign-ins and the accounts needing attention — dark theme"></a></td>
</tr>
</table>

**Resetting someone's second factor** is the one destructive thing this page can
do, so it asks for three separate things: their address typed out in full (a
mis-clicked row is the failure this prevents), a written reason that goes into
the audit log against *your* account, and — as middleware, on the route itself —
your own password. Telling the user by email is opt-in, and that mail carries
what was removed and how to set it up again, never your reason.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/admin-reset-light.png"><img width="430" src="art/screenshots/admin-reset-light.png" alt="The reset confirmation: what it removes, the address typed to confirm, a reason recorded in the audit log, and an opt-in notification — light theme"></a></td>
  <td><a href="art/screenshots/admin-reset-dark.png"><img width="430" src="art/screenshots/admin-reset-dark.png" alt="The reset confirmation: what it removes, the address typed to confirm, a reason recorded in the audit log, and an opt-in notification — dark theme"></a></td>
</tr>
</table>

### Settings

Policy, changeable without a deploy, at `/dashboards/two-factor-settings` — off
until you turn it on, because a fresh install should not hand anyone who reaches
Nova the power to weaken two-factor policy. Enforcement mode, which factors are
offered, code lifetimes, trusted devices and step-up all live here. Anything
pinned in `.env` shows read-only rather than editable-but-ignored, since the
alternative is an edit the next deploy silently reverts, and every change is
written to the activity log with the name of whoever made it.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/admin-settings-light.png"><img width="430" src="art/screenshots/admin-settings-light.png" alt="The 2FA settings page with enforcement mode, method toggles, code lifetimes and trusted-device options — light theme"></a></td>
  <td><a href="art/screenshots/admin-settings-dark.png"><img width="430" src="art/screenshots/admin-settings-dark.png" alt="The 2FA settings page with enforcement mode, method toggles, code lifetimes and trusted-device options — dark theme"></a></td>
</tr>
</table>

### Activity

The audit trail as an ordinary Nova resource, so it filters, sorts and searches
like everything else in the dashboard. It opens on administrator actions —
resets, reminders, exemptions, pauses and every settings change with its before
and after — because a history that also lists each login and emailed code is one
nobody reads. The filter widens it to suspicious events or to everything.

<table>
<tr>
  <th align="center">Light</th>
  <th align="center">Dark</th>
</tr>
<tr>
  <td><a href="art/screenshots/admin-activity-light.png"><img width="430" src="art/screenshots/admin-activity-light.png" alt="The 2FA activity log listing administrator resets, reminders, enforcement pauses and settings changes with their before and after values — light theme"></a></td>
  <td><a href="art/screenshots/admin-activity-dark.png"><img width="430" src="art/screenshots/admin-activity-dark.png" alt="The 2FA activity log listing administrator resets, reminders, enforcement pauses and settings changes with their before and after values — dark theme"></a></td>
</tr>
</table>


---

[← Back to the README](README.md)
