<?php

/**
 * Confirms to Content Studio Engine that an article is actually live on this
 * site. Runs both when a sync publishes an article and when an editor
 * publishes a Content Studio post by hand, so the Engine gets the signal
 * whichever way the post went live.
 *
 * Each confirmation is recorded on the post, so a repeat sync does not fire a
 * request per article every hour. If the permalink changes, the article is
 * confirmed again with the new URL.
 */
class Content_Studio_Publish_Confirmation
{
    const CONFIRMED_AT_META = '_content_studio_published_confirmed_at';

    const CONFIRMED_URL_META = '_content_studio_published_confirmed_url';

    public function __construct()
    {
        add_action('transition_post_status', [self::class, 'on_transition_post_status'], 10, 3);
    }

    public static function on_transition_post_status($new_status, $old_status, $post)
    {
        if ('publish' !== $new_status) {
            return;
        }

        if (!$post instanceof WP_Post || 'post' !== $post->post_type) {
            return;
        }

        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) {
            return;
        }

        self::maybe_confirm($post->ID);
    }

    /**
     * WordPress kent de omgeving via WP_ENVIRONMENT_TYPE; zonder die constante
     * gaat WordPress zelf uit van 'production', dus dan gedraagt de plugin
     * zich zoals voorheen.
     *
     * @return bool
     */
    private static function is_production()
    {
        if (defined('CONTENT_STUDIO_CONFIRM_PUBLISHED')) {
            return (bool) CONTENT_STUDIO_CONFIRM_PUBLISHED;
        }

        if (!function_exists('wp_get_environment_type')) {
            return true;
        }

        return 'production' === wp_get_environment_type();
    }

    /**
     * @param int $post_id
     *
     * @return bool Whether a confirmation was sent.
     */
    public static function maybe_confirm($post_id)
    {
        // Alleen productie bevestigt publicatie bij de Engine. Een lokale of
        // staging-installatie die naar dezelfde Engine wijst, zou anders echte
        // content op gepubliceerd zetten en uit de sync laten vallen.
        if (!self::is_production()) {
            return false;
        }

        $post_id = absint($post_id);

        if (!$post_id) {
            return false;
        }

        $post = get_post($post_id);

        if (!$post instanceof WP_Post || 'publish' !== $post->post_status) {
            return false;
        }

        $content_id = get_post_meta($post_id, '_content_studio_external_id', true);

        // The Engine identifies content by its numeric ID; posts stored under
        // the uuid/slug fallback cannot be confirmed.
        if (!is_numeric($content_id)) {
            return false;
        }

        $published_url = self::get_published_url($post_id);

        $confirmed_at = get_post_meta($post_id, self::CONFIRMED_AT_META, true);
        $confirmed_url = get_post_meta($post_id, self::CONFIRMED_URL_META, true);

        if ($confirmed_at && $confirmed_url === $published_url) {
            return false;
        }

        $response = Content_Studio_API_Client::confirm_published($content_id, $published_url);

        if (is_wp_error($response)) {
            update_option('content_studio_last_publish_confirmation_error', $response->get_error_message());

            return false;
        }

        update_option('content_studio_last_publish_confirmation_error', '');
        update_post_meta($post_id, self::CONFIRMED_AT_META, current_time('mysql'));
        update_post_meta($post_id, self::CONFIRMED_URL_META, $published_url);

        return true;
    }

    private static function get_published_url($post_id)
    {
        $permalink = get_permalink($post_id);

        if (!$permalink) {
            return '';
        }

        // The Engine stores published_url as a string(255).
        return strlen($permalink) > 255 ? '' : $permalink;
    }
}
