<?php

class Content_Studio_Admin_Settings
{
    public function __construct()
    {
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_content_studio_sync', [$this, 'sync_articles']);
    }

    public function add_settings_page()
    {
        add_options_page(
            'Content Studio',
            'Content Studio',
            'manage_options',
            'content-studio',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        register_setting(
            'content_studio_settings',
            'content_studio_api_key',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );

        register_setting(
            'content_studio_settings',
            'content_studio_project_id',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );

        register_setting(
            'content_studio_settings',
            'content_studio_category_name',
            [
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => '',
            ]
        );

        register_setting(
            'content_studio_settings',
            'content_studio_fallback_author_id',
            [
                'type' => 'integer',
                'sanitize_callback' => [$this, 'sanitize_fallback_author_id'],
                'default' => 0,
            ]
        );

        foreach (content_studio_get_style_color_settings() as $option_name => $setting) {
            register_setting(
                'content_studio_settings',
                $option_name,
                [
                    'type' => 'string',
                    'sanitize_callback' => [$this, 'sanitize_color'],
                    'default' => '',
                ]
            );

            register_setting(
                'content_studio_settings',
                $setting['use_theme_option'],
                [
                    'type' => 'boolean',
                    'sanitize_callback' => [$this, 'sanitize_theme_color_toggle'],
                    'default' => true,
                ]
            );
        }

        foreach (content_studio_get_style_range_settings() as $option_name => $setting) {
            register_setting(
                'content_studio_settings',
                $option_name,
                [
                    'type' => 'integer',
                    'sanitize_callback' => function ($value) use ($setting) {
                        return $this->sanitize_range($value, $setting);
                    },
                    'default' => $setting['default'],
                ]
            );
        }

        add_settings_section(
            'content_studio_api_section',
            'API Settings',
            '__return_empty_string',
            'content-studio'
        );

        add_settings_field(
            'content_studio_api_key',
            'API Key',
            [$this, 'render_api_key_field'],
            'content-studio',
            'content_studio_api_section'
        );

        add_settings_field(
            'content_studio_project_id',
            'Project ID',
            [$this, 'render_project_id_field'],
            'content-studio',
            'content_studio_api_section'
        );

        add_settings_field(
            'content_studio_category_name',
            'Article Category',
            [$this, 'render_category_field'],
            'content-studio',
            'content_studio_api_section'
        );

        add_settings_field(
            'content_studio_fallback_author_id',
            'Fallback Author',
            [$this, 'render_fallback_author_field'],
            'content-studio',
            'content_studio_api_section'
        );

        add_settings_section(
            'content_studio_style_section',
            'Style Settings',
            [$this, 'render_style_section'],
            'content-studio'
        );

        foreach (content_studio_get_style_color_settings() as $option_name => $setting) {
            add_settings_field(
                $option_name,
                $setting['label'],
                [$this, 'render_color_field'],
                'content-studio',
                'content_studio_style_section',
                [
                    'option_name' => $option_name,
                    'use_theme_option' => $setting['use_theme_option'],
                    'css_variable' => $setting['css_variable'],
                    'default' => isset($setting['default']) ? $setting['default'] : '',
                    'description' => $setting['description'],
                ]
            );
        }

        $advanced_spacing_field_added = false;

        foreach (content_studio_get_style_range_settings() as $option_name => $setting) {
            if (!empty($setting['advanced']) && !$advanced_spacing_field_added) {
                add_settings_field(
                    'content_studio_advanced_spacing_toggle',
                    '',
                    [$this, 'render_advanced_spacing_toggle'],
                    'content-studio',
                    'content_studio_style_section'
                );

                $advanced_spacing_field_added = true;
            }

            add_settings_field(
                $option_name,
                $setting['label'],
                [$this, 'render_range_field'],
                'content-studio',
                'content_studio_style_section',
                [
                    'option_name' => $option_name,
                    'css_variable' => $setting['css_variable'],
                    'default' => $setting['default'],
                    'min' => $setting['min'],
                    'max' => $setting['max'],
                    'step' => $setting['step'],
                    'unit' => $setting['unit'],
                    'description' => $setting['description'],
                    'class' => !empty($setting['advanced']) ? 'content-studio-advanced-spacing-row' : '',
                ]
            );
        }
    }

    public function render_api_key_field()
    {
        printf(
            '<span style="display: inline-flex; align-items: center; gap: 6px;"><input id="content_studio_api_key" type="password" name="content_studio_api_key" value="%s" class="regular-text" autocomplete="new-password" /><button type="button" class="button content-studio-button content-studio-button--icon content-studio-toggle-api-key" aria-label="Show API key" aria-pressed="false"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button></span>',
            esc_attr(get_option('content_studio_api_key', ''))
        );
    }

    public function render_project_id_field()
    {
        printf(
            '<input type="text" name="content_studio_project_id" value="%s" class="regular-text" />',
            esc_attr(get_option('content_studio_project_id', ''))
        );
    }

    public function render_category_field()
    {
        printf(
            '<input type="text" name="content_studio_category_name" value="%s" class="regular-text" placeholder="%s" /> <p class="description">Leave empty to use the default category for the site language.</p>',
            esc_attr(get_option('content_studio_category_name', '')),
            esc_attr(Content_Studio_Storage::get_default_category_name())
        );
    }

    public function render_fallback_author_field()
    {
        $selected_user_id = Content_Studio_Storage::get_fallback_author_id();

        wp_dropdown_users([
            'name' => 'content_studio_fallback_author_id',
            'id' => 'content_studio_fallback_author_id',
            'selected' => $selected_user_id,
            'show' => 'display_name_with_login',
            'class' => 'regular-text',
        ]);

        echo '<p class="description">Used when an article from the API does not include an author.</p>';
    }

    public function render_style_section()
    {
        echo '<p>Enable theme colors to use the active theme when possible. Disable it to use the selected color, or the plugin default when the picker is empty.</p>';
    }

    public function render_color_field($args)
    {
        $option_name = $args['option_name'];
        $use_theme_option = $args['use_theme_option'];
        $css_variable = $args['css_variable'];
        $default = $args['default'];
        $description = $args['description'];
        $use_theme = get_option($use_theme_option, '1');

        printf(
            '<span class="content-studio-style-field" data-css-variable="%6$s" data-fallback-color="%7$s"><input type="text" name="%1$s" value="%2$s" class="content-studio-color-picker" data-default-color="" /><label><input type="hidden" name="%3$s" value="0" /><input type="checkbox" name="%3$s" value="1" %4$s /> Use theme color</label></span><p class="description">%5$s</p>',
            esc_attr($option_name),
            esc_attr(get_option($option_name, '')),
            esc_attr($use_theme_option),
            checked($use_theme, true, false),
            esc_html($description),
            esc_attr($css_variable),
            esc_attr($default)
        );
    }

    public function render_range_field($args)
    {
        $option_name = $args['option_name'];
        $value = absint(get_option($option_name, $args['default']));
        $value = max($args['min'], min($args['max'], $value));

        printf(
            '<span class="content-studio-range-field" data-css-variable="%6$s" data-unit="%7$s" data-default-value="%9$d"><input type="range" name="%1$s" value="%2$d" min="%3$d" max="%4$d" step="%5$d" /><output>%2$d%7$s</output></span><p class="description">%8$s</p>',
            esc_attr($option_name),
            $value,
            absint($args['min']),
            absint($args['max']),
            absint($args['step']),
            esc_attr($args['css_variable']),
            esc_html($args['unit']),
            esc_html($args['description']),
            absint($args['default'])
        );
    }

    public function render_advanced_spacing_toggle()
    {
        echo '<button type="button" class="button content-studio-advanced-spacing-toggle" aria-expanded="false">Advanced spacing</button>';
    }

    public function sanitize_fallback_author_id($user_id)
    {
        $user_id = absint($user_id);

        return get_user_by('id', $user_id) ? $user_id : Content_Studio_Storage::get_content_studio_user_id();
    }

    public function sanitize_color($color)
    {
        $color = trim((string) $color);

        if ('' === $color) {
            return '';
        }

        return sanitize_hex_color($color) ?: '';
    }

    public function sanitize_theme_color_toggle($enabled)
    {
        return (bool) $enabled;
    }

    public function sanitize_range($value, $setting)
    {
        $value = absint($value);

        return max($setting['min'], min($setting['max'], $value));
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sync_result = isset($_GET['content_studio_sync']) ? sanitize_text_field(wp_unslash($_GET['content_studio_sync'])) : '';
        $notice = null;

        if ('success' === $sync_result) {
            $saved = isset($_GET['saved']) ? absint($_GET['saved']) : 0;
            $notice = [
                'type' => 'success',
                'message' => sprintf('Content Studio synced %d article(s).', $saved),
            ];
        } elseif ('error' === $sync_result) {
            $message = isset($_GET['message']) ? sanitize_text_field(wp_unslash($_GET['message'])) : 'Sync failed.';
            $notice = [
                'type' => 'error',
                'message' => $message,
            ];
        }

        $count = Content_Studio_Storage::count_posts();

        require plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'views/admin-settings.php';
    }

    public function sync_articles()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sorry, you are not allowed to sync Content Studio articles.');
        }

        check_admin_referer('content_studio_sync');

        $redirect_url = admin_url('options-general.php?page=content-studio');
        $result = self::run_sync();

        if (is_wp_error($result)) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'content_studio_sync' => 'error',
                        'message' => $result->get_error_message(),
                    ],
                    $redirect_url
                )
            );
            exit;
        }

        wp_safe_redirect(
            add_query_arg(
                [
                    'content_studio_sync' => 'success',
                    'saved' => $result,
                ],
                $redirect_url
            )
        );
        exit;
    }

    public static function run_sync()
    {
        $content = Content_Studio_API_Client::fetch_content();

        if (is_wp_error($content)) {
            return $content;
        }

        return Content_Studio_Storage::save($content);
    }
}
