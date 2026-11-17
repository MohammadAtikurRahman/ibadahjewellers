# Ibadah Jewellers

The single-page Ibadah Jewellers storefront with a Cash on Delivery checkout, live inventory and an admin panel at `/mehedi/`. It runs on PHP and MySQL and uses the original design and photographs. There is no build step, Composer install or Node.js server.

## cPanel requirements

- PHP 8.2 or newer with `pdo_mysql`, `mbstring`, `ctype` and sessions enabled. Use a supported version offered by the host, preferably PHP 8.4 or newer.
- MySQL 8.0+ or MariaDB 10.6+ with InnoDB and utf8mb4.
- An HTTPS certificate and the domain pointed to this hosting account. The examples below use `ibadahjewellers.com`; substitute your registered domain if its extension differs.

## Install on cPanel

1. In **Domains**, add the domain if needed. Set the domain's DNS using the instructions from the host. Confirm the certificate in **SSL/TLS Status** and turn on **Force HTTPS Redirect** in **Domains** when available.
2. Check the domain's document root in **Domains**. In **MultiPHP Manager**, choose the PHP version for that domain. If your host exposes **Select PHP Version**, check that the required PHP extensions are enabled there.
3. Upload the project using **File Manager** or SFTP. Preserve the folder layout below. Enable **Show Hidden Files** in File Manager so you can see and copy `.htaccess`.
4. Open **Database Wizard** (called **MySQL Database Wizard** on older cPanel versions). Create a database and a database user with a generated password. Assign the user to the database. For the runtime user, grant SELECT, INSERT, UPDATE and DELETE for this database. Use the full, prefixed database and user names shown in cPanel. In **phpMyAdmin**, select your new database and import `database/schema.sql` before `database/catalogue.sql`. The second import adds the original eight sample products and all variants with zero stock. Both imports can be repeated without resetting existing orders or stock.
5. Copy `app/config.example.php` to `app/config.php` in the same private folder. Fill in the database host (usually `localhost`), name, username and password. Use File Manager's password generator or a password manager to generate a unique setup token of at least 32 characters. With cPanel **Terminal** you can also use `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Set that value as `setup_token`. Keep `checkout_enabled` set to `false` during setup.
6. Visit `https://ibadahjewellers.com/setup.php` and enter the setup token, your chosen admin username and a unique password. Successful setup takes you to `/mehedi/` and disables public setup automatically. Clear the setup token in `config.php` after creating the admin. Use the new username and password to sign in.
7. In **Inventory**, verify product names, prices and variants. Enter actual quantities and hide any sample pieces you do not sell. Review the photos, descriptions, sizing, delivery charges, gift-wrap fee, discount and store policies before opening the shop. The checked-in sample descriptions/photos are illustrative. Catalogue fields beyond the dashboard controls can be edited in the `products` and `variants` tables, and product images are stored in `public/assets/images/`.
8. Set `checkout_enabled` to `true`. Place a test order, confirm it appears under **Orders**, and cancel it to check stock restoration. On hosting with a page-cache feature, exclude `/api/`, `/mehedi/` and `/setup.php`. The app also sends no-store response headers on those routes.

Official cPanel references: [Database Wizard](https://docs.cpanel.net/cpanel/databases/database-wizard/), [phpMyAdmin](https://docs.cpanel.net/cpanel/databases/phpmyadmin/), [MultiPHP Manager](https://docs.cpanel.net/cpanel/software/multiphp-manager-for-cpanel/), [Domain document roots](https://docs.cpanel.net/knowledge-base/cpanel-product/how-domains-serve-content/).

### File layout

For a primary domain that uses `public_html`, put the **contents** of `public/` inside `public_html/`. Put `app/` beside it, directly under your cPanel home directory. The main website URL is `https://ibadahjewellers.com/`; the admin URL is `https://ibadahjewellers.com/mehedi/`. Visiting `/mehedi` redirects to the directory under standard Apache/LiteSpeed hosting.

```text
/home/CPANEL_USER/
├── app/
│   ├── bootstrap.php
│   ├── config.php
│   ├── http.php
│   └── orders.php
└── public_html/
    ├── .htaccess
    ├── index.html
    ├── app.js
    ├── styles.css
    ├── setup.php
    ├── setup.css
    ├── api/
    ├── assets/
    └── mehedi/
```

For a domain with a configurable document root, upload the project and set its document root to the project's `public/` folder. Prefer a project directory outside other domains' document roots. Keep `app/config.php`, the database SQL, any backup ZIPs and Git metadata outside all document roots. `app/config.php` is ignored by Git. The `database/` directory is only needed to import SQL.

## Daily use

- **Orders** shows contact and delivery details, purchased variants, the recorded total, and status. Confirm each new order before dispatch. Delivery goes from **Confirmed** to **Shipped** to **Completed**. **Pending** and **Confirmed** orders can be cancelled and will return their reserved quantities once.
- **Inventory** updates the count separately for each ring size or one-size product. It also edits product name and price, and toggles storefront visibility. A stale stock edit tells you to use the current count if a checkout or another edit changed it first.
- **Password** changes the current admin password. Keep the password and database backups private. Export the database through phpMyAdmin before changes to the catalogue or schema.
- The browser keeps the bag and wishlist. Successful checkout records customer details in MySQL and displays an order reference. The customer pays in cash when the order arrives.

The shop calculates prices and checks stock on the server. The inherited launch rules are 10% off merchandise with `IBADAH10`, optional gift wrapping for BDT 100, delivery for BDT 80 inside Dhaka or BDT 120 outside, and free delivery when discounted merchandise reaches BDT 2,500. Update corresponding storefront copy and prices if you change the rules.

## Local development

Import the SQL into a local database, create `app/config.php`, then run `php -S 127.0.0.1:8000 -t public` from the project directory. Open `http://127.0.0.1:8000/` and follow the setup procedure. Production setup, admin access, and checkout require HTTPS.

The storefront uses Bootstrap 5.3.3 and Cormorant Garamond and Manrope fonts. Local photograph attributions are in `public/assets/photo-sources.json`. The business contact from the original shop is **info@ibadahejewellery.com**, **Mirpur 1**.

## Push this copy to GitHub

If you received a ZIP, extract it first. Create an empty private GitHub repository called `ibadahjewellers` in your account. Leave the initial README, .gitignore and license unchecked for the terminal workflow below. In the extracted project directory, run:

```sh
git init -b main
git add .
git commit -m "Add Ibadah Jewellers storefront and admin"
git remote add origin https://github.com/YOUR_GITHUB_USERNAME/ibadahjewellers.git
git push -u origin main
```

Use your normal GitHub authentication flow. Keep `app/config.php` and database exports out of Git.
