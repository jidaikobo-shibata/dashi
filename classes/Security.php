<?php
/**
 * Dashi\Core\Security
 */
namespace Dashi\Core;

if (!defined('ABSPATH')) exit;

class Security
{
    public const HIDE_AUTHOR_ARCHIVES = 'dashi_disactivate_author_page';
    public const RESTRICT_REST_USERS = 'dashi_restrict_public_rest_users';
    /**
     * forge
     *
     * @return Void
     */
    public static function forge()
    {
        add_action('template_redirect', [self::class, 'enforceHttps'], 0);

        add_action('template_redirect', [self::class, 'hideAuthorArchives'], 1);
        add_filter('wp_sitemaps_add_provider', [self::class, 'filterSitemapProvider'], 10, 2);
        add_filter('rest_pre_dispatch', [self::class, 'restrictPublicRestUsers'], PHP_INT_MAX, 3);
    }

    public static function hideAuthorArchives()
    {
        if (!get_option(self::HIDE_AUTHOR_ARCHIVES)) return;
        if (get_query_var('sitemap') === 'users') {
            nocache_headers();
            wp_die(esc_html__('Author sitemap not found.', 'dashi'), '', ['response' => 404]);
        }
        if (is_author()) {
            wp_safe_redirect(home_url());
            exit;
        }
    }

    public static function filterSitemapProvider($provider, $name)
    {
        return $name === 'users' && get_option(self::HIDE_AUTHOR_ARCHIVES) ? false : $provider;
    }

    public static function restUsersRestricted(): bool
    {
        return (bool) get_option(self::RESTRICT_REST_USERS, true);
    }

    public static function restrictPublicRestUsers($result, $server, \WP_REST_Request $request)
    {
        if (is_user_logged_in() || !self::restUsersRestricted()
            || !in_array($request->get_method(), ['GET', 'HEAD'], true)
            || !preg_match('~\A/wp/v2/users(?:/[0-9]+)?/?\z~i', $request->get_route())) {
            return $result;
        }

        return new \WP_Error(
            'dashi_rest_users_login_required',
            __('Log in to view user information.', 'dashi'),
            ['status' => 401]
        );
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
