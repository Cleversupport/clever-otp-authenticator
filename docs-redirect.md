
# Redirect after OTP verification

This fork adds support for post-login redirects:
- Standard `redirect_to` query param in the login URL.
- Any intermediate redirects saved by OTP Authenticator (`otpa_set_otp_redirect`).
- A site-wide default via the `otpa_custom_default_redirect` filter.

Example: force dashboard after login

```php
add_filter('otpa_custom_default_redirect', function($url){
    return admin_url();
});
```
