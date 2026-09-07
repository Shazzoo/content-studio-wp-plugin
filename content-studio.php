<?php

/*
* Plugin Name: Content Studio
* Description: Fetches article content from an external API and stores it locally.
* Version: 1.0
*/

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CONTENT_STUDIO_API_ROUTE')) {
    define('CONTENT_STUDIO_API_ROUTE', 'https://engine.content-studio.com/api/v1');
}

define('CONTENT_STUDIO_PLUGIN_FILE', __FILE__);
define('CONTENT_STUDIO_REWRITE_VERSION', '3');
define('CONTENT_STUDIO_SYNC_CRON_EVENT', 'content_studio_sync_articles');
define('CONTENT_STUDIO_SYNC_CRON_INTERVAL', 'content_studio_fifteen_minutes');

function content_studio_enqueue_styles($include_custom_styles = true)
{
    $css_path = plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'css/content-studio.css';

    wp_enqueue_style(
        'content-studio',
        plugin_dir_url(CONTENT_STUDIO_PLUGIN_FILE) . 'css/content-studio.css',
        [],
        file_exists($css_path) ? filemtime($css_path) : '1.0'
    );

    if (!$include_custom_styles) {
        return;
    }

    $custom_css = content_studio_get_custom_style_css();

    if ($custom_css) {
        wp_add_inline_style('content-studio', $custom_css);
    }
}

function content_studio_get_custom_style_css()
{
    $variables = [];

    foreach (content_studio_get_style_color_settings() as $option_name => $setting) {
        $color = sanitize_hex_color(get_option($option_name, ''));

        if (!$color && isset($setting['default'])) {
            $color = $setting['default'];
        }

        if ($color) {
            $variables[] = sprintf('%s: %s;', $setting['css_variable'], $color);
            $variables[] = sprintf(
                '%s: %s;',
                str_replace('--content-studio-', '--_content-studio-', $setting['css_variable']),
                $color
            );

            if ('content_studio_color_primary_hover' === $option_name) {
                $variables[] = sprintf('--content-studio-color-article-title-hover: %s;', $color);
            }
        }
    }

    foreach (content_studio_get_style_range_settings() as $option_name => $setting) {
        $value = isset($setting['type']) && 'float' === $setting['type'] ? (float) get_option($option_name, $setting['default']) : absint(get_option($option_name, $setting['default']));
        $value = max($setting['min'], min($setting['max'], $value));

        if ($value !== $setting['default']) {
            $variables[] = sprintf('%s: %s%s;', $setting['css_variable'], $value, $setting['unit']);
            $variables[] = sprintf(
                '%s: %s%s;',
                str_replace('--content-studio-', '--_content-studio-', $setting['css_variable']),
                $value,
                $setting['unit']
            );
        }
    }

    $css = $variables ? '.content-studio-blog {' . implode('', $variables) . '}' : '';

    $css .= '.content-studio-blog .content-studio-blog__article:hover .content-studio-blog__article-link,';
    $css .= '.content-studio-blog .content-studio-blog__article:focus-within .content-studio-blog__article-link,';
    $css .= '.content-studio-blog .content-studio-blog__article-link:hover,';
    $css .= '.content-studio-blog .content-studio-blog__article-link:focus {';
    $css .= 'color: var(--content-studio-color-article-title-hover, var(--_content-studio-color-text)) !important;';
    $css .= '}';

    return $css;
}

function content_studio_get_style_color_settings()
{
    return [
        'content_studio_color_primary_hover' => [
            'label' => 'Primary Color',
            'description' => 'Used when article titles are hovered.',
            'css_variable' => '--content-studio-color-primary-hover',
            'default' => '#ec8f39',
        ],
        'content_studio_color_text' => [
            'label' => 'Text Color',
            'description' => 'Used for titles and article text.',
            'css_variable' => '--content-studio-color-text',
            'default' => '#111827',
        ],
        'content_studio_color_muted' => [
            'label' => 'Meta Text Color',
            'description' => 'Used for article metadata and secondary text.',
            'css_variable' => '--content-studio-color-muted',
            'default' => '#666666',
        ],
        'content_studio_color_background' => [
            'label' => 'Background Color',
            'description' => 'Used for article card backgrounds.',
            'css_variable' => '--content-studio-color-background',
        ],
    ];
}

function content_studio_get_style_range_settings()
{
    return [
        'content_studio_card_padding' => [
            'label' => 'Padding',
            'description' => 'Controls the spacing inside each article card.',
            'css_variable' => '--content-studio-card-padding',
            'default' => 0,
            'min' => 0,
            'max' => 48,
            'step' => 1,
            'unit' => 'px',
        ],
        'content_studio_card_rounding' => [
            'label' => 'Rounding',
            'description' => 'Controls the overall rounded corners for articles and images.',
            'css_variable' => '--content-studio-card-rounding',
            'default' => 12,
            'min' => 0,
            'max' => 32,
            'step' => 1,
            'unit' => 'px',
        ],
        'content_studio_card_container_rounding' => [
            'label' => 'Card Rounding',
            'description' => 'Controls the rounded corners on article cards.',
            'css_variable' => '--content-studio-card-border-radius',
            'default' => 12,
            'min' => 0,
            'max' => 32,
            'step' => 1,
            'unit' => 'px',
            'advanced_group' => 'rounding',
        ],
        'content_studio_card_image_rounding' => [
            'label' => 'Image Rounding',
            'description' => 'Controls the rounded corners on card images.',
            'css_variable' => '--content-studio-image-border-radius',
            'default' => 12,
            'min' => 0,
            'max' => 32,
            'step' => 1,
            'unit' => 'px',
            'advanced_group' => 'rounding',
        ],
        'content_studio_card_font_size' => [
            'label' => 'Font Size',
            'description' => 'Controls the overall font sizing in articles.',
            'css_variable' => '--content-studio-card-font-size',
            'default' => 24,
            'min' => 16,
            'max' => 40,
            'step' => 1,
            'unit' => 'px',
        ],
        'content_studio_card_title_font_size' => [
            'label' => 'Title Font Size',
            'description' => 'Controls the article title size in cards.',
            'css_variable' => '--content-studio-card-title-size',
            'default' => 24,
            'min' => 16,
            'max' => 40,
            'step' => 1,
            'unit' => 'px',
            'advanced_group' => 'font-size',
        ],
        'content_studio_card_meta_font_size' => [
            'label' => 'Meta Font Size',
            'description' => 'Controls the date and read-time size in cards.',
            'css_variable' => '--content-studio-meta-font-size',
            'default' => 12,
            'min' => 10,
            'max' => 20,
            'step' => 1,
            'unit' => 'px',
            'advanced_group' => 'font-size',
        ],
        'content_studio_card_excerpt_font_size' => [
            'label' => 'Excerpt Font Size',
            'description' => 'Controls the excerpt text size in cards.',
            'css_variable' => '--content-studio-excerpt-font-size',
            'default' => 16,
            'min' => 12,
            'max' => 28,
            'step' => 1,
            'unit' => 'px',
            'advanced_group' => 'font-size',
        ],
        'content_studio_card_line_height' => [
            'label' => 'Line Height',
            'description' => 'Controls the overall line height in articles.',
            'css_variable' => '--content-studio-card-line-height',
            'default' => 1.4,
            'min' => 1,
            'max' => 2,
            'step' => 0.05,
            'unit' => '',
            'type' => 'float',
        ],
        'content_studio_card_title_line_height' => [
            'label' => 'Title Line Height',
            'description' => 'Controls the title line height in cards.',
            'css_variable' => '--content-studio-card-title-line-height',
            'default' => 1.4,
            'min' => 1,
            'max' => 2,
            'step' => 0.05,
            'unit' => '',
            'type' => 'float',
            'advanced_group' => 'line-height',
        ],
        'content_studio_card_meta_line_height' => [
            'label' => 'Meta Line Height',
            'description' => 'Controls the meta line height in cards.',
            'css_variable' => '--content-studio-meta-line-height',
            'default' => 1.4,
            'min' => 1,
            'max' => 2,
            'step' => 0.05,
            'unit' => '',
            'type' => 'float',
            'advanced_group' => 'line-height',
        ],
        'content_studio_card_excerpt_line_height' => [
            'label' => 'Excerpt Line Height',
            'description' => 'Controls the excerpt line height in cards.',
            'css_variable' => '--content-studio-excerpt-line-height',
            'default' => 1.4,
            'min' => 1,
            'max' => 2,
            'step' => 0.05,
            'unit' => '',
            'type' => 'float',
            'advanced_group' => 'line-height',
        ],
        'content_studio_card_inner_spacing' => [
            'label' => 'Inner Spacing',
            'description' => 'Controls the vertical spacing inside each article.',
            'css_variable' => '--content-studio-card-inner-spacing',
            'default' => 12,
            'min' => 0,
            'max' => 32,
            'step' => 1,
            'unit' => 'px',
        ],
        'content_studio_card_image_spacing' => [
            'label' => 'Image Spacing',
            'description' => 'Controls the space below the article image.',
            'css_variable' => '--content-studio-card-image-spacing',
            'default' => 12,
            'min' => 0,
            'max' => 32,
            'step' => 1,
            'unit' => 'px',
            'advanced_group' => 'spacing',
        ],
        'content_studio_card_title_spacing' => [
            'label' => 'Title Spacing',
            'description' => 'Controls the space below the article title.',
            'css_variable' => '--content-studio-card-title-spacing',
            'default' => 12,
            'min' => 0,
            'max' => 32,
            'step' => 1,
            'unit' => 'px',
            'advanced_group' => 'spacing',
        ],
        'content_studio_card_meta_spacing' => [
            'label' => 'Meta Spacing',
            'description' => 'Controls the space below the article meta.',
            'css_variable' => '--content-studio-card-meta-spacing',
            'default' => 12,
            'min' => 0,
            'max' => 32,
            'step' => 1,
            'unit' => 'px',
            'advanced_group' => 'spacing',
        ],
    ];
}

function content_studio_enqueue_admin_styles($hook)
{
    if ('settings_page_content-studio' !== $hook) {
        return;
    }

    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    content_studio_enqueue_styles(false);

    if (function_exists('wp_get_global_stylesheet')) {
        wp_add_inline_style('content-studio', wp_get_global_stylesheet(['variables']));
    }
}

function content_studio_register_cron_interval($schedules)
{
    $schedules[CONTENT_STUDIO_SYNC_CRON_INTERVAL] = [
        'interval' => 15 * MINUTE_IN_SECONDS,
        'display'  => __('Every 15 minutes', 'content-studio'),
    ];

    return $schedules;
}

function content_studio_schedule_sync()
{
    $scheduled = wp_get_scheduled_event(CONTENT_STUDIO_SYNC_CRON_EVENT);

    if ($scheduled && CONTENT_STUDIO_SYNC_CRON_INTERVAL === $scheduled->schedule) {
        return;
    }

    if ($scheduled) {
        content_studio_clear_sync_schedule();
    }

    wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, CONTENT_STUDIO_SYNC_CRON_INTERVAL, CONTENT_STUDIO_SYNC_CRON_EVENT);
}

function content_studio_clear_sync_schedule()
{
    wp_clear_scheduled_hook(CONTENT_STUDIO_SYNC_CRON_EVENT);
}

function content_studio_run_scheduled_sync()
{
    $result = Content_Studio_Admin_Settings::run_sync();

    update_option('content_studio_last_sync_at', current_time('mysql'));

    if (is_wp_error($result)) {
        update_option('content_studio_last_sync_error', $result->get_error_message());
        return;
    }

    update_option('content_studio_last_sync_error', '');
    update_option('content_studio_last_sync_count', absint($result));
}

add_action('wp_enqueue_scripts', 'content_studio_enqueue_styles');
add_action('admin_enqueue_scripts', 'content_studio_enqueue_admin_styles');
add_filter('cron_schedules', 'content_studio_register_cron_interval');
add_action('init', 'content_studio_schedule_sync');

require_once plugin_dir_path(__FILE__) . 'includes/class-language.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-api-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-storage.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-rest-routes.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-admin-settings.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-latest-posts-block.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-blog-route.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-publish-confirmation.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-seo.php';

register_activation_hook(__FILE__, ['Content_Studio_Blog_Route', 'activate']);
register_activation_hook(__FILE__, 'content_studio_schedule_sync');
register_deactivation_hook(__FILE__, ['Content_Studio_Blog_Route', 'deactivate']);
register_deactivation_hook(__FILE__, 'content_studio_clear_sync_schedule');
add_action(CONTENT_STUDIO_SYNC_CRON_EVENT, 'content_studio_run_scheduled_sync');

new Content_Studio_REST_Routes();
new Content_Studio_Admin_Settings();
new Content_Studio_Latest_Posts_Block();
new Content_Studio_Blog_Route();
new Content_Studio_Publish_Confirmation();
new Content_Studio_Seo();
