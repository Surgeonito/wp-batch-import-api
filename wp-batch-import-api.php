<?php
/**
 * Plugin Name: WP Batch Importer
 * Description: bespoke import plugin
 * Version: 1.1.0
 * Author: MK
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Batch_Importer {

    private $option_group = 'wp_batch_importer_options';

    private $last_imported_id = 'last_imported_id';
    private $settings_slug = 'wp-batch-importer-settings';
    private $remote_url_option = 'wp_batch_remote_url'; // e.g. https://source-site.com/wp-json/content-migrate/v1/posts
    private $remote_token_option = 'wp_batch_remote_token';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_wp_batch_import', [$this, 'handle_import_submit']);
        add_action('wp_ajax_wp_batch_get_remote_post_types', [$this, 'ajax_get_remote_post_types']);
        // AJAX step endpoint for batch importing
        add_action('wp_ajax_wp_batch_import_step', [$this, 'ajax_import_step']);
    }

    public function add_admin_menu() {
        add_menu_page(
            'Batch Importer',
            'Batch Importer',
            'manage_options',
            $this->settings_slug,
            [$this, 'render_admin_page'],
            'dashicons-migrate',
            80
        );
    }

    public function register_settings() {
        // remote URL + token stored as options so you don't retype them
        register_setting($this->option_group, $this->remote_url_option, ['sanitize_callback' => 'esc_url_raw']);
        register_setting($this->option_group, $this->remote_token_option, ['sanitize_callback' => 'sanitize_text_field']);
    }

    public function render_admin_page() {
        if ( ! current_user_can('manage_options') ) return;

        $remote_url   = esc_attr( get_option($this->remote_url_option, '') );
        $remote_token = esc_attr( get_option($this->remote_token_option, '') );
        $last_ids = $this->get_last_imported_ids();
        $default_post_type = 'post';
        $lastID = $this->get_last_imported_id($default_post_type, $last_ids);

        // Read last run result (flash message)
        if ( isset($_GET['wp_batch_result']) ) {
            echo '<div class="notice notice-success"><p>' . esc_html($_GET['wp_batch_result']) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>Batch Importer</h1>

            <form method="post" action="options.php">
                <?php settings_fields( $this->option_group ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="<?php echo $this->remote_url_option; ?>">Source API URL</label></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo $this->remote_url_option; ?>" id="<?php echo $this->remote_url_option; ?>" value="<?php echo $remote_url; ?>" placeholder="https://example.com/wp-json/content-migrate/v1/posts" />
                            <p class="description">This is the endpoint on the source site, e.g. https://example.com/wp-json/content-migrate/v1/posts</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo $this->remote_token_option; ?>">Bearer Token</label></th>
                        <td>
                            <input type="text" class="regular-text" name="<?php echo $this->remote_token_option; ?>" id="<?php echo $this->remote_token_option; ?>" value="<?php echo $remote_token; ?>" />
                            <p class="description">Must match source site's generated token.</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Connection Settings'); ?>
            </form>

            <hr />

            <h2>Run Import</h2>
            <p>
                You can still run a single non-AJAX import (using "Count"), or use the AJAX batch options below:<br>
                Example: total <strong>100</strong> posts, <strong>5</strong> per batch = 20 AJAX requests.
            </p>

            <form method="post" action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" id="wp-batch-import-form">
                <?php wp_nonce_field('wp_batch_import_action', 'wp_batch_import_nonce'); ?>
                <input type="hidden" name="action" value="wp_batch_import" />

                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="count">Count (single-run posts_per_page)</label></th>
                        <td>
                            <input type="number" min="1" max="100" name="count" id="count" value="10" />
                            <p class="description">Used only for the classic single-run import (no AJAX).</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="total_count">Total posts to import (AJAX)</label></th>
                        <td>
                            <input type="number" min="1" max="10000" name="total_count" id="total_count" value="100" />
                            <p class="description">How many posts to import in total using the AJAX batch loop.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="batch_size">Batch size (per AJAX request)</label></th>
                        <td>
                            <input type="number" min="1" max="100" name="batch_size" id="batch_size" value="5" />
                            <p class="description">How many posts to import per AJAX step.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="startID">startID</label></th>
                        <td><input type="number" min="0" name="startID" id="startID" value="<?php echo $lastID;?>" /> <i>automatically filled with last imported ID</i></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="post_type">Post Type</label></th>
                        <td>
                            <select id="remote_post_type_select" style="min-width:220px;">
                                <option value="">Load post types from source...</option>
                            </select>
                            <span id="remote_post_type_status" class="description" style="margin-left:10px;"></span>
                            <p class="description" style="margin-top:6px;">Choose a post type from the source site or type your own below.</p>
                            <input type="text" name="post_type" id="post_type" value="post" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="status">Status</label></th>
                        <td><input type="text" name="status" id="status" value="publish" /></td>
                    </tr>
                </table>

                <?php submit_button('Import Now'); ?>
            </form>

            <div id="wp-batch-import-status" style="margin-top:15px; display:none; padding:10px; background:#fff; border:1px solid #ccd0d4;"></div>

            <script>
                jQuery(function($){
                    var $form = $('#wp-batch-import-form');
                    var $statusBox = $('#wp-batch-import-status');
                    var $postTypeSelect = $('#remote_post_type_select');
                    var $postTypeInput = $('#post_type');
                    var $postTypeStatus = $('#remote_post_type_status');
                    var lastImportedByPostType = <?php echo wp_json_encode($last_ids); ?>;
                    var nonce = $('#wp_batch_import_nonce').val();


                    function setStartIdForPostType(pt) {
                        var startVal = 0;
                        if (pt && lastImportedByPostType && lastImportedByPostType[pt]) {
                            startVal = parseInt(lastImportedByPostType[pt], 10) || 0;
                        }
                        $('#startID').val(startVal);
                    }

                    function fetchRemotePostTypes() {
                        var remoteUrl = $('#<?php echo $this->remote_url_option; ?>').val();
                        var remoteToken = $('#<?php echo $this->remote_token_option; ?>').val();

                        if (!remoteUrl || !remoteToken) {
                            $postTypeSelect.prop('disabled', true);
                            $postTypeStatus.text('Save connection settings to load source post types.');
                            return;
                        }

                        $postTypeSelect.prop('disabled', true).empty().append('<option>Loading...</option>');
                        $postTypeStatus.text('');

                        $.post(ajaxurl, {
                            action: 'wp_batch_get_remote_post_types',
                            wp_batch_import_nonce: nonce
                        }).done(function(resp){
                            $postTypeSelect.empty();
                            if (!resp || !resp.success || !resp.data || !resp.data.post_types) {
                                $postTypeStatus.text('Could not load post types from source site.');
                                $postTypeSelect.append('<option value="">Post types unavailable</option>');
                                return;
                            }

                            var postTypes = resp.data.post_types;
                            if (!postTypes.length) {
                                $postTypeStatus.text('No public post types returned by source site.');
                                $postTypeSelect.append('<option value="">Post types unavailable</option>');
                                return;
                            }

                            $postTypeSelect.append('<option value="">Select a source post type...</option>');
                            postTypes.forEach(function(pt){
                                var option = $('<option>');
                                option.val(pt.slug);
                                option.text(pt.label + ' (' + pt.slug + ')');
                                $postTypeSelect.append(option);
                            });
                            $postTypeSelect.prop('disabled', false);
                        }).fail(function(xhr){
                            $postTypeSelect.empty().append('<option value="">Post types unavailable</option>');
                            $postTypeStatus.text('Error loading post types: ' + xhr.status + ' ' + xhr.statusText);
                        });
                    }

                    $postTypeSelect.on('change', function(){
                        var selected = $(this).val();
                        if (selected) {
                            $postTypeInput.val(selected);
                        }
                        setStartIdForPostType(selected);
                    });

                    $postTypeInput.on('input', function(){
                        var manual = $(this).val();
                        setStartIdForPostType(manual);
                    });

                    if (!$form.length) return;

                    fetchRemotePostTypes();
                    setStartIdForPostType($postTypeInput.val());

                    $form.on('submit', function(e){
                        // If JS is disabled, this won't run and classic submit will work.
                        e.preventDefault();

                        var total = parseInt($('#total_count').val(), 10) || 0;
                        var batchSize = parseInt($('#batch_size').val(), 10) || 0;
                        var startID = parseInt($('#startID').val(), 10) || 0;
                        var postType = $postTypeInput.val();
                        var status = $('#status').val();
                        var nonce = $('#wp_batch_import_nonce').val();

                        if (!total || !batchSize) {
                            alert('Please set both "Total posts to import" and "Batch size".');
                            return;
                        }

                        var remaining = total;
                        var importedTotal = 0;

                        $statusBox.show().text('Starting import...');

                        function runBatch() {
                            if (remaining <= 0) {
                                $statusBox.text('Finished. Imported ' + importedTotal + ' posts.');
                                return;
                            }

                            $.post(ajaxurl, {
                                action: 'wp_batch_import_step',
                                wp_batch_import_nonce: nonce,
                                batch_size: batchSize,
                                startID: startID,
                                post_type: postType,
                                status: status
                            }).done(function(resp){
                                if (!resp || !resp.success) {
                                    var msg = resp && resp.data ? resp.data : 'Unknown error';
                                    $statusBox.text('Error: ' + msg);
                                    return;
                                }

                                var data = resp.data || {};
                                var imported = parseInt(data.imported, 10) || 0;
                                var lastID = parseInt(data.lastID, 10) || startID;
                                var done = !!data.done;

                                importedTotal += imported;
                                remaining -= imported;
                                startID = lastID;
                                lastImportedByPostType[postType] = startID;
                                $('#startID').val(startID);

                                $statusBox.text('Imported ' + importedTotal + ' posts so far... (last source ID: ' + lastID + ')');

                                if (done || remaining <= 0) {
                                    $statusBox.text('Finished. Imported ' + importedTotal + ' posts.');
                                } else {
                                    runBatch();
                                }
                            }).fail(function(xhr){
                                $statusBox.text('AJAX error: ' + xhr.status + ' ' + xhr.statusText);
                            });
                        }

                        runBatch();
                    });
                });
            </script>
        </div>
        <?php
    }

    /**
     * Classic single-run import (no AJAX) – still here as a fallback.
     */
    public function handle_import_submit() {
        if ( ! current_user_can('manage_options') ) {
            wp_die('Not allowed');
        }

        check_admin_referer('wp_batch_import_action','wp_batch_import_nonce');

        $remote_url   = get_option($this->remote_url_option, '');
        $remote_token = get_option($this->remote_token_option, '');

        $count     = isset($_POST['count']) ? absint($_POST['count']) : 10;
        $startID   = isset($_POST['startID']) ? absint($_POST['startID']) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'post';
        $status    = isset($_POST['status']) ? sanitize_key($_POST['status']) : 'publish';

        if ( empty($remote_url) || empty($remote_token) ) {
            wp_die('Remote URL or token not configured.');
        }

        // Build query string for pagination
        $request_url = add_query_arg([
            'count'     => $count,
            'startID'   => $startID,
            'post_type' => $post_type,
            'status'    => $status,
        ], $remote_url);


        $response = wp_remote_get( $request_url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $remote_token,
            ],
        ]);

        if ( is_wp_error($response) ) {
            wp_die('Remote request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ( $code !== 200 ) {
            wp_die('Remote request HTTP ' . $code . ' Body:' . esc_html($body));
        }

        $json = json_decode($body, true);

        if ( ! $json || ! isset($json['records']) ) {
            wp_die('Invalid JSON from remote.');
        }

        $imported = 0;
        foreach ( $json['records'] as $record ) {
            $new_post_id = $this->import_single_post($record);
            if ( $new_post_id ) {
                $imported++;
            }
        }

        // redirect back with result notice
        $redirect_url = add_query_arg([
            'page'            => $this->settings_slug,
            'wp_batch_result' => rawurlencode("Imported $imported posts."),
        ], admin_url('admin.php'));

        wp_safe_redirect( $redirect_url );
        exit;
    }

    /**
     * AJAX handler: import a single batch of posts.
     * Returns JSON: { imported, lastID, done }
     */
    public function ajax_import_step() {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('Not allowed', 403);
        }

        check_ajax_referer('wp_batch_import_action','wp_batch_import_nonce');

        $remote_url   = get_option($this->remote_url_option, '');
        $remote_token = get_option($this->remote_token_option, '');

        if ( empty($remote_url) || empty($remote_token) ) {
            wp_send_json_error('Remote URL or token not configured.');
        }

        $batch_size = isset($_POST['batch_size']) ? absint($_POST['batch_size']) : 5;
        if ( $batch_size < 1 ) $batch_size = 1;

        $startID   = isset($_POST['startID']) ? absint($_POST['startID']) : 0;
        $post_type = isset($_POST['post_type']) ? sanitize_key($_POST['post_type']) : 'post';
        $status    = isset($_POST['status']) ? sanitize_key($_POST['status']) : 'publish';

        // Build query string for this batch
        $request_url = add_query_arg([
            'count'     => $batch_size,
            'startID'   => $startID,
            'post_type' => $post_type,
            'status'    => $status,
        ], $remote_url);

        $response = wp_remote_get( $request_url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $remote_token,
            ],
        ]);

        if ( is_wp_error($response) ) {
            wp_send_json_error('Remote request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ( $code !== 200 ) {
            wp_send_json_error('Remote request HTTP ' . $code . ' Body:' . $body);
        }

        $json = json_decode($body, true);
        if ( ! $json || ! isset($json['records']) || ! is_array($json['records']) ) {
            wp_send_json_error('Invalid JSON from remote.');
        }

        $imported = 0;
        $lastID   = $startID;

        foreach ( $json['records'] as $record ) {
            $new_post_id = $this->import_single_post($record);
            if ( $new_post_id ) {
                $imported++;
            }

            // Update lastID to the source post ID if present
            if ( isset($record['ID']) ) {
                $source_id = (int) $record['ID'];
                if ( $source_id > $lastID ) {
                    $lastID = $source_id;
                }
            }
        }
        $this->set_last_imported_id($post_type, $lastID);

        // done = no more records returned
        $done = ( $imported === 0 || empty($json['records']) );

        wp_send_json_success([
            'imported' => $imported,
            'lastID'   => $lastID,
            'done'     => $done,
        ]);
    }

    private function get_last_imported_ids() {
        $stored = get_option($this->last_imported_id, []);

        if ( ! is_array($stored) ) {
            // Backwards compatibility with the previous single-value storage.
            $stored = [
                'post' => (int) $stored,
            ];
        }

        foreach ( $stored as $type => $value ) {
            $stored[ $type ] = (int) $value;
        }

        return $stored;
    }

    private function get_last_imported_id( $post_type, $last_ids = null ) {
        if ( null === $last_ids ) {
            $last_ids = $this->get_last_imported_ids();
        }

        $post_type = sanitize_key( $post_type );

        return isset( $last_ids[ $post_type ] ) ? (int) $last_ids[ $post_type ] : 0;
    }

    private function set_last_imported_id( $post_type, $last_id ) {
        $post_type = sanitize_key( $post_type );

        $last_ids = $this->get_last_imported_ids();
        $last_ids[ $post_type ] = (int) $last_id;

        update_option( $this->last_imported_id, $last_ids );
    }

    /**
     * Fetch a list of post types from the remote export plugin for UI selection.
     */
    public function ajax_get_remote_post_types() {
        if ( ! current_user_can('manage_options') ) {
            wp_send_json_error('Not allowed', 403);
        }

        check_ajax_referer('wp_batch_import_action','wp_batch_import_nonce');

        $remote_url   = get_option($this->remote_url_option, '');
        $remote_token = get_option($this->remote_token_option, '');

        if ( empty($remote_url) || empty($remote_token) ) {
            wp_send_json_error('Remote URL or token not configured.');
        }

        $post_types_endpoint = $this->build_post_types_endpoint( $remote_url );

        $response = wp_remote_get( $post_types_endpoint, [
            'timeout' => 20,
            'headers' => [
                'Authorization' => 'Bearer ' . $remote_token,
            ],
        ]);

        if ( is_wp_error($response) ) {
            wp_send_json_error('Remote request failed: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ( $code !== 200 ) {
            wp_send_json_error('Remote request HTTP ' . $code . ' Body:' . $body);
        }

        $json = json_decode($body, true);
        if ( ! $json || ! isset($json['post_types']) || ! is_array($json['post_types']) ) {
            wp_send_json_error('Invalid JSON from remote.');
        }

        wp_send_json_success([
            'post_types' => $json['post_types'],
        ]);
    }

    /**
     * Create the post locally, set meta, terms, featured image.
     */
    private function import_single_post( $record ) {

        // Resolve / create author from exported data.
        $author_id = 0;
        if ( ! empty( $record['author'] ) && is_array( $record['author'] ) ) {
            $author_id = $this->get_or_create_author( $record['author'] );
        }
        if ( ! $author_id ) {
            $author_id = get_current_user_id(); // fallback
        }

        // 1. Create/insert the post
        $postarr = [
            'post_type'      => $record['post_type'],
            'post_title'     => $record['post_title'],
            'post_name'      => $record['post_name'],
            'post_content'   => $record['post_content'],
            'post_excerpt'   => $record['post_excerpt'],
            'post_status'    => $record['post_status'],
            'post_date'      => $record['post_date'],
            'post_date_gmt'  => $record['post_date_gmt'],
            'menu_order'     => $record['menu_order'],
            'comment_status' => $record['comment_status'],
            'ping_status'    => $record['ping_status'],
            'post_author'    => $author_id,
        ];

        // Try to avoid duplicates:
        // Strategy: look for same post_name AND same post_date_gmt AND same post_type.
        $existing = get_posts([
            'name'        => $record['post_name'],
            'post_type'   => $record['post_type'],
            'post_status' => 'any',
            'numberposts' => 1,
            'date_query'  => [
                [
                    'column'    => 'post_date_gmt',
                    'after'     => $record['post_date_gmt'],
                    'before'    => $record['post_date_gmt'],
                    'inclusive' => true,
                ]
            ]
        ]);

        if ( $existing ) {
            $new_post_id = $existing[0]->ID;
            // Update instead of insert
            $postarr['ID'] = $new_post_id;
            wp_update_post($postarr);
        } else {
            $new_post_id = wp_insert_post($postarr);
        }

        if ( is_wp_error($new_post_id) || ! $new_post_id ) {
            return 0;
        }

        // 2. Import taxonomies (create terms if missing)
        if ( ! empty($record['taxonomies']) && is_array($record['taxonomies']) ) {
            foreach ( $record['taxonomies'] as $tax_name => $terms ) {
                if ( ! taxonomy_exists($tax_name) ) {
                    continue; // skip unknown taxonomies in destination
                }

                $term_ids = [];
                foreach ($terms as $t) {
                    // Try to get by slug first
                    $term_obj = get_term_by('slug', $t['slug'], $tax_name);

                    if ( ! $term_obj ) {
                        // create term
                        $args = [
                            'description' => $t['description'],
                            'slug'        => $t['slug'],
                        ];
                        $inserted = wp_insert_term( $t['name'], $tax_name, $args );
                        if ( ! is_wp_error($inserted) ) {
                            $term_obj = get_term_by('id', $inserted['term_id'], $tax_name);
                        }
                    }

                    if ( $term_obj && ! is_wp_error($term_obj) ) {
                        $term_ids[] = intval($term_obj->term_id);
                    }
                }

                if ( $term_ids ) {
                    wp_set_post_terms($new_post_id, $term_ids, $tax_name, false);
                }
            }
        }

        // 3. Import meta
        if ( ! empty($record['meta']) && is_array($record['meta']) ) {
            foreach ($record['meta'] as $meta_key => $meta_val) {

                if ( in_array($meta_key, ['_edit_lock','_edit_last'], true) ) {
                    continue;
                }

                // delete first to avoid duplicates
                delete_post_meta($new_post_id, $meta_key);

                if ( is_array($meta_val) && $this->is_assoc($meta_val) === false ) {
                    // array of values
                    foreach ($meta_val as $single_val) {
                        add_post_meta($new_post_id, $meta_key, $single_val);
                    }
                } else {
                    // single value or associative array
                    update_post_meta($new_post_id, $meta_key, $meta_val);
                }
            }
        }
        // 3b. Import ACF fields (including file/image downloads when possible)
        if ( ! empty( $record['acf'] ) && is_array( $record['acf'] ) ) {
            $this->import_acf_fields( $new_post_id, $record['acf'] );
        }


        // 4. Featured image (we'll sideload from URL if present)
        if ( ! empty($record['featured_image']['url']) ) {
            $thumb_id = $this->sideload_image_as_attachment(
                $record['featured_image']['url'],
                $new_post_id,
                $record['featured_image']['alt']
            );

            if ( $thumb_id ) {
                set_post_thumbnail($new_post_id, $thumb_id);
            }
        }

        return $new_post_id;
    }

    private function is_assoc( $arr ) {
        if ( array() === $arr ) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    /**
     * Download remote image and attach to post.
     */
    private function sideload_image_as_attachment( $image_url, $parent_post_id, $alt = '' ) {

        if ( ! function_exists('media_sideload_image') ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        // media_sideload_image returns HTML <img> or WP_Error, not attachment ID.
        $tmp = media_sideload_image( $image_url, $parent_post_id, null, 'id' );

        if ( is_wp_error($tmp) ) {
            return 0;
        }

        $attachment_id = $tmp;

        if ( $alt ) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt));
        }

        return $attachment_id;
    }

    /**
     * Import ACF fields from exported payload.
     *
     * @param int   $post_id
     * @param array $acf_payload
     */
    private function import_acf_fields( $post_id, $acf_payload ) {
        if ( empty( $acf_payload['fields'] ) || ! is_array( $acf_payload['fields'] ) ) {
            return;
        }

        foreach ( $acf_payload['fields'] as $field_name => $field_data ) {
            if ( ! is_array( $field_data ) ) {
                continue;
            }

            $field_key = $field_data['key'] ?? '';
            $field_type = $field_data['type'] ?? '';
            $return_format = $field_data['return_format'] ?? '';
            $value = $field_data['value'] ?? null;

            if ( in_array( $field_type, [ 'image', 'file' ], true ) ) {
                $value = $this->prepare_acf_attachment_value_for_import( $value, $post_id, $field_type, $return_format );
            } elseif ( 'gallery' === $field_type ) {
                $value = $this->prepare_acf_gallery_value_for_import( $value, $post_id, $return_format );
            }

            if ( function_exists( 'update_field' ) ) {
                $field_identifier = $field_key ? $field_key : $field_name;
                update_field( $field_identifier, $value, $post_id );
            } else {
                update_post_meta( $post_id, $field_name, $value );
                if ( $field_key ) {
                    update_post_meta( $post_id, '_' . $field_name, $field_key );
                }
            }
        }
    }

    private function prepare_acf_attachment_value_for_import( $value, $post_id, $field_type, $return_format ) {
        $attachment_id = $this->resolve_acf_attachment_id( $value, $post_id, $field_type );
        if ( ! $attachment_id ) {
            return $value;
        }

        if ( 'url' === $return_format ) {
            return wp_get_attachment_url( $attachment_id );
        }

        if ( 'array' === $return_format ) {
            return $this->build_acf_attachment_array( $attachment_id, $field_type );
        }

        return $attachment_id;
    }

    private function prepare_acf_gallery_value_for_import( $value, $post_id, $return_format ) {
        if ( empty( $value ) ) {
            return $value;
        }

        $items = is_array( $value ) ? $value : [];
        $attachment_ids = [];
        foreach ( $items as $item ) {
            $attachment_id = $this->resolve_acf_attachment_id( $item, $post_id, 'image' );
            if ( $attachment_id ) {
                $attachment_ids[] = $attachment_id;
            }
        }

        if ( 'url' === $return_format ) {
            return array_map( 'wp_get_attachment_url', $attachment_ids );
        }

        if ( 'array' === $return_format ) {
            return array_map( function( $attachment_id ) {
                return $this->build_acf_attachment_array( $attachment_id, 'image' );
            }, $attachment_ids );
        }

        return $attachment_ids;
    }

    private function resolve_acf_attachment_id( $value, $post_id, $field_type ) {
        if ( empty( $value ) ) {
            return 0;
        }

        if ( is_numeric( $value ) ) {
            $attachment_id = (int) $value;
            return $this->attachment_exists( $attachment_id ) ? $attachment_id : 0;
        }

        if ( is_array( $value ) ) {
            $candidate_id = 0;
            if ( ! empty( $value['id'] ) ) {
                $candidate_id = (int) $value['id'];
            }
            if ( ! empty( $value['ID'] ) ) {
                $candidate_id = (int) $value['ID'];
            }

            if ( $candidate_id && $this->attachment_exists( $candidate_id ) ) {
                return $candidate_id;
            }

            if ( ! empty( $value['url'] ) ) {
                return $this->sideload_attachment_from_url( $value['url'], $post_id, $field_type, $value['alt'] ?? '' );
            }
        }

        if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
            return $this->sideload_attachment_from_url( $value, $post_id, $field_type );
        }

        return 0;
    }
    private function attachment_exists( $attachment_id ) {
        $attachment = get_post( $attachment_id );

        return $attachment && 'attachment' === $attachment->post_type;
    }


    private function build_acf_attachment_array( $attachment_id, $field_type ) {
        if ( ! $attachment_id ) {
            return [];
        }

        $url = wp_get_attachment_url( $attachment_id );

        $data = [
            'ID'       => $attachment_id,
            'id'       => $attachment_id,
            'url'      => $url,
            'title'    => get_the_title( $attachment_id ),
            'mime_type'=> get_post_mime_type( $attachment_id ),
        ];

        if ( 'image' === $field_type ) {
            $data['alt'] = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        }

        return $data;
    }

    private function sideload_attachment_from_url( $url, $parent_post_id, $field_type, $alt = '' ) {
        if ( 'file' === $field_type ) {
            return $this->sideload_file_as_attachment( $url, $parent_post_id );
        }

        return $this->sideload_image_as_attachment( $url, $parent_post_id, $alt );
    }

    /**
     * Download remote file (non-image) and attach to post.
     */
    private function sideload_file_as_attachment( $file_url, $parent_post_id ) {
        if ( ! function_exists('download_url') ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        $tmp = download_url( $file_url );
        if ( is_wp_error( $tmp ) ) {
            return 0;
        }

        $file_array = [
            'name'     => basename( parse_url( $file_url, PHP_URL_PATH ) ),
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload( $file_array, $parent_post_id );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp );
            return 0;
        }

        return $attachment_id;
    }



    /**
     * Get or create an author user based on exported author data.
     *
     * @param array $author_record
     * @return int user ID or 0 on failure
     */
    private function get_or_create_author( $author_record ) {
        if ( empty($author_record) || ! is_array($author_record) ) {
            return 0;
        }

        $email = ! empty($author_record['user_email']) ? sanitize_email($author_record['user_email']) : '';

        // 1. Try by email
        if ( $email && is_email($email) ) {
            $user = get_user_by('email', $email);
            if ( $user ) {
                return (int) $user->ID;
            }
        }

        // 2. Try by login
        if ( ! empty($author_record['user_login']) ) {
            $login = sanitize_user( $author_record['user_login'], true );
            $user = get_user_by('login', $login);
            if ( $user ) {
                return (int) $user->ID;
            }
        }

        // 3. Try by nicename / slug
        if ( ! empty($author_record['user_nicename']) ) {
            $nicename = sanitize_title( $author_record['user_nicename'] );
            $user = get_user_by('slug', $nicename);
            if ( $user ) {
                return (int) $user->ID;
            }
        }

        // 4. Create a new user
        if ( ! empty($author_record['user_login']) ) {
            $base_login = sanitize_user( $author_record['user_login'], true );
        } elseif ( $email ) {
            $parts = explode('@', $email);
            $base_login = sanitize_user( $parts[0], true );
        } elseif ( ! empty($author_record['display_name']) ) {
            $base_login = sanitize_user( $author_record['display_name'], true );
        } else {
            $base_login = 'imported_author';
        }

        if ( ! $base_login ) {
            $base_login = 'imported_author';
        }

        $login = $base_login;
        $i = 1;
        while ( username_exists( $login ) ) {
            $login = $base_login . '_' . $i;
            $i++;
        }

        $userdata = [
            'user_login'   => $login,
            'user_pass'    => wp_generate_password( 24 ),
            'user_email'   => $email,
            'display_name' => ! empty($author_record['display_name']) ? $author_record['display_name'] : $login,
            'user_nicename'=> ! empty($author_record['user_nicename']) ? sanitize_title( $author_record['user_nicename'] ) : '',
            'user_url'     => ! empty($author_record['user_url']) ? esc_url_raw( $author_record['user_url'] ) : '',
        ];

        if ( ! empty($author_record['roles']) && is_array($author_record['roles']) && ! empty($author_record['roles'][0]) ) {
            $userdata['role'] = sanitize_key( $author_record['roles'][0] );
        }

        if ( ! empty($author_record['user_registered']) ) {
            $userdata['user_registered'] = $author_record['user_registered'];
        }

        $new_user_id = wp_insert_user( $userdata );
        if ( is_wp_error($new_user_id) ) {
            return 0;
        }

        // Set basic meta
        if ( ! empty($author_record['first_name']) ) {
            update_user_meta( $new_user_id, 'first_name', sanitize_text_field( $author_record['first_name'] ) );
        }
        if ( ! empty($author_record['last_name']) ) {
            update_user_meta( $new_user_id, 'last_name', sanitize_text_field( $author_record['last_name'] ) );
        }
        if ( ! empty($author_record['nickname']) ) {
            update_user_meta( $new_user_id, 'nickname', sanitize_text_field( $author_record['nickname'] ) );
        }
        if ( ! empty($author_record['description']) ) {
            update_user_meta( $new_user_id, 'description', wp_kses_post( $author_record['description'] ) );
        }

        return (int) $new_user_id;
    }
    /**
     * Convert the configured posts endpoint URL into the sibling post types endpoint.
     */
    private function build_post_types_endpoint( $remote_posts_url ) {
        $remote_posts_url = strtok( $remote_posts_url, '?' );
        $remote_posts_url = untrailingslashit( $remote_posts_url );

        $base = trailingslashit( dirname( $remote_posts_url ) );

        return $base . 'post-types';
    }

}

new WP_Batch_Importer();
