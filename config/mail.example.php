<?php
declare(strict_types=1);

return [
    "admin_panel" => [
        "password" => "admin123",
    ],

    "access_accounts" => [
        "user@example.com" => "query-password",
    ],

    "backend_mailbox" => [
        "imap_host" => "imap.qq.com",
        "imap_port" => 993,
        "imap_flags" => "/imap/ssl",
        "mail_folder" => "INBOX",
        "email" => "your-qq-mail@qq.com",
        "auth_code" => "your-qq-imap-auth-code",
    ],

    // "imap" keeps using backend_mailbox. "outlook_graph" reads an authorized Outlook mailbox with Microsoft Graph.
    "mail_provider" => "imap",

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

    "lookup" => [
        "default_limit" => 10,
        "max_limit" => 20,
        "scan_limit" => 200,
        "max_age_seconds" => 900,
        "unread_only" => true,
        "delete_after_read" => true,
    ],
];
