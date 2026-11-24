# Assis Prime Manager

Custom management plugin for Assis Prime Spa e Beleza, built on WordPress.

## Features

- Appointment scheduling with multi-professional support
- Client management with deduplication (CPF, phone, email)
- Medical records (anamnesis, evolution notes, consent forms) with digital signature
- Inventory control with automatic consumption per service
- Financial management (accounts payable/receivable, payment integration)
- CRM with lead pipeline and WhatsApp auto-capture
- WhatsApp notifications via Z-API (booking confirmation, reminders, OTP)
- Online booking flow with InfinitePay payment (Pix + credit card)
- Notifications center
- System status dashboard

## Requirements

- WordPress 6.0+
- PHP 7.4+

## Architecture

The codebase is organized by domain. The `APM_Repository` class composes all repository traits:

```
includes/
├── class-apm-repository.php       — public API (composes all traits)
├── class-apm-installer.php        — DB schema management
├── class-apm-rest.php             — REST API endpoints
├── class-apm-router.php           — public page routing
├── class-apm-admin.php            — WP admin integration
└── repository/
    ├── trait-apm-repository-agenda.php
    ├── trait-apm-repository-anamnesis.php
    ├── trait-apm-repository-access.php
    ├── trait-apm-repository-catalog.php
    ├── trait-apm-repository-crm.php
    ├── trait-apm-repository-finance.php
    ├── trait-apm-repository-inventory.php
    ├── trait-apm-repository-records.php
    ├── trait-apm-repository-status.php
    └── trait-apm-repository-whatsapp.php
```

Assets are compiled via CLI build scripts in `tools/`. Run them directly from the command line — they are never executed within WordPress.

## License

GPL v2 or later
