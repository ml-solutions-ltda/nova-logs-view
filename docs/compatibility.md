# Compatibility design

## Scope and decision

Extend the existing package with Nova 3, 4 and 5 adapters and Laravel 8 through 13 backend support. PHP 8.1 is the package minimum because the shared backend uses readonly promoted properties. Laravel 11/12 require PHP 8.2, and Laravel 13 requires PHP 8.3. Composer must also satisfy the installed Nova version's own framework requirements: this is not a Cartesian product of all versions.

Changing dependency constraints alone cannot support Nova 3: Nova 4 replaced Vue 2 / Vue Router with Vue 3 / Inertia. Ship separate precompiled Vue 2 and Vue 3 bundles from one shared Tool component. Use Nova's native Tool render/navigation contract for Nova 3 and MenuSection/Inertia for Nova 4/5. Use the script/style APIs shared by all three majors instead of Nova 5's mix helper. Keep the modern dist paths stable for existing consumers.

The backend stays shared and allows Carbon 2 and 3. Do not widen to unreleased Laravel 14/15 or future Nova majors. Extend explicit constraints only after running the compatibility checks on the new major.

## Security and lifecycle

Every API remains GET-only, behind Nova authentication and the existing viewNovaLogs Gate. The Nova 3 sidebar uses an escaped Blade view and native router-link; there is no global DOM manipulation. No proprietary Nova code or license is shipped. Both Vue lifecycle variants remove listeners, invalidate requests and cancel refresh/copy/filter timers. File allowlisting, traversal rejection, bounds and redaction stay common across framework versions.

## Verification plan

Run real Laravel/Testbench backend matrices from Laravel 8 through 13; authorization, parsing, redaction and file boundaries must pass. Compile both Vue runtime targets, mount them with the respective Vue runtimes, exercise rendering and unmount cleanup, and verify asset integrity. Test adapter contracts separately; licensed Nova 4/5 source checks supplement these but do not establish a browser smoke on every Nova installation. Verify Composer resolution against installed Nova requirements. A licensed Nova 3 application smoke remains a separate check if its source/application is unavailable.

References: https://nova.laravel.com/docs/v4/upgrade , https://nova.laravel.com/docs/v4/customization/tools , https://nova.laravel.com/docs/v4/installation , https://nova.laravel.com/docs/v5/upgrade , https://laravel.com/framework/docs/releases

## Local evidence for 1.1.0

- Both production bundles compiled in the Meu Parlamentar Laradock workspace (Node 22, PHP 8.3).
- Nine frontend tests passed, including bundle mounting with Vue 2.6.14, Vue 3.2.29 and Vue 3.5; empty/list/detail rendering, HTML escaping and cleanup were exercised.
- On Laravel 12/Testbench 10, all three Nova interface adapters passed 13 backend tests each. Nova 3 uses interface doubles because a licensed Nova 3 source/application was unavailable.
- The same 13 backend tests passed with licensed Nova 4.35.3 and Nova 5.10.1 source copies (57 assertions each), including tool loading, native menu, provider routes and asset registration. Those copies are local test inputs and are not published.
- Cross-Laravel backend verification is implemented in GitHub Actions for Laravel 8–13 with the corresponding Testbench and PHP versions. Release publication requires all matrix jobs to pass.
- No complete authenticated browser smoke is claimed for all three Nova majors. Applications must retain their own authorized Nova/framework combination and operational access Gate.
