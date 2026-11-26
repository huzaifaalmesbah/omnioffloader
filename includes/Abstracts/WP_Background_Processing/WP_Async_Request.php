<?php

namespace OmniOffloader\Abstracts\WP_Background_Processing;

/**
 * WP Async Request
 *
 * Bundled from WP Background Processing by Delicious Brains
 * (https://github.com/deliciousbrains/wp-background-processing),
 * licensed GPL-2.0-or-later. Namespaced and adapted for OmniOffloader.
 *
 * @package WP-Background-Processing
 */

/**
 * Abstract WP_Async_Request class.
 *
 * @abstract
 */
abstract class WP_Async_Request
{

    /**
     * Prefix
     *
     * (default value: 'wp')
     *
     * @var string
     * @access protected
     */
    protected $prefix = 'wp';

    /**
     * Action
     *
     * (default value: 'async_request')
     *
     * @var string
     * @access protected
     */
    protected $action = 'async_request';

    /**
     * Identifier
     *
     * @var mixed
     * @access protected
     */
    protected $identifier;

    /**
     * Data
     *
     * (default value: array())
     *
     * @var array
     * @access protected
     */
    protected $data = array();

    /**
     * POST field that carries the signed loopback token.
     *
     * @var string
     */
    const TOKEN_FIELD = 'omnioffloader_token';

    /**
     * Initiate new async request.
     */
    public function __construct()
    {
        $this->identifier = $this->prefix . '_' . $this->action;

        add_action('wp_ajax_' . $this->identifier, array($this, 'maybe_handle'));
        add_action('wp_ajax_nopriv_' . $this->identifier, array($this, 'maybe_handle'));
    }

    /**
     * Set data used during the request.
     *
     * @param array $data Data.
     *
     * @return $this
     */
    public function data($data)
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Dispatch the async request.
     *
     * @return array|WP_Error|false HTTP Response array, WP_Error on failure, or false if not attempted.
     */
    public function dispatch()
    {
        $url  = add_query_arg($this->get_query_args(), $this->get_query_url());
        $args = $this->get_post_args();

        return wp_remote_post(esc_url_raw($url), $args);
    }

    /**
     * Get query args.
     *
     * @return array
     */
    protected function get_query_args()
    {
        if (property_exists($this, 'query_args')) {
            return $this->query_args;
        }

        // Only the action goes in the URL: the request is authenticated by a
        // signed token in the POST body (see get_post_args()), so nothing
        // secret ends up in web server or CDN access logs.
        $args = array(
            'action' => $this->identifier,
        );

        /**
         * Filters the post arguments used during an async request.
         *
         * @param array $url
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        return apply_filters($this->identifier . '_query_args', $args);
    }

    /**
     * Get query URL.
     *
     * @return string
     */
    protected function get_query_url()
    {
        if (property_exists($this, 'query_url')) {
            return $this->query_url;
        }

        $url = admin_url('admin-ajax.php');

        // Optional: send the loopback straight to the local web server instead
        // of out through the public address (CDN, WAF) and back.
        $local = self::local_loopback_url();
        if ('' !== $local) {
            $url = $local;
        }

        /**
         * Filters the post arguments used during an async request.
         *
         * @param string $url
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        return apply_filters($this->identifier . '_query_url', $url);
    }

    /**
     * Get post args.
     *
     * @return array
     */
    protected function get_post_args()
    {
        if (property_exists($this, 'post_args')) {
            return $this->post_args;
        }

        // No cookies: the worker never needs the initiating user (runs started
        // by WP-Cron already work as user 0), and forwarding them would send an
        // administrator's login cookies over a request whose TLS is unchecked.
        $body                      = is_array($this->data) ? $this->data : array();
        $body[ self::TOKEN_FIELD ] = $this->loopback_token();

        $args = array(
            'timeout'   => 5,
            'blocking'  => false,
            'body'      => $body,
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            'sslverify' => apply_filters('https_local_ssl_verify', false), // Local requests, fine to pass false.
        );

        if ('' !== self::local_loopback_url()) {
            $host = wp_parse_url(home_url(), PHP_URL_HOST);
            if (is_string($host) && '' !== $host) {
                $args['headers'] = array('Host' => $host);
            }
        }

        /**
         * Filters the post arguments used during an async request.
         *
         * @param array $args
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        return apply_filters($this->identifier . '_post_args', $args);
    }

    /**
     * Maybe handle a dispatched request.
     *
     * Check for correct nonce and pass to handler.
     *
     * @return void|mixed
     */
    public function maybe_handle()
    {
        // Don't lock up other requests while processing.
        session_write_close();

        $this->verify_loopback_request();

        $this->handle();

        return $this->maybe_wp_die();
    }

    /**
     * Signed token for one loopback request.
     *
     * An HMAC of the queue identifier and the current hour, keyed with the
     * site's nonce salt. It is the same for every caller (the worker runs as
     * user 0 either way), never appears in a URL, and is only valid for this
     * site and this queue for one to two hours.
     *
     * @param int|null $window Hour window; defaults to the current one.
     *
     * @return string
     */
    protected function loopback_token($window = null)
    {
        if (null === $window) {
            $window = (int) floor(time() / HOUR_IN_SECONDS);
        }

        return hash_hmac('sha256', $this->identifier . '|' . $window, wp_salt('nonce'));
    }

    /**
     * Whether the current request carries a valid loopback token.
     *
     * Accepts the current and the previous hour, so a request sent just
     * before the hour turns still passes.
     *
     * @return bool
     */
    protected function is_valid_loopback_request()
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- this is the request's authentication: an HMAC token, checked below.
        $token = isset($_POST[ self::TOKEN_FIELD ]) ? sanitize_text_field(wp_unslash((string) $_POST[ self::TOKEN_FIELD ])) : '';

        if ('' === $token) {
            return false;
        }

        $window = (int) floor(time() / HOUR_IN_SECONDS);

        return hash_equals($this->loopback_token($window), $token)
            || hash_equals($this->loopback_token($window - 1), $token);
    }

    /**
     * Stop the request unless it is a genuine loopback from this site.
     *
     * Runs before anything reads or changes queue state, so an anonymous
     * admin-ajax hit costs nothing beyond the WordPress boot.
     *
     * @return void
     */
    protected function verify_loopback_request()
    {
        if (! $this->is_valid_loopback_request()) {
            wp_die('-1', 403);
        }
    }

    /**
     * Local loopback URL from the OMNIOFFLOADER_LOOPBACK_URL constant.
     *
     * For example https://127.0.0.1/wp-admin/admin-ajax.php. The site's own
     * host is then sent in the Host header. Empty when not set or invalid.
     *
     * @return string
     */
    protected static function local_loopback_url()
    {
        if (! defined('OMNIOFFLOADER_LOOPBACK_URL')) {
            return '';
        }

        $url = constant('OMNIOFFLOADER_LOOPBACK_URL');

        // Not wp_http_validate_url(): it rejects 127.0.0.1 by design.
        if (! is_string($url) || false === filter_var($url, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    /**
     * Should the process exit with wp_die?
     *
     * @param mixed $return What to return if filter says don't die, default is null.
     *
     * @return void|mixed
     */
    protected function maybe_wp_die($return = null)
    {
        /**
         * Should wp_die be used?
         *
         * @return bool
         */
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
        if (apply_filters($this->identifier . '_wp_die', true)) {
            wp_die();
        }

        return $return;
    }

    /**
     * Handle a dispatched request.
     *
     * Override this method to perform any actions required
     * during the async request.
     */
    abstract protected function handle();
}
