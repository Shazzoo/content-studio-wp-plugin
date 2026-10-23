<?php

/**
 * Toont onder een artikel wie het schreef: naam, rol, een korte intro en waar
 * de kennis van de auteur ophoudt. De Engine zet dit niet meer in de tekst
 * van het artikel, juist zodat een site het zelf kan opmaken.
 *
 * Net als de hub-artikelen komt het via the_content achter de tekst. Zonder
 * auteursnaam, of zonder iets om over de auteur te vertellen, blijft het weg.
 */
class Content_Studio_Author_Box
{
    public function __construct()
    {
        add_filter('the_content', [self::class, 'append_author_box']);
    }

    public static function append_author_box($content)
    {
        if (!is_singular(content_studio_post_type()) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post_id = get_the_ID();

        if ($post_id !== get_queried_object_id()) {
            return $content;
        }

        $field = static function ($key) use ($post_id) {
            return trim((string) get_post_meta($post_id, '_content_studio_' . $key, true));
        };

        $name = $field('author_name');
        $intro = array_values(array_filter([$field('author_experience_summary'), $field('author_article_relevance')], 'strlen'));
        $boundary_note = $field('author_boundary_note');

        if ('' === $name || ([] === $intro && '' === $boundary_note)) {
            return $content;
        }

        ob_start();

        content_studio_load_view('author-box', [
            // In de taal van het artikel, niet die van de site.
            'title' => Content_Studio_Strings::get('about_the_author', Content_Studio_Language::for_post($post_id)),
            'author_name' => $name,
            'role' => implode(' · ', array_filter([$field('author_role_title'), $field('author_experience_label')], 'strlen')),
            'intro' => $intro,
            'boundary_note' => $boundary_note,
        ]);

        return $content . ob_get_clean();
    }
}
