# ⚡ Flash Sale API — High-Concurrency Inventory Management System

## 📌 Overview
This repository contains a Laravel 12-based API system designed to handle high-concurrency flash sale checkout scenarios while preventing overselling through strict inventory management and concurrency control.

The system enforces the invariant:

> **reserved_count ≤ stock**

through transactional consistency, pessimistic locking, idempotent webhook processing, and background job execution.

---

## 🎯 System Purpose
Flash sales create extreme concurrency where hundreds of users attempt to purchase limited inventory simultaneously. Naive systems risk overselling.

This system prevents overselling using:

- Row-level database locking (`lockForUpdate()`)
- Two-phase reservation flow (Hold → Order → Payment)
- Idempotent webhook handling
- Deadlock retry logic
- Background job-based hold expiration

---

## 🧠 Core Business Concepts

| Concept | Description |
|--------|-------------|
| Product | Represents inventory with stock and reserved count |
| Hold | Temporary reservation for a product |
| Order | Created from a hold before payment |
| PaymentEvent | Tracks payment gateway callbacks |

Flow:
**Product → Hold → Order → Payment → Inventory Finalization**

---

## 🛠️ Technology Stack

| Component | Technology |
|------------|------------|
| Framework | Laravel 12 |
| Language | PHP 8.2+ |
| Database | MySQL (SQLite for tests) |
| Queue | Database-backed queue |
| Cache | Database cache |
| Testing | PHPUnit 11.5+ |
| CI/CD | GitHub Actions |

---

## 🏗️ System Architecture

The application is organized into layered architecture:

### 🔹 API Layer
Handles incoming HTTP requests and responses.

### 🔹 Business Logic Layer
Contains concurrency control, retry logic, and transactional processing.

### 🔹 Background Processing Layer
Manages hold expiration asynchronously.

### 🔹 Data Layer
Persists products, holds, orders, and payment events.

---

## 🧩 Core Components

### API Controllers

| Controller | File | Responsibility |
|--------------|--------|----------------|
| ProductController | `app/Http/Controllers/ProductController.php` | Returns product summaries with available stock |
| HoldController | `app/Http/Controllers/HoldController.php` | Creates time-limited reservations |
| OrderController | `app/Http/Controllers/OrderController.php` | Converts holds into orders |
| PaymentWebhookController | `app/Http/Controllers/PaymentWebhookController.php` | Processes payment callbacks with idempotency |

---

### Business Services

| Service | File | Responsibility |
|----------|--------|----------------|
| DbRetry | `app/Services/DbRetry.php` | Wraps DB ops with deadlock retry logic |
| PaymentEventProcessor | `app/Services/PaymentEventProcessor.php` | Finalizes orders based on payment results |

---

### Background Jobs

| Job | File | Responsibility |
|------|--------|----------------|
| ExpireHoldJob | `app/Jobs/ExpireHoldJob.php` | Releases expired holds and decrements reserved_count |

---

### Data Models

| Model | File | Key Fields | Purpose |
|--------|--------|--------------|-----------|
| Product | `app/Models/Product.php` | stock, reserved_count | Tracks inventory |
| Hold | `app/Models/Hold.php` | status, expires_at, quantity | Temporary reservations |
| Order | `app/Models/Order.php` | status, paid_at, hold_id | Pre-payment orders |
| PaymentEvent | `app/Models/PaymentEvent.php` | idempotency_key, processed_at | Webhook deduplication |

---

## 🔐 Key System Guarantees

### ✅ No Overselling
`available = stock - reserved_count` is maintained transactionally.

### ✅ Hold Idempotency
Expired holds are released exactly once, even if jobs run multiple times.

### ✅ Payment Idempotency
Webhooks with the same `idempotency_key` are processed only once.

### ✅ Transaction Safety
All inventory mutations occur inside database transactions with pessimistic locking.

---

## 🔄 Request Flow Example

1. Client requests product list
2. Client creates a hold (`POST /api/holds`)
3. System locks product row and increments `reserved_count`
4. Client converts hold into order (`POST /api/orders`)
5. Payment gateway sends webhook
6. Webhook processed idempotently
7. Stock decremented, reserved count reduced
8. Order marked paid

---
# Rantget API - Hold, Order, and Payment Requests

---

## 1. Create Hold

```http
POST /api/holds
Content-Type: application/json

{
  "product_id": 1,
  "quantity": 1
}
```
## 2. Create order
```http

POST /api/orders
Content-Type: application/json

{
  "hold_id": 5
}
```
## 3.Payment Webhook
```http
POST /api/payments/webhook
Content-Type: application/json

{
  "order_id": 10,
  "status": "paid",
  "idempotency_key": "txn_abc123"
}
```

## 🛣️ API Routes

### 🔍 Product Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/products/{id}` | Get product summary with available stock |

---

### ⏳ Hold Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/holds` | Create a time-limited reservation (hold) |

---

### 🧾 Order Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/orders` | Convert a hold into an order |

---

### 💳 Payment Webhook

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/payments/webhook` | Process payment gateway callbacks (idempotent) |

---

## 🔐 Concurrency Control Mechanisms

- `lockForUpdate()` ensures row-level exclusivity
- Database transactions enforce atomicity
- Deadlock retries via `DbRetry` (3 attempts, 50ms delay)
- ExpireHoldJob ensures abandoned holds are released
- Payment webhook idempotency ensures consistency under retries

---

## 🧪 Testing Strategy

The test suite validates concurrency, idempotency, and consistency.

### Feature Tests

| Test File | Purpose |
|-------------|----------|
| ParallelHoldTest.php | Validates concurrent hold creation |
| HoldExpiryTest.php | Tests expiration job behavior |
| WebhookIdempotencyTest.php | Ensures duplicate webhook safety |
| WebhookBeforeOrderTest.php | Handles out-of-order webhook events |

---

## 🗂️ File Structure

```txt
app/
├── Http/Controllers/
│   ├── ProductController.php
│   ├── HoldController.php
│   ├── OrderController.php
│   └── PaymentWebhookController.php
├── Models/
│   ├── Product.php
│   ├── Hold.php
│   ├── Order.php
│   └── PaymentEvent.php
├── Jobs/
│   └── ExpireHoldJob.php
└── Services/
    ├── DbRetry.php
    └── PaymentEventProcessor.php

database/migrations/
├── *_create_products_table.php
├── *_create_holds_table.php
├── *_create_orders_table.php
└── *_create_payment_events_table.php

tests/Feature/
├── ParallelHoldTest.php
├── HoldExpiryTest.php
├── WebhookIdempotencyTest.php
└── WebhookBeforeOrderTest.php


---

## Installation & Setup

### Prerequisites

* PHP ^8.2
* Composer
* MySQL or compatible database

### Setup Steps

```bash
# 1. Clone the repository
git clone <your-repo-url>
cd flash-sale

# 2. Install backend dependencies
composer install

# 3. Copy environment file
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Configure database in .env
DB_DATABASE=your_db_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password

# 6. Run migrations
php artisan migrate

# 7. (Optional) Seed database
php artisan db:seed

# 8. Run development server
php artisan serve
php artisan queue:work

# 9. (Optional) test project
php artisan test

```

Visit: `http://127.0.0.1:8000`

---

## 📚 System Documentation (PDFs)

- 🏗️ [System Architecture](docs/System%20Architecture.pdf)
- 🔄 [Request Flow Example](docs/Request%20Flow%20Example.pdf)
- 🛡️ [Key System Guarantees](docs/Key%20System%20Guarantees.pdf)

*Maintained by Steven Hany Elia*
