<?php

class Content_Studio_REST_Routes
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes()
    {
        register_rest_route(
            'content-studio/v1',
            '/sync',
            [
                'methods' => 'POST',
                'callback' => [$this, 'sync_content'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]
        );

        register_rest_route(
            'content-studio/v1',
            '/webhook',
            [
                'methods' => 'POST',
                'callback' => [$this, 'engine_webhook'],
                /*
                 * Geen ingelogde gebruiker: dit is de engine, met een
                 * handtekening. Die handtekening is de toegang, en hij wordt
                 * in de callback zelf gecontroleerd voordat er iets gebeurt.
                 */
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function sync_content()
    {
        $saved = Content_Studio_Admin_Settings::run_sync();

        if (is_wp_error($saved)) {
            return $saved;
        }

        return rest_ensure_response([
            'success' => true,
            'saved' => $saved,
        ]);
    }

    /**
     * Waar de engine aanklopt.
     *
     * De aanroep draagt alleen een id: wat er in het artikel staat haalt deze
     * site zelf op, met zijn eigen sleutel. Wie de webhook onderschept weet
     * dus niets dat hij niet toch al kon opvragen.
     *
     * Het werk gebeurt hier meteen. Dat is één ophaal, één opslag en één
     * terugmelding, en het scheelt deze site de aanname dat WP-Cron op tijd
     * langskomt - juist het ding dat zonder bezoek stil blijft staan.
     *
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function engine_webhook($request)
    {
        if (!Content_Studio_Engine_Client::is_connected()) {
            return new WP_REST_Response(['ok' => false, 'message' => 'Deze site is niet aangemeld.'], 409);
        }

        $valid = Content_Studio_Webhook_Signature::matches(
            Content_Studio_Engine_Client::secret(),
            $request->get_header('x-content-studio-timestamp'),
            $request->get_body(),
            $request->get_header('x-content-studio-signature')
        );

        if (!$valid) {
            return new WP_REST_Response(['ok' => false], 401);
        }

        $event = (string) ($request->get_param('event') ?: $request->get_header('x-content-studio-event'));
        $article_id = (int) $request->get_param('article_id');

        switch ($event) {
            case 'article.publish':
                Content_Studio_Engine_Sync::one($article_id, 'published');
                break;
            case 'article.update':
                Content_Studio_Engine_Sync::one($article_id, 'updated');
                break;
            case 'sync.requested':
                // Een duwtje zonder inhoud: de site gaat zelf kijken wat hij
                // sinds zijn cursor heeft gemist.
                Content_Studio_Engine_Sync::catch_up();
                break;
        }

        return new WP_REST_Response(['ok' => true], 200);
    }
}
