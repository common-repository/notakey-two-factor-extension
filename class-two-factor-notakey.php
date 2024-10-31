<?php

/**
 * Extension class for Two_Factor_Provider
 *
 * @package Two_Factor_Notakey
 */

require_once(plugin_dir_path(__FILE__) . '/vendor/autoload.php');
require_once(plugin_dir_path(__FILE__) . '/nas-api.php');

class Two_Factor_Notakey extends Two_Factor_Provider
{
    private object $_ntkas;

    private const ONBOARDING_STATUS_STARTED = 1;
    private const ONBOARDING_STATUS_NONE = 2;
    private const ONBOARDING_STATUS_DONE = 3;

    private const KEY_ONBOARDING_STATUS = '_notakey_onboarding_state';
    private const KEY_ONBOARDING_MOBILE = '_notakey_onboarding_mobile';
    private const KEY_ONBOARDING_PASS = '_notakey_onboarding_pass';

    private const TOKEN_META_KEY = '_notakey_token';

    /**
     * Class constructor.
     *
     * @since 0.1-dev
     */
    protected function __construct()
    {
        add_action('two_factor_user_options_' . __CLASS__, array($this, 'user_options'));
        add_action('personal_options_update', array($this, 'user_options_update'));
        add_action('edit_user_profile_update', array($this, 'user_options_update'));
        add_action('admin_notices', array($this, 'admin_notices'));
        return parent::__construct();
    }

    /**
     * Displays an admin notice when backup user not onboarded.
     *
     * @since 0.1-dev
     */
    public function admin_notices()
    {
        $user = wp_get_current_user();

        // Return if the provider is not enabled.
        if (!in_array(__CLASS__, Two_Factor_Core::get_enabled_providers_for_user($user->ID), true)) {
            return;
        }

        // Return if if user is already provisioned
        if ($this->is_available_for_user($user)) {
            return;
        }

?>
        <div class="error">
            <p>
                <span>
                    <?php
                    echo wp_kses(
                        sprintf(
                            /* translators: %s: URL for code regeneration */
                            __('Two-Factor: Notakey Authenticator mobile device has not been registered. Register device <a href="%s">here</a>!', Ntk_Two_Factor_Core::td()),
                            esc_url(get_edit_user_link($user->ID) . '')
                        ),
                        array('a' => array('href' => true))
                    );
                    ?>
                    <span>
            </p>
        </div>
    <?php
    }


    /**
     * Ensures only one instance of this class exists in memory at any one time.
     *
     * @since 0.1-dev
     */
    public static function get_instance()
    {
        static $instance;
        $class = __CLASS__;
        if (!is_a($instance, $class)) {
            $instance = new $class();
        }
        return $instance;
    }

    private function ntkas()
    {
        if (!isset($this->_ntkas)) {
            $this->_ntkas = Ntk_Two_Factor_Core::ntkas();
            // new NasApi($this->_api_url, $this->_api_client_id, $this->_api_client_secret, $this->_service_id, $this);
        }
        return $this->_ntkas;
    }

    static private function is_admin_mode()
    {
        $user = wp_get_current_user();

        if ($user && in_array('administrator', $user->roles)) {
            return true;
        }

        return false;
    }

    static private function token_key(string $scopes)
    {
        return self::TOKEN_META_KEY . '_' . md5($scopes);
    }

    public function store_token(string $token, string $scopes)
    {
        if (!is_multisite()) {
            return update_option(self::token_key($scopes), $token);
        }

        return update_metadata('blog', get_current_blog_id(), self::token_key($scopes), $token);
    }

    public function clear_token(string $scopes)
    {
        if (!is_multisite()) {
            return delete_option(self::token_key($scopes));
        }

        return delete_metadata('blog', get_current_blog_id(), self::token_key($scopes));
    }

    public function fetch_token(string $scopes)
    {
        if (!is_multisite()) {
            return get_option(self::token_key($scopes));
        }

        return get_metadata('blog', get_current_blog_id(), self::token_key($scopes), true);
    }

    /**
     * Returns the name of the provider.
     *
     * @since 0.1-dev
     */
    public function get_label()
    {
        return _x('Notakey Authenticator', 'Provider Label', Ntk_Two_Factor_Core::td());
    }

    private function get_ntk_username(WP_User $user)
    {
        if (!isset($user->ID)) {
            throw new Exception("get_ntk_username missing WP_User");
        }

        return $user->data->user_login;
    }

    /**
     *
     *
     * @param  WP_User $user WP_User object of the logged-in user.
     * @return string UUID of authentication request
     */

    private function create_auth_request(WP_User $user)
    {
        $title = Ntk_Two_Factor_Core::get_config('request_title', 'Wordpress authentication');
        $message = Ntk_Two_Factor_Core::get_config('request_message', 'Proceed with login as user %user%?');

        $authreq = array(
            "username" => $this->get_ntk_username($user),
            "action" => $title,
            "description" => str_replace('%user%', $this->get_ntk_username($user), $message),
            "ttl_seconds" => Ntk_Two_Factor_Core::get_config('request_ttl', 300),
        );

        // TODO: Add authentication profile support

        $r = $this->ntkas()->auth($authreq);

        return $r->uuid;
    }

    /**
     * Prints the form that prompts the user to authenticate.
     *
     * @since 0.1-dev
     *
     * @param WP_User $user WP_User object of the logged-in user.
     */
    public function authentication_page($user)
    {
        // $resend = false;
        // $uuid = self::get_auth_request_uuid($user->ID);

        // if ($this->get_auth_status($uuid) != 1) {
        //     $resend = true;
        // }

        if (isset($_POST['wp-auth-ntk-uuid'])) {
            // this is a resend request
            // $resend = true;
        }

        $uuid = $this->create_auth_request($user);

        if (!$uuid) {
            trigger_error(__('Unable to create Notakey authentication request', Ntk_Two_Factor_Core::td()));
        }

        // TODO: Fix path
        wp_enqueue_script('ntk-script', plugins_url('ntk.js', __FILE__), ['wp-util', 'jquery']);

        require_once ABSPATH . '/wp-admin/includes/template.php';
    ?>
        <p><strong><?php esc_html_e('Notakey Authentication', Ntk_Two_Factor_Core::td()); ?></strong></p>
        <input type="hidden" name="wp-auth-ntk-uuid" id="wp-auth-ntk-uuid" value="<?php echo esc_attr($uuid); ?>">
        <div id="ntk_auth_wait">
            <p><?php esc_html_e('Sending authentication request...', Ntk_Two_Factor_Core::td()); ?></p>
        </div>
        <div id="ntk_auth_pending" style="display: none;">
            <p><?php esc_html_e('Authentication request is waiting for approval.', Ntk_Two_Factor_Core::td()); ?></p>
        </div>
        <div id="ntk_auth_success" style="display: none;">
            <p><?php esc_html_e('Authentication confirmed, logging you in...', Ntk_Two_Factor_Core::td()); ?></p>
        </div>
        <div id="ntk_auth_denied" style="display: none;">
            <p><?php esc_html_e('Authentication request rejected.', Ntk_Two_Factor_Core::td()); ?></p>
        </div>
        <div id="ntk_auth_timeout" style="display: none;">
            <p><?php esc_html_e('Authentication request expired', Ntk_Two_Factor_Core::td()); ?></p>
        </div>
        <div id="ntk_auth_error" style="display: none;">
            <p><?php esc_html_e('Authentication encountered unexpected error', Ntk_Two_Factor_Core::td()); ?></p>
        </div>
        <div id="ntk_resend_auth" style="display: none;">
            <p><?php esc_html_e('Resend authentication request?', Ntk_Two_Factor_Core::td()); ?></p>
            <?php submit_button(__('Do It.', Ntk_Two_Factor_Core::td()), 'primary', 'submit-resend'); ?>
        </div>
        <?php
    }

    private function get_auth_request($uuid)
    {
        return $this->ntkas()->status($uuid);
    }

    private function is_authenticated(WP_User $user, string $uuid)
    {
        if (!isset($user->ID)) {
            return false;
        }

        if (!isset($user->data->user_login)) {
            return false;
        }

        if (empty($uuid)) {
            return false;
        }

        try {
            // TODO: this throws
            $r = $this->get_auth_request($uuid);
        } catch (Exception $ex) {
            // 404 or other bad status
            return false;
        }

        $authenticated = false;

        if ($r->response_type == "ApproveRequest" && $this->get_ntk_username($user) == $r->username) {
            $authenticated = true;
        }

        return $authenticated;
    }

    public function get_auth_status($uuid)
    {
        if (!$uuid) {
            return 0;
        }

        try {
            $r = $this->get_auth_request($uuid);

            if (!$r || $r->expired) {
                return 2;
            }

            if ($r->response_type == "DenyRequest") {
                return 4;
            }

            if ($r->response_type == "ApproveRequest") {
                return 3;
            }
        } catch (Exception $ex) {
            // 404 or other bad status
            Ntk_Two_Factor_Core::log("Auth status error: " . $ex->getMessage(), $ex);
            return 0;
        }

        return 1;
    }

    /**
     * Validates the users input token.
     *
     * In this class we just return true.
     *
     * @since 0.1-dev
     *
     * @param WP_User $user WP_User object of the logged-in user.
     * @return boolean
     */
    public function validate_authentication($user)
    {
        return $this->is_authenticated($user, $_POST["wp-auth-ntk-uuid"]);
    }

    /**
     * Whether this Two Factor provider is configured and available for the user specified.
     *
     * @since 0.1-dev
     *
     * @param WP_User $user WP_User object of the logged-in user.
     * @return boolean
     */
    public function is_available_for_user($user)
    {
        if (!Ntk_Two_Factor_Core::ready()) {
            return false;
        }

        $ob_status = $this->get_umeta($user->ID, self::KEY_ONBOARDING_STATUS, self::ONBOARDING_STATUS_NONE);

        if ($ob_status != self::ONBOARDING_STATUS_NONE) {
            return true;
        }

        $exists = false;
        try {
            $exists = $this->ntkas()->user_exists($this->get_ntk_username($user));
        } catch (Exception $ex) {
            Ntk_Two_Factor_Core::log("User query error: " . $ex->getMessage(), $ex);
        }

        // In case we don't have local state for this user
        return $exists;
    }

    /**
     * Uses the Google Charts API to build a QR Code for onboarding
     *
     * @return string A URL to use as an img src to display the QR code
     */

    private function get_onboarding_qr_code()
    {
        // TODO: Use native QR generation library
        $onboarding_url = $this->ntkas()->get_onboarding_qr();
        return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . urlencode($onboarding_url);
    }

    private function has_onboarded(WP_User $user)
    {
        return $this->onboarding_status($user) == self::ONBOARDING_STATUS_DONE;
    }

    private function can_self_edit()
    {
        if (current_user_can('manage_options') || Ntk_Two_Factor_Core::get_config('enable_user_self_service', false)) {
            return true;
        }

        return false;
    }

    /**
     * Inserts markup at the end of the user profile field for this provider.
     *
     * @since 0.1-dev
     *
     * @param WP_User $user WP_User object of the logged-in user.
     */
    public function user_options($user)
    {
        if (!isset($user->ID)) {
            return;
        }

        $active_error = '';
        try {
            $s = $this->ntkas()->service();
        } catch (Exception $e) {
            $active_error = 'Exception when connecting to service: ' . $e->getMessage();
        }

        if (!Ntk_Two_Factor_Core::ready()) {
            $active_error  = 'Required parameters missing in Notakey MFA';
        }

        if ($active_error) {
        ?>
            <div style="border: 1px solid; background-color: #FFBABA; color: #D8000C; margin: 10px auto; padding: 15px 10px 15px 50px;">
                <p>
                    <?php esc_html_e('Notakey Two-Factor provider is not configured, device registration not possible.', Ntk_Two_Factor_Core::td()); ?>
                    <br />
                    <code>
                        <?php esc_html_e($active_error, Ntk_Two_Factor_Core::td()); ?>
                    </code>
                </p>
            </div>
        <?php
            return;
        }

        wp_nonce_field('user_two_factor_notakey_options', '_nonce_user_two_factor_notakey_options', false);

        $s = $this->ntkas()->service();

        $has_sms = false;
        $has_pass = false;
        $secret = '';
        $is_editable = false;

        foreach ($s->onboarding_requirements as $or) {
            // TODO: Resolve compatibility issues
            // if ($or->type == "UserpassOnboardingRequirement") {
            if (strpos($or->proof_creation_uri, "UserpassOnboardingRequirement") > 0) {
                $has_pass = true;
                $secret = $this->get_user_pass($user->ID);
            }

            // if ($or->type == "SmsOnboardingRequirement") {
            if (strpos($or->proof_creation_uri, "SmsOnboardingRequirement") > 0) {
                $has_sms = true;
                $is_editable = $this->can_self_edit();
            }
        }
        ?>
        <div id="notakey-two-factor-options">
            <p>
                <?php
                echo esc_html(
                    _x('Push based authentication with key stored on mobile phone.', Ntk_Two_Factor_Core::td()),
                );
                ?>
            </p>
            <?php
            if ($this->onboarding_status($user) == self::ONBOARDING_STATUS_NONE) {
            ?>
                <input type="submit" class="button" name="two-factor-notakey-submit" value="<?php esc_attr_e('Start onboarding', Ntk_Two_Factor_Core::td()); ?>" />
            <?php
            } else if ($this->onboarding_status($user) == self::ONBOARDING_STATUS_DONE) {
            ?>
                <p>
                    <?php esc_html_e('Reset will remove all your active Notakey Authenticator devices below:', Ntk_Two_Factor_Core::td()); ?>
                </p>
                <p>
                <ul>
                    <?php
                    $ntk_user = $this->ntkas()->get_user($this->get_ntk_username($user));

                    $devices = $this->ntkas()->get_user_devices($ntk_user->keyname);

                    foreach ($devices as $d) {
                    ?>
                        <li><?php echo esc_html("{$d->manufacturer} {$d->model} ({$d->app_version}, {$d->os_locale})"); ?></li>
                    <?php
                    }
                    ?>
                </ul>
                </p>
                <p>
                    <input type="submit" class="button" name="two-factor-notakey-submit" value="<?php esc_attr_e('Reset onboarding', Ntk_Two_Factor_Core::td()); ?>" />
                </p>
            <?php
            } else if ($this->onboarding_status($user) == self::ONBOARDING_STATUS_STARTED) {
            ?>
                <p>
                    <?php esc_html_e('Scan code below with Notakey Authenticator and complete onboarding in the application.', Ntk_Two_Factor_Core::td()); ?>
                </p>
                <div style="width: 100%;">
                    <div style="display: flex;">
                        <div style="width: 205px;">
                            <p>
                                <img src="<?php echo esc_url($this->get_onboarding_qr_code()); ?>" id="notakey-two-factor-qrcode" />
                            </p>
                        </div>
                        <div style="width: auto;">
                            <?php if ($has_sms) { ?>
                                <p>
                                    <?php
                                    if ($is_editable) {
                                    ?>
                                        <label for="two-factor-notakey-mobile">
                                            <?php esc_html_e("Mobile number:", Ntk_Two_Factor_Core::td()); ?> <input type="tel" name="two-factor-notakey-mobile" id="two-factor-notakey-mobile" class="input" value="<?php echo esc_attr($this->get_user_phone($user->ID)); ?>" size="20" pattern="[0-9]*">
                                        </label>
                                    <?php
                                    } else {
                                    ?>
                                        <label for="two-factor-notakey-pass">
                                            <?php esc_html_e("Mobile number:", Ntk_Two_Factor_Core::td()); ?> <code><?php echo esc_html($this->get_user_phone($user->ID)); ?></code>
                                        </label>
                                    <?php
                                    }
                                    ?>
                                </p>
                            <?php } ?>
                            <?php if ($has_pass) { ?>
                                <p>
                                    <label for="two-factor-notakey-user">
                                        <?php esc_html_e("Notakey username:", Ntk_Two_Factor_Core::td()); ?> <code><?php echo esc_html($this->get_ntk_username($user)); ?></code>
                                    </label>
                                </p>
                                <p>
                                    <input type="hidden" name="two-factor-notakey-pass" value="<?php echo esc_attr($secret); ?>" />
                                    <label for="two-factor-notakey-pass">
                                        <?php esc_html_e("Notakey password:", Ntk_Two_Factor_Core::td()); ?> <code><?php echo esc_html($secret); ?></code>
                                    </label>
                                </p>
                            <?php } ?>
                            <p>
                                <?php
                                if ($is_editable) {
                                ?>
                                    <input type="submit" class="button" name="two-factor-notakey-submit" value="<?php esc_attr_e('Update settings', Ntk_Two_Factor_Core::td()); ?>" />
                                <?php
                                }
                                ?>
                            </p>
                        </div>

                    </div>
                </div>
            <?php
            }
            ?>
            <!-- TODO: Add authentication test here -->
        </div>
<?php
    }

    private function onboarding_status(WP_User $user)
    {
        $ob_status = false;

        if ($this->ntkas()->user_exists($this->get_ntk_username($user))) {
            if ($this->ntkas()->can_be_onboarded($this->get_ntk_username($user))) {
                // User has free device seats available
                $ob_status = $this->get_umeta($user->ID, self::KEY_ONBOARDING_STATUS, self::ONBOARDING_STATUS_STARTED);
            } else {
                $ob_status = self::ONBOARDING_STATUS_DONE;
            }
        }

        if (!$ob_status) {
            $ob_status = self::ONBOARDING_STATUS_NONE;
        }

        return $ob_status;
    }

    private function set_onboarding_status($user_id, $status)
    {
        return update_user_meta($user_id, self::KEY_ONBOARDING_STATUS, $status);
    }

    private function set_user_phone($user_id, $phone)
    {
        return update_user_meta($user_id, self::KEY_ONBOARDING_MOBILE, $phone);
    }

    private function get_umeta($user_id, $key, $default)
    {
        $v = get_user_meta($user_id, $key, true);

        if ($v === false) {
            $v = $default;
        }

        return $v;
    }

    private function get_user_phone($user_id)
    {
        return $this->get_umeta($user_id, self::KEY_ONBOARDING_MOBILE, "");
    }

    private function set_user_pass($user_id, $pass)
    {
        return update_user_meta($user_id, self::KEY_ONBOARDING_PASS, $pass);
    }

    private function getpass()
    {
        return wp_generate_password(10, true, false);
    }

    private function get_user_pass($user_id)
    {
        return $this->get_umeta($user_id, self::KEY_ONBOARDING_PASS, $this->getpass());
    }

    private function get_ntk_user(WP_User $user)
    {
        if (!isset($user->ID)) {
            throw new Exception("User missing");
        }

        return array(
            "username" => $this->get_ntk_username($user),
            "password" => $this->get_user_pass($user->ID),
            "full_name" => $user->data->display_name,
            "email" => $user->data->user_email,
            "main_phone_number" => $this->get_user_phone($user->ID),
            "groups" => $user->roles
        );
    }

    private function get_user($user_id)
    {
        $user = get_userdata($user_id);

        if (!$user) {
            throw new Exception("User missing");
        }

        return $user;
    }

    private function remove_user_devices($user_id)
    {
        $user = $this->get_user($user_id);

        if ($this->ntkas()->user_exists($this->get_ntk_username($user))) {
            return $this->ntkas()->reset_user_devices($this->get_ntk_username($user));
        }
        return true;
    }

    private function create_or_update_user($user_id)
    {
        $user = $this->get_user($user_id);
        return $this->ntkas()->sync_user($this->get_ntk_username($user), $this->get_ntk_user($user));
    }

    /**
     * Save the options specified in `::user_two_factor_options()`
     *
     * @param integer $user_id The user ID whose options are being updated.
     *
     * @return void
     */
    public function user_options_update($user_id)
    {
        // $notices = array();
        // $errors  = array();
        if (isset($_POST['_nonce_user_two_factor_notakey_options'])) {
            check_admin_referer('user_two_factor_notakey_options', '_nonce_user_two_factor_notakey_options');

            if (!empty($_POST["two-factor-notakey-submit"])) {
                $op = sanitize_text_field($_POST['two-factor-notakey-submit']);

                if ($op == _x('Update settings', Ntk_Two_Factor_Core::td())) {
                    if ($this->can_self_edit()) {
                        if (!empty($_POST['two-factor-notakey-pass'])) {
                            $this->set_user_pass($user_id, sanitize_text_field($_POST['two-factor-notakey-pass']));
                        }

                        if (!empty($_POST['two-factor-notakey-mobile'])) {
                            $this->set_user_phone($user_id, sanitize_text_field($_POST['two-factor-notakey-mobile']));
                        }
                    }
                }

                if ($op == _x('Start onboarding', Ntk_Two_Factor_Core::td())) {
                    $this->set_user_pass($user_id, $this->getpass());
                }

                if ($op == _x('Start onboarding', Ntk_Two_Factor_Core::td()) || $op == _x('Update settings', Ntk_Two_Factor_Core::td())) {
                    if ($this->create_or_update_user($user_id)) {
                        $this->set_onboarding_status($user_id, self::ONBOARDING_STATUS_STARTED);
                    }
                }

                if ($op == _x('Reset onboarding', Ntk_Two_Factor_Core::td())) {
                    if ($this->remove_user_devices($user_id)) {
                        $this->set_onboarding_status($user_id, self::ONBOARDING_STATUS_NONE);
                    }
                }
            }
        }
    }
}
