<?php
/*
Plugin Name: AI Product Reviews
Plugin URI: https://github.com/hassanzn2023/ai-product-reviews
Description: Automatically generates product reviews using AI by fetching product title and description.
Version: 2.1.2
Author: Hassan Zein
Author URI: http://skillyweb.com
Text Domain: ai-reviews
GitHub Plugin URI: https://github.com/hassanzn2023/ai-product-reviews
GitHub Branch: main
*/


defined('ABSPATH') or die('No script kiddies please!');

// AJAX function for publishing reviews
add_action('wp_ajax_publish_review', 'publish_review_function');
function publish_review_function() {
    if (isset($_POST['product_id']) && isset($_POST['review_content']) && isset($_POST['review_author']) && isset($_POST['review_rating'])) {
        $product_id = intval($_POST['product_id']);
        $review_content = sanitize_text_field($_POST['review_content']);
        $review_author = sanitize_text_field($_POST['review_author']);
        $review_rating = intval($_POST['review_rating']);

        $comment_data = array(
            'comment_post_ID' => $product_id,
            'comment_author' => $review_author,
            'comment_content' => $review_content,
            'comment_type' => 'review',
            'comment_approved' => 1,
        );

        $comment_id = wp_insert_comment($comment_data);

        if ($comment_id) {
            update_comment_meta($comment_id, 'rating', $review_rating);
            wp_send_json_success();
        } else {
            wp_send_json_error('Failed to insert review.');
        }
    } else {
        wp_send_json_error('Missing required fields.');
    }
}

// Load text domain for translations
add_action('plugins_loaded', 'ai_reviews_load_textdomain');
function ai_reviews_load_textdomain() {
    load_plugin_textdomain('ai-reviews', false, basename(dirname(__FILE__)) . '/languages');
}

// Schedule daily event on plugin activation
register_activation_hook(__FILE__, 'ai_reviews_daily_schedule');
function ai_reviews_daily_schedule() {
    if (!wp_next_scheduled('ai_reviews_daily_event')) {
        wp_schedule_event(time(), 'daily', 'ai_reviews_daily_event');
    }
    ai_reviews_log("Scheduled daily event");
}

// Clear scheduled event on plugin deactivation
register_deactivation_hook(__FILE__, 'ai_reviews_daily_unschedule');
function ai_reviews_daily_unschedule() {
    wp_clear_scheduled_hook('ai_reviews_daily_event');
    ai_reviews_log("Unscheduled daily event");
}

// Hook for the daily event to generate reviews
add_action('ai_reviews_daily_event', 'generate_daily_reviews');
function generate_daily_reviews() {
    ai_reviews_log("Running daily review generation");
    $auto_generate = get_option('ai_reviews_auto_generate', 'no');
    if ($auto_generate !== 'yes') {
        ai_reviews_log("Auto reviews are disabled.");
        return;
    }

    $daily_rate = intval(get_option('ai_reviews_daily_rate', 10));
    $review_length = intval(get_option('ai_reviews_review_length', 100));
    $interval_minutes = intval(get_option('ai_reviews_time_between_reviews', 1));
    generate_reviews_in_intervals($daily_rate, $review_length, 'publish', $interval_minutes);
}

// Add settings menu to the admin dashboard
add_action('admin_menu', 'ai_reviews_menu');
function ai_reviews_menu() {
    add_menu_page(__('AI Product Reviews Settings', 'ai-reviews'), __('AI Reviews', 'ai-reviews'), 'manage_options', 'ai-product-reviews', 'ai_reviews_init', 'dashicons-testimonial');
    add_submenu_page('ai-product-reviews', __('Test AI Reviews', 'ai-reviews'), __('Test Reviews', 'ai-reviews'), 'manage_options', 'ai-reviews-test', 'ai_reviews_test_page');
}

// Enqueue styles and scripts for the plugin pages
add_action('admin_enqueue_scripts', 'ai_reviews_enqueue_scripts');
function ai_reviews_enqueue_scripts($hook) {
    if ($hook === 'toplevel_page_ai-product-reviews' || $hook === 'ai-reviews_page_ai-reviews-test') {
        wp_enqueue_style('ai-reviews-styles', plugin_dir_url(__FILE__) . 'ai-reviews-styles.css');
        wp_enqueue_style('jquery-ui-css', '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
        wp_enqueue_script('jquery-ui-autocomplete');
    }
}

// Initialize the settings page
function ai_reviews_init() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ai-reviews'));
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_admin_referer('ai_reviews_nonce_action', 'ai_reviews_nonce_name');

        update_option('ai_reviews_api_key', sanitize_text_field($_POST['ai_reviews_api_key']));
        update_option('ai_reviews_prompts', array_map('sanitize_text_field', $_POST['ai_reviews_prompts'] ?? []));
        update_option('ai_reviews_enabled', isset($_POST['ai_reviews_enabled']) ? 'yes' : 'no');
        update_option('ai_reviews_name_prompt', sanitize_text_field($_POST['ai_reviews_name_prompt']));
        update_option('ai_reviews_auto_generate', sanitize_text_field($_POST['ai_reviews_auto_generate']));
        update_option('ai_reviews_time_between_reviews', intval($_POST['ai_reviews_time_between_reviews']));
        update_option('ai_reviews_daily_rate', intval($_POST['ai_reviews_daily_rate']));

        wp_clear_scheduled_hook('ai_reviews_daily_event');
        if ($_POST['ai_reviews_auto_generate'] === 'yes' && intval($_POST['ai_reviews_time_between_reviews']) > 0) {
            $daily_rate = intval($_POST['ai_reviews_daily_rate']);
            $review_length = 100;
            $interval_minutes = intval($_POST['ai_reviews_time_between_reviews']);
            generate_reviews_in_intervals($daily_rate, $review_length, 'publish', $interval_minutes);
            ai_reviews_log("Scheduled $daily_rate reviews to start in $interval_minutes minutes, one review every $interval_minutes minutes.");
        }

        echo '<div class="updated"><p>' . __('Settings saved.', 'ai-reviews') . '</p></div>';
    }

    $api_key = get_option('ai_reviews_api_key', '');
    $prompts = get_option('ai_reviews_prompts', array_fill(0, 5, ''));
    $name_prompt = get_option('ai_reviews_name_prompt', 'Give me a random name for a product reviewer.');
    $enabled = get_option('ai_reviews_enabled', 'no');
    $auto_generate = get_option('ai_reviews_auto_generate', 'no');
    $time_between_reviews = intval(get_option('ai_reviews_time_between_reviews', 0));
    $daily_rate = intval(get_option('ai_reviews_daily_rate', 10));

    ?>
    <div class="wrap">
        <h1><?php _e('AI Product Reviews Settings', 'ai-reviews'); ?></h1>
        <form method="post">
            <?php wp_nonce_field('ai_reviews_nonce_action', 'ai_reviews_nonce_name'); ?>
            <label for="ai_reviews_api_key"><?php _e('OpenAI API Key:', 'ai-reviews'); ?></label>
            <input type="text" id="ai_reviews_api_key" name="ai_reviews_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text">
            <br><br>

            <h2><?php _e('Prompts for Reviews', 'ai-reviews'); ?></h2>
            <?php for ($i = 0; $i < 5; $i++): ?>
                <label for="ai_reviews_prompt_<?php echo $i; ?>"><?php printf(__('Prompt %d:', 'ai-reviews'), $i + 1); ?></label>
                <input type="text" id="ai_reviews_prompt_<?php echo $i; ?>" name="ai_reviews_prompts[]" value="<?php echo esc_attr($prompts[$i]); ?>" class="regular-text" placeholder="<?php _e('e.g., Write a review about {{product_title}} focusing on {{aspect}}', 'ai-reviews'); ?>">
                <br><br>
            <?php endfor; ?>

            <label for="ai_reviews_name_prompt"><?php _e('Name Generation Prompt:', 'ai-reviews'); ?></label>
            <input type="text" id="ai_reviews_name_prompt" name="ai_reviews_name_prompt" value="<?php echo esc_attr($name_prompt); ?>" class="regular-text" placeholder="<?php _e('e.g., Give me a random name for a product reviewer.', 'ai-reviews'); ?>">
            <br><br>

            <h2><?php _e('Auto Reviews Settings', 'ai-reviews'); ?></h2>
            <label for="ai_reviews_auto_generate"><?php _e('Enable Auto Reviews:', 'ai-reviews'); ?></label>
            <select id="ai_reviews_auto_generate" name="ai_reviews_auto_generate">
                <option value="yes" <?php selected($auto_generate, 'yes'); ?>><?php _e('Yes', 'ai-reviews'); ?></option>
                <option value="no" <?php selected($auto_generate, 'no'); ?>><?php _e('No', 'ai-reviews'); ?></option>
            </select>
            <br><br>

            <label for="ai_reviews_time_between_reviews"><?php _e('Time Between Save Settings and Review Publish (in minutes):', 'ai-reviews'); ?></label>
            <input type="number" id="ai_reviews_time_between_reviews" name="ai_reviews_time_between_reviews" value="<?php echo esc_attr($time_between_reviews); ?>" min="0" class="regular-text">
            <p class="description"><?php _e('Enter the time between saving settings and review publish in minutes.', 'ai-reviews'); ?></p>
            <br><br>

            <label for="ai_reviews_daily_rate"><?php _e('Daily Rate (number of reviews):', 'ai-reviews'); ?></label>
            <input type="number" id="ai_reviews_daily_rate" name="ai_reviews_daily_rate" value="<?php echo esc_attr($daily_rate); ?>" min="1" class="regular-text">
            <p class="description"><?php _e('Enter the maximum number of reviews to generate per day.', 'ai-reviews'); ?></p>
            <br><br>

            <input type="submit" value="<?php _e('Save Settings', 'ai-reviews'); ?>" class="button-primary"/>
        </form>
    </div>
    <?php
}

// New function for the test page
function ai_reviews_test_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.', 'ai-reviews'));
    }

    $name_prompt = get_option('ai_reviews_name_prompt', 'Give me a random name for a product reviewer.');

    ?>
    <div class="wrap">
        <h1><?php _e('Test AI Reviews', 'ai-reviews'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('ai_reviews_test_nonce_action', 'ai_reviews_test_nonce_name'); ?>
            
            <label for="ai_reviews_test_prompt"><?php _e('Review Prompt:', 'ai-reviews'); ?></label>
            <input type="text" id="ai_reviews_test_prompt" name="ai_reviews_test_prompt" class="regular-text" placeholder="Enter your custom prompt here">
            <p class="description">Please include <code>{{product_title}}</code> to display the product title and <code>{{product_description}}</code> to display the product description in the review.</p>
            <br><br>

            <label for="ai_reviews_test_name_prompt"><?php _e('Name Generation Prompt:', 'ai-reviews'); ?></label>
            <input type="text" id="ai_reviews_test_name_prompt" name="ai_reviews_test_name_prompt" value="<?php echo esc_attr($name_prompt); ?>" class="regular-text">
            <br><br>

            <label for="ai_reviews_test_rating"><?php _e('Rating:', 'ai-reviews'); ?></label>
            <select id="ai_reviews_test_rating" name="ai_reviews_test_rating">
                <option value="1">1 <?php _e('star', 'ai-reviews'); ?></option>
                <option value="2">2 <?php _e('stars', 'ai-reviews'); ?></option>
                <option value="3">3 <?php _e('stars', 'ai-reviews'); ?></option>
                <option value="4" selected>4 <?php _e('stars', 'ai-reviews'); ?></option>
                <option value="5">5 <?php _e('stars', 'ai-reviews'); ?></option>
            </select>
            <br><br>

            <label for="product_search"><?php _e('Search and Select Product:', 'ai-reviews'); ?></label>
            <input type="text" id="product_search" name="product_search" class="regular-text" placeholder="<?php _e('Start typing to search for a product', 'ai-reviews'); ?>">
            <input type="hidden" id="selected_product_id" name="selected_product_id">
            <br><br>

            <input type="submit" name="generate_test_review" value="<?php _e('Generate Review', 'ai-reviews'); ?>" class="button-primary">
        </form>

        <script>
        jQuery(document).ready(function($) {
            $("#product_search").autocomplete({
                source: function(request, response) {
                    $.ajax({
                        url: ajaxurl,
                        dataType: "json",
                        data: {
                            action: "search_products",
                            term: request.term
                        },
                        success: function(data) {
                            response(data);
                        }
                    });
                },
                minLength: 2,
                select: function(event, ui) {
                    $("#selected_product_id").val(ui.item.id);
                }
            });
        });
        </script>

<?php
        if (isset($_POST['generate_test_review']) && check_admin_referer('ai_reviews_test_nonce_action', 'ai_reviews_test_nonce_name')) {
            $product_id = intval($_POST['selected_product_id']);
            $review_prompt = sanitize_text_field($_POST['ai_reviews_test_prompt']);
            $name_prompt = sanitize_text_field($_POST['ai_reviews_test_name_prompt']);
            $rating = intval($_POST['ai_reviews_test_rating']);

            $product = wc_get_product($product_id);
            if (!$product) {
                echo '<p>' . __('Product not found, please select a valid product.', 'ai-reviews') . '</p>';
                return;
            }

            $result = generate_and_save_review($product_id, 100, 'publish', $review_prompt, $name_prompt, $rating);

            if ($result) {
                if (is_array($result)) {
                    echo '<h3>' . __('Generated Review:', 'ai-reviews') . '</h3>';
                    echo '<p><strong>' . __('Author:', 'ai-reviews') . '</strong> <span class="author-name">' . esc_html($result['author']) . '</span></p>';
                    echo '<p><strong>' . __('Review:', 'ai-reviews') . '</strong> <span class="generated-review">' . esc_html($result['review']) . '</span></p>';
                    echo '<p><strong>' . __('Rating:', 'ai-reviews') . '</strong> <span class="review-rating">' . esc_html($result['rating']) . '</span> ' . __('stars', 'ai-reviews') . '</p>';
                    echo '<button id="publish-review-button" class="button-primary">' . __('Publish Review', 'ai-reviews') . '</button>';
                } else {
                    echo '<p>' . __('Review successfully generated and saved as a real product review.', 'ai-reviews') . '</p>';
                }
            } else {
                echo '<p>' . __('Failed to generate review. Please check your API settings and try again.', 'ai-reviews') . '</p>';
            }
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('#publish-review-button').on('click', function() {
                if (confirm('Do you want to publish this review?')) {
                    var product_id = $('#selected_product_id').val();
                    var review_content = $('.generated-review').text();
                    var review_author = $('.author-name').text();
                    var review_rating = $('.review-rating').text();

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'publish_review',
                            product_id: product_id,
                            review_content: review_content,
                            review_author: review_author,
                            review_rating: review_rating,
                        },
                        success: function(response) {
                            if (response.success) {
                                alert('Review published successfully!');
                            } else {
                                alert('Failed to publish the review: ' + response.data);
                            }
                        },
                        error: function() {
                            alert('An error occurred during the publishing process.');
                        }
                    });
                }
            });
        });
        </script>
    </div>
    <?php
}

// Function to handle AJAX product search
add_action('wp_ajax_search_products', 'ai_reviews_search_products');
function ai_reviews_search_products() {
    $term = sanitize_text_field($_GET['term']);
    $args = array(
        'post_type' => 'product',
        'post_status' => 'publish',
        's' => $term,
        'posts_per_page' => 10
    );
    $products = get_posts($args);
    $results = array();
    foreach ($products as $product) {
        $results[] = array(
            'id' => $product->ID,
            'label' => $product->post_title,
            'value' => $product->post_title
        );
    }
    wp_send_json($results);
}

// Function to generate reviews in intervals
function generate_reviews_in_intervals($daily_rate, $review_length, $publish_option, $interval_minutes) {
    ai_reviews_log("Scheduling $daily_rate reviews to be published at intervals of $interval_minutes minutes");
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1
    );
    $products = get_posts($args);
    shuffle($products);

    $reviews_generated = 0;
    $initial_timestamp = time();

    foreach ($products as $product) {
        if ($reviews_generated >= $daily_rate) {
            break;
        }
        $timestamp = $initial_timestamp + ($reviews_generated * $interval_minutes * 60);
        wp_schedule_single_event($timestamp, 'generate_and_save_review_at_time', array($product->ID, $review_length, $publish_option));
        $reviews_generated++;
    }

    if ($reviews_generated < $daily_rate) {
        set_transient('ai_reviews_admin_notice', 'Only ' . $reviews_generated . ' reviews scheduled today.', 5);
    } else {
        set_transient('ai_reviews_admin_notice', 'Daily rate of ' . $daily_rate . ' reviews scheduled.', 5);
    }
}

// Hook for generating and saving a review at a specific time
add_action('generate_and_save_review_at_time', 'generate_and_save_review', 10, 3);
function generate_and_save_review($product_id, $review_length, $publish_option, $custom_prompt = '', $custom_name_prompt = '', $custom_rating = null) {
    $product = wc_get_product($product_id);
    if (!$product) {
        return false;
    }

    $title = $product->get_name();
    $description = $product->get_description();
    $review = generate_review($title, $description, $review_length, $custom_prompt);

    if (empty($review)) {
        return false;
    }

    $rating = $custom_rating !== null ? intval($custom_rating) : rand(4, 5);
    $author_name = generate_random_author_name($custom_name_prompt);

    $comment_data = array(
        'comment_post_ID'      => $product_id,
        'comment_author'       => $author_name,
        'comment_content'      => $review,
        'comment_type'         => 'review',
        'comment_approved'     => 1,
        'user_id'              => get_current_user_id(),
    );

    $comment_id = wp_insert_comment($comment_data);

    if ($comment_id) {
        update_comment_meta($comment_id, 'rating', $rating);
        return array(
            'author' => $author_name,
            'review' => $review,
            'rating' => $rating
        );
    }

    return false;
}

// Function to generate review using OpenAI API
function generate_review($title, $description, $review_length, $custom_prompt = '') {
    if (!empty($custom_prompt)) {
        $prompt = $custom_prompt;
    } else {
        $prompts = get_option('ai_reviews_prompts', []);
        $valid_prompts = array_filter($prompts, function($prompt) {
            return !empty($prompt);
        });

        if (empty($valid_prompts)) {
            set_transient('ai_reviews_admin_notice', 'No valid prompts defined.', 5);
            return '';
        }

        $prompt = $valid_prompts[array_rand($valid_prompts)];
    }

    $prompt = str_replace(
        array('{{product_title}}', '{{product_description}}'),
        array($title, $description),
        $prompt
    );

    $api_key = get_option('ai_reviews_api_key', '');
    if (empty($api_key)) {
        set_transient('ai_reviews_admin_notice', 'API Key is missing.', 5);
        return '';
    }

    if (empty($title) || empty($description)) {
        set_transient('ai_reviews_admin_notice', 'Title or description is empty, cannot generate review.', 5);
        return '';
    }

    $message = array(
        'role' => 'user',
        'content' => $prompt
    );

    $api_url = 'https://api.openai.com/v1/chat/completions';
    $headers = array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key
    );

    $data = array(
        'model' => 'gpt-4o',  // Updated to a more current model
        'messages' => array($message),
        'max_tokens' => $review_length * 2,
    );

    $response = wp_remote_post($api_url, array(
        'method' => 'POST',
        'headers' => $headers,
        'body' => json_encode($data),
        'data_format' => 'body'
    ));

    if (is_wp_error($response)) {
        set_transient('ai_reviews_admin_notice', 'Error in API call: ' . $response->get_error_message(), 5);
        return '';
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (!isset($result['choices'][0]['message']['content']) || empty($result['choices'][0]['message']['content'])) {
        set_transient('ai_reviews_admin_notice', 'Received empty review, check API settings and input data. Response: ' . $body, 5);
        return '';
    }

    return $result['choices'][0]['message']['content'];
}

// Function to generate random author name using OpenAI API
function generate_random_author_name($custom_prompt = '') {
    $api_key = get_option('ai_reviews_api_key', '');
    if (empty($api_key)) {
        ai_reviews_log('API Key is missing.');
        return 'Anonymous';
    }

    if (!empty($custom_prompt)) {
        $name_prompt = $custom_prompt;
    } else {
        $name_prompt = get_option('ai_reviews_name_prompt', 'Give me a random name for a product reviewer.');
    }

    $message = array(
        'role' => 'user',
        'content' => $name_prompt
    );

    $api_url = 'https://api.openai.com/v1/chat/completions';
    $headers = array(
        'Content-Type' => 'application/json',
        'Authorization' => 'Bearer ' . $api_key
    );

    $data = array(
        'model' => 'gpt-4o',  // Updated to a more current model
        'messages' => array($message),
        'max_tokens' => 20,
    );

    $response = wp_remote_post($api_url, array(
        'method' => 'POST',
        'headers' => $headers,
        'body' => json_encode($data),
        'data_format' => 'body'
    ));

    if (is_wp_error($response)) {
        ai_reviews_log('Error in API call: ' . $response->get_error_message());
        return 'Anonymous';
    }

    $body = wp_remote_retrieve_body($response);
    $result = json_decode($body, true);

    if (!isset($result['choices'][0]['message']['content']) || empty($result['choices'][0]['message']['content'])) {
        ai_reviews_log('Received empty response, check API settings and input data. Response: ' . $body);
        return 'Anonymous';
    }

    return trim($result['choices'][0]['message']['content']);
}

// Function to log messages for debugging
function ai_reviews_log($message) {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log($message);
    }
}

// وظيفة للتحقق من وجود تحديثات جديدة على GitHub
function check_for_plugin_update($transient) {
    // الرابط إلى API الإصدار الأخير في GitHub
    $remote = wp_remote_get('https://api.github.com/repos/hassanzn2023/ai-product-reviews/releases/latest');
    
    // التحقق مما إذا كان هناك خطأ في الاتصال بـ GitHub
    if (is_wp_error($remote)) {
        return $transient;
    }

    // تحليل الرد من API GitHub
    $response = json_decode(wp_remote_retrieve_body($remote), true);

    // مقارنة النسخة الحالية مع النسخة المتاحة على GitHub
    if ($response && version_compare('2.1', $response['tag_name'], '<')) {
        $transient->response['ai-product-reviews/ai-product-reviews.php'] = array(
            'new_version' => $response['tag_name'],
            'package' => $response['zipball_url'], // الرابط لتحميل ملف التحديث
            'slug' => 'ai-product-reviews',
        );
    }

    return $transient;
}
add_filter('site_transient_update_plugins', 'check_for_plugin_update');

// وظيفة لتحميل التحديث من GitHub
function update_plugin_from_github($false, $action, $response) {
    if (!isset($response->slug) || $response->slug != 'ai-product-reviews') {
        return false;
    }

    // تحديد الرابط الخاص بملف ZIP الخاص بالإصدار الجديد من GitHub
    $response->package = 'https://github.com/hassanzn2023/ai-product-reviews/archive/refs/tags/' . $response->new_version . '.zip';
    
    return $response;
}
add_filter('upgrader_package_options', 'update_plugin_from_github', 10, 3);
