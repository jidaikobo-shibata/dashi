<?php

namespace Dashi\Core\Posttype;

if (!defined('ABSPATH')) exit;

class Help
{
    public static function hook(\WP_Screen $screen)
    {
        if ($screen->base !== 'post') return;
        $post_type = get_post_type_object($screen->post_type);
        $help_type = get_post_type_object('editablehelp');
        if (!$post_type || !$help_type) return;

        $requested_id = Input::get('post');
        $post_id = is_scalar($requested_id) ? absint($requested_id) : 0;
        if ($post_id) {
            if (get_post_type($post_id) !== $post_type->name || !current_user_can('edit_post', $post_id)) return;
        } elseif (!current_user_can($post_type->cap->create_posts)) {
            return;
        }

        self::addTab($screen, $help_type, 'post_type', 'editablehelp-pt-' . $post_type->name,
            __('Common Help', 'dashi'), __('Add Help of this post_type', 'dashi'));
        if ($post_id) {
            self::addTab($screen, $help_type, 'id', 'editablehelp-id-' . $post_id,
                __('Individual Help', 'dashi'), __('Add Help of this page', 'dashi'));
        }
    }

    private static function addTab($screen, $help_type, $key, $slug, $title, $add_label)
    {
        $help = get_page_by_path($slug, OBJECT, ['editablehelp']);
        $visible = $help && current_user_can('read_post', $help->ID) ? self::visibleHelp($help->ID) : null;
        $body = esc_html__('Help is not exist yet.', 'dashi');
        $url = '';
        $label = $add_label;

        if ($visible) {
            $title = $visible->post_title;
            // 管理画面でショートコードや動的ブロックのコールバックを実行しない。
            $body = wp_kses_post(wpautop(strip_shortcodes($visible->post_content)));
            $url = get_edit_post_link($visible->ID, 'raw');
            $label = __('Edit this Help', 'dashi');
        } elseif (!$help && current_user_can($help_type->cap->create_posts)) {
            $url = add_query_arg(['post_type' => 'editablehelp', 'slug' => $slug], admin_url('post-new.php'));
        }

        if ($url) {
            $body .= '<p><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
        }
        $screen->add_help_tab([
            'title' => esc_html($title),
            'id' => 'custom-help-' . $key,
            'content' => $body,
        ]);
    }

    private static function visibleHelp($id)
    {
        $matches = get_posts([
            'p' => $id,
            'post_type' => 'editablehelp',
            'post_status' => 'publish',
            'numberposts' => 1,
            'suppress_filters' => false,
            'cache_results' => false,
        ]);
        $post = $matches[0] ?? null;
        if (!$post instanceof \WP_Post || (int) $post->ID !== (int) $id
            || $post->post_type !== 'editablehelp' || $post->post_status !== 'publish'
            || post_password_required($post) || !apply_filters('dashi_search_can_show_result', true, $post)) return null;
        return $post;
    }
}
