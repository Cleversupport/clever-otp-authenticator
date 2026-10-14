# Automatic Logout Due to Inactivity

Version 1.0.21 adds an internal module; the installed directory, entry point,
GitHub updater and `clever-otp-authenticator-main.zip` release layout are unchanged.

Configure **Settings > OTP Authenticator > Hide Login > Automatic Logout Due to
Inactivity**. Defaults: disabled, 30-minute timeout, warning enabled two minutes
before logout, redirect `/otpa/passwordless-login/`, applies to all users.
Administrators (administrator role or `manage_options`) and multisite super
administrators can be excluded. Numeric values must be integers; invalid values
fall back to defaults. Warning duration is clamped below the timeout. A zero
warning duration uses a final 30-second warning, including a one-minute timeout.
Redirects must use this site's scheme, host and port; relative paths are expanded with
`home_url()` and all redirects use WordPress validation.

## Security and timing

The module records server timestamps in user metadata indexed by a salted HMAC
of the WordPress session token plus the current site ID. The raw token never
reaches JavaScript or metadata. Expiration calls `wp_logout()` to destroy only
that token and clear authentication cookies. State is checked at `init` on
cookie-authenticated requests; expired AJAX and REST requests return HTTP 401.
Document requests redirect to the configured destination without routing through
a blocked login screen. An authenticated session without an activity record receives a server-time
baseline on its first protected request. This gives users already logged in a
full timeout after enabling the feature. Existing records, including expired
records, are never reset by initialization or polling. Protection remains
request-driven; an unobserved session begins tracking on its first request. Independent logins remain unaffected. Metadata cleanup runs on
`clear_auth_cookie` before user/cookie state disappears, including on WordPress
versions before 5.5 where `wp_logout` supplies no user ID. It removes only that
token's records across sites and remains registered when protection is disabled.
Native `wp-login.php?action=logout` requests retain core nonce validation and
redirect handling even if the idle deadline has passed. Multisite sites use their own
settings and activity record; expiring a shared WordPress token logs it out
across the network.

Only nonce-protected authenticated POST reports renew a session. Expiration is
checked before renewal. Heartbeat, REST calls, page reloads and status checks do
not renew it. Trusted clicks, keys, touch, scrolling and pointer presses report
activity, with 500ms listener throttling, ten-second normal report coalescing and
immediate reports near the server deadline. Queued reports include interaction
age, so a delayed report does not turn sleep/wake into new activity. Database updates are throttled to
five seconds (explicit Stay Logged In renewals are immediate), using a previous
value comparison to avoid replacing a newer timestamp. Expiration rechecks
metadata at the boundary, and activity responses refresh it after a competing
write. Native token verification prevents attaching tracking to an invalid cookie
when another authentication mechanism supplied the current user. Timing can differ by a
few seconds because reports are coalesced; the server deadline is authoritative.

PHP checks expiration on subsequent requests, not through an independent
scheduled task. JavaScript sends a status request at the deadline, so actual
logout follows that server check. Sleep, suspended tabs and disabled JavaScript
do not revive an expired session. Without JavaScript, no requests count as
meaningful interaction, and the next authenticated request expires the session.
Activity reporting establishes an authenticated browser interaction signal;
it cannot prove that a client has not been automated.

## Browser behavior

An opaque session/site key scopes localStorage and BroadcastChannel messages.
Activity in another tab shares its deadline and dismisses warnings. Server
status checks remain authoritative before expiration, so an idle tab cannot
force logout while another tab keeps the server session active. Visibility
changes and back/forward cache restores check the session again. If localStorage
is unavailable, BroadcastChannel still communicates; if both are unavailable,
server checks preserve session safety, though warning UI is independent per tab.
Automatic logout broadcasts confirmation to all tabs. In-flight interaction and
Stay requests are preserved, stale messages cannot shorten newer deadlines, and
BroadcastChannel activity is retained even when localStorage is blocked. The
countdown continues during requests. A bare AJAX `0` is a failure, not proof of
logout; a read-only unauthenticated status handler reports expired authentication
without exposing a logout or renewal action.

The warning has a labeled alertdialog, keyboard focus trapping/restoration,
a per-second timer and a Stay Logged In button. Renewal requires a valid server
session. On request failures it keeps the warning, retries every five seconds,
and provides a native nonce-protected WordPress logout link. It never assumes a
network failure means logout succeeded. CSS uses only namespaced selectors and
the modal is attached to the document body, outside theme/Elementor containers.

## Automated checks

Run from the repository root:

```sh
php tests/inactivity.php
php tests/hide-login-logout.php
node tests/inactivity.js
node --check js/inactivity.js
git ls-files '*.php' ':!:libraries/**' | while read -r file; do php -l "$file" || exit 1; done
```

PHP tests use isolated WordPress doubles to check validation, nonce protection,
renewal, expiration, role exclusions, session isolation, REST rejection and
visitor/disabled asset behavior. JavaScript tests simulate two browser tabs,
time, WordPress responses and network failure to check warnings, countdown,
renewal, synchronized activity/logout and sleep/wake expiration. These are
contract tests, not a live WordPress or Elementor integration test. CI runs
them and syntax checks on PHP 7.0 and 8.4 without application services.

## Manual review on a staging WordPress installation

1. Install the existing plugin package in `clever-otp-authenticator-main`; verify
   its basename, updater and passwordless/email gateway settings still work.
2. Leave protection disabled. Check frontend and wp-admin page source for absence
   of inactivity assets and confirm existing OTP, Extra Button, redirect_to,
   default login redirects, admin bar and dashboard restrictions behave normally.
3. Enable protection with a one-minute timeout and warning enabled. Open a normal
   frontend page, an Elementor shortcode page and wp-admin. Leave each idle;
   check the final 30-second warning, countdown, keyboard tab order, focus return
   and narrow viewport. Click Stay Logged In and repeat with warning disabled.
4. Test clicks, typing, touch, pointer presses and scrolling. Mouse movement,
   repeated reloads and Heartbeat must not keep the session alive.
5. Open two tabs using the same login. Keep one active while the other is hidden;
   verify no premature logout, then leave both idle and verify both redirect.
   Repeat with localStorage blocked and with BroadcastChannel unavailable.
6. Sleep the computer beyond the timeout or disable JavaScript and wait. The next
   request must reject the old cookie. A warning button on an expired page must
   not revive it. A separate browser login of the same user must still work.
7. Block admin-ajax requests at expiration. Confirm warning, retry and secure
   logout fallback. Restore networking and confirm real logout. Reusing the old
   cookie against a protected page must fail; inspect authentication cookie clears.
8. Enable Hide Login and dashboard restrictions. Verify expiration redirects
   directly to `/otpa/passwordless-login/`, and native manual logout still works.
9. Test safe custom relative and same-origin absolute redirects and malicious
   external, protocol-relative and credential-bearing URL inputs.
10. Select administrator exclusion; test administrator, subscriber and multisite
    super administrator sessions, plus independently configured multisite sites.
11. Enable protection while logged in with an old untracked session: its first
    protected request must start a full timeout. An already tracked expired
    session must still expire. Verify manual/automatic logout removes metadata
    for that token across sites while retaining another independent login.
12. Send expired authenticated AJAX/REST requests; expect HTTP 401 and no renewal.
    Verify normal passwordless login, OTP email and shortcode redirects afterward.

Review is required before merging. No release is created by this change.
