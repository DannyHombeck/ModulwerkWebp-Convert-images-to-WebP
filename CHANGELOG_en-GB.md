# 2.0.13
- Overview and settings: plugin icon in front of the name in the page header

# 2.0.12
- Entry under Settings → Extensions now leads directly to the overview instead of the settings

# 2.0.11
- Lazy loading setting marked with a warning triangle; the note not to enable it together with another lazy loading plugin or theme option now opens the help text

# 2.0.10
- Fixed: since 2.0.7 the left admin main menu could disappear completely. Shopware's menu list is no longer filtered or observed; "Show under Content in the main menu" now only hides the own entry via CSS

# 2.0.9
- Overview: columns status, original, WebP, saving and the "Recreate" button centred under their headings

# 2.0.8
- New setting "Name of the media folder" (default "Modulwerk WebP-Konverter"): renames the folder under Content → Media right after saving; the overview shows the current folder name

# 2.0.7
- New setting "Show under Content in the main menu": show or hide the admin menu entry, takes effect right after saving; settings page with "Overview" button

# 2.0.6
- Copyright holder in licence and composer.json: Danny Hombeck

# 2.0.5
- Licence changed to MIT (composer.json, LICENSE.md with German translation, admin info)

# 2.0.4
- Support mail changed to post@danny-hombeck.de

# 2.0.3
- Manufacturer and support address changed to danny-hombeck.de (website https://danny-hombeck.de/, support mail support@danny-hombeck.de)

# 2.0.2
- Colour profiles: images in Adobe RGB, Display P3 or CMYK are converted to sRGB by Imagick before conversion instead of being served with shifted colours, skipped with GD; the console no longer hangs endlessly when a media folder entry cannot be created; the scheduled task uses the configured interval right after installation (default 24 hours instead of 5 minutes) and reacts to switching between interval and fixed time; admin endpoints and menu check user permissions (view/edit/delete media, plugin management); no more imagedestroy() (deprecated as of PHP 8.5); English README completed, help texts cleaned up

# 2.0.1
- README: reference to a foreign skeleton plugin removed

# 2.0.0
- Shopware 6.8 support: service and route definitions in PHP instead of XML (Symfony 8), admin uses $t throughout instead of the removed $tc; the daily run checks the time every five minutes instead of setting the next run itself; still runs on 6.7

# 1.4.2
- Menu entry under Content now also appears when the admin asset is loaded after the main menu has been built

# 1.4.1
- Default interval of the scheduled task changed to 1440 minutes (24 hours)

# 1.4.0
- Scheduled task either every few minutes (up to 1440 = 24 hours) or once a day at a fixed time; from an interval of one hour on, a run works through all pending images (max. 15 minutes)

# 1.3.0
- Interval of the scheduled task configurable in minutes (default 5); switching it off sets the task to inactive under Settings → System → Tasks, switching it on schedules it again

# 1.2.5
- Fixed: "Convert pending images" aborted with "that.api().call is not a function"

# 1.2.4
- Taking over alt text and title from the original can be switched off (setting under media management); when switched on again, all entries are updated once

# 1.2.3
- New button "Reconvert all images" in the overview: discards all WebP files and reconverts every image with the current settings, cache is cleared once at the end

# 1.2.2
- Alt text and title of the originals are taken over into the media folder in all languages and kept in sync when the original changes; existing entries are completed on update

# 1.2.1
- Setting "Image library" renamed for clarity: "PHP image processing (GD / Imagick)", options with recommendation, help text explains GD and Imagick

# 1.2.0
- Page cache (HTTP cache/Varnish) is cleared automatically after each processing if WebP files were created, removed or changed – for the admin button once at the end of the run; new setting "Clear cache automatically after processing"

# 1.1.4
- Help texts of all settings extended in detail (effect, recommendations, requirements), in German and English

# 1.1.3
- Display name "Modulwerk WebP converter" in menu, settings, page titles and media folder; existing folder is renamed on update

# 1.1.2
- Plugin icon instead of the default symbol under Settings → Extensions

# 1.1.1
- Admin messages show numbers and details again (placeholders are passed via vue-i18n)

# 1.1.0
- Own folder "WebP-Konverter" under Content → Media with the WebP version of every original image (no copy, no thumbnails); existing conversions are added; deleting/renaming in the folder is detected; "Delete unused media" keeps the entries; new setting media management; button "Open media folder" in the overview

# 1.0.3
- New option "Convert JPG losslessly"; if a lossless WebP (PNG or JPG) is not smaller than the original, it is automatically converted lossy instead of being skipped

# 1.0.2
- Settings fully bilingual: English as default, German with lang="de-DE" (previously the German admin showed English); missing help texts added

# 1.0.1
- Admin snippets moved to their own files de-DE.json and en-GB.json (loaded server-side, no build); conversion notes are stored language-neutral and shown in German or English in the admin; umlauts in the German texts fixed

# 1.0.0
- First version: conversion of JPG/PNG including thumbnails to WebP, delivery in the storefront, admin overview with progress, CLI commands, scheduled task, optional lazy loading
