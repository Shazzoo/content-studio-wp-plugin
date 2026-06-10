<?php

if (!defined('ABSPATH')) {
    exit;
}

$dashboard_card = plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'views/dashboard-card.php';
$notice = isset($notice) && is_array($notice) ? $notice : null;

function content_studio_render_settings_section($page, $section_id)
{
    global $wp_settings_sections;

    if (!isset($wp_settings_sections[$page][$section_id])) {
        return;
    }

    $section = $wp_settings_sections[$page][$section_id];

    if ($section['title']) {
        echo '<h2>' . esc_html($section['title']) . '</h2>';
    }

    if ($section['callback']) {
        call_user_func($section['callback'], $section);
    }

    echo '<table class="form-table" role="presentation">';
    do_settings_fields($page, $section_id);
    echo '</table>';
}

?>

<?php if ($notice) : ?>
    <div class="notice notice-<?php echo esc_attr($notice['type']); ?>">
        <p><?php echo esc_html($notice['message']); ?></p>
    </div>
<?php endif; ?>

<div class="wrap">
    <h1>Content Studio</h1>

    <div style="margin: 20px 0 28px;">
        <?php require $dashboard_card; ?>
    </div>

    <form action="options.php" method="post" class="content-studio-settings-form">
        <?php
        settings_fields('content_studio_settings');
        content_studio_render_settings_section('content-studio', 'content_studio_api_section');
        ?>

        <div class="content-studio-settings-layout">
            <div class="content-studio-settings-style-fields">
                <?php content_studio_render_settings_section('content-studio', 'content_studio_style_section'); ?>
                <?php submit_button('Save Settings', 'primary content-studio-button'); ?>
            </div>

            <aside class="content-studio-settings-preview" aria-label="Article card style preview">
                <div class="content-studio-settings-preview__sticky">
                    <h2>Live Preview</h2>
                    <p class="description">Shows how an article card can look on /blog. Theme colors are approximated in this preview.</p>

                    <div class="content-studio-blog content-studio-settings-preview__blog">
                        <div class="content-studio-blog__grid">
                        <article class="content-studio-blog__article content-studio-settings-preview__card">
                            <a class="content-studio-blog__image-link content-studio-blog__image-placeholder" aria-label="Preview article" aria-disabled="true" tabindex="-1">
                                <span>Content Studio</span>
                            </a>

                            <h2 class="content-studio-blog__article-title">
                                <a class="content-studio-blog__article-link" aria-disabled="true" tabindex="-1">How teams turn ideas into better articles</a>
                            </h2>

                            <div class="content-studio-blog__meta">
                                <span><?php echo esc_html(date_i18n(get_option('date_format'))); ?></span>
                                <span>4 min read</span>
                            </div>

                            <div class="content-studio-blog__excerpt">
                                <p>A short preview of the generated article appears here, using the same colors as the blog card.</p>
                            </div>
                        </article>

                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </form>
    <hr />
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var button = document.querySelector('.content-studio-toggle-api-key');
        var input = document.getElementById('content_studio_api_key');
        var preview = document.querySelector('.content-studio-settings-preview__blog');
        var advancedSpacingButton = document.querySelector('.content-studio-advanced-spacing-toggle');

        function getInternalVariable(cssVariable) {
            return cssVariable.replace('--content-studio-', '--_content-studio-');
        }

        function updatePreviewField(field) {
            if (!preview || !field) {
                return;
            }

            var picker = field.querySelector('.content-studio-color-picker');
            var useTheme = field.querySelector('input[type="checkbox"]');
            var cssVariable = field.getAttribute('data-css-variable');
            var fallbackColor = field.getAttribute('data-fallback-color');
            var color = picker ? picker.value : '';

            if (!cssVariable) {
                return;
            }

            if (useTheme && useTheme.checked) {
                if (fallbackColor) {
                    preview.style.setProperty(cssVariable, fallbackColor);
                    preview.style.setProperty(getInternalVariable(cssVariable), fallbackColor);
                } else {
                    preview.style.removeProperty(cssVariable);
                    preview.style.removeProperty(getInternalVariable(cssVariable));
                }
                return;
            }

            color = color || fallbackColor;

            if (!color) {
                preview.style.removeProperty(cssVariable);
                preview.style.removeProperty(getInternalVariable(cssVariable));
                return;
            }

            preview.style.setProperty(cssVariable, color);
            preview.style.setProperty(getInternalVariable(cssVariable), color);

            if (cssVariable === '--content-studio-color-primary-hover') {
                preview.style.setProperty('--content-studio-color-article-title-hover', color);
            }
        }

        function updatePreviewRange(field) {
            if (!preview || !field) {
                return;
            }

            var range = field.querySelector('input[type="range"]');
            var output = field.querySelector('output');
            var cssVariable = field.getAttribute('data-css-variable');
            var unit = field.getAttribute('data-unit') || 'px';
            var defaultValue = field.getAttribute('data-default-value');
            var row = field.closest('tr');
            var min = parseFloat(range.min || 0);
            var max = parseFloat(range.max || 100);
            var value = parseFloat(range.value || 0);
            var progress = max > min ? ((value - min) / (max - min)) * 100 : 0;

            if (!range || !cssVariable) {
                return;
            }

            range.style.setProperty('--content-studio-range-progress', progress + '%');

            if (row && row.classList.contains('content-studio-advanced-spacing-row') && !row.classList.contains('is-visible') && range.value === defaultValue) {
                preview.style.removeProperty(cssVariable);
                preview.style.removeProperty(getInternalVariable(cssVariable));
                return;
            }

            preview.style.setProperty(cssVariable, range.value + unit);
            preview.style.setProperty(getInternalVariable(cssVariable), range.value + unit);

            if (output) {
                output.textContent = range.value + unit;
            }
        }

        function updatePreview() {
            document.querySelectorAll('.content-studio-style-field').forEach(updatePreviewField);
            document.querySelectorAll('.content-studio-range-field').forEach(updatePreviewRange);
        }

        if (window.jQuery && jQuery.fn.wpColorPicker) {
            jQuery('.content-studio-color-picker').wpColorPicker({
                change: function(event, ui) {
                    var picker = event.target;
                    var field = picker.closest('.content-studio-style-field');

                    picker.value = ui.color ? ui.color.toString() : '';
                    updatePreviewField(field);
                },
                clear: function(event) {
                    var field = event.target.closest('.content-studio-style-field');

                    window.setTimeout(function() {
                        updatePreviewField(field);
                    }, 0);
                }
            });
        }

        document.querySelectorAll('.content-studio-style-field input[type="checkbox"], .content-studio-color-picker').forEach(function(control) {
            control.addEventListener('input', updatePreview);
            control.addEventListener('change', updatePreview);
        });

        document.querySelectorAll('.content-studio-range-field input[type="range"]').forEach(function(control) {
            control.addEventListener('input', updatePreview);
            control.addEventListener('change', updatePreview);
        });

        if (advancedSpacingButton) {
            advancedSpacingButton.addEventListener('click', function() {
                var rows = document.querySelectorAll('.content-studio-advanced-spacing-row');
                var isExpanded = advancedSpacingButton.getAttribute('aria-expanded') === 'true';

                rows.forEach(function(row) {
                    row.classList.toggle('is-visible', !isExpanded);
                });

                advancedSpacingButton.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                advancedSpacingButton.textContent = isExpanded ? 'Advanced spacing' : 'Hide advanced spacing';
                updatePreview();
            });
        }

        if (button && input) {
            button.addEventListener('click', function() {
                var isVisible = input.type === 'text';
                var icon = button.querySelector('.dashicons');

                input.type = isVisible ? 'password' : 'text';
                button.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
                button.setAttribute('aria-label', isVisible ? 'Show API key' : 'Hide API key');

                if (icon) {
                    icon.className = isVisible ? 'dashicons dashicons-visibility' : 'dashicons dashicons-hidden';
                }
            });
        }

        updatePreview();
    });
</script>
