<?php
/*
  Plugin Name:  Limit Posts Per User
  Description:  Allows an admin to set limits on how many posts a user can create
  Version:      1.0
  Author:       Neil Kulbiski
  Author URI:   http://kulbiski.com

 * Copyright 2018 Neil Kulbiski
 * This software is provided 'as is'. Any liability will be limited to the original purchase price.
 * This software is without any warranty or implied warranty.
 */

Class k_limit_posts_per_user {

    private $_post_limit,
            $_user_ID,
            $_current_user_post_count,
            $_user_at_post_limit,
            $_user_at_or_over_post_limit,
            $_user_has_post_limit,
            $_post_type;

    /**
     * 
     * @param string $post_type Slug of the post type to limit
     */
    public function __construct($post_type = 'post') {

        $this->_post_type = $post_type;

        //add the input
        add_action('show_user_profile', array($this, 'add_post_limit_section'));
        add_action('edit_user_profile', array($this, 'add_post_limit_section'));

        //save the input
        add_action('personal_options_update', array($this, 'save_post_limit_fields'));
        add_action('edit_user_profile_update', array($this, 'save_post_limit_fields'));

        //check the post limit when before saving a post
        add_filter('wp_insert_post_data', array($this, 'check_post_limit'));

        //don't show buttons to add a new post if over the post limit
        add_action('init', array($this, 'remove_add_new_buttons'));

        //show an admin message if over the post limit
        add_action('admin_notices', array($this, 'show_post_limit_message'));
    }

    /**
     * 
     * @return int The post limit
     */
    public function get_post_limit() {

        if (!$this->_post_limit) {
            $user_ID = $this->get_user_ID();
            $this->_post_limit = get_user_meta($user_ID, 'k_post_limit', true);
        }
        return $this->_post_limit;
    }

    /**
     * 
     * @return int The user ID
     */
    public function get_user_ID() {
        if (!$this->_user_ID) {
            $this->_user_ID = get_current_user_id();
        }
        return $this->_user_ID;
    }

    /**
     * 
     * @return int The number of posts by the user
     */
    public function get_user_post_count() {

        if (!$this->_current_user_post_count) {

            $this->_current_user_post_count = $this->_get_user_post_count_by_user($this->get_user_ID());
        }
        return $this->_current_user_post_count;
    }

    /**
     * 
     * @return bool If the user has a post limit
     */
    public function get_user_has_post_limit() {
        if (!$this->_user_has_post_limit) {

            $post_limit = get_user_meta($this->get_user_ID(), 'k_post_limit', true);
            if (!$post_limit || $post_limit === 0) {
                $this->_user_has_post_limit = false;
            } else {
                $this->_user_has_post_limit = true;
            }
        }
        return $this->_user_has_post_limit;
    }

    /**
     * 
     * @return bool If the user is exactly at the post limit
     */
    public function get_user_at_post_limit() {
        if (!$this->_user_at_post_limit) {

            $this->_user_at_post_limit = ($this->get_user_has_post_limit() && $this->_post_limit === $this->_current_user_post_count);
        }
        return $this->_user_at_post_limit;
    }

    /**
     * 
     * @return bool If the user is at of over the post limit
     */
    public function get_user_at_or_over_post_limit() {

        if (!$this->_user_at_or_over_post_limit) {

            $this->_user_at_or_over_post_limit = ($this->get_user_has_post_limit() && $this->get_user_post_count() >= $this->get_post_limit());
        }
        return $this->_user_at_or_over_post_limit;
    }

    /**
     * Get the post count for user ID
     * 
     * @param int $user_ID The user ID
     * @return int The number of posts authored by the user
     */
    private function _get_user_post_count_by_user($user_ID) {
        $query = new WP_Query(array(
            'author' => $user_ID,
            'post_type' => $this->_post_type)
        );

        return $query->post_count;
    }

    /**
     * 
     * Add a section to the user edit screen
     * 
     * @param user $user The user object
     */
    public function add_post_limit_section($user) {

        //don't show unless when non-admins are editng their own profile
        if (!current_user_can('edit_users')) {
            return;
        }

        //get the current setting, or the default of 0 (unlimited posts)
        $post_limit = 0;
        if (get_user_meta($user->ID, 'k_post_limit', true)) {
            $post_limit = get_user_meta($user->ID, 'k_post_limit', true);
        }
        ?>
        <h3>Limit Posts</h3>
        <table class="form-table">
            <tbody>
                <tr class="user-k-post-limit-wrap">
                    <th><label for="k-post-limit">Post Limit (0 for no limit)</label></th>
                    <td>
                        <input type="number" name="k-post-limit" id="k-post-limit" value="<?php echo($post_limit); ?>" class="num" min="0">
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * Save the post limit field or display an error
     * 
     * @param int $user_ID The user ID
     */
    public function save_post_limit_fields($user_ID) {

        //prevent non-admins from increasing their own limit
        if (!current_user_can('edit_users')) {
            return;
        }

        $post_limit = filter_input(INPUT_POST, 'k-post-limit', FILTER_VALIDATE_INT);

        if ($post_limit !== false && current_user_can('edit_user', $user_ID)) {
            update_user_meta($user_ID, 'k_post_limit', $post_limit);
            //reset the flag so a notice will show if they hit their new limit
            delete_user_meta($user_ID, 'k_post_limit_notice_shown');
        } else {
            //show an error
            add_action('user_profile_update_errors', function ($errors) {
                $errors->add('post_limit_error', __('<strong>ERROR</strong>: The post limit isn’t correct.'));
            }, 10, 1);
        }

        //display a notice if the user has already exceeded their new limit
        if ($post_limit && $post_limit < $this->_get_user_post_count_by_user($user_ID) && current_user_can('edit_user', $user_ID)) {
            add_action('user_profile_update_errors', function ($errors) {
                $errors->add('post_limit_error', __('<strong>Notice</strong>: User is already over the limit'));
            }, 10, 1);
        }
    }

    /**
     * prevent direct post
     * 
     * @param object $data The post data
     * @return object The post data
     */
    public function check_post_limit($data) {

        //don't do anything if it's not the selected post type
        if ($data['post_type'] !== $this->_post_type) {
            return $data;
        }

        //allow trashing a post
        if ($data['post_status'] === 'trash') {
            //reset the flag so a notice will show if they hit their limit again
            delete_user_meta($this->get_user_ID(), 'k_post_limit_notice_shown');
            return $data;
        }

        //prevent adding new post if over the limit
        if ($this->get_user_at_or_over_post_limit()) {
            wp_die('You are over you post limit.');
        } else {
            return $data;
        }
    }

    /**
     * hide the add new post buttons
     */
    public function remove_add_new_buttons() {

        if ($this->get_user_at_or_over_post_limit()) {
            $post = get_post_type_object($this->_post_type);

            $post->cap->create_posts = false;
            $post->cap->publish_posts = false;
        }
    }

    /**
     * show a notice when they reach their limit
     */
    public function show_post_limit_message() {

        if (get_user_meta($this->get_user_ID(), 'k_post_limit_notice_shown', true) === '' && $this->get_user_at_or_over_post_limit()) {
            $class = 'notice notice-warning is-dismissible';
            $message = __('Notice: You have reached your post limit and can not add more posts.', 'k_limit_posts_per_user');

            printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
            //add flag so the notice will only show once
            add_user_meta($this->get_user_ID(), 'k_post_limit_notice_shown', true);
        }
    }

}

$k_limit_posts_per_user = new k_limit_posts_per_user();