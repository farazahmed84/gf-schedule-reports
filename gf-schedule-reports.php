<?php
/*
Plugin Name: Gravity Forms Scheduled Reports
Description: Schedule and email Gravity Forms entry reports (CSV) automatically.
Version: 0.2
Author: Faraz Ahmed
Author URI: https://farazthewebguy.com/
*/

if (!defined('ABSPATH')) exit;

// --- Register all custom intervals globally for WP-Cron ---
add_filter('cron_schedules', function($schedules) {
    $schedules_query = get_posts([
        'post_type'   => 'gfsr_schedule',
        'post_status' => 'publish',
        'numberposts' => -1,
        'fields'      => 'ids',
    ]);
    foreach ($schedules_query as $schedule_id) {
        $type = get_post_meta($schedule_id, 'gfsr_schedule_type', true);
        $repeat = max(1, intval(get_post_meta($schedule_id, 'gfsr_repeat_every', true)));
        $interval = ($type === 'daily') ? DAY_IN_SECONDS * $repeat :
                    (($type === 'weekly') ? WEEK_IN_SECONDS * $repeat :
                    (30 * DAY_IN_SECONDS * $repeat));
        $schedules['gfsr_custom_' . $schedule_id] = [
            'interval' => $interval,
            'display'  => 'GFSR Custom Interval',
        ];
    }
    return $schedules;
});

// --- Custom Post Type for Schedules ---
add_action('init', function() {
    register_post_type('gfsr_schedule', array(
        'labels' => array(
            'name' => 'Schedule Reports',
            'singular_name' => 'Schedule Report',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Schedule Report',
            'edit_item' => 'Edit Schedule Report',
            'new_item' => 'New Schedule Report',
            'view_item' => 'View Schedule Report',
            'search_items' => 'Search Schedule Reports',
            'not_found' => 'No Schedule Reports found',
            'not_found_in_trash' => 'No Schedule Reports found in Trash',
        ),
        'public' => false,
        'show_ui' => true,
        'show_in_menu' => false, // We'll add it under Gravity Forms
        'supports' => array('title'),
        'menu_icon' => 'dashicons-calendar-alt',
    ));
});

// Add CPT to Gravity Forms menu
add_filter('gform_addon_navigation', function($menus) {
    $menus[] = array(
        'name'       => 'edit.php?post_type=gfsr_schedule',
        'label'      => 'GF Scheduled Reports',
        'callback'   => '', // Not needed for CPT
        'permission' => 'manage_options'
    );
    return $menus;
});

// --- Admin Columns: Show Last Run and Next Schedule ---
add_filter('manage_gfsr_schedule_posts_columns', function($columns) {
    $columns['schedule_timing'] = 'Schedule Timing';
    $columns['report'] = 'Enquiry Form Report';
    $columns['run_now'] = 'Run Now';
    $columns['last_run'] = 'Last Run';
    $columns['next_schedule'] = 'Next Schedule';
    return $columns;
});

add_action('manage_gfsr_schedule_posts_custom_column', function($column, $post_id) {
    switch ($column) {
        case 'schedule_timing':
            echo esc_html(get_post_meta($post_id, 'gfsr_time', true));
            break;
        case 'report':
            $form_id = get_post_meta($post_id, 'gfsr_form_id', true);
            if (class_exists('GFAPI') && $form_id) {
                $form = GFAPI::get_form($form_id);
                echo $form && isset($form['title']) ? esc_html($form['title']) : '<em>Form not found</em>';
            } else {
                echo '<em>No form selected</em>';
            }
            break;
        case 'run_now':
            $url = wp_nonce_url(
                add_query_arg([
                    'action' => 'gfsr_run_now',
                    'post' => $post_id
                ], admin_url('edit.php?post_type=gfsr_schedule')),
                'gfsr_run_now_' . $post_id
            );
            echo '<a href="' . esc_url($url) . '" class="button">Run Now</a>';
            break;
        case 'last_run':
            $last = get_post_meta($post_id, 'gfsr_last_run', true);
            echo $last ? esc_html(date_i18n('Y/m/d H:i', strtotime($last))) : '<em>Never</em>';
            break;
        case 'next_schedule':
            // Calculate next schedule based on stored data
            $type = get_post_meta($post_id, 'gfsr_schedule_type', true);
            $time = get_post_meta($post_id, 'gfsr_time', true);
            $repeat = max(1, intval(get_post_meta($post_id, 'gfsr_repeat_every', true)));
            $weekday = get_post_meta($post_id, 'gfsr_weekday', true);
            $last_run = get_post_meta($post_id, 'gfsr_last_run', true);
            
            if (!$type || !$time) {
                echo '<em>Not configured</em>';
                break;
            }
            
            $now = current_time('timestamp');
            $next_time = 0;
            
            if ($type === 'weekly' && $weekday !== '') {
                // Calculate next occurrence of selected weekday
                $current_weekday = date('w', $now);
                $target_weekday = intval($weekday);
                $days_ahead = ($target_weekday - $current_weekday + 7) % 7;
                if ($days_ahead === 0) {
                    $days_ahead = 7; // Next week
                }
                $next_time = strtotime('+' . $days_ahead . ' days', strtotime(date('Y-m-d', $now) . ' ' . $time));
            } else {
                $run_time = strtotime(date('Y-m-d', $now) . ' ' . $time);
                if ($run_time <= $now) {
                    if ($type === 'daily') $next_time = strtotime('+1 day', $run_time);
                    if ($type === 'weekly') $next_time = strtotime('+1 week', $run_time);
                    if ($type === 'monthly') $next_time = strtotime('+1 month', $run_time);
                } else {
                    $next_time = $run_time;
                }
            }
            
            echo $next_time ? esc_html(date_i18n('Y/m/d H:i', $next_time)) : '<em>Not scheduled</em>';
            break;
    }
}, 10, 2);

// --- Handle Run Now Action (with security) ---
add_action('admin_init', function() {
    if (
        isset($_GET['action'], $_GET['post']) &&
        $_GET['action'] === 'gfsr_run_now' &&
        current_user_can('manage_options') &&
        isset($_GET['_wpnonce']) &&
        wp_verify_nonce($_GET['_wpnonce'], 'gfsr_run_now_' . intval($_GET['post']))
    ) {
        $post_id = intval($_GET['post']);
        do_action('gfsr_run_schedule', $post_id);
        // Reschedule the next event as if the report was just sent
        gfsr_schedule_event($post_id);
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p>Report sent successfully.</p></div>';
        });
        wp_redirect(remove_query_arg(array('action', '_wpnonce', 'post')));
        exit;
    }
});

// --- Meta Box for Schedule Details ---
add_action('add_meta_boxes', function() {
    add_meta_box('gfsr_schedule_details', 'Schedule Details', function($post) {
        $get = function($key, $default = '') use ($post) {
            $v = get_post_meta($post->ID, $key, true);
            return $v !== '' ? $v : $default;
        };
        $selected_form_id = $get('gfsr_form_id');
        $selected_fields = array_filter(array_map('trim', explode(',', $get('gfsr_fields'))));
        $system_fields = array(
            'id' => 'Entry ID',
            'date_created' => 'Submission Date',
            'ip' => 'IP Address',
            'source_url' => 'Source URL',
            'user_agent' => 'User Agent',
            'payment_status' => 'Payment Status',
            'payment_date' => 'Payment Date',
            'transaction_id' => 'Transaction ID',
            'created_by' => 'Created By (User ID)'
        );
        $field_options = array();
        if (class_exists('GFAPI') && $selected_form_id) {
            $form = GFAPI::get_form($selected_form_id);
            if ($form && isset($form['fields']) && is_array($form['fields'])) {
                foreach ($form['fields'] as $field) {
                    if (isset($field->inputs) && is_array($field->inputs)) {
                        foreach ($field->inputs as $input) {
                            $field_options[] = array(
                                'id' => $input['id'],
                                'label' => $field->label . ' (' . $input['label'] . ')'
                            );
                        }
                    } elseif (isset($field->id) && isset($field->label)) {
                        $field_options[] = array(
                            'id' => $field->id,
                            'label' => $field->label
                        );
                    }
                }
            }
        }
        ?>
        <style>
        .gfsr-schedule-form-table .section-title {
            font-size: 15px;
            color: #333;
            background: #f6f7f7;
            padding: 8px 10px;
            border-bottom: 1px solid #e5e5e5;
        }
        </style>
        <table class="form-table gfsr-schedule-form-table">
            <tbody>
                <tr><th colspan="2" class="section-title">Schedule Details</th></tr>
                <tr>
                    <th><label for="gfsr_schedule_type">Schedule Type</label></th>
                    <td>
                        <select name="gfsr_schedule_type" id="gfsr_schedule_type">
                            <option value="daily" <?php selected($get('gfsr_schedule_type'), 'daily'); ?>>Daily</option>
                            <option value="weekly" <?php selected($get('gfsr_schedule_type'), 'weekly'); ?>>Weekly</option>
                            <option value="monthly" <?php selected($get('gfsr_schedule_type'), 'monthly'); ?>>Monthly</option>
                        </select>
                    </td>
                </tr>
                <tr id="gfsr_weekday_row" style="display: <?php echo $get('gfsr_schedule_type') === 'weekly' ? 'table-row' : 'none'; ?>;">
                    <th><label for="gfsr_weekday">Week Day</label></th>
                    <td>
                        <select name="gfsr_weekday" id="gfsr_weekday">
                            <option value="1" <?php selected($get('gfsr_weekday'), '1'); ?>>Monday</option>
                            <option value="2" <?php selected($get('gfsr_weekday'), '2'); ?>>Tuesday</option>
                            <option value="3" <?php selected($get('gfsr_weekday'), '3'); ?>>Wednesday</option>
                            <option value="4" <?php selected($get('gfsr_weekday'), '4'); ?>>Thursday</option>
                            <option value="5" <?php selected($get('gfsr_weekday'), '5'); ?>>Friday</option>
                            <option value="6" <?php selected($get('gfsr_weekday'), '6'); ?>>Saturday</option>
                            <option value="0" <?php selected($get('gfsr_weekday'), '0'); ?>>Sunday</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="gfsr_repeat_every">Repeat Every</label></th>
                    <td><input type="number" name="gfsr_repeat_every" id="gfsr_repeat_every" value="<?php echo esc_attr($get('gfsr_repeat_every', '1')); ?>" min="1" style="width:60px;"></td>
                </tr>
                <tr>
                    <th><label for="gfsr_time">Time</label></th>
                    <td><input type="time" name="gfsr_time" id="gfsr_time" value="<?php echo esc_attr($get('gfsr_time', '14:30')); ?>"></td>
                </tr>
                <tr><th colspan="2" class="section-title">Contact Form Details</th></tr>
                <tr>
                    <th><label for="gfsr_form_id">Form</label></th>
                    <td>
                        <select name="gfsr_form_id" id="gfsr_form_id">
                            <option value="">-- Select Form --</option>
                            <?php
                            if (class_exists('GFAPI')) {
                                $forms = GFAPI::get_forms();
                                foreach ($forms as $form) {
                                    $selected = selected($get('gfsr_form_id'), $form['id'], false);
                                    echo '<option value="' . esc_attr($form['id']) . '" ' . $selected . '>' . esc_html($form['title']) . '</option>';
                                }
                            } else {
                                echo '<option value="">Gravity Forms not active</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="gfsr_fields">Fields</label></th>
                    <td>
                        <select name="gfsr_fields[]" id="gfsr_fields" multiple size="8" style="width: 100%; max-width: 400px;">
                            <optgroup label="System Fields">
                            <?php
                            foreach ($system_fields as $fid => $label) {
                                $is_selected = in_array((string)$fid, $selected_fields) ? 'selected' : '';
                                echo '<option value="' . esc_attr($fid) . '" ' . $is_selected . '>' . esc_html($label) . '</option>';
                            }
                            ?>
                            </optgroup>
                            <optgroup label="Form Fields">
                            <?php
                            if (!empty($field_options)) {
                                foreach ($field_options as $field) {
                                    $is_selected = in_array((string)$field['id'], $selected_fields) ? 'selected' : '';
                                    echo '<option value="' . esc_attr($field['id']) . '" ' . $is_selected . '>' . esc_html($field['label']) . '</option>';
                                }
                            } else {
                                echo '<option value="">Select a form to see fields</option>';
                            }
                            ?>
                            </optgroup>
                        </select>
                        <p class="description">Hold Ctrl (Windows) or Cmd (Mac) to select multiple fields.</p>
                    </td>
                </tr>
                <tr><th colspan="2" class="section-title">Email Manager</th></tr>
                <tr>
                    <th><label for="gfsr_from_name">From Name</label></th>
                    <td><input type="text" name="gfsr_from_name" id="gfsr_from_name" value="<?php echo esc_attr($get('gfsr_from_name', get_bloginfo('name'))); ?>"></td>
                </tr>
                <tr>
                    <th><label for="gfsr_from_email">From Email</label></th>
                    <td><input type="email" name="gfsr_from_email" id="gfsr_from_email" value="<?php echo esc_attr($get('gfsr_from_email', get_bloginfo('admin_email'))); ?>"></td>
                </tr>
                <tr>
                    <th><label for="gfsr_to">To</label></th>
                    <td><input type="text" name="gfsr_to" id="gfsr_to" value="<?php echo esc_attr($get('gfsr_to')); ?>" placeholder="Comma-separated emails"></td>
                </tr>
                <tr>
                    <th><label for="gfsr_subject">Subject</label></th>
                    <td><input type="text" name="gfsr_subject" id="gfsr_subject" value="<?php echo esc_attr($get('gfsr_subject', 'Gravity Forms Scheduled Report')); ?>"></td>
                </tr>
                <tr>
                    <th><label for="gfsr_message">Message</label></th>
                    <td>
                        <textarea name="gfsr_message" id="gfsr_message" rows="3" cols="50"><?php echo esc_textarea($get('gfsr_message', 'Please find out the attachment. Number of Records: {record_count}')); ?></textarea>
                        <p class="description">You can use <code>{record_count}</code> in your message to show the number of records in the report.</p>
                    </td>
                </tr>
            </tbody>
        </table>
        <script>
        jQuery(document).ready(function($) {
            // Show/hide weekday field based on schedule type
            $('#gfsr_schedule_type').on('change', function() {
                var scheduleType = $(this).val();
                if (scheduleType === 'weekly') {
                    $('#gfsr_weekday_row').show();
                } else {
                    $('#gfsr_weekday_row').hide();
                }
            });
            
            $('#gfsr_form_id').on('change', function() {
                var formId = $(this).val();
                var fieldsSelect = $('#gfsr_fields');
                
                // Clear existing form fields
                fieldsSelect.find('optgroup[label="Form Fields"]').html('<option value="">Loading fields...</option>');
                
                if (formId) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'gfsr_get_form_fields',
                            form_id: formId,
                            nonce: '<?php echo wp_create_nonce('gfsr_get_form_fields'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                var options = '';
                                $.each(response.data, function(index, field) {
                                    options += '<option value="' + field.id + '">' + field.label + '</option>';
                                });
                                fieldsSelect.find('optgroup[label="Form Fields"]').html(options);
                            } else {
                                fieldsSelect.find('optgroup[label="Form Fields"]').html('<option value="">Error loading fields</option>');
                            }
                        },
                        error: function(xhr, status, error) {
                            fieldsSelect.find('optgroup[label="Form Fields"]').html('<option value="">Error loading fields</option>');
                        }
                    });
                } else {
                    fieldsSelect.find('optgroup[label="Form Fields"]').html('<option value="">Select a form to see fields</option>');
                }
            });
        });
        </script>
        <?php
    }, 'gfsr_schedule', 'normal', 'default');
});

// AJAX endpoint to get form fields
add_action('wp_ajax_gfsr_get_form_fields', function() {
    if (!wp_verify_nonce($_POST['nonce'], 'gfsr_get_form_fields')) {
        wp_die('Security check failed');
    }
    
    $form_id = intval($_POST['form_id']);
    $fields = array();
    
    if (class_exists('GFAPI') && $form_id) {
        $form = GFAPI::get_form($form_id);
        if ($form && isset($form['fields']) && is_array($form['fields'])) {
            foreach ($form['fields'] as $field) {
                if (isset($field->inputs) && is_array($field->inputs)) {
                    foreach ($field->inputs as $input) {
                        $fields[] = array(
                            'id' => $input['id'],
                            'label' => $field->label . ' (' . $input['label'] . ')'
                        );
                    }
                } elseif (isset($field->id) && isset($field->label)) {
                    $fields[] = array(
                        'id' => $field->id,
                        'label' => $field->label
                    );
                }
            }
        }
    }
    
    wp_send_json_success($fields);
});

// --- Save Schedule Meta ---
add_action('save_post_gfsr_schedule', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    $fields = [
        'gfsr_time', 'gfsr_next_schedule',
        'gfsr_schedule_type', 'gfsr_repeat_every', 'gfsr_weekday',
        'gfsr_form_id',
        'gfsr_from_name', 'gfsr_from_email',
        'gfsr_to', 'gfsr_subject', 'gfsr_message'
    ];
    foreach ($fields as $field) {
        if (isset($_POST[$field])) {
            update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
        }
    }
    if (isset($_POST['gfsr_fields']) && is_array($_POST['gfsr_fields'])) {
        $fields_val = implode(',', array_map('sanitize_text_field', $_POST['gfsr_fields']));
        update_post_meta($post_id, 'gfsr_fields', $fields_val);
    }
    update_post_meta($post_id, 'gfsr_option', 'export');
    update_post_meta($post_id, 'gfsr_file_type', 'csv');
    gfsr_schedule_event($post_id);
});

// --- WP-Cron Scheduling for Each Schedule ---
function gfsr_schedule_event($post_id) {
    if (get_post_type($post_id) !== 'gfsr_schedule') return;
    if (get_post_status($post_id) !== 'publish') return;
    wp_clear_scheduled_hook('gfsr_run_schedule', array($post_id));
    $type = get_post_meta($post_id, 'gfsr_schedule_type', true);
    $repeat = max(1, intval(get_post_meta($post_id, 'gfsr_repeat_every', true)));
    $time = get_post_meta($post_id, 'gfsr_time', true);
    $weekday = get_post_meta($post_id, 'gfsr_weekday', true);
    if (!$type || !$time) return;
    $now = current_time('timestamp');
    $run_time = strtotime(date('Y-m-d', $now) . ' ' . $time);
    
    if ($type === 'weekly' && $weekday !== '') {
        // For weekly schedules, calculate the next occurrence of the selected weekday
        $current_weekday = date('w', $now); // 0 = Sunday, 1 = Monday, etc.
        $target_weekday = intval($weekday);
        $days_ahead = ($target_weekday - $current_weekday + 7) % 7;
        if ($days_ahead === 0 && $run_time <= $now) {
            $days_ahead = 7; // If today is the target day but time has passed, schedule for next week
        }
        $run_time = strtotime('+' . $days_ahead . ' days', strtotime(date('Y-m-d', $now) . ' ' . $time));
    } else {
        if ($run_time <= $now) {
            if ($type === 'daily') $run_time = strtotime('+1 day', $run_time);
            if ($type === 'weekly') $run_time = strtotime('+1 week', $run_time);
            if ($type === 'monthly') $run_time = strtotime('+1 month', $run_time);
        }
    }
    
    $interval = ($type === 'daily') ? DAY_IN_SECONDS * $repeat :
                (($type === 'weekly') ? WEEK_IN_SECONDS * $repeat :
                (30 * DAY_IN_SECONDS * $repeat));
    wp_schedule_event($run_time, 'gfsr_custom_' . $post_id, 'gfsr_run_schedule', array($post_id));
}
add_action('before_delete_post', function($post_id) {
    if (get_post_type($post_id) === 'gfsr_schedule') {
        wp_clear_scheduled_hook('gfsr_run_schedule', array($post_id));
    }
});

// --- Cron Callback: Generate and Email Report ---
add_action('gfsr_run_schedule', function($post_id) {
    $form_id = get_post_meta($post_id, 'gfsr_form_id', true);
    $fields = get_post_meta($post_id, 'gfsr_fields', true);
    $to = get_post_meta($post_id, 'gfsr_to', true);
    $subject = get_post_meta($post_id, 'gfsr_subject', true);
    $message = get_post_meta($post_id, 'gfsr_message', true);
    $from_name = get_post_meta($post_id, 'gfsr_from_name', true);
    $from_email = get_post_meta($post_id, 'gfsr_from_email', true);
    $last_run = get_post_meta($post_id, 'gfsr_last_run', true);
    $now = current_time('mysql');
    $record_count = 0;
    $csv_path = '';
    $system_fields = array(
        'id' => 'Entry ID',
        'date_created' => 'Submission Date',
        'ip' => 'IP Address',
        'source_url' => 'Source URL',
        'user_agent' => 'User Agent',
        'payment_status' => 'Payment Status',
        'payment_date' => 'Payment Date',
        'transaction_id' => 'Transaction ID',
        'created_by' => 'Created By (User ID)'
    );
    if (class_exists('GFAPI') && $form_id) {
        $search_criteria = array();
        if ($last_run) {
            $search_criteria['start_date'] = $last_run;
        }
        $entries = GFAPI::get_entries($form_id, $search_criteria);
        $field_ids = array_map('trim', explode(',', $fields));
        $csv_rows = array();
        $header = array();
        $form = GFAPI::get_form($form_id);
        $form_name = $form && isset($form['title']) ? strtoupper(str_replace([' ', '/','\\'], '-', $form['title'])) : 'FORM';
        $schedule_type = get_post_meta($post_id, 'gfsr_schedule_type', true);
        $date_str = date('Ymd');
        $upload_dir = wp_upload_dir();
        $csv_path = $upload_dir['basedir'] . '/gfsr-report-' . $post_id . '-' . $form_name . '-' . $schedule_type . '-' . $date_str . '.csv';
        
        // Try to create the CSV file
        $fp = fopen($csv_path, 'w');
        if ($fp === false) {
            // If file creation fails, try using a different directory
            $csv_path = sys_get_temp_dir() . '/gfsr-report-' . $post_id . '-' . $form_name . '-' . $schedule_type . '-' . $date_str . '.csv';
            $fp = fopen($csv_path, 'w');
        }
        
        if ($fp !== false) {
            foreach ($field_ids as $fid) {
                if (isset($system_fields[$fid])) {
                    $header[] = $system_fields[$fid];
                } else {
                    $label = $fid;
                    if ($form && isset($form['fields']) && is_array($form['fields'])) {
                        foreach ($form['fields'] as $gf_field) {
                            if (isset($gf_field->inputs) && is_array($gf_field->inputs)) {
                                foreach ($gf_field->inputs as $input) {
                                    if ((string)$input['id'] === $fid) {
                                        $label = $gf_field->label . ' (' . $input['label'] . ')';
                                        break 2;
                                    }
                                }
                            } elseif (isset($gf_field->id) && (string)$gf_field->id === $fid) {
                                $label = isset($gf_field->label) ? $gf_field->label : $fid;
                                break;
                            }
                        }
                    }
                    $header[] = $label;
                }
            }
            $csv_rows[] = $header;
            if (!empty($entries)) {
                foreach ($entries as $entry) {
                    $row = array();
                    foreach ($field_ids as $fid) {
                        if (isset($system_fields[$fid])) {
                            $row[] = isset($entry[$fid]) ? $entry[$fid] : '';
                        } else {
                            $row[] = isset($entry[$fid]) ? $entry[$fid] : '';
                        }
                    }
                    $csv_rows[] = $row;
                }
            }
            $record_count = count($csv_rows) - 1;
            
            // Write CSV data to file
            foreach ($csv_rows as $csv_row) {
                fputcsv($fp, $csv_row);
            }
            fclose($fp);
        }
    }
    $message = str_replace('{record_count}', $record_count, $message);
    $from_header = $from_name && $from_email ? $from_name . ' <' . $from_email . '>' : $from_email;
    $headers = array('From: ' . $from_header);
    if ($csv_path && file_exists($csv_path)) {
        wp_mail($to, $subject, $message, $headers, array($csv_path));
        // Delete the CSV file after sending
        unlink($csv_path);
    } else {
        wp_mail($to, $subject, $message, $headers);
    }
    update_post_meta($post_id, 'gfsr_last_run', $now);
    gfsr_schedule_event($post_id);
});
