Absolutely! Here’s a **full, copy-paste-ready README.md** including:

* API endpoints
* How to run tests
* Test descriptions

All in **one cohesive file**:

````markdown
# Flash Sale — README

## Project Overview

Minimal **Laravel 12 API** implementing a flash-sale checkout system:

- **Products** endpoint (cached product summary)  
- **Holds** (short-lived reservations, default 2 minutes)  
- **Orders** (pre-payment, created from holds)  
- **Payment webhook** with idempotency and out-of-order safety  
- **Background expiry job** to release expired holds  

This system ensures no oversell during high-concurrency flash sales.

---

## Assumptions & Invariants

- Each **Product** has:
  - `stock` — total units available  
  - `reserved_count` — units currently reserved  
  - `available = stock - reserved_count`  

- **Holds**:
  - Short-lived (TTL 120 seconds by default)  
  - Immediately increment `reserved_count` when created  
  - Can be used once for order creation  

- **Orders**:
  - Always created from a valid hold  
  - Stock is decremented only when order is marked `paid`  

- **Webhook idempotency**:
  - Requires `Idempotency-Key` header or `idempotency_key` in body  
  - Enforced via DB unique constraint: `payment_events.idempotency_key UNIQUE`  
  - Payment results accepted: `succeeded`, `paid`, `success` (case-insensitive)  

- **Invariants enforced transactionally**:
  - `reserved_count <= stock`  
  - Expiry job is idempotent (releases only active holds)  
  - Parallel hold requests are safe (no oversell)  

---

## Migrations / Models / Controllers / Jobs

- **Migrations**:
  - `products`  
  - `holds`  
  - `orders`  
  - `payment_events` (with unique `idempotency_key`)  

- **Models**:
  - `Product`  
  - `Hold`  
  - `Order`  
  - `PaymentEvent`  

- **Controllers**:
  - `HoldController` — create holds  
  - `OrderController` — create orders from holds  
  - `PaymentWebhookController` — receive payment events  

- **Jobs**:
  - `ExpireHoldJob` — releases expired holds  
  - Uses `DbRetry` service for safe retry on deadlocks  

---

## Environment Setup

**Requirements**: PHP, Composer, MySQL. (Redis optional.)

### `.env.example`

```dotenv
APP_NAME=FlashSale
APP_ENV=local
APP_KEY=base64:GENERATED_KEY_HERE
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flashsale
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
````

### `.env.testing` for MySQL

```dotenv
APP_ENV=testing
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flashsale_test
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=sync
CACHE_STORE=array
SESSION_DRIVER=array
```

---

## Running Locally

```bash
# Clone repo & install dependencies
composer install
cp .env.example .env
php artisan key:generate

# Create DB & run migrations
php artisan migrate
php artisan db:seed --class=ProductSeeder

# Start queue worker (expiry job requires worker)
php artisan queue:work --tries=3
# OR for testing only: QUEUE_CONNECTION=sync

# Serve the app
php artisan serve --host=127.0.0.1 --port=8000
```

---

## API Endpoints

| Method | Endpoint              | Body / Params                                   | Description                                                           |
| ------ | --------------------- | ----------------------------------------------- | --------------------------------------------------------------------- |
| GET    | /api/products/{id}    | N/A                                             | Fetch product summary (cached)                                        |
| POST   | /api/holds            | `{ product_id, qty }`                           | Create a hold (short-term reservation)                                |
| POST   | /api/orders           | `{ hold_id }`                                   | Create an order from a valid hold                                     |
| POST   | /api/payments/webhook | `{ idempotency_key, order_id/hold_id, result }` | Process payment webhook; supports idempotency & out-of-order delivery |

---

## Automated Tests

**Install dev dependencies for concurrency tests:**

```bash
composer require --dev guzzlehttp/guzzle
```

**Feature tests (tests/Feature/):**

1. **ParallelHoldTest**

   * Fires many concurrent `POST /api/holds` requests at a product with limited stock
   * Asserts **no oversell**
   * Requires server running & queue worker

2. **HoldExpiryTest**

   * Creates a hold and simulates expiry
   * Verifies `reserved_count` and available stock update

3. **WebhookIdempotencyTest**

   * Sends the same webhook twice
   * Asserts it is processed only once (idempotent)

4. **WebhookBeforeOrderTest**

   * Posts a webhook for a hold **before order creation**
   * Verifies order is processed correctly after creation

**Run tests:**

```bash
# Start server
php artisan serve --host=127.0.0.1 --port=8000

# Start queue worker
php artisan queue:work --tries=3

# Run all tests
php artisan test

# Run individual test
php artisan test --filter ParallelHoldTest
```

---

## Logs & Metrics

* Application logs: `storage/logs/laravel.log`
* Add `\Log::info()` for metrics (deadlocks, retries, webhook dedupe) in:

  * `DbRetry`
  * `HoldController`
  * `PaymentWebhookController`
  * `PaymentEventProcessor`

---

## Notes

* Stock updates and reserved count are **transaction-safe**
* Delayed jobs require queue worker (`QUEUE_CONNECTION=database`)
* Concurrency tests simulate high traffic and ensure invariants


