<?php
/*
Plugin Name: WordPress Convention Master Sponsor Sync
Plugin URI: https://github.com/sonicer105/wp-cm-sponsor-sync
Description: Synchronizes the names of sponsors tier members between Convention Master and WordPress automatically on a schedule.
Author: LinuxAvali
Text Domain: wpcm
Version: 0.1.0
Author URI: https://linuxpony.dev/
*/

/**
 * custom option and settings
 */
class WP_CM {
    /* @var array */
    private $options;

    /**
     * WP_CM constructor
     */
    function __construct() {
        add_action('init', [$this, 'load_settings_and_schedule']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_menu', [$this, 'options_page']);
        add_filter('cron_schedules', [$this, 'cron_custom_timespan']);
        add_action('wpcm_sync_with_cm_hook', [$this, 'wpcm_sync_with_cm']);
    }

    /**
     * Load in the options from the database and manage cron
     */
    function load_settings_and_schedule() {
        $this->options = get_option('wpcm_options', []);
        add_shortcode('sponsor-list', [$this, 'embed_names']);
        // Schedule an action if it's not already scheduled
        if (!wp_next_scheduled('wpcm_sync_with_cm_hook') && ($this->options['wpcm_field_sync_enabled'] ?? false)) {
            wp_schedule_event(time(), 'wpcm_custom_length', 'wpcm_sync_with_cm_hook');
        } else if(wp_next_scheduled('wpcm_sync_with_cm_hook') && !($this->options['wpcm_field_sync_enabled'] ?? false)) {
            wp_clear_scheduled_hook('wpcm_sync_with_cm_hook');
        }
    }

    /**
     * @param $schedules array
     * @return array
     */
    function cron_custom_timespan(array $schedules) {
        $timespan = ($this->options['wpcm_field_sync_interval'] ?? 60);
        $schedules['wpcm_custom_length'] = array(
            'interval' => $timespan * 60,
            'display' => sprintf(__('Every %s Minutes', 'wpcm'), $timespan)
        );
        return $schedules;
    }

    /**
     * Perform the sync with CM
     */
    function wpcm_sync_with_cm() {
        $service_url = $this->options['wpcm_field_service_url'] ?? null;
        if(empty($service_url)) return; // Badge Service URL, likely unconfigured.

        // Get Login Token
        $json = json_encode(['username' => $this->options['wpcm_field_username'], 'password' => $this->options['wpcm_field_password']]);
        $ch1 = curl_init($service_url . 'token');
        curl_setopt($ch1, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch1, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch1, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch1, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($json)
        ));
        curl_exec($ch1);
        $raw_token_response = curl_exec($ch1);
        if(curl_errno($ch1)) {
            update_option('wp-cm-sync-error', curl_error($ch1));
            curl_close($ch1);
            return;
        }
        curl_close($ch1);

        // Parse Token
        $token_response = json_decode($raw_token_response, true);
        if(empty($token_response['token'] ?? '')) {
            update_option('wp-cm-sync-error', $raw_token_response);
            return;
        }

        // Get CM data
        $ch2 = curl_init($service_url . 'reports/sponsors');
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, array(
            'Authorization: Bearer ' . $token_response['token']
        ));
        curl_exec($ch2);
        $raw_data_response = curl_exec($ch2);
        if(curl_errno($ch2)) {
            update_option('wp-cm-sync-error', curl_error($ch2));
            curl_close($ch2);
            return;
        }
        curl_close($ch2);

        // Parse and save the data

        $data_response = json_decode($raw_data_response, true);
        $to_save = [];

        foreach ($data_response as $registrant) {
            if (!isset($registrant['displayName'])) continue;
            if (isset($to_save[$registrant['currentMembershipType']])){
                array_push($to_save[$registrant['currentMembershipType']], $registrant['displayName']);
            } else {
                $to_save[$registrant['currentMembershipType']] = [$registrant['displayName']];
            }
        }
        update_option('wp-cm-sync-result', $to_save);
    }

    /**
     * Register setting group, section and fields
     */
    function settings_init() {
        register_setting('wpcm', 'wpcm_options', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_options']
        ]);

        add_settings_section(
            'wpcm_section_general',
            __('General Settings', 'wpcm'),
            [$this, 'section_description_callback'],
            'wpcm',
            ['description' => __('General configuration for the plugin.', 'wpcm')]
        );

        add_settings_field(
            'wpcm_field_service_url',
            __('CM API Service URL', 'wpcm') . ' <span class="required" title="Required">*</span>',
            [$this, 'field_input_cb'],
            'wpcm',
            'wpcm_section_general',
            [
                'label_for' => 'wpcm_field_service_url',
                'class' => 'wpcm_row',
                'description' => __('The path your Convention Master API is at. You must enter the full path.<br />e.g. <kbd>https://reg.convention.org/lp-api/</kbd>', 'wpcm'),
                'required' => true
            ]
        );

        add_settings_field(
            'wpcm_field_username',
            __( 'CM API Username', 'wpcm' ) . ' <span class="required" title="Required">*</span>',
            [$this, 'field_input_cb'],
            'wpcm',
            'wpcm_section_general',
            [
                'label_for' => 'wpcm_field_username',
                'class' => 'wpcm_row',
                'description' => __('Your Convention Master API username', 'wpcm'),
                'required' => true
            ]
        );

        add_settings_field(
            'wpcm_field_password',
            __( 'CM API Password', 'wpcm' ) . ' <span class="required" title="Required">*</span>',
            [$this, 'field_input_cb'],
            'wpcm',
            'wpcm_section_general',
            [
                'label_for' => 'wpcm_field_password',
                'class' => 'wpcm_row',
                'description' => __('Your Convention Master API password', 'wpcm'),
                'field_type' => 'password',
                'required' => true
            ]
        );

        add_settings_field(
            'wpcm_field_sync_interval',
            __( 'Sync Interval (Minutes)', 'wpcm' ) . ' <span class="required" title="Required">*</span>',
            [$this, 'field_input_cb'],
            'wpcm',
            'wpcm_section_general',
            [
                'label_for' => 'wpcm_field_sync_interval',
                'class' => 'wpcm_row',
                'description' => __('The frequency in which data is retrieved from the API.<br />min: <kbd>5</kbd>, max: <kbd>1440</kbd>', 'wpcm'),
                'field_type' => 'number',
                'min' => 5,
                'max' => 1440,
                'required' => true
            ]
        );

        add_settings_field(
            'wpcm_field_sync_enabled',
            __( 'Sync Enabled', 'wpcm' ),
            [$this, 'field_checkbox_cb'],
            'wpcm',
            'wpcm_section_general',
            [
                'label_for' => 'wpcm_field_sync_enabled',
                'label_text' => __('Enable', 'wpcm'),
                'class' => 'wpcm_row',
                'description' => __('If data retrieval is enabled.', 'wpcm')
            ]
        );
    }

    /**
     * Settings section rendering callback.
     *
     * @param array $args keys: id, title, callback, before_section, after_section, section_class ...
     */
    function section_description_callback(array $args) {
        ?>
        <p id="<?php echo esc_attr($args['id']); ?>"><?php echo $args['description']; ?></p>
        <?php
    }

    /**
     * Input field callback
     *
     * @param array $args passed in directly from add_settings_field
     */
    function field_input_cb(array $args) {
        ?>
        <input id="<?php echo esc_attr($args['label_for']); ?>"
            name="wpcm_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
            value="<?php echo $this->options[$args['label_for']] ?? '' ?>"
            type="<?php echo $args['field_type'] ?? 'text' ?>"
            <?php if($args['min'] ?? -1 >= 0) echo 'min="' . $args['min'] . '"' ?>
            <?php if($args['max'] ?? -1 >= 0) echo 'max="' . $args['max'] . '"' ?>
            <?php if($args['required'] ?? false) echo 'required="required"' ?>
        />
        <p class="description">
            <?php echo $args['description']; ?>
        </p>
        <?php
    }

    /**
     * Checkbox field callback
     *
     * @param array $args passed in directly from add_settings_field
     */
    function field_checkbox_cb(array $args) {
        ?>
        <label>
        <input id="<?php echo esc_attr($args['label_for']); ?>"
               name="wpcm_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               value="1"
               type="Checkbox"

               <?php if($this->options[$args['label_for']] ?? false) echo 'checked="checked"' ?>
        /><?php echo $args['label_text'] ?? '' ?>
        </label>
        <p class="description">
            <?php echo $args['description']; ?>
        </p>
        <?php
    }

    /**
     * Registers the page
     */
    function options_page() {
        add_menu_page(
            'WP CM Options',
            'WP CM Options',
            'manage_options',
            'wpcm',
            [$this, 'options_page_html']
        );
    }

    /**
     * @param $data array
     * @return array
     */
    function sanitize_options(array $data) {
        $has_errors = false;

        // Step 1: Validation

        if(empty($data['wpcm_field_service_url'])){
            add_settings_error('wpcm_options', 'bad_service_url', __('Service URL is required', 'wpcm'), 'error');
            $has_errors = true;
        }
        if(!filter_var($data['wpcm_field_service_url'], FILTER_VALIDATE_URL)){
            add_settings_error('wpcm_options', 'bad_service_url', __('Service URL must be a fully qualified (e.g. https://example.com or http://localhost)', 'wpcm'), 'error');
            $has_errors = true;
        }
        if(empty($data['wpcm_field_username'])){
            add_settings_error('wpcm_options', 'bad_username', __('Username is required', 'wpcm'), 'error');
            $has_errors = true;
        }
        if(empty($data['wpcm_field_password'])){
            add_settings_error('wpcm_options', 'bad_password', __('Password is required', 'wpcm'), 'error');
            $has_errors = true;
        }
        if(empty($data['wpcm_field_sync_interval'])){
            add_settings_error('wpcm_options', 'bad_sync_interval', __('Sync Interval is required', 'wpcm'), 'error');
            $has_errors = true;
        }
        if(!is_numeric($data['wpcm_field_sync_interval'])){
            add_settings_error('wpcm_options', 'bad_sync_interval', __('Sync Interval must be a number', 'wpcm'), 'error');
            $has_errors = true;
        }
        if(is_numeric($data['wpcm_field_sync_interval']) && ($data['wpcm_field_sync_interval'] > 1440 || $data['wpcm_field_sync_interval'] < 5)){
            add_settings_error('wpcm_options', 'bad_sync_interval', __('Sync Interval must be between 5 and 1440', 'wpcm'), 'error');
            $has_errors = true;
        }

        if($has_errors) return $this->options; // Discard changes on error.

        // Step 2: Sanitization

        if(substr($data['wpcm_field_service_url'], -1) !== '/'){
            $data['wpcm_field_service_url'] .= '/';
        }

        add_settings_error( 'wpcm_options', 'wpcm_message', __( 'Settings Saved', 'wpcm' ), 'updated' );

        return $data;
    }

    /**
     * Top level menu callback function
     */
    function options_page_html() {
        if (!current_user_can('manage_options')) {
            return;
        }

        settings_errors( 'wpcm_options' );
        ?>
        <style>
            .cm-data {
                width: 100%;
                height: 300px;
                overflow: scroll;
                border: 1px solid;
            }
        </style>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post" id="settings-forum">
                <?php
                settings_fields('wpcm');
                do_settings_sections('wpcm');
                submit_button('Save');
                ?>
            </form>
            <script>document.getElementById("settings-forum").reset()</script>
            Last Error:
            <pre class="cm-data"><?php print_r(get_option('wp-cm-sync-error')); ?></pre>
            Data:
            <pre class="cm-data"><?php print_r(get_option('wp-cm-sync-result')); ?></pre>
        </div>
        <?php
    }

    /**
     * @param $param array
     * @return string
     */
    function embed_names(array $param) {
        $badge_type = $param['tier'] ?? '';
        if (empty($badge_type)) return 'Error: You must provide a "tier" parameter!';
        $badge_info = get_option('wp-cm-sync-result');
        if (!isset($badge_info[$badge_type])) return '<span>None Yet...</span>';
        $formatted_badges = [];
        foreach ($badge_info[$badge_type] as $badge) {
            array_push($formatted_badges, '<span>' . $badge . '</span>');
        }
        return '<div class="sponsor-container">' . implode(' ', $formatted_badges) . '</div>';
    }
}

new WP_CM();