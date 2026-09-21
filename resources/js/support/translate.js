/**
 * Nova's `__` is an Options-API mixin method, not a global function.
 *
 * `Nova.app.mixin(Localization)` puts it on the render context, so a template
 * can call `__('…')` and a component using the Options API can call
 * `this.__('…')`. Neither reaches the body of a `<script setup>` block, where a
 * bare `__(…)` is just an undeclared identifier — it throws `ReferenceError`
 * the moment the computed that contains it is evaluated.
 *
 * The lookup is the same one Nova performs, against the same translations bag,
 * so a key resolves identically whether it was rendered from a template or
 * computed in script.
 *
 * @param {string} key
 * @param {{[key: string]: string|number}} [replace]
 * @returns {string}
 */
export function __(key, replace) {
  // Inside Nova's SPA the bag comes from Nova; on the pre-auth Blade screens
  // there is no Nova instance, and the layout ships the same strings itself.
  const translations =
    (typeof Nova !== 'undefined' ? Nova.config('translations') : null) ?? window.__n2fLang ?? {}

  let translation = translations[key] ?? key

  Object.entries(replace ?? {}).forEach(([token, value]) => {
    if (value === null || value === undefined) {
      return
    }

    const name = String(token)
    const replacement = String(value)

    const searches = [
      ':' + name,
      ':' + name.toUpperCase(),
      ':' + name.charAt(0).toUpperCase() + name.slice(1),
    ]

    const replacements = [
      replacement,
      replacement.toUpperCase(),
      replacement.charAt(0).toUpperCase() + replacement.slice(1),
    ]

    for (let i = searches.length - 1; i >= 0; i--) {
      translation = translation.replace(searches[i], replacements[i])
    }
  })

  return translation
}
