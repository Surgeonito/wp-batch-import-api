<?php
/**
 * Plugin Name: WP Batch Export API
 * Description: bespoke export API
 * Version: 1.0.0
 * Author: MK
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Batch_Export_API {
    const NAMESPACE = 'content-migrate/v1';
    const ROUTE     = '/posts';

    private $api_token_option_key = 'wp_batch_export_api_token';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
        add_action('admin_init',    [$this, 'maybe_generate_token']);
        add_filter('rest_post_dispatch', [$this, 'disable_rest_cache_for_export'], 10, 3);
    }

    public function disable_rest_cache_for_export( $result, $server, $request ) {
        $route = $request->get_route(); // e.g. "/content-migrate/v1/posts"

        if ( strpos( $route, '/'. self::NAMESPACE . self::ROUTE ) !== false ) {
            $server->send_header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
            $server->send_header( 'Pragma', 'no-cache' );
            $server->send_header( 'Expires', '0' );
        }

        return $result;
    }

    public function maybe_generate_token() {
        // Generate token once if not exists
        if ( ! get_option($this->api_token_option_key) ) {
            $token = wp_generate_password(64, false, false);
            add_option($this->api_token_option_key, $token, false);
        }
    }

    public function get_api_token() {
        return get_option($this->api_token_option_key);
    }

    public function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            self::ROUTE,
            [
                'methods'             => 'GET',
                'callback'            => [$this, 'handle_get_posts'],
                'permission_callback' => [$this, 'auth_check'],
                'args'                => [
                    'count'  => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'default'           => 10,
                    ],
                    'offset' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                        'default'           => 0,
                    ],
                    'post_type' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'default'           => 'post',
                    ],
                    'status' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                        'default'           => 'publish',
                    ],
                ]
            ]
        );
    }

    /**
     * SECURITY
     * We'll require a header:
     * Authorization: Bearer {token}
     */
    public function auth_check( $request ) {
        $headers = $request->get_headers();
        $auth    = isset($headers['authorization'][0]) ? $headers['authorization'][0] : '';

        if ( stripos($auth, 'Bearer ') !== 0 ) {
            return new WP_Error('forbidden', 'Missing bearer token', ['status' => 403]);
        }

        $token = trim(substr($auth, 7));
        if ( ! hash_equals($this->get_api_token(), $token) ) {
            return new WP_Error('forbidden', 'Invalid token', ['status' => 403]);
        }

        return true;
    }

    public function filter_min_id( $where, $wp_query ) {
        if ( $wp_query->get('wp_batch_min_id') ) {
            global $wpdb;
            $min_id = intval( $wp_query->get('wp_batch_min_id') );
            if ( $min_id > 0 ) {
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $min_id);
            }
        }
        return $where;
    }


    public function handle_get_posts( WP_REST_Request $request ) {
        global $wpdb;

        $count     = (int) $request->get_param('count');
        $post_type = $request->get_param('post_type');
        $status    = $request->get_param('status');
        $startID   = (int) $request->get_param('startID');

        if ( $count <= 0 ) {
            $count = 10;
        }

        // Ensure sane values
        $post_type = $post_type ? sanitize_key( $post_type ) : 'post';
        $status    = $status    ? sanitize_key( $status )    : 'publish';

        nocache_headers();

        /**
         * STEP 1: get list of IDs via raw SQL (no WP_Query filters/plugins involved)
         */
        $sql = $wpdb->prepare(
            "
        SELECT ID
        FROM {$wpdb->posts}
        WHERE post_type = %s
          AND post_status = %s
          AND ID > %d
        ORDER BY ID ASC
        LIMIT %d
        ",
            $post_type,
            $status,
            $startID,
            $count
        );

        $ids = $wpdb->get_col( $sql ); // returns array of IDs

        if ( empty( $ids ) ) {
            return [
                'count'   => 0,
                'startID' => $startID,
                'total'   => 0,
                'records' => [],
                'ids'     => [],
                'sql'     => $sql,
            ];
        }

        /**
         * STEP 2: fetch posts by those IDs only
         *        suppress_filters => true stops other plugins from altering the query again
         */
        $query = new WP_Query( [
            'post_type'              => $post_type,
            'post_status'            => $status,
            'post__in'               => $ids,
            'orderby'                => 'post__in', // keep same order as SQL
            'posts_per_page'         => count( $ids ),
            'no_found_rows'          => true,
            'cache_results'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'suppress_filters'       => true,
            'ignore_sticky_posts'    => true,
        ] );

        $posts_data = [];

        foreach ( $query->posts as $post ) {
            $post_id = $post->ID;

            $data = [
                'ID'            => $post_id,
                'post_type'     => $post->post_type,
                'post_title'    => $post->post_title,
                'post_name'     => $post->post_name,
                'post_content'  => $post->post_content,
                'post_excerpt'  => $post->post_excerpt,
                'post_status'   => $post->post_status,
                'post_date'     => $post->post_date,
                'post_date_gmt' => $post->post_date_gmt,
                'post_author'   => $post->post_author,
                'menu_order'    => $post->menu_order,
                'comment_status'=> $post->comment_status,
                'ping_status'   => $post->ping_status,
                'meta'          => [],
                'taxonomies'    => [],
                'featured_image'=> null,
                'author'        => null,
            ];

            // author
            $author = get_userdata( $post->post_author );
            if ( $author ) {
                $first_name  = get_user_meta( $author->ID, 'first_name', true );
                $last_name   = get_user_meta( $author->ID, 'last_name', true );
                $description = get_user_meta( $author->ID, 'description', true );
                $nickname    = get_user_meta( $author->ID, 'nickname', true );

                $data['author'] = [
                    'ID'              => $author->ID,
                    'user_login'      => $author->user_login,
                    'display_name'    => $author->display_name,
                    'user_nicename'   => $author->user_nicename,
                    'user_email'      => $author->user_email,
                    'user_url'        => $author->user_url,
                    'user_registered' => $author->user_registered,
                    'roles'           => $author->roles,
                    'first_name'      => $first_name,
                    'last_name'       => $last_name,
                    'nickname'        => $nickname,
                    'description'     => $description,
                ];
            }

            // meta
            $raw_meta = get_post_meta( $post_id );
            foreach ( $raw_meta as $meta_key => $values ) {
                $data['meta'][ $meta_key ] = ( count( $values ) === 1 )
                    ? maybe_unserialize( $values[0] )
                    : array_map( 'maybe_unserialize', $values );
            }

            // taxonomies
            $taxes = get_object_taxonomies( $post->post_type, 'names' );
            foreach ( $taxes as $tax_name ) {
                $terms = wp_get_post_terms( $post_id, $tax_name );
                $data['taxonomies'][ $tax_name ] = [];
                foreach ( $terms as $t ) {
                    $data['taxonomies'][ $tax_name ][] = [
                        'term_id'     => $t->term_id,
                        'name'        => $t->name,
                        'slug'        => $t->slug,
                        'description' => $t->description,
                        'parent'      => $t->parent,
                    ];
                }
            }

            // featured image
            $thumb_id = get_post_thumbnail_id( $post_id );
            if ( $thumb_id ) {
                $thumb_url = wp_get_attachment_url( $thumb_id );
                $data['featured_image'] = [
                    'url'       => $thumb_url,
                    'alt'       => get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
                    'title'     => get_the_title( $thumb_id ),
                    'mime_type' => get_post_mime_type( $thumb_id ),
                ];
            }

            $posts_data[] = $data;
        }

        return [
            'count'   => count( $posts_data ),
            'startID' => $startID,
            'total'   => 0,           // you’re not computing totals in the raw SQL; set 0 or compute separately
            'records' => $posts_data,
            'ids'     => $ids,        // helpful for debugging
            'sql'     => $sql,        // helpful for debugging
        ];
    }

}

new WP_Batch_Export_API();
