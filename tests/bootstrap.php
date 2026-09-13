<?php

if (! defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

if (! defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
    if (! is_dir(WP_CONTENT_DIR)) {
        @mkdir(WP_CONTENT_DIR, 0755, true);
    }
}

if (! defined('CLOCKWORK_RENEGADE_VERSION')) {
    if (preg_match("/define\\('CLOCKWORK_RENEGADE_VERSION',\\s*'([^\x27]+)'\\)/", file_get_contents(dirname(__DIR__) . '/clockwork-renegade.php'), $matches)) {
        define('CLOCKWORK_RENEGADE_VERSION', $matches[1]);
    } else {
        define('CLOCKWORK_RENEGADE_VERSION', '1.36.0');
    }
}

if (! defined('CLOCKWORK_RENEGADE_DIR')) {
    define('CLOCKWORK_RENEGADE_DIR', dirname(__DIR__));
}

if (! defined('CLOCKWORK_RENEGADE_NAMESPACE')) {
    define('CLOCKWORK_RENEGADE_NAMESPACE', 'clockwork-renegade/v1');
}

// In-memory options and hooks storage
$GLOBALS['wp_test_options'] = [];
$GLOBALS['wp_test_filters'] = [];
$GLOBALS['wp_test_actions'] = [];
$GLOBALS['wp_test_current_user'] = null;

if (! class_exists('WP_User')) {
    class WP_User
    {
        public string $user_email = '';

        public function __construct(string $email = '')
        {
            $this->user_email = $email;
        }
    }
}

if (! function_exists('get_option')) {
    function get_option(string $option, mixed $default = false): mixed
    {
        return $GLOBALS['wp_test_options'][$option] ?? $default;
    }
}

if (! function_exists('update_option')) {
    function update_option(string $option, mixed $value, bool $autoload = true): bool
    {
        $GLOBALS['wp_test_options'][$option] = $value;
        return true;
    }
}

if (! function_exists('delete_option')) {
    function delete_option(string $option): bool
    {
        unset($GLOBALS['wp_test_options'][$option]);
        return true;
    }
}

if (! function_exists('get_transient')) {
    function get_transient(string $transient): mixed
    {
        return $GLOBALS['wp_test_transients'][$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['wp_test_transients'][$transient] = $value;
        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset($GLOBALS['wp_test_transients'][$transient]);
        return true;
    }
}


if (! function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wp_test_filters'][$tag][] = $callback;
        return true;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        $GLOBALS['wp_test_actions'][$tag][] = $callback;
        return true;
    }
}

if (! function_exists('apply_filters')) {
    /**
     * Runs callbacks registered through the add_filter() stub above, in
     * registration order, so filter-driven behaviour is actually exercised
     * rather than silently skipped.
     */
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        foreach ($GLOBALS['wp_test_filters'][$tag] ?? [] as $callback) {
            $value = $callback($value, ...$args);
        }

        return $value;
    }
}

if (! function_exists('is_email')) {
    function is_email(string $email): string|false
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? false : $email;
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return ($GLOBALS['wp_test_home_url'] ?? 'https://example.test') . $path;
    }
}

if (! function_exists('esc_js')) {
    function esc_js(string $text): string
    {
        return addslashes($text);
    }
}

if (! function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (! function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string
    {
        return trim(strip_tags($str));
    }
}

if (! function_exists('sanitize_email')) {
    function sanitize_email(string $email): string
    {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) ? trim($email) : '';
    }
}

if (! function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string
    {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (! function_exists('esc_url')) {
    function esc_url(string $url): string
    {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('plugins_url')) {
    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return 'https://example.com/wp-content/mu-plugins/' . ltrim($path, '/');
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = ''): string
    {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return true;
    }
}

if (! function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action): string
    {
        return 'test_nonce_' . md5($action);
    }
}

if (! function_exists('wp_verify_nonce')) {
    function wp_verify_nonce(string $nonce, string $action): bool
    {
        return $nonce === 'test_nonce_' . md5($action);
    }
}

if (! function_exists('wp_get_current_user')) {
    function wp_get_current_user(): ?WP_User
    {
        return $GLOBALS['wp_test_current_user'] ?? new WP_User('admin@clientdomain.com');
    }
}

if (! class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        public function __construct(
            protected string $method = 'GET',
            protected string $route = '',
            protected ?array $jsonParams = [],
            protected array $headers = [],
            protected string $body = ''
        ) {}

        public function get_method(): string { return $this->method; }
        public function get_route(): string { return $this->route; }
        public function get_json_params(): ?array { return $this->jsonParams; }
        public function get_header(string $header): ?string {
            $normalized = str_replace('-', '_', strtolower($header));
            foreach ($this->headers as $k => $v) {
                if (str_replace('-', '_', strtolower($k)) === $normalized) {
                    return $v;
                }
            }
            return null;
        }
        public function get_body(): string { return $this->body; }
        public function get_param(string $key): mixed { return $this->jsonParams[$key] ?? null; }
        public function get_params(): array { return (array) $this->jsonParams; }
    }
}

// WordPress Comments stubs
$GLOBALS['wp_test_comments'] = [];

if (! function_exists('get_comments')) {
    function get_comments(array $args = []): array
    {
        $all = $GLOBALS['wp_test_comments'] ?? [];
        $status = $args['status'] ?? 'all';
        $filtered = [];
        foreach ($all as $c) {
            if ($status === 'all' || ($c->comment_approved ?? '') === $status) {
                $filtered[] = $c;
            }
        }
        $offset = (int) ($args['offset'] ?? 0);
        $number = (int) ($args['number'] ?? 20);
        return array_slice($filtered, $offset, $number);
    }
}

if (! function_exists('wp_count_comments')) {
    function wp_count_comments(): object
    {
        $counts = (object) [
            'approved' => 0,
            'moderated' => 0,
            'spam' => 0,
            'trash' => 0,
            'total_comments' => 0,
        ];
        foreach ($GLOBALS['wp_test_comments'] ?? [] as $c) {
            $counts->total_comments++;
            if (($c->comment_approved ?? '') === '1' || ($c->comment_approved ?? '') === 'approve') {
                $counts->approved++;
            } elseif (($c->comment_approved ?? '') === '0' || ($c->comment_approved ?? '') === 'hold') {
                $counts->moderated++;
            } elseif (($c->comment_approved ?? '') === 'spam') {
                $counts->spam++;
            } elseif (($c->comment_approved ?? '') === 'trash') {
                $counts->trash++;
            }
        }
        return $counts;
    }
}

if (! function_exists('wp_set_comment_status')) {
    function wp_set_comment_status(int $comment_id, string $status): bool
    {
        foreach ($GLOBALS['wp_test_comments'] ?? [] as $c) {
            if ((int) $c->comment_ID === $comment_id) {
                $c->comment_approved = ($status === 'approve') ? '1' : (($status === 'hold') ? '0' : $status);
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('wp_trash_comment')) {
    function wp_trash_comment(int $comment_id): bool
    {
        return wp_set_comment_status($comment_id, 'trash');
    }
}

if (! function_exists('wp_spam_comment')) {
    function wp_spam_comment(int $comment_id): bool
    {
        return wp_set_comment_status($comment_id, 'spam');
    }
}

if (! function_exists('wp_delete_comment')) {
    function wp_delete_comment(int $comment_id, bool $force = false): bool
    {
        foreach ($GLOBALS['wp_test_comments'] ?? [] as $k => $c) {
            if ((int) $c->comment_ID === $comment_id) {
                unset($GLOBALS['wp_test_comments'][$k]);
                return true;
            }
        }
        return false;
    }
}

if (! function_exists('get_the_title')) {
    function get_the_title(int $post_id): string
    {
        return 'Post #' . $post_id;
    }
}

if (! function_exists('get_permalink')) {
    function get_permalink(int $post_id): string
    {
        return 'https://example.com/?p=' . $post_id;
    }
}

if (! function_exists('esc_sql')) {
    function esc_sql(string $data): string
    {
        return addslashes($data);
    }
}

if (! class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        public function __construct(
            public mixed $data = null,
            public int $status = 200,
            public array $headers = []
        ) {}

        public function get_data(): mixed { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}

if (! function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool
    {
        $GLOBALS['wp_test_routes'][$namespace . $route] = $args;
        return true;
    }
}

// Autoload Companion classes
spl_autoload_register(function (string $class): void {
    $prefix = 'ClockworkRenegade\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($path)) {
        require_once $path;
    }
});

if (! class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            protected string $code = '',
            protected string $message = '',
            protected mixed $data = null
        ) {}

        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data(): mixed { return $this->data; }
    }
}

if (! function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $data, int $options = 0, int $depth = 512): string|false
    {
        return json_encode($data, $options, $depth);
    }
}

if (! defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

if (! defined('ARRAY_N')) {
    define('ARRAY_N', 'ARRAY_N');
}

if (! function_exists('delete_site_option')) {
    function delete_site_option(string $option): bool
    {
        return delete_option($option);
    }
}

if (! function_exists('is_multisite')) {
    function is_multisite(): bool
    {
        return false;
    }
}

if (! function_exists('wp_add_privacy_policy_content')) {
    function wp_add_privacy_policy_content(string $plugin_name, string $policy_text): void
    {
        $GLOBALS['wp_test_privacy_content'][$plugin_name] = $policy_text;
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string
    {
        return $content;
    }
}

if (! class_exists('wpdb')) {
    class wpdb
    {
        public string $prefix = 'wp_';
        public string $options = 'wp_options';
        public array $queries = [];

        public function query(string $query): int
        {
            $this->queries[] = $query;
            return 1;
        }

        public function prepare(string $query, mixed ...$args): string
        {
            foreach ($args as $arg) {
                $query = preg_replace('/%s/', "'".addslashes((string) $arg)."'", $query, 1);
            }

            return $query;
        }

        public function esc_like(string $text): string
        {
            return addcslashes($text, '_%\\');
        }

        public function get_var(?string $query = null, int $x = 0, int $y = 0): mixed
        {
            return null;
        }

        public function get_results(?string $query = null, string $output = 'ARRAY_A'): array
        {
            return [];
        }
    }
}

$GLOBALS['wpdb'] = new wpdb();

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('_e')) {
    function _e(string $text, string $domain = 'default'): void
    {
        echo $text;
    }
}

if (! function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (! function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = 'default'): void
    {
        echo esc_html__($text, $domain);
    }
}

if (! function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo esc_attr__($text, $domain);
    }
}

if (! function_exists('wp_kses')) {
    function wp_kses(string $content, array $allowed_html): string
    {
        return $content;
    }
}

if (! function_exists('wp_kses_post')) {
    function wp_kses_post(string $content): string
    {
        return $content;
    }
}

if (! function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1): mixed
    {
        return $component === -1 ? parse_url($url) : parse_url($url, $component);
    }
}

if (! function_exists('_n')) {
    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return $number === 1 ? $single : $plural;
    }
}

if (! function_exists('number_format_i18n')) {
    function number_format_i18n(int|float $number, int $decimals = 0): string
    {
        return number_format((float) $number, $decimals);
    }
}
