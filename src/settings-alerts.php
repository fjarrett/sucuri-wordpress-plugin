<?php

/**
 * Code related to the settings-alerts.php interface.
 *
 * @package Sucuri Security
 * @subpackage settings-alerts.php
 * @copyright Since 2010 Sucuri Inc.
 */

if (!defined('SUCURISCAN_INIT') || SUCURISCAN_INIT !== true) {
    if (!headers_sent()) {
        /* Report invalid access if possible. */
        header('HTTP/1.1 403 Forbidden');
    }
    exit(1);
}

/**
 * Returns the HTML to configure who receives the email alerts.
 *
 * By default the plugin sends the email notifications about the security events
 * to the first email address used during the installation of the website. This
 * is usually the email of the website owner. The plugin allows to add more
 * emails to the list so the alerts are sent to other people.
 *
 * @param bool $nonce True if the CSRF protection worked, false otherwise.
 * @return string HTML for the email alert recipients.
 */
function sucuriscan_settings_alerts_recipients($nonce)
{
    $params = array();
    $params['Alerts.Recipients'] = '';
    $notify_to = SucuriScanOption::getOption(':notify_to');
    $emails = array();

    // If the recipient list is not empty, explode.
    if (is_string($notify_to)) {
        $emails = explode(',', $notify_to);
    }

    // Process form submission.
    if ($nonce) {
        // Add new email address to the alert recipient list.
        if (SucuriScanRequest::post(':save_recipient') !== false) {
            $new_email = SucuriScanRequest::post(':recipient');

            if (SucuriScan::isValidEmail($new_email)) {
                $emails[] = $new_email;
                $message = 'Sucuri will send email alerts to: <code>' . $new_email . '</code>';

                SucuriScanOption::updateOption(':notify_to', implode(',', $emails));
                SucuriScanEvent::reportInfoEvent($message);
                SucuriScanEvent::notifyEvent('plugin_change', $message);
                SucuriScanInterface::info($message);
            } else {
                SucuriScanInterface::error('Email format not supported.');
            }
        }

        // Delete one or more recipients from the list.
        if (SucuriScanRequest::post(':delete_recipients') !== false) {
            $deleted_emails = array();
            $recipients = SucuriScanRequest::post(':recipients', '_array');

            foreach ($recipients as $address) {
                if (in_array($address, $emails)) {
                    $deleted_emails[] = $address;
                    $index = array_search($address, $emails);
                    unset($emails[$index]);
                }
            }

            if (!empty($deleted_emails)) {
                $deleted_emails_str = implode(",\x20", $deleted_emails);
                $message = 'Sucuri will not send email alerts to: <code>' . $deleted_emails_str . '</code>';

                SucuriScanOption::updateOption(':notify_to', implode(',', $emails));
                SucuriScanEvent::reportInfoEvent($message);
                SucuriScanEvent::notifyEvent('plugin_change', $message);
                SucuriScanInterface::info($message);
            }
        }

        // Debug ability of the plugin to send email alerts correctly.
        if (SucuriScanRequest::post(':debug_email')) {
            $recipients = SucuriScanOption::getOption(':notify_to');
            SucuriScanMail::sendMail(
                $recipients,
                'Test Email Alert',
                sprintf('Test email alert sent at %s', date('r')),
                array('Force' => true)
            );
            SucuriScanInterface::info('Test email alert sent, check your inbox.');
        }
    }


    foreach ($emails as $email) {
        if (!empty($email)) {
            $params['Alerts.Recipients'] .=
            SucuriScanTemplate::getSnippet('settings-alerts-recipients', array(
                'Recipient.Email' => $email,
            ));
        }
    }

    return SucuriScanTemplate::getSection('settings-alerts-recipients', $params);
}

/**
 * Returns the HTML to configure the list of trusted IPs.
 *
 * The plugin will not report security events coming from these IP addresses. If
 * the users are all from the same network, like in an office, they can include
 * the IP of the entire LAN as a valid CIDR format.
 *
 * @return string HTML for the trusted IP addresses.
 */
function sucuriscan_settings_alerts_trustedips()
{
    $params = array();
    $params['TrustedIPs.List'] = '';
    $params['TrustedIPs.NoItems.Visibility'] = 'visible';

    $cache = new SucuriScanCache('trustip');

    if (SucuriScanInterface::checkNonce()) {
        // Trust and IP address to ignore alerts for a subnet.
        if ($trust_ip = SucuriScanRequest::post(':trust_ip')) {
            if (SucuriScan::isValidIP($trust_ip) || SucuriScan::isValidCIDR($trust_ip)) {
                $ip_info = SucuriScan::getIPInfo($trust_ip);
                $ip_info['added_at'] = SucuriScan::localTime();
                $cache_key = md5($ip_info['remote_addr']);

                if ($cache->exists($cache_key)) {
                    SucuriScanInterface::error('The IP address specified was already trusted.');
                } elseif ($cache->add($cache_key, $ip_info)) {
                    $message = 'Changes from <code>' . $trust_ip . '</code> will be ignored';

                    SucuriScanEvent::reportWarningEvent($message);
                    SucuriScanInterface::info($message);
                } else {
                    SucuriScanInterface::error('The new entry was not saved in the datastore file.');
                }
            }
        }

        // Trust and IP address to ignore alerts for a subnet.
        if ($del_trust_ip = SucuriScanRequest::post(':del_trust_ip', '_array')) {
            foreach ($del_trust_ip as $cache_key) {
                $cache->delete($cache_key);
            }

            SucuriScanInterface::info('The IP addresses selected were deleted successfully.');
        }
    }

    $trusted_ips = $cache->getAll();

    if ($trusted_ips) {
        foreach ($trusted_ips as $cache_key => $ip_info) {
            if ($ip_info->cidr_range == 32) {
                $ip_info->cidr_format = 'n/a';
            }

            $params['TrustedIPs.List'] .=
            SucuriScanTemplate::getSnippet('settings-alerts-trustedips', array(
                'TrustIP.CacheKey' => $cache_key,
                'TrustIP.RemoteAddr' => SucuriScan::escape($ip_info->remote_addr),
                'TrustIP.CIDRFormat' => SucuriScan::escape($ip_info->cidr_format),
                'TrustIP.AddedAt' => SucuriScan::datetime($ip_info->added_at),
            ));
        }

        $params['TrustedIPs.NoItems.Visibility'] = 'hidden';
    }

    return SucuriScanTemplate::getSection('settings-alerts-trustedips', $params);
}

/**
 * Returns the HTML to configure the subject for the email alerts.
 *
 * @param bool $nonce True if the CSRF protection worked, false otherwise.
 * @return string HTML for the email alert subject option.
 */
function sucuriscan_settings_alerts_subject($nonce)
{
    global $sucuriscan_email_subjects;

    $params = array(
        'Alerts.Subject' => '',
        'Alerts.CustomChecked' => '',
        'Alerts.CustomValue' => '',
    );

    // Process form submission to change the alert settings.
    if ($nonce) {
        if ($email_subject = SucuriScanRequest::post(':email_subject')) {
            $current_value = SucuriScanOption::getOption(':email_subject');
            $new_email_subject = false;

            /**
             * Validate the format of the email subject format.
             *
             * If the user chooses the option to build the subject of the email alerts
             * manually we will need to validate the characters. Otherwise we will need to
             * check if the pseudo-tags selected by the user are allowed and supported.
             */
            if ($email_subject === 'custom') {
                $format_pattern = '/^[0-9a-zA-Z:,\s]+$/';
                $custom_subject = SucuriScanRequest::post(':custom_email_subject');

                if ($custom_subject !== false
                    && !empty($custom_subject)
                    && @preg_match($format_pattern, $custom_subject)
                ) {
                    $new_email_subject = trim($custom_subject);
                } else {
                    SucuriScanInterface::error('Invalid characters in the email subject.');
                }
            } elseif (is_array($sucuriscan_email_subjects)
                && in_array($email_subject, $sucuriscan_email_subjects)
            ) {
                $new_email_subject = trim($email_subject);
            }

            // Proceed with the operation saving the new subject.
            if ($new_email_subject !== false
                && $current_value !== $new_email_subject
            ) {
                $message = 'Email subject set to <code>' . $new_email_subject . '</code>';

                SucuriScanOption::updateOption(':email_subject', $new_email_subject);
                SucuriScanEvent::reportInfoEvent($message);
                SucuriScanEvent::notifyEvent('plugin_change', $message);
                SucuriScanInterface::info($message);
            }
        }
    }

    // Build the HTML code for the interface.
    if (is_array($sucuriscan_email_subjects)) {
        $email_subject = SucuriScanOption::getOption(':email_subject');
        $is_official_subject = false;

        foreach ($sucuriscan_email_subjects as $subject_format) {
            if ($email_subject === $subject_format) {
                $is_official_subject = true;
                $checked = 'checked="checked"';
            } else {
                $checked = '';
            }

            $params['Alerts.Subject'] .=
            SucuriScanTemplate::getSnippet('settings-alerts-subject', array(
                'EmailSubject.Name' => $subject_format,
                'EmailSubject.Value' => $subject_format,
                'EmailSubject.Checked' => $checked,
            ));
        }

        if ($is_official_subject === false) {
            $params['Alerts.CustomChecked'] = 'checked="checked"';
            $params['Alerts.CustomValue'] = $email_subject;
        }
    }

    return SucuriScanTemplate::getSection('settings-alerts-subject', $params);
}

/**
 * Returns the HTML to configure the maximum number of alerts per hour.
 *
 * @param bool $nonce True if the CSRF protection worked, false otherwise.
 * @return string HTML for the maximum number of alerts per hour.
 */
function sucuriscan_settings_alerts_perhour($nonce)
{
    global $sucuriscan_emails_per_hour;

    $params = array();
    $params['Alerts.PerHour'] = '';

    if ($nonce) {
        // Update the value for the maximum emails per hour.
        if ($per_hour = SucuriScanRequest::post(':emails_per_hour')) {
            if (array_key_exists($per_hour, $sucuriscan_emails_per_hour)) {
                $per_hour_label = strtolower($sucuriscan_emails_per_hour[$per_hour]);
                $message = 'Maximum alerts per hour set to <code>' . $per_hour_label . '</code>';

                SucuriScanOption::updateOption(':emails_per_hour', $per_hour);
                SucuriScanEvent::reportInfoEvent($message);
                SucuriScanEvent::notifyEvent('plugin_change', $message);
                SucuriScanInterface::info($message);
            } else {
                SucuriScanInterface::error('Invalid value for the maximum emails per hour.');
            }
        }
    }

    $per_hour = (int) SucuriScanOption::getOption(':emails_per_hour');
    $per_hour_options = SucuriScanTemplate::selectOptions($sucuriscan_emails_per_hour, $per_hour);
    $params['Alerts.PerHour'] = $per_hour_options;

    return SucuriScanTemplate::getSection('settings-alerts-perhour', $params);
}

/**
 * Returns the HTML to configure the trigger for the brute-force alerts.
 *
 * @param bool $nonce True if the CSRF protection worked, false otherwise.
 * @return string HTML for the trigger for the brute-force alerts.
 */
function sucuriscan_settings_alerts_bruteforce($nonce)
{
    global $sucuriscan_maximum_failed_logins;

    $params = array();
    $params['Alerts.BruteForce'] = '';

    if ($nonce) {
        // Update the maximum failed logins per hour before consider it a brute-force attack.
        if ($maximum = SucuriScanRequest::post(':maximum_failed_logins')) {
            if (array_key_exists($maximum, $sucuriscan_maximum_failed_logins)) {
                $message = 'Consider brute-force attack after <code>' . $maximum . '</code> failed logins per hour';

                SucuriScanOption::updateOption(':maximum_failed_logins', $maximum);
                SucuriScanEvent::reportInfoEvent($message);
                SucuriScanEvent::notifyEvent('plugin_change', $message);
                SucuriScanInterface::info($message);
            } else {
                SucuriScanInterface::error('Invalid value for the brute-force alerts.');
            }
        }
    }

    $maximum = (int) SucuriScanOption::getOption(':maximum_failed_logins');
    $maximum_options = SucuriScanTemplate::selectOptions($sucuriscan_maximum_failed_logins, $maximum);
    $params['Alerts.BruteForce'] = $maximum_options;

    return SucuriScanTemplate::getSection('settings-alerts-bruteforce', $params);
}

/**
 * Returns the HTML to configure which alerts will be sent.
 *
 * @param bool $nonce True if the CSRF protection worked, false otherwise.
 * @return string HTML for the alerts that will be sent.
 */
function sucuriscan_settings_alerts_events($nonce)
{
    global $sucuriscan_notify_options;

    $params = array();
    $params['Alerts.Events'] = '';

    // Process form submission to change the alert settings.
    if ($nonce) {
        // Update the notification settings.
        if (SucuriScanRequest::post(':save_alert_events') !== false) {
            $ucounter = 0;

            foreach ($sucuriscan_notify_options as $alert_type => $alert_label) {
                $option_value = SucuriScanRequest::post($alert_type, '(1|0)');

                if ($option_value !== false) {
                    $current_value = SucuriScanOption::getOption($alert_type);
                    $option_value = ($option_value == 1) ? 'enabled' : 'disabled';

                    // Check that the option value was actually changed.
                    if ($current_value !== $option_value) {
                        $written = SucuriScanOption::updateOption($alert_type, $option_value);

                        if ($written === true) {
                            $ucounter += 1;
                        }
                    }
                }
            }

            if ($ucounter > 0) {
                $message = 'A total of ' . $ucounter . ' alert events were changed';

                SucuriScanEvent::reportInfoEvent($message);
                SucuriScanEvent::notifyEvent('plugin_change', $message);
                SucuriScanInterface::info($message);
            }
        }
    }

    // Build the HTML code for the interface.
    if (is_array($sucuriscan_notify_options)) {
        $pattern = '/^([a-z]+:)?(.+)/';

        foreach ($sucuriscan_notify_options as $alert_type => $alert_label) {
            $alert_value = SucuriScanOption::getOption($alert_type);
            $checked = ($alert_value == 'enabled') ? 'checked="checked"' : '';
            $alert_icon = '';

            if (@preg_match($pattern, $alert_label, $match)) {
                $alert_group = str_replace(':', '', $match[1]);
                $alert_label = $match[2];

                switch ($alert_group) {
                    case 'user':
                        $alert_icon = 'dashicons-before dashicons-admin-users';
                        break;
                    case 'plugin':
                        $alert_icon = 'dashicons-before dashicons-admin-plugins';
                        break;
                    case 'theme':
                        $alert_icon = 'dashicons-before dashicons-admin-appearance';
                        break;
                }
            }

            $params['Alerts.Events'] .=
            SucuriScanTemplate::getSnippet('settings-alerts-events', array(
                'Event.Name' => $alert_type,
                'Event.Checked' => $checked,
                'Event.Label' => $alert_label,
                'Event.LabelIcon' => $alert_icon,
            ));
        }
    }

    return SucuriScanTemplate::getSection('settings-alerts-events', $params);
}

/**
 * Returns the HTML to configure the post-types that will be ignored.
 *
 * @return string HTML for the ignored post-types.
 */
function sucuriscan_settings_alerts_ignore_posts()
{
    $notify_new_site_content = SucuriScanOption::getOption(':notify_post_publication');

    $params = array(
        'IgnoreRules.MessageVisibility' => 'visible',
        'IgnoreRules.PostTypes' => '',
    );

    if (SucuriScanInterface::checkNonce()) {
        // Ignore a new event for email alerts.
        if ($action = SucuriScanRequest::post(':ignorerule_action', '(add|remove)')) {
            $ignore_rule = SucuriScanRequest::post(':ignorerule');

            if ($action == 'add') {
                if (!preg_match('/^[a-z_]+$/', $ignore_rule)) {
                    SucuriScanInterface::error('Only lowercase letters and underscores are allowed.');
                } elseif (SucuriScanOption::addIgnoredEvent($ignore_rule)) {
                    SucuriScanInterface::info('Post-type ignored successfully.');
                    SucuriScanEvent::reportWarningEvent('Changes in <code>' . $ignore_rule . '</code> post-type will be ignored');
                } else {
                    SucuriScanInterface::error('The post-type is invalid or it may be already ignored.');
                }
            } elseif ($action == 'remove') {
                SucuriScanOption::removeIgnoredEvent($ignore_rule);
                SucuriScanInterface::info('Post-type removed from the list successfully.');
                SucuriScanEvent::reportNoticeEvent('Changes in <code>' . $ignore_rule . '</code> post-type will not be ignored');
            }
        }
    }

    if ($notify_new_site_content == 'enabled') {
        $post_types = get_post_types();
        $ignored_events = SucuriScanOption::getIgnoredEvents();

        $params['IgnoreRules.MessageVisibility'] = 'hidden';

        /* Include custom non-registered post-types */
        foreach ($ignored_events as $event => $time) {
            if (!array_key_exists($event, $post_types)) {
                $post_types[$event] = $event;
            }
        }

        /* Check which post-types are being ignored */
        foreach ($post_types as $post_type) {
            $post_type_title = ucwords(str_replace('_', chr(32), $post_type));

            if (array_key_exists($post_type, $ignored_events)) {
                $is_ignored_text = 'YES';
                $was_ignored_at = SucuriScan::datetime($ignored_events[ $post_type ]);
                $is_ignored_class = 'danger';
                $button_action = 'remove';
                $button_text = 'Receive These Alerts';
            } else {
                $is_ignored_text = 'NO';
                $was_ignored_at = '--';
                $is_ignored_class = 'success';
                $button_action = 'add';
                $button_text = 'Stop These Alerts';
            }

            $params['IgnoreRules.PostTypes'] .=
            SucuriScanTemplate::getSnippet('settings-alerts-ignore-posts', array(
                'IgnoreRules.PostTypeTitle' => $post_type_title,
                'IgnoreRules.IsIgnored' => $is_ignored_text,
                'IgnoreRules.WasIgnoredAt' => $was_ignored_at,
                'IgnoreRules.IsIgnoredClass' => $is_ignored_class,
                'IgnoreRules.PostType' => $post_type,
                'IgnoreRules.Action' => $button_action,
                'IgnoreRules.ButtonText' => $button_text,
            ));
        }
    }

    return SucuriScanTemplate::getSection('settings-alerts-ignore-posts', $params);
}
