# Ibadah Jewellers

The storefront, Cash on Delivery checkout, inventory and `/mehedi/` admin are all in this repository. It runs on PHP and MySQL. This installation starts with an empty catalogue.

## First install on cPanel

This guide assumes your domain serves `/home/CPANEL_USER/public_html/`. Check **Domains** in cPanel to confirm the document root. Use Apache or LiteSpeed with `.htaccess` and rewrite support.

### 1. Clone into the document root

The GitHub repository is private. Configure a read-only GitHub deploy key on the cPanel account before cloning. You can clone with **Git Version Control** or with cPanel Terminal:

```sh
cd ~
git clone --branch main --single-branch git@github.com:MohammadAtikurRahman/ibadahjewellers.git public_html
```

Git can clone into an existing empty `public_html`. If it already contains files, back them up and check for hosting files or another site before changing that directory. The repository's root `.htaccess` serves the storefront directly at `/` and routes `/api/` and `/mehedi/`. Leave the repository layout intact. If you upload a GitHub ZIP instead, extract the contents of the ZIP's top-level project folder into `public_html/`, including the root `.htaccess`.

Select PHP 8.2 or newer in **MultiPHP Manager** and enable PDO MySQL and PHP sessions. Enable the domain's SSL certificate and **Force HTTPS Redirect**.

### 2. Create the database and import the tables

Open **Database Wizard**, create a MySQL or MariaDB database and a database user, then assign the user to the database. Note the complete names displayed by cPanel, including the account prefix where present. In **phpMyAdmin**, select your database and import `database/schema.sql` one time. This file creates the tables and leaves them empty. Use MySQL 8.0+ or MariaDB 10.6+ with InnoDB and utf8mb4.

### 3. Create the private configuration

The checkout and admin read their configuration from `ibadahjewellers-private/config.php`, beside the cloned directory. From cPanel Terminal:

```sh
mkdir -p -m 700 ~/ibadahjewellers-private
cp ~/public_html/app/config.example.php ~/ibadahjewellers-private/config.php
chmod 600 ~/ibadahjewellers-private/config.php
```

You can also create the folder and copy the example in cPanel File Manager. In the copied file, set the database host (usually `localhost`), database name, user and password from step 2. Generate a random setup token and put it in `setup_token`. For example:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Keep `checkout_enabled` set to `false` while you add your products.

```text
/home/CPANEL_USER/
├── ibadahjewellers-private/
│   ├── config.php
│   └── media/          (created on first photo upload)
└── public_html/        (the entire Git clone)
    ├── .htaccess
    ├── app/
    ├── database/
    └── public/
```

For a domain that serves a different directory directly under your cPanel home, use that directory instead of `public_html`. The private directory must sit beside the clone and outside every domain's document root.

### 4. Create your admin, add products and open checkout

1. Open `https://YOUR-DOMAIN/setup.php`. Enter your setup token and create an admin username and password. Setup closes automatically after the account is created. Clear `setup_token` in the private configuration afterward.
2. Sign in at `https://YOUR-DOMAIN/mehedi/`. In **Inventory**, add each real product with its photo, price and available quantity per size. Use **One size** for pieces without size options.
3. Set `checkout_enabled` to `true` in the private configuration. Place a test COD order and confirm it appears under **Orders**. Cancelling it restores the reserved stock.
4. If LiteSpeed page caching is enabled, exclude `/api/`, `/mehedi/` and `/setup.php`. Check that `/api/catalog.php` returns JSON and that `/database/schema.sql` and `/.git/HEAD` return 404 or 403.

[cPanel private repository access](https://docs.cpanel.net/knowledge-base/web-services/guide-to-git-set-up-access-to-private-repositories/) · [Database Wizard](https://docs.cpanel.net/cpanel/databases/database-wizard/) · [phpMyAdmin](https://docs.cpanel.net/cpanel/databases/phpmyadmin/)

## Orders and inventory

- Orders accept cash on delivery. Delivery is BDT 80 within Dhaka or BDT 120 outside Dhaka, with free delivery at BDT 2,500 in merchandise. Optional gift wrapping is BDT 100.
- The server confirms totals, saves orders and reserves stock per product size. Customers receive a reference after the order is saved.
- In **Orders**, confirm the order, then mark it **Shipped** or **Completed** as it progresses. Cancelling a pending or confirmed order returns the reserved stock once.
- In **Inventory**, change the available stock, product details, photo or visibility. Store product photos as JPG, PNG or WebP. Your hosting upload limit also applies.
- Keep backups of the database and the private `media/` folder. Pull code updates with `git pull --ff-only` inside the cloned directory. The database and uploaded photos are retained separately.

Store contact: **info@ibadahejewellery.com**, **Mirpur 1**.

## Local development

Clone into a folder and create `ibadahjewellers-private/` beside it, following the database and configuration steps above. Run `php -S 127.0.0.1:8000 -t public` from the cloned directory. Open `http://127.0.0.1:8000/`. Production setup, admin and checkout require HTTPS.
