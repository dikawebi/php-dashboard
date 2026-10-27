# Mining Accounting Dashboard — PHP / Laravel / MySQL

Versi migrasi server-rendered dari dashboard lama. Tampilan mempertahankan tema, KPI, tabel matriks, inventory moving average, laporan keuangan, dan pusat impor data.

## Stack

- PHP 8.3+
- Laravel 13
- Blade server-side rendering
- MySQL 8+
- PhpSpreadsheet untuk impor Excel/CSV
- CSS dashboard lama dipakai kembali di `public/css/dashboard.css`

## Setup MySQL

Buat database terlebih dahulu:

```sql
CREATE DATABASE mining_accounting_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Atur kredensial di `.env`, lalu jalankan:

```bash
composer install
php artisan migrate --seed
php artisan serve
```

Buka `http://127.0.0.1:8000`.

## Data dan fitur

Seeder `LegacyDataSeeder` memindahkan data JSON lama ke tabel MySQL:

- `cost_matrix_definitions`
- `gl_entries`
- `production_tickets`
- `dashboard_settings`

Route utama:

- `GET /` — dashboard Blade
- `GET /export-matrix` — export CSV
- `POST /import-gl` — impor Excel/CSV/TSV
- `POST /inventory` — simpan saldo awal inventory

Frontend tidak memuat Vite atau file JavaScript aplikasi; navigasi, filter, form, dan report diproses melalui request server Laravel.

## Login dan Role

Seeder membuat akun administrator dari `.env`:

```text
ADMIN_EMAIL=admin@example.com
ADMIN_PASSWORD=change-me-now
```

Segera ganti password tersebut pada environment deployment. Role tersedia:

- `administrator`: semua modul, branding, formula setup, dan user access.
- `accountant`: cost matrix, import GL, inventory, cost/MT, dan laporan keuangan.
- `operations`: overview, produksi, inventory, dan operasi timbangan.
- `viewer`: akses baca dashboard.

Setup administrator tersedia di `/admin/settings`. Logo awal berasal dari project `asset-tagging` dan berada di `public/images/logo-bp.svg`.

Parameter formula utama meliputi konversi Kg/MT, rasio volume Cost/MT, direct mining cost, transfer/yield stockpile, shipment ratio, harga jual, serta asumsi neraca keuangan.

## Verifikasi

```bash
vendor/bin/pint --test
php artisan test
```
