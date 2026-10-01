<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Paginanummers onder de blog.
 *
 * @var array $pagination current, total, previous_url, next_url en pages:
 *                        per pagina number, url en current; null is een gat.
 */

?>
<nav class="content-studio-pagination" aria-label="<?php echo esc_attr(Content_Studio_Strings::get('pagination')); ?>">
    <?php if ('' !== $pagination['previous_url']) : ?>
        <a class="content-studio-pagination__link content-studio-pagination__previous" href="<?php echo esc_url($pagination['previous_url']); ?>" rel="prev" aria-label="<?php echo esc_attr(Content_Studio_Strings::get('previous_page')); ?>">&larr;</a>
    <?php endif; ?>

    <?php foreach ($pagination['pages'] as $page) : ?>
        <?php if (null === $page) : ?>
            <span class="content-studio-pagination__gap" aria-hidden="true">&hellip;</span>
        <?php elseif ($page['current']) : ?>
            <span class="content-studio-pagination__link is-current" aria-current="page"><?php echo esc_html($page['number']); ?></span>
        <?php else : ?>
            <a class="content-studio-pagination__link" href="<?php echo esc_url($page['url']); ?>"><?php echo esc_html($page['number']); ?></a>
        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ('' !== $pagination['next_url']) : ?>
        <a class="content-studio-pagination__link content-studio-pagination__next" href="<?php echo esc_url($pagination['next_url']); ?>" rel="next" aria-label="<?php echo esc_attr(Content_Studio_Strings::get('next_page')); ?>">&rarr;</a>
    <?php endif; ?>
</nav>
