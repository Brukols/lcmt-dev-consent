<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}
delete_option('lcmt_dev_consent_settings');
