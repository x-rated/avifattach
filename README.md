# AVIF attachments (slamb/avifattach)

Converts uploaded image attachments and avatars to AVIF with PHP's GD, or with Imagick when GD has no AVIF (quality 50; screenshots and graphics 90) before they are stored.

## Requirements
- phpBB 3.3.x
- PHP with GD built with AVIF support (`imageavif()` and `imagecreatefromavif()`, PHP 8.1+ with libavif), **or** the Imagick extension with an ImageMagick that can read and write AVIF (libheif/libavif). GD is preferred; Imagick is used only for the AVIF step and is checked at runtime (a build that only reads AVIF is rejected). Quality 90 asks for full-resolution colour from Imagick on a best-effort basis; whether a build delivers 4:4:4 depends on the ImageMagick version. Without it the extension does nothing and uploads work as before.
- The extension `avif` must be allowed in the ACP (Posting > Attachments > Manage extensions, group *Images*), otherwise converted attachments cannot be downloaded.

## What it does
- JPEG, PNG, GIF, WebP and BMP uploads are re-encoded as AVIF. Metadata (EXIF, GPS, ICC, XMP) is dropped; the EXIF orientation is applied first.
- Animated GIF/WebP/PNG are left untouched, as is any image that is not smaller as AVIF or too large for the PHP memory limit.
- Attachment: the file name, extension and mime type are changed to `.avif` / `image/avif`; thumbnails are created as AVIF too.
- Avatar: the file keeps its old name and extension (phpBB only serves avatars named png/gif/jpg/jpeg); the content is AVIF and `download/file.php` sends the real type.
- The upload progress in the posting form shows the real (converted) size.

## Notes
- The original file is **not** kept.
- Already uploaded images can be converted with the companion extension `slamb/avifmigrate`.

## License
GPL-2.0-only, see `license.txt`.
