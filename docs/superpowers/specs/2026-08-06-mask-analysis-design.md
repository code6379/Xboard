# Subscription Mask Analysis Page Design

## Goal

Provide a standalone, password-protected Blade page for investigating suspicious use of subscription links recorded by `SubscriptionMaskLog`. The page must work without changing the existing admin SPA, controller, route, or configuration files. The only existing-file change allowed is adding `MASK_ANALYSIS_PASSWORD` to `.env` during implementation.

## Entry Points

The existing route provider discovers every PHP file in `app/Http/Routes/V2`, so a new `MaskAnalysisRoute` class can register these endpoints without modifying existing route files:

- `GET /api/v2/mask-analysis`: show the password form or, after authentication, the analysis page.
- `POST /api/v2/mask-analysis/login`: validate the password and issue an encrypted, HTTP-only access cookie.
- `POST /api/v2/mask-analysis/logout`: clear the access cookie.
- `GET /api/v2/mask-analysis/data`: return the filtered aggregates and paginated log entries. It requires the access cookie.

All endpoints are under the existing API group. The controller will return Blade views for the HTML endpoint and JSON for the data endpoints.

## Access Control

`MASK_ANALYSIS_PASSWORD` is the only accepted page password. It is not embedded in HTML, JavaScript, or the database.

Successful logins create a Laravel-encrypted, `HttpOnly`, `SameSite=Lax` cookie with an eight-hour lifetime. The cookie proves only that the password was validated; it does not contain the password. Requests to the data endpoint decrypt and validate the cookie before querying logs. Invalid or expired cookies receive HTTP 401. Login attempts are limited per source IP to five failures per fifteen minutes using Laravel's rate limiter. The controller returns HTTP 503 if the environment password is absent, so an accidentally unconfigured page never grants access.

## Data Model and Query Scope

The page reads only `v2_subscription_mask_logs`. It creates no tables and writes no audit-log records.

The default time window is the last seven calendar days. The request accepts a start and end date, but enforces a maximum 31-day span. Supported filters are email substring, exact IP, country code, rule reason, proxy-only, masked-only, and minimum fraud score. Page size is capped at 100 records.

The response contains:

- Summary counts: total requests, distinct users, distinct IPs, masked requests, proxy requests, and high-risk requests (`fraud_score >= 70`).
- Suspicion lists: shared IPs used by two or more users, users with two or more distinct IPs, users observed in two or more countries, and high-frequency user/IP pairs with five or more requests.
- Paginated raw records with the fields required to investigate a finding: timestamp, user ID, email, IP, geography, ASN/ISP, proxy state, fraud score, rule reason, masking result, and client flag.

All aggregate queries inherit exactly the same date and filter constraints as the raw log list. Aggregates are bounded to their top 20 results and use existing date-composite indexes where possible.

## Blade Experience

The login view contains a single password input and an error state. The analysis view is a restrained operations page rather than an admin-SPA clone:

- Compact filter bar and apply/reset controls.
- Six fixed-size summary counters for fast scanning.
- Four ranked suspicion tables that link their values into the raw-log filters.
- A paginated detail table with explicit status badges for proxy, high risk, and masked output.
- Logout control and loading, empty, error, and unauthorized states.

The view uses only Blade, CSS, and browser APIs. No build step, JavaScript framework, or external asset is required.

## Files and Tests

Implementation will create a route class, controller, query service, two Blade views, and a feature test. No existing PHP, route, theme, or SPA file is modified. The implementation adds the documented password entry to `.env` after the tests are written. The feature tests cover password configuration, wrong-password throttling, successful cookie authentication, unauthorized data access, filtered query results, and the shared-IP aggregation.

## Non-Goals

The first version does not add menu navigation, export, user banning, mutation actions, or a new database migration. It is an investigation surface only.
