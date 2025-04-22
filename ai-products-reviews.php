<?php
/**
 * Plugin Name:       AI Product Reviews
 * Plugin URI:        https://github.com/hassanzn2023/ai-product-reviews
 * Description:       Automatically generates product reviews using AI by fetching product title and description. Supports scheduled generation and custom prompts.
 * Version:           2.4.0
 * Author:            Hassan Zein
 * Author URI:        http://skillyweb.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-reviews
 * Domain Path:       /languages
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

defined('ABSPATH') or die('No script kiddies please!');

// Define plugin constants
define('AI_REVIEWS_VERSION', '2.4.0');
define('AI_REVIEWS_PLUGIN_FILE', __FILE__);
define('AI_REVIEWS_PLUGIN_BASENAME', plugin_basename(AI_REVIEWS_PLUGIN_FILE));
define('AI_REVIEWS_PLUGIN_DIR', plugin_dir_path(AI_REVIEWS_PLUGIN_FILE));
define('AI_REVIEWS_PLUGIN_URL', plugin_dir_url(AI_REVIEWS_PLUGIN_FILE));

/**
 * Check dependencies on activation or admin load.
 */
function ai_reviews_check_dependencies() {
    // Check for WooCommerce
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'ai_reviews_woocommerce_missing_notice');
        // Deactivate self
        deactivate_plugins(AI_REVIEWS_PLUGIN_BASENAME);
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
        return false; // Stop further execution if WC not found
    }
    return true;
}
add_action('admin_init', 'ai_reviews_check_dependencies');

/**
 * Display WooCommerce missing notice.
 */
function ai_reviews_woocommerce_missing_notice() {
    ?>
    <div class="notice notice-error is-dismissible">
        <p><?php esc_html_e('AI Product Reviews requires WooCommerce to be installed and activated. The plugin has been deactivated.', 'ai-reviews'); ?></p>
    </div>
    <?php
}

/**
 * Load text domain for translations.
 */
add_action('plugins_loaded', 'ai_reviews_load_textdomain');
function ai_reviews_load_textdomain() {
    load_plugin_textdomain('ai-reviews', false, basename(dirname(AI_REVIEWS_PLUGIN_FILE)) . '/languages');
}

/**
 * Schedule daily event on plugin activation.
 */
register_activation_hook(AI_REVIEWS_PLUGIN_FILE, 'ai_reviews_activation');
function ai_reviews_activation() {
    // Check dependencies first
    if (!ai_reviews_check_dependencies()) {
        return; // Stop activation if dependencies not met
    }

    // Schedule daily event
    if (!wp_next_scheduled('ai_reviews_daily_event')) {
        // Schedule to run daily, starting approximately 24 hours from now to avoid immediate heavy load
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'ai_reviews_daily_event');
    }
    ai_reviews_log("AI Reviews: Scheduled daily event on activation.");

    // Set default options if they don't exist
    add_option('ai_reviews_prompts', array_fill(0, 5, ''));
    add_option('ai_reviews_name_prompt', 'Give me a random name for a product reviewer.');
    add_option('ai_reviews_auto_generate', 'no');
    add_option('ai_reviews_time_between_reviews', 5); // Default interval
    add_option('ai_reviews_daily_rate', 10);
    add_option('ai_reviews_label_ai_generated', 'no');
}

/**
 * Clear scheduled event on plugin deactivation.
 */
register_deactivation_hook(AI_REVIEWS_PLUGIN_FILE, 'ai_reviews_deactivation');
function ai_reviews_deactivation() {
    wp_clear_scheduled_hook('ai_reviews_daily_event');
    wp_clear_scheduled_hook('generate_and_save_review_at_time'); // Clear any individually scheduled reviews too
    ai_reviews_log("AI Reviews: Unscheduled events on deactivation.");
}

/**
 * Hook for the daily event to generate reviews.
 */
add_action('ai_reviews_daily_event', 'generate_daily_reviews');
function generate_daily_reviews() {
    ai_reviews_log("AI Reviews: Running daily review generation task.");
    if (!class_exists('WooCommerce')) {
         ai_reviews_log("AI Reviews: WooCommerce not active. Skipping daily generation.");
         return;
    }

    $auto_generate = get_option('ai_reviews_auto_generate', 'no');
    if ($auto_generate !== 'yes') {
        ai_reviews_log("AI Reviews: Auto reviews are disabled. Skipping daily generation.");
        return;
    }

    $daily_rate = max(1, intval(get_option('ai_reviews_daily_rate', 10)));
    $review_length = intval(get_option('ai_reviews_review_length', 150)); // Increased default length slightly
    $interval_minutes = max(1, intval(get_option('ai_reviews_time_between_reviews', 5))); // Min 1 minute interval

    generate_reviews_in_intervals($daily_rate, $review_length, $interval_minutes);
}

/**
 * Add settings menu to the admin dashboard.
 */
add_action('admin_menu', 'ai_reviews_menu');
function ai_reviews_menu() {
    add_menu_page(
        __('AI Product Reviews Settings', 'ai-reviews'),
        __('AI Reviews', 'ai-reviews'),
        'manage_options',
        'ai-product-reviews',
        'ai_reviews_settings_page', // Changed callback function name
        'dashicons-testimonial',
        80 // Position
    );
    add_submenu_page(
        'ai-product-reviews',
        __('Test AI Reviews', 'ai-reviews'),
        __('Test Generation', 'ai-reviews'), // Changed submenu title
        'manage_options',
        'ai-reviews-test',
        'ai_reviews_test_page'
    );
     add_submenu_page(
        'ai-product-reviews',
        __('Scheduled Reviews', 'ai-reviews'),
        __('Scheduled', 'ai-reviews'), // Changed submenu title
        'manage_options',
        'ai-reviews-scheduled',
        'ai_reviews_scheduled_page'
    );
}

/**
 * Enqueue styles and scripts for the plugin admin pages.
 */
add_action('admin_enqueue_scripts', 'ai_reviews_enqueue_scripts');
function ai_reviews_enqueue_scripts($hook_suffix) {
    // Only load on our plugin pages
    $allowed_hooks = [
        'toplevel_page_ai-product-reviews',
        'ai-reviews_page_ai-reviews-test',
        'ai-reviews_page_ai-reviews-scheduled'
    ];

    if (in_array($hook_suffix, $allowed_hooks)) {
        wp_enqueue_style('ai-reviews-styles', AI_REVIEWS_PLUGIN_URL . 'ai-reviews-styles.css', [], AI_REVIEWS_VERSION);
        wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css'); // Update jQuery UI version

        wp_enqueue_script('jquery-ui-autocomplete');
        // Enqueue custom JS if needed in the future
        // wp_enqueue_script('ai-reviews-admin-js', AI_REVIEWS_PLUGIN_URL . 'admin.js', ['jquery', 'jquery-ui-autocomplete'], AI_REVIEWS_VERSION, true);
        // wp_localize_script('ai-reviews-admin-js', 'aiReviews', ['ajax_url' => admin_url('admin-ajax.php')]);
    }
}

/**
 * Render the settings page.
 */
function ai_reviews_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'ai-reviews'));
    }

    // Save settings
    if (isset($_POST['submit']) && check_admin_referer('ai_reviews_settings_nonce')) {
        update_option('ai_reviews_api_key', sanitize_text_field($_POST['ai_reviews_api_key']));
        update_option('ai_reviews_prompts', isset($_POST['ai_reviews_prompts']) ? array_map('sanitize_textarea_field', $_POST['ai_reviews_prompts']) : []);
        update_option('ai_reviews_name_prompt', sanitize_textarea_field($_POST['ai_reviews_name_prompt']));
        update_option('ai_reviews_auto_generate', isset($_POST['ai_reviews_auto_generate']) ? 'yes' : 'no');
        update_option('ai_reviews_time_between_reviews', max(1, intval($_POST['ai_reviews_time_between_reviews'])));
        update_option('ai_reviews_daily_rate', max(1, intval($_POST['ai_reviews_daily_rate'])));
        update_option('ai_reviews_review_length', max(50, intval($_POST['ai_reviews_review_length']))); // Added review length setting
        update_option('ai_reviews_label_ai_generated', isset($_POST['ai_reviews_label_ai_generated']) ? 'yes' : 'no');

        // Reschedule if settings changed related to cron
        wp_clear_scheduled_hook('ai_reviews_daily_event');
         wp_clear_scheduled_hook('generate_and_save_review_at_time'); // Clear any pending single reviews
        if (get_option('ai_reviews_auto_generate') === 'yes') {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'ai_reviews_daily_event');
             ai_reviews_log("AI Reviews: Daily event rescheduled after settings save.");
             add_settings_error('ai_reviews_settings', 'settings_updated', __('Settings saved and daily generation rescheduled.', 'ai-reviews'), 'updated');
        } else {
             add_settings_error('ai_reviews_settings', 'settings_updated', __('Settings saved. Auto-generation is disabled.', 'ai-reviews'), 'updated');
        }

        // Display success message via Settings API
        settings_errors('ai_reviews_settings');

    }

    // Retrieve settings
    $api_key = get_option('ai_reviews_api_key', '');
    $prompts = get_option('ai_reviews_prompts', array_fill(0, 5, ''));
    $name_prompt = get_option('ai_reviews_name_prompt', 'Give me a random name for a product reviewer.');
    $auto_generate = get_option('ai_reviews_auto_generate', 'no');
    $time_between_reviews = intval(get_option('ai_reviews_time_between_reviews', 5));
    $daily_rate = intval(get_option('ai_reviews_daily_rate', 10));
    $review_length = intval(get_option('ai_reviews_review_length', 150));
    $label_ai_generated = get_option('ai_reviews_label_ai_generated', 'no');

    ?>
    <div class="wrap ai-reviews-admin-wrap">
        <h1><?php esc_html_e('AI Product Reviews Settings', 'ai-reviews'); ?></h1>

        <form method="post" action="">
            <?php wp_nonce_field('ai_reviews_settings_nonce'); ?>

            <h2 class="nav-tab-wrapper">
                <a href="#" class="nav-tab nav-tab-active"><?php esc_html_e('General Settings', 'ai-reviews'); ?></a>
            </h2>

            <table class="form-table">
                <tbody>
                    <!-- API Key -->
                    <tr>
                        <th scope="row">
                            <label for="ai_reviews_api_key"><?php esc_html_e('OpenAI API Key', 'ai-reviews'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="ai_reviews_api_key" name="ai_reviews_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e('Enter your OpenAI API key. Get one from', 'ai-reviews'); ?> <a href="https://platform.openai.com/account/api-keys" target="_blank">OpenAI</a>.</p>
                        </td>
                    </tr>

                     <!-- Prompts -->
                    <tr>
                        <th scope="row"><?php esc_html_e('Review Prompts', 'ai-reviews'); ?></th>
                        <td>
                            <?php for ($i = 0; $i < 5; $i++): ?>
                                <label for="ai_reviews_prompt_<?php echo $i; ?>" style="display: block; margin-bottom: 5px;"><?php printf(esc_html__('Prompt %d', 'ai-reviews'), $i + 1); ?></label>
                                <textarea id="ai_reviews_prompt_<?php echo $i; ?>" name="ai_reviews_prompts[]" rows="3" class="large-text" placeholder="<?php esc_attr_e('e.g., Write a positive review about {{product_title}} focusing on its quality. Mention {{product_description}} briefly.', 'ai-reviews'); ?>"><?php echo esc_textarea($prompts[$i] ?? ''); ?></textarea>
                                <?php if ($i < 4) echo '<br><br>'; ?>
                            <?php endfor; ?>
                            <p class="description"><?php esc_html_e('Enter prompts used to generate reviews. Use {{product_title}} and {{product_description}}. One prompt will be chosen randomly for each review.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>

                     <!-- Name Prompt -->
                    <tr>
                        <th scope="row">
                            <label for="ai_reviews_name_prompt"><?php esc_html_e('Reviewer Name Prompt', 'ai-reviews'); ?></label>
                        </th>
                        <td>
                             <textarea id="ai_reviews_name_prompt" name="ai_reviews_name_prompt" rows="2" class="large-text"><?php echo esc_textarea($name_prompt); ?></textarea>
                            <p class="description"><?php esc_html_e('Prompt used to generate a random reviewer name.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>

                    <!-- Review Length -->
                     <tr>
                        <th scope="row">
                            <label for="ai_reviews_review_length"><?php esc_html_e('Approx. Review Length (tokens)', 'ai-reviews'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="ai_reviews_review_length" name="ai_reviews_review_length" value="<?php echo esc_attr($review_length); ?>" min="50" max="500" step="10" class="small-text">
                            <p class="description"><?php esc_html_e('Approximate number of tokens (words ~ 0.75 tokens) for the generated review.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>

                    <!-- Label AI Reviews -->
                     <tr>
                        <th scope="row"><?php esc_html_e('Label AI Reviews', 'ai-reviews'); ?></th>
                        <td>
                            <label for="ai_reviews_label_ai_generated">
                                <input type="checkbox" id="ai_reviews_label_ai_generated" name="ai_reviews_label_ai_generated" value="yes" <?php checked($label_ai_generated, 'yes'); ?>>
                                <?php esc_html_e('Append "(AI Generated Review)" to the end of each automatically generated review.', 'ai-reviews'); ?>
                            </label>
                             <p class="description"><?php esc_html_e('Recommended for transparency.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>

                     <!-- Auto Generation Settings Header -->
                     <tr><th colspan="2"><h2><?php esc_html_e('Automatic Review Generation', 'ai-reviews'); ?></h2></th></tr>

                     <!-- Enable Auto Reviews -->
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Auto Generation', 'ai-reviews'); ?></th>
                        <td>
                            <label for="ai_reviews_auto_generate">
                                <input type="checkbox" id="ai_reviews_auto_generate" name="ai_reviews_auto_generate" value="yes" <?php checked($auto_generate, 'yes'); ?>>
                                <?php esc_html_e('Enable daily automatic review generation using WP-Cron.', 'ai-reviews'); ?>
                            </label>
                             <p class="description">
                                 <?php
                                 $next_run = wp_next_scheduled('ai_reviews_daily_event');
                                 if ($auto_generate === 'yes' && $next_run) {
                                     printf(
                                         esc_html__('Next daily generation scheduled for: %s (in %s)', 'ai-reviews'),
                                         '<strong>' . get_date_from_gmt(date('Y-m-d H:i:s', $next_run), get_option('date_format') . ' ' . get_option('time_format')) . '</strong>',
                                         '<strong>' . human_time_diff($next_run) . '</strong>'
                                     );
                                 } elseif ($auto_generate === 'yes') {
                                     esc_html_e('Daily generation is enabled but might not be scheduled yet. Save settings to ensure it\'s scheduled.', 'ai-reviews');
                                 } else {
                                     esc_html_e('Daily generation is currently disabled.', 'ai-reviews');
                                 }
                                 ?>
                             </p>
                        </td>
                    </tr>

                    <!-- Daily Rate -->
                    <tr>
                        <th scope="row">
                            <label for="ai_reviews_daily_rate"><?php esc_html_e('Reviews per Day', 'ai-reviews'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="ai_reviews_daily_rate" name="ai_reviews_daily_rate" value="<?php echo esc_attr($daily_rate); ?>" min="1" class="small-text">
                            <p class="description"><?php esc_html_e('Maximum number of reviews to generate automatically each day.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>

                     <!-- Interval -->
                    <tr>
                        <th scope="row">
                            <label for="ai_reviews_time_between_reviews"><?php esc_html_e('Interval Between Reviews (minutes)', 'ai-reviews'); ?></label>
                        </th>
                        <td>
                            <input type="number" id="ai_reviews_time_between_reviews" name="ai_reviews_time_between_reviews" value="<?php echo esc_attr($time_between_reviews); ?>" min="1" class="small-text">
                            <p class="description"><?php esc_html_e('Time delay between generating each review within the daily batch. Helps distribute server load and appear more natural.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>

                </tbody>
            </table>

            <?php submit_button(esc_html__('Save Settings', 'ai-reviews')); ?>
        </form>
    </div>
    <?php
}

/**
 * Render the Test Generation page.
 */
function ai_reviews_test_page() {
     if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'ai-reviews'));
    }

    $name_prompt = get_option('ai_reviews_name_prompt', 'Give me a random name for a product reviewer.');
    $api_key = get_option('ai_reviews_api_key', '');

     if (empty($api_key)) {
         add_settings_error('ai_reviews_test', 'api_key_missing', __('Please enter your OpenAI API Key on the Settings page first.', 'ai-reviews'), 'error');
     }

    ?>
    <div class="wrap ai-reviews-admin-wrap">
        <h1><?php esc_html_e('Test AI Review Generation', 'ai-reviews'); ?></h1>
        <?php settings_errors('ai_reviews_test'); ?>

        <form id="ai-reviews-test-form" method="post" action="">
            <?php wp_nonce_field('ai_reviews_test_nonce_action', 'ai_reviews_test_nonce_name'); ?>

            <table class="form-table">
                 <tbody>
                    <tr>
                        <th scope="row"><label for="product_search"><?php esc_html_e('Select Product', 'ai-reviews'); ?></label></th>
                        <td>
                            <input type="text" id="product_search" name="product_search" class="regular-text" placeholder="<?php esc_attr_e('Start typing product name...', 'ai-reviews'); ?>" required>
                            <input type="hidden" id="selected_product_id" name="selected_product_id" required>
                             <p class="description"><?php esc_html_e('Search for the product you want to generate a test review for.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>
                     <tr>
                        <th scope="row"><label for="ai_reviews_test_prompt"><?php esc_html_e('Custom Review Prompt', 'ai-reviews'); ?></label></th>
                        <td>
                            <textarea id="ai_reviews_test_prompt" name="ai_reviews_test_prompt" rows="4" class="large-text" placeholder="<?php esc_attr_e('Write a review about {{product_title}} focusing on...', 'ai-reviews'); ?>" required></textarea>
                            <p class="description"><?php esc_html_e('Use {{product_title}} and {{product_description}}. This prompt overrides the saved ones for this test.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ai_reviews_test_name_prompt"><?php esc_html_e('Custom Name Prompt', 'ai-reviews'); ?></label></th>
                        <td>
                            <textarea id="ai_reviews_test_name_prompt" name="ai_reviews_test_name_prompt" rows="2" class="large-text"><?php echo esc_textarea($name_prompt); ?></textarea>
                             <p class="description"><?php esc_html_e('Prompt to generate the reviewer name for this test.', 'ai-reviews'); ?></p>
                        </td>
                    </tr>
                     <tr>
                        <th scope="row"><label for="ai_reviews_test_rating"><?php esc_html_e('Rating', 'ai-reviews'); ?></label></th>
                        <td>
                            <select id="ai_reviews_test_rating" name="ai_reviews_test_rating">
                                <?php for ($i = 5; $i >= 1; $i--): ?>
                                <option value="<?php echo $i; ?>" <?php selected($i, 4); ?>>
                                    <?php printf(esc_html(_n('%d star', '%d stars', $i, 'ai-reviews')), $i); ?>
                                </option>
                                <?php endfor; ?>
                            </select>
                        </td>
                    </tr>
                 </tbody>
            </table>

            <?php submit_button(esc_html__('Generate Test Review', 'ai-reviews'), 'primary', 'generate_test_review', true, empty($api_key) ? ['disabled' => 'disabled'] : null); ?>
        </form>

         <div id="test-review-result" style="margin-top: 20px; display: none;">
             <h3><?php esc_html_e('Generated Review:', 'ai-reviews'); ?></h3>
             <div id="test-review-output" class="notice notice-success inline">
                 <p><strong><?php esc_html_e('Author:', 'ai-reviews'); ?></strong> <span class="author-name"></span></p>
                 <p><strong><?php esc_html_e('Rating:', 'ai-reviews'); ?></strong> <span class="review-rating"></span> <?php esc_html_e('stars', 'ai-reviews'); ?></p>
                 <p><strong><?php esc_html_e('Review:', 'ai-reviews'); ?></strong></p>
                 <div class="generated-review" style="white-space: pre-wrap; background: #f9f9f9; padding: 10px; border: 1px solid #eee;"></div>
             </div>
             <p>
                 <button id="publish-review-button" class="button button-primary"><?php esc_html_e('Publish This Review', 'ai-reviews'); ?></button>
                 <span id="publish-status" style="margin-left: 10px;"></span>
             </p>
         </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            // Autocomplete
            $("#product_search").autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: ajaxurl,
                        dataType: "json",
                        data: {
                            action: "ai_reviews_search_products", // Prefixed action
                            term: request.term,
                            nonce: '<?php echo wp_create_nonce("ai_reviews_search_nonce"); ?>' // Add nonce
                        },
                        success: function(data) {
                            if (data.success) {
                                response(data.data);
                            } else {
                                response([]);
                                console.error("Product search failed:", data.data);
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                             console.error("AJAX error during product search:", textStatus, errorThrown);
                             response([]);
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $("#selected_product_id").val(ui.item.id);
                    $("#product_search").val(ui.item.label); // Keep label in search field
                    return false; // Prevent default value insertion
                },
                 // Optional: Display something nice while searching
                 search: function() { $(this).addClass('ui-autocomplete-loading'); },
                 open: function() { $(this).removeClass('ui-autocomplete-loading'); }
            });

            // Handle Test Form Submission via AJAX (prevents page reload, better UX)
            $('#ai-reviews-test-form').on('submit', function(e) {
                e.preventDefault(); // Stop normal form submission
                $('#test-review-result').slideUp(); // Hide previous result
                $('#generate_test_review').prop('disabled', true).val('<?php esc_attr_e('Generating...', 'ai-reviews'); ?>'); // Disable button

                 $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'ai_reviews_generate_test_review', // New AJAX action
                        nonce: '<?php echo wp_create_nonce("ai_reviews_test_nonce_action"); ?>', // Reuse nonce
                        product_id: $('#selected_product_id').val(),
                        review_prompt: $('#ai_reviews_test_prompt').val(),
                        name_prompt: $('#ai_reviews_test_name_prompt').val(),
                        rating: $('#ai_reviews_test_rating').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#test-review-result .author-name').text(response.data.author);
                            $('#test-review-result .review-rating').text(response.data.rating);
                            $('#test-review-result .generated-review').html(response.data.review.replace(/\n/g, '<br>')); // Use html() and handle newlines
                            $('#test-review-result').slideDown();
                            $('#publish-status').empty();
                        } else {
                            alert('<?php esc_attr_e('Error generating review:', 'ai-reviews'); ?> ' + response.data);
                            $('#test-review-result').hide();
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        alert('<?php esc_attr_e('AJAX Error:', 'ai-reviews'); ?> ' + textStatus + ' - ' + errorThrown);
                         $('#test-review-result').hide();
                    },
                    complete: function() {
                        $('#generate_test_review').prop('disabled', false).val('<?php esc_attr_e('Generate Test Review', 'ai-reviews'); ?>'); // Re-enable button
                    }
                });
            });


            // Handle Publish Button Click
            $('#publish-review-button').on('click', function() {
                var $button = $(this);
                if (confirm('<?php esc_attr_e('Are you sure you want to publish this generated review to the selected product?', 'ai-reviews'); ?>')) {
                    var product_id = $('#selected_product_id').val();
                    // Get raw text content for security, let server handle sanitization
                    var review_content = $('#test-review-result .generated-review').text();
                    var review_author = $('#test-review-result .author-name').text();
                    var review_rating = $('#test-review-result .review-rating').text();
                    var $status = $('#publish-status');

                    $button.prop('disabled', true);
                    $status.text('<?php esc_attr_e('Publishing...', 'ai-reviews'); ?>').removeClass('success error');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'ai_reviews_publish_review', // Prefixed action
                            nonce: '<?php echo wp_create_nonce("ai_reviews_publish_nonce"); ?>', // Different nonce for publish
                            product_id: product_id,
                            review_content: review_content,
                            review_author: review_author,
                            review_rating: review_rating,
                        },
                        success: function(response) {
                            if (response.success) {
                                $status.text('<?php esc_attr_e('Review published successfully!', 'ai-reviews'); ?>').addClass('success');
                                // Optionally hide the publish button after success
                                // $button.hide();
                            } else {
                                $status.text('<?php esc_attr_e('Failed to publish:', 'ai-reviews'); ?> ' + response.data).addClass('error');
                                $button.prop('disabled', false); // Re-enable on failure
                            }
                        },
                        error: function(jqXHR, textStatus, errorThrown) {
                            $status.text('<?php esc_attr_e('AJAX error during publishing:', 'ai-reviews'); ?> ' + textStatus).addClass('error');
                            $button.prop('disabled', false); // Re-enable on failure
                        }
                        // complete: function() {  } // No need to re-enable if hidden on success
                    });
                }
            });
        });
        </script>
    </div>
    <?php
}

/**
 * AJAX handler for generating a test review.
 */
add_action('wp_ajax_ai_reviews_generate_test_review', 'ai_reviews_generate_test_review_ajax');
function ai_reviews_generate_test_review_ajax() {
    check_ajax_referer('ai_reviews_test_nonce_action', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'ai-reviews'), 403);
    }

     $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
     $review_prompt = isset($_POST['review_prompt']) ? sanitize_textarea_field($_POST['review_prompt']) : '';
     $name_prompt = isset($_POST['name_prompt']) ? sanitize_textarea_field($_POST['name_prompt']) : '';
     $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 4;
     $review_length = intval(get_option('ai_reviews_review_length', 150));

     if (!$product_id || empty($review_prompt) || empty($name_prompt)) {
         wp_send_json_error(__('Missing required fields.', 'ai-reviews'), 400);
     }

     if (!get_post_status($product_id) || get_post_type($product_id) !== 'product') {
         wp_send_json_error(__('Invalid Product ID.', 'ai-reviews'), 400);
     }

     // Use the main generation function but don't save yet
    $result = generate_single_review_content(
        $product_id,
        $review_length,
        $review_prompt, // Use custom prompt
        $name_prompt,   // Use custom name prompt
        $rating         // Use custom rating
    );


    if ($result && is_array($result)) {
        // Sanitize for display before sending
        $result['author'] = esc_html($result['author']);
        $result['review'] = esc_html($result['review']); // Basic escaping for JS, will be properly sanitized on publish
        $result['rating'] = intval($result['rating']);
        wp_send_json_success($result);
    } else {
         wp_send_json_error($result ?: __('Failed to generate review content. Check API key and prompts.', 'ai-reviews'), 500);
    }
}


/**
 * AJAX handler for publishing a review from the test page.
 */
add_action('wp_ajax_ai_reviews_publish_review', 'ai_reviews_publish_review_ajax');
function ai_reviews_publish_review_ajax() {
    check_ajax_referer('ai_reviews_publish_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'ai-reviews'), 403);
    }

    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    // Use wp_kses_post for review content to allow basic HTML but sanitize dangerous tags/attributes
    $review_content = isset($_POST['review_content']) ? wp_kses_post(wp_unslash($_POST['review_content'])) : '';
    $review_author = isset($_POST['review_author']) ? sanitize_text_field($_POST['review_author']) : 'Anonymous';
    $review_rating = isset($_POST['review_rating']) ? intval($_POST['review_rating']) : 0;

    if (!$product_id || empty($review_content) || !$review_rating) {
        wp_send_json_error(__('Missing required fields or invalid data.', 'ai-reviews'), 400);
    }

     if ($review_rating < 1 || $review_rating > 5) {
         wp_send_json_error(__('Invalid rating value.', 'ai-reviews'), 400);
     }

    if (!get_post_status($product_id) || get_post_type($product_id) !== 'product') {
         wp_send_json_error(__('Invalid Product ID.', 'ai-reviews'), 400);
     }

    $comment_data = array(
        'comment_post_ID' => $product_id,
        'comment_author' => $review_author,
        'comment_content' => $review_content,
        'comment_type' => 'review',
        'comment_approved' => 1, // Auto-approve reviews published by admin
        'user_id' => get_current_user_id(), // Associate with the admin user
    );

    $comment_id = wp_insert_comment($comment_data);

    if ($comment_id && !is_wp_error($comment_id)) {
        update_comment_meta($comment_id, 'rating', $review_rating);
        // Clear product rating transient cache for WooCommerce
        delete_transient('wc_average_rating_' . $product_id);
        delete_transient('wc_rating_count_' . $product_id);
        wp_send_json_success(['message' => __('Review published successfully.', 'ai-reviews'), 'comment_id' => $comment_id]);
    } else {
         $error_message = is_wp_error($comment_id) ? $comment_id->get_error_message() : __('Failed to insert review into database.', 'ai-reviews');
         wp_send_json_error($error_message, 500);
    }
}


/**
 * Function to handle AJAX product search with nonce check.
 */
add_action('wp_ajax_ai_reviews_search_products', 'ai_reviews_search_products_ajax');
function ai_reviews_search_products_ajax() {
    check_ajax_referer('ai_reviews_search_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Permission denied.', 'ai-reviews'), 403);
    }

    $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
    if (empty($term)) {
        wp_send_json_success([]); // Return empty array if no term
    }

    $data_store = WC_Data_Store::load('product');
    $ids = $data_store->search_products($term, '', true, false, 10); // Search only published, return IDs

    $results = [];
    if (!empty($ids)) {
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if ($product) {
                $results[] = [
                    'id'    => $product->get_id(),
                    'label' => $product->get_formatted_name(), // Includes SKU or ID if names are similar
                    'value' => $product->get_name() // Value for display after selection (optional)
                ];
            }
        }
    }

    wp_send_json_success($results);
}

/**
 * Schedules multiple review generation tasks at intervals.
 */
function generate_reviews_in_intervals($daily_rate, $review_length, $interval_minutes) {
    ai_reviews_log("AI Reviews: Attempting to schedule $daily_rate reviews with $interval_minutes min interval.");

    // Use WC_Product_Query for better performance and filtering
    $query = new WC_Product_Query([
        'limit' => -1, // Get all products (consider pagination/batching for very large stores)
        'status' => 'publish',
        'return' => 'ids',
    ]);
    $product_ids = $query->get_products();

    if (empty($product_ids)) {
        ai_reviews_log("AI Reviews: No published products found to schedule reviews for.");
         set_transient('ai_reviews_admin_notice', __('No published products found to schedule reviews for.', 'ai-reviews'), 30);
        return;
    }

    shuffle($product_ids); // Randomize product order

    $scheduled_count = 0;
    $current_timestamp = time(); // Base time for scheduling

    foreach ($product_ids as $product_id) {
        if ($scheduled_count >= $daily_rate) {
            break; // Stop if we've scheduled the daily limit
        }

        // Calculate timestamp for this specific review job
        $timestamp = $current_timestamp + ($scheduled_count * $interval_minutes * MINUTE_IN_SECONDS);

        // Schedule a single event for this product at the calculated time
        $scheduled = wp_schedule_single_event($timestamp, 'generate_and_save_review_at_time', [$product_id, $review_length]);

         if ($scheduled !== false) {
            $scheduled_count++;
            ai_reviews_log("AI Reviews: Scheduled review for Product ID $product_id at " . date('Y-m-d H:i:s', $timestamp));
         } else {
             ai_reviews_log("AI Reviews: Failed to schedule review for Product ID $product_id at " . date('Y-m-d H:i:s', $timestamp));
         }
    }

    if ($scheduled_count > 0) {
         $notice = sprintf(
             /* translators: %1$d = number of reviews scheduled, %2$d = total requested, %3$d = interval */
             _n(
                 'Scheduled %1$d review (of %2$d requested) to be generated starting in approximately %3$d minute.',
                 'Scheduled %1$d reviews (of %2$d requested) to be generated, one every %3$d minutes.',
                 $scheduled_count,
                 'ai-reviews'
             ),
             $scheduled_count,
             $daily_rate,
             $interval_minutes
         );
         set_transient('ai_reviews_admin_notice', $notice, 30);
         ai_reviews_log("AI Reviews: Finished scheduling. Total scheduled: $scheduled_count / $daily_rate requested.");
    } elseif ($daily_rate > 0) {
         set_transient('ai_reviews_admin_notice', __('Could not schedule any reviews. Check WP-Cron and product availability.', 'ai-reviews'), 30);
         ai_reviews_log("AI Reviews: Failed to schedule any reviews.");
    }
}


/**
 * Hook for the single scheduled event to generate and save one review.
 */
add_action('generate_and_save_review_at_time', 'generate_and_save_review', 10, 2); // Adjusted parameters
function generate_and_save_review($product_id, $review_length) {
    ai_reviews_log("AI Reviews: Cron job generate_and_save_review_at_time started for Product ID: $product_id");

    $product = wc_get_product($product_id);
    if (!$product) {
         ai_reviews_log("AI Reviews: Product ID $product_id not found. Aborting review generation.");
        return false;
    }

    // Generate the content (using default prompts from settings)
    $review_data = generate_single_review_content($product_id, $review_length);

    if (!$review_data || !is_array($review_data)) {
        ai_reviews_log("AI Reviews: Failed to generate review content for Product ID $product_id. Error: " . print_r($review_data, true));
        return false;
    }

    // Check if AI labeling is enabled
    $label_ai = get_option('ai_reviews_label_ai_generated', 'no') === 'yes';
    if ($label_ai) {
        // Append the label with a space and parentheses for clarity
        $review_data['review'] .= ' (' . esc_html__('AI Generated Review', 'ai-reviews') . ')';
    }

    $comment_data = array(
        'comment_post_ID'      => $product_id,
        'comment_author'       => $review_data['author'], // Already sanitized text
        'comment_content'      => wp_kses_post($review_data['review']), // Sanitize allowing basic HTML
        'comment_type'         => 'review',
        'comment_approved'     => 1, // Auto-approve generated reviews
        'comment_agent'        => 'AI Reviews Plugin v' . AI_REVIEWS_VERSION, // Identify the source
        'comment_author_IP'    => '', // Leave blank for automated reviews
        'comment_author_url'   => '',
        'comment_author_email' => '', // No email for generated authors
         // 'user_id' => 0, // Optional: Don't associate with a specific user
    );

    $comment_id = wp_insert_comment($comment_data);

    if ($comment_id && !is_wp_error($comment_id)) {
        update_comment_meta($comment_id, 'rating', $review_data['rating']);
        update_comment_meta($comment_id, '_ai_generated', '1'); // Add meta flag

        // Clear product rating transient cache for WooCommerce
        delete_transient('wc_average_rating_' . $product_id);
        delete_transient('wc_rating_count_' . $product_id);

        ai_reviews_log("AI Reviews: Successfully generated and saved review (Comment ID: $comment_id) for Product ID: $product_id");
        return true;
    } else {
        $error_message = is_wp_error($comment_id) ? $comment_id->get_error_message() : 'wp_insert_comment failed';
        ai_reviews_log("AI Reviews: Failed to save review for Product ID: $product_id. Error: $error_message");
        return false;
    }
}

/**
 * Generates the review content (author, review text, rating) without saving it.
 * Can use custom prompts/rating or defaults from settings.
 *
 * @param int $product_id
 * @param int $review_length Approx tokens.
 * @param string $custom_prompt Override settings prompt.
 * @param string $custom_name_prompt Override settings name prompt.
 * @param int|null $custom_rating Override random rating.
 * @return array|string Returns array ['author', 'review', 'rating'] on success, error message string on failure.
 */
function generate_single_review_content($product_id, $review_length, $custom_prompt = '', $custom_name_prompt = '', $custom_rating = null) {

    $product = wc_get_product($product_id);
    if (!$product) {
        return __('Product not found.', 'ai-reviews');
    }

    $title = $product->get_name();
    // Get description, strip tags for cleaner input to AI
    $description = wp_strip_all_tags($product->get_description() ?: $product->get_short_description() ?: $title);

    // --- Generate Review Text ---
    $review_text = generate_openai_completion(
        $title,
        $description,
        $review_length,
        'review',
        $custom_prompt
    );

    if (empty($review_text)) {
        return __('Failed to generate review text from API.', 'ai-reviews');
    }

    // --- Generate Author Name ---
    $author_name = generate_openai_completion(
        $title, // Pass title/desc optionally if name prompt uses them
        $description,
        20, // Max tokens for name
        'name',
        $custom_name_prompt
    );
     // Fallback if name generation fails
    if (empty($author_name)) {
         $author_name = __('AI Reviewer', 'ai-reviews'); // Default name
         ai_reviews_log("AI Reviews: Failed to generate author name, using default.");
    }


    // --- Determine Rating ---
    $rating = ($custom_rating !== null && $custom_rating >= 1 && $custom_rating <= 5)
              ? intval($custom_rating)
              : rand(4, 5); // Default to 4 or 5 stars for auto-generated

    return [
        'author' => sanitize_text_field($author_name), // Sanitize name
        'review' => $review_text, // Keep raw text, sanitize on save/display
        'rating' => $rating,
    ];
}

/**
 * Helper function to interact with OpenAI API.
 *
 * @param string $title Product Title.
 * @param string $description Product Description.
 * @param int $max_tokens Max tokens for the response.
 * @param string $type 'review' or 'name'. Determines which prompt setting to use if custom is empty.
 * @param string $custom_prompt Custom prompt to override settings.
 * @return string Generated text on success, empty string on failure.
 */
function generate_openai_completion($title, $description, $max_tokens, $type = 'review', $custom_prompt = '') {
    $api_key = get_option('ai_reviews_api_key', '');
    if (empty($api_key)) {
        ai_reviews_log('AI Reviews Error: OpenAI API Key is missing.');
        set_transient('ai_reviews_admin_notice', __('OpenAI API Key is missing. Please configure it in the settings.', 'ai-reviews'), 30);
        return '';
    }

    // Determine the prompt
    $final_prompt = '';
    if (!empty($custom_prompt)) {
        $final_prompt = $custom_prompt;
    } elseif ($type === 'review') {
        $prompts = get_option('ai_reviews_prompts', []);
        $valid_prompts = array_filter($prompts, 'strlen'); // Filter out empty prompts
        if (empty($valid_prompts)) {
            ai_reviews_log('AI Reviews Error: No valid review prompts are configured.');
            set_transient('ai_reviews_admin_notice', __('No valid review prompts are defined in settings.', 'ai-reviews'), 30);
            return '';
        }
        $final_prompt = $valid_prompts[array_rand($valid_prompts)];
    } elseif ($type === 'name') {
        $final_prompt = get_option('ai_reviews_name_prompt', 'Give me a random name for a product reviewer.');
    }

     // Replace placeholders
     $final_prompt = str_replace(
        ['{{product_title}}', '{{product_description}}'],
        [$title, $description],
        $final_prompt
    );

    if (empty($final_prompt)) {
        ai_reviews_log("AI Reviews Error: Final prompt is empty for type '$type'.");
        return '';
    }

    // Prepare API request
    $api_url = 'https://api.openai.com/v1/chat/completions';
    $headers = [
        'Content-Type'  => 'application/json',
        'Authorization' => 'Bearer ' . $api_key,
    ];
    $body = [
        'model'    => 'gpt-3.5-turbo', // Use gpt-3.5-turbo for potentially lower cost/faster response, or gpt-4o if preferred/needed
        'messages' => [
            ['role' => 'user', 'content' => $final_prompt],
        ],
        'max_tokens' => intval($max_tokens),
        'temperature' => 0.7, // Adjust creativity (0.5-0.8 often good for reviews)
        'n' => 1, // Generate one completion
        'stop' => null, // Let the model decide when to stop
    ];

    $args = [
        'method'  => 'POST',
        'headers' => $headers,
        'body'    => json_encode($body),
        'timeout' => 60, // Increase timeout for API calls
        'data_format' => 'body',
    ];

    ai_reviews_log("AI Reviews: Sending request to OpenAI. Type: $type, Max Tokens: $max_tokens");
    // ai_reviews_log("AI Reviews: Prompt: " . substr($final_prompt, 0, 100) . "..."); // Log truncated prompt

    $response = wp_remote_post($api_url, $args);

    // Handle response
    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        ai_reviews_log("AI Reviews API Error: wp_remote_post failed. $error_message");
        set_transient('ai_reviews_admin_notice', sprintf(__('Error communicating with OpenAI API: %s', 'ai-reviews'), $error_message), 30);
        return '';
    }

    $response_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $result = json_decode($response_body, true);

    if ($response_code >= 300 || $response_code < 200) {
         $error_detail = isset($result['error']['message']) ? $result['error']['message'] : $response_body;
         ai_reviews_log("AI Reviews API Error: Received status code $response_code. Detail: $error_detail");
         set_transient('ai_reviews_admin_notice', sprintf(__('OpenAI API Error (%s): %s', 'ai-reviews'), $response_code, esc_html($error_detail)), 30);
        return '';
    }


    if (isset($result['choices'][0]['message']['content'])) {
        $generated_text = trim($result['choices'][0]['message']['content']);
         ai_reviews_log("AI Reviews: Received successful response from OpenAI. Length: " . strlen($generated_text));
        // Optional: Basic cleanup (remove surrounding quotes if AI adds them)
        $generated_text = trim($generated_text, '"');
        return $generated_text;
    } else {
        ai_reviews_log("AI Reviews API Error: Unexpected response format or empty content. Body: $response_body");
         set_transient('ai_reviews_admin_notice', __('Received an unexpected or empty response from OpenAI.', 'ai-reviews'), 30);
        return '';
    }
}


/**
 * Function to log messages for debugging if WP_DEBUG is enabled.
 *
 * @param string $message The message to log.
 */
function ai_reviews_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log(print_r($message, true));
        } else {
            error_log($message);
        }
    }
}

/**
 * Display admin notices stored in transients.
 */
add_action('admin_notices', 'ai_reviews_display_admin_notices');
function ai_reviews_display_admin_notices() {
    if ($notice = get_transient('ai_reviews_admin_notice')) {
        ?>
        <div class="notice notice-info is-dismissible">
            <p><strong><?php esc_html_e('AI Product Reviews:', 'ai-reviews'); ?></strong> <?php echo esc_html($notice); ?></p>
        </div>
        <?php
        delete_transient('ai_reviews_admin_notice'); // Clear notice after displaying
    }
     // Display WP settings errors (used on settings save)
     settings_errors('ai_reviews_settings');
     settings_errors('ai_reviews_test'); // Also show errors on test page if any were added
}


/**
 * Renders the Scheduled Reviews page (basic implementation).
 */
function ai_reviews_scheduled_page() {
     if (!current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'ai-reviews'));
    }

     ?>
    <div class="wrap ai-reviews-admin-wrap">
        <h1><?php esc_html_e('Scheduled Review Tasks', 'ai-reviews'); ?></h1>

         <h2><?php esc_html_e('Main Daily Generation Task', 'ai-reviews'); ?></h2>
         <?php
         $next_daily_run = wp_next_scheduled('ai_reviews_daily_event');
         if (get_option('ai_reviews_auto_generate') === 'yes') {
             if ($next_daily_run) {
                 echo '<p>' . sprintf(
                     esc_html__('The main task to trigger daily review scheduling is set to run next at: %s (%s from now).', 'ai-reviews'),
                     '<strong>' . get_date_from_gmt(date('Y-m-d H:i:s', $next_daily_run), get_option('date_format') . ' ' . get_option('time_format')) . '</strong>',
                     '<strong>' . human_time_diff($next_daily_run) . '</strong>'
                 ) . '</p>';
             } else {
                 echo '<p class="notice notice-warning inline">' . esc_html__('The main daily task is enabled but not currently scheduled. Please save the settings on the General Settings tab to schedule it.', 'ai-reviews') . '</p>';
             }
         } else {
             echo '<p>' . esc_html__('Automatic daily generation is disabled in the settings.', 'ai-reviews') . '</p>';
         }
         ?>

        <h2><?php esc_html_e('Upcoming Individual Review Generations', 'ai-reviews'); ?></h2>
        <p><?php esc_html_e('This list shows individual review generation tasks scheduled by the daily process. These will appear after the main daily task runs.', 'ai-reviews'); ?></p>

        <?php
        $cron_jobs = _get_cron_array();
        $scheduled_reviews = [];
        if (!empty($cron_jobs)) {
            foreach ($cron_jobs as $timestamp => $hooks) {
                if (isset($hooks['generate_and_save_review_at_time'])) {
                    foreach ($hooks['generate_and_save_review_at_time'] as $key => $details) {
                         $product_id = $details['args'][0] ?? 0;
                         $scheduled_reviews[$timestamp][] = [
                             'product_id' => $product_id,
                             'product_name' => $product_id ? get_the_title($product_id) : __('Unknown Product', 'ai-reviews'),
                             'hash' => $key, // Unique identifier for this job instance
                         ];
                    }
                }
            }
        }
        ksort($scheduled_reviews); // Sort by timestamp (soonest first)

        if (!empty($scheduled_reviews)) {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr><th>' . esc_html__('Scheduled Time', 'ai-reviews') . '</th><th>' . esc_html__('Product', 'ai-reviews') . '</th></tr></thead>';
            echo '<tbody>';
            foreach ($scheduled_reviews as $timestamp => $reviews) {
                foreach ($reviews as $review_info) {
                    echo '<tr>';
                     echo '<td>' . esc_html(get_date_from_gmt(date('Y-m-d H:i:s', $timestamp), get_option('date_format') . ' ' . get_option('time_format'))) . ' (' . human_time_diff($timestamp) . ' ' . esc_html__('from now', 'ai-reviews') . ')</td>';
                     echo '<td>' . esc_html($review_info['product_name']) . ' (ID: ' . esc_html($review_info['product_id']) . ')</td>';
                    echo '</tr>';
                }
            }
            echo '</tbody></table>';
        } else {
            echo '<p>' . esc_html__('No individual reviews are currently scheduled. They will be scheduled when the daily task runs.', 'ai-reviews') . '</p>';
        }
        ?>
         <p style="margin-top: 20px;"><em><?php esc_html_e('Note: WP-Cron depends on site visits to trigger scheduled tasks. For reliable scheduling, consider setting up a server-level cron job.', 'ai-reviews'); ?></em></p>

    </div>
    <?php
}


// --- GitHub Updater ---

/**
 * Checks GitHub for plugin updates.
 * Uses a `version.json` file in the root of the main branch.
 */
add_filter('pre_set_site_transient_update_plugins', 'ai_reviews_check_for_plugin_update');
function ai_reviews_check_for_plugin_update($transient) {
    // Check if the transient has data and the checked array key exists
    if (empty($transient) || !isset($transient->checked) || !isset($transient->checked[AI_REVIEWS_PLUGIN_BASENAME])) {
        return $transient;
    }

    // --- IMPORTANT: URL to your version.json file on GitHub ---
    // Replace 'main' with your default branch name if different (e.g., 'master')
    $remote_version_url = 'https://raw.githubusercontent.com/hassanzn2023/ai-product-reviews/main/version.json';

    // Get remote version info
    $response = wp_remote_get($remote_version_url, [
        'timeout' => 10, // Shorter timeout for version check
        'headers' => ['Accept' => 'application/json'], // Optional: Ensure JSON is preferred
    ]);

    // Check for errors or non-200 status
    if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
        ai_reviews_log('GitHub Updater Error: Failed to fetch version info from: ' . $remote_version_url . ' Code: ' . wp_remote_retrieve_response_code($response));
        return $transient; // Fail silently, don't block other updates
    }

    // Decode the JSON response
    $remote_data = json_decode(wp_remote_retrieve_body($response));

    // Check if decoding failed or required properties are missing
    if (!$remote_data || !isset($remote_data->new_version) || !isset($remote_data->download_url)) {
        ai_reviews_log('GitHub Updater Error: Invalid or incomplete version data received from: ' . $remote_version_url);
        return $transient;
    }

    // Get the currently installed version
    $current_version = $transient->checked[AI_REVIEWS_PLUGIN_BASENAME];

    // Compare versions
    if (version_compare($current_version, $remote_data->new_version, '<')) {
        ai_reviews_log("GitHub Updater: Update available. Current: $current_version, New: {$remote_data->new_version}");
        // Prepare the update data object for WordPress
        $update_data = new stdClass();
        $update_data->slug = dirname(AI_REVIEWS_PLUGIN_BASENAME); // 'ai-product-reviews'
        $update_data->plugin = AI_REVIEWS_PLUGIN_BASENAME; // 'ai-product-reviews/ai-product-reviews.php'
        $update_data->new_version = $remote_data->new_version;
        $update_data->url = $remote_data->url ?? 'https://github.com/hassanzn2023/ai-product-reviews'; // Plugin homepage URL
        $update_data->package = $remote_data->download_url; // Direct download link for the ZIP file
        $update_data->tested = $remote_data->tested ?? ''; // Tested up to WP version
        $update_data->requires = $remote_data->requires ?? ''; // Required WP version
        $update_data->requires_php = $remote_data->requires_php ?? ''; // Required PHP version

        // Add icons if provided in version.json
         if (isset($remote_data->icons) && is_object($remote_data->icons)) {
            $update_data->icons = (array) $remote_data->icons; // Convert object to array
         } else {
             // Provide default icon (optional)
             $update_data->icons = [
                 '1x' => AI_REVIEWS_PLUGIN_URL . 'assets/icon-128x128.png', // Example path, create this icon
                 '2x' => AI_REVIEWS_PLUGIN_URL . 'assets/icon-256x256.png', // Example path
                 //'svg' => AI_REVIEWS_PLUGIN_URL . 'assets/icon.svg',
             ];
         }

         // Add banners if provided
         if (isset($remote_data->banners) && is_object($remote_data->banners)) {
             $update_data->banners = (array) $remote_data->banners;
             $update_data->banners_rtl = (array) ($remote_data->banners_rtl ?? []);
         } else {
             $update_data->banners = [
                // 'low' => AI_REVIEWS_PLUGIN_URL . 'assets/banner-772x250.png', // Example path
                // 'high' => AI_REVIEWS_PLUGIN_URL . 'assets/banner-1544x500.png' // Example path
             ];
             $update_data->banners_rtl = [];
         }

         // Add sections (Description, Changelog etc.) if provided
         if (isset($remote_data->sections) && is_object($remote_data->sections)) {
             $update_data->sections = (array) $remote_data->sections;
         }


        // Add the update data to the transient
        $transient->response[AI_REVIEWS_PLUGIN_BASENAME] = $update_data;

    } else {
        ai_reviews_log("GitHub Updater: No update needed. Current: $current_version, Remote: {$remote_data->new_version}");
        // Ensure it's not lingering in the response if version is downgraded or same
         if (isset($transient->response[AI_REVIEWS_PLUGIN_BASENAME])) {
            unset($transient->response[AI_REVIEWS_PLUGIN_BASENAME]);
         }
    }

    return $transient;
}
