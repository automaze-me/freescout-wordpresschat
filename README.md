# Website Chat (WordPress)

Live chat from WordPress websites as a channel for [FreeScout](https://freescout.net).

This is the FreeScout half of the chat. The visitor-facing half is the WordPress plugin
**Live Chat for FreeScout**, which shows the chat widget on your website and talks to this
module. A visitor's chat becomes a FreeScout conversation; agents reply from FreeScout as usual.
A reply the visitor does not see in the chat within a short delay is sent to them by email, with
a link that reopens the chat.

## Requirements

- FreeScout 1.8.248 or newer.
- PHP 7.1.2 or newer. The module derives its token keys with `hash_hkdf()`, which PHP has had
  since 7.1.2.
- FreeScout's cron job, running `php artisan schedule:run` every minute. The module schedules two
  commands through it: `wordpresschat:send-due-emails` (every minute) and `wordpresschat:cleanup`
  (hourly). Without cron, fallback emails are never sent.
- A queue worker that consumes the `emails` queue. Fallback emails are sent by a queued job on
  that queue.
- The PHP `pcntl` extension is recommended. Queue jobs have a timeout, and PHP can only enforce it
  with `pcntl`. System → Status shows whether it is available.
- MySQL/MariaDB or PostgreSQL.

## Installation

1. Download `WordPressChat.zip` from the
   [latest release](https://github.com/automaze-me/freescout-wordpresschat/releases/latest) and
   extract it into your FreeScout `Modules/` directory. The zip already contains the
   `WordPressChat/` folder.
2. Activate the module in **Manage → Modules**. FreeScout links the module's assets into
   `public/modules/wordpresschat` when it installs or activates the module. If that folder does
   not exist, the chat's stylesheet and scripts are not served.
3. Run the module's migrations. The command takes the module's name from `module.json`:

   ```bash
   php artisan module:migrate "Website Chat (WordPress)"
   ```

   If your `Modules` folder is a symlink to a location outside the FreeScout directory (as in
   some Docker setups), FreeScout skips module migrations without saying so. Use **System →
   Tools → Migrate DB** instead.
4. Restart the queue workers so they load the module: `php artisan queue:restart`.

Until the migrations have run, the chat API answers `503 maintenance` and administrators see a
notice to open System → Tools and click Migrate DB.

## Updating

Replace the `WordPressChat/` folder with the new release, then repeat steps 3 and 4 of the
installation: run the migrations and restart the queue workers. The module also broadcasts a
queue restart by itself the first time it loads after its version changed, but restarting the
workers yourself does no harm.

After you update the WordPress plugin, purge your website's page cache. A cached page still holds
the old widget configuration, so new texts and settings only appear once the page is
regenerated.

## Connecting a website

1. In FreeScout, open **Manage → Website Chat** (administrators only) and add a website:
   - **Name** – shown in the list.
   - **URL** – the website's address, for example `https://www.example.com`. Its origin
     (scheme, host and port) is the only origin allowed to use the chat API, and the "continue
     this chat" links in emails point to it.
   - **Mailbox** – where the website's conversations land.
   - **Email fallback delay** – seconds before an unseen reply goes out by email (30 to 3600;
     default 120).
   - **Max attachment size** – in MB (1 to 50; default 10). PHP's limits can lower it, see
     [Upload limits](#upload-limits).
2. Open the website entry. It shows the **Site ID** and the **secret**.
3. In WordPress, open **Settings → Live Chat for FreeScout**, enter the FreeScout URL, the Site ID
   and the secret, and save.
4. Click **Test connection**. It tells you whether FreeScout knows the Site ID, whether the secret
   matches, and whether the website's address matches the origin saved in FreeScout.

The secret is what lets the plugin vouch for logged-in WordPress users and order numbers. If you
leave it out, visitors can still chat, but they are not recognised. Regenerating the secret in
FreeScout stops the plugin until you enter the new one.

**System → Status** has a "Website Chat" block that checks the database, `pcntl`, WebP support
and the upload limits.

## How replies reach visitors

The visitor sees an agent's reply in the chat widget, which polls for new messages and reports
back when a reply has been seen.

A reply the visitor has not seen within the website's fallback delay goes out as one email to the
conversation's customer. The email carries a "continue this chat" link to the website. The widget redeems it only on an
explicit click by the visitor.

No link is included, and the email still goes out, when it would also reach other people: when the
reply has a Cc or Bcc, or when the mailbox has an automatic Bcc. The link is only
ever sent to the conversation's own address, so it does not go to anyone else.

Agents can also choose **Email** in the reply picker above the Send button to send a particular
reply by email at once.

## Upload limits

Visitors can send images (JPEG, PNG, GIF, WebP) and PDF files. The limit is the website's
**Max attachment size**, capped by PHP's `upload_max_filesize` and `post_max_size`. System →
Status shows the limit that applies and warns when PHP is lower than the website's setting.

The web server has a limit of its own, and it has to be at least as large as `post_max_size`:
`client_max_body_size` in nginx, `LimitRequestBody` in Apache. If it is lower, the web server
rejects the upload before PHP sees it, and the visitor gets a generic error instead of a message
that the file is too large.

## Abuse limits

The chat API limits how often one visitor and one IP address can call it. The counters are kept
in the database. A request over a limit gets `429` with a `Retry-After` header, and the widget
shows "Too many messages in a short time". Every attempt counts, including refused ones.

- **Starting a chat** (a message without a chat token): 10 per hour per IP address.
- **Messages**: 20 per minute per chat token, and 60 per minute per IP address.
- **Uploads**: 50 MB per hour per chat token, and 200 MB per hour per IP address.
- **Identifying by email or order number**: 5 per 15 minutes per chat token, and 10 per 15
  minutes per IP address.
- **WordPress login handover**: 60 per 15 minutes per IP address.
- **Continue links**: 10 per 15 minutes per IP address.
- **Marking replies as seen**: 60 per minute per chat token.
- **Attachment downloads**: 120 per minute per chat token.
- **Log out**: 30 per minute per IP address.
- **Test connection** from the plugin: 10 per minute per IP address.

Polling for new messages is not limited, and neither are browser preflight requests. Every open chat
polls, so leave room for that in any rate limiting you do in the web server or a CDN.

A message that fills in the hidden `hp` field is refused as a bot and not stored.

Visitors behind one shared IP address (an office, a mobile carrier) share the per-IP limits.

## Origin check limits

The API accepts browser requests only from the website's origin as saved in FreeScout. The origin
is compared exactly, after lowercasing, dropping default ports and converting international
domain names to punycode (this needs PHP's `intl` extension).

- Each website entry has **one** origin. `example.com` and `www.example.com` are different
  origins. A site reachable under several domains needs one website entry for each, with its own
  Site ID and secret, and the plugin on each domain configured with its own.
- A reverse proxy or CDN in front of FreeScout that terminates TLS must pass the browser's
  `Origin` header on unchanged. If it removes or rewrites the header, every request fails with
  `403 origin`.
- Requests without an `Origin` header (server-to-server calls) are not subject to the check.

## Privacy

The module stores what a chat needs and little else:

- the chat messages and their attachments, as FreeScout conversations and threads;
- the visitor's email address, when they give one or when WordPress vouches for the logged-in
  user, or the link to the customer found through an order number;
- the page path the visitor is on, so agents can see "On the site now" with the path. The query
  string and the fragment are never kept;
- chat session tokens only as SHA-256 hashes;
- rate-limit counters, which are keyed by SHA-256 hashes of the IP address (or the token) and
  are purged by the hourly cleanup once they have expired. IPv6 addresses are folded to their first 64 bits first.
  The module stores no raw IP addresses.

## Channel code

The module registers communication channel **106**. This code is provisional until the FreeScout
team confirms it. Codes below 100 belong to official modules, and community modules use 100 and
above.

## Testing

The suite runs inside a FreeScout development installation, against a database for the `testing`
connection (see `config/database.php`). The module has to be active in that database, and the
suite refuses to run against any other connection. From the FreeScout root:

```bash
vendor/bin/phpunit -c Modules/WordPressChat/phpunit.xml
```

In the FreeScout development setup that runs in Docker:

```bash
docker compose exec -T --user www-data app ./vendor/bin/phpunit -c Modules/WordPressChat/phpunit.xml
```

## License

MIT, see [LICENSE](LICENSE).
