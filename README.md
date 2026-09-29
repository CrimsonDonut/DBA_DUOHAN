# DBA_DUOHAN

This project contains a simple PHP + PostgreSQL CRUD demo for working with the library database tables.

## Structure

- [db/borrowers.sql](db/borrowers.sql) — example borrower table definition
- [config/db.php](config/db.php) — database connection setup
- [public/crud_demo.php](public/crud_demo.php) — CRUD interface for borrowers, equipment, and transactions

## Setup

1. Create the PostgreSQL database and user you want to use.
2. Ensure the database contains the `borrowers`, `equipment`, and `transactions` tables.
3. Update the connection settings in [config/db.php](config/db.php) if needed.
4. Start a local PHP server from the project root:

```bash
php -S localhost:8000 -t public
```

5. Open the browser at:

```text
http://localhost:8000/crud_demo.php
```

## Notes

- The script uses prepared statements to protect against SQL injection.
- The example includes create, read, update, and delete operations across the `borrowers`, `equipment`, and `transactions` tables.
