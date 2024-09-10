<?php
/*
Plugin Name: WP Duplicate Content
Description: A plugin to duplicate custom post types, pages, and posts with support for Yoast SEO, ACF, and custom fields.
Version: 1.3 // Version number updated
Author: Gal Tibet
Text Domain: wp-duplicate-content
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// CHANGE: Restructured the entire plugin into a class for better organization and OOP approach
class WP_Duplicate_Content {
    public function __construct() {
        // CHANGE: Moved all action and filter hooks into the constructor
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_filter('post_row_actions', array($this, 'add_duplicate_link'), 10, 2);
        add_filter('page_row_actions', array($this, 'add_duplicate_link'), 10, 2);
        add_action('admin_action_duplicate_post', array($this, 'duplicate_post_as_draft'));
    }

    // CHANGE: Added new method to register plugin settings
    public function register_settings() {
        register_setting('wp_duplicate_content_options', 'wp_duplicate_content_post_types');
    }

    // CHANGE: Added new method to create a settings page in the WordPress admin
    public function add_settings_page() {
        add_options_page(
            'WP Duplicate Content Settings',
            'WP Duplicate Content',
            'manage_options',
            'wp-duplicate-content',
            array($this, 'render_settings_page')
        );
    }

    // CHANGE: Added new method to render the settings page
    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('wp_duplicate_content_options');
                do_settings_sections('wp-duplicate-content');
                ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Post Types to Enable</th>
                        <td>
                            <?php
                            // CHANGE: Added ability to select which post types to enable for duplication
                            $post_types = get_post_types(array('public' => true), 'objects');
                            $enabled_post_types = get_option('wp_duplicate_content_post_types', array('post', 'page'));
                            foreach ($post_types as $post_type) {
                                $checked = in_array($post_type->name, $enabled_post_types) ? 'checked' : '';
                                echo '<label><input type="checkbox" name="wp_duplicate_content_post_types[]" value="' . esc_attr($post_type->name) . '" ' . $checked . '> ' . esc_html($post_type->label) . '</label><br>';
                            }
                            ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // CHANGE: Updated method to consider enabled post types from settings
    public function add_duplicate_link($actions, $post) {
        $enabled_post_types = get_option('wp_duplicate_content_post_types', array('post', 'page'));
        if (in_array($post->post_type, $enabled_post_types) && current_user_can('edit_posts') && current_user_can('edit_post', $post->ID)) {
            $nonce = wp_create_nonce('duplicate_post_' . $post->ID);
            $url = admin_url('admin.php?action=duplicate_post&post=' . $post->ID . '&duplicate_nonce=' . $nonce);
            $actions['duplicate'] = '<a href="' . esc_url($url) . '" title="' . esc_attr__('Duplicate this item', 'wp-duplicate-content') . '" rel="permalink">' . __('Duplicate', 'wp-duplicate-content') . '</a>';
        }
        return $actions;
    }

    // CHANGE: Updated method with improved error handling and more comprehensive duplication
    public function duplicate_post_as_draft() {
        global $wpdb;

        if (!isset($_GET['post']) || !isset($_GET['duplicate_nonce'])) {
            wp_die(__('No post to duplicate has been supplied!', 'wp-duplicate-content'));
        }

        $post_id = absint($_GET['post']);
        $nonce = sanitize_text_field($_GET['duplicate_nonce']);

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'duplicate_post_' . $post_id)) {
            wp_die(__('Security check failed', 'wp-duplicate-content'));
        }

        $post = get_post($post_id);

        if (!$post || !current_user_can('edit_post', $post_id)) {
            wp_die(__('You do not have permission to duplicate this post.', 'wp-duplicate-content'));
        }

        $new_post_author = wp_get_current_user();

        // CHANGE: More comprehensive duplication of post properties
        $new_post = array(
            'post_author'    => $new_post_author->ID,
            'post_title'     => $post->post_title . ' ' . __('(Copy)', 'wp-duplicate-content'),
            'post_content'   => $post->post_content,
            'post_excerpt'   => $post->post_excerpt,
            'post_status'    => 'draft',
            'post_type'      => $post->post_type,
            'comment_status' => $post->comment_status,
            'ping_status'    => $post->ping_status,
            'post_password'  => $post->post_password,
            'post_parent'    => $post->post_parent,
            'menu_order'     => $post->menu_order,
        );

        $new_post_id = wp_insert_post($new_post);

        if (!is_wp_error($new_post_id)) {
            // CHANGE: Added separate methods for duplicating taxonomies and meta
            $this->duplicate_post_taxonomies($post_id, $new_post_id);
            $this->duplicate_post_meta($post_id, $new_post_id);

            // Log duplication action
            $this->log_duplication_action($post_id, $new_post_id);

            // Redirect to the new draft
            wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
            exit;
        } else {
            wp_die(__('Post creation failed, could not create a new post.', 'wp-duplicate-content'));
        }
    }

    // CHANGE: New method to duplicate post taxonomies
    private function duplicate_post_taxonomies($post_id, $new_post_id) {
        $taxonomies = get_object_taxonomies(get_post_type($post_id));
        foreach ($taxonomies as $taxonomy) {
            $post_terms = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'slugs'));
            wp_set_object_terms($new_post_id, $post_terms, $taxonomy, false);
        }
    }

    // CHANGE: Updated method for duplicating post meta with improved handling
    private function duplicate_post_meta($post_id, $new_post_id) {
        $post_meta = get_post_meta($post_id);
        if ($post_meta) {
            foreach ($post_meta as $meta_key => $meta_values) {
                if (in_array($meta_key, array('_wp_old_slug', '_edit_lock', '_edit_last'))) {
                    continue;
                }
                foreach ($meta_values as $meta_value) {
                    add_post_meta($new_post_id, $meta_key, maybe_unserialize($meta_value));
                }
            }
        }

        // Duplicate Yoast SEO meta data
        if (defined('WPSEO_VERSION')) {
            $yoast_meta_keys = array(
                '_yoast_wpseo_focuskw',
                '_yoast_wpseo_title',
                '_yoast_wpseo_metadesc',
                '_yoast_wpseo_linkdex',
                '_yoast_wpseo_metakeywords',
                '_yoast_wpseo_primary_category' // CHANGE: Added primary category meta
            );
            foreach ($yoast_meta_keys as $key) {
                $meta_value = get_post_meta($post_id, $key, true);
                if ($meta_value) {
                    update_post_meta($new_post_id, $key, $meta_value);
                }
            }
        }
    }

    // CHANGE: Updated logging method with more detailed information
    private function log_duplication_action($post_id, $new_post_id) {
        $user = wp_get_current_user();
        $log_message = sprintf(
            'Post ID %d was duplicated to new Post ID %d by User %s (ID: %d)',
            $post_id,
            $new_post_id,
            $user->user_login,
            $user->ID
        );
        error_log($log_message);
    }
}

// CHANGE: Instantiate the class instead of calling functions directly
$wp_duplicate_content = new WP_Duplicate_Content();
