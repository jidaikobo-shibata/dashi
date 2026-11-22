<?php
/**
 * Dashi\Core\Security
 */
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

class Security
{
    /**
     * forge
     *
     * @return Void
     */
    public static function forge()
    {
        add_action('template_redirect', [self::class, 'enforceHttps'], 0);

        // ?author=Nを表示しない
        if (get_option('dashi_disactivate_author_page'))
        {
	            add_action(
	                'template_redirect',
	                function () {
	                    if(is_author())
	                    {
	                        wp_safe_redirect(home_url());
	                        exit;
	                    }
	                }
	            );
        }
    }
    public static function requiresHttps()
    {
        return get_option('dashi_keep_ssl_connection') && !is_ssl();
    }

    public static function enforceHttps()
    {
        if (!self::requiresHttps()) return;
        $method = sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (in_array($method, ['get', 'head'], true)) {
            $origin = wp_parse_url(home_url('/', 'https'));
            if (!empty($origin['host'])) {
                $port = isset($origin['port']) ? ':' . (int) $origin['port'] : '';
                // REQUEST_URI はサイトのサブディレクトリを含むため、ホストだけを前置する。
                $path = wp_sanitize_redirect(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
                $url = 'https://' . $origin['host'] . $port . '/' . ltrim($path, '/');
                if (wp_safe_redirect($url, 301)) exit;
            }
        }
        // 平文で届いたフォームの内容を処理したり、リダイレクトで再送させたりしない。
        nocache_headers();
        wp_die(esc_html__('HTTPS is required.', 'dashi'), '', ['response' => 403]);
    }

}
