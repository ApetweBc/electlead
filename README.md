# ElectLead (PHP + MySQL)

ElectLead is a server-side election system using PHP and MySQL relational tables.

## What This Enforces

- Each voter uses a unique `voter_id` from the voter registry.
- A voter can vote **once per category** (enforced by DB primary key on `voter_participation(voter_id, category_id)`).
- Secret ballot behavior:
  - Voter participation is tracked in `voter_participation`.
  - Ballots are stored in `ballots` without voter ID linkage.
- Candidate approval requires committee checks and minimum 2 nominators.
- Results are visible only in the admin portal.

## Project Files

- `index.php`: Client portal (nominate + vote)
- `admin/login.php`: Admin login
- `admin/index.php`: Admin dashboard (verification + results)
- `admin/import_voters.php`: Mass voter CSV import
- `database.sql`: Full schema and seed data
- `config.php`: DB connection config

## Setup

1. Create MySQL database/tables:
   - Run `database.sql` in MySQL.
2. Configure DB credentials in `config.php` (or via env vars `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`).
3. Run PHP local server from project root:

```bash
php -S localhost:8000
```

4. Open:
   - Client portal: `http://localhost:8000/index.php`
   - Admin portal: `http://localhost:8000/admin/login.php`

## Git Ignore And Team Usage

This project now includes `.gitignore` rules to prevent sensitive/local files from being committed.

Ignored by default:

- `.env`
- `.env.*`
- `*.db`, `*.sqlite`, `*.sqlite3`
- `*.sql.bak`, `*.sql.gz`, `*.dump`
- `*.log`

Allowed template file:

- `.env.example` (safe to commit for shared config template)

Team guidance:

1. Store real credentials only in local `.env` (or local machine environment variables).
2. Do not commit database dumps, backup files, or logs.
3. Keep `database.sql` in git as the canonical schema/bootstrap script.
4. Before pushing, check `git status` to confirm no secret/local files are staged.

## Default Admin Credentials

- Username: `Root`
- Password: `LumbaParkoso`

## Mass Voter Import

Use `admin/import_voters.php` and upload a CSV with:

- Required columns: `voter_id`, `full_name`
- Optional column: `active` (`1/0`, `true/false`, `yes/no`, `active/inactive`)

Example:

```csv
voter_id,full_name,active
V001,Jane Doe,1
V002,John Smith,1
V003,Inactive Member,0
```

Re-import is supported. Existing voter IDs are updated.
