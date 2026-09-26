# Forward to Muse

A [Roundcube](https://roundcube.net) plugin that adds a **Muse** button to the
mail toolbar. One click immediately forwards the selected message(s) to your
Muse agent — no compose window.

Each forward is sent as its own email from your identity, with the original
message attached as `.eml` (lossless, any content type), a copy saved to Sent,
and the original flagged as forwarded.

## Requirements

- Roundcube 1.6+ (developed and tested on 1.7)
- PHP 7.4+
- Skins: Elastic, Larry, Classic

## Install

With Composer (in your Roundcube directory):

```bash
composer require rackamx/forward_to_muse
```

Or manually: copy this directory to `plugins/forward_to_muse` and enable it:

```php
$config['plugins'] = array_merge($config['plugins'], ['forward_to_muse']);
```

## Configuration

Each user sets their own address under Settings -> Muse (empty = use the
global default). The global default lives in the main Roundcube config:

Replace the placeholder with your Muse agent's address:

```php
$config['forward_to_muse_recipient'] = 'muse@example.com';
```

Sending is refused until a real address is configured (per-user setting or
global default above).

To lock one address for everybody (hides the per-user field):

```php
$config['dont_override'] = ['forward_to_muse_recipient'];
```

See `config.inc.php.dist` for all options.

## Tests

Self-contained smoke test, no framework needed:

```bash
php tests/smoke.php
```

## License

Public domain ([The Unlicense](LICENSE)).
