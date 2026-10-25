# Project: inventory, sales and money management API

Laravel 13 JSON API (Sanctum tokens) for a small trading shop. Single-tenant: one deployment and one database per shop. There is no tenant concept in the code.
The full design and the reasons behind it are in `docs/design.md`. Read it before changing the data model or any business rule.

## Commands
- Run tests (Pest): `php artisan test --compact`, using the narrowest set while working (a file path or `--filter`)
- Format: `vendor/bin/pint --dirty --format agent`
- Reset local data: `php artisan migrate:fresh --seed`
- List routes and their middleware: `php artisan route:list`
- Check data consistency: `php artisan inventory:verify` (once it exists)
- API docs (local only): `php artisan serve`, then open http://localhost:8000/docs/api. Export for Postman: `php artisan scramble:export`.
- Manual scenario tests: import `docs/postman/inventory-api.postman_collection.json` into Postman, run `php artisan migrate:fresh --seed`, then Run collection. Each phase adds its walkthrough to `docs/postman/scenarios.py` and re-exports (see the file header); `run` checks the same steps from the command line.
- Concurrency tests (MySQL in Docker): `docker compose up -d mysql-test`, then `php artisan test --compact --group=mysql`. They are skipped when MySQL is not reachable.

## Architecture rules (never break these)
- Stock and money are append-only ledgers (`stock_movements`, `account_transactions`). Never update or delete ledger rows or posted documents.
- Never store a value that can be calculated (stock, balances, paid amounts, payment status). Calculate it from the ledgers.
- Corrections are made by voiding or reversing with a required reason, never by editing amounts or quantities.
- Money is decimal(12,2). Do arithmetic in whole cents. Quantities are integers.
- Prices and costs are copied onto document items when the document is created.
- Any write that touches more than one row runs inside `DB::transaction`. Anything that depends on current stock or balance locks the rows first (`lockForUpdate`), in id order.
- Authorization uses spatie/laravel-permission. Check named permissions (for example `sales.void`) with `permission:` middleware or `$user->can()`, never role names. Permissions and roles are created in a seeder; run `php artisan permission:cache-reset` after changing them.
- Document numbers (PUR-000123, SAL-000123) come from the `document_sequences` table: lock the row inside the transaction, increment, then format.
- Keep data access in Eloquent models and Form Requests. No scattered raw queries and no hard-coded single-shop assumptions, so multi-tenancy stays possible later.
- Use `Relation::morphMap()` for polymorphic aliases, never `enforceMorphMap()` (it breaks Sanctum tokens).

## Conventions
- Routes live under `/api/v1`. Controllers are thin and live in `App\Http\Controllers\Api\V1`.
- Validation in Form Requests. Output through API Resources. Never return models directly.
- Status codes: 401 not logged in, 403 not allowed, 404 not found, 409 conflict, 422 validation or business-rule failure.
- Enums for fixed value sets (roles, movement types, transaction types, statuses).
- Exclude soft-deleted products and other inactive records in `exists` rules where they must not be used.

## Workflow
- For anything that touches several files or changes the data model, propose a plan first and wait for approval.
- Write or update tests with every change, in Pest (`php artisan make:test --pest`).
- Run the relevant tests as you work, and the full suite before saying a phase is done. Report the result.
- The rules in this file are the project's business rules. If they conflict with the generic Laravel Boost guidelines, follow this file.
- Keep changes small and commit after each working step with a clear message.
- If a request conflicts with `docs/design.md`, say so instead of silently working around it.
- Concurrency tests need MySQL or PostgreSQL; SQLite ignores row locks.






<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
