# JPW Khata

Mobile-friendly PHP + MySQL/MariaDB app for tracking one spare-parts supplier account.

## Local XAMPP
1. Keep the project folder at `C:\xampp\htdocs\jp-workshop-khata`.
2. Start Apache and MySQL in XAMPP.
3. Open `http://localhost/jp-workshop-khata/`.
4. Do not re-import `database/schema.sql` when updating an existing installation; it is only for initial database setup.

## New records-page features
- Dashboard header includes See All Records beside Log out.
- Payment methods: Bank Transfer, Wallet, Mobile Banking, Cheque, Cash, Other.
- Records page supports selecting individual records or all records for a date, then deleting selected records with confirmation.
- Delete all records is available at the bottom and requires confirmation.
- PDF export supports all records, last month, last three months, or custom From/To dates. Purchases are Debit; supplier payments are Credit.

## Important data safety
Deleting records is permanent. Export a PDF report before deleting if you may need a historical record. This app's database remains local until you deploy it to a server. Keep independent database backups regularly.
