# Hosting Security

## Record Links

Record pages use authenticated-encrypted URL prefixes generated with Laravel's encrypter. For example, a provider program link becomes `/provider/r/<opaque-token>/edit/benefits`. Tokens are URL references, not login or access credentials. Every decoded request still passes through the original route bindings, authentication, role permissions, and ownership checks.

Old numeric links remain compatible: successful browser navigations redirect to their opaque equivalent. Forms and internal JSON APIs retain numeric database IDs. JavaScript reads the logical route from server-provided context; wizard history retains the opaque prefix, including after the first draft save. Signed email links, external payment redirects, and webhooks are not rewritten. No record IDs, relationships, passwords, or database schema are changed.

Tokens intentionally remain usable for bookmarks. Protect and back up `APP_KEY`; rotating it changes the validity of encrypted references unless the previous key is retained through Laravel's previous-key support. Invalid, altered, wrong-purpose, or cross-area tokens return 404.

## Browser And Server Protections

- Production CSP uses a fresh script nonce and allows only the existing map, icon, font, and location services. Same-origin frames remain available for legitimate previews.
- HTTPS production responses use HSTS without subdomain/preload commitments. Secure, HttpOnly session cookies remain compatible with external payment returns through SameSite=Lax.
- Authenticated pages and protected files are not stored by shared/browser caches. MIME sniffing and cross-origin framing are restricted; referrer URLs are not sent to external sites.
- Apache rules disable directory indexes, deny application/configuration directories, and prevent script execution through upload URLs.
- Existing per-IP sign-in throttling remains, with an additional five-failed-attempt account limit per minute. Successful authentication clears the account failure counter.
- Production dependencies must pass Composer and npm audits before deployment. Deployment backs up runtime/dependencies and verifies business-data, environment, and upload preservation; it does not migrate or reset the database.

## Remaining Demo Risk

The owner explicitly chose to retain shared demo passwords. These remain unsuitable for production use. Use unique strong staff passwords before enabling real applicant data or real financial transactions. This hardening is not a complete penetration test or a guarantee against every vulnerability.

References: [OWASP IDOR prevention](https://cheatsheetseries.owasp.org/cheatsheets/Insecure_Direct_Object_Reference_Prevention_Cheat_Sheet.html), [OWASP response headers](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html).
