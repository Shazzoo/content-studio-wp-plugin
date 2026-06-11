<?php

if (!defined('ABSPATH')) {
    exit;
}

$article_card = plugin_dir_path(CONTENT_STUDIO_PLUGIN_FILE) . 'views/article-card.php';
$preview_card = [
    'article_classes' => 'content-studio-blog__article content-studio-settings-preview__card',
    'show_image' => true,
    'image_html' => '',
    'image_url' => '',
    'image_label' => 'Preview article',
    'show_title' => true,
    'title' => 'The title of you article will appear here',
    'title_url' => '',
    'title_disabled' => true,
    'show_meta' => true,
    'meta_items' => [date_i18n(get_option('date_format')), '4 min read'],
    'show_excerpt' => true,
    'excerpt' => 'A short preview of the generated article appears here, using the same colors as the blog card.',
];

?>

<aside class="content-studio-settings-preview" aria-label="Article card style preview">
    <div class="content-studio-settings-preview__sticky">
        <div class="content-studio-settings-preview__header">
            <h2>Live Preview</h2>
            <?php submit_button('Reset to Defaults', 'secondary content-studio-button content-studio-button--secondary', 'submit', false, ['form' => 'content-studio-reset-settings-form']); ?>
        </div>

        <div class="content-studio-blog content-studio-settings-preview__blog">
            <div class="content-studio-blog__grid">
                <?php
                $card = $preview_card;
                require $article_card;
                ?>
            </div>
        </div>
    </div>
</aside>
