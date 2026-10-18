<?php
/** Administrator documentation. Text only; examples are never executed. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
return array(
	'getting-started' => array(
		'title' => __( 'Getting Started', 'otpa' ),
		'paragraphs' => array(
			__( '1. Open Settings > OTP Authenticator > General Settings. Choose WordPress Email as the Authentication Gateway and select Enable Passwordless Authentication. Save Changes. Passwordless Authentication and Two-Factor Authentication cannot be enabled at the same time.', 'otpa' ),
			__( '2. Open Authentication Gateway Settings. Review Email Subject and the email message, then save your changes. The WordPress Email gateway sends through your site’s WordPress mail service; it does not provide SMTP server settings.', 'otpa' ),
			__( '3. Leave Sandbox Mode off for real email delivery. Sandbox Mode bypasses the gateway and sends the verification code to Activity Logs instead of the user. Use it only for controlled testing.', 'otpa' ),
			__( '4. Test in a private browser window with an existing WordPress account’s email address. Request a code, check the inbox and spam folder, and enter the code to sign in. This login form does not create new accounts.', 'otpa' ),
		),
	),
	'shortcodes' => array(
		'title' => __( 'Shortcodes', 'otpa' ),
		'paragraphs' => array(
			__( 'Add a Shortcode block to a WordPress page and paste an example below. Publish the page and test while logged out. Enable Passwordless Authentication first. The form uses your selected authentication gateway.', 'otpa' ),
			__( 'redirect_to sets the destination after a successful login. Replace the example destination with a published page on your own site. These examples use your site’s full home URL so they also work when the site is installed in a subdirectory.', 'otpa' ),
			__( 'show_logo="yes" displays the configured logo. The shortcode hides it by default (show_logo="no"); yes, true and 1 enable it. The optional class attribute adds one custom CSS class to the wrapper.', 'otpa' ),
		),
		'examples' => array(
			array( __( 'Basic passwordless form', 'otpa' ), '[otpa_passwordless_login]' ),
			array( __( 'Form with a login destination', 'otpa' ), '[otpa_passwordless_login redirect_to="' . home_url( '/account/' ) . '"]' ),
			array( __( 'Form with logo and login destination', 'otpa' ), '[otpa_passwordless_login show_logo="yes" redirect_to="' . home_url( '/account/' ) . '"]' ),
		),
	),
	'login-pages' => array(
		'title' => __( 'Login Pages', 'otpa' ),
		'paragraphs' => array(
			__( 'Use the dedicated passwordless URL below, or publish your own page containing the shortcode. The dedicated URL is based on your site’s home address. A shortcode page can include your own headings and surrounding content.', 'otpa' ),
			__( 'For users who are already logged in, the dedicated passwordless URL redirects to the site home page. The shortcode returns no form for logged-in users. Use a private browser window to preview the login form as a visitor.', 'otpa' ),
		),
		'examples' => array( array( __( 'Dedicated passwordless URL', 'otpa' ), home_url( '/otpa/passwordless-login/' ) ) ),
	),
	'redirects' => array(
		'title' => __( 'Redirects', 'otpa' ),
		'paragraphs' => array(
			__( 'General Settings > Default Login Redirect URL is the fallback after passwordless login. Leave it empty to return users to the site home page. A relative path such as /account/ is supported in this setting.', 'otpa' ),
			__( 'A valid redirect_to on the login URL takes priority over the default. On a shortcode page, a nonempty shortcode redirect_to overrides the URL value. Use a full URL on your own site for shortcode examples. WordPress redirect validation applies; unsafe destinations are discarded. Other plugins may also apply WordPress login redirect rules.', 'otpa' ),
			__( 'Redirects happen after successful authentication. Setting a destination does not grant access to a protected page or change a user’s role.', 'otpa' ),
		),
		'examples' => array( array( __( 'Dedicated login with a destination', 'otpa' ), add_query_arg( 'redirect_to', home_url( '/account/' ), home_url( '/otpa/passwordless-login/' ) ) ) ),
	),
	'hide-login' => array(
		'title' => __( 'Hide Login', 'otpa' ),
		'paragraphs' => array(
			__( 'In Hide Login, configure Login URL and enable Enable Hide Login. The public short login URL redirects to the dedicated OTP passwordless page. Confirm passwordless login works before enabling this protection, and bookmark your new login address.', 'otpa' ),
			__( 'With Hide Login enabled, direct wp-login.php and logged-out wp-admin requests redirect to the site home page. Native WordPress logout remains available with its normal nonce validation. When disabled, Hide Login leaves the normal WordPress login and admin behavior unchanged.', 'otpa' ),
			__( 'Redirect URL is a legacy compatibility setting; blocked login and logged-out admin requests now go to the site home page. Hiding a URL is not a replacement for strong account security. Keep an authenticated administrator session open while testing changes.', 'otpa' ),
		),
	),
	'dashboard-access' => array(
		'title' => __( 'Dashboard Access', 'otpa' ),
		'paragraphs' => array(
			__( 'In Hide Login, Enable Dashboard Access Restriction redirects logged-in users without an allowed role away from wp-admin. Choose additional roles in Allowed Dashboard Roles. This restriction can be configured independently of hiding the login URL.', 'otpa' ),
			__( 'Administrators and multisite super administrators remain allowed. Administrators are not offered as a role to remove. Restricted users also lose the frontend admin bar. AJAX, REST, cron and admin-post requests retain their existing exceptions.', 'otpa' ),
		),
	),
	'security' => array(
		'title' => __( 'Security', 'otpa' ),
		'paragraphs' => array(
			__( 'Hide Login includes Automatic Logout Due to Inactivity. Enable Automatic Logout Due to Inactivity, choose Inactivity Timeout (minutes), and optionally enable Show Warning Before Logout. Warning Before Logout (minutes) must be shorter than the timeout; zero uses a final 30-second warning.', 'otpa' ),
			__( 'Redirect After Logout chooses the destination after inactivity logout. Apply Automatic Logout To offers All Users or All Users Except Administrators. The administrator exclusion also covers users with manage_options and multisite super administrators.', 'otpa' ),
			__( 'Inactivity is tracked per WordPress login session. Tabs sharing a session share activity; a separate login has separate tracking. Meaningful interaction keeps the session active, while background polling and page reloads alone do not. Stay Logged In renews an active session; it cannot revive an expired one. The server checks expiration on subsequent requests, including when JavaScript is unavailable.', 'otpa' ),
			__( 'Use HTTPS, secure the email accounts used for login, and sign out on shared devices. Review OTP Verification Code Max. Requests, Max. Failed Verification Attempts, OTP Verification Code Request Frequency and OTP Verification Code Expiry in General Settings. Keep limits suitable for your users and leave Sandbox Mode off in production.', 'otpa' ),
		),
	),
	'appearance' => array(
		'title' => __( 'Appearance', 'otpa' ),
		'paragraphs' => array(
			__( 'Open OTP Form Style Settings to choose Logo, Submit Button Background Color, Submit Button Text Color and Links Text Color. Send Code Button settings control background, hover and text/icon colors. Save the settings and preview in a logged-out window. The shortcode only shows the logo when show_logo is enabled.', 'otpa' ),
			__( 'In Extra Button, set Extra Button Text and Extra Button URL. A configured button appears below Submit on the passwordless form and follows its styling. Leave the text or URL empty to hide it.', 'otpa' ),
		),
	),
	'elementor' => array(
		'title' => __( 'Elementor', 'otpa' ),
		'paragraphs' => array(
			__( 'In Elementor, add a Shortcode widget and paste [otpa_passwordless_login] or one of the examples above. Publish the page and check it while logged out. Elementor Pro is not required for the plugin’s shortcode.', 'otpa' ),
			__( 'The plugin exempts the dedicated passwordless endpoint and /otpa-api/otp from Elementor Coming Soon and Maintenance Mode using Elementor’s maintenance login-page filter. Your Elementor version must support that filter. Other public pages, including custom pages containing the shortcode, keep Elementor’s maintenance policy.', 'otpa' ),
			__( 'During maintenance, share the dedicated passwordless URL with users who need to sign in. A destination after login remains subject to its own access rules. External server or CDN maintenance rules can still block these URLs; check those separately.', 'otpa' ),
		),
	),
	'troubleshooting' => array(
		'title' => __( 'Troubleshooting', 'otpa' ),
		'paragraphs' => array(
			__( 'No form or a blank shortcode area: confirm Enable Passwordless Authentication is saved, and test while logged out. Paste the shortcode into a Shortcode block or Elementor Shortcode widget, not a code sample block. Check that another plugin is not replacing the page.', 'otpa' ),
			__( 'Missing email: check the account email address, spam folder and your site’s WordPress mail delivery. Confirm WordPress Email is selected and Sandbox Mode is off. Authentication Gateway Settings controls the message, not your mail server. If Activity Logs is enabled, review it for gateway errors; do not share verification codes.', 'otpa' ),
			__( 'Invalid or expired code: request a fresh code and use it before OTP Verification Code Expiry. Respect the request interval and temporary blocks rather than repeatedly clicking Send Code. Reload the form if a nonce/session error asks you to do so.', 'otpa' ),
			__( 'Missing fields during Elementor maintenance: use the dedicated passwordless URL and a current compatible Elementor version. Ordinary shortcode pages remain under maintenance. After updates or mode changes, clear relevant page caches and exclude login/API endpoints from caching.', 'otpa' ),
			__( 'Login URL shows a 404: in WordPress Settings > Permalinks, save the current settings to refresh rewrite rules, then retry. If redirects are unexpected, inspect the shortcode redirect_to, the URL redirect_to and Default Login Redirect URL, and confirm the destination exists.', 'otpa' ),
		),
	),
	'updates' => array(
		'title' => __( 'Updates', 'otpa' ),
		'paragraphs' => array(
			__( 'Back up the site before updating. In the Updates tab, review Installed Version and click Check for Updates Now. This refreshes the WordPress plugin update cache. If a newer GitHub Release is available, install the offered update from the WordPress Plugins screen.', 'otpa' ),
			__( 'Updates are delivered from GitHub Releases, not every commit on main. The updater prefers the WordPress-ready clever-otp-authenticator-main.zip asset and preserves the plugin folder name. Keep the existing plugin directory and main file name when installing manually.', 'otpa' ),
			__( 'Public repository updates normally need no GitHub token. Private repository access requires a token with access to the repository; configure it in Updates if needed. Do not paste tokens into pages, shortcodes or support messages. If no update appears, check connectivity to GitHub and confirm a newer release has been published.', 'otpa' ),
		),
	),
);
