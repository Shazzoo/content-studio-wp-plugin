<?php

if (!defined('ABSPATH')) {
    exit;
}

$card = wp_parse_args(isset($card) && is_array($card) ? $card : [], [
    'article_classes' => 'content-studio-blog__article',
    'show_image' => true,
    'image_html' => '',
    'image_url' => '',
    'image_label' => '',
    'placeholder_text' => 'Content Studio',
    'show_title' => true,
    'title' => '',
    'title_url' => '',
    'title_disabled' => false,
    'show_meta' => true,
    'meta_items' => [],
    'show_excerpt' => true,
    'excerpt' => '',
]);

?>

<article class="<?php echo esc_attr($card['article_classes']); ?>">
    <?php if (!empty($card['show_image'])) : ?>
        <?php if ('' !== $card['image_html']) : ?>
            <a class="content-studio-blog__image-link" href="<?php echo esc_url($card['image_url']); ?>">
                <?php echo $card['image_html']; ?>
            </a>
        <?php else : ?>
            <a class="content-studio-blog__image-link content-studio-blog__image-placeholder" <?php echo $card['image_url'] ? 'href="' . esc_url($card['image_url']) . '"' : 'aria-disabled="true" tabindex="-1"'; ?> aria-label="<?php echo esc_attr($card['image_label']); ?>">
                <span><?php echo esc_html($card['placeholder_text']); ?></span>
            </a>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!empty($card['show_title'])) : ?>
        <h2 class="content-studio-blog__article-title">
            <a class="content-studio-blog__article-link" <?php echo !$card['title_disabled'] && $card['title_url'] ? 'href="' . esc_url($card['title_url']) . '"' : 'aria-disabled="true" tabindex="-1"'; ?>><?php echo esc_html($card['title']); ?></a>
        </h2>
    <?php endif; ?>

    <?php if (!empty($card['show_meta']) && !empty($card['meta_items'])) : ?>
        <div class="content-studio-blog__meta">
            <?php foreach ($card['meta_items'] as $meta_item) : ?>
                <span><?php echo esc_html($meta_item); ?></span>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($card['show_excerpt']) && '' !== trim((string) $card['excerpt'])) : ?>
        <div class="content-studio-blog__excerpt">
            <p><?php echo esc_html($card['excerpt']); ?></p>
        </div>
    <?php endif; ?>
</article>
