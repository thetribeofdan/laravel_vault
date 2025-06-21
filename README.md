# 🛡️ Laravel Vault Package

Welcome to the **Laravel Vault Package** — your trusted sidekick for keeping secrets safe, cleanly organized, and instantly usable across your Laravel project. Whether you're working with static `.env` or `.json` files, or pulling secrets live from **HashiCorp Vault**, this package has your back 💼🔐

---

## 🚀 Features

✅ Seamless integration with Laravel 8 - 12
✅ Supports `.env`, `.json`, and remote Vault (KVv2) secrets
✅ Automatic config injection with fallback + caching 🔄
✅ Works with **multiple Vault paths or files**
✅ Simple `Vault::refresh()` method to reload secrets on the fly
✅ Easy config publishing & customization

---

## 🛠️ Installation

1. Add the package to your Laravel project:

```bash
composer require thetribephotography/laravel_vault
```

2. Publish the config file:

```bash
php artisan vendor:publish --tag=vault-config
```

You'll now see a `config/vault.php` file. This is your main control center 🧠

---

## ⚙️ Configuration

### 1. Choose your mode

Set the mode in `.env` or directly in `config/vault.php`:

```env
VAULT_MODE=file     # or 'token'
```

---

### 2. File Mode (📁 Local Files)

Use this when you want to load secrets from one or more **local files** (e.g. `.env`, `.json`):

```php
'file_paths' => [
    base_path('.vault/secrets.env'),
    base_path('.vault/extra.json'),
],
```

✅ Supported formats:

* `.env`-style: `KEY=value`
* `.json`-style: `{ "KEY": "value" }`

---

### 3. Token Mode (🔐 Live HashiCorp Vault)

Use this when you want to connect to a live Vault instance using **tokens**:

```php
'token_sources' => [
    [
        'token' => env('VAULT_TOKEN'),
        'path'  => '/v1/secret/data/app',
        'url'   => env('VAULT_URL'),
    ],
    [
        'token' => env('VAULT_ALT_TOKEN'),
        'path'  => '/v1/secret/data/billing',
        'url'   => env('VAULT_URL'),
    ],
],
```

📌 Supports multiple tokens + paths.

---

### 4. Mapping Secrets to Config 🗺️

Map secrets from your files or Vault into Laravel config using the `map` key:

```php
'config.app_key' => 'APP_KEY',
'config.mailgun.secret' => 'MAILGUN_SECRET',
```

Usage in your app:

```php
config('config.app_key');
config('config.mailgun.secret');
```

---

## 🧼 Runtime Refreshing

Need to reload your secrets on the fly (e.g. after token rotation or file update)? Just call:

```php
Vault::refresh();
```

It will:

* Clear existing cache
* Reload from source
* Reinject all mapped config values 🔁

---

## 🪵 Logging & Warnings

The package will log friendly messages when:

* A Vault file path is missing ❌
* A Vault token/path combo fails to load 🔒
* A mapped key is missing ⚠️

---

## 💡 Tips

* Make sure you **run Laravel's `php artisan cache:table` & migrate** if using database cache
* All secrets are **cached for 1 hour** by default (configurable)
* This package is best used for **secure, centralized secrets**

---

## 🙌 Credits

Built with 🖤 by [Daniel Fiyinfoluwa Egbeleke](mailto:fiyinfoluwaegbeleke@gmail.com) aka *The Bad Guy™*.
Inspired by a personal need to make secure secret integration feel effortless for my Future Projects...Hope You Like It 🔐

Massive Help by [@imambash6](https://github.com/imambash6)

---

## 📄 License

MIT — feel free to improve, and contribute!
