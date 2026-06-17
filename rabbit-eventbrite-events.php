<?php
/**
 * Plugin Name: Rabbit Eventbrite Events
 * Description: Syncs your Eventbrite organisation events into WordPress and displays them with event cards, filters, single event pages, and optional Eventbrite checkout modals.
 * Version: 1.4.0
 * Author: Rabbit Web Design
 * License: GPL-2.0-or-later
 * Text Domain: rabbit-eventbrite-events
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Rabbit_Eventbrite_Events
{
    const VERSION = '1.4.0';
    const OPTION_NAME = 'ree_settings';
    const LAST_SYNC_OPTION = 'ree_last_sync';
    const LOGS_OPTION = 'ree_sync_logs';
    const POST_TYPE = 'ree_event';
    const TAX_CATEGORY = 'ree_event_category';
    const CRON_HOOK = 'ree_sync_eventbrite_events';

    private static $instance = null;

    public static function instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', [$this, 'register_content_types']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_ree_sync_now', [$this, 'sync_now_action']);
        add_action('admin_post_ree_clear_logs', [$this, 'clear_logs_action']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action(self::CRON_HOOK, [$this, 'sync_events']);
        add_shortcode('eventbrite_events', [$this, 'render_shortcode']);
        add_shortcode('eventbrite_events_synced', [$this, 'render_shortcode']);
        add_filter('the_content', [$this, 'append_single_event_details']);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'event_admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'event_admin_column_content'], 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [$this, 'event_sortable_columns']);
        add_action('pre_get_posts', [$this, 'event_admin_orderby']);
        add_action('add_meta_boxes', [$this, 'add_event_meta_boxes']);
    }

    public static function activate(): void
    {
        $instance = self::instance();
        $instance->register_content_types();
        flush_rewrite_rules();
        $instance->maybe_reschedule_cron();
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        flush_rewrite_rules();
    }

    public function register_assets(): void
    {
        wp_register_style(
            'rabbit-eventbrite-events',
            plugin_dir_url(__FILE__) . 'assets/eventbrite-events.css',
            [],
            self::VERSION
        );

        wp_register_script(
            'rabbit-eventbrite-checkout',
            'https://www.eventbrite.com/static/widgets/eb_widgets.js',
            [],
            null,
            true
        );

        if (is_singular(self::POST_TYPE) || is_post_type_archive(self::POST_TYPE)) {
            wp_enqueue_style('rabbit-eventbrite-events');
        }
    }

    public function register_content_types(): void
    {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Eventbrite Events', 'rabbit-eventbrite-events'),
                'singular_name'      => __('Eventbrite Event', 'rabbit-eventbrite-events'),
                'menu_name'          => __('Eventbrite Events', 'rabbit-eventbrite-events'),
                'edit_item'          => __('Edit Synced Event', 'rabbit-eventbrite-events'),
                'view_item'          => __('View Event', 'rabbit-eventbrite-events'),
                'all_items'          => __('Synced Events', 'rabbit-eventbrite-events'),
                'search_items'       => __('Search Synced Events', 'rabbit-eventbrite-events'),
                'not_found'          => __('No synced events found.', 'rabbit-eventbrite-events'),
                'not_found_in_trash' => __('No synced events found in Trash.', 'rabbit-eventbrite-events'),
            ],
            'public'       => true,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-calendar-alt',
            'has_archive'  => 'eventbrite-events',
            'rewrite'      => [
                'slug'       => 'eventbrite-events',
                'with_front' => false,
            ],
            'supports'      => ['title', 'editor', 'excerpt', 'thumbnail'],
            'show_in_rest'  => true,
            'map_meta_cap'  => true,
            'capabilities'  => [
                'create_posts' => 'do_not_allow',
            ],
        ]);

        register_taxonomy(self::TAX_CATEGORY, [self::POST_TYPE], [
            'labels' => [
                'name'          => __('Event Categories', 'rabbit-eventbrite-events'),
                'singular_name' => __('Event Category', 'rabbit-eventbrite-events'),
                'search_items'  => __('Search Event Categories', 'rabbit-eventbrite-events'),
                'all_items'     => __('All Event Categories', 'rabbit-eventbrite-events'),
                'edit_item'     => __('Edit Event Category', 'rabbit-eventbrite-events'),
                'update_item'   => __('Update Event Category', 'rabbit-eventbrite-events'),
                'add_new_item'  => __('Add New Event Category', 'rabbit-eventbrite-events'),
                'menu_name'     => __('Event Categories', 'rabbit-eventbrite-events'),
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'eventbrite-category'],
        ]);
    }

    public function add_admin_menu(): void
    {
        add_options_page(
            __('Eventbrite Events', 'rabbit-eventbrite-events'),
            __('Eventbrite Events', 'rabbit-eventbrite-events'),
            'manage_options',
            'rabbit-eventbrite-events',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings(): void
    {
        register_setting('ree_settings_group', self::OPTION_NAME, [$this, 'sanitize_settings']);

        add_settings_section(
            'ree_api_section',
            __('Eventbrite Organisation', 'rabbit-eventbrite-events'),
            function () {
                echo '<p>' . esc_html__('Connect one Eventbrite organisation. Events are synced into WordPress; manual event creation and partner organisation sources have been removed.', 'rabbit-eventbrite-events') . '</p>';
            },
            'rabbit-eventbrite-events'
        );

        add_settings_field('organization_id', __('Organisation ID', 'rabbit-eventbrite-events'), [$this, 'field_organization_id'], 'rabbit-eventbrite-events', 'ree_api_section');
        add_settings_field('private_token', __('Private token', 'rabbit-eventbrite-events'), [$this, 'field_private_token'], 'rabbit-eventbrite-events', 'ree_api_section');
        add_settings_field('status', __('Event status to sync', 'rabbit-eventbrite-events'), [$this, 'field_status'], 'rabbit-eventbrite-events', 'ree_api_section');

        add_settings_section(
            'ree_display_section',
            __('Display and Sync Settings', 'rabbit-eventbrite-events'),
            function () {
                echo '<p>' . esc_html__('Choose how synced events are displayed and how often Eventbrite should refresh.', 'rabbit-eventbrite-events') . '</p>';
            },
            'rabbit-eventbrite-events'
        );

        add_settings_field('button_label', __('Default Button Label', 'rabbit-eventbrite-events'), [$this, 'field_button_label'], 'rabbit-eventbrite-events', 'ree_display_section');
        add_settings_field('card_checkout_mode', __('Card Ticket Button', 'rabbit-eventbrite-events'), [$this, 'field_card_checkout_mode'], 'rabbit-eventbrite-events', 'ree_display_section');
        add_settings_field('single_checkout_mode', __('Single Event Ticket Button', 'rabbit-eventbrite-events'), [$this, 'field_single_checkout_mode'], 'rabbit-eventbrite-events', 'ree_display_section');
        add_settings_field('sync_frequency', __('Automatic Sync', 'rabbit-eventbrite-events'), [$this, 'field_sync_frequency'], 'rabbit-eventbrite-events', 'ree_display_section');
        add_settings_field('sync_limit', __('Maximum Events To Sync', 'rabbit-eventbrite-events'), [$this, 'field_sync_limit'], 'rabbit-eventbrite-events', 'ree_display_section');
        add_settings_field('remove_missing', __('Missing Synced Events', 'rabbit-eventbrite-events'), [$this, 'field_remove_missing'], 'rabbit-eventbrite-events', 'ree_display_section');
    }

    public function sanitize_settings(array $input): array
    {
        $settings = $this->get_settings();

        $settings['organization_id'] = isset($input['organization_id'])
            ? preg_replace('/[^0-9]/', '', (string) $input['organization_id'])
            : '';

        $settings['private_token'] = isset($input['private_token'])
            ? sanitize_text_field(trim((string) $input['private_token']))
            : '';

        $settings['status'] = isset($input['status']) ? sanitize_key((string) $input['status']) : 'live';
        if (!in_array($settings['status'], $this->get_allowed_statuses(), true)) {
            $settings['status'] = 'live';
        }

        $settings['button_label'] = isset($input['button_label'])
            ? sanitize_text_field($input['button_label'])
            : __('Book Now', 'rabbit-eventbrite-events');

        $settings['card_checkout_mode'] = isset($input['card_checkout_mode']) && in_array($input['card_checkout_mode'], ['link', 'modal'], true)
            ? $input['card_checkout_mode']
            : 'link';

        $settings['single_checkout_mode'] = isset($input['single_checkout_mode']) && in_array($input['single_checkout_mode'], ['link', 'modal'], true)
            ? $input['single_checkout_mode']
            : 'link';

        $settings['sync_frequency'] = isset($input['sync_frequency']) && in_array($input['sync_frequency'], ['manual', 'hourly', 'twicedaily', 'daily'], true)
            ? $input['sync_frequency']
            : 'manual';

        $settings['sync_limit'] = isset($input['sync_limit'])
            ? max(1, min(500, absint($input['sync_limit'])))
            : 100;

        $settings['remove_missing'] = !empty($input['remove_missing']) ? 'yes' : 'no';

        unset($settings['sources'], $settings['display_source'], $settings['cache_minutes']);

        $this->maybe_reschedule_cron($settings);

        return $settings;
    }

    public function get_settings(): array
    {
        $raw = get_option(self::OPTION_NAME, []);
        if (!is_array($raw)) {
            $raw = [];
        }

        $defaults = [
            'private_token'        => '',
            'organization_id'      => '',
            'status'               => 'live',
            'button_label'         => __('Book Now', 'rabbit-eventbrite-events'),
            'card_checkout_mode'   => 'link',
            'single_checkout_mode' => 'link',
            'sync_frequency'       => 'manual',
            'sync_limit'           => 100,
            'remove_missing'       => 'no',
        ];

        $settings = wp_parse_args($raw, $defaults);

        if ((empty($settings['private_token']) || empty($settings['organization_id'])) && !empty($raw['sources']) && is_array($raw['sources'])) {
            $source = $this->pick_first_source($raw['sources']);
            if (!empty($source)) {
                $settings['private_token'] = sanitize_text_field((string) ($source['private_token'] ?? ''));
                $settings['organization_id'] = preg_replace('/[^0-9]/', '', (string) ($source['organization_id'] ?? ''));
                $settings['status'] = isset($source['status']) && in_array($source['status'], $this->get_allowed_statuses(), true) ? $source['status'] : 'live';
            }
        }

        $settings['organization_id'] = preg_replace('/[^0-9]/', '', (string) $settings['organization_id']);
        $settings['private_token'] = sanitize_text_field((string) $settings['private_token']);
        $settings['status'] = in_array($settings['status'], $this->get_allowed_statuses(), true) ? $settings['status'] : 'live';
        $settings['card_checkout_mode'] = in_array($settings['card_checkout_mode'], ['link', 'modal'], true) ? $settings['card_checkout_mode'] : 'link';
        $settings['single_checkout_mode'] = in_array($settings['single_checkout_mode'], ['link', 'modal'], true) ? $settings['single_checkout_mode'] : 'link';
        $settings['sync_frequency'] = in_array($settings['sync_frequency'], ['manual', 'hourly', 'twicedaily', 'daily'], true) ? $settings['sync_frequency'] : 'manual';
        $settings['sync_limit'] = max(1, min(500, absint($settings['sync_limit'])));
        $settings['remove_missing'] = $settings['remove_missing'] === 'yes' ? 'yes' : 'no';

        return $settings;
    }

    private function pick_first_source(array $sources): array
    {
        foreach ($sources as $source) {
            if (!is_array($source)) {
                continue;
            }

            if (($source['enabled'] ?? '') === 'yes' && !empty($source['private_token']) && !empty($source['organization_id'])) {
                return $source;
            }
        }

        foreach ($sources as $source) {
            if (is_array($source) && (!empty($source['private_token']) || !empty($source['organization_id']))) {
                return $source;
            }
        }

        return [];
    }

    private function get_allowed_statuses(): array
    {
        return ['live', 'all', 'started', 'ended', 'completed', 'canceled', 'draft'];
    }

    private function get_status_labels(): array
    {
        return [
            'live'      => __('Live', 'rabbit-eventbrite-events'),
            'all'       => __('All', 'rabbit-eventbrite-events'),
            'started'   => __('Started', 'rabbit-eventbrite-events'),
            'ended'     => __('Ended', 'rabbit-eventbrite-events'),
            'completed' => __('Completed', 'rabbit-eventbrite-events'),
            'canceled'  => __('Canceled', 'rabbit-eventbrite-events'),
            'draft'     => __('Draft', 'rabbit-eventbrite-events'),
        ];
    }

    public function field_organization_id(): void
    {
        $settings = $this->get_settings();
        printf(
            '<input type="text" name="%1$s[organization_id]" value="%2$s" class="regular-text" />',
            esc_attr(self::OPTION_NAME),
            esc_attr($settings['organization_id'])
        );
        echo '<p class="description">' . esc_html__('Your Eventbrite organisation ID. Only this organisation will be synced.', 'rabbit-eventbrite-events') . '</p>';
    }

    public function field_private_token(): void
    {
        $settings = $this->get_settings();
        printf(
            '<input type="password" name="%1$s[private_token]" value="%2$s" class="regular-text" autocomplete="off" />',
            esc_attr(self::OPTION_NAME),
            esc_attr($settings['private_token'])
        );
    }

    public function field_status(): void
    {
        $settings = $this->get_settings();
        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[status]">';
        foreach ($this->get_status_labels() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['status'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function field_button_label(): void
    {
        $settings = $this->get_settings();
        printf('<input type="text" name="%1$s[button_label]" value="%2$s" class="regular-text" />', esc_attr(self::OPTION_NAME), esc_attr($settings['button_label']));
    }

    public function field_card_checkout_mode(): void
    {
        $this->render_checkout_mode_field('card_checkout_mode', __('Cards can open the Eventbrite modal when an Eventbrite ID is available. Otherwise the button falls back to the Eventbrite link.', 'rabbit-eventbrite-events'));
    }

    public function field_single_checkout_mode(): void
    {
        $this->render_checkout_mode_field('single_checkout_mode', __('Single event pages can open the Eventbrite modal when an Eventbrite ID is available. Otherwise the button falls back to the Eventbrite link.', 'rabbit-eventbrite-events'));
    }

    private function render_checkout_mode_field(string $name, string $description): void
    {
        $settings = $this->get_settings();
        $value = isset($settings[$name]) && in_array($settings[$name], ['link', 'modal'], true) ? $settings[$name] : 'link';
        $options = [
            'link'  => __('Open Eventbrite link', 'rabbit-eventbrite-events'),
            'modal' => __('Open Eventbrite checkout modal', 'rabbit-eventbrite-events'),
        ];

        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[' . esc_attr($name) . ']">';
        foreach ($options as $option_value => $label) {
            echo '<option value="' . esc_attr($option_value) . '" ' . selected($value, $option_value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html($description) . '</p>';
        echo '<p class="description">' . esc_html__('Eventbrite embedded checkout usually requires a live public Eventbrite event and an HTTPS website.', 'rabbit-eventbrite-events') . '</p>';
    }

    public function field_sync_frequency(): void
    {
        $settings = $this->get_settings();
        $frequencies = [
            'manual'     => __('Manual only', 'rabbit-eventbrite-events'),
            'hourly'     => __('Hourly', 'rabbit-eventbrite-events'),
            'twicedaily' => __('Twice daily', 'rabbit-eventbrite-events'),
            'daily'      => __('Daily', 'rabbit-eventbrite-events'),
        ];

        echo '<select name="' . esc_attr(self::OPTION_NAME) . '[sync_frequency]">';
        foreach ($frequencies as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($settings['sync_frequency'], $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function field_sync_limit(): void
    {
        $settings = $this->get_settings();
        printf(
            '<input type="number" name="%1$s[sync_limit]" value="%2$d" min="1" max="500" class="small-text" />',
            esc_attr(self::OPTION_NAME),
            absint($settings['sync_limit'])
        );
    }

    public function field_remove_missing(): void
    {
        $settings = $this->get_settings();
        printf(
            '<label><input type="checkbox" name="%1$s[remove_missing]" value="yes" %2$s /> %3$s</label>',
            esc_attr(self::OPTION_NAME),
            checked($settings['remove_missing'], 'yes', false),
            esc_html__('Move local synced Eventbrite events to draft when they are no longer returned by Eventbrite.', 'rabbit-eventbrite-events')
        );
    }

    public function render_settings_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $last_sync = get_option(self::LAST_SYNC_OPTION, []);
        $logs = get_option(self::LOGS_OPTION, []);
        if (!is_array($logs)) {
            $logs = [];
        }
        ?>
        <div class="wrap ree-admin-wrap">
            <h1><?php esc_html_e('Eventbrite Events', 'rabbit-eventbrite-events'); ?></h1>

            <?php if (isset($_GET['ree_logs_cleared'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Sync logs cleared.', 'rabbit-eventbrite-events'); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['ree_synced'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html($this->get_last_sync_message($last_sync)); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['ree_sync_error'])) : ?>
                <div class="notice notice-error is-dismissible"><p><?php echo esc_html($this->get_last_sync_message($last_sync)); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('ree_settings_group');
                do_settings_sections('rabbit-eventbrite-events');
                submit_button(__('Save Settings', 'rabbit-eventbrite-events'));
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Sync Tools', 'rabbit-eventbrite-events'); ?></h2>
            <p><?php esc_html_e('Pull your Eventbrite organisation events into WordPress now. New manual event creation has been removed, so this plugin now uses Eventbrite as the source of truth.', 'rabbit-eventbrite-events'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right: 8px;">
                <?php wp_nonce_field('ree_sync_now'); ?>
                <input type="hidden" name="action" value="ree_sync_now" />
                <?php submit_button(__('Sync Events Now', 'rabbit-eventbrite-events'), 'primary', 'submit', false); ?>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
                <?php wp_nonce_field('ree_clear_logs'); ?>
                <input type="hidden" name="action" value="ree_clear_logs" />
                <?php submit_button(__('Clear Sync Logs', 'rabbit-eventbrite-events'), 'secondary', 'submit', false); ?>
            </form>

            <?php if (!empty($last_sync)) : ?>
                <p><strong><?php esc_html_e('Last Sync:', 'rabbit-eventbrite-events'); ?></strong> <?php echo esc_html($this->get_last_sync_message($last_sync)); ?></p>
            <?php endif; ?>

            <hr />

            <h2><?php esc_html_e('Sync Logs', 'rabbit-eventbrite-events'); ?></h2>
            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('No sync logs yet.', 'rabbit-eventbrite-events'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead><tr><th><?php esc_html_e('Time', 'rabbit-eventbrite-events'); ?></th><th><?php esc_html_e('Status', 'rabbit-eventbrite-events'); ?></th><th><?php esc_html_e('Message', 'rabbit-eventbrite-events'); ?></th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($logs, 0, 20) as $log) : ?>
                            <tr>
                                <td><?php echo esc_html($log['time'] ?? ''); ?></td>
                                <td><?php echo esc_html($log['status'] ?? ''); ?></td>
                                <td><?php echo esc_html($log['message'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <hr />

            <h2><?php esc_html_e('Shortcodes', 'rabbit-eventbrite-events'); ?></h2>
            <p><code>[eventbrite_events limit="6" columns="3" filters="true" button="Book Now"]</code></p>
            <p><code>[eventbrite_events checkout="modal" limit="6" columns="3"]</code></p>
            <p><code>[eventbrite_events location="Sunderland" category="Training" from="2026-06-01" to="2026-12-31"]</code></p>
            <p><?php esc_html_e('Filter attributes include:', 'rabbit-eventbrite-events'); ?> <code>filters</code>, <code>location</code>, <code>category</code>, <code>from</code>, <code>to</code>, <code>show_past</code>, <code>checkout</code>.</p>
        </div>
        <?php
    }

    public function clear_logs_action(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'rabbit-eventbrite-events'));
        }

        check_admin_referer('ree_clear_logs');
        delete_option(self::LOGS_OPTION);

        wp_safe_redirect(add_query_arg('ree_logs_cleared', '1', admin_url('options-general.php?page=rabbit-eventbrite-events')));
        exit;
    }

    public function sync_now_action(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to do this.', 'rabbit-eventbrite-events'));
        }

        check_admin_referer('ree_sync_now');
        $result = $this->sync_events();
        $arg = is_wp_error($result) ? 'ree_sync_error' : 'ree_synced';

        wp_safe_redirect(add_query_arg($arg, '1', admin_url('options-general.php?page=rabbit-eventbrite-events')));
        exit;
    }

    public function render_shortcode($atts): string
    {
        $settings = $this->get_settings();
        $atts = shortcode_atts([
            'limit'         => 6,
            'columns'       => 3,
            'show_past'     => 'false',
            'button'        => '',
            'empty_message' => __('No upcoming events found.', 'rabbit-eventbrite-events'),
            'filters'       => 'false',
            'location'      => '',
            'category'      => '',
            'from'          => '',
            'to'            => '',
            'checkout'      => '',
        ], $atts, 'eventbrite_events');

        $limit = max(1, min(100, absint($atts['limit'])));
        $columns = max(1, min(4, absint($atts['columns'])));
        $button_label = $atts['button'] !== '' ? sanitize_text_field($atts['button']) : $settings['button_label'];
        $checkout_mode = $this->resolve_checkout_mode((string) $atts['checkout'], 'card');
        $filters_enabled = filter_var($atts['filters'], FILTER_VALIDATE_BOOLEAN);
        $filters = $this->build_filters_from_atts($atts, $filters_enabled);
        $events = $this->get_synced_events($limit, $filters);

        wp_enqueue_style('rabbit-eventbrite-events');

        ob_start();
        if ($filters_enabled) {
            echo $this->render_filter_form($filters); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        if (empty($events)) {
            echo '<div class="ree-notice">' . esc_html($atts['empty_message']) . '</div>';
            return ob_get_clean();
        }
        ?>
        <div class="ree-events-grid" style="--ree-columns: <?php echo esc_attr((string) $columns); ?>;">
            <?php foreach ($events as $event_post) : ?>
                <?php echo $this->render_synced_event_card($event_post, $button_label, $checkout_mode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function build_filters_from_atts(array $atts, bool $allow_get): array
    {
        $filters = [
            'show_past' => filter_var($atts['show_past'], FILTER_VALIDATE_BOOLEAN),
            'location'  => sanitize_text_field((string) $atts['location']),
            'category'  => sanitize_text_field((string) $atts['category']),
            'from'      => $this->sanitize_date((string) $atts['from']),
            'to'        => $this->sanitize_date((string) $atts['to']),
        ];

        if ($allow_get) {
            $filters['location'] = isset($_GET['ree_location']) ? sanitize_text_field(wp_unslash($_GET['ree_location'])) : $filters['location'];
            $filters['category'] = isset($_GET['ree_category']) ? sanitize_text_field(wp_unslash($_GET['ree_category'])) : $filters['category'];
            $filters['from'] = isset($_GET['ree_from']) ? $this->sanitize_date(wp_unslash($_GET['ree_from'])) : $filters['from'];
            $filters['to'] = isset($_GET['ree_to']) ? $this->sanitize_date(wp_unslash($_GET['ree_to'])) : $filters['to'];
        }

        return $filters;
    }

    private function sanitize_date(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    private function render_filter_form(array $filters): string
    {
        $terms = get_terms([
            'taxonomy'   => self::TAX_CATEGORY,
            'hide_empty' => true,
        ]);

        ob_start();
        ?>
        <form class="ree-event-filters" method="get">
            <div class="ree-filter-field">
                <label for="ree-location-filter"><?php esc_html_e('Location', 'rabbit-eventbrite-events'); ?></label>
                <input id="ree-location-filter" type="text" name="ree_location" value="<?php echo esc_attr($filters['location']); ?>" placeholder="<?php esc_attr_e('Sunderland', 'rabbit-eventbrite-events'); ?>" />
            </div>

            <div class="ree-filter-field">
                <label for="ree-category-filter"><?php esc_html_e('Category', 'rabbit-eventbrite-events'); ?></label>
                <select id="ree-category-filter" name="ree_category">
                    <option value=""><?php esc_html_e('All categories', 'rabbit-eventbrite-events'); ?></option>
                    <?php if (!is_wp_error($terms)) : ?>
                        <?php foreach ($terms as $term) : ?>
                            <option value="<?php echo esc_attr($term->slug); ?>" <?php selected($filters['category'], $term->slug); ?>><?php echo esc_html($term->name); ?></option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <div class="ree-filter-field">
                <label for="ree-from-filter"><?php esc_html_e('From', 'rabbit-eventbrite-events'); ?></label>
                <input id="ree-from-filter" type="date" name="ree_from" value="<?php echo esc_attr($filters['from']); ?>" />
            </div>

            <div class="ree-filter-field">
                <label for="ree-to-filter"><?php esc_html_e('To', 'rabbit-eventbrite-events'); ?></label>
                <input id="ree-to-filter" type="date" name="ree_to" value="<?php echo esc_attr($filters['to']); ?>" />
            </div>

            <div class="ree-filter-actions">
                <button class="ree-event-button" type="submit"><?php esc_html_e('Filter events', 'rabbit-eventbrite-events'); ?></button>
                <a class="ree-event-button ree-event-button-secondary" href="<?php echo esc_url(remove_query_arg(['ree_location', 'ree_category', 'ree_from', 'ree_to'])); ?>"><?php esc_html_e('Reset', 'rabbit-eventbrite-events'); ?></a>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    private function get_synced_events(int $limit, array $filters): array
    {
        $settings = $this->get_settings();
        $meta_query = [
            [
                'key'   => '_ree_source_type',
                'value' => 'eventbrite',
            ],
        ];

        if (!empty($settings['organization_id'])) {
            $meta_query[] = [
                'key'   => '_ree_organization_id',
                'value' => $settings['organization_id'],
            ];
        }

        $query = new WP_Query([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => 500,
            'meta_key'       => '_ree_start_utc',
            'orderby'        => 'meta_value',
            'order'          => 'ASC',
            'no_found_rows'  => true,
            'meta_query'     => $meta_query,
        ]);

        $events = [];
        foreach ($query->posts as $post) {
            if (!$this->event_post_matches_filters($post, $filters)) {
                continue;
            }

            $events[] = $post;
            if (count($events) >= $limit) {
                break;
            }
        }

        wp_reset_postdata();
        return $events;
    }

    private function event_post_matches_filters(WP_Post $post, array $filters): bool
    {
        $start = get_post_meta($post->ID, '_ree_start_utc', true) ?: get_post_meta($post->ID, '_ree_start_local', true);
        $end = get_post_meta($post->ID, '_ree_end_utc', true) ?: get_post_meta($post->ID, '_ree_end_local', true);
        $timestamp = strtotime($end ?: $start);

        if (empty($filters['show_past']) && $timestamp && $timestamp < time()) {
            return false;
        }

        $start_timestamp = strtotime($start);
        if (!empty($filters['from']) && $start_timestamp) {
            $from_timestamp = strtotime($filters['from'] . ' 00:00:00');
            if ($from_timestamp && $start_timestamp < $from_timestamp) {
                return false;
            }
        }

        if (!empty($filters['to']) && $start_timestamp) {
            $to_timestamp = strtotime($filters['to'] . ' 23:59:59');
            if ($to_timestamp && $start_timestamp > $to_timestamp) {
                return false;
            }
        }

        if (!empty($filters['location'])) {
            $haystack = implode(' ', [
                get_post_meta($post->ID, '_ree_location_key', true),
                get_post_meta($post->ID, '_ree_venue_name', true),
                get_post_meta($post->ID, '_ree_venue_address', true),
                $post->post_title,
            ]);
            if (stripos($haystack, $filters['location']) === false) {
                return false;
            }
        }

        if (!empty($filters['category'])) {
            $category = $filters['category'];
            $category_meta = get_post_meta($post->ID, '_ree_category', true);
            if (!has_term($category, self::TAX_CATEGORY, $post) && strcasecmp($category_meta, $category) !== 0) {
                return false;
            }
        }

        return true;
    }

    private function render_synced_event_card(WP_Post $event_post, string $button_label, string $checkout_mode = 'link'): string
    {
        $image = get_post_meta($event_post->ID, '_ree_image_url', true);
        $eventbrite_url = get_post_meta($event_post->ID, '_ree_event_url', true);
        $eventbrite_id = $this->get_eventbrite_id_for_post($event_post->ID);
        $date = $this->format_synced_event_date($event_post->ID);
        $venue = $this->get_synced_event_venue($event_post->ID);
        $summary = has_excerpt($event_post) ? get_the_excerpt($event_post) : wp_trim_words(wp_strip_all_tags($event_post->post_content), 24);
        $local_url = get_permalink($event_post);
        $category = $this->get_event_category_label($event_post->ID);

        ob_start();
        ?>
        <article class="ree-event-card">
            <?php if (!empty($image)) : ?>
                <a class="ree-event-image" href="<?php echo esc_url($local_url); ?>">
                    <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr(get_the_title($event_post)); ?>" loading="lazy" />
                </a>
            <?php endif; ?>

            <div class="ree-event-content">
                <div class="ree-event-badges">
                    <span class="ree-badge"><?php esc_html_e('Eventbrite', 'rabbit-eventbrite-events'); ?></span>
                    <?php if (!empty($category)) : ?><span class="ree-badge ree-badge-light"><?php echo esc_html($category); ?></span><?php endif; ?>
                </div>
                <?php if (!empty($date)) : ?><p class="ree-event-date"><?php echo esc_html($date); ?></p><?php endif; ?>
                <h3 class="ree-event-title"><a href="<?php echo esc_url($local_url); ?>"><?php echo esc_html(get_the_title($event_post)); ?></a></h3>
                <?php if (!empty($venue)) : ?><p class="ree-event-venue"><?php echo esc_html($venue); ?></p><?php endif; ?>
                <?php if (!empty($summary)) : ?><p class="ree-event-summary"><?php echo esc_html($summary); ?></p><?php endif; ?>
                <div class="ree-event-actions">
                    <a class="ree-event-button" href="<?php echo esc_url($local_url); ?>"><?php esc_html_e('View details', 'rabbit-eventbrite-events'); ?></a>
                    <?php echo $this->render_ticket_action($eventbrite_id, $eventbrite_url, $button_label, $checkout_mode, 'card-' . $event_post->ID); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                </div>
            </div>
        </article>
        <?php
        return ob_get_clean();
    }

    private function render_ticket_action(string $eventbrite_id, string $url, string $label, string $mode, string $context): string
    {
        if ($url === '' && $eventbrite_id === '') {
            return '';
        }

        if ($mode !== 'modal' || $eventbrite_id === '') {
            return sprintf(
                '<a class="ree-event-button ree-event-button-secondary" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                esc_url($url),
                esc_html($label)
            );
        }

        wp_enqueue_script('rabbit-eventbrite-checkout');
        $button_id = 'ree-eb-modal-' . sanitize_html_class($context) . '-' . wp_rand(1000, 999999);
        $fallback_url = $url !== '' ? $url : 'https://www.eventbrite.com/e/' . rawurlencode($eventbrite_id);

        $script = "window.addEventListener('load',function(){var el=document.getElementById('" . esc_js($button_id) . "');if(!el||!window.EBWidgets){return;}window.EBWidgets.createWidget({widgetType:'checkout',eventId:'" . esc_js($eventbrite_id) . "',modal:true,modalTriggerElementId:'" . esc_js($button_id) . "'});});";
        wp_add_inline_script('rabbit-eventbrite-checkout', $script);

        return sprintf(
            '<a id="%1$s" class="ree-event-button ree-event-button-secondary" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
            esc_attr($button_id),
            esc_url($fallback_url),
            esc_html($label)
        );
    }

    private function resolve_checkout_mode(string $override, string $context): string
    {
        $settings = $this->get_settings();
        if (in_array($override, ['link', 'modal'], true)) {
            return $override;
        }

        return $context === 'single' ? $settings['single_checkout_mode'] : $settings['card_checkout_mode'];
    }

    public function append_single_event_details(string $content): string
    {
        if (!is_singular(self::POST_TYPE) || !in_the_loop() || !is_main_query()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        wp_enqueue_style('rabbit-eventbrite-events');

        $settings = $this->get_settings();
        $eventbrite_url = get_post_meta($post_id, '_ree_event_url', true);
        $eventbrite_id = $this->get_eventbrite_id_for_post($post_id);
        $checkout_mode = $this->resolve_checkout_mode('', 'single');
        $date = $this->format_synced_event_date($post_id);
        $venue = $this->get_synced_event_venue($post_id);
        $address = get_post_meta($post_id, '_ree_venue_address', true);
        $status = get_post_meta($post_id, '_ree_status', true);
        $category = $this->get_event_category_label($post_id);

        ob_start();
        ?>
        <section class="ree-single-details">
            <h2><?php esc_html_e('Event Details', 'rabbit-eventbrite-events'); ?></h2>
            <dl>
                <?php if ($date) : ?><div><dt><?php esc_html_e('Date', 'rabbit-eventbrite-events'); ?></dt><dd><?php echo esc_html($date); ?></dd></div><?php endif; ?>
                <?php if ($venue) : ?><div><dt><?php esc_html_e('Venue', 'rabbit-eventbrite-events'); ?></dt><dd><?php echo esc_html($venue); ?></dd></div><?php endif; ?>
                <?php if ($address && $address !== $venue) : ?><div><dt><?php esc_html_e('Address', 'rabbit-eventbrite-events'); ?></dt><dd><?php echo esc_html($address); ?></dd></div><?php endif; ?>
                <?php if ($category) : ?><div><dt><?php esc_html_e('Category', 'rabbit-eventbrite-events'); ?></dt><dd><?php echo esc_html($category); ?></dd></div><?php endif; ?>
                <?php if ($status) : ?><div><dt><?php esc_html_e('Eventbrite Status', 'rabbit-eventbrite-events'); ?></dt><dd><?php echo esc_html(ucfirst($status)); ?></dd></div><?php endif; ?>
            </dl>
            <div class="ree-event-actions">
                <?php echo $this->render_ticket_action($eventbrite_id, $eventbrite_url, $settings['button_label'], $checkout_mode, 'single-' . $post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>
        </section>
        <?php
        return $content . ob_get_clean();
    }

    public function sync_events()
    {
        $settings = $this->get_settings();

        if (empty($settings['private_token']) || empty($settings['organization_id'])) {
            $message = __('Missing Eventbrite private token or organisation ID.', 'rabbit-eventbrite-events');
            $this->store_last_sync('error', $message, 0);
            $this->add_log('error', $message);
            return new WP_Error('ree_missing_credentials', $message);
        }

        $fetched = $this->fetch_eventbrite_events($settings);
        if (is_wp_error($fetched)) {
            $message = $fetched->get_error_message();
            $this->store_last_sync('error', $message, 0);
            $this->add_log('error', $message);
            return $fetched;
        }

        $created = 0;
        $updated = 0;
        $seen_ids = [];

        foreach ($fetched as $event) {
            if (empty($event['id'])) {
                continue;
            }

            $event_id = preg_replace('/[^0-9]/', '', (string) $event['id']);
            if ($event_id === '') {
                continue;
            }

            $seen_ids[] = $event_id;
            $post_id = $this->find_event_post_by_eventbrite_id($event_id);
            $result = $this->upsert_event_post($event, $post_id, $settings['organization_id']);

            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        }

        $drafted = 0;
        if ($settings['remove_missing'] === 'yes') {
            $drafted = $this->draft_missing_events($seen_ids, $settings['organization_id']);
        }

        $message = sprintf(
            /* translators: 1: imported count, 2: updated count, 3: drafted count */
            __('Sync complete. Imported %1$d new events, updated %2$d events, drafted %3$d missing events.', 'rabbit-eventbrite-events'),
            $created,
            $updated,
            $drafted
        );

        $this->store_last_sync('success', $message, count($seen_ids));
        $this->add_log('success', $message);

        return [
            'created' => $created,
            'updated' => $updated,
            'drafted' => $drafted,
            'seen'    => count($seen_ids),
        ];
    }

    private function fetch_eventbrite_events(array $settings)
    {
        $events = [];
        $continuation = '';
        $limit = max(1, min(500, absint($settings['sync_limit'])));

        do {
            $per_page = min(50, $limit - count($events));
            if ($per_page < 1) {
                break;
            }

            $url = add_query_arg([
                'status'    => $settings['status'],
                'order_by'  => 'start_asc',
                'page_size' => $per_page,
                'expand'    => 'venue,logo,category,ticket_availability',
            ], 'https://www.eventbriteapi.com/v3/organizations/' . rawurlencode($settings['organization_id']) . '/events/');

            if ($continuation !== '') {
                $url = add_query_arg('continuation', $continuation, $url);
            }

            $response = wp_remote_get($url, [
                'timeout' => 20,
                'headers' => [
                    'Authorization' => 'Bearer ' . $settings['private_token'],
                    'Accept'        => 'application/json',
                ],
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if ($status_code < 200 || $status_code >= 300) {
                $api_message = is_array($data) && !empty($data['error_description']) ? $data['error_description'] : __('Eventbrite API request failed.', 'rabbit-eventbrite-events');
                return new WP_Error('ree_eventbrite_api_error', sprintf('Eventbrite API error %d: %s', $status_code, $api_message));
            }

            if (!is_array($data)) {
                return new WP_Error('ree_invalid_json', __('Eventbrite returned an invalid response.', 'rabbit-eventbrite-events'));
            }

            $page_events = isset($data['events']) && is_array($data['events']) ? $data['events'] : [];
            $events = array_merge($events, $page_events);

            $has_more = !empty($data['pagination']['has_more_items']);
            $continuation = isset($data['pagination']['continuation']) ? (string) $data['pagination']['continuation'] : '';
        } while ($has_more && $continuation !== '' && count($events) < $limit);

        return array_slice($events, 0, $limit);
    }

    private function upsert_event_post(array $event, int $existing_post_id, string $organization_id): string
    {
        $event_id = preg_replace('/[^0-9]/', '', (string) ($event['id'] ?? ''));
        $title = sanitize_text_field($event['name']['text'] ?? __('Untitled Eventbrite Event', 'rabbit-eventbrite-events'));
        $summary = sanitize_textarea_field($event['summary'] ?? '');
        $description_html = $event['description']['html'] ?? '';
        $description_text = $event['description']['text'] ?? '';
        $content = $description_html !== '' ? wp_kses_post($description_html) : wp_kses_post(wpautop($description_text ?: $summary));
        $url = esc_url_raw($event['url'] ?? '');
        $slug = sanitize_title($title . '-' . $event_id);

        $post_data = [
            'post_type'    => self::POST_TYPE,
            'post_status'  => 'publish',
            'post_title'   => $title,
            'post_content' => $content,
            'post_excerpt' => $summary,
            'post_name'    => $slug,
        ];

        if ($existing_post_id > 0) {
            $post_data['ID'] = $existing_post_id;
            $post_id = wp_update_post(wp_slash($post_data), true);
            $action = 'updated';
        } else {
            $post_id = wp_insert_post(wp_slash($post_data), true);
            $action = 'created';
        }

        if (is_wp_error($post_id) || empty($post_id)) {
            $this->add_log('error', sprintf('Could not save event %s: %s', $event_id, is_wp_error($post_id) ? $post_id->get_error_message() : 'Unknown error'));
            return 'failed';
        }

        $start_local = $event['start']['local'] ?? '';
        $start_utc = $event['start']['utc'] ?? '';
        $end_local = $event['end']['local'] ?? '';
        $end_utc = $event['end']['utc'] ?? '';
        $timezone = $event['start']['timezone'] ?? '';
        $status = sanitize_key((string) ($event['status'] ?? ''));
        $image_url = $this->extract_event_image_url($event);
        $venue_name = sanitize_text_field($event['venue']['name'] ?? '');
        $venue_address = sanitize_text_field($event['venue']['address']['localized_address_display'] ?? '');
        $category_name = sanitize_text_field($event['category']['name'] ?? '');

        $meta = [
            '_ree_eventbrite_id'   => $event_id,
            '_ree_organization_id' => $organization_id,
            '_ree_source_type'     => 'eventbrite',
            '_ree_source_label'    => 'Eventbrite',
            '_ree_event_url'       => $url,
            '_ree_image_url'       => $image_url,
            '_ree_start_local'     => sanitize_text_field($start_local),
            '_ree_start_utc'       => sanitize_text_field($start_utc),
            '_ree_end_local'       => sanitize_text_field($end_local),
            '_ree_end_utc'         => sanitize_text_field($end_utc),
            '_ree_timezone'        => sanitize_text_field($timezone),
            '_ree_status'          => $status,
            '_ree_venue_name'      => $venue_name,
            '_ree_venue_address'   => $venue_address,
            '_ree_location_key'    => trim($venue_name . ' ' . $venue_address),
            '_ree_category'        => $category_name,
            '_ree_synced_at'       => current_time('mysql'),
        ];

        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }

        if ($category_name !== '') {
            wp_set_object_terms($post_id, $category_name, self::TAX_CATEGORY, false);
        }

        return $action;
    }

    private function extract_event_image_url(array $event): string
    {
        if (!empty($event['logo']['original']['url'])) {
            return esc_url_raw($event['logo']['original']['url']);
        }

        if (!empty($event['logo']['url'])) {
            return esc_url_raw($event['logo']['url']);
        }

        return '';
    }

    private function find_event_post_by_eventbrite_id(string $eventbrite_id): int
    {
        $query = new WP_Query([
            'post_type'      => self::POST_TYPE,
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'   => '_ree_eventbrite_id',
                    'value' => $eventbrite_id,
                ],
            ],
        ]);

        return !empty($query->posts[0]) ? absint($query->posts[0]) : 0;
    }

    private function draft_missing_events(array $seen_eventbrite_ids, string $organization_id): int
    {
        $query = new WP_Query([
            'post_type'      => self::POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'   => '_ree_source_type',
                    'value' => 'eventbrite',
                ],
                [
                    'key'   => '_ree_organization_id',
                    'value' => $organization_id,
                ],
            ],
        ]);

        $drafted = 0;
        foreach ($query->posts as $post_id) {
            $event_id = get_post_meta($post_id, '_ree_eventbrite_id', true);
            if ($event_id && !in_array($event_id, $seen_eventbrite_ids, true)) {
                wp_update_post([
                    'ID'          => $post_id,
                    'post_status' => 'draft',
                ]);
                $drafted++;
            }
        }

        return $drafted;
    }

    private function format_synced_event_date(int $post_id): string
    {
        $start = get_post_meta($post_id, '_ree_start_local', true) ?: get_post_meta($post_id, '_ree_start_utc', true);
        $end = get_post_meta($post_id, '_ree_end_local', true) ?: get_post_meta($post_id, '_ree_end_utc', true);

        if ($start === '') {
            return '';
        }

        $start_timestamp = strtotime($start);
        $end_timestamp = $end !== '' ? strtotime($end) : false;

        if (!$start_timestamp) {
            return '';
        }

        $date = wp_date(get_option('date_format') . ' ' . get_option('time_format'), $start_timestamp);
        if ($end_timestamp) {
            $same_day = wp_date('Y-m-d', $start_timestamp) === wp_date('Y-m-d', $end_timestamp);
            $date .= $same_day
                ? ' - ' . wp_date(get_option('time_format'), $end_timestamp)
                : ' - ' . wp_date(get_option('date_format') . ' ' . get_option('time_format'), $end_timestamp);
        }

        return $date;
    }

    private function get_synced_event_venue(int $post_id): string
    {
        $venue = get_post_meta($post_id, '_ree_venue_name', true);
        $address = get_post_meta($post_id, '_ree_venue_address', true);

        if ($venue && $address && $venue !== $address) {
            return $venue . ', ' . $address;
        }

        return $venue ?: $address;
    }

    private function get_event_category_label(int $post_id): string
    {
        $terms = get_the_terms($post_id, self::TAX_CATEGORY);
        if (!is_wp_error($terms) && !empty($terms[0]->name)) {
            return $terms[0]->name;
        }

        return get_post_meta($post_id, '_ree_category', true) ?: '';
    }

    private function get_eventbrite_id_for_post(int $post_id): string
    {
        return preg_replace('/[^0-9]/', '', (string) get_post_meta($post_id, '_ree_eventbrite_id', true));
    }

    public function event_admin_columns(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'title') {
                $new['ree_event_date'] = __('Event Date', 'rabbit-eventbrite-events');
                $new['ree_status'] = __('Status', 'rabbit-eventbrite-events');
                $new['ree_eventbrite_id'] = __('Eventbrite ID', 'rabbit-eventbrite-events');
            }
        }

        return $new;
    }

    public function event_admin_column_content(string $column, int $post_id): void
    {
        if ($column === 'ree_event_date') {
            echo esc_html($this->format_synced_event_date($post_id));
        }

        if ($column === 'ree_status') {
            echo esc_html(ucfirst((string) get_post_meta($post_id, '_ree_status', true)));
        }

        if ($column === 'ree_eventbrite_id') {
            $url = get_post_meta($post_id, '_ree_event_url', true);
            $id = $this->get_eventbrite_id_for_post($post_id);
            if ($url) {
                echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($id) . '</a>';
            } else {
                echo esc_html($id);
            }
        }
    }

    public function event_sortable_columns(array $columns): array
    {
        $columns['ree_event_date'] = 'ree_event_date';
        return $columns;
    }

    public function event_admin_orderby(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== self::POST_TYPE) {
            return;
        }

        if ($query->get('orderby') === 'ree_event_date') {
            $query->set('meta_key', '_ree_start_utc');
            $query->set('orderby', 'meta_value');
        }
    }

    public function add_event_meta_boxes(): void
    {
        add_meta_box(
            'ree_eventbrite_details',
            __('Synced Eventbrite Details', 'rabbit-eventbrite-events'),
            [$this, 'render_event_details_meta_box'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_event_details_meta_box(WP_Post $post): void
    {
        $eventbrite_url = get_post_meta($post->ID, '_ree_event_url', true);
        $rows = [
            __('Eventbrite ID', 'rabbit-eventbrite-events') => $this->get_eventbrite_id_for_post($post->ID),
            __('Date', 'rabbit-eventbrite-events')          => $this->format_synced_event_date($post->ID),
            __('Venue', 'rabbit-eventbrite-events')         => $this->get_synced_event_venue($post->ID),
            __('Status', 'rabbit-eventbrite-events')        => get_post_meta($post->ID, '_ree_status', true),
            __('Last synced', 'rabbit-eventbrite-events')   => get_post_meta($post->ID, '_ree_synced_at', true),
        ];

        echo '<p>' . esc_html__('This event is synced from Eventbrite. New local/manual event creation is disabled.', 'rabbit-eventbrite-events') . '</p>';
        echo '<table class="widefat striped"><tbody>';
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>';
        }
        echo '</tbody></table>';

        if ($eventbrite_url) {
            echo '<p><a class="button button-secondary" href="' . esc_url($eventbrite_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Open on Eventbrite', 'rabbit-eventbrite-events') . '</a></p>';
        }
    }

    private function maybe_reschedule_cron(?array $settings = null): void
    {
        $settings = $settings ?: $this->get_settings();
        wp_clear_scheduled_hook(self::CRON_HOOK);

        if (!empty($settings['sync_frequency']) && $settings['sync_frequency'] !== 'manual') {
            wp_schedule_event(time() + 300, $settings['sync_frequency'], self::CRON_HOOK);
        }
    }

    private function store_last_sync(string $status, string $message, int $count): void
    {
        update_option(self::LAST_SYNC_OPTION, [
            'status'  => $status,
            'message' => $message,
            'count'   => $count,
            'time'    => current_time('mysql'),
        ], false);
    }

    private function get_last_sync_message(array $last_sync): string
    {
        if (empty($last_sync)) {
            return __('No sync has run yet.', 'rabbit-eventbrite-events');
        }

        $time = !empty($last_sync['time']) ? $last_sync['time'] . ' - ' : '';
        return $time . ($last_sync['message'] ?? __('Sync finished.', 'rabbit-eventbrite-events'));
    }

    private function add_log(string $status, string $message): void
    {
        $logs = get_option(self::LOGS_OPTION, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        array_unshift($logs, [
            'time'    => current_time('mysql'),
            'status'  => $status,
            'message' => $message,
        ]);

        update_option(self::LOGS_OPTION, array_slice($logs, 0, 50), false);
    }
}

Rabbit_Eventbrite_Events::instance();
register_activation_hook(__FILE__, ['Rabbit_Eventbrite_Events', 'activate']);
register_deactivation_hook(__FILE__, ['Rabbit_Eventbrite_Events', 'deactivate']);
