# RegenerateThumbnails

Module-owned thumbnail regeneration tools for PrestaShop.

## Admin batch tool (AJAX)

In Back Office, open this module configuration page to use the built-in batch UI.

- Select scope and image type filters
- Optional erase of previous generated thumbs
- Choose batch size per AJAX request
- Start and monitor progress bar/log output

This mode processes thumbnails in small requests instead of one long request, which helps prevent server timeouts.

## Symfony command (auto-registered)

`prestashop:image:thumbnails:regenerate`

Once the module is installed and active, PrestaShop loads `modules/regeneratethumbnails/config/services.yml` and registers the command automatically.

```bash
php <your-console-entrypoint> prestashop:image:thumbnails:regenerate --help
```

## Standalone script (optional fallback)

`modules/regeneratethumbnails/cli/regenerate_thumbnails.php`

```bash
php modules/regeneratethumbnails/cli/regenerate_thumbnails.php --help
```

## Options

- `--rease_previous` delete already generated thumbs only for the selected scope/type
- `--erase_previous` alias for `--rease_previous`
- `--image_type=<id|name>` image type id or name
- `--image_scope=<scope>` `all|product(s)|category(ies)|manufacturer(s)|brand(s)|supplier(s)|store(s)`
- `--help` show help

When both `--image_type` and `--image_scope` are omitted, the command/script asks for confirmation before regenerating all images.
