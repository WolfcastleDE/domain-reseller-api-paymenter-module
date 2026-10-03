# Domain Reseller API – Paymenter extension

Server extension for [Paymenter](https://paymenter.org) (v1) that sells and manages domains through
[domain-reseller-api.de](https://domain-reseller-api.de). Each domain is a Paymenter service. The
extension registers or transfers the domain when the service is created and keeps it in sync with
the billing lifecycle. Customers manage it from the client area.

## Features

**Ordering**
- Registration and transfer (auth code) per product, with a configurable TLD allow-list.
- The domain is checked live against the API at checkout: syntax, allowed TLD, availability and premium status.
- Nameservers: managed DNS, fixed default nameservers, or nameservers entered by the customer.
- Optional WHOIS privacy (OpenProvider TLDs, private persons only).
- IDN support: names are converted to punycode.
- Problems are caught **before payment**: an incomplete customer profile (missing house number,
  phone number, ...), or a reseller wallet that cannot pay for the order. In the wallet case,
  customers see a neutral message and admins get a notification.
- Taken domains come with available alternatives from the product's TLDs.
- **Public domain search** (`/domain-search`, linked in the navigation). It checks a name across
  all domain products and links to the checkout with the domain already filled in.

**Provisioning**
- An owner contact handle is created automatically from the customer profile. The street and house
  number are split, phone numbers are converted to `+CC.NUMBER`, and country names become ISO codes.
  The handle is reused for all of the customer's domains. If the customer edits their profile, a new
  handle is created so existing domains never change silently.
- Optional reseller-wide admin, tech and billing handles.
- Provisioning is idempotent. A retry never registers a domain twice. If a request timed out but the
  domain was created, it is adopted, but only when it is registered to the same customer.
- A domain that another active service already manages is refused.

**Billing lifecycle**

| Paymenter event | Action at the API (configurable) |
| --- | --- |
| Service suspended | Auto-renew is switched off. The domain keeps working. |
| Service unsuspended / renewal paid | Auto-renew is switched on again, a scheduled deletion is cancelled, and the domain is restored from redemption (optional). |
| Service terminated / cancelled | The domain is cancelled at the end of its term, deleted immediately, has auto-renew disabled, or nothing happens. |

Renewals run through the registry auto-renew of the API (there is no separate renew endpoint). The
optional *auto-renew guard* pauses auto-renew shortly before expiry while the renewal invoice is
unpaid. It resumes as soon as the invoice is paid.

For yearly plans, the Paymenter due date follows the registry expiry date. Renewal invoices are
therefore created before the registry renews the domain, including after transfers, which add a
year at the registry.

**Client area** (tabs on the service page)
- Overview: status, registration and expiry dates, auto-renew, nameservers, contacts.
- Nameservers: edit 2–6 nameservers, or switch back to managed DNS.
- DNS: list, add, edit and delete records (A, AAAA, CNAME, MX, TXT, SRV, CAA, NS, TLSA, HTTPS, SVCB, Redirect).
  Zone files can be imported (BIND format) and downloaded.
- Contacts: update the owner contact data, and change the owner. Free owner changes only by default;
  chargeable ones (e.g. a .eu trade) can be allowed per product, with the price shown before
  confirmation.
- DNSSEC: zone signing for managed DNS, or DNSKEY records for external nameservers.
- Auth code: reveal and copy the code, and reset it (.de). Outgoing transfer requests can be approved
  or rejected here.

Each tab can be disabled per product. Translations: English, German, French, Spanish, Italian, Dutch.

**Notifications** (Paymenter's bell icon)
- Customers: a transfer completed or failed.
- Admins:
  - provisioning failed
  - a transfer failed
  - unexpected registry status changes (expired, redemption, transferred away, ...)
  - an order was refused because the wallet balance was too low
  - the wallet will not cover the renewals of the next 30 days
  - a chargeable owner change
  - the API's `wallet.low_balance` webhook

**Admin & automation**
- *Test connection* button on the server.
- **Admin → Administration → Domains** lists every domain with its customer, registry status, expiry,
  due date and auto-renew, plus the wallet balance. Row actions:
  - sync now
  - retry provisioning
  - auto-renew on/off
  - restore from redemption
  - cancel or schedule deletion
  - .de hold/release
  - approve or reject an outgoing transfer
  - show the auth code
- Webhook receiver with HMAC verification. The webhook is registered at the API automatically when
  the server is saved; its secret is stored encrypted.
- `domain-reseller-api:sync` runs hourly via the Paymenter scheduler. It updates status and expiry
  in the service properties and applies the auto-renew guard.
- `domain-reseller-api:import-tlds` creates one product per TLD from the API price list, with your mark-up.

## Requirements

- Paymenter v1 (Laravel 12, Livewire 3/4), PHP 8.3+, `ext-intl` (already required by Paymenter)
- An account and API key at domain-reseller-api.de

## Installation

1. Download `DomainResellerApi-x.y.z.zip` from the releases and upload it in the Paymenter admin
   under **Extensions**. Alternatively, copy the `DomainResellerApi` folder to
   `extensions/Servers/DomainResellerApi` in your Paymenter installation.
2. **Admin → Servers → New server**, choose *Domain Reseller API*, enter the API key and press
   *Test connection*. Enable *Sandbox mode* for testing; no real registrations or charges happen then.
3. Create products, either manually or with the import command below. Assign the server to them.
   Configure the allowed TLDs and the other options in the product's *Server* tab.
4. The webhook is registered automatically when you save the server. This needs a public HTTPS
   URL for Paymenter. If that fails (e.g. on a local test system), a warning is shown. In that case,
   create a webhook of type *custom* in the domain-reseller-api.de dashboard. Point it to
   `https://your-paymenter/extensions/domain-reseller-api/webhook`, subscribe to the `domain.*`
   events, and paste the secret into the server settings.

The API key needs the scopes `domains`, `contacts`, `dns`, `webhooks` and `billing` (read). Without
`billing`, the wallet checks are skipped.

Make sure the Paymenter scheduler (`php artisan schedule:run` every minute) is running. The default
Docker image already does this.

### Product setup

Use **one product per TLD**, or per group of TLDs with the same price. Use a **yearly plan**.
Paymenter charges *price + setup fee* for the first year and *price* for renewals. Use the setup fee
to cover a registration price that is higher than the renewal price. Transfers are billed at the
transfer price of the TLD. If that price differs from the registration price, create separate
transfer products (`--mode=transfer`).

### Importing TLD prices

```bash
# Preview prices with a 25 % mark-up, rounded to .99, converted to USD
php artisan domain-reseller-api:import-tlds --tlds=de,com,eu --margin=25 --ending=0.99 --currency=USD --rate=1.08 --dry-run

# Create the products (category "domains"); use --update-prices to update existing products
php artisan domain-reseller-api:import-tlds --tlds=de,com,eu --margin=25 --ending=0.99
```

API prices are net EUR purchase prices. Paymenter adds taxes according to your tax settings.

### Settings reference

| Server setting | Description |
| --- | --- |
| API key / API URL / Sandbox | Credentials. The URL must use HTTPS (localhost is allowed for development). |
| Admin / tech / billing handle | Optional handles used for all domains instead of the customer. |
| When suspended / terminated | See *Billing lifecycle*. |
| Restore on unsuspend | Restore domains from redemption automatically. The restore fee is charged to your wallet. |
| Auto-renew guard (days) | Pause auto-renew this many days before expiry while the renewal is unpaid. `0` = off. |
| Do not check the wallet balance | By default, orders the wallet cannot pay for (balance + credit limit, or an active auto top-up) are refused. Tick for accounts that are not billed. |
| Do not align the service due date | By default, the due date of yearly services follows the registry expiry. |
| Do not register the webhook automatically | By default, the webhook is created when the server is saved. |
| Webhook secret | Secret of the custom webhook. Filled in automatically. |

| Product setting | Description |
| --- | --- |
| Allowed TLDs | TLDs orderable with the product. Empty = all. |
| Allow registrations / transfers | Which order types are offered at checkout. |
| Registration period | Years per registration. Should match the plan. |
| Nameservers | `managed`, `default` (list below), or `customer` (entered at checkout, with fallback). |
| WHOIS privacy | `off`, `optional` (customer chooses), or `always`. |
| Allow premium domains | Premium domains cost more than the product price. Disabled by default. |
| Owner changes by customers | `off`, `free` (default: only free changes), or `all` (including chargeable ones; the fee is debited from your wallet). |
| Hide from the public domain search | Leave the product out of `/domain-search`. |
| Customer features | Enable or disable the individual client-area tabs. |

## Service properties

The extension stores its state as service properties, which are visible to admins on the service page:
`domain`, `action`, `dra_domain_id`, `dra_status`, `dra_expires_at`, `dra_auto_renew`,
`dra_owner_handle`, `dra_nameservers`, `dra_last_sync` and `dra_autorenew_guarded`. The transfer
auth code is removed once the transfer has been submitted.

## Development

```bash
composer install
vendor/bin/phpunit

# or without a local PHP installation
docker build -t dra-paymenter-tests . && docker run --rm dra-paymenter-tests
```

The unit tests replace the Paymenter classes the extension depends on with small stubs
(`tests/Stubs`) and replace the HTTP layer with a fake transport.

Tagging `vX.Y.Z` builds the installable zip through GitHub Actions.

### End-to-end tests with Docker

`e2e/` starts a complete local environment with Docker Compose. It contains:

- MySQL
- the Domain Reseller API backend in sandbox mode, built from a local checkout of the backend repository
- the official Paymenter image with this extension installed
- a Playwright browser runner

```bash
./e2e/run.sh          # start, set up and run all tests; the environment keeps running
./e2e/run.sh setup    # start and set up only, for clicking through manually
./e2e/run.sh test     # re-run the tests (picks up extension code changes)
./e2e/run.sh down     # remove everything
```

The backend checkout is detected automatically next to this repository or in `~/Projects`.
Otherwise set `BACKEND_PATH=/path/to/domain-reseller-api`. Paymenter runs on
<http://127.0.0.1:18080> with these accounts:

- customer: `kunde@e2e.test` / `e2e-password`
- admin: `admin@e2e.test` / `e2e-password`

Screenshots are written to `e2e/artifacts/`.

The suite covers:

- **Setup:** extension discovery, installation from the release zip, and the connection test.
- **Checkout:** validation in the browser checkout. The order is then paid, and Paymenter's create
  job registers the domain at the API.
- **Lifecycle:** suspend, unsuspend, terminate and re-activate; due-date alignment; the auto-renew
  pause for an unpaid renewal and its resumption on payment.
- **Transfers:** transfer-in with an auth code; completion and failure through signed webhooks,
  including the customer and admin notifications.
- **Integration:** signed webhooks, the sync command, the scheduler entry, the price import and
  webhook registration.
- **Client area:** every tab, used in a real browser, including the owner change and zone file
  import/export.
- **Domain search:** the search page and its checkout links.
- **Admin area:** the server and product forms, and the Domains page with its row actions.

Switching a domain back to managed DNS needs `BUNNY_DNS_API_KEY` on the backend, even in sandbox
mode. Without it, the test only checks that the API error is shown to the customer.

The suite can also run on GitHub Actions (workflow *E2E*, started manually). It needs a token with
read access to the private backend repository, stored as the secret `BACKEND_REPO_TOKEN`.

### Checking the live API

```bash
LIVE_API_KEY=drapi_… ./e2e/run.sh live
```

This runs every read endpoint the extension uses against the production API and reports the
results:

- health
- domains, pricing and contacts
- wallet and auto top-up
- availability checks and suggestions
- pending transfers and webhooks

It registers nothing and costs nothing. For a full production test, configure a cheap TLD
product in sandbox mode first. Then switch sandbox off, order one domain, and cancel the service
afterwards.

## License

MIT
