# mailCode

OpenAI verification code lookup tool for forwarded mailbox workflows, including iCloud Hide My Email aliases.

## Features

- PHP + Apache, no frontend build step.
- Reads verification emails through IMAP.
- Supports multiple private email aliases through one backend mailbox.
- Admin pages for adding allowed emails and query passwords.
- Query passwords are stored with `password_hash()` when saved from the admin UI.
- Docker deployment included.

## Pages

- `index.html` - lookup verification codes.
- `privacy.html` - add an iCloud private email alias.
- `admin.html` - manage allowed emails.

## Docker

```bash
docker compose up -d --build
```

Open:

```text
http://SERVER_IP:8090/
```

See [DOCKER.md](DOCKER.md) for deployment details.

## Configuration

Copy the example config before non-Docker deployment:

```bash
cp config/mail.example.php config/mail.php
cp config/access_accounts.example.json config/access_accounts.json
```

For iCloud Mail, use an app-specific password and IMAP settings:

```php
"backend_mailbox" => [
    "imap_host" => "imap.mail.me.com",
    "imap_port" => 993,
    "imap_flags" => "/imap/ssl",
    "mail_folder" => "INBOX",
    "email" => "your-real-icloud-account@icloud.com",
    "auth_code" => "your-app-specific-password",
],
```

Do not commit `config/mail.php` or `config/access_accounts.json`; they are intentionally ignored.
