<?php

/**
 * Stuurt paginaweergaven en klikken op artikelpagina's naar de Engine, zodat
 * die ziet hoe een gepubliceerd artikel presteert.
 *
 * Het script staat als los bestand in de plugin en leest zijn configuratie
 * uit data-attributen op #content-studio-tracking. Inline scripts of
 * wp_localize_script zouden door een strikte CSP worden geblokkeerd.
 *
 * Uitzetten kan met de constante CONTENT_STUDIO_TRACKING_ENABLED.
 */
class Content_Studio_Tracking
{
    /**
     * Events gaan altijd naar de Engine, nooit naar de site zelf.
     */
    const DEFAULT_ENDPOINT = 'https://engine.content-studio.com/api/tracking/event';

    public function __construct()
    {
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_script']);
        // Voor wp_print_footer_scripts (prioriteit 20), zodat het element er
        // staat zodra het script draait.
        add_action('wp_footer', [self::class, 'render_config'], 5);
    }

    public static function enqueue_script()
    {
        if (!self::should_track()) {
            return;
        }

        $script_path = plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'js/tracking.js';

        wp_enqueue_script(
            'content-studio-tracking',
            plugin_dir_url(CONTENT_STUDIO_PLUGIN_FILE) . 'js/tracking.js',
            [],
            file_exists($script_path) ? filemtime($script_path) : '1.0',
            true
        );
    }

    public static function render_config()
    {
        if (!self::should_track()) {
            return;
        }

        $post_id = get_queried_object_id();

        printf(
            '<div id="content-studio-tracking" hidden data-endpoint="%s" data-project-key="%s" data-content-id="%s" data-article-slug="%s"></div>' . "\n",
            esc_url(self::endpoint()),
            esc_attr(get_option('content_studio_project_id', '')),
            esc_attr(get_post_meta($post_id, '_content_studio_external_id', true)),
            esc_attr(get_post_field('post_name', $post_id))
        );
    }

    /**
     * Alleen gesynchroniseerde artikelen; gewone berichten van de site horen
     * niet in de statistieken van de Engine.
     *
     * @return bool
     */
    private static function should_track()
    {
        if (defined('CONTENT_STUDIO_TRACKING_ENABLED') && !CONTENT_STUDIO_TRACKING_ENABLED) {
            return false;
        }

        if (!is_singular('post')) {
            return false;
        }

        return '' !== (string) get_post_meta(get_queried_object_id(), '_content_studio_external_id', true);
    }

    /**
     * Altijd een absolute Engine-URL. Een relatieve waarde zou het event naar
     * de site zelf sturen, dus die valt terug op de default.
     *
     * @return string
     */
    private static function endpoint()
    {
        $endpoint = defined('CONTENT_STUDIO_TRACKING_ENDPOINT') ? trim((string) CONTENT_STUDIO_TRACKING_ENDPOINT) : '';

        if ('' === $endpoint || !preg_match('#^https?://#i', $endpoint)) {
            return self::DEFAULT_ENDPOINT;
        }

        return $endpoint;
    }
}
