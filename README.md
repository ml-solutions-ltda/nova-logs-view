# Nova Logs View

Read-only log explorer for **Nova 3, 4 or 5, Laravel 8–13 and PHP 8.1+**. Includes dashboard summaries, file/date/level filters, pagination, recurring error fingerprints, sanitized details and text/Markdown/AI clipboard formats. The interface currently uses Brazilian Portuguese.

## Compatibility

| Nova | Frontend adapter | Laravel constraints |
| --- | --- | --- |
| 3.x | Vue 2.6 / Vue Router, native Blade sidebar | The versions allowed by your Nova 3 release, intersected with Laravel 8–13 |
| 4.x | Vue 3 / Inertia, native MenuSection | Laravel 8–11 according to the installed Nova 4 manifest |
| 5.x | Vue 3 / Inertia, native MenuSection | Laravel 10–13; Laravel 13 requires a Nova release that accepts it (for example 5.10.1) |

The package backend allows Laravel 8.83.4+, 9, 10, 11, 12 and 13 and Carbon 2 or 3. This does **not** make every Nova/Laravel pair compatible: Composer also enforces Nova's own requirements. PHP 8.1 is this package's minimum; Laravel 11/12 require 8.2 and Laravel 13 requires 8.3. Other host dependencies may impose higher requirements.

The correct bundle is selected automatically. No configuration switch or consumer frontend build is needed. Backend CI tests each Laravel major and public Nova adapter contracts; frontend CI mounts the shipped bundles with Vue 2.6, Vue 3.2 (Nova 4) and Vue 3.5 (Nova 5). A contract test is not a complete licensed Nova installation smoke. See [compatibility evidence](docs/compatibility.md).

Laravel 14/15 and future Nova majors are not declared compatible before release and verification. Supporting an older framework here does not extend that framework's upstream security maintenance.

## Install

Your application must already have a licensed supported Nova installation and its Composer repository/authentication configured. This package does not distribute Nova or supply a Nova license.

```bash
composer require ml-solutions/nova-logs-view:^1.1
```

The service provider is discovered automatically. Compiled assets are included; consumers do not need Node.js or a frontend build.

## Authorize and register

Define the `viewNovaLogs` Gate in your application's `AppServiceProvider::boot()`. Use an explicit application permission for trusted operators who may read application-wide logs. For example, if your application uses a `view application logs` permission:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('viewNovaLogs', fn ($user) => $user->can('view application logs'));
```

Register the tool in `NovaServiceProvider::tools()`:

```php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use MlSolutions\NovaLogsView\NovaLogsView;

public function tools(): array
{
    return [
        (new NovaLogsView)->canSee(fn (Request $request) =>
            $request->user() !== null
            && Gate::forUser($request->user())->allows('viewNovaLogs')
        ),
    ];
}
```

Both the page and API repeat the server-side Gate check. Guests and users without an explicitly allowed Gate receive HTTP 403. Do not grant access based solely on Nova login, and do not define `viewNovaLogs` in terms of itself. Existing applications may keep their current Gate policy.

## Configure

```bash
php artisan vendor:publish --tag=nova-logs-view-config
```

Defaults read allowlisted `*.log` files directly under `storage/logs`: at most 31 files, 4 MiB and 2,500 entries per file, 6,000 combined entries, and up to 100 entries per page. Truncated scans are identified in the interface. Adjust `config/nova-logs-view.php` in the consuming application.

Supports Laravel text logs, multiline exception traces and JSON-line logs. Hidden files, symlinks, traversal and paths outside the configured root are rejected. The viewer has no delete, clear or download endpoint.

Secrets, sensitive configured context keys and absolute server paths are masked before the response leaves the server. AI clipboard formatting also minimizes common personal identifiers. Redaction is pattern based: logs may contain sensitive business information, so access must remain restricted.

The optional 30/60-second refresh pauses while details are open or the browser tab is hidden. Fingerprints indicate recurring diagnostic evidence, not an automatically established root cause.

## Develop and test

Run these commands in your development container from the package root:

```bash
composer validate --strict
composer install --working-dir=tests
php tests/vendor/bin/phpunit --fail-on-warning
npm ci
npm run install:legacy
npm test
npm run production
node tests/check-dist.mjs
```

The portable backend suite uses Laravel Testbench and minimal public Nova interface doubles without proprietary Nova dependencies. To run against a licensed Nova source copy, set `NOVA_SOURCE=/path/to/nova` when invoking PHPUnit. Nova page integration should also be checked in a licensed consuming application. Commit the compiled `dist/` assets with source changes.

## Releases

Versions come from Git tags (`v1.0.0`, etc.), not a hardcoded Composer version. Keep releases immutable. To roll back in a consuming application, pin the previous version and run a targeted Composer update; this package has no database migrations.

## License

Proprietary. Copyright ML Solutions. Public availability of the repository does not grant an open-source license. Existing internal licensing is preserved.
