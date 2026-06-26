# Docker deployment

This project runs as a small PHP + Apache container. The image installs the PHP IMAP extension required by the mailbox reader.

## Build and run

```bash
docker compose up -d --build
```

Open:

```text
http://SERVER_IP:8080/
```

Pages:

- `/index.html` lookup page
- `/privacy.html` add iCloud private email page
- `/admin.html` account admin page

## Configure mailbox

Edit `config/mail.php` before starting the container, or edit it on the host and restart the service.

Set `mail_provider` to choose the backend:

- `imap`: read from `backend_mailbox`
- `outlook_graph`: read from `outlook_graph`

iCloud IMAP example:

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

Outlook Graph example:

```php
"mail_provider" => "outlook_graph",
"outlook_graph" => [
    "tenant_id" => "common",
    "client_id" => "your-azure-app-client-id",
    "client_secret" => "",
    "refresh_token" => "your-authorized-outlook-refresh-token",
    "access_token" => "",
    "scopes" => "https://graph.microsoft.com/Mail.Read offline_access",
    "mail_folder" => "inbox",
    "graph_base_url" => "https://graph.microsoft.com/v1.0",
],
```

The Microsoft app/token must be authorized by the mailbox owner and include `Mail.Read`. If `lookup.delete_after_read` is enabled, the token also needs permission to delete messages, such as `Mail.ReadWrite`; otherwise the code can still be read, but the delete attempt will return an error. Keep tokens only in `config/mail.php`; do not send them from the browser.

`config/access_accounts.json` is mounted from the host, so accounts added in the admin pages survive container rebuilds.

If the container cannot write accounts, check the host directory permissions for `config/`.

## Common commands

```bash
docker compose logs -f
docker compose restart
docker compose down
```

Check PHP modules:

```bash
docker compose exec youjian php -m | grep imap
```
