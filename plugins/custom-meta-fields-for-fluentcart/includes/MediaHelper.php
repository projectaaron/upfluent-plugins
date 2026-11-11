<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * Attachment helpers shared by the editor (preview data) and the shortcodes
 * (front-end markup). Values are attachment IDs, never URLs.
 */
class MediaHelper
{
    /** Broad kind of an attachment: image | video | audio | file. */
    public static function kind(int $attachment_id): string
    {
        $mime = (string) get_post_mime_type($attachment_id);
        if (strpos($mime, 'image/') === 0) {
            return 'image';
        }
        if (strpos($mime, 'video/') === 0) {
            return 'video';
        }
        if (strpos($mime, 'audio/') === 0) {
            return 'audio';
        }
        return 'file';
    }

    /** True when the attachment exists and matches the field's allowed_types. */
    public static function isAllowed(int $attachment_id, string $allowed): bool
    {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }
        if ($allowed === 'any' || $allowed === '') {
            return true;
        }
        return self::kind($attachment_id) === $allowed;
    }

    /**
     * Preview payload for the editor.
     * @return array|null id, kind, mime, url, filename, thumb, poster
     */
    public static function info(int $attachment_id): ?array
    {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return null;
        }

        $kind   = self::kind($attachment_id);
        $url    = (string) wp_get_attachment_url($attachment_id);
        $thumb  = '';
        $poster = '';

        if ($kind === 'image') {
            $src   = wp_get_attachment_image_src($attachment_id, 'thumbnail');
            $thumb = $src ? (string) $src[0] : $url;
        } elseif ($kind === 'video') {
            $poster = self::posterUrl($attachment_id, 'medium');
        }

        return [
            'id'       => $attachment_id,
            'kind'     => $kind,
            'mime'     => (string) get_post_mime_type($attachment_id),
            'url'      => $url,
            'filename' => wp_basename((string) get_attached_file($attachment_id)),
            'title'    => get_the_title($attachment_id),
            'thumb'    => $thumb,
            'poster'   => $poster,
        ];
    }

    /** Poster for a video attachment: its own featured image, if set. */
    public static function posterUrl(int $attachment_id, string $size = 'large'): string
    {
        $poster_id = (int) get_post_thumbnail_id($attachment_id);
        if (!$poster_id) {
            return '';
        }
        $src = wp_get_attachment_image_src($poster_id, $size);
        return $src ? (string) $src[0] : '';
    }
}
