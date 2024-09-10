<?php
/*
Plugin Name: WP Duplicate Content
Description: A plugin to duplicate custom post types, pages, and posts with support for Yoast SEO, ACF, and custom fields.
Version: 1.3
Author: Gal Tibet
Text Domain: wp-duplicate-content
Network: true
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Add duplicate link to post actions
 *
 * @param array $actions An array of post actions.
 * @param WP_Post $post The post object.
 * @return array Modified array of post actions.
 */
function wdc_add_duplicate_link($actions, $post) {
    if (current_user_can('edit_posts') && current_user_can('edit_post', $post->ID)) {
        $nonce = wp_create_nonce('duplicate_post_' . $post->ID);
        $url = admin_url(sprintf('admin.php?action=duplicate_post&post=%d&duplicate_nonce=%s', $post->ID, $nonce));
        $actions['duplicate'] = sprintf(
            '<a href="%s" title="%s" rel="permalink">%s</a>',
            esc_url($url),
            esc_attr__('Duplicate this item', 'wp-duplicate-content'),
            esc_html__('Duplicate', 'wp-duplicate-content')
        );
    }
    return $actions;
}
add_filter('post_row_actions', 'wdc_add_duplicate_link', 10, 2);
add_filter('page_row_actions', 'wdc_add_duplicate_link', 10, 2);

/**
 * Duplicate a post as a draft
 */
function wdc_duplicate_post_as_draft() {
    // Check if post ID and nonce are set
    if (!isset($_GET['post']) || !isset($_GET['duplicate_nonce'])) {
        wp_die(__('No post to duplicate has been supplied!', 'wp-duplicate-content'));
    }

    // Verify nonce
    $post_id = absint($_GET['post']);
    $nonce = sanitize_text_field($_GET['duplicate_nonce']);
    if (!wp_verify_nonce($nonce, 'duplicate_post_' . $post_id)) {
        wp_die(__('Security check failed', 'wp-duplicate-content'));
    }

    // Check user capabilities
    if (!current_user_can('edit_post', $post_id)) {
        wp_die(__('You do not have permission to duplicate this post.', 'wp-duplicate-content'));
    }

    // Get the original post
    $post = get_post($post_id);

    if (!$post) {
        wp_die(__('Post creation failed, could not find original post: ', 'wp-duplicate-content') . $post_id);
    }

    // Create the duplicate post
    $new_post_id = wdc_create_duplicate($post);

    if (is_wp_error($new_post_id)) {
        wp_die($new_post_id->get_error_message());
    }

    // Redirect to the edit post screen for the new draft
    wp_safe_redirect(admin_url('post.php?action=edit&post=' . $new_post_id));
    exit;
}
add_action('admin_action_duplicate_post', 'wdc_duplicate_post_as_draft');

/**
 * Create a duplicate from a post
 *
 * @param WP_Post $post The post object to duplicate.
 * @return int|WP_Error The new post ID or WP_Error on failure.
 */
function wdc_create_duplicate($post) {
    // Create the duplicate post
    $new_post_id = wp_insert_post(
        array(
            'post_title'    => $post->post_title . ' ' . __('(Copy)', 'wp-duplicate-content'),
            'post_type'     => $post->post_type,
            'post_content'  => $post->post_content,
            'post_excerpt'  => $post->post_excerpt,
            'post_author'   => get_current_user_id(),
            'post_status'   => 'draft',
            'post_parent'   => $post->post_parent,
            'menu_order'    => $post->menu_order,
        )
    );

    if (is_wp_error($new_post_id)) {
        return $new_post_id;
    }

    // Duplicate post meta
    wdc_duplicate_post_meta($post->ID, $new_post_id);

    // Duplicate taxonomies
    wdc_duplicate_taxonomies($post->ID, $new_post_id, $post->post_type);

    // Log the duplication action
    wdc_log_duplication_action($post->ID, $new_post_id);

    return $new_post_id;
}

/**
 * Duplicate all post meta
 *
 * @param int $old_post_id The ID of the post being duplicated.
 * @param int $new_post_id The ID of the newly created post.
 */
function wdc_duplicate_post_meta($old_post_id, $new_post_id) {
    $post_meta = get_post_meta($old_post_id);
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
            '_yoast_wpseo_primary_category'
        );
        foreach ($yoast_meta_keys as $meta_key) {
            $meta_value = get_post_meta($old_post_id, $meta_key, true);
            if ($meta_value) {
                update_post_meta($new_post_id, $meta_key, $meta_value);
            }
        }
    }
}

/**
 * Duplicate the taxonomies of a post to another post
 *
 * @param int $old_post_id The ID of the post being duplicated.
 * @param int $new_post_id The ID of the newly created post.
 * @param string $post_type The post type of the new post.
 */
function wdc_duplicate_taxonomies($old_post_id, $new_post_id, $post_type) {
    $taxonomies = get_object_taxonomies($post_type);
    foreach ($taxonomies as $taxonomy) {
        $post_terms = wp_get_object_terms($old_post_id, $taxonomy, array('fields' => 'slugs'));
        wp_set_object_terms($new_post_id, $post_terms, $taxonomy, false);
    }
}

/**
 * Log the duplication action
 *
 * @param int $old_post_id The ID of the post being duplicated.
 * @param int $new_post_id The ID of the newly created post.
 */
function wdc_log_duplication_action($old_post_id, $new_post_id) {
    $user = wp_get_current_user();
    $log_message = sprintf(
        'Post ID %d was duplicated to new Post ID %d by User %s (ID: %d)',
        $old_post_id,
        $new_post_id,
        $user->user_login,
        $user->ID
    );
    error_log($log_message);
}
