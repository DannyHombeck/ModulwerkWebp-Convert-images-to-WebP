# Modulwerk WebP converter (ModulwerkWebp)

Converts JPG and PNG images including thumbnails to WebP and serves them in
the storefront. Originals stay untouched; the WebP files live in their own
directory and can be removed completely at any time.

## How it works

- Every image file (original and each thumbnail) is converted separately and
  stored at `public/modulwerk-webp/<original path>.webp`.
- In the storefront the image URLs are rewritten in the final HTML output,
  no matter which template they come from (product images, shopping
  experiences, sliders, logo, theme extensions). Only files that are
  recorded as converted are rewritten.
- Mails, product feeds and the API are untouched and keep the original URLs.
- If the WebP file is not smaller than the original, it is skipped.
- When a media item is deleted, its file replaced or its thumbnails
  regenerated, the related WebP files are removed and the image is converted
  again on the next run.

## Requirements

- Shopware 6.7 or 6.8
- PHP with GD (WebP support) or Imagick with WebP delegate
- Browsers: all current browsers, Safari 14 or later

The overview shows which library is available on the server.

## Usage

Menu **Content → Modulwerk WebP converter**:

- **Convert pending images** processes all pending media in batches and shows
  the progress. Each request takes at most about 20 seconds, so even a tight
  `max_execution_time` does not cause an abort.
- **Reconvert all images** discards all WebP files and reconverts every image
  with the current settings, e.g. after changing the quality. The cache is
  cleared once at the end.
- **Clear cache** – afterwards the storefront shows the WebP images.
- **Retry errors**, **Remove orphaned files**, **Delete all WebP files**
- Table of recently processed files with size, savings and **Recreate** per
  media item

New images are converted by the scheduled task `modulwerk_webp.convert`. It
runs either at an interval in minutes (default 1440 = 24 hours) or once a day
at a fixed time. From an interval of one hour on, a run works through all
pending images, at most 15 minutes at a time. Switching it off sets the task
to inactive under Settings → System → Scheduled tasks. A running scheduled
task runner or the admin worker is required.

**Cache:** the storefront stores finished pages in the HTTP cache. The plugin
clears this page cache (or Varnish) automatically as soon as a processing run
has created, removed or changed WebP files – for the overview button once at
the end of the run, and for the scheduled task, console commands, deleted
media and regenerated thumbnails. Can be switched off under Settings →
Automation. Clear the cache yourself after changing settings and after
deactivating the plugin.

## Media folder "Modulwerk WebP-Konverter"

Under **Content → Media** the folder "Modulwerk WebP-Konverter" lists the WebP version
of every original image. The entries point directly to the files in
`modulwerk-webp/`; nothing is copied and no thumbnails are created. Alt text and title are taken over from the original in all languages and kept in sync; edit them on the original. Deleting or
renaming an entry there makes the storefront serve the original again;
"Recreate" in the overview brings it back. "Delete unused media" keeps the
entries. Do not assign these entries to products or shopping experiences –
they are replaced when recreated. The storefront serves WebP automatically
anyway. Can be switched off under Settings → Media management.

## Commands

```bash
bin/console modulwerk:webp:status
bin/console modulwerk:webp:convert [--limit=200] [--retry-errors] [--force] [-m <mediaId>]
bin/console modulwerk:webp:clear [--orphaned]
bin/console cache:clear
```

For large stocks the console is the fastest way, as no time limit applies
there.

## Notes

- GD decodes images completely into memory. Images for which `memory_limit`
  is not sufficient are marked as errors instead of aborting the script.
- Phone and camera photos are rotated according to their EXIF orientation.
- Colour profiles: browsers display WebP without a profile as sRGB. Images in
  another colour space (Adobe RGB, Display P3 from smartphones, CMYK) are
  therefore converted to sRGB by Imagick before conversion so the colours stay
  right. GD cannot do this – such images are skipped with GD and the
  storefront shows the original.
- Private media (e.g. documents) are never converted.
- With external storage (S3, CDN) the WebP files end up in the same public
  filesystem.

## Permissions

The overview is shown to users who may view media. Converting requires the
permission to edit media, deleting the WebP files the permission to delete
media. The settings require the permission to manage plugins. Administrators
may do everything.

## Texts (snippets)

All admin texts live in `src/Resources/app/administration/src/snippet/de-DE.json`
and `en-GB.json`. Shopware loads them server-side, no build is required;
`sync-admin.sh` additionally embeds them into the admin asset. Conversion
notes are stored language-neutral as `code|detail` and translated via
`modulwerk-webp.messages.<code>`. The settings (`config.xml`) are bilingual as
well. Console command output is German.

## Uninstall

Without "keep data" the plugin removes the `modulwerk-webp` directory in the
public filesystem, the table `modulwerk_webp_file`, all settings
`ModulwerkWebp.config.*` and the scheduled task. Clear the cache afterwards.
