<?php

/**
 * Zet de uitgelichte afbeelding boven de tekst van een artikel, voor thema's
 * die hem op een losse berichtpagina niet tonen, zoals Hello Elementor.
 *
 * Staat standaard uit: de meeste thema's tonen hem zelf, en dan zou hij er
 * twee keer staan.
 */
class Content_Studio_Featured_Image
{
    const OPTION = 'content_studio_show_featured_image';

    public function __construct()
    {
        add_filter('the_content', [self::class, 'prepend_featured_image']);
    }

    public static function enabled()
    {
        return (bool) get_option(self::OPTION, 0);
    }

    public static function prepend_featured_image($content)
    {
        if (!self::enabled() || !is_singular(content_studio_post_type()) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post_id = get_the_ID();

        if ($post_id !== get_queried_object_id() || !has_post_thumbnail($post_id)) {
            return $content;
        }

        $image = get_the_post_thumbnail($post_id, 'large', ['class' => 'content-studio-featured-image__image']);

        return '<figure class="content-studio-featured-image">' . $image . '</figure>' . $content;
    }
}
