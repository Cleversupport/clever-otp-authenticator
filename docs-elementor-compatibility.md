# Passwordless form rendering investigation (1.0.21 → 1.0.22)

## Finding and confidence

A reproducible plugin defect exists at the nested template boundary: the full-page
template captures form variables, calls `wp_head()`, then uses `load_template()`
to load the card in a new scope. That second call extracts variables from the
**current** global `$wp_query->query_vars`, not the page's captured variables.
Replacing or clearing the query during header rendering therefore loses the
heading, type and complete gateway widget together. The gateway instruction
still prints because it is an independently registered action.

The regression test reproduces that loss on the original code and passes with
the fix. This establishes the rendering defect and a mechanism matching the
reported HTML; it does **not** establish that Elementor 4.3.5 executes the query
replacement on dev.clevernwa.com. No live-site trace or functional WordPress
runtime was available. The site-specific trigger remains unconfirmed. Do not
describe this as a proven universal Elementor incompatibility.

## Initialization and request order

References below name files and methods so they remain useful after line changes.

1. `clever-otp-authenticator.php::otpa_run()` runs on `plugins_loaded` at 10.
   Gateway files and their registration filters are loaded; the configured
   gateway is constructed with hooks enabled before settings validation. If
   settings validate, `Otpa` is created with hooks enabled and
   `Otpa_Passwordless` is created with the `enable_passwordless` flag. There is
   no Elementor-specific initialization condition. Admin requests additionally
   construct settings renderers and initialize the updater.
2. `Otpa::__construct()` registers `init` at 0 for `add_endpoints()` and
   `parse_request` at 10. Passwordless registers its endpoint filter and page
   action during construction; therefore they exist before `init` fires.
   Its OTP API callback filters are registered at `init` 10 only for logged-out
   users. Settings validation, feature enablement and user state can prevent
   registration or redirect a request, but do not explain an empty type in a
   successfully prepared passwordless form.
3. `Otpa::add_endpoints()` adds `^otpa/passwordless-login` →
   `index.php?__otpa_page=1&action=passwordless_login`. In `parse_request()`, an
   authorized page dispatches `otpa_page_passwordless_login` without exiting.
   `Otpa_Passwordless::login_page()` registers its form-vars filter and schedules
   `otp_form_page()` on `template_redirect` at `PHP_INT_MIN`. Logged-in visitors
   are redirected home instead.
4. `otp_form_page()` enqueues assets, generates the widget, applies the vars
   filter, and writes the result with `set_query_var()`. It registers
   `otp_form_template()` on `template_include` at 99. That callback locates a
   theme page override or loads the bundled page and returns false because it
   already emitted the response.
5. The bundled page calls `wp_head()` before displaying the card, then
   `wp_footer()`. In 1.0.21 the card was loaded by a second `load_template()`.
   WordPress `wp-includes/template.php::load_template()` extracts the current
   query vars with `EXTR_SKIP`; PHP caller locals are not inherited by a new
   function call. This is the demonstrated data-loss boundary.

## Why the filter and gateway are not sufficient explanations

`Otpa::get_otp_form_vars()` defaults `otp_form_type` to `default` and the title
to `Get a Verification Code`. `Otpa_Passwordless::set_otp_form_vars()` overrides
these with `passwordless` and `Passwordless Authentication`. A missed
passwordless filter alone yields `default`, not an empty type. The filter is
not conditional on Elementor. Downstream filters can of course deliberately
replace values, but none was identified in this repository doing so.

`Otpa::get_otp_widget()` delegates to the configured gateway.
`Otpa_Abstract_Gateway::get_otp_widget()` enqueues assets then buffers
`get_otp_widget_markup()`. The latter includes the gateway-specific template
if present, otherwise `inc/gateways/templates/otpa-default-gateway-otp-widget.php`.
WordPress Email inherits that method and uses the default markup, which
unconditionally includes `otpa_id_widget`, `otpa_code_widget`, and
`otpa_send_code`. Its placeholder filter sets `Email` and its independent
`otpa_before_otp_form` action prints the instruction. Gateway initialization
therefore explains why the instruction survives even when `$otp_widget` is
lost. Mail transport, identifier lookup, OTP expiry and throttling run on API
requests, not while generating those controls.

Other possible empty-widget paths are a custom gateway/template that emits no
markup, missing/unreadable template files, an external vars filter, a theme page
override, or query data loss before the outer page loads. Those require separate
evidence and are not all repaired by this change. The default Email template
has no conditional empty return. CSS/JavaScript can hide elements but cannot
explain absent server-rendered markup in the supplied source alone.

## Elementor review

Reviewed the actual GitHub tag **4.3.5** (without a `v` prefix):

- [`includes/frontend.php`](https://github.com/elementor/elementor/blob/4.3.5/includes/frontend.php):
  constructor registers `template_redirect` callbacks at -1 and 10. The OTP
  preparation runs earlier at `PHP_INT_MIN`. Frontend initialization registers
  additional `wp_head` callbacks; it does not remove OTP's hooks.
- [`modules/page-templates/module.php`](https://github.com/elementor/elementor/blob/4.3.5/modules/page-templates/module.php):
  `template_include()` runs at 11 and conditionally chooses a template for a
  singular Elementor document. OTP's callback at 99 runs later.
- [`includes/db.php`](https://github.com/elementor/elementor/blob/4.3.5/includes/db.php):
  `switch_to_query()` replaces the query and `restore_current_query()` restores
  the saved object. Their existence is not proof that this endpoint calls them
  or that a balanced switch loses vars. Reviewed core files do not establish
  a callback that clears OTP's data on the affected request.

The custom endpoint uses the normal WordPress request/template hooks; it is not
itself evidence of skipped hooks. Its form data's dependence on mutable query
state makes this boundary fragile. A shortcode renders its card directly,
without an intervening `wp_head()`, so it does not have this particular gap.
Another working site with identical plugin versions is consistent with a
request-, theme-, or configuration-dependent trigger, not proof of which one.
Elementor Pro 4.3.2 and the affected site's theme were not available for review.

## Minimal fix and scope

The bundled full-page template now includes the card directly in its existing
scope. This preserves all captured form variables across header callbacks and
avoids a second dependency on the global query. No Elementor hooks are removed.
The shortcode path, its theme card lookup, all OTP API handlers, redirects,
Hide Login, dashboard restrictions, Extra Button hooks, and updater are unchanged.
Full-page theme overrides continue to take precedence and must carry their own
equivalent correction if they duplicate the old nested load. Direct inclusion
does not fire WordPress's before/after-load-template hooks for the nested card;
the outer page still goes through `load_template()`.

## Validation and remaining checks

- On local PHP 8.4.26, the new isolated test failed before the fix with
  `Missing: data-otp_form_type="passwordless"` after simulated query replacement
  in `wp_head()`. After the fix, full-page rendering with and without that
  replacement and the real passwordless shortcode callback all pass, checking
  Email, Code, Send Code, heading, nonce and handler. WordPress services and
  asset enqueues are stubbed; production template and gateway markup methods
  are exercised. This is not an Elementor integration test.
- Existing PHP inactivity, Hide Login logout, and JavaScript inactivity tests
  pass. First-party PHP lint passes; the new test is linted separately.
- PHP 8.1 and 8.3 were added to the existing CI matrix alongside 7.0 and 8.4.
  Those CI results must be checked on the PR; only PHP 8.4 was available locally.
  Its pre-existing no-argument `get_class()` deprecation is visible in the new
  test and is outside this rendering fix.
- Full HTTP WordPress/Elementor rendering, an actual Elementor shortcode widget,
  mail delivery, OTP request/verification and auth-cookie issuance have **not**
  been executed. They need an operational WordPress environment. The attached
  workspace provides a static-only setup and inactive historical runtime data.

Before accepting this as the affected site's fix, trace only OTP key presence
and lengths (not codes, nonces, cookies or email values) in `otpa_otp_form_vars`,
before outer page loading, at the beginning/end of `wp_head`, and before card
loading. Check both the page body's `otpa-passwordless` class and card type.
If data is missing before the outer page, this one-boundary fix is insufficient.
Then exercise a logged-out request to `/otpa/passwordless-login/`, a normal
shortcode and Elementor shortcode widget, request a code for a test account,
verify it, confirm redirect/authentication, and check invalid/reused code rejection.
Repeat with Elementor/Pro active and inactive on PHP 8.1 and 8.3, and verify
Hide Login, dashboard roles, Extra Button, and configured redirects.
