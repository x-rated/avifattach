<?php
/**
 * AVIF attachments - converts uploaded images to AVIF through GD.
 *
 * @copyright (c) 2026 Slamb
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace slamb\avifattach\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
	/** Photos (JPEG, lossy WebP): 4:2:0 colour is fine and keeps the files small */
	const QUALITY = 50;

	/**
	 * Screenshots and graphics (PNG, BMP, GIF, lossless WebP): GD switches the encoder to full-resolution
	 * colour (4:4:4) from quality 90, which removes the colour fringes around coloured text.
	 */
	const QUALITY_SHARP = 90;

	/** @var \phpbb\config\config */
	protected $config;

	/** @var string */
	protected $root_path;

	public function __construct(\phpbb\config\config $config, $root_path)
	{
		$this->config = $config;
		$this->root_path = $root_path;
	}

	public static function getSubscribedEvents()
	{
		return [
			'core.modify_uploaded_file'   => 'convert_upload',
			'core.thumbnail_create_before' => 'avif_thumbnail',
			'core.ucp_profile_avatar_sql'  => 'convert_avatar',
			'core.acp_users_avatar_sql'    => 'convert_avatar',
		];
	}

	/**
	 * Quality by source type: lossless sources (screenshots, graphics) are encoded in 4:4:4, photos in 4:2:0.
	 */
	protected function quality_for($ext, $raw)
	{
		if (in_array($ext, ['png', 'bmp', 'gif'], true))
		{
			return self::QUALITY_SHARP;
		}
		if ($ext === 'webp' && strpos(substr($raw, 0, 64), 'VP8L') !== false)
		{
			return self::QUALITY_SHARP;
		}
		return self::QUALITY;
	}

	/**
	 * True when an AVIF file stores full-resolution colour (read from its av1C box).
	 */
	protected function is_444($head)
	{
		$p = strpos($head, 'av1C');
		return $p !== false && isset($head[$p + 6]) && (ord($head[$p + 6]) & 0x0C) === 0;
	}

	protected function supported()
	{
		return function_exists('imageavif') && function_exists('imagecreatefromavif');
	}

	/**
	 * Re-encode the uploaded image as AVIF in place. The physical file name has no
	 * extension, so it can stay the same and phpBB's thumbnail step finds it.
	 */
	public function convert_upload($event)
	{
		$data = $event['filedata'];

		if (!$event['is_image'] || !$this->supported() || !empty($data['error']) || empty($data['physical_filename']))
		{
			return;
		}

		$ext = strtolower($data['extension'] ?? '');
		if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true))
		{
			return;
		}

		$path = $this->root_path . $this->config['upload_path'] . '/' . $data['physical_filename'];
		if (!is_file($path))
		{
			return;
		}

		$raw = @file_get_contents($path);
		if ($raw === false || $this->is_animated($raw) || !$this->fits_in_memory($raw))
		{
			return; // GD cannot keep animation / not enough memory, leave the original untouched
		}

		$img = @imagecreatefromstring($raw);
		if ($img === false)
		{
			return;
		}

		if ($ext === 'jpg' || $ext === 'jpeg')
		{
			$img = $this->apply_orientation($img, $path);
		}

		// keep transparency
		imagepalettetotruecolor($img);
		imagealphablending($img, false);
		imagesavealpha($img, true);

		// re-encoding drops all metadata (EXIF, GPS, ICC, XMP)
		$tmp = $path . '.avif.tmp';
		$ok = @imageavif($img, $tmp, $this->quality_for($ext, $raw));
		unset($img);

		clearstatcache(true, $tmp);
		$size = $ok ? (int) @filesize($tmp) : 0;

		// failed, or not smaller than the original: keep the original
		if ($size <= 0 || $size >= strlen($raw))
		{
			@unlink($tmp);
			return;
		}

		if (!@rename($tmp, $path))
		{
			@unlink($tmp);
			return;
		}

		$data['filesize'] = $size;
		$data['mimetype'] = 'image/avif';
		$data['extension'] = 'avif';
		$name = $data['real_filename'] ?? '';
		$data['real_filename'] = (preg_match('/\.[A-Za-z0-9]+$/', $name) ? preg_replace('/\.[A-Za-z0-9]+$/', '.avif', $name) : $name . '.avif');

		$event['filedata'] = $data;
	}

	/**
	 * Uploaded avatars: the file is already in the avatar folder when phpBB is about to save the
	 * result, so re-encode it in place. The file name and the stored name keep their old extension
	 * (phpBB only serves avatars with png/gif/jpg/jpeg names); download/file.php sends the real type.
	 */
	public function convert_avatar($event)
	{
		$result = $event['result'];

		if (!$this->supported() || ($result['user_avatar_type'] ?? '') !== 'avatar.driver.upload' || empty($result['user_avatar']))
		{
			return;
		}

		// stored name is "<user_id>_<time>.<ext>", the file on disk "<salt>_<user_id>.<ext>"
		if (!preg_match('/^(\d+)_\d+\.([A-Za-z0-9]+)$/', $result['user_avatar'], $m))
		{
			return;
		}
		$ext = strtolower($m[2]);
		if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true))
		{
			return;
		}

		$path = $this->root_path . rtrim($this->config['avatar_path'], '/') . '/' . $this->config['avatar_salt'] . '_' . $m[1] . '.' . $m[2];
		if (!is_file($path))
		{
			return;
		}

		$raw = @file_get_contents($path);
		if ($raw === false || $this->is_animated($raw) || !$this->fits_in_memory($raw))
		{
			return;
		}

		$img = @imagecreatefromstring($raw);
		if ($img === false)
		{
			return;
		}

		if ($ext === 'jpg' || $ext === 'jpeg')
		{
			$img = $this->apply_orientation($img, $path);
		}

		imagepalettetotruecolor($img);
		imagealphablending($img, false);
		imagesavealpha($img, true);

		$w = imagesx($img);
		$h = imagesy($img);

		$tmp = $path . '.avif.tmp';
		$ok = @imageavif($img, $tmp, $this->quality_for($ext, $raw));
		unset($img);

		clearstatcache(true, $tmp);
		$size = $ok ? (int) @filesize($tmp) : 0;

		if ($size <= 0 || $size >= strlen($raw) || !@rename($tmp, $path))
		{
			@unlink($tmp);
			return;
		}

		// orientation may have swapped the sides
		$result['user_avatar_width'] = $w;
		$result['user_avatar_height'] = $h;
		$event['result'] = $result;
	}

	/**
	 * phpBB thumbnails use the original mimetype, which no longer matches an
	 * AVIF file, so build the thumbnail ourselves.
	 */
	public function avif_thumbnail($event)
	{
		$source = $event['source'];

		$head = @file_get_contents($source, false, null, 0, 512);
		if ($head === false || substr($head, 4, 8) !== 'ftypavif' || !$this->supported())
		{
			return;
		}

		$img = @imagecreatefromavif($source);
		if ($img === false)
		{
			return;
		}

		$w = imagesx($img);
		$h = imagesy($img);
		$nw = max(1, (int) $event['new_width']);
		$nh = max(1, (int) $event['new_height']);

		$new = imagecreatetruecolor($nw, $nh);
		imagealphablending($new, false);
		imagesavealpha($new, true);
		imagefill($new, 0, 0, imagecolorallocatealpha($new, 0, 0, 0, 127));
		imagecopyresampled($new, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);

		// keep full-resolution colour in the thumbnail when the original has it
		if (@imageavif($new, $event['destination'], $this->is_444($head) ? self::QUALITY_SHARP : self::QUALITY))
		{
			$event['thumbnail_created'] = true;
		}
	}

	/**
	 * A small file can still decode to a huge bitmap (e.g. a 20000x20000 PNG). Refuse to decode what would
	 * not fit into the PHP memory limit, the original is then simply kept.
	 */
	protected function fits_in_memory($raw)
	{
		$info = @getimagesizefromstring($raw);
		if (!$info)
		{
			return false;
		}
		$limit = ini_get('memory_limit');
		if ($limit === '-1' || $limit === false || $limit === '')
		{
			return true;
		}
		$bytes = (int) $limit;
		switch (strtolower(substr($limit, -1)))
		{
			case 'g': $bytes *= 1024;
			case 'm': $bytes *= 1024;
			case 'k': $bytes *= 1024;
		}
		// GD true colour: 4 bytes per pixel, plus a second copy for rotation / palette conversion
		return ((int) $info[0] * (int) $info[1] * 4 * 2 + memory_get_usage()) < ($bytes * 0.9);
	}

	protected function is_animated($raw)
	{
		if (substr($raw, 0, 3) === 'GIF')
		{
			// more than one image descriptor / graphic control extension = animated
			return preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $raw) > 1;
		}
		if (substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP')
		{
			return strpos($raw, 'ANIM') !== false && strpos($raw, 'ANMF') !== false;
		}
		if (substr($raw, 0, 8) === "\x89PNG\r\n\x1a\n")
		{
			return strpos(substr($raw, 0, 4096), 'acTL') !== false;
		}
		return false;
	}

	protected function apply_orientation($img, $path)
	{
		if (!function_exists('exif_read_data'))
		{
			return $img;
		}
		$exif = @exif_read_data($path);
		$o = (int) ($exif['Orientation'] ?? 1);
		switch ($o)
		{
			case 2: imageflip($img, IMG_FLIP_HORIZONTAL); break;
			case 3: $img = imagerotate($img, 180, 0); break;
			case 4: imageflip($img, IMG_FLIP_VERTICAL); break;
			case 5: $img = imagerotate($img, -90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
			case 6: $img = imagerotate($img, -90, 0); break;
			case 7: $img = imagerotate($img, 90, 0); imageflip($img, IMG_FLIP_HORIZONTAL); break;
			case 8: $img = imagerotate($img, 90, 0); break;
		}
		return $img;
	}
}
