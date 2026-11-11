<?php

namespace UpfluentCmf;

defined('ABSPATH') || exit;

/**
 * A one-time request for a WordPress.org review, on this plugin's own screen
 * only (never an admin-wide notice). Shown to administrators a week after
 * their first visit to this screen with at least one field defined, and never
 * alongside the FluentCart update notice. Either button hides it for good;
 * "Leave a review" also opens the review form.
 */
class ReviewNotice
{
    const OPTION     = 'upfluent_cmf_review';
    const ACTION     = 'upfluent_cmf_review';
    const REVIEW_URL = 'https://wordpress.org/support/plugin/custom-meta-fields-for-fluentcart/reviews/#new-post';
    const DELAY      = WEEK_IN_SECONDS;

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /** The card for AdminMenu::render(), or nothing. */
    public static function html(): string
    {
        if (!current_user_can('manage_options') || !FieldsService::all() || CoreFeatureWatch::pending()) {
            return '';
        }

        $state = get_option(self::OPTION, []);
        $state = is_array($state) ? $state : [];
        if (!empty($state['done'])) {
            return '';
        }
        if (empty($state['since'])) {
            update_option(self::OPTION, ['since' => time()], false);
            return '';
        }
        if (time() - (int) $state['since'] < self::DELAY) {
            return '';
        }

        $url = function ($go) {
            return wp_nonce_url(admin_url('admin-post.php?action=' . self::ACTION . '&go=' . $go), self::ACTION);
        };

        return '<div class="fct-cm-app fct-cm-app--notice' . (FluentHub::isPortalRequest() ? ' fct-cm-app--hub' : '') . '"><section class="fct-cm-card fct-cm-card--note">'
            . '<p class="fct-cm-card-title">' . esc_html__('Enjoying Custom Meta Fields?', 'custom-meta-fields-for-fluentcart') . '</p>'
            . '<p>' . esc_html__('A short review on WordPress.org helps other FluentCart stores find it. It takes a minute.', 'custom-meta-fields-for-fluentcart') . '</p>'
            . '<p class="fct-cm-card-actions"><a class="fct-cm-btn fct-cm-btn--primary" href="' . esc_url($url('review')) . '" target="_blank" rel="noopener">' . esc_html__('Leave a review', 'custom-meta-fields-for-fluentcart') . '</a> '
            . '<a class="fct-cm-btn fct-cm-btn--ghost" href="' . esc_url($url('dismiss')) . '">' . esc_html__('No thanks', 'custom-meta-fields-for-fluentcart') . '</a></p>'
            . '</section></div>';
    }

    public function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Sorry, you are not allowed to do that.', 'custom-meta-fields-for-fluentcart'), 403);
        }
        check_admin_referer(self::ACTION);

        update_option(self::OPTION, ['done' => time()], false);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked above
        if (isset($_GET['go']) && sanitize_key(wp_unslash($_GET['go'])) === 'review') {
            wp_redirect(self::REVIEW_URL); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed WordPress.org URL
            exit;
        }
        wp_safe_redirect(wp_get_referer() ?: AdminMenu::url());
        exit;
    }
}
