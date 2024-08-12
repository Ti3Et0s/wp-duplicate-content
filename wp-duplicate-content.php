<?php
/*
Plugin Name: WP Duplicate Content
Description: A plugin to duplicate custom post types, pages, and posts with support for Yoast SEO, ACF, and custom fields.
Version: 1.2
Author: Gal Tibet
Text Domain: wp-duplicate-content
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

add_filter('post_row_actions', 'add_duplicate_link', 10, 2);
add_filter('page_row_actions', 'add_duplicate_link', 10, 2);

function add_duplicate_link($actions, $post)
{
	if (current_user_can('edit_posts', $post->ID) && current_user_can('manage_options')) {
		$nonce = wp_create_nonce('duplicate_post_' . $post->ID);
		$url = admin_url('admin.php?action=duplicate_post&post=' . $post->ID . '&duplicate_nonce=' . $nonce);
		$actions['duplicate'] = '<a href="' . esc_url($url) . '" title="' . esc_attr__('Duplicate this item', 'wp-duplicate-content') . '" rel="permalink">' . __('Duplicate', 'wp-duplicate-content') . '</a>';
	}
	return $actions;
}

function duplicate_post_as_draft()
{
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

	if (!current_user_can('edit_posts', $post_id) || !current_user_can('manage_options')) {
		wp_die(__('You do not have permission to duplicate this post.', 'wp-duplicate-content'));
	}

	$post = get_post($post_id);

	if (isset($post) && $post != null) {
		$new_post = array(
			'post_title'    => $post->post_title . ' (Copy)',
			'post_content'  => $post->post_content,
			'post_status'   => 'draft',
			'post_type'     => $post->post_type,
		);

		$new_post_id = wp_insert_post($new_post);

		$meta_data = get_post_meta($post_id);
		foreach ($meta_data as $key => $value) {
			$sanitized_key = sanitize_key($key);
			$sanitized_value = maybe_unserialize($value[0]);
			update_post_meta($new_post_id, $sanitized_key, sanitize_text_field($sanitized_value));
		}

		// Duplicate Yoast SEO meta data
		if (class_exists('WPSEO_Meta')) {
			$yoast_meta_keys = array(
				'_yoast_wpseo_focuskw',
				'_yoast_wpseo_title',
				'_yoast_wpseo_metadesc',
				'_yoast_wpseo_linkdex',
				'_yoast_wpseo_metakeywords'
			);
			foreach ($yoast_meta_keys as $key) {
				$meta_value = get_post_meta($post_id, $key, true);
				if ($meta_value) {
					update_post_meta($new_post_id, sanitize_key($key), sanitize_text_field($meta_value));
				}
			}
		}

		// Log duplication action
		log_duplication_action($post_id, $new_post_id);

		// Redirect to the new draft
		wp_safe_redirect(admin_url('post.php?action=edit&post=' . intval($new_post_id)));
		exit;
	} else {
		wp_die(__('Post creation failed, could not find original post: ', 'wp-duplicate-content') . $post_id);
	}
}

add_action('admin_action_duplicate_post', 'duplicate_post_as_draft');

function log_duplication_action($post_id, $new_post_id)
{
	if (function_exists('error_log')) {
		error_log('Post ID ' . $post_id . ' was duplicated to new Post ID ' . $new_post_id . ' by User ID ' . get_current_user_id());
	}
}
