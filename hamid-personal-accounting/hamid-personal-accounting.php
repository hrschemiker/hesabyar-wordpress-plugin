<?php
/**
 * Plugin Name: HesabYar — Personal Accounting
 * Description: افزونه حسابداری شخصی فارسی با حساب‌ها، تراکنش‌ها، طلب و بدهی، دارایی‌ها، گزارش و نمودار سبک با رابط کاربری مدرن فارسی. دارای اتصال دوطرفه به نرم‌افزار دسکتاپ حساب‌یار.
 * Version: 3.18.0
 * Author: hrschemiker
 * Text Domain: hamid-personal-accounting
 */

if (!defined('ABSPATH')) { exit; }

final class Hamid_Personal_Accounting {
    const VERSION = '3.18.0';
    const ROLE = 'personal_finance_manager';
    const CAP = 'hpa_manage_accounting';
    const AUTHORIZED_EMAIL = 'hrschemiker@gmail.com';
    const OPTION = 'hpa_settings';
    const NONCE = 'hpa_nonce_action';
    private static $instance = null;
    private $tables = [];
    private $last_upload_ids = [];

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $p = $wpdb->prefix . 'hpa_';
        $this->tables = [
            'accounts' => $p . 'accounts',
            'categories' => $p . 'categories',
            'transactions' => $p . 'transactions',
            'debts' => $p . 'debts',
            'receivables' => $p . 'receivables',
            'assets' => $p . 'assets',
            'asset_files' => $p . 'asset_files',
            'settings' => $p . 'settings',
            'rates' => $p . 'rates',
            'loans' => $p . 'loans',
            'loan_installments' => $p . 'loan_installments',
            'checks' => $p . 'checks',
            'recurring' => $p . 'recurring',
            'attachments' => $p . 'attachments',
            'goals' => $p . 'goals',
            'transaction_splits' => $p . 'transaction_splits',
            'transaction_items' => $p . 'transaction_items',
            'deleted_items' => $p . 'deleted_items',
            'archives' => $p . 'archives',
        ];
        add_shortcode('hamid_personal_accounting', [$this, 'shortcode']);
        add_action('wp_enqueue_scripts', [$this, 'assets']);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_post_hpa_save_settings', [$this, 'save_settings']);
        add_action('admin_post_hpa_save_account', [$this, 'save_account']);
        add_action('admin_post_hpa_delete_account', [$this, 'delete_account']);
        add_action('admin_post_hpa_save_category', [$this, 'save_category']);
        add_action('admin_post_hpa_delete_category', [$this, 'delete_category']);
        add_action('admin_post_hpa_save_transaction', [$this, 'save_transaction']);
        add_action('admin_post_hpa_delete_transaction', [$this, 'delete_transaction']);
        add_action('admin_post_hpa_save_debt', [$this, 'save_debt']);
        add_action('admin_post_hpa_save_receivable', [$this, 'save_receivable']);
        add_action('admin_post_hpa_delete_debt', [$this, 'delete_debt']);
        add_action('admin_post_hpa_delete_receivable', [$this, 'delete_receivable']);
        add_action('admin_post_hpa_save_asset', [$this, 'save_asset']);
        add_action('admin_post_hpa_delete_asset', [$this, 'delete_asset']);
        add_action('admin_post_hpa_save_rate', [$this, 'save_rate']);
        add_action('admin_post_hpa_delete_rate', [$this, 'delete_rate']);
        add_action('admin_post_hpa_fetch_rates', [$this, 'manual_fetch_rates']);
        add_action('admin_post_hpa_save_loan', [$this, 'save_loan']);
        add_action('admin_post_hpa_delete_loan', [$this, 'delete_loan']);
        add_action('admin_post_hpa_save_check', [$this, 'save_check']);
        add_action('admin_post_hpa_delete_check', [$this, 'delete_check']);
        add_action('admin_post_hpa_save_recurring', [$this, 'save_recurring']);
        add_action('admin_post_hpa_reconcile_account', [$this, 'reconcile_account']);
        add_action('admin_post_hpa_save_goal', [$this, 'save_goal']);
        add_action('admin_post_hpa_delete_goal', [$this, 'delete_goal']);
        add_action('admin_post_hpa_delete_recurring', [$this, 'delete_recurring']);
        add_action('admin_post_hpa_export_backup', [$this, 'export_backup']);
        add_action('admin_post_hpa_import_backup', [$this, 'import_backup']);
        add_action('admin_post_hpa_restore_deleted_item', [$this, 'restore_deleted_item']);
        add_action('admin_post_hpa_permanent_delete_item', [$this, 'permanent_delete_item']);
        add_action('admin_post_hpa_reopen_account', [$this, 'reopen_account']);
        add_action('admin_post_hpa_save_archive', [$this, 'save_archive']);
        add_action('admin_post_hpa_delete_archive', [$this, 'delete_archive']);
        add_action('admin_post_hpa_archive_report', [$this, 'archive_report']);
        add_action('hpa_daily_rate_update', [$this, 'fetch_rates_from_tgju']);
        add_action('init', [$this, 'maybe_upgrade']);
        add_filter('show_admin_bar', [$this, 'maybe_hide_admin_bar']);
        // اتصال نرم‌افزار دسکتاپ حساب‌یار (REST API)
        add_action('rest_api_init', [$this, 'register_app_api']);
    }

    public static function activate() {
        $self = self::instance();
        $self->create_tables();
        $self->ensure_person_columns();
        $self->ensure_finance_extensions();
        $self->ensure_hide_amount_column();
        $self->create_role_and_caps();
        $self->seed_defaults();
        if (!get_option(self::OPTION)) {
            update_option(self::OPTION, [
                'allowed_roles' => ['administrator'],
                'theme_mode' => 'light',
                'default_currency' => 'toman',
                'auto_rate_update' => 1,
                'rate_provider' => 'tgju',
                'security_pin' => '',
            ], false);
        }
        if (!wp_next_scheduled('hpa_daily_rate_update')) {
            wp_schedule_event(time() + 300, 'daily', 'hpa_daily_rate_update');
        }
        update_option('hpa_plugin_version', self::VERSION, false);
        $self->fetch_rates_from_tgju(true);
    }

    public static function deactivate() {
        wp_clear_scheduled_hook('hpa_daily_rate_update');
    }


    public function maybe_upgrade() {
        if (get_option('hpa_plugin_version') === self::VERSION) return;
        $this->create_tables();
        $this->ensure_person_columns();
        $this->ensure_finance_extensions();
        $this->ensure_hide_amount_column();
        $this->create_role_and_caps();
        $this->seed_defaults();
        if (!wp_next_scheduled('hpa_daily_rate_update')) {
            wp_schedule_event(time() + 300, 'daily', 'hpa_daily_rate_update');
        }
        update_option('hpa_plugin_version', self::VERSION, false);
        $this->fetch_rates_from_tgju(true);
    }

    private function create_role_and_caps() {
        add_role(self::ROLE, 'مدیر حسابداری شخصی', ['read' => true, self::CAP => true]);
        $admin = get_role('administrator');
        if ($admin && !$admin->has_cap(self::CAP)) $admin->add_cap(self::CAP);
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->tables['accounts']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza',
            name VARCHAR(190) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'cash',
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            opening_balance DECIMAL(20,2) NOT NULL DEFAULT 0,
            bank_name VARCHAR(190) NULL,
            account_number VARCHAR(80) NULL,
            card_number VARCHAR(80) NULL,
            iban VARCHAR(80) NULL,
            icon VARCHAR(20) NULL,
            color VARCHAR(20) NULL,
            note TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY person_key(person_key), KEY type(type), KEY currency(currency)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['categories']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(190) NOT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'expense',
            icon VARCHAR(20) NULL,
            color VARCHAR(20) NULL,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_essential TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY type(type)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['transactions']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza',
            from_person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza',
            to_person_key VARCHAR(40) NOT NULL DEFAULT 'samira',
            account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            to_account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            type VARCHAR(40) NOT NULL DEFAULT 'expense',
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            jalali_date VARCHAR(12) NOT NULL,
            gregorian_date DATE NOT NULL,
            description TEXT NULL,
            transaction_place VARCHAR(190) NULL,
            receipt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            tags VARCHAR(250) NULL,
            source_loan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            loan_installment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            debt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            receivable_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            check_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            asset_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            asset_quantity DECIMAL(20,8) NOT NULL DEFAULT 0,
            recurring_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            recurring_due_jalali_date VARCHAR(12) NULL,
            recurring_due_gregorian_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'done',
            hide_amount TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY person_key(person_key), KEY account_id(account_id), KEY category_id(category_id), KEY source_loan_id(source_loan_id), KEY loan_installment_id(loan_installment_id), KEY debt_id(debt_id), KEY receivable_id(receivable_id), KEY check_id(check_id), KEY asset_id(asset_id), KEY recurring_id(recurring_id), KEY gregorian_date(gregorian_date), KEY type(type)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['debts']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            person_name VARCHAR(190) NOT NULL,
            phone VARCHAR(80) NULL,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            jalali_date VARCHAR(12) NOT NULL,
            gregorian_date DATE NOT NULL,
            due_jalali_date VARCHAR(12) NULL,
            due_gregorian_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            note TEXT NULL,
            receipt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY due_gregorian_date(due_gregorian_date), KEY status(status)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['receivables']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            person_name VARCHAR(190) NOT NULL,
            phone VARCHAR(80) NULL,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            jalali_date VARCHAR(12) NOT NULL,
            gregorian_date DATE NOT NULL,
            due_jalali_date VARCHAR(12) NULL,
            due_gregorian_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            note TEXT NULL,
            receipt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY due_gregorian_date(due_gregorian_date), KEY status(status)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['assets']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza',
            title VARCHAR(190) NOT NULL,
            asset_group VARCHAR(40) NOT NULL DEFAULT 'gold',
            model VARCHAR(120) NULL,
            purity VARCHAR(80) NULL,
            weight DECIMAL(20,4) NULL,
            quantity DECIMAL(20,8) NULL,
            unit VARCHAR(30) NULL,
            purchase_price DECIMAL(20,2) NOT NULL DEFAULT 0,
            unit_price DECIMAL(20,8) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            jalali_date VARCHAR(12) NOT NULL,
            gregorian_date DATE NOT NULL,
            purchase_place VARCHAR(190) NULL,
            source_loan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            goal_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            funding_source VARCHAR(40) NOT NULL DEFAULT 'personal',
            receipt_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY person_key(person_key), KEY source_loan_id(source_loan_id), KEY asset_group(asset_group), KEY gregorian_date(gregorian_date)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['asset_files']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            asset_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY asset_id(asset_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['rates']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rate_key VARCHAR(80) NOT NULL,
            title VARCHAR(190) NOT NULL,
            type VARCHAR(40) NOT NULL DEFAULT 'currency',
            price DECIMAL(24,6) NOT NULL DEFAULT 0,
            unit VARCHAR(40) NOT NULL DEFAULT 'toman',
            source VARCHAR(120) NULL,
            jalali_date VARCHAR(12) NULL,
            gregorian_date DATE NULL,
            note TEXT NULL,
            is_manual TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), UNIQUE KEY rate_key(rate_key), KEY type(type), KEY gregorian_date(gregorian_date)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['loans']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza',
            title VARCHAR(190) NOT NULL,
            lender VARCHAR(190) NULL,
            principal_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            received_jalali_date VARCHAR(12) NULL,
            received_gregorian_date DATE NULL,
            used_for TEXT NULL,
            total_installments INT UNSIGNED NOT NULL DEFAULT 0,
            paid_installments INT UNSIGNED NOT NULL DEFAULT 0,
            installment_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            variable_installments TINYINT(1) NOT NULL DEFAULT 0,
            installment_overrides TEXT NULL,
            first_due_jalali_date VARCHAR(12) NULL,
            first_due_gregorian_date DATE NULL,
            last_due_jalali_date VARCHAR(12) NULL,
            last_due_gregorian_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY person_key(person_key), KEY status(status), KEY first_due_gregorian_date(first_due_gregorian_date), KEY last_due_gregorian_date(last_due_gregorian_date)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['loan_installments']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            loan_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            installment_no INT UNSIGNED NOT NULL DEFAULT 0,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            due_jalali_date VARCHAR(12) NULL,
            due_gregorian_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            paid_transaction_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY loan_id(loan_id), KEY due_gregorian_date(due_gregorian_date), KEY status(status), KEY paid_transaction_id(paid_transaction_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['checks']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza',
            title VARCHAR(190) NOT NULL,
            check_count INT UNSIGNED NOT NULL DEFAULT 1,
            amount_each DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            first_due_jalali_date VARCHAR(12) NULL,
            first_due_gregorian_date DATE NULL,
            used_for TEXT NULL,
            include_in_assets TINYINT(1) NOT NULL DEFAULT 0,
            paid_transaction_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            paid_jalali_date VARCHAR(12) NULL,
            paid_gregorian_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'open',
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY person_key(person_key), KEY status(status), KEY first_due_gregorian_date(first_due_gregorian_date), KEY paid_transaction_id(paid_transaction_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['recurring']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza',
            title VARCHAR(190) NOT NULL,
            category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            account_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            type VARCHAR(40) NOT NULL DEFAULT 'expense',
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            fee_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            interval_type VARCHAR(30) NOT NULL DEFAULT 'monthly',
            start_jalali_date VARCHAR(12) NULL,
            start_gregorian_date DATE NULL,
            next_jalali_date VARCHAR(12) NULL,
            next_gregorian_date DATE NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY person_key(person_key), KEY next_gregorian_date(next_gregorian_date), KEY status(status)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['attachments']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            object_type VARCHAR(40) NOT NULL,
            object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            attachment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY object_type(object_type), KEY object_id(object_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['goals']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            title VARCHAR(190) NOT NULL,
            target_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            target_jalali_date VARCHAR(12) NULL,
            target_gregorian_date DATE NULL,
            note TEXT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY(id), KEY user_id(user_id), KEY status(status), KEY target_gregorian_date(target_gregorian_date)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['transaction_splits']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            category_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id), KEY transaction_id(transaction_id), KEY category_id(category_id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['transaction_items']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            transaction_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(190) NOT NULL,
            amount DECIMAL(20,2) NOT NULL DEFAULT 0,
            currency VARCHAR(20) NOT NULL DEFAULT 'toman',
            jalali_date VARCHAR(12) NULL,
            gregorian_date DATE NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id), KEY transaction_id(transaction_id), KEY name(name), KEY gregorian_date(gregorian_date)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['archives']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(190) NULL,
            scope LONGTEXT NULL,
            summary LONGTEXT NULL,
            data LONGTEXT NULL,
            jalali_date VARCHAR(12) NULL,
            gregorian_date DATE NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY(id)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['deleted_items']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            table_key VARCHAR(80) NOT NULL,
            original_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            item_title VARCHAR(190) NULL,
            item_data LONGTEXT NOT NULL,
            deleted_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            deleted_at DATETIME NOT NULL,
            PRIMARY KEY(id), KEY table_key(table_key), KEY original_id(original_id), KEY deleted_at(deleted_at)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->tables['settings']} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            setting_key VARCHAR(190) NOT NULL,
            setting_value LONGTEXT NULL,
            autoload TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY(id), UNIQUE KEY setting_key(setting_key)
        ) $charset;");
    }


    private function ensure_person_columns() {
        global $wpdb;
        $map = ['accounts'=>'name', 'transactions'=>'account_id', 'assets'=>'title'];
        foreach ($map as $key=>$after) {
            $table = $this->tables[$key];
            $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", 'person_key'));
            if (!$exists) {
                $wpdb->query("ALTER TABLE `$table` ADD COLUMN person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza' AFTER user_id");
            }
            $idx = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name=%s", 'person_key'));
            if (!$idx) {
                $wpdb->query("ALTER TABLE `$table` ADD INDEX person_key (person_key)");
            }
        }
        $assets_table = $this->tables['assets'];
        $unit_price_exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$assets_table` LIKE %s", 'unit_price'));
        if (!$unit_price_exists) {
            $wpdb->query("ALTER TABLE `$assets_table` ADD COLUMN unit_price DECIMAL(20,8) NOT NULL DEFAULT 0 AFTER purchase_price");
        }
    }


    private function ensure_hide_amount_column() {
        global $wpdb;
        $table = $this->tables['transactions'];
        $col = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", 'hide_amount' ) );
        if ( ! $col ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `hide_amount` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`" );
        }
        // ایندکس برای فیلتر سریع hide_amount
        $idx = $wpdb->get_var( $wpdb->prepare( "SHOW INDEX FROM `{$table}` WHERE Key_name=%s", 'hide_amount' ) );
        if ( ! $idx ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX `hide_amount` (`hide_amount`)" );
        }
    }

    private function ensure_finance_extensions() {
        global $wpdb;
        $columns = [
            'categories' => [
                'is_essential' => "ALTER TABLE `%s` ADD COLUMN is_essential TINYINT(1) NOT NULL DEFAULT 1 AFTER is_default",
            ],
            'transactions' => [
                'fee_amount' => "ALTER TABLE `%s` ADD COLUMN fee_amount DECIMAL(20,2) NOT NULL DEFAULT 0 AFTER amount",
                'source_loan_id' => "ALTER TABLE `%s` ADD COLUMN source_loan_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER tags",
                'loan_installment_id' => "ALTER TABLE `%s` ADD COLUMN loan_installment_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_loan_id",
                'debt_id' => "ALTER TABLE `%s` ADD COLUMN debt_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER loan_installment_id",
                'receivable_id' => "ALTER TABLE `%s` ADD COLUMN receivable_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER debt_id",
                'check_id' => "ALTER TABLE `%s` ADD COLUMN check_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER receivable_id",
                'asset_id' => "ALTER TABLE `%s` ADD COLUMN asset_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER receivable_id",
                'recurring_id' => "ALTER TABLE `%s` ADD COLUMN recurring_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER asset_id",
                'recurring_due_jalali_date' => "ALTER TABLE `%s` ADD COLUMN recurring_due_jalali_date VARCHAR(12) NULL AFTER recurring_id",
                'recurring_due_gregorian_date' => "ALTER TABLE `%s` ADD COLUMN recurring_due_gregorian_date DATE NULL AFTER recurring_due_jalali_date",
                'from_person_key' => "ALTER TABLE `%s` ADD COLUMN from_person_key VARCHAR(40) NOT NULL DEFAULT 'hamidreza' AFTER person_key",
                'to_person_key' => "ALTER TABLE `%s` ADD COLUMN to_person_key VARCHAR(40) NOT NULL DEFAULT 'samira' AFTER from_person_key",
                'transaction_place' => "ALTER TABLE `%s` ADD COLUMN transaction_place VARCHAR(190) NULL AFTER description",
                'asset_quantity' => "ALTER TABLE `%s` ADD COLUMN asset_quantity DECIMAL(20,8) NOT NULL DEFAULT 0 AFTER asset_id",
            ],
            'assets' => [
                'fee_amount' => "ALTER TABLE `%s` ADD COLUMN fee_amount DECIMAL(20,2) NOT NULL DEFAULT 0 AFTER amount",
                'source_loan_id' => "ALTER TABLE `%s` ADD COLUMN source_loan_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER purchase_place",
                'goal_id' => "ALTER TABLE `%s` ADD COLUMN goal_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER source_loan_id",
                'funding_source' => "ALTER TABLE `%s` ADD COLUMN funding_source VARCHAR(40) NOT NULL DEFAULT 'personal' AFTER goal_id",
                'is_active' => "ALTER TABLE `%s` ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER note",
            ],
            'debts' => [
                'paid_amount' => "ALTER TABLE `%s` ADD COLUMN paid_amount DECIMAL(20,2) NOT NULL DEFAULT 0 AFTER amount",
                'account_id' => "ALTER TABLE `%s` ADD COLUMN account_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER currency",
            ],
            'receivables' => [
                'paid_amount' => "ALTER TABLE `%s` ADD COLUMN paid_amount DECIMAL(20,2) NOT NULL DEFAULT 0 AFTER amount",
            ],
            'loans' => [
                'account_id' => "ALTER TABLE `%s` ADD COLUMN account_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER currency",
                'last_due_jalali_date' => "ALTER TABLE `%s` ADD COLUMN last_due_jalali_date VARCHAR(12) NULL AFTER first_due_gregorian_date",
                'last_due_gregorian_date' => "ALTER TABLE `%s` ADD COLUMN last_due_gregorian_date DATE NULL AFTER last_due_jalali_date",
                'variable_installments' => "ALTER TABLE `%s` ADD COLUMN variable_installments TINYINT(1) NOT NULL DEFAULT 0 AFTER installment_amount",
                'installment_overrides' => "ALTER TABLE `%s` ADD COLUMN installment_overrides TEXT NULL AFTER variable_installments",
            ],
            'checks' => [
                'paid_transaction_id' => "ALTER TABLE `%s` ADD COLUMN paid_transaction_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER include_in_assets",
                'paid_jalali_date' => "ALTER TABLE `%s` ADD COLUMN paid_jalali_date VARCHAR(12) NULL AFTER paid_transaction_id",
                'paid_gregorian_date' => "ALTER TABLE `%s` ADD COLUMN paid_gregorian_date DATE NULL AFTER paid_jalali_date",
            ],
        ];
        foreach ($columns as $key => $defs) {
            if (empty($this->tables[$key])) continue;
            $table = $this->tables[$key];
            foreach ($defs as $col => $sql) {
                $exists = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `$table` LIKE %s", $col));
                if (!$exists) $wpdb->query(sprintf($sql, $table));
            }
        }
        $indexes = [
            ['transactions','source_loan_id','source_loan_id'], ['transactions','loan_installment_id','loan_installment_id'], ['transactions','debt_id','debt_id'], ['transactions','receivable_id','receivable_id'], ['transactions','check_id','check_id'], ['transactions','asset_id','asset_id'], ['transactions','recurring_id','recurring_id'],
            ['assets','source_loan_id','source_loan_id'], ['checks','paid_transaction_id','paid_transaction_id'], ['loans','last_due_gregorian_date','last_due_gregorian_date']
        ];
        foreach ($indexes as $idx) {
            [$key,$name,$col] = $idx;
            if (empty($this->tables[$key])) continue;
            $table = $this->tables[$key];
            $exists = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `$table` WHERE Key_name=%s", $name));
            if (!$exists) $wpdb->query("ALTER TABLE `$table` ADD INDEX `$name` (`$col`)");
        }
    }

    private function seed_defaults() {
        global $wpdb;
        $count = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->tables['categories']} WHERE is_default=1");
        if ($count > 0) return;
        $defaults = [
            ['خوراک و سوپرمارکت','expense','🛒','#FDE68A'], ['رستوران و کافه','expense','🍽️','#FECACA'], ['اجاره خانه','expense','🏠','#DDD6FE'],
            ['قبوض و شارژ','expense','💡','#BAE6FD'], ['اینترنت و موبایل','expense','📱','#BFDBFE'], ['حمل‌ونقل عمومی','expense','🚇','#BBF7D0'],
            ['تاکسی و بنزین','expense','🚕','#FED7AA'], ['درمان و دارو','expense','💊','#FBCFE8'], ['بیمه','expense','🛡️','#C7D2FE'],
            ['آموزش و کتاب','expense','📚','#A7F3D0'], ['پوشاک','expense','👕','#E9D5FF'], ['تفریح و سفر','expense','✈️','#FEF3C7'],
            ['هدیه و مهمانی','expense','🎁','#FCE7F3'], ['تعمیرات و وسایل خانه','expense','🛠️','#D1FAE5'], ['مالیات و عوارض','expense','🧾','#E5E7EB'],
            ['قسط و وام','expense','🏦','#FEE2E2'], ['سرمایه‌گذاری','expense','📈','#DCFCE7'], ['سایر هزینه‌ها','expense','📌','#E0E7FF'],
            ['حقوق و دستمزد','income','💼','#BBF7D0'], ['درآمد آزاد','income','🧑‍💻','#BAE6FD'], ['فروش دارایی','income','💰','#FEF08A'],
            ['هدیه دریافتی','income','🎉','#FBCFE8'], ['سود سرمایه‌گذاری','income','📊','#A7F3D0'], ['سایر درآمدها','income','✨','#DDD6FE'],
        ];
        foreach ($defaults as $d) {
            $wpdb->insert($this->tables['categories'], [
                'user_id'=>0, 'name'=>$d[0], 'type'=>$d[1], 'icon'=>$d[2], 'color'=>$d[3], 'is_default'=>1, 'created_at'=>current_time('mysql')
            ], ['%d','%s','%s','%s','%s','%d','%s']);
        }
    }

    public function assets() {
        global $post;
        if (!is_a($post, 'WP_Post') || strpos((string)$post->post_content, '[hamid_personal_accounting') === false) return;
        wp_enqueue_style('hpa-css', plugin_dir_url(__FILE__) . 'assets/css/hpa.css', [], self::VERSION);
        // تنظیم نوع media برای جلوگیری از render-blocking در صفحات دیگر — اینجا چون مستقیم load می‌کنیم مشکلی نیست
        wp_enqueue_script('hpa-js', plugin_dir_url(__FILE__) . 'assets/js/hpa.js', [], self::VERSION, true);
        // افزودن defer به اسکریپت‌های غیر ضروری در هنگام لود اولیه
        add_filter('script_loader_tag', function($tag, $handle) {
            if ($handle === 'hpa-js') {
                return str_replace(' src=', ' defer src=', $tag);
            }
            return $tag;
        }, 10, 2);
    }

    private function user_can_access() {
        if ( ! is_user_logged_in() ) return false;
        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) return false;
        return ( strtolower( trim( $user->user_email ) ) === strtolower( self::AUTHORIZED_EMAIL ) );
    }

    private function is_authorized_user( $user_obj = null ) {
        if ( $user_obj === null ) $user_obj = wp_get_current_user();
        if ( ! $user_obj || ! $user_obj->exists() ) return false;
        return ( strtolower( trim( $user_obj->user_email ) ) === strtolower( self::AUTHORIZED_EMAIL ) );
    }

    private function guard() {
        if ( ! $this->is_authorized_user() ) {
            wp_die( 'دسترسی غیرمجاز.', '', array( 'response' => 403 ) );
        }
        check_admin_referer( self::NONCE, 'hpa_nonce' );
    }

    private function redirect($tab='dashboard') {
        $url = wp_get_referer() ? wp_get_referer() : home_url('/');
        $url = remove_query_arg(['hpa_msg','hpa_edit_account','hpa_edit_transaction','hpa_edit_asset','hpa_edit_category','hpa_edit_debt','hpa_edit_receivable','hpa_edit_loan','hpa_edit_check','hpa_edit_recurring'], $url);
        wp_safe_redirect(add_query_arg(['hpa_tab'=>$tab,'hpa_msg'=>'saved'], $url));
        exit;
    }

    private function clean($key, $default='') { return isset($_POST[$key]) ? sanitize_text_field(wp_unslash($_POST[$key])) : $default; }
    private function textarea($key) { return isset($_POST[$key]) ? sanitize_textarea_field(wp_unslash($_POST[$key])) : ''; }
    private function money($key) { return isset($_POST[$key]) ? (float) str_replace([',','٬',' '], '', sanitize_text_field(wp_unslash($_POST[$key]))) : 0; }
    private function id($key) { return isset($_REQUEST[$key]) ? absint($_REQUEST[$key]) : 0; }

    private function currencies() { return ['toman'=>'تومان','rial'=>'ریال','usd'=>'دلار','eur'=>'یورو','aed'=>'درهم','try'=>'لیر']; }
    private function account_types() { return ['cash'=>'نقدی','bank'=>'بانکی','credit'=>'اعتباری']; }
    private function bank_list() { return [['bmi','بانک ملی ایران'],['mellat','بانک ملت'],['tejarat','بانک تجارت'],['bsi','بانک صادرات'],['sepah','بانک سپه'],['bki','بانک کشاورزی'],['maskan','بانک مسکن'],['parsian','بانک پارسیان'],['bpi','بانک پاسارگاد'],['en','بانک اقتصاد نوین'],['sb','بانک سامان'],['sina','بانک سینا'],['shahr','بانک شهر'],['day','بانک دی'],['kar','بانک کارآفرین'],['rb','بانک رفاه کارگران'],['post','پست بانک'],['sarmayeh','بانک سرمایه'],['ansar','بانک انصار'],['tourism','بانک گردشگری'],['edbi','بانک توسعه صادرات'],['bim','بانک صنعت و معدن'],['iz','بانک ایران‌زمین'],['hi','بانک حکمت ایرانیان'],['melal','مؤسسه اعتباری ملل'],['resalat','بانک رسالت'],['mehriran','بانک مهر ایران'],['tt','بانک توسعه تعاون'],['ivbb','بانک ایران‌ونزوئلا'],['me','بانک مهر اقتصاد'],['ghbi','بانک قوامین'],['ba','بانک آینده']]; }
    private function account_glyphs() { return ['💳','💵','🏦','🐷','💼','🪙','📇','💰','🏠','🎓']; }
    private function bank_name($code) { foreach($this->bank_list() as $b){ if($b[0]===$code) return $b[1]; } return ''; }
    private function account_icon_html($icon, $cls='') { $icon = (string)($icon===null?'💳':$icon); if (strpos($icon,'bank:')===0){ $code=preg_replace('/[^a-z0-9]/i','',substr($icon,5)); return '<img class="hpa-bank-logo '.esc_attr($cls).'" src="'.esc_url(plugins_url('assets/img/banks/'.$code.'.png', __FILE__)).'" alt="'.esc_attr($this->bank_name($code)).'">'; } return '<span class="hpa-acc-emoji '.esc_attr($cls).'">'.esc_html($icon).'</span>'; }
    private function account_icon_text($icon) { $icon=(string)($icon===null?'💳':$icon); return strpos($icon,'bank:')===0 ? '🏦' : $icon; }
    private function account_type_label($a) { if ($a && isset($a->icon) && strpos((string)$a->icon,'bank:')===0){ $n=$this->bank_name(substr((string)$a->icon,5)); if($n) return $n; } $t=$this->account_types(); return ($a && isset($t[$a->type])) ? $t[$a->type] : 'حساب'; }
    private function account_icon_picker($current) { $current=(string)($current===null?'💳':$current); $out='<div class="hpa-icon-picker" data-current="'.esc_attr($current).'"><input type="hidden" name="icon" value="'.esc_attr($current).'">'; $out.='<div class="hpa-icon-picker-glyphs">'; foreach($this->account_glyphs() as $g) $out.='<button type="button" class="hpa-icon-opt hpa-icon-opt-glyph'.($current===$g?' is-selected':'').'" data-icon="'.esc_attr($g).'">'.esc_html($g).'</button>'; $out.='</div><div class="hpa-icon-picker-banks">'; foreach($this->bank_list() as $b){ $val='bank:'.$b[0]; $nm=$b[1]; $out.='<button type="button" class="hpa-icon-opt hpa-icon-opt-bank'.($current===$val?' is-selected':'').'" data-icon="'.esc_attr($val).'" title="'.esc_attr($nm).'"><img src="'.esc_url(plugins_url('assets/img/banks/'.$b[0].'.png', __FILE__)).'" alt="'.esc_attr($nm).'"><small>'.esc_html(preg_replace('/^مؤسسه اعتباری /u','',preg_replace('/^بانک /u','',$nm))).'</small></button>'; } $out.='</div></div>'; return $out; }
    private function transaction_types() { return ['income'=>'درآمد','expense'=>'هزینه','loan_installment'=>'پرداخت قسط','recurring_debt'=>'بدهی تکرارشونده','transfer'=>'انتقال بین حساب‌ها','person_transfer'=>'انتقال بین اشخاص','debt_incur'=>'دریافت قرض/وام','debt_settlement'=>'تسویه بدهی','receivable_settlement'=>'تسویه طلب','check_settlement'=>'تسویه چک','asset_buy'=>'خرید دارایی','asset_sell'=>'فروش دارایی']; }
    // طبقه‌بندی حسابداری: فقط مصرف واقعی «هزینه» است. بازپرداخت اصل بدهی/وام، خرید/فروش دارایی و
    // گرفتن/وصول قرض «جابه‌جایی پول (تأمین مالی)» هستند و درآمد یا هزینه حساب نمی‌شوند.
    private function expense_types() { return ['expense','recurring_debt']; }
    private function financing_out_types() { return ['loan_installment','debt_settlement','check_settlement','asset_buy']; }
    private function financing_in_types() { return ['debt_incur','asset_sell','receivable_settlement']; }
    private function cash_out_types() { return array_merge($this->expense_types(), $this->financing_out_types()); }
    private function cash_in_types() { return array_merge(['income'], $this->financing_in_types()); }
    private function asset_groups() { return ['gold'=>'طلا','silver'=>'نقره','crypto'=>'کریپتو','cash_currency'=>'ارز نقدی','property'=>'ملک','car'=>'خودرو','valuable'=>'کالای ارزشمند','other'=>'سایر']; }
    private function asset_group_icon($group) { $icons = ['gold'=>'🥇','silver'=>'🥈','crypto'=>'₿','cash_currency'=>'💵','property'=>'🏠','car'=>'🚗','valuable'=>'💍','other'=>'📦']; return $icons[$group] ?? '💼'; }
    private function persons() { return ['hamidreza'=>'خودم','samira'=>'همسر','joint'=>'مشترک']; }
    private function person_label($key) { $p=$this->persons(); return $p[$key] ?? $p['hamidreza']; }
    private function person_select($name='person_key', $selected='hamidreza') { $out='<select name="'.esc_attr($name).'">'; foreach($this->persons() as $k=>$v){ $out.='<option value="'.esc_attr($k).'" '.selected($selected,$k,false).'>'.esc_html($v).'</option>'; } return $out.'</select>'; }
    private function today_jalali() { return $this->gregorian_to_jalali_date(date('Y-m-d')); }
    private function status_labels() { return ['open'=>'باز','done'=>'انجام‌شده','paid'=>'تسویه‌شده','partial'=>'بخشی تسویه‌شده','cancelled'=>'لغوشده']; }

    private function fmt_money($amount, $currency='toman') {
        $curr = $this->currencies();
        $precision = in_array($currency, ['usd','eur','aed','try'], true) ? 2 : 0;
        return number_format_i18n((float)$amount, $precision) . ' ' . ($curr[$currency] ?? esc_html($currency));
    }
    // number display: keep the millions digits full-size, shrink the rest + the currency word.
    // Splits on the actual thousands separator (Persian ٬ / Arabic ، / comma).
    private function fmt_money_html($amount, $currency='toman') {
        $full = $this->fmt_money($amount, $currency);
        $sp = mb_strrpos($full, ' ');
        $numPart = ($sp !== false) ? mb_substr($full, 0, $sp) : $full;
        $cur = ($sp !== false) ? mb_substr($full, $sp + 1) : '';
        $bare = preg_replace('/[-−‎‏]/u', '', $numPart);
        $groups = preg_split('/[,٬،]/u', $bare);
        preg_match('/[,٬،]/u', $bare, $sm); $sep = $sm[0] ?? ',';
        $neg = ((float)$amount < 0) ? '−' : '';
        $n = count($groups);
        if ($n >= 3) { $lead = $neg . implode($sep, array_slice($groups, 0, $n - 2)); $rest = $sep . implode($sep, array_slice($groups, $n - 2)); }
        else { $lead = $neg . $bare; $rest = ''; }
        return '<span class="hy-lead">'.esc_html($lead).'</span>'.($rest ? '<span class="hy-rest">'.esc_html($rest).'</span>' : '').($cur ? '<span class="hy-cur">'.esc_html($cur).'</span>' : '');
    }

    private function amount_to_toman($amount, $currency='toman') {
        $amount = (float)$amount;
        $currency = sanitize_key((string)$currency);
        if ($currency === 'rial') return $amount / 10;
        if ($currency === 'toman' || $currency === '') return $amount;
        $rate_key_map = ['usd'=>'usd','eur'=>'eur','aed'=>'aed','try'=>'try'];
        $rate_key = $rate_key_map[$currency] ?? '';
        if ($rate_key) {
            global $wpdb;
            $rate = (float)$wpdb->get_var($wpdb->prepare("SELECT price FROM {$this->tables['rates']} WHERE rate_key=%s LIMIT 1", $rate_key));
            if ($rate > 0) return $amount * $rate;
        }
        return $amount;
    }

    private function toman_to_currency($amount_toman, $currency='toman') {
        $amount_toman = (float)$amount_toman;
        $currency = sanitize_key((string)$currency);
        if ($currency === 'rial') return $amount_toman * 10;
        if ($currency === 'toman' || $currency === '') return $amount_toman;
        $rate_key_map = ['usd'=>'usd','eur'=>'eur','aed'=>'aed','try'=>'try'];
        $rate_key = $rate_key_map[$currency] ?? '';
        if ($rate_key) {
            global $wpdb;
            $rate = (float)$wpdb->get_var($wpdb->prepare("SELECT price FROM {$this->tables['rates']} WHERE rate_key=%s LIMIT 1", $rate_key));
            if ($rate > 0) return $amount_toman / $rate;
        }
        return $amount_toman;
    }

    private function convert_currency($amount, $from='toman', $to='toman') {
        $from = sanitize_key((string)$from); $to = sanitize_key((string)$to);
        if ($from === $to) return (float)$amount;
        return $this->toman_to_currency($this->amount_to_toman($amount, $from), $to);
    }

    private function rows_sum_toman($rows, $amount_field='amount', $currency_field='currency') {
        $sum = 0;
        foreach ((array)$rows as $row) {
            $sum += $this->amount_to_toman($row->{$amount_field} ?? 0, $row->{$currency_field} ?? 'toman');
        }
        return $sum;
    }

    private function table_sum_toman($table_key, $amount_field='amount', $where='1=1') {
        global $wpdb;
        $table = $this->tables[$table_key];
        $allowed = ['amount','purchase_price'];
        if (!in_array($amount_field, $allowed, true)) $amount_field = 'amount';
        $rows = $wpdb->get_results("SELECT `$amount_field` AS amount, currency FROM `$table` WHERE $where");
        return $this->rows_sum_toman($rows);
    }

    private function transaction_sum_toman($type, $where='1=1') {
        global $wpdb;
        if (is_array($type)) {
            $types = array_values(array_filter(array_map('sanitize_key', $type)));
            if (!$types) return 0;
            $placeholders = implode(',', array_fill(0, count($types), '%s'));
            $sql = "SELECT amount, currency FROM {$this->tables['transactions']} WHERE type IN ($placeholders) AND status!='cancelled' AND $where";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$types));
        } else {
            $type = sanitize_key($type);
            $rows = $wpdb->get_results($wpdb->prepare("SELECT amount, currency FROM {$this->tables['transactions']} WHERE type=%s AND status!='cancelled' AND $where", $type));
        }
        return $this->rows_sum_toman($rows);
    }

    private function total_balances_toman($balances) {
        $total = 0;
        foreach ($this->get_accounts() as $a) {
            $total += $this->amount_to_toman($balances[$a->id] ?? 0, $a->currency);
        }
        return $total;
    }

    private function latest_rate_price($rate_key) {
        $rate_key = sanitize_key((string)$rate_key);
        if ($rate_key === '') return 0;
        global $wpdb;
        return (float)$wpdb->get_var($wpdb->prepare("SELECT price FROM {$this->tables['rates']} WHERE rate_key=%s LIMIT 1", $rate_key));
    }

    private function asset_base_amount($asset) {
        if (in_array($asset->asset_group, ['gold','silver'], true)) return max(0, (float)$asset->weight);
        $quantity = isset($asset->quantity) ? (float)$asset->quantity : 0;
        $weight = isset($asset->weight) ? (float)$asset->weight : 0;
        if ($quantity > 0) return $quantity;
        if ($weight > 0) return $weight;
        return 1;
    }

    private function asset_market_rate_key($asset) {
        $group = sanitize_key((string)($asset->asset_group ?? ''));
        $text = strtolower(trim(($asset->title ?? '').' '.($asset->model ?? '').' '.($asset->purity ?? '').' '.($asset->unit ?? '').' '.($asset->currency ?? '')));
        $text = str_replace(['ي','ك'], ['ی','ک'], $text);
        if ($group === 'gold') {
            return (preg_match('/24|۲۴|999|۹۹۹/u', $text)) ? 'gold24' : 'gold18';
        }
        if ($group === 'silver') return 'silver';
        if ($group === 'crypto') {
            $map = [
                'btc'=>['btc','bitcoin','بیت کوین','بیت‌کوین'],
                'eth'=>['eth','ethereum','اتریوم'],
                'usdt'=>['usdt','tether','تتر'],
                'bnb'=>['bnb','binance','بایننس'],
                'sol'=>['sol','solana','سولانا'],
                'xrp'=>['xrp','ripple','ریپل'],
                'doge'=>['doge','dogecoin','دوج'],
            ];
            foreach ($map as $key=>$needles) foreach ($needles as $needle) if (strpos($text, strtolower($needle)) !== false) return $key;
        }
        if ($group === 'cash_currency') {
            if (preg_match('/usd|dollar|دلار/u', $text)) return 'usd';
            if (preg_match('/eur|euro|یورو/u', $text)) return 'eur';
            if (preg_match('/usdt|تتر/u', $text)) return 'usdt';
        }
        return '';
    }

    private function asset_valuation($asset) {
        $base = $this->asset_base_amount($asset);
        $purchase_total = $this->amount_to_toman($asset->purchase_price ?? 0, $asset->currency ?? 'toman');
        $purchase_unit = ($base > 0) ? ($purchase_total / $base) : 0;
        $rate_key = $this->asset_market_rate_key($asset);
        $market_unit = $rate_key ? $this->latest_rate_price($rate_key) : 0;
        $has_market = ($rate_key !== '' && $market_unit > 0 && $base > 0);
        $current_total = $has_market ? ($base * $market_unit) : $purchase_total;
        $current_unit = $has_market ? $market_unit : $purchase_unit;
        $profit = $current_total - $purchase_total;
        $percent = $purchase_total > 0 ? (($profit / $purchase_total) * 100) : 0;
        return [
            'base'=>$base,
            'rate_key'=>$rate_key,
            'has_market'=>$has_market,
            'purchase_total'=>$purchase_total,
            'purchase_unit'=>$purchase_unit,
            'current_unit'=>$current_unit,
            'current_total'=>$current_total,
            'profit'=>$profit,
            'percent'=>$percent,
        ];
    }

    private function asset_summary_totals($where='1=1') {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT * FROM {$this->tables['assets']} WHERE $where");
        $out = ['purchase'=>0, 'current'=>0, 'profit'=>0];
        foreach ((array)$rows as $asset) {
            $v = $this->asset_valuation($asset);
            $out['purchase'] += $v['purchase_total'];
            $out['current'] += $v['current_total'];
        }
        $out['profit'] = $out['current'] - $out['purchase'];
        return $out;
    }

    private function asset_funding_label($asset) {
        $map = ['personal'=>'پول شخصی','loan'=>'از محل وام','check'=>'از محل چک','debt'=>'از محل بدهی'];
        $src = $asset->funding_source ?? 'personal';
        $label = $map[$src] ?? $src;
        if (!empty($asset->source_loan_id)) $label .= ' / وام مرتبط';
        if (!empty($asset->goal_id)) {
            global $wpdb;
            $goal = $wpdb->get_var($wpdb->prepare("SELECT title FROM {$this->tables['goals']} WHERE id=%d", (int)$asset->goal_id));
            if ($goal) $label .= ' / هدف: '.$goal;
        }
        return $label;
    }

    private function asset_status_html($valuation) {
        $profit = (float)($valuation['profit'] ?? 0);
        $percent = (float)($valuation['percent'] ?? 0);
        $class = $profit >= 0 ? 'hpa-asset-gain' : 'hpa-asset-loss';
        $arrow = $profit >= 0 ? '↗' : '↘';
        $label = $profit >= 0 ? 'سود' : 'زیان';
        return '<span class="hpa-asset-status '.$class.'"><b>'.$arrow.'</b><span>'.$label.' '.esc_html($this->fmt_money(abs($profit), 'toman')).'</span><small>'.esc_html(number_format_i18n(abs($percent), 1)).'%</small></span>';
    }

    private function upload_receipt($field='receipt') {
        $this->last_upload_ids = [];
        if (empty($_FILES[$field]['name'])) return 0;
        if (!current_user_can('upload_files')) return 0;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $allowed = ['jpg|jpeg|jpe'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','pdf'=>'application/pdf'];
        add_filter('upload_mimes', function($m) use ($allowed) { return array_merge($m, $allowed); });
        if (is_array($_FILES[$field]['name'])) {
            $files = $_FILES[$field];
            $count = count($files['name']);
            for ($i=0; $i<$count; $i++) {
                if (empty($files['name'][$i])) continue;
                $_FILES[$field.'_single'] = [
                    'name'=>$files['name'][$i], 'type'=>$files['type'][$i] ?? '', 'tmp_name'=>$files['tmp_name'][$i] ?? '',
                    'error'=>$files['error'][$i] ?? 0, 'size'=>$files['size'][$i] ?? 0,
                ];
                $att = media_handle_upload($field.'_single', 0);
                if (!is_wp_error($att)) $this->last_upload_ids[] = absint($att);
                unset($_FILES[$field.'_single']);
            }
            return $this->last_upload_ids[0] ?? 0;
        }
        $attachment_id = media_handle_upload($field, 0);
        if (!is_wp_error($attachment_id)) $this->last_upload_ids[] = absint($attachment_id);
        return is_wp_error($attachment_id) ? 0 : absint($attachment_id);
    }

    private function link_last_uploads($object_type, $object_id) {
        if (!$object_id || empty($this->last_upload_ids) || empty($this->tables['attachments'])) return;
        global $wpdb;
        foreach (array_unique(array_map('absint', $this->last_upload_ids)) as $att) {
            if (!$att) continue;
            $wpdb->insert($this->tables['attachments'], [
                'user_id'=>get_current_user_id(), 'object_type'=>sanitize_key($object_type), 'object_id'=>absint($object_id),
                'attachment_id'=>$att, 'created_at'=>current_time('mysql')
            ]);
        }
    }

    public function save_account() {
        $this->guard(); global $wpdb;
        $id = $this->id('id');
        $account_type = $this->clean('type','cash');
        if (!array_key_exists($account_type, $this->account_types())) $account_type = 'cash';
        $data = [
            'user_id'=>get_current_user_id(), 'person_key'=>$this->clean('person_key','hamidreza'), 'name'=>$this->clean('name'), 'type'=>$account_type, 'currency'=>$this->clean('currency','toman'),
            'opening_balance'=>$this->money('opening_balance'), 'bank_name'=>$this->clean('bank_name'), 'account_number'=>$this->clean('account_number'),
            'card_number'=>$this->clean('card_number'), 'iban'=>$this->clean('iban'), 'icon'=>$this->clean('icon','💳'), 'color'=>$this->clean('color','#fde68a'),
            'note'=>$this->textarea('note'), 'is_active'=>isset($_POST['is_active']) ? 1 : 0, 'updated_at'=>current_time('mysql')
        ];
        if ($id) $wpdb->update($this->tables['accounts'], $data, ['id'=>$id], null, ['%d']);
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['accounts'], $data); }
        $this->redirect('accounts');
    }

    private function archive_item_before_delete($table_key, $id, $title_field='id') {
        global $wpdb;
        $id = absint($id);
        if (!$id || empty($this->tables[$table_key]) || empty($this->tables['deleted_items'])) return;
        $table = $this->tables[$table_key];
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table` WHERE id=%d", $id), ARRAY_A);
        if (!$row) return;
        $title = isset($row[$title_field]) ? (string)$row[$title_field] : ($table_key.' #'.$id);
        $wpdb->insert($this->tables['deleted_items'], [
            'table_key'=>$table_key,
            'original_id'=>$id,
            'item_title'=>wp_strip_all_tags($title),
            'item_data'=>wp_json_encode($row, JSON_UNESCAPED_UNICODE),
            'deleted_by'=>get_current_user_id(),
            'deleted_at'=>current_time('mysql')
        ]);
    }

    public function reopen_account() { $this->guard(); global $wpdb; $wpdb->update($this->tables['accounts'], ['is_active'=>1, 'updated_at'=>current_time('mysql')], ['id'=>$this->id('id')]); $this->redirect('accounts'); }

    public function restore_deleted_item() {
        if ( ! $this->is_authorized_user() ) {
            wp_die( 'دسترسی غیرمجاز.', '', array( 'response' => 403 ) );
        }
        check_admin_referer(self::NONCE,'hpa_nonce');
        global $wpdb; $id=$this->id('id');
        $item=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['deleted_items']} WHERE id=%d", $id));
        if ($item && !empty($this->tables[$item->table_key])) {
            $data=json_decode((string)$item->item_data, true);
            if (is_array($data)) {
                $table=$this->tables[$item->table_key];
                if (!empty($data['id'])) {
                    $exists=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM `$table` WHERE id=%d", (int)$data['id']));
                    if ($exists) unset($data['id']);
                }
                $wpdb->insert($table, $data);
                $wpdb->delete($this->tables['deleted_items'], ['id'=>$id]);
            }
        }
        wp_safe_redirect(add_query_arg(['page'=>'hpa-settings','hpa_admin_tab'=>'deleted','restored'=>'1'], admin_url('options-general.php'))); exit;
    }

    public function permanent_delete_item() {
        if ( ! $this->is_authorized_user() ) {
            wp_die( 'دسترسی غیرمجاز.', '', array( 'response' => 403 ) );
        }
        check_admin_referer(self::NONCE,'hpa_nonce');
        global $wpdb; $wpdb->delete($this->tables['deleted_items'], ['id'=>$this->id('id')]);
        wp_safe_redirect(add_query_arg(['page'=>'hpa-settings','hpa_admin_tab'=>'deleted','deleted'=>'1'], admin_url('options-general.php'))); exit;
    }

    public function delete_account() { $this->guard(); global $wpdb; $wpdb->update($this->tables['accounts'], ['is_active'=>0], ['id'=>$this->id('id')]); $this->redirect('accounts'); }

    public function save_category() {
        $this->guard(); global $wpdb;
        $id = $this->id('id');
        $data = [
            'user_id'=>get_current_user_id(),
            'name'=>$this->clean('name'),
            'type'=>$this->clean('type','expense'),
            'icon'=>$this->clean('icon','📌'),
            'color'=>$this->clean('color','#E0E7FF'),
            'is_essential'=>isset($_POST['is_essential']) ? 1 : 0
        ];
        if ($id) {
            $wpdb->update($this->tables['categories'], $data, ['id'=>$id]);
        } else {
            $data['is_default']=0;
            $data['created_at']=current_time('mysql');
            $wpdb->insert($this->tables['categories'], $data);
        }
        $this->redirect('categories');
    }
    public function delete_category() { $this->guard(); global $wpdb; $id=$this->id('id'); $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['categories']} WHERE id=%d AND is_default=0", $id)); if($row){ $this->archive_item_before_delete('categories',$id,'name'); $wpdb->delete($this->tables['categories'], ['id'=>$id, 'is_default'=>0]); } $this->redirect('categories'); }

    public function save_transaction() {
        $this->guard(); global $wpdb;
        $jalali = $this->clean('jalali_date'); $greg = $this->jalali_to_gregorian_date($jalali);
        $receipt = $this->upload_receipt('receipt');
        $id = $this->id('id');
        $old = $id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE id=%d", $id)) : null;
        $old_check_id = $old ? (int)($old->check_id ?? 0) : 0;
        $old_recurring_id = $old ? (int)($old->recurring_id ?? 0) : 0;
        if ($old) {
            if ((int)($old->loan_installment_id ?? 0)) {
                $inst = $this->get_installment((int)$old->loan_installment_id);
                $wpdb->update($this->tables['loan_installments'], ['status'=>'open','paid_transaction_id'=>0,'updated_at'=>current_time('mysql')], ['id'=>(int)$old->loan_installment_id]);
                if ($inst) $this->refresh_loan_paid_count((int)$inst->loan_id);
            }
            if ($old->type === 'debt_settlement' && (int)($old->debt_id ?? 0)) $this->update_debt_like_payment('debts', (int)$old->debt_id, -1*(float)$old->amount, $old->currency);
            if ($old->type === 'receivable_settlement' && (int)($old->receivable_id ?? 0)) $this->update_debt_like_payment('receivables', (int)$old->receivable_id, -1*(float)$old->amount, $old->currency);
        }
        $type = $this->clean('type','expense');
        $from_person = $this->clean('from_person_key', $this->clean('person_key','hamidreza'));
        $to_person = $this->clean('to_person_key', 'samira');
        $is_loan_related = isset($_POST['hpa_is_loan_related']) || $type === 'loan_installment';
        $recurring_id = 0;
        $recurring_due_jalali = '';
        $recurring_due_gregorian = null;
        if ($type === 'recurring_debt') {
            [$recurring_id, $recurring_due_jalali, $recurring_due_gregorian] = $this->resolve_recurring_payment_selection(
                $this->id('recurring_id'),
                $this->clean('recurring_due_jalali_date'),
                $this->id('recurring_due_recurring_id')
            );
            if ($recurring_due_jalali) {
                // تاریخ خود تراکنش می‌تواند روز پرداخت واقعی باشد؛ تاریخ سررسید جداگانه ذخیره می‌شود.
                // برای سازگاری رفتاری نسخه‌های قبلی، اگر تاریخ تراکنش خالی باشد از تاریخ سررسید استفاده می‌کنیم.
                if (!$jalali) {
                    $jalali = $recurring_due_jalali;
                    $greg = $recurring_due_gregorian ?: $this->jalali_to_gregorian_date($jalali);
                }
            }
        }
        $data = [
            'user_id'=>get_current_user_id(),
            'person_key'=>($type === 'person_transfer' ? $from_person : $this->clean('person_key','hamidreza')),
            'from_person_key'=>$from_person,
            'to_person_key'=>$to_person,
            'account_id'=>$this->id('account_id'),
            'to_account_id'=>($type === 'person_transfer' ? 0 : $this->id('to_account_id')),
            'category_id'=>($type === 'person_transfer' || $type === 'transfer' ? 0 : $this->id('category_id')),
            'type'=>$type, 'amount'=>$this->money('amount'), 'fee_amount'=>in_array($type, ['transfer','person_transfer'], true) ? $this->money('fee_amount') : 0, 'currency'=>$this->clean('currency','toman'), 'jalali_date'=>$jalali,
            'gregorian_date'=>$greg, 'description'=>$this->textarea('description'), 'transaction_place'=>$this->clean('transaction_place'), 'tags'=>$this->clean('tags'),
            'source_loan_id'=>$is_loan_related ? $this->id('source_loan_id') : 0,
            'loan_installment_id'=>$is_loan_related ? $this->id('loan_installment_id') : 0,
            'debt_id'=>($type === 'debt_settlement') ? $this->id('debt_id') : 0,
            'receivable_id'=>($type === 'receivable_settlement') ? $this->id('receivable_id') : 0,
            'check_id'=>($type === 'check_settlement') ? $this->id('check_id') : 0,
            'asset_id'=>in_array($type, ['asset_buy','asset_sell'], true) ? $this->id('asset_id') : 0,
            'asset_quantity'=>($type === 'asset_sell') ? $this->money('asset_quantity') : 0,
            'recurring_id'=>($type === 'recurring_debt') ? $recurring_id : 0,
            'recurring_due_jalali_date'=>($type === 'recurring_debt') ? $recurring_due_jalali : null,
            'recurring_due_gregorian_date'=>($type === 'recurring_debt') ? $recurring_due_gregorian : null,
            'status'=>$this->clean('status','done'), 'hide_amount'=>( isset($_POST['hide_amount']) ? 1 : 0 ), 'updated_at'=>current_time('mysql')
        ];
        if ($receipt) $data['receipt_id'] = $receipt;
        if (!$id) {
            $dup = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['transactions']} WHERE status!='cancelled' AND account_id=%d AND type=%s AND amount=%f AND currency=%s AND jalali_date=%s AND COALESCE(description,'')=%s LIMIT 1", (int)$data['account_id'], $data['type'], (float)$data['amount'], $data['currency'], $data['jalali_date'], (string)$data['description']));
            if ($dup && empty($_POST['hpa_allow_duplicate'])) {
                set_transient('hpa_duplicate_warning_'.get_current_user_id(), $dup, 60);
                $this->redirect('transactions');
            }
        }
        if ($id) {
            $wpdb->update($this->tables['transactions'], $data, ['id'=>$id]);
            $transaction_id = $id;
        } else {
            $data['created_at']=current_time('mysql');
            $wpdb->insert($this->tables['transactions'], $data);
            $transaction_id = (int)$wpdb->insert_id;
        }
        $this->link_last_uploads('transaction', $transaction_id);
        if ($transaction_id && !empty($this->tables['transaction_splits'])) {
            $wpdb->delete($this->tables['transaction_splits'], ['transaction_id'=>$transaction_id]);
            if (!in_array($type, ['transfer','person_transfer'], true) && isset($_POST['hpa_split_categories'])) {
                $split_rows = [
                    [$data['category_id'], $data['amount']],
                    [$this->id('split_category_id_2'), $this->money('split_amount_2')],
                    [$this->id('split_category_id_3'), $this->money('split_amount_3')],
                ];
                foreach ($split_rows as $sr) {
                    if ((int)$sr[0] > 0 && (float)$sr[1] > 0) {
                        $wpdb->insert($this->tables['transaction_splits'], ['transaction_id'=>$transaction_id, 'category_id'=>(int)$sr[0], 'amount'=>(float)$sr[1], 'currency'=>$data['currency'], 'created_at'=>current_time('mysql')]);
                    }
                }
            }
        }
        // اقلام خرید (نام + قیمت) مستقل از مبلغ کل تراکنش
        if ($transaction_id && !empty($this->tables['transaction_items'])) {
            $wpdb->delete($this->tables['transaction_items'], ['transaction_id'=>$transaction_id]);
            $raw = isset($_POST['hpa_items']) ? wp_unslash($_POST['hpa_items']) : '';
            $items = json_decode((string)$raw, true);
            if (is_array($items)) {
                foreach ($items as $it) {
                    if (!is_array($it)) continue;
                    $name = isset($it['name']) ? sanitize_text_field($it['name']) : '';
                    $amt = isset($it['amount']) ? (float)str_replace([',','٬',' '], '', (string)$it['amount']) : 0;
                    if ($name !== '' && $amt > 0) $wpdb->insert($this->tables['transaction_items'], ['transaction_id'=>$transaction_id, 'name'=>$name, 'amount'=>$amt, 'currency'=>$data['currency'], 'jalali_date'=>$data['jalali_date'], 'gregorian_date'=>$data['gregorian_date'], 'created_at'=>current_time('mysql')]);
                }
            }
        }
        $installment_id = (int)($data['loan_installment_id'] ?? 0);
        if ($transaction_id && $installment_id && $data['status'] !== 'cancelled') {
            $inst = $this->get_installment($installment_id);
            if ($inst) {
                $wpdb->update($this->tables['loan_installments'], [
                    'status' => 'paid',
                    'paid_transaction_id' => $transaction_id,
                    'updated_at' => current_time('mysql')
                ], ['id' => $installment_id]);
                $this->refresh_loan_paid_count((int)$inst->loan_id);
            }
        }
        if ($transaction_id && $data['status'] !== 'cancelled') {
            if ($type === 'debt_settlement' && (int)$data['debt_id']) $this->update_debt_like_payment('debts', (int)$data['debt_id'], (float)$data['amount'], $data['currency']);
            if ($type === 'receivable_settlement' && (int)$data['receivable_id']) $this->update_debt_like_payment('receivables', (int)$data['receivable_id'], (float)$data['amount'], $data['currency']);
        }
        $new_check_id = (int)($data['check_id'] ?? 0);
        if ($old_check_id && $old_check_id !== $new_check_id) $this->sync_check_settlement_status($old_check_id);
        if ($new_check_id) $this->sync_check_settlement_status($new_check_id);
        $new_recurring_id = (int)($data['recurring_id'] ?? 0);
        if ($old_recurring_id && $old_recurring_id !== $new_recurring_id) $this->sync_recurring_payment_status($old_recurring_id);
        if ($new_recurring_id) $this->sync_recurring_payment_status($new_recurring_id);
        $this->redirect('transactions');
    }
    public function delete_transaction() {
        $this->guard(); global $wpdb;
        $id=$this->id('id');
        $tr=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE id=%d", $id));
        if ($tr) {
            if ((int)$tr->loan_installment_id) {
                $inst=$this->get_installment((int)$tr->loan_installment_id);
                $wpdb->update($this->tables['loan_installments'], ['status'=>'open','paid_transaction_id'=>0,'updated_at'=>current_time('mysql')], ['id'=>(int)$tr->loan_installment_id]);
                if ($inst) $this->refresh_loan_paid_count((int)$inst->loan_id);
            }
            if ($tr->type === 'debt_settlement' && (int)($tr->debt_id ?? 0)) $this->update_debt_like_payment('debts', (int)$tr->debt_id, -1*(float)$tr->amount, $tr->currency);
            if ($tr->type === 'receivable_settlement' && (int)($tr->receivable_id ?? 0)) $this->update_debt_like_payment('receivables', (int)$tr->receivable_id, -1*(float)$tr->amount, $tr->currency);
        }
        $old_check_id = $tr ? (int)($tr->check_id ?? 0) : 0;
        $old_recurring_id = $tr ? (int)($tr->recurring_id ?? 0) : 0;
        $this->archive_item_before_delete('transactions',$id,'description');
        $wpdb->delete($this->tables['transaction_items'], ['transaction_id'=>$id]);
        $wpdb->delete($this->tables['transactions'], ['id'=>$id]);
        if ($old_check_id) $this->sync_check_settlement_status($old_check_id);
        if ($old_recurring_id) $this->sync_recurring_payment_status($old_recurring_id);
        $this->redirect('transactions');
    }

    public function save_debt() { $this->save_debt_like('debts','debt'); }
    public function save_receivable() { $this->save_debt_like('receivables','receivable'); }
    private function save_debt_like($table_key, $tab) {
        $this->guard(); global $wpdb;
        $jalali = $this->clean('jalali_date'); $due = $this->clean('due_jalali_date'); $receipt = $this->upload_receipt('receipt');
        $data = [
            'user_id'=>get_current_user_id(), 'person_name'=>$this->clean('person_name'), 'phone'=>$this->clean('phone'), 'amount'=>$this->money('amount'), 'paid_amount'=>$this->money('paid_amount'),
            'currency'=>$this->clean('currency','toman'), 'jalali_date'=>$jalali, 'gregorian_date'=>$this->jalali_to_gregorian_date($jalali),
            'due_jalali_date'=>$due, 'due_gregorian_date'=>$due ? $this->jalali_to_gregorian_date($due) : null, 'status'=>$this->clean('status','open'),
            'note'=>$this->textarea('note'), 'updated_at'=>current_time('mysql')
        ];
        if ($receipt) $data['receipt_id']=$receipt;
        if ($table_key === 'debts') $data['account_id'] = $this->id('account_id');
        $id=$this->id('id');
        if ($id) { $wpdb->update($this->tables[$table_key], $data, ['id'=>$id]); $object_id=$id; }
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables[$table_key], $data); $object_id=(int)$wpdb->insert_id; }
        $this->link_last_uploads($table_key, $object_id);
        // قرض گرفتن، موجودی حساب را زیاد می‌کند اما درآمد نیست.
        if ($table_key === 'debts') $this->sync_incur_transaction('debt_id', $object_id, ['account_id'=>$this->id('account_id'), 'amount'=>$data['amount'], 'currency'=>$data['currency'], 'jalali_date'=>$jalali, 'description'=>'دریافت قرض از '.($data['person_name'] ?: '—')]);
        $this->redirect($tab);
    }
    public function delete_debt() { $this->guard(); global $wpdb; $id=$this->id('id'); $this->archive_item_before_delete('debts',$id,'person_name'); $this->delete_incur_transaction('debt_id',$id); $wpdb->delete($this->tables['debts'], ['id'=>$id]); $this->redirect('debt'); }
    public function delete_receivable() { $this->guard(); global $wpdb; $id=$this->id('id'); $this->archive_item_before_delete('receivables',$id,'person_name'); $wpdb->delete($this->tables['receivables'], ['id'=>$id]); $this->redirect('receivable'); }

    public function save_asset() {
        $this->guard(); global $wpdb;
        $jalali = $this->clean('jalali_date'); $receipt = $this->upload_receipt('receipt'); $id=$this->id('id');
        $asset_group = $this->clean('asset_group','gold');
        $crypto_items = $this->crypto_rate_items();
        $is_crypto = ($asset_group === 'crypto');
        $model = $is_crypto ? sanitize_key($this->clean('model_crypto','')) : $this->clean('model');
        if ($is_crypto && !isset($crypto_items[$model])) {
            $crypto_keys = array_keys($crypto_items);
            $model = $crypto_keys ? $crypto_keys[0] : 'btc';
        }
        $weight = $is_crypto ? 0 : $this->money('weight');
        $quantity = ($asset_group === 'gold') ? 0 : $this->money('quantity');
        $purchase_price = $this->money('purchase_price');
        $unit_base = in_array($asset_group, ['gold','silver'], true) ? $weight : ($quantity > 0 ? $quantity : $weight);
        $unit_price = $unit_base > 0 ? round($purchase_price / $unit_base, 8) : 0;
        $data = [
            'user_id'=>get_current_user_id(), 'person_key'=>$this->clean('person_key','hamidreza'), 'title'=>$this->clean('title'), 'asset_group'=>$asset_group, 'model'=>$model,
            'purity'=>$is_crypto ? '' : $this->clean('purity'), 'weight'=>$weight, 'quantity'=>$quantity, 'unit'=>$is_crypto ? strtoupper($model) : $this->clean('unit'),
            'purchase_price'=>$purchase_price, 'unit_price'=>$unit_price, 'currency'=>$this->clean('currency','toman'), 'jalali_date'=>$jalali, 'gregorian_date'=>$this->jalali_to_gregorian_date($jalali),
            'purchase_place'=>$this->clean('purchase_place'), 'source_loan_id'=>$this->id('source_loan_id'), 'goal_id'=>$this->id('goal_id'), 'funding_source'=>$this->clean('funding_source','personal'), 'note'=>$this->textarea('note'), 'is_active'=>isset($_POST['is_active']) ? 1 : 0, 'updated_at'=>current_time('mysql')
        ];
        if ($receipt) $data['receipt_id']=$receipt;
        if ($id) { $wpdb->update($this->tables['assets'], $data, ['id'=>$id]); $asset_id=$id; }
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['assets'], $data); $asset_id=(int)$wpdb->insert_id; }
        $this->link_last_uploads('asset', $asset_id);
        $this->redirect('assets');
    }
    public function delete_asset() { $this->guard(); global $wpdb; $id=$this->id('id'); $this->archive_item_before_delete('assets',$id,'title'); $wpdb->delete($this->tables['assets'], ['id'=>$id]); $this->redirect('assets'); }


    private function loan_select($name='source_loan_id', $selected=0) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,title,principal_amount,currency,status FROM {$this->tables['loans']} WHERE status!='cancelled' ORDER BY id DESC");
        $out = '<select name="'.esc_attr($name).'"><option value="0">ندارد</option>';
        foreach ((array)$rows as $r) {
            $label = $r->title . ' — ' . $this->fmt_money($r->principal_amount, $r->currency);
            $out .= '<option value="'.esc_attr($r->id).'" '.selected((int)$selected,(int)$r->id,false).'>'.esc_html($label).'</option>';
        }
        return $out . '</select>';
    }

    private function installment_select($name='loan_installment_id', $loan_id=0, $selected=0) {
        global $wpdb;
        $where = $loan_id ? $wpdb->prepare('i.loan_id=%d', $loan_id) : '1=1';
        $rows = $wpdb->get_results("SELECT i.*, l.title AS loan_title FROM {$this->tables['loan_installments']} i LEFT JOIN {$this->tables['loans']} l ON l.id=i.loan_id WHERE $where AND i.status!='paid' ORDER BY i.due_gregorian_date ASC, i.installment_no ASC LIMIT 100");
        $out = '<select name="'.esc_attr($name).'"><option value="0">ندارد</option>';
        foreach ((array)$rows as $r) {
            $label = trim(($r->due_jalali_date ?: 'بدون تاریخ') . ' — ' . ($r->loan_title ?: 'وام') . ' — ' . $this->fmt_money($r->amount, $r->currency));
            $out .= '<option value="'.esc_attr($r->id).'" '.selected((int)$selected,(int)$r->id,false).'>'.esc_html($label).'</option>';
        }
        return $out . '</select>';
    }



    private function debt_select($name='debt_id', $selected=0) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,person_name,amount,paid_amount,currency,due_jalali_date,status FROM {$this->tables['debts']} WHERE status!='paid' ORDER BY COALESCE(due_gregorian_date, gregorian_date) ASC, id DESC LIMIT 150");
        $out = '<select name="'.esc_attr($name).'"><option value="0">انتخاب بدهی</option>';
        foreach ((array)$rows as $r) {
            $remain = max(0, (float)$r->amount - (float)($r->paid_amount ?? 0));
            $label = trim(($r->due_jalali_date ?: 'بدون موعد') . ' — ' . ($r->person_name ?: 'بدهی') . ' — مانده: ' . $this->fmt_money($remain, $r->currency));
            $out .= '<option data-amount="'.esc_attr($remain).'" data-currency="'.esc_attr($r->currency).'" value="'.esc_attr($r->id).'" '.selected((int)$selected,(int)$r->id,false).'>'.esc_html($label).'</option>';
        }
        return $out . '</select>';
    }

    private function receivable_select($name='receivable_id', $selected=0) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,person_name,amount,paid_amount,currency,due_jalali_date,status FROM {$this->tables['receivables']} WHERE status!='paid' ORDER BY COALESCE(due_gregorian_date, gregorian_date) ASC, id DESC LIMIT 150");
        $out = '<select name="'.esc_attr($name).'"><option value="0">انتخاب طلب</option>';
        foreach ((array)$rows as $r) {
            $remain = max(0, (float)$r->amount - (float)($r->paid_amount ?? 0));
            $label = trim(($r->due_jalali_date ?: 'بدون موعد') . ' — ' . ($r->person_name ?: 'طلب') . ' — مانده: ' . $this->fmt_money($remain, $r->currency));
            $out .= '<option data-amount="'.esc_attr($remain).'" data-currency="'.esc_attr($r->currency).'" value="'.esc_attr($r->id).'" '.selected((int)$selected,(int)$r->id,false).'>'.esc_html($label).'</option>';
        }
        return $out . '</select>';
    }

    private function check_select($name='check_id', $selected=0) {
        global $wpdb;
        $selected = absint($selected);
        $where = $selected ? $wpdb->prepare("(status!='paid' OR id=%d)", $selected) : "status!='paid'";
        $rows = $wpdb->get_results("SELECT id,title,check_count,amount_each,currency,first_due_jalali_date,used_for,status FROM {$this->tables['checks']} WHERE $where ORDER BY COALESCE(first_due_gregorian_date, created_at) ASC, id DESC LIMIT 150");
        $out = '<select name="'.esc_attr($name).'" class="hpa-check-select"><option value="0">انتخاب چک</option>';
        foreach ((array)$rows as $r) {
            $amount = (float)$r->amount_each * max(1, (int)$r->check_count);
            $label = trim(($r->first_due_jalali_date ?: 'بدون موعد') . ' — ' . ($r->title ?: 'چک') . ' — ' . $this->fmt_money($amount, $r->currency));
            if ((int)$r->check_count > 1) $label .= ' — تعداد: ' . (int)$r->check_count;
            $out .= '<option data-amount="'.esc_attr($amount).'" data-currency="'.esc_attr($r->currency).'" value="'.esc_attr($r->id).'" '.selected($selected,(int)$r->id,false).'>'.esc_html($label).'</option>';
        }
        return $out . '</select>';
    }

    private function asset_select($name='asset_id', $selected=0) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,title,asset_group,purchase_price,currency FROM {$this->tables['assets']} ORDER BY gregorian_date DESC, id DESC LIMIT 150");
        $groups = $this->asset_groups();
        $out = '<select name="'.esc_attr($name).'"><option value="0">انتخاب دارایی</option>';
        foreach ((array)$rows as $r) {
            $label = trim(($r->title ?: 'دارایی') . ' — ' . ($groups[$r->asset_group] ?? $r->asset_group) . ' — ' . $this->fmt_money($r->purchase_price, $r->currency));
            $out .= '<option value="'.esc_attr($r->id).'" '.selected((int)$selected,(int)$r->id,false).'>'.esc_html($label).'</option>';
        }
        return $out . '</select>';
    }

    private function recurring_select($name='recurring_id', $selected=0) {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,title,amount,currency,next_jalali_date,interval_type,status FROM {$this->tables['recurring']} WHERE status='active' ORDER BY COALESCE(next_gregorian_date, start_gregorian_date) ASC, id DESC LIMIT 150");
        $out = '<select name="'.esc_attr($name).'" class="hpa-recurring-select"><option value="0">انتخاب بدهی تکرارشونده</option>';
        foreach ((array)$rows as $r) {
            $label = trim(($r->title ?: 'تکرارشونده') . ' — موعد: ' . ($r->next_jalali_date ?: 'بدون تاریخ') . ' — ' . $this->fmt_money($r->amount, $r->currency));
            $out .= '<option data-amount="'.esc_attr($r->amount).'" data-currency="'.esc_attr($r->currency).'" data-due="'.esc_attr($r->next_jalali_date).'" value="'.esc_attr($r->id).'" '.selected((int)$selected,(int)$r->id,false).'>'.esc_html($label).'</option>';
        }
        return $out . '</select>';
    }



    private function recurring_due_select($name='recurring_due_jalali_date', $selected_recurring=0, $selected_date='') {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id,title,next_jalali_date,next_gregorian_date,start_jalali_date,start_gregorian_date,interval_type FROM {$this->tables['recurring']} WHERE status='active' ORDER BY COALESCE(next_gregorian_date, start_gregorian_date) ASC, id DESC LIMIT 150");
        $out = '<select name="'.esc_attr($name).'" class="hpa-recurring-due-select"><option value="">انتخاب تاریخ سررسید</option>';
        foreach ((array)$rows as $r) {
            $base_g = $r->next_gregorian_date ?: ($r->start_gregorian_date ?: (($r->next_jalali_date ?: $r->start_jalali_date) ? $this->jalali_to_gregorian_date($r->next_jalali_date ?: $r->start_jalali_date) : ''));
            if (!$base_g) continue;
            for ($i=0; $i<12; $i++) {
                $g = $this->advance_recurring_gregorian_date($base_g, $r->interval_type, $i);
                $j = $this->gregorian_to_jalali_date($g);
                $label = ($r->title ?: 'تکرارشونده') . ' — ' . $j;
                $is_selected = ((string)$selected_date === (string)$j) && (!$selected_recurring || (int)$selected_recurring === (int)$r->id);
                $out .= '<option data-recurring="'.esc_attr($r->id).'" data-gregorian="'.esc_attr($g).'" value="'.esc_attr($j).'" '.($is_selected ? 'selected' : '').'>'.esc_html($label).'</option>';
            }
        }
        return $out . '</select>';
    }

    private function advance_recurring_gregorian_date($base_g, $interval_type='monthly', $steps=1) {
        $base_g = sanitize_text_field((string)$base_g);
        $steps = max(0, (int)$steps);
        if (!$base_g || $steps === 0) return $base_g;
        $interval_type = sanitize_key((string)$interval_type);
        if ($interval_type === 'daily') $modifier = '+'.$steps.' day';
        elseif ($interval_type === 'weekly') $modifier = '+'.($steps * 7).' day';
        elseif ($interval_type === 'yearly') $modifier = '+'.$steps.' year';
        else $modifier = '+'.$steps.' month';
        return date('Y-m-d', strtotime($modifier, strtotime($base_g)));
    }

    private function resolve_recurring_payment_selection($recurring_id, $due_jalali='', $due_recurring_id=0) {
        global $wpdb;
        $recurring_id = absint($recurring_id);
        $due_recurring_id = absint($due_recurring_id);
        $due_jalali = preg_replace('/[^0-9۰-۹٠-٩\/\-]/u', '', (string)$due_jalali);
        $due_jalali = str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','-'], ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9','/'], $due_jalali);
        $rec = null;
        foreach (array_unique(array_filter([$recurring_id, $due_recurring_id])) as $candidate_id) {
            $rec = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['recurring']} WHERE id=%d LIMIT 1", $candidate_id));
            if ($rec) break;
        }

        // پشتیبان سمت سرور: اگر شناسه در فرم موبایل منتقل نشد، بدهی را از همان تاریخ سررسید پیدا می‌کنیم.
        if (!$rec && $due_jalali) {
            $exact = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->tables['recurring']} WHERE status='active' AND (next_jalali_date=%s OR start_jalali_date=%s) ORDER BY id DESC LIMIT 1",
                $due_jalali, $due_jalali
            ));
            if ($exact) $rec = $exact;
        }
        if (!$rec && $due_jalali) {
            $target_g = $this->jalali_to_gregorian_date($due_jalali);
            $rows = $wpdb->get_results("SELECT * FROM {$this->tables['recurring']} WHERE status='active' ORDER BY id DESC LIMIT 150");
            foreach ((array)$rows as $candidate) {
                $base_g = $candidate->next_gregorian_date ?: ($candidate->start_gregorian_date ?: '');
                if (!$base_g) continue;
                for ($i=0; $i<24; $i++) {
                    if ($this->advance_recurring_gregorian_date($base_g, $candidate->interval_type, $i) === $target_g) {
                        $rec = $candidate;
                        break 2;
                    }
                }
            }
        }

        if (!$rec) return [0, $due_jalali, $due_jalali ? $this->jalali_to_gregorian_date($due_jalali) : null];
        if (!$due_jalali) $due_jalali = $rec->next_jalali_date ?: $rec->start_jalali_date;
        $due_gregorian = $due_jalali ? $this->jalali_to_gregorian_date($due_jalali) : ($rec->next_gregorian_date ?: $rec->start_gregorian_date);
        return [(int)$rec->id, $due_jalali, $due_gregorian ?: null];
    }

    private function sync_recurring_payment_status($recurring_id) {
        global $wpdb;
        $recurring_id = absint($recurring_id);
        if (!$recurring_id) return;
        $rec = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['recurring']} WHERE id=%d", $recurring_id));
        if (!$rec) return;

        // از موعد فعلی شروع می‌کنیم تا بدهی‌های قدیمی که پیش از این نسخه جلو رفته‌اند دوباره به عقب برنگردند.
        $base_g = $rec->next_gregorian_date ?: (($rec->next_jalali_date ?? '') ? $this->jalali_to_gregorian_date($rec->next_jalali_date) : '');
        if (!$base_g) $base_g = $rec->start_gregorian_date ?: (($rec->start_jalali_date ?? '') ? $this->jalali_to_gregorian_date($rec->start_jalali_date) : '');
        if (!$base_g) return;

        $paid_dates = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT recurring_due_gregorian_date FROM {$this->tables['transactions']} WHERE recurring_id=%d AND type='recurring_debt' AND status='done' AND recurring_due_gregorian_date IS NOT NULL",
            $recurring_id
        ));
        $paid_map = [];
        foreach ((array)$paid_dates as $paid_date) if ($paid_date) $paid_map[(string)$paid_date] = true;

        $next_g = $base_g;
        for ($i=0; $i<600; $i++) {
            if (empty($paid_map[$next_g])) break;
            $next_g = $this->advance_recurring_gregorian_date($next_g, $rec->interval_type, 1);
        }
        $next_j = $this->gregorian_to_jalali_date($next_g);
        $wpdb->update($this->tables['recurring'], [
            'next_jalali_date'=>$next_j,
            'next_gregorian_date'=>$next_g,
            'updated_at'=>current_time('mysql')
        ], ['id'=>$recurring_id]);
    }

    private function update_debt_like_payment($table_key, $id, $amount, $currency) {
        global $wpdb;
        $id = absint($id);
        if (!$id || empty($this->tables[$table_key])) return;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables[$table_key]} WHERE id=%d", $id));
        if (!$row) return;
        $paid_existing_toman = $this->amount_to_toman((float)($row->paid_amount ?? 0), $row->currency);
        $total_toman = $this->amount_to_toman((float)$row->amount, $row->currency);
        $new_paid_toman = max(0, $paid_existing_toman + $this->amount_to_toman($amount, $currency));
        $new_paid_native = $this->toman_to_currency(min($new_paid_toman, $total_toman), $row->currency);
        $status = $new_paid_toman + 0.0001 >= $total_toman ? 'paid' : ($new_paid_toman > 0 ? 'partial' : 'open');
        $wpdb->update($this->tables[$table_key], ['paid_amount'=>$new_paid_native, 'status'=>$status, 'updated_at'=>current_time('mysql')], ['id'=>$id]);
    }

    private function sync_check_settlement_status($check_id) {
        global $wpdb;
        $check_id = absint($check_id);
        if (!$check_id) return;
        $tx = $wpdb->get_row($wpdb->prepare("SELECT id,jalali_date,gregorian_date FROM {$this->tables['transactions']} WHERE type=%s AND status!='cancelled' AND check_id=%d ORDER BY gregorian_date DESC, id DESC LIMIT 1", 'check_settlement', $check_id));
        if ($tx) {
            $wpdb->update($this->tables['checks'], [
                'status' => 'paid',
                'paid_transaction_id' => (int)$tx->id,
                'paid_jalali_date' => $tx->jalali_date,
                'paid_gregorian_date' => $tx->gregorian_date,
                'updated_at' => current_time('mysql')
            ], ['id' => $check_id]);
        } else {
            $wpdb->update($this->tables['checks'], [
                'status' => 'open',
                'paid_transaction_id' => 0,
                'paid_jalali_date' => null,
                'paid_gregorian_date' => null,
                'updated_at' => current_time('mysql')
            ], ['id' => $check_id]);
        }
    }

    private function get_installment($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT i.*, l.title AS loan_title FROM {$this->tables['loan_installments']} i LEFT JOIN {$this->tables['loans']} l ON l.id=i.loan_id WHERE i.id=%d", $id));
    }

    private function refresh_loan_paid_count($loan_id) {
        global $wpdb;
        $paid = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['loan_installments']} WHERE loan_id=%d AND status='paid'", $loan_id));
        $total = (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tables['loan_installments']} WHERE loan_id=%d", $loan_id));
        $status = ($total > 0 && $paid >= $total) ? 'paid' : 'open';
        $wpdb->update($this->tables['loans'], ['paid_installments'=>$paid, 'status'=>$status, 'updated_at'=>current_time('mysql')], ['id'=>$loan_id]);
    }

    private function add_months_to_gregorian($date, $months) {
        try {
            $dt = new DateTime($date ?: date('Y-m-d'));
            if ((int)$months > 0) $dt->modify('+' . (int)$months . ' month');
            return $dt->format('Y-m-d');
        } catch (Exception $e) { return date('Y-m-d'); }
    }

    private function count_monthly_installments($first, $last) {
        if (!$first || !$last) return 0;
        try {
            $a = new DateTime($first); $b = new DateTime($last);
            if ($b < $a) return 0;
            return ((int)$b->format('Y') - (int)$a->format('Y')) * 12 + ((int)$b->format('n') - (int)$a->format('n')) + 1;
        } catch (Exception $e) { return 0; }
    }

    private function parse_installment_overrides($text) {
        $out = [];
        $lines = preg_split('/\r\n|\r|\n/', (string)$text);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $line = str_replace(['=', ':', '،'], '|', $line);
            $parts = array_values(array_filter(array_map('trim', explode('|', $line)), 'strlen'));
            if (count($parts) < 2) continue;
            $key = preg_replace('/[^0-9\/\-]/', '', $parts[0]);
            $amount = $this->parse_market_number($parts[1]);
            if ($key !== '' && $amount > 0) $out[$key] = $amount;
        }
        return $out;
    }

    private function installment_override_amount($overrides, $installment_no, $jalali_date, $default_amount) {
        $keys = [(string)$installment_no, ltrim((string)$installment_no, '0'), (string)$jalali_date];
        foreach ($keys as $k) {
            if ($k !== '' && isset($overrides[$k]) && (float)$overrides[$k] > 0) return (float)$overrides[$k];
        }
        return (float)$default_amount;
    }

    private function regenerate_loan_installments($loan_id, $loan) {
        global $wpdb;
        $wpdb->delete($this->tables['loan_installments'], ['loan_id'=>$loan_id]);
        $total = max(0, (int)$loan['total_installments']);
        $paid = max(0, min($total, (int)$loan['paid_installments']));
        $amount = (float)$loan['installment_amount'];
        if ($amount <= 0 && $total > 0) $amount = (float)$loan['principal_amount'] / $total;
        $overrides = !empty($loan['variable_installments']) ? $this->parse_installment_overrides($loan['installment_overrides'] ?? '') : [];
        $first = $loan['first_due_gregorian_date'] ?: date('Y-m-d');
        for ($i=1; $i <= $total; $i++) {
            $g = $this->add_months_to_gregorian($first, $i-1);
            $j = $this->gregorian_to_jalali_date($g);
            $row_amount = $this->installment_override_amount($overrides, $i, $j, $amount);
            $wpdb->insert($this->tables['loan_installments'], [
                'user_id'=>get_current_user_id(), 'loan_id'=>$loan_id, 'installment_no'=>$i,
                'amount'=>$row_amount, 'currency'=>$loan['currency'], 'due_jalali_date'=>$j, 'due_gregorian_date'=>$g,
                'status'=>($i <= $paid ? 'paid' : 'open'), 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql')
            ]);
        }
        $this->refresh_loan_paid_count($loan_id);
    }

    public function save_loan() {
        $this->guard(); global $wpdb;
        $id = $this->id('id');
        $received = $this->clean('received_jalali_date') ?: $this->today_jalali();
        $first_due = $this->clean('first_due_jalali_date');
        $last_due = $this->clean('last_due_jalali_date');
        $first_greg = $first_due ? $this->jalali_to_gregorian_date($first_due) : null;
        $last_greg = $last_due ? $this->jalali_to_gregorian_date($last_due) : null;
        $principal = $this->money('principal_amount');
        $total = $this->count_monthly_installments($first_greg, $last_greg);
        $installment_amount = $this->money('installment_amount');
        if ($installment_amount <= 0 && $total > 0) $installment_amount = round($principal / $total, 2);
        $posted_paid = max(0, absint($this->clean('paid_installments', 0)));
        $paid_existing = ($total > 0) ? min($total, $posted_paid) : $posted_paid;
        $data = [
            'user_id'=>get_current_user_id(), 'person_key'=>$this->clean('person_key','hamidreza'), 'title'=>$this->clean('title'), 'lender'=>$this->clean('lender'),
            'principal_amount'=>$principal, 'currency'=>$this->clean('currency','toman'), 'account_id'=>$this->id('account_id'), 'received_jalali_date'=>$received, 'received_gregorian_date'=>$this->jalali_to_gregorian_date($received),
            'used_for'=>$this->textarea('used_for'), 'total_installments'=>$total, 'paid_installments'=>$paid_existing, 'installment_amount'=>$installment_amount,
            'variable_installments'=>isset($_POST['variable_installments']) ? 1 : 0, 'installment_overrides'=>$this->textarea('installment_overrides'),
            'first_due_jalali_date'=>$first_due, 'first_due_gregorian_date'=>$first_greg,
            'last_due_jalali_date'=>$last_due, 'last_due_gregorian_date'=>$last_greg,
            'status'=>$this->clean('status','open'), 'note'=>$this->textarea('note'), 'updated_at'=>current_time('mysql')
        ];
        if ($id) $wpdb->update($this->tables['loans'], $data, ['id'=>$id]);
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['loans'], $data); $id=(int)$wpdb->insert_id; }
        if ($id) $this->regenerate_loan_installments($id, $data);
        // دریافت اصل وام، موجودی حساب را زیاد می‌کند اما درآمد نیست.
        if ($id) $this->sync_incur_transaction('source_loan_id', $id, ['account_id'=>$this->id('account_id'), 'amount'=>$principal, 'currency'=>$data['currency'], 'jalali_date'=>$received, 'person_key'=>$data['person_key'], 'description'=>'دریافت وام '.($data['title'] ?: '')]);
        $this->redirect('debt');
    }

    public function delete_loan() { $this->guard(); global $wpdb; $id=$this->id('id'); $this->archive_item_before_delete('loans',$id,'title'); $inst=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['loan_installments']} WHERE loan_id=%d", $id)); foreach($inst as $i){ $this->archive_item_before_delete('loan_installments',(int)$i->id,'due_jalali_date'); } $this->delete_incur_transaction('source_loan_id',$id); $wpdb->delete($this->tables['loan_installments'], ['loan_id'=>$id]); $wpdb->delete($this->tables['loans'], ['id'=>$id]); $this->redirect('debt'); }

    public function save_check() {
        $this->guard(); global $wpdb;
        $due = $this->clean('first_due_jalali_date'); $id=$this->id('id');
        $data = [
            'user_id'=>get_current_user_id(), 'person_key'=>$this->clean('person_key','hamidreza'), 'title'=>$this->clean('title'), 'check_count'=>max(1,absint($this->clean('check_count',1))),
            'amount_each'=>$this->money('amount_each'), 'currency'=>$this->clean('currency','toman'), 'first_due_jalali_date'=>$due, 'first_due_gregorian_date'=>$due ? $this->jalali_to_gregorian_date($due) : null,
            'used_for'=>$this->textarea('used_for'), 'include_in_assets'=>isset($_POST['include_in_assets']) ? 1 : 0, 'status'=>$this->clean('status','open'), 'note'=>$this->textarea('note'), 'updated_at'=>current_time('mysql')
        ];
        if ($id) $wpdb->update($this->tables['checks'], $data, ['id'=>$id]);
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['checks'], $data); }
        $this->redirect('debt');
    }
    public function delete_check() { $this->guard(); global $wpdb; $id=$this->id('id'); $this->archive_item_before_delete('checks',$id,'title'); $wpdb->delete($this->tables['checks'], ['id'=>$id]); $this->redirect('debt'); }


    public function save_recurring() {
        $this->guard(); global $wpdb;
        $id = $this->id('id');
        $start = $this->clean('start_jalali_date') ?: $this->today_jalali();
        $next = $this->clean('next_jalali_date') ?: $start;
        $data = [
            'user_id'=>get_current_user_id(), 'person_key'=>$this->clean('person_key','hamidreza'), 'title'=>$this->clean('title'),
            'category_id'=>$this->id('category_id'), 'account_id'=>$this->id('account_id'), 'type'=>$this->clean('type','expense'),
            'amount'=>$this->money('amount'), 'currency'=>$this->clean('currency','toman'), 'interval_type'=>$this->clean('interval_type','monthly'),
            'start_jalali_date'=>$start, 'start_gregorian_date'=>$this->jalali_to_gregorian_date($start),
            'next_jalali_date'=>$next, 'next_gregorian_date'=>$this->jalali_to_gregorian_date($next),
            'status'=>$this->clean('status','active'), 'note'=>$this->textarea('note'), 'updated_at'=>current_time('mysql')
        ];
        if ($id) $wpdb->update($this->tables['recurring'], $data, ['id'=>$id]);
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['recurring'], $data); }
        $this->redirect('debt');
    }
    public function delete_recurring() { $this->guard(); global $wpdb; $id=$this->id('id'); $this->archive_item_before_delete('recurring',$id,'title'); $wpdb->delete($this->tables['recurring'], ['id'=>$id]); $this->redirect('debt'); }

    private function view_recurring() {
        global $wpdb; $curr=$this->currencies();
        $edit_id = isset($_GET['hpa_edit_recurring']) ? absint($_GET['hpa_edit_recurring']) : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['recurring']} WHERE id=%d", $edit_id)) : null;
        $is_edit = (bool)$edit;
        echo '<section class="hpa-card '.($is_edit?'hpa-editing':'').'"><h2>'.($is_edit?'ویرایش تراکنش تکرارشونده':'اجاره، بیمه و تراکنش‌های تکرارشونده').'</h2>';
        $this->form_open('hpa_save_recurring');
        if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($edit->id).'">';
        $person=$is_edit?($edit->person_key?:'hamidreza'):'hamidreza'; $currency=$is_edit?($edit->currency?:'toman'):'toman'; $type=$is_edit?($edit->type?:'expense'):'expense'; $status=$is_edit?($edit->status?:'active'):'active';
        echo '<div class="hpa-form-grid">'
            .'<label>عنوان<input name="title" required placeholder="مثلاً اجاره خانه / بیمه / اشتراک" value="'.esc_attr($is_edit?$edit->title:'').'" /></label>'
            .'<label>شخص'.$this->person_select('person_key',$person).'</label>'
            .'<label>نوع<select name="type"><option value="expense" '.selected($type,'expense',false).'>هزینه</option><option value="income" '.selected($type,'income',false).'>درآمد</option></select></label>'
            .'<label>حساب'.$this->account_select('account_id',$is_edit?(int)$edit->account_id:0).'</label>'
            .'<label>دسته‌بندی'.$this->category_select('category_id',$type,$is_edit?(int)$edit->category_id:0).'</label>'
            .'<label>مبلغ<input name="amount" inputmode="decimal" required value="'.esc_attr($is_edit?$edit->amount:'').'" /></label>'
            .'<label>واحد پول<select name="currency">';
        foreach($curr as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($currency,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label><label>تکرار<select name="interval_type"><option value="monthly" '.selected($is_edit?$edit->interval_type:'monthly','monthly',false).'>ماهانه</option><option value="weekly" '.selected($is_edit?$edit->interval_type:'monthly','weekly',false).'>هفتگی</option><option value="yearly" '.selected($is_edit?$edit->interval_type:'monthly','yearly',false).'>سالانه</option></select></label>'
            .'<label>تاریخ شروع<input name="start_jalali_date" class="hpa-jdate" value="'.esc_attr($is_edit?$edit->start_jalali_date:$this->today_jalali()).'"></label>'
            .'<label>موعد بعدی<input name="next_jalali_date" class="hpa-jdate" value="'.esc_attr($is_edit?$edit->next_jalali_date:$this->today_jalali()).'"></label>'
            .'<label>وضعیت<select name="status"><option value="active" '.selected($status,'active',false).'>فعال</option><option value="paused" '.selected($status,'paused',false).'>متوقف</option></select></label>'
            .'<label class="hpa-col-full">یادداشت<textarea name="note">'.esc_textarea($is_edit?$edit->note:'').'</textarea></label></div>';
        $this->form_close($is_edit?'ذخیره تراکنش تکرارشونده':'ثبت تراکنش تکرارشونده');
        if ($is_edit) echo '<a class="hpa-btn hpa-btn-ghost hpa-cancel-edit" href="'.esc_url(remove_query_arg('hpa_edit_recurring')).'">انصراف از ویرایش</a>';
        $rows=$wpdb->get_results("SELECT r.*, c.name AS category_name, c.icon AS category_icon FROM {$this->tables['recurring']} r LEFT JOIN {$this->tables['categories']} c ON c.id=r.category_id ORDER BY COALESCE(r.next_gregorian_date, r.created_at) ASC LIMIT 50");
        echo '<div class="hpa-table-wrap"><table class="hpa-table hpa-table-pro"><thead><tr><th>عنوان</th><th>شخص</th><th>دسته</th><th>مبلغ</th><th>تکرار</th><th>موعد بعدی</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        foreach($rows as $r){ $edit_url=esc_url(add_query_arg(['hpa_tab'=>'debt','hpa_edit_recurring'=>$r->id])); echo '<tr><td><strong>'.esc_html($r->title).'</strong></td><td>'.esc_html($this->person_label($r->person_key)).'</td><td>'.$this->clickable_category((int)$r->category_id, $r->category_name ?: '—', $r->category_icon ?: '🏷️').'</td><td>'.esc_html($this->fmt_money($r->amount,$r->currency)).'</td><td>'.esc_html($r->interval_type).'</td><td>'.esc_html($r->next_jalali_date).'</td><td>'.esc_html($r->status==='active'?'فعال':'متوقف').'</td><td><div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.$this->delete_button('hpa_delete_recurring',$r->id,'debt').'</div></td></tr>'; }
        if(!$rows) echo '<tr><td colspan="8" class="hpa-muted">تراکنش تکرارشونده‌ای ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
    }


    private function loan_remaining_total_toman() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT amount,currency FROM {$this->tables['loan_installments']} WHERE status!='paid'");
        return $this->rows_sum_toman($rows);
    }
    private function check_open_total_toman() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT (amount_each * check_count) AS amount, currency FROM {$this->tables['checks']} WHERE status!='paid'");
        return $this->rows_sum_toman($rows);
    }
    private function loan_due_reminders() {
        global $wpdb;
        $today = date('Y-m-d'); $to = date('Y-m-d', strtotime('+5 days'));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT i.*, l.title AS loan_title FROM {$this->tables['loan_installments']} i LEFT JOIN {$this->tables['loans']} l ON l.id=i.loan_id WHERE i.status!='paid' AND i.due_gregorian_date BETWEEN %s AND %s ORDER BY i.due_gregorian_date ASC LIMIT 5", $today, $to));
        if (!$rows) return;
        echo '<section class="hpa-card hpa-loan-reminders"><h3>یادآور اقساط نزدیک</h3>';
        foreach ($rows as $r) {
            $url = esc_url(add_query_arg(['hpa_tab'=>'transactions','hpa_pay_loan'=>$r->id]));
            echo '<div class="hpa-list-row hpa-warn-row"><span class="hpa-badge">🏦</span><b>'.esc_html($r->loan_title).' — قسط '.(int)$r->installment_no.'<small>موعد: '.esc_html($r->due_jalali_date).'</small></b><em>'.esc_html($this->fmt_money($r->amount,$r->currency)).'</em><a class="hpa-btn hpa-btn-small" href="'.$url.'">پرداخت کردم</a></div>';
        }
        echo '</section>';
    }


    private function check_due_reminders() {
        global $wpdb;
        $today=date('Y-m-d'); $to=date('Y-m-d', strtotime('+30 days'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['checks']} WHERE status!='paid' AND first_due_gregorian_date BETWEEN %s AND %s ORDER BY first_due_gregorian_date ASC LIMIT 10", $today, $to));
        if(!$rows) return;
        echo '<section class="hpa-card hpa-check-reminders"><h3>چک‌های آینده ۳۰ روزه</h3>';
        foreach($rows as $r) {
            $url = esc_url(add_query_arg(['hpa_tab'=>'transactions','hpa_pay_check'=>(int)$r->id]));
            $total = (float)$r->amount_each * max(1, (int)$r->check_count);
            echo '<div class="hpa-list-row hpa-warn-row"><span class="hpa-badge">🧾</span><b>'.esc_html($r->title).'<small>موعد: '.esc_html($r->first_due_jalali_date).' | '.esc_html($r->used_for).'</small></b><em>'.esc_html($this->fmt_money($total,$r->currency)).'</em><a class="hpa-btn hpa-btn-small" href="'.$url.'">پرداخت کردم</a></div>';
        }
        echo '</section>';
    }
    private function recurring_due_reminders() {
        global $wpdb;
        $today=date('Y-m-d'); $to=date('Y-m-d', strtotime('+5 days'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['recurring']} WHERE status='active' AND next_gregorian_date BETWEEN %s AND %s ORDER BY next_gregorian_date ASC LIMIT 5", $today, $to));
        if(!$rows) return;
        echo '<section class="hpa-card hpa-recurring-reminders"><h3>یادآور تراکنش‌های تکرارشونده</h3>';
        foreach($rows as $r) echo '<div class="hpa-list-row hpa-warn-row"><span class="hpa-badge">🔁</span><b>'.esc_html($r->title).'<small>موعد بعدی: '.esc_html($r->next_jalali_date).'</small></b><em>'.esc_html($this->fmt_money($r->amount,$r->currency)).'</em><a class="hpa-btn hpa-btn-small" href="'.esc_url(add_query_arg(['hpa_tab'=>'transactions','hpa_q'=>$r->title])).'">ثبت پرداخت</a></div>';
        echo '</section>';
    }

    private function view_debts_full() {
        echo '<section class="hpa-debt-tabs"><div class="hpa-card"><h2>بدهی‌های ساده</h2></div></section>';
        $this->view_debt_like('debts','debt','بدهی‌ها','hpa_save_debt','طلبکار');
        $this->view_recurring();
        $this->view_loans();
        $this->view_checks();
        $this->report_future_obligations();
        $this->report_next_month_obligations();
        $this->report_debt_backed_assets();
    }

    private function view_loans() {
        global $wpdb; $curr=$this->currencies();
        $edit_id = isset($_GET['hpa_edit_loan']) ? absint($_GET['hpa_edit_loan']) : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['loans']} WHERE id=%d", $edit_id)) : null;
        $is_edit = (bool)$edit;
        echo '<section class="hpa-card '.($is_edit?'hpa-editing':'').'"><h2>'.($is_edit?'ویرایش وام / اقساط':'اقساط و وام‌ها').'</h2>';
        $this->form_open('hpa_save_loan');
        if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($edit->id).'">';
        $person = $is_edit ? ($edit->person_key ?: 'hamidreza') : 'hamidreza';
        $currency = $is_edit ? ($edit->currency ?: 'toman') : 'toman';
        $status = $is_edit ? ($edit->status ?: 'open') : 'open';
        echo '<div class="hpa-form-grid">'
            .'<label>عنوان وام<input name="title" required placeholder="مثلاً وام خرید طلا / وام بانک ملت" value="'.esc_attr($is_edit?$edit->title:'').'"></label>'
            .'<label>شخص'.$this->person_select('person_key',$person).'</label>'
            .'<label>وام‌دهنده / بانک<input name="lender" value="'.esc_attr($is_edit?$edit->lender:'').'"></label>'
            .'<label>مبلغ اصلی وام<input name="principal_amount" required inputmode="decimal" value="'.esc_attr($is_edit?$edit->principal_amount:'').'"></label>'
            .'<label>واحد پول<select name="currency">';
        foreach($curr as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($currency,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label>'
            .'<label>تاریخ دریافت وام<input name="received_jalali_date" class="hpa-jdate" required value="'.esc_attr($is_edit?($edit->received_jalali_date?:$this->today_jalali()):$this->today_jalali()).'"></label>'
            .'<label>واریز به حساب'.$this->account_select('account_id',$is_edit?(int)($edit->account_id ?? 0):0).'<small class="hpa-help">اگر حساب انتخاب شود، اصل وام به‌صورت خودکار به‌عنوان تراکنش «قرض/وام» به آن حساب واریز می‌شود (جزو درآمد حساب نمی‌شود).</small></label>'
            .'<label>مبلغ هر قسط<input name="installment_amount" inputmode="decimal" placeholder="اگر خالی بماند خودکار از مبلغ و بازه اقساط محاسبه می‌شود" value="'.esc_attr($is_edit?$edit->installment_amount:'').'"></label>'
            .'<label>تاریخ اولین قسط<input name="first_due_jalali_date" class="hpa-jdate" placeholder="1403/02/15" value="'.esc_attr($is_edit?$edit->first_due_jalali_date:'').'"></label>'
            .'<label>تاریخ آخرین قسط<input name="last_due_jalali_date" class="hpa-jdate" placeholder="1405/02/15" value="'.esc_attr($is_edit?$edit->last_due_jalali_date:'').'"><small class="hpa-help">تعداد کل اقساط خودکار از فاصله اولین تا آخرین قسط محاسبه می‌شود.</small></label>'
            .'<label>تعداد اقساط پرداخت‌شده<input name="paid_installments" inputmode="numeric" min="0" value="'.esc_attr($is_edit?(int)$edit->paid_installments:0).'"><small class="hpa-help">برای وام‌های قبلی وارد کن تا مانده اقساط درست محاسبه شود.</small></label>'
            .'<label>وضعیت<select name="status"><option value="open" '.selected($status,'open',false).'>باز</option><option value="paid" '.selected($status,'paid',false).'>تسویه‌شده</option></select></label>'
            .'<label class="hpa-col-full hpa-variable-installment-toggle"><span class="hpa-checkline"><input type="checkbox" name="variable_installments" value="1" '.checked($is_edit?(int)($edit->variable_installments ?? 0):0,1,false).'> اقساط با مبلغ متفاوت؟</span><small class="hpa-help">اگر بعضی ماه‌ها مبلغ قسط فرق دارد، این گزینه را فعال کن.</small></label>'
            .'<label class="hpa-col-full hpa-variable-installment-box">مبالغ متفاوت اقساط<textarea name="installment_overrides" placeholder="هر خط یک قسط:&#10;3 = 25000000&#10;1403/07/15 = 25000000">'.esc_textarea($is_edit?($edit->installment_overrides ?? ''):'').'</textarea><small class="hpa-help">می‌توانی شماره قسط یا تاریخ شمسی قسط را بنویسی. بقیه اقساط با مبلغ عادی محاسبه می‌شوند.</small></label>'
            .'<label class="hpa-col-full">کجا استفاده شده؟<textarea name="used_for" placeholder="مثلاً خرید سکه، تعمیر خانه، خرید ماشین...">'.esc_textarea($is_edit?$edit->used_for:'').'</textarea></label>'
            .'<label class="hpa-col-full">یادداشت<textarea name="note">'.esc_textarea($is_edit?$edit->note:'').'</textarea></label></div>';
        $this->form_close($is_edit?'ذخیره تغییرات وام':'ثبت وام و ساخت برنامه اقساط');
        if ($is_edit) echo '<a class="hpa-btn hpa-btn-ghost hpa-cancel-edit" href="'.esc_url(remove_query_arg('hpa_edit_loan')).'">انصراف از ویرایش</a>';
        $rows=$wpdb->get_results("SELECT * FROM {$this->tables['loans']} ORDER BY id DESC LIMIT 50");
        echo '<div class="hpa-table-wrap"><table class="hpa-table hpa-table-pro"><thead><tr><th>وام</th><th>شخص</th><th>اصل وام</th><th>اقساط پرداخت‌شده</th><th>باقی‌مانده</th><th>آخرین قسط</th><th>مصرف‌شده برای</th><th>عملیات</th></tr></thead><tbody>';
        foreach($rows as $r){ $rem=max(0,(int)$r->total_installments-(int)$r->paid_installments); $edit_url=esc_url(add_query_arg(['hpa_tab'=>'debt','hpa_edit_loan'=>$r->id])); echo '<tr><td><div class="hpa-loan-title"><strong>'.esc_html($r->title).'</strong><small class="hpa-loan-lender">وام‌دهنده: '.esc_html($r->lender ?: '—').'</small></div></td><td>'.esc_html($this->person_label($r->person_key)).'</td><td>'.esc_html($this->fmt_money($r->principal_amount,$r->currency)).'</td><td>'.esc_html((int)$r->paid_installments.' / '.(int)$r->total_installments).'</td><td>'.esc_html($rem.' قسط').'</td><td>'.esc_html($r->last_due_jalali_date ?: '—').'</td><td>'.esc_html(wp_trim_words($r->used_for,12)).'</td><td><div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.$this->delete_button('hpa_delete_loan',$r->id,'debt').'</div></td></tr>'; }
        if(!$rows) echo '<tr><td colspan="8" class="hpa-muted">وامی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private function view_checks() {
        global $wpdb; $curr=$this->currencies();
        $edit_id = isset($_GET['hpa_edit_check']) ? absint($_GET['hpa_edit_check']) : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['checks']} WHERE id=%d", $edit_id)) : null;
        $is_edit = (bool)$edit;
        echo '<section class="hpa-card '.($is_edit?'hpa-editing':'').'"><h2>'.($is_edit?'ویرایش چک':'چک‌های باز').'</h2>';
        $this->form_open('hpa_save_check');
        if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($edit->id).'">';
        $person=$is_edit?($edit->person_key?:'hamidreza'):'hamidreza'; $currency=$is_edit?($edit->currency?:'toman'):'toman'; $status=$is_edit?($edit->status?:'open'):'open';
        echo '<div class="hpa-form-grid">'
            .'<label>عنوان چک‌ها<input name="title" required placeholder="مثلاً چک خرید خودرو / خرید طلا" value="'.esc_attr($is_edit?$edit->title:'').'"></label>'
            .'<label>شخص'.$this->person_select('person_key',$person).'</label>'
            .'<label>تعداد چک<input name="check_count" inputmode="numeric" value="'.esc_attr($is_edit?(int)$edit->check_count:1).'"></label>'
            .'<label>مبلغ هر چک<input name="amount_each" inputmode="decimal" required value="'.esc_attr($is_edit?$edit->amount_each:'').'"></label>'
            .'<label>واحد پول<select name="currency">';
        foreach($curr as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($currency,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label>'
            .'<label>تاریخ اولین چک<input name="first_due_jalali_date" class="hpa-jdate" placeholder="1403/02/15" value="'.esc_attr($is_edit?$edit->first_due_jalali_date:'').'"></label>'
            .'<label>وضعیت<select name="status"><option value="open" '.selected($status,'open',false).'>باز</option><option value="paid" '.selected($status,'paid',false).'>تسویه‌شده</option></select></label>'
            .'<label>در دارایی حساب شود؟ <span class="hpa-checkline"><input type="checkbox" name="include_in_assets" value="1" '.checked($is_edit?(int)$edit->include_in_assets:0,1,false).'> فقط وقتی خودم می‌خواهم</span></label>'
            .'<label class="hpa-col-full">در چه زمینه‌ای صرف شده؟<textarea name="used_for">'.esc_textarea($is_edit?$edit->used_for:'').'</textarea></label>'
            .'<label class="hpa-col-full">یادداشت<textarea name="note">'.esc_textarea($is_edit?$edit->note:'').'</textarea></label></div>';
        $this->form_close($is_edit?'ذخیره تغییرات چک':'ثبت چک');
        if ($is_edit) echo '<a class="hpa-btn hpa-btn-ghost hpa-cancel-edit" href="'.esc_url(remove_query_arg('hpa_edit_check')).'">انصراف از ویرایش</a>';
        $rows=$wpdb->get_results("SELECT * FROM {$this->tables['checks']} ORDER BY COALESCE(first_due_gregorian_date, created_at) ASC LIMIT 50");
        echo '<div class="hpa-table-wrap"><table class="hpa-table hpa-table-pro"><thead><tr><th>عنوان</th><th>شخص</th><th>تعداد</th><th>مبلغ هر چک</th><th>جمع</th><th>موعد اول</th><th>مصرف‌شده برای</th><th>دارایی؟</th><th>عملیات</th></tr></thead><tbody>';
        foreach($rows as $r){ $total=(float)$r->amount_each*(int)$r->check_count; $edit_url=esc_url(add_query_arg(['hpa_tab'=>'debt','hpa_edit_check'=>$r->id])); echo '<tr><td><strong>'.esc_html($r->title).'</strong></td><td>'.esc_html($this->person_label($r->person_key)).'</td><td>'.(int)$r->check_count.'</td><td>'.esc_html($this->fmt_money($r->amount_each,$r->currency)).'</td><td>'.esc_html($this->fmt_money($total,$r->currency)).'</td><td>'.esc_html($r->first_due_jalali_date).'</td><td>'.esc_html(wp_trim_words($r->used_for,10)).'</td><td>'.($r->include_in_assets?'بله':'خیر').'</td><td><div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.$this->delete_button('hpa_delete_check',$r->id,'debt').'</div></td></tr>'; }
        if(!$rows) echo '<tr><td colspan="9" class="hpa-muted">چکی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
    }

    public function admin_menu() {
        add_options_page('حسابدار شخصی', 'حسابدار شخصی', 'manage_options', 'hpa-settings', [$this, 'settings_page']);
    }
    public function settings_page() {
        $settings = get_option(self::OPTION, []); $roles = wp_roles()->roles;
        ?>
        <div class="wrap hpa-admin-wrap" dir="rtl"><h1>تنظیمات حسابدار شخصی</h1><?php $admin_tab = isset($_GET['hpa_admin_tab']) ? sanitize_key($_GET['hpa_admin_tab']) : 'settings'; ?><h2 class="nav-tab-wrapper"><a class="nav-tab <?php echo $admin_tab==='settings'?'nav-tab-active':''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>'hpa-settings','hpa_admin_tab'=>'settings'], admin_url('options-general.php'))); ?>">تنظیمات</a><a class="nav-tab <?php echo $admin_tab==='deleted'?'nav-tab-active':''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>'hpa-settings','hpa_admin_tab'=>'deleted'], admin_url('options-general.php'))); ?>">حذف‌شده‌ها</a><a class="nav-tab <?php echo $admin_tab==='archive'?'nav-tab-active':''; ?>" href="<?php echo esc_url(add_query_arg(['page'=>'hpa-settings','hpa_admin_tab'=>'archive'], admin_url('options-general.php'))); ?>">بایگانی</a></h2><?php if($admin_tab==='deleted'){ $this->admin_deleted_items_panel(); echo '</div>'; return; } if($admin_tab==='archive'){ $this->admin_archive_panel(); echo '</div>'; return; } ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="hpa_save_settings"><?php wp_nonce_field(self::NONCE,'hpa_nonce'); ?>
            <table class="form-table" role="presentation"><tbody>
            <tr><th>نقش‌های مجاز</th><td><?php foreach($roles as $key=>$role): ?><label style="display:block;margin:6px 0"><input type="checkbox" name="allowed_roles[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $settings['allowed_roles'] ?? ['administrator'], true)); ?>> <?php echo esc_html(translate_user_role($role['name'])); ?></label><?php endforeach; ?></td></tr>
            <tr><th>حالت ظاهری</th><td><select name="theme_mode"><option value="light" <?php selected($settings['theme_mode'] ?? 'light','light'); ?>>روشن</option><option value="dark" <?php selected($settings['theme_mode'] ?? 'light','dark'); ?>>تیره</option></select></td></tr>
            <tr><th>ارز پیش‌فرض</th><td><select name="default_currency"><?php foreach($this->currencies() as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($settings['default_currency'] ?? 'toman',$k,false).'>'.esc_html($v).'</option>'; ?></select></td></tr>
            <tr><th>آپدیت خودکار نرخ‌ها</th><td><label><input type="checkbox" name="auto_rate_update" value="1" <?php checked(!empty($settings['auto_rate_update'])); ?>> روزی یک بار با WP-Cron نرخ‌ها از موتور داخلی TGJU دریافت شود.</label><p class="description">در این نسخه اتصال هوش مصنوعی حذف شده و نرخ‌ها از منبع TGJU خوانده و در جدول نرخ‌های همین افزونه کش می‌شوند.</p></td></tr>
            <tr><th>نمایش حساب‌های بسته‌شده</th><td><label><input type="checkbox" name="show_inactive_accounts" value="1" <?php checked(!empty($settings['show_inactive_accounts'])); ?>> حساب‌های بسته‌شده/حذف‌شده در تب حساب‌ها و گزارش‌ها نمایش داده شوند.</label><p class="description">پیش‌فرض خاموش است تا حساب‌های حذف‌شده در رابط اصلی دیده نشوند. بازیابی کامل از تب «حذف‌شده‌ها» انجام می‌شود.</p></td></tr>
            <tr><th>منبع نرخ‌ها</th><td><strong>TGJU داخلی</strong><p class="description">موتور دریافت قیمت TGJU داخل همین افزونه ادغام شده است. اگر سرویس TGJU موقتاً در دسترس نباشد، آخرین نرخ ذخیره‌شده یا نرخ دستی باقی می‌ماند.</p></td></tr>
            <tr><th>PIN ورود افزونه</th><td><input type="password" name="security_pin" value="<?php echo esc_attr($settings['security_pin'] ?? ''); ?>" autocomplete="new-password" placeholder="مثلاً 1234"><p class="description">اگر وارد شود، حتی ادمین هم برای دیدن صفحه شورت‌کد باید PIN را وارد کند.</p></td></tr>
            <tr><th>اتصال نرم‌افزار دسکتاپ (حساب‌یار)</th><td><label><input type="checkbox" name="app_sync_enabled" value="1" <?php checked(!empty($settings['app_sync_enabled'])); ?>> اجازهٔ اتصال و همگام‌سازی داده با نرم‌افزار ویندوزی «حساب‌یار» را بده.</label><p class="description">پس از فعال‌کردن، در تنظیماتِ نرم‌افزار دسکتاپ، آدرس سایت زیر و نام‌کاربری/رمز وردپرس (همین حساب) را وارد کن. فقط کاربر با ایمیل مجاز (<code><?php echo esc_html(self::AUTHORIZED_EMAIL); ?></code>) می‌تواند متصل شود.</p><p class="description">آدرس API این سایت: <code><?php echo esc_html(rest_url('hpa/v1/')); ?></code></p></td></tr>
            </tbody></table><?php submit_button('ذخیره تنظیمات'); ?>
        </form><p>شورت‌کد: <code>[hamid_personal_accounting]</code></p></div>
        <?php
    }


    private function admin_deleted_items_panel() {
        global $wpdb;
        echo '<h2>آرشیو حذف‌شده‌ها</h2><p>حذف‌ها به‌صورت نرم ذخیره می‌شوند تا در صورت اشتباه، از اینجا بازیابی شوند. این بخش فقط در تنظیمات ادمین وردپرس نمایش داده می‌شود.</p>';
        $rows = $wpdb->get_results("SELECT * FROM {$this->tables['deleted_items']} ORDER BY deleted_at DESC LIMIT 200");
        echo '<table class="widefat striped"><thead><tr><th>نوع</th><th>عنوان</th><th>شناسه قبلی</th><th>زمان حذف</th><th>عملیات</th></tr></thead><tbody>';
        if (!$rows) echo '<tr><td colspan="5">مورد حذف‌شده‌ای ثبت نشده است.</td></tr>';
        foreach($rows as $r){
            $restore = wp_nonce_url(admin_url('admin-post.php?action=hpa_restore_deleted_item&id='.(int)$r->id), self::NONCE, 'hpa_nonce');
            $perm = wp_nonce_url(admin_url('admin-post.php?action=hpa_permanent_delete_item&id='.(int)$r->id), self::NONCE, 'hpa_nonce');
            echo '<tr><td>'.esc_html($r->table_key).'</td><td>'.esc_html($r->item_title ?: '—').'</td><td>'.esc_html($r->original_id).'</td><td>'.esc_html($r->deleted_at).'</td><td><a class="button button-primary" href="'.esc_url($restore).'">بازیابی</a> <a class="button" onclick="return confirm(\'حذف دائمی انجام شود؟\')" href="'.esc_url($perm).'">حذف دائمی</a></td></tr>';
        }
        echo '</tbody></table>';
    }

    public function export_backup() {
        $this->guard(); global $wpdb;
        $data = ['version'=>self::VERSION, 'created_at'=>current_time('mysql'), 'tables'=>[]];
        foreach ($this->tables as $key=>$table) {
            if (in_array($key, ['settings'], true)) continue;
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if ($exists) $data['tables'][$key] = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="hpa-backup-'.date('Ymd-His').'.json"');
        echo wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public function import_backup() {
        $this->guard(); global $wpdb;
        if (empty($_FILES['hpa_backup']['tmp_name'])) $this->redirect('reports');
        $json = file_get_contents($_FILES['hpa_backup']['tmp_name']);
        $data = json_decode($json, true);
        if (empty($data['tables']) || !is_array($data['tables'])) $this->redirect('reports');
        foreach ($data['tables'] as $key=>$rows) {
            if (empty($this->tables[$key]) || !is_array($rows)) continue;
            $table = $this->tables[$key];
            foreach ($rows as $row) {
                if (!is_array($row)) continue;
                $clean = [];
                foreach ($row as $col=>$val) { $clean[sanitize_key($col)] = is_scalar($val) ? wp_kses_post((string)$val) : ''; }
                if (!empty($clean['id'])) {
                    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM `$table` WHERE id=%d", (int)$clean['id']));
                    if ($exists) $wpdb->update($table, $clean, ['id'=>(int)$clean['id']]); else $wpdb->insert($table, $clean);
                } else $wpdb->insert($table, $clean);
            }
        }
        $this->redirect('reports');
    }

    public function save_settings() {
        if ( ! $this->is_authorized_user() ) {
            wp_die( 'دسترسی غیرمجاز.', '', array( 'response' => 403 ) );
        }
        check_admin_referer(self::NONCE,'hpa_nonce');
        $roles = isset($_POST['allowed_roles']) ? array_map('sanitize_key', (array)$_POST['allowed_roles']) : ['administrator'];
        if (!in_array('administrator', $roles, true)) $roles[] = 'administrator';
        update_option(self::OPTION, [
            'allowed_roles'=>$roles,
            'theme_mode'=>sanitize_key($_POST['theme_mode'] ?? 'light'),
            'default_currency'=>sanitize_key($_POST['default_currency'] ?? 'toman'),
            'auto_rate_update'=>isset($_POST['auto_rate_update']) ? 1 : 0,
            'show_inactive_accounts'=>isset($_POST['show_inactive_accounts']) ? 1 : 0,
            'rate_provider'=>'tgju',
            'security_pin'=>sanitize_text_field(wp_unslash($_POST['security_pin'] ?? '')),
            'app_sync_enabled'=>isset($_POST['app_sync_enabled']) ? 1 : 0,
        ], false);
        wp_safe_redirect(add_query_arg('updated','true', wp_get_referer())); exit;
    }

    // ================= ARCHIVE (snapshot + reset to zero) =================
    private function archive_groups() {
        return [
            'tx' => 'تراکنش‌ها',
            'accounts' => 'حساب‌ها',
            'assets' => 'دارایی‌ها',
            'qard' => 'قرض‌ها (بدهی ساده)',
            'liabilities' => 'بدهی‌ها (وام، چک، تکرارشونده)',
            'receivables' => 'طلب‌ها',
        ];
    }
    private function rows_sum_toman_arr($rows, $field='amount') {
        $sum=0; foreach((array)$rows as $r){ $r=(array)$r; $sum += $this->amount_to_toman($r[$field] ?? 0, $r['currency'] ?? 'toman'); } return $sum;
    }
    private function redirect_settings_archive() {
        wp_safe_redirect(add_query_arg(['page'=>'hpa-settings','hpa_admin_tab'=>'archive','archived'=>'1'], admin_url('options-general.php'))); exit;
    }
    public function save_archive() {
        if ( ! $this->is_authorized_user() ) wp_die('دسترسی غیرمجاز.', '', ['response'=>403]);
        check_admin_referer(self::NONCE, 'hpa_nonce');
        global $wpdb; $T=$this->tables; $groups=$this->archive_groups();
        $selected=[];
        if (!empty($_POST['group_all'])) $selected=array_keys($groups);
        else foreach($groups as $k=>$v) if (!empty($_POST['group_'.$k])) $selected[]=$k;
        if (!$selected) $this->redirect_settings_archive();
        $G=array_flip($selected);
        $title=sanitize_text_field(wp_unslash($_POST['archive_title'] ?? '')) ?: ('بایگانی '.$this->today_jalali());
        $snap=[]; $summary=[];
        $add=function($table,$rows) use (&$snap){ if($rows) $snap[$table]=array_merge($snap[$table]??[], $rows); };
        $now=current_time('mysql');
        $wipeAllTx = isset($G['tx']) || isset($G['accounts']);
        if ($wipeAllTx) {
            $txs=$wpdb->get_results("SELECT * FROM {$T['transactions']}", ARRAY_A);
            if (isset($G['tx'])) $summary['tx']=['label'=>$groups['tx'],'count'=>count($txs),'total'=>$this->transaction_sum_toman('income')];
            if (isset($G['accounts'])) {
                $accts=$wpdb->get_results("SELECT * FROM {$T['accounts']}", ARRAY_A);
                $balances=$this->calculate_balances(); $totalBal=0;
                foreach($accts as $a) $totalBal += $this->amount_to_toman($balances[$a['id']] ?? 0, $a['currency']);
                $summary['accounts']=['label'=>$groups['accounts'],'count'=>count($accts),'total'=>$totalBal];
                $add($T['accounts'],$accts);
            }
            $add($T['transactions'],$txs);
            $add($T['transaction_items'],$wpdb->get_results("SELECT * FROM {$T['transaction_items']}", ARRAY_A));
            $add($T['transaction_splits'],$wpdb->get_results("SELECT * FROM {$T['transaction_splits']}", ARRAY_A));
            $wpdb->query("DELETE FROM {$T['transactions']}"); $wpdb->query("DELETE FROM {$T['transaction_items']}"); $wpdb->query("DELETE FROM {$T['transaction_splits']}");
            if (isset($G['accounts'])) $wpdb->query("DELETE FROM {$T['accounts']}");
            else $wpdb->query($wpdb->prepare("UPDATE {$T['accounts']} SET opening_balance=0, updated_at=%s", $now));
        }
        if (isset($G['assets'])) {
            $assets=$wpdb->get_results("SELECT * FROM {$T['assets']}", ARRAY_A); $cur=0;
            foreach($assets as $a){ $v=$this->asset_valuation((object)$a); $cur += $v['current_total']; }
            $summary['assets']=['label'=>$groups['assets'],'count'=>count($assets),'total'=>$cur];
            $add($T['assets'],$assets); $add($T['asset_files'],$wpdb->get_results("SELECT * FROM {$T['asset_files']}", ARRAY_A)); $add($T['goals'],$wpdb->get_results("SELECT * FROM {$T['goals']}", ARRAY_A));
            $add($T['transactions'],$wpdb->get_results("SELECT * FROM {$T['transactions']} WHERE type IN ('asset_buy','asset_sell')", ARRAY_A));
            $wpdb->query("DELETE FROM {$T['assets']}"); $wpdb->query("DELETE FROM {$T['asset_files']}"); $wpdb->query("DELETE FROM {$T['goals']}"); $wpdb->query("DELETE FROM {$T['transactions']} WHERE type IN ('asset_buy','asset_sell')");
        }
        if (isset($G['qard'])) {
            $paid=$wpdb->get_results("SELECT * FROM {$T['debts']} WHERE status='paid'", ARRAY_A);
            $summary['qard']=['label'=>$groups['qard'],'count'=>count($paid),'total'=>$this->rows_sum_toman_arr($paid)];
            $add($T['debts'],$paid); $ids=wp_list_pluck($paid,'id');
            if ($ids){ $in=implode(',',array_map('intval',$ids)); $add($T['transactions'],$wpdb->get_results("SELECT * FROM {$T['transactions']} WHERE debt_id IN ($in) AND type IN ('debt_incur','debt_settlement')", ARRAY_A)); $wpdb->query("DELETE FROM {$T['transactions']} WHERE debt_id IN ($in) AND type IN ('debt_incur','debt_settlement')"); $wpdb->query("DELETE FROM {$T['debts']} WHERE status='paid'"); }
        }
        if (isset($G['liabilities'])) {
            $liaCount=0; $liaTotal=0;
            $loansPaid=$wpdb->get_results("SELECT * FROM {$T['loans']} WHERE status='paid'", ARRAY_A); $loanIds=wp_list_pluck($loansPaid,'id');
            $add($T['loans'],$loansPaid); $liaCount+=count($loansPaid); $liaTotal += $this->rows_sum_toman_arr($loansPaid,'principal_amount');
            if ($loanIds){ $in=implode(',',array_map('intval',$loanIds)); $add($T['loan_installments'],$wpdb->get_results("SELECT * FROM {$T['loan_installments']} WHERE loan_id IN ($in)", ARRAY_A)); $add($T['transactions'],$wpdb->get_results("SELECT * FROM {$T['transactions']} WHERE source_loan_id IN ($in) AND type IN ('debt_incur','loan_installment')", ARRAY_A)); $wpdb->query("DELETE FROM {$T['transactions']} WHERE source_loan_id IN ($in) AND type IN ('debt_incur','loan_installment')"); $wpdb->query("DELETE FROM {$T['loan_installments']} WHERE loan_id IN ($in)"); $wpdb->query("DELETE FROM {$T['loans']} WHERE status='paid'"); }
            $checksPaid=$wpdb->get_results("SELECT * FROM {$T['checks']} WHERE status='paid'", ARRAY_A); $checkIds=wp_list_pluck($checksPaid,'id');
            $add($T['checks'],$checksPaid); $liaCount+=count($checksPaid);
            foreach($checksPaid as $c) $liaTotal += $this->amount_to_toman(((float)$c['amount_each'])*max(1,(int)$c['check_count']), $c['currency']);
            if ($checkIds){ $in=implode(',',array_map('intval',$checkIds)); $add($T['transactions'],$wpdb->get_results("SELECT * FROM {$T['transactions']} WHERE check_id IN ($in) AND type='check_settlement'", ARRAY_A)); $wpdb->query("DELETE FROM {$T['transactions']} WHERE check_id IN ($in) AND type='check_settlement'"); $wpdb->query("DELETE FROM {$T['checks']} WHERE status='paid'"); }
            $recInactive=$wpdb->get_results("SELECT * FROM {$T['recurring']} WHERE status!='active'", ARRAY_A); $recIds=wp_list_pluck($recInactive,'id');
            $add($T['recurring'],$recInactive); $liaCount+=count($recInactive);
            if ($recIds){ $in=implode(',',array_map('intval',$recIds)); $add($T['transactions'],$wpdb->get_results("SELECT * FROM {$T['transactions']} WHERE recurring_id IN ($in) AND type='recurring_debt'", ARRAY_A)); $wpdb->query("DELETE FROM {$T['transactions']} WHERE recurring_id IN ($in) AND type='recurring_debt'"); $wpdb->query("DELETE FROM {$T['recurring']} WHERE status!='active'"); }
            $summary['liabilities']=['label'=>$groups['liabilities'],'count'=>$liaCount,'total'=>$liaTotal];
        }
        if (isset($G['receivables'])) {
            $paid=$wpdb->get_results("SELECT * FROM {$T['receivables']} WHERE status='paid'", ARRAY_A);
            $summary['receivables']=['label'=>$groups['receivables'],'count'=>count($paid),'total'=>$this->rows_sum_toman_arr($paid)];
            $add($T['receivables'],$paid); $ids=wp_list_pluck($paid,'id');
            if ($ids){ $in=implode(',',array_map('intval',$ids)); $add($T['transactions'],$wpdb->get_results("SELECT * FROM {$T['transactions']} WHERE receivable_id IN ($in) AND type='receivable_settlement'", ARRAY_A)); $wpdb->query("DELETE FROM {$T['transactions']} WHERE receivable_id IN ($in) AND type='receivable_settlement'"); $wpdb->query("DELETE FROM {$T['receivables']} WHERE status='paid'"); }
        }
        $wpdb->insert($T['archives'], [
            'title'=>$title,
            'scope'=>wp_json_encode(array_map(function($k) use ($groups){ return $groups[$k] ?? $k; }, $selected), JSON_UNESCAPED_UNICODE),
            'summary'=>wp_json_encode($summary, JSON_UNESCAPED_UNICODE), 'data'=>wp_json_encode($snap, JSON_UNESCAPED_UNICODE),
            'jalali_date'=>$this->today_jalali(), 'gregorian_date'=>date('Y-m-d'), 'created_at'=>$now,
        ]);
        $this->redirect_settings_archive();
    }
    public function delete_archive() {
        if ( ! $this->is_authorized_user() ) wp_die('دسترسی غیرمجاز.', '', ['response'=>403]);
        check_admin_referer(self::NONCE, 'hpa_nonce');
        global $wpdb; $wpdb->delete($this->tables['archives'], ['id'=>$this->id('id')]);
        $this->redirect_settings_archive();
    }
    public function archive_report() {
        if ( ! $this->is_authorized_user() ) wp_die('دسترسی غیرمجاز.', '', ['response'=>403]);
        check_admin_referer(self::NONCE, 'hpa_nonce');
        global $wpdb;
        $a=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['archives']} WHERE id=%d", $this->id('id')), ARRAY_A);
        nocache_headers(); header('Content-Type: text/html; charset=utf-8');
        echo $this->render_archive_report_html($a);
        exit;
    }
    private function render_archive_report_html($a) {
        $css = esc_url(plugin_dir_url(__FILE__).'assets/css/hpa.css');
        $styles = '<style>@page{size:A4;margin:14mm}html,body{margin:0}body{font-family:HPAIranSans,Tahoma,sans-serif;direction:rtl;color:#0f172a;padding:16px;background:#fff}h1{font-size:20px;margin:0 0 4px}h2{font-size:15px;margin:18px 0 8px;border-bottom:1px solid #e2e8f0;padding-bottom:5px}p{margin:4px 0;color:#334155}table.rep{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px}table.rep th,table.rep td{border:1px solid #e2e8f0;padding:6px 8px;text-align:right}table.rep th{background:#f1f5f9}.noprint{margin:0 0 14px}.noprint button{padding:9px 16px;border:0;border-radius:10px;background:#4f46e5;color:#fff;font-weight:700;cursor:pointer}@media print{.noprint{display:none!important}}</style>';
        $head = '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><link rel="stylesheet" href="'.$css.'">'.$styles.'<title>گزارش بایگانی</title></head><body>';
        $foot = '<script>window.addEventListener("load",function(){setTimeout(function(){try{window.print();}catch(e){}},450);});</script></body></html>';
        if (!$a) return $head.'<p>بایگانی یافت نشد.</p>'.$foot;
        $summary=json_decode($a['summary'] ?? '{}', true) ?: []; $data=json_decode($a['data'] ?? '{}', true) ?: []; $scope=json_decode($a['scope'] ?? '[]', true) ?: [];
        $types=$this->transaction_types(); $T=$this->tables;
        $body = '<div class="noprint"><button onclick="window.print()">چاپ / ذخیره PDF</button></div>';
        $body .= '<h1>گزارش بایگانی: '.esc_html($a['title']).'</h1>';
        $body .= '<p>تاریخ بایگانی: '.esc_html($a['jalali_date']).' — حساب‌یار</p>';
        $body .= '<p>بخش‌های بایگانی‌شده: '.esc_html(implode('، ',$scope)).'</p>';
        $body .= '<h2>خلاصه</h2><table class="rep"><thead><tr><th>بخش</th><th>تعداد</th><th>جمع (تومان)</th></tr></thead><tbody>';
        foreach($summary as $k=>$s){ $body .= '<tr><td>'.esc_html($s['label'] ?? $k).'</td><td>'.esc_html(number_format_i18n((int)($s['count'] ?? 0))).'</td><td>'.esc_html($this->fmt_money($s['total'] ?? 0,'toman')).'</td></tr>'; }
        $body .= '</tbody></table>';
        $tbl=function($rows,$heads,$cb){ $h='<table class="rep"><thead><tr>'; foreach($heads as $x) $h.='<th>'.esc_html($x).'</th>'; $h.='</tr></thead><tbody>'; foreach($rows as $r){ $h.='<tr>'; foreach($cb($r) as $c) $h.='<td>'.$c.'</td>'; $h.='</tr>'; } return $h.'</tbody></table>'; };
        if (!empty($data[$T['transactions']])) $body .= '<h2>تراکنش‌ها ('.number_format_i18n(count($data[$T['transactions']])).')</h2>'.$tbl($data[$T['transactions']],['تاریخ','نوع','مبلغ','توضیح'],function($r){ return [esc_html($r['jalali_date']),esc_html($this->transaction_types()[$r['type']] ?? $r['type']),esc_html($this->fmt_money($r['amount'],$r['currency'])),esc_html(wp_trim_words($r['description'] ?? '',12))]; });
        if (!empty($data[$T['accounts']])) $body .= '<h2>حساب‌ها ('.number_format_i18n(count($data[$T['accounts']])).')</h2>'.$tbl($data[$T['accounts']],['نام','ارز','موجودی اولیه'],function($r){ return [esc_html($r['name']),esc_html($this->currencies()[$r['currency']] ?? $r['currency']),esc_html($this->fmt_money($r['opening_balance'],$r['currency']))]; });
        if (!empty($data[$T['assets']])) $body .= '<h2>دارایی‌ها ('.number_format_i18n(count($data[$T['assets']])).')</h2>'.$tbl($data[$T['assets']],['عنوان','گروه','قیمت خرید'],function($r){ return [esc_html($r['title']),esc_html($this->asset_groups()[$r['asset_group']] ?? $r['asset_group']),esc_html($this->fmt_money($r['purchase_price'],$r['currency']))]; });
        if (!empty($data[$T['debts']])) $body .= '<h2>قرض‌ها ('.number_format_i18n(count($data[$T['debts']])).')</h2>'.$tbl($data[$T['debts']],['شخص','مبلغ','تاریخ'],function($r){ return [esc_html($r['person_name']),esc_html($this->fmt_money($r['amount'],$r['currency'])),esc_html($r['jalali_date'])]; });
        if (!empty($data[$T['loans']])) $body .= '<h2>وام‌ها ('.number_format_i18n(count($data[$T['loans']])).')</h2>'.$tbl($data[$T['loans']],['عنوان','وام‌دهنده','اصل وام'],function($r){ return [esc_html($r['title']),esc_html($r['lender'] ?: '—'),esc_html($this->fmt_money($r['principal_amount'],$r['currency']))]; });
        if (!empty($data[$T['checks']])) $body .= '<h2>چک‌ها ('.number_format_i18n(count($data[$T['checks']])).')</h2>'.$tbl($data[$T['checks']],['عنوان','تعداد','مبلغ هر چک'],function($r){ return [esc_html($r['title']),esc_html(number_format_i18n((int)$r['check_count'])),esc_html($this->fmt_money($r['amount_each'],$r['currency']))]; });
        if (!empty($data[$T['receivables']])) $body .= '<h2>طلب‌ها ('.number_format_i18n(count($data[$T['receivables']])).')</h2>'.$tbl($data[$T['receivables']],['شخص','مبلغ','تاریخ'],function($r){ return [esc_html($r['person_name']),esc_html($this->fmt_money($r['amount'],$r['currency'])),esc_html($r['jalali_date'])]; });
        return $head.$body.$foot;
    }
    private function admin_archive_panel() {
        global $wpdb; $groups=$this->archive_groups();
        echo '<h2>بایگانی و شروع دورهٔ جدید</h2><p>بخش‌های انتخابی را بایگانی کن: یک نسخهٔ کامل ذخیره و سپس داده‌ها و اعدادشان صفر می‌شوند. تعهدات باز (بدهی/وام/چک پرداخت‌نشده و طلب وصول‌نشده) پاک نمی‌شوند. بعداً می‌توانی از هر بایگانی خروجی PDF بگیری.</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="hpa_save_archive">';
        wp_nonce_field(self::NONCE,'hpa_nonce');
        echo '<p><label>عنوان بایگانی: <input type="text" name="archive_title" placeholder="مثلاً پایان سال ۱۴۰۴" style="min-width:280px"></label></p>';
        echo '<p><label><input type="checkbox" name="group_all" value="1"> <strong>همه‌چیز</strong></label></p><fieldset style="border:1px solid #ddd;padding:10px 14px;max-width:520px;border-radius:6px">';
        foreach($groups as $k=>$v) echo '<label style="display:block;margin:6px 0"><input type="checkbox" name="group_'.esc_attr($k).'" value="1"> '.esc_html($v).'</label>';
        echo '</fieldset><p><button class="button button-primary" onclick="return confirm(\'مطمئنی؟ داده‌های انتخاب‌شده صفر و پاک می‌شوند. یک نسخهٔ بایگانی برای PDF ذخیره می‌ماند. این کار قابل بازگشت خودکار نیست.\')">بایگانی و صفر کردن</button></p></form>';
        $rows=$wpdb->get_results("SELECT * FROM {$this->tables['archives']} ORDER BY id DESC LIMIT 200");
        echo '<h3>بایگانی‌های ثبت‌شده</h3><table class="widefat striped"><thead><tr><th>عنوان</th><th>تاریخ</th><th>بخش‌ها</th><th>عملیات</th></tr></thead><tbody>';
        if(!$rows) echo '<tr><td colspan="4">هنوز بایگانی‌ای ثبت نشده است.</td></tr>';
        foreach($rows as $r){ $scope=json_decode($r->scope,true) ?: []; $pdf=wp_nonce_url(admin_url('admin-post.php?action=hpa_archive_report&id='.(int)$r->id), self::NONCE,'hpa_nonce'); $del=wp_nonce_url(admin_url('admin-post.php?action=hpa_delete_archive&id='.(int)$r->id), self::NONCE,'hpa_nonce'); echo '<tr><td>'.esc_html($r->title).'</td><td>'.esc_html($r->jalali_date).'</td><td>'.esc_html(implode('، ',$scope)).'</td><td><a class="button" href="'.esc_url($pdf).'" target="_blank">دانلود PDF</a> <a class="button" onclick="return confirm(\'حذف این بایگانی؟\')" href="'.esc_url($del).'">حذف</a></td></tr>'; }
        echo '</tbody></table>';
    }


    public function maybe_hide_admin_bar($show) {
        if (!is_singular()) return $show;
        global $post;
        if (is_a($post, 'WP_Post') && strpos((string)$post->post_content, '[hamid_personal_accounting') !== false) return false;
        return $show;
    }

    private function pin_is_unlocked() {
        $settings = get_option(self::OPTION, []);
        $pin = (string)($settings['security_pin'] ?? '');
        if ($pin === '') return true;
        $cookie = isset($_COOKIE['hpa_pin_ok']) ? sanitize_text_field(wp_unslash($_COOKIE['hpa_pin_ok'])) : '';
        return $cookie && hash_equals(wp_hash($pin), $cookie);
    }

    private function maybe_process_pin() {
        if (!isset($_POST['hpa_pin_submit'])) return;
        if (!is_user_logged_in() || !current_user_can('administrator')) return;
        $settings = get_option(self::OPTION, []);
        $pin = (string)($settings['security_pin'] ?? '');
        $given = isset($_POST['hpa_pin']) ? sanitize_text_field(wp_unslash($_POST['hpa_pin'])) : '';
        if ($pin !== '' && hash_equals($pin, $given)) {
            if (!headers_sent()) setcookie('hpa_pin_ok', wp_hash($pin), time() + DAY_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
            $_COOKIE['hpa_pin_ok'] = wp_hash($pin);
        }
    }

    private function pin_gate_html() {
        if (!is_user_logged_in()) {
            ob_start();
            echo '<div class="hpa-login-gate hpa-auth-gate" dir="rtl"><div class="hpa-login-box hpa-auth-card"><div class="hpa-auth-logo">💳</div><h2>ورود به حسابدار شخصی</h2><p>برای ادامه، با حساب ادمین وارد شوید.</p>';
            wp_login_form(['redirect'=>esc_url(get_permalink()), 'label_username'=>'نام کاربری یا ایمیل', 'label_password'=>'رمز عبور', 'label_log_in'=>'ورود به افزونه', 'remember'=>false]);
            echo '</div></div>';
            return ob_get_clean();
        }
        if (!$this->is_authorized_user()) return '<div class="hpa-denied" dir="rtl"><p style="padding:20px;color:#dc2626;">دسترسی غیرمجاز.</p></div>';
        return '<div class="hpa-login-gate hpa-auth-gate" dir="rtl"><form method="post" class="hpa-login-box hpa-auth-card hpa-pin-card"><div class="hpa-auth-logo">🔐</div><h2>قفل امنیتی حسابداری</h2><p>PIN تنظیم‌شده در افزونه را وارد کن.</p><input type="password" name="hpa_pin" inputmode="numeric" autocomplete="off" data-form-type="other" data-lpignore="true" data-1p-ignore="1" placeholder="رمز ورود به صفحه حسابداری (نه رمز سایت)"><button class="hpa-btn hpa-btn-primary" name="hpa_pin_submit" value="1">ورود امن</button></form></div>';
    }

    public function shortcode() {
        $this->maybe_process_pin();
        if (!$this->user_can_access()) return $this->pin_gate_html();
        if (!$this->pin_is_unlocked()) return $this->pin_gate_html();
        // assets() از wp_enqueue_scripts hook لود می‌شوند — اینجا برای اطمینان
        if (!wp_style_is('hpa-css', 'enqueued')) {
            wp_enqueue_style('hpa-css', plugin_dir_url(__FILE__) . 'assets/css/hpa.css', [], self::VERSION);
        }
        if (!wp_script_is('hpa-js', 'enqueued')) {
            wp_enqueue_script('hpa-js', plugin_dir_url(__FILE__) . 'assets/js/hpa.js', [], self::VERSION, true);
        }
        $settings = get_option(self::OPTION, []); $mode = $settings['theme_mode'] ?? 'light';
        $tab = isset($_GET['hpa_tab']) ? sanitize_key($_GET['hpa_tab']) : 'dashboard';
        ob_start();
        echo '<div class="hpa-app hpa-mode-'.esc_attr($mode).'" dir="rtl">';
        $this->topbar($tab);
        echo '<main class="hpa-main">';
        $this->tab_header($tab);
        if ($tab === 'accounts') $this->view_accounts();
        elseif ($tab === 'categories') $this->view_categories();
        elseif ($tab === 'transactions') $this->view_transactions();
        elseif ($tab === 'debt') $this->view_debts_full();
        elseif ($tab === 'receivable') $this->view_debt_like('receivables','receivable','طلب‌ها','hpa_save_receivable','بدهکار');
        elseif ($tab === 'assets') $this->view_assets();
        elseif ($tab === 'reports') $this->view_reports();
        elseif ($tab === 'rates') $this->view_rates();
        else $this->view_dashboard();
        echo '</main></div>';
        return ob_get_clean();
    }

    private function tab_header($active) {
        $data = [
            'accounts'=>['حساب‌ها','دفترها، مانده‌ها، تطبیق حساب و صورت‌حساب‌ها','💳'],
            'transactions'=>['تراکنش‌ها','ثبت، فیلتر، بررسی و مدیریت جریان پول','↔️'],
            'categories'=>['موضوعات','دسته‌بندی درآمد و هزینه با رنگ و آیکن','🏷️'],
            'debt'=>['بدهی و تعهدات','وام، اقساط، چک، بدهی‌های تکرارشونده و تعهدات آینده','📉'],
            'receivable'=>['طلب‌ها','مدیریت طلب‌ها، وصول کامل و وصول جزئی','📈'],
            'assets'=>['دارایی‌ها','دارایی، هدف مالی، ارزش فعلی و سود/زیان','💰'],
            'reports'=>['گزارش‌ها','تحلیل مالی، نمودارها و گزارش‌های تصمیم‌ساز','📊'],
            'rates'=>['تنظیمات','نرخ‌ها، موضوعات مخفی موبایل و ابزارهای مدیریتی','⚙️'],
        ];
        if ($active === 'dashboard' || empty($data[$active])) return;
        $d = $data[$active];
        echo '<section class="hpa-tab-identity"><span>'.esc_html($d[2]).'</span><div><h1>'.esc_html($d[0]).'</h1><p>'.esc_html($d[1]).'</p></div></section>';
    }

    private function topbar($active) {
        $tabs = ['dashboard'=>'داشبورد','accounts'=>'حساب‌ها','transactions'=>'تراکنش‌ها','categories'=>'موضوعات','debt'=>'بدهی','receivable'=>'طلب','assets'=>'دارایی‌ها','reports'=>'گزارش‌ها','rates'=>'نرخ‌ها'];
        $tab_icons = ['dashboard'=>'🏠','accounts'=>'💳','transactions'=>'↔️','categories'=>'🏷️','debt'=>'📉','receivable'=>'📈','assets'=>'💰','reports'=>'📊','rates'=>'⚙️'];
        echo '<header class="hpa-top"><div><strong>حساب‌یار</strong><span>نرم‌افزار حسابداری شخصی</span></div><nav class="hpa-desktop-nav">';
        foreach($tabs as $k=>$v) echo '<a class="'.($active===$k?'is-active':'').'" href="'.esc_url(add_query_arg('hpa_tab',$k, remove_query_arg('hpa_msg'))).'"><span class="hpa-nav-ico">'.esc_html($tab_icons[$k]).'</span><span>'.esc_html($v).'</span></a>';
        echo '</nav></header><nav class="hpa-mobile-nav" aria-label="منوی حسابداری شخصی">';
        $mobile_tabs = ['dashboard'=>$tabs['dashboard'], 'transactions'=>$tabs['transactions'], 'assets'=>$tabs['assets'], 'reports'=>$tabs['reports'], 'rates'=>'تنظیمات'];
        foreach($mobile_tabs as $k=>$v) echo '<a class="'.($active===$k?'is-active':'').'" href="'.esc_url(add_query_arg('hpa_tab',$k, remove_query_arg('hpa_msg'))).'"><span class="hpa-nav-ico">'.esc_html($tab_icons[$k]).'</span><span>'.esc_html($v).'</span></a>';
        echo '</nav>';
        if (isset($_GET['hpa_msg'])) echo '<div class="hpa-toast">اطلاعات با موفقیت ذخیره شد.</div>';
    }

    private function form_open($action, $multipart=false) {
        echo '<form class="hpa-form" method="post" '.($multipart?'enctype="multipart/form-data"':'').' action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="'.esc_attr($action).'">';
        wp_nonce_field(self::NONCE,'hpa_nonce');
    }
    private function form_close($label='ذخیره') { echo '<button class="hpa-btn hpa-btn-primary" type="submit">'.esc_html($label).'</button></form>'; }

    private function get_accounts() {
        static $accounts_cache = null;
        if ($accounts_cache !== null) return $accounts_cache;
        global $wpdb;
        $accounts_cache = $wpdb->get_results("SELECT * FROM {$this->tables['accounts']} WHERE is_active=1 ORDER BY id DESC");
        return $accounts_cache;
    }

    private function account_select($name='account_id', $selected=0) {
        $out='<select name="'.esc_attr($name).'"><option value="0">انتخاب حساب</option>';
        foreach($this->get_accounts() as $a) $out.='<option value="'.esc_attr($a->id).'" '.selected((int)$selected,(int)$a->id,false).'>'.esc_html($this->account_icon_text($a->icon).' '.$a->name.' — '.$this->person_label($a->person_key ?? 'hamidreza')).'</option>';
        return $out.'</select>';
    }
    private function category_select($name='category_id', $type='expense', $selected=0) {
        global $wpdb;
        $type=sanitize_key($type ?: 'expense');
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['categories']} WHERE type=%s ORDER BY is_default DESC, name ASC", $type));
        if(!$rows) $rows=$wpdb->get_results("SELECT * FROM {$this->tables['categories']} ORDER BY is_default DESC, name ASC");
        $out='<select name="'.esc_attr($name).'"><option value="0">بدون دسته‌بندی</option>';
        foreach($rows as $c) $out.='<option value="'.esc_attr($c->id).'" '.selected((int)$selected,(int)$c->id,false).'>'.esc_html(($c->icon ?: '🏷️').' '.$c->name).'</option>';
        return $out.'</select>';
    }
    private function clickable_category($id, $name, $icon='🏷️') {
        if (!$id) return esc_html($name);
        return '<a class="hpa-tax-link" href="'.esc_url(add_query_arg(['hpa_tab'=>'transactions','hpa_category'=>$id], remove_query_arg(['paged'])) . '#hpa-transactions-list').'">'.esc_html(trim($icon.' '.$name)).'</a>';
    }
    private function clickable_tag($tag) {
        $tag = trim((string)$tag); if ($tag==='') return '';
        return '<a class="hpa-tag-link" href="'.esc_url(add_query_arg(['hpa_tab'=>'transactions','hpa_tag'=>$tag], remove_query_arg(['paged'])) . '#hpa-transactions-list').'">#'.esc_html($tag).'</a>';
    }
    private function get_categories($type=null) { global $wpdb; $where = $type ? $wpdb->prepare('WHERE type=%s', $type) : ''; return $wpdb->get_results("SELECT * FROM {$this->tables['categories']} $where ORDER BY is_default DESC, id DESC"); }

    private function view_dashboard() {
        global $wpdb;
        $accounts = $this->get_accounts();
        $balances = $this->calculate_balances();
        // داشبورد فقط پنج حساب با بیشترین موجودی معادل ریالی/تومانی را نمایش می‌دهد.
        usort($accounts, function($a, $b) use ($balances) {
            $a_total = $this->amount_to_toman($balances[$a->id] ?? 0, $a->currency);
            $b_total = $this->amount_to_toman($balances[$b->id] ?? 0, $b->currency);
            if ($a_total == $b_total) return (int)$b->id <=> (int)$a->id;
            return $b_total <=> $a_total;
        });
        $dashboard_accounts = array_slice($accounts, 0, 5);
        $asset_summary = $this->asset_summary_totals();
        $assets_total = $asset_summary['current'];
        $asset_profit = $asset_summary['profit'];
        $asset_profit_text = ($asset_profit >= 0 ? 'سود ' : 'زیان ') . $this->fmt_money(abs($asset_profit), 'toman');
        $asset_icon = $asset_profit >= 0 ? '<span class="hpa-trend-icon hpa-trend-up">↗</span>' : '<span class="hpa-trend-icon hpa-trend-down">↘</span>';
        $debts_total = $this->table_sum_toman('debts', 'amount', "status!='paid'") + $this->loan_remaining_total_toman() + $this->check_open_total_toman();
        $recv_total = $this->table_sum_toman('receivables', 'amount', "status!='paid'");
        $range = $this->current_jalali_month_gregorian_range();
        $income = $this->transaction_sum_toman('income', $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $expense = $this->transaction_sum_toman($this->expense_types(), $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $net_now = $this->total_balances_toman($balances) + $assets_total - $debts_total;
        $monthly_net = $income - $expense;
        $usd_rate = $this->latest_rate_price('usd');
        $gold18_rate = $this->latest_rate_price('gold18');
        $hero_class = $monthly_net >= 0 ? 'hpa-hero-positive' : 'hpa-hero-negative';
        $hero_label = $monthly_net >= 0 ? 'مازاد ماه جاری' : 'کسری ماه جاری';
        echo '<section class="hpa-hero-finance hpa-hero-finance-fixed '.esc_attr($hero_class).'"><div class="hpa-hero-copy"><span class="hpa-eyebrow">خلاصه مالی ماه جاری</span><h1>'.esc_html($hero_label).': <span class="hpa-hero-amount">'.$this->fmt_money_html(abs($monthly_net),'toman').'</span></h1></div><div class="hpa-hero-metrics hpa-hero-market-metrics"><div><small>دلار</small><b>'.esc_html($usd_rate ? $this->fmt_money($usd_rate,'toman') : 'ثبت نشده').'</b></div><div><small>طلای ۱۸ عیار</small><b>'.esc_html($gold18_rate ? $this->fmt_money($gold18_rate,'toman') : 'ثبت نشده').'</b></div></div></section>';
        echo '<section class="hpa-grid hpa-kpis">';
        $this->kpi('موجودی حساب‌ها', $this->fmt_money_html($this->total_balances_toman($balances),'toman'), '💶');
        $this->kpi_asset_current($this->fmt_money_html($assets_total,'toman'), $asset_profit, $asset_icon);
        $this->kpi('طلب‌های باز', $this->fmt_money_html($recv_total,'toman'), '🤝', 'hpa-mobile-hide');
        $this->kpi('بدهی‌های باز', $this->fmt_money_html($debts_total,'toman'), '⚠️', 'hpa-mobile-hide');
        $this->kpi('درآمد ماه', $this->fmt_money_html($income,'toman'), '📈');
        $this->kpi('هزینه ماه', $this->fmt_money_html($expense,'toman'), '📉');
        echo '</section>';
        $this->loan_due_reminders();
        $this->check_due_reminders();
        $this->recurring_due_reminders();
        echo '<section class="hpa-three hpa-dashboard-middle"><div class="hpa-card hpa-dashboard-expenses"><h3>ترکیب هزینه‌ها</h3>'.$this->expense_chart(false, true, true).'</div><div class="hpa-card hpa-dashboard-accounts"><h3>حساب‌ها</h3>';
        if (!$dashboard_accounts) echo '<p class="hpa-muted">هنوز حسابی ثبت نشده است.</p>'; else foreach($dashboard_accounts as $a) { $account_color = $a->color ?: '#eef2ff'; echo '<div class="hpa-list-row hpa-dashboard-account-row" style="background:'.esc_attr($account_color).'"><span class="hpa-badge">'.$this->account_icon_html($a->icon).'</span><b>'.esc_html($a->name).'</b><em>'.esc_html($this->fmt_money($balances[$a->id] ?? 0, $a->currency)).'</em></div>'; }
        echo '</div>'; $this->dashboard_future_obligations_preview(); echo '</section>';
        echo '<section class="hpa-card hpa-recent-card-section"><div class="hpa-section-head"><div><h3>آخرین تراکنش‌ها</h3><p class="hpa-muted">سه تراکنش آخر با جزئیات جمع‌شونده</p></div></div>'; $this->recent_transaction_cards(3); echo '<div class="hpa-more-under"><a class="hpa-btn hpa-btn-ghost hpa-more-btn" href="'.esc_url(add_query_arg('hpa_tab','transactions')).'">نمایش بیشتر</a></div></section>';
    }

    private function global_search_section() {
        global $wpdb;
        $q = sanitize_text_field(wp_unslash($_GET['hpa_global_q'] ?? ''));
        echo '<section class="hpa-card hpa-global-search"><form method="get" class="hpa-filter-bar"><input type="hidden" name="hpa_tab" value="dashboard"><input name="hpa_global_q" value="'.esc_attr($q).'" placeholder="جستجوی سریع در تراکنش، دارایی، بدهی، طلب، وام و چک..."><button class="hpa-btn hpa-btn-primary" type="submit">جستجو</button></form>';
        if ($q !== '') {
            $like = '%' . $wpdb->esc_like($q) . '%';
            echo '<div class="hpa-search-results">';
            $tx=$wpdb->get_results($wpdb->prepare("SELECT id,jalali_date,amount,currency,description,tags FROM {$this->tables['transactions']} WHERE description LIKE %s OR tags LIKE %s ORDER BY id DESC LIMIT 8", $like,$like));
            foreach($tx as $r) echo '<div class="hpa-list-row"><b>تراکنش <small>'.esc_html($r->jalali_date).' — '.esc_html(wp_trim_words($r->description,9)).'</small></b><em>'.esc_html($this->fmt_money($r->amount,$r->currency)).'</em></div>';
            $as=$wpdb->get_results($wpdb->prepare("SELECT title,jalali_date,purchase_price,currency FROM {$this->tables['assets']} WHERE title LIKE %s OR note LIKE %s OR purchase_place LIKE %s ORDER BY id DESC LIMIT 8", $like,$like,$like));
            foreach($as as $r) echo '<div class="hpa-list-row"><b>دارایی <small>'.esc_html($r->title).' — '.esc_html($r->jalali_date).'</small></b><em>'.esc_html($this->fmt_money($r->purchase_price,$r->currency)).'</em></div>';
            $lo=$wpdb->get_results($wpdb->prepare("SELECT title,principal_amount,currency,lender FROM {$this->tables['loans']} WHERE title LIKE %s OR lender LIKE %s OR used_for LIKE %s ORDER BY id DESC LIMIT 6", $like,$like,$like));
            foreach($lo as $r) echo '<div class="hpa-list-row"><b>وام <small>'.esc_html($r->title).' · '.esc_html($r->lender ?: '—').'</small></b><em>'.esc_html($this->fmt_money($r->principal_amount,$r->currency)).'</em></div>';
            if (!$tx && !$as && !$lo) echo '<p class="hpa-muted">نتیجه‌ای پیدا نشد.</p>';
            echo '</div>';
        }
        echo '</section>';
    }

    private function kpi($title,$value,$icon,$extra_class='') { echo '<article class="hpa-kpi '.esc_attr($extra_class).'"><span>'.$icon.'</span><small>'.esc_html($title).'</small><strong>'.$value.'</strong></article>'; }
    private function kpi_asset_current($value, $profit, $icon) {
        $class = $profit >= 0 ? 'hpa-profit-positive' : 'hpa-profit-negative';
        $label = ($profit >= 0 ? 'سود: ' : 'زیان: ') . $this->fmt_money(abs($profit), 'toman');
        echo '<article class="hpa-kpi hpa-kpi-asset-current"><span>'.$icon.'</span><small>ارزش فعلی دارایی‌ها</small><strong>'.$value.'</strong><em class="'.esc_attr($class).'">'.esc_html($label).'</em></article>';
    }
    private function transaction_flow_class($r) {
        $type = is_object($r) ? ($r->type ?? '') : '';
        if (in_array($type, ['income','asset_sell','receivable_settlement','debt_incur'], true)) return 'in';
        if ($type === 'person_transfer') {
            $to = is_object($r) ? ($r->to_person_key ?? '') : '';
            $from = is_object($r) ? ($r->from_person_key ?? '') : '';
            if ($to === 'hamidreza' || $to === 'joint') return 'in';
            if ($from === 'hamidreza' || $from === 'joint') return 'out';
            return 'neutral';
        }
        if ($type === 'transfer') return 'neutral';
        return 'out';
    }

    private function recent_transaction_cards($limit=3) {
        global $wpdb;
        $types=$this->transaction_types();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT t.*, a.name account_name, c.id cat_id, c.name cat_name, c.icon cat_icon, c.color cat_color FROM {$this->tables['transactions']} t LEFT JOIN {$this->tables['accounts']} a ON a.id=t.account_id LEFT JOIN {$this->tables['categories']} c ON c.id=t.category_id WHERE t.status!='cancelled' ORDER BY t.gregorian_date DESC, t.id DESC LIMIT %d", $limit));
        echo '<div class="hpa-recent-tx-cards">';
        if (!$rows) echo '<p class="hpa-muted">هنوز تراکنشی ثبت نشده است.</p>';
        foreach($rows as $r) {
            $flow=$this->transaction_flow_class($r);
            $money_class=$flow==='in'?'hpa-positive':($flow==='out'?'hpa-negative':'hpa-neutral');
            $flow_icon=$flow==='in'?'↗':($flow==='out'?'↘':'↔');
            $tags=''; foreach(array_filter(array_map('trim', explode(',', str_replace('#','',$r->tags ?? '')))) as $tag) $tags.=$this->clickable_tag($tag).' ';
            if (!$tags && !empty($r->tags)) foreach(array_filter(preg_split('/\s+/', str_replace('#','',$r->tags))) as $tag) $tags.=$this->clickable_tag($tag).' ';
            $edit_url = esc_url(add_query_arg(['hpa_tab'=>'transactions','hpa_edit_transaction'=>$r->id]));
            $bal_after = $this->account_balance_after_transaction($r);
            $balance_line = $bal_after ? '<p><strong>مانده حساب بعد از تراکنش:</strong> '.esc_html($this->fmt_money($bal_after['balance'],$bal_after['currency'])).'</p>' : '';
            $hpa_hide = !empty($r->hide_amount);
            $hpa_amt_html = $hpa_hide ? '<span class="hpa-amount-hidden" aria-hidden="true">***</span>' : '<b class="'.esc_attr($money_class).'">'.esc_html($this->fmt_money($r->amount,$r->currency)).'</b>';
            echo '<details class="hpa-recent-tx-card hpa-flow-'.esc_attr($flow).( $hpa_hide ? ' hpa-tx-hidden' : '' ).'"><summary><span class="hpa-flow-mark">'.esc_html($flow_icon).'</span><span class="hpa-recent-main">'.$hpa_amt_html.'<small>'.esc_html($r->jalali_date).' · '.esc_html($types[$r->type]??$r->type).'</small></span><span class="hpa-recent-cat" style="background:'.esc_attr($r->cat_color ?: '#eef2ff').'">'.$this->clickable_category((int)$r->cat_id, $r->cat_name?:'بدون موضوع', $r->cat_icon?:'📌').'</span></summary><div class="hpa-recent-details"><p><strong>حساب:</strong> '.esc_html($r->account_name ?: '—').'</p>'.$balance_line.'<p><strong>محل تراکنش:</strong> '.esc_html(($r->transaction_place ?? '') ?: '—').'</p><p><strong>توضیح:</strong> '.esc_html($r->description ?: '—').'</p><p><strong>برچسب‌ها:</strong> '.($tags ?: '<span class="hpa-muted">ندارد</span>').'</p><div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.$this->delete_button('hpa_delete_transaction',$r->id,'dashboard').'</div></div></details>';
        }
        echo '</div>';
    }



    private function get_or_create_debt_category() {
        global $wpdb;
        $name = 'قرض/وام دریافتی';
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['categories']} WHERE name=%s LIMIT 1", $name));
        if ($id) return $id;
        $wpdb->insert($this->tables['categories'], ['user_id'=>get_current_user_id(),'name'=>$name,'type'=>'income','icon'=>'🤝','color'=>'#E0F2FE','is_default'=>0,'is_essential'=>0,'created_at'=>current_time('mysql')]);
        return (int)$wpdb->insert_id;
    }
    // تراکنش خودکار «دریافت قرض/وام»: موجودی حساب را زیاد می‌کند اما درآمد نیست.
    private function sync_incur_transaction($link_field, $link_id, $opts) {
        global $wpdb;
        $link_field = in_array($link_field, ['debt_id','source_loan_id'], true) ? $link_field : 'debt_id';
        $link_id = absint($link_id);
        $account_id = absint($opts['account_id'] ?? 0);
        $amount = (float)($opts['amount'] ?? 0);
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE type='debt_incur' AND {$link_field}=%d ORDER BY id DESC LIMIT 1", $link_id));
        if (!$account_id || $amount <= 0) { if ($existing) $wpdb->delete($this->tables['transactions'], ['id'=>(int)$existing->id]); return; }
        $cat = $this->get_or_create_debt_category();
        $jalali = ($opts['jalali_date'] ?? '') ?: $this->today_jalali();
        $person = $opts['person_key'] ?? 'hamidreza';
        $data = [
            'user_id'=>get_current_user_id(), 'person_key'=>$person, 'from_person_key'=>$person, 'to_person_key'=>$person,
            'account_id'=>$account_id, 'to_account_id'=>0, 'category_id'=>$cat, 'type'=>'debt_incur', 'amount'=>$amount, 'fee_amount'=>0, 'currency'=>($opts['currency'] ?? '') ?: 'toman',
            'jalali_date'=>$jalali, 'gregorian_date'=>$this->jalali_to_gregorian_date($jalali), 'description'=>($opts['description'] ?? '') ?: 'دریافت قرض/وام',
            'transaction_place'=>'', 'tags'=>'قرض', 'status'=>'done', 'updated_at'=>current_time('mysql'),
            $link_field=>$link_id,
        ];
        if ($existing) $wpdb->update($this->tables['transactions'], $data, ['id'=>(int)$existing->id]);
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['transactions'], $data); }
    }
    private function delete_incur_transaction($link_field, $link_id) {
        global $wpdb;
        $link_field = in_array($link_field, ['debt_id','source_loan_id'], true) ? $link_field : 'debt_id';
        $link_id = absint($link_id);
        $rows = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$this->tables['transactions']} WHERE type='debt_incur' AND {$link_field}=%d", $link_id));
        foreach ((array)$rows as $rid) $wpdb->delete($this->tables['transactions'], ['id'=>(int)$rid]);
    }

    private function get_or_create_reconciliation_category($type='expense') {
        global $wpdb;
        $type = $type === 'income' ? 'income' : 'expense';
        $name = 'متفرقه/تطابق';
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['categories']} WHERE name=%s AND type=%s LIMIT 1", $name, $type));
        if ($id) return $id;
        $wpdb->insert($this->tables['categories'], [
            'user_id'=>get_current_user_id(), 'name'=>$name, 'type'=>$type, 'icon'=>'⚖️', 'color'=>'#E0F2FE', 'is_default'=>0, 'is_essential'=>1, 'created_at'=>current_time('mysql')
        ]);
        return (int)$wpdb->insert_id;
    }

    public function reconcile_account() {
        $this->guard(); global $wpdb;
        $account_id = $this->id('account_id');
        $actual = $this->money('actual_balance');
        $account = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['accounts']} WHERE id=%d", $account_id));
        if (!$account) $this->redirect('accounts');
        $balances = $this->calculate_balances();
        $calc = (float)($balances[$account_id] ?? 0);
        $diff = $actual - $calc;
        if (abs($diff) > 0.0001) {
            $type = $diff >= 0 ? 'income' : 'expense';
            $cat = $this->get_or_create_reconciliation_category($type);
            $jalali = $this->today_jalali();
            $wpdb->insert($this->tables['transactions'], [
                'user_id'=>get_current_user_id(), 'person_key'=>$account->person_key ?: 'hamidreza', 'from_person_key'=>$account->person_key ?: 'hamidreza', 'to_person_key'=>$account->person_key ?: 'hamidreza',
                'account_id'=>$account_id, 'to_account_id'=>0, 'category_id'=>$cat, 'type'=>$type, 'amount'=>abs($diff), 'fee_amount'=>0, 'currency'=>$account->currency ?: 'toman',
                'jalali_date'=>$jalali, 'gregorian_date'=>$this->jalali_to_gregorian_date($jalali), 'description'=>'اصلاحیه خودکار تطبیق مانده حساب: '.($diff>=0?'افزایش':'کاهش').' مانده',
                'transaction_place'=>'تطبیق مانده', 'tags'=>'تطابق,اصلاحیه', 'status'=>'done', 'created_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql')
            ]);
        }
        $this->redirect('accounts');
    }

    private function get_goals($active_only=true) {
        global $wpdb;
        $where = $active_only ? "WHERE status='active'" : 'WHERE 1=1';
        return $wpdb->get_results("SELECT * FROM {$this->tables['goals']} $where ORDER BY id DESC");
    }
    private function goal_select($name='goal_id', $selected=0) {
        $out='<select name="'.esc_attr($name).'"><option value="0">بدون هدف مالی</option>';
        foreach($this->get_goals(false) as $g) $out.='<option value="'.esc_attr($g->id).'" '.selected((int)$selected,(int)$g->id,false).'>'.esc_html('🎯 '.$g->title).'</option>';
        return $out.'</select>';
    }
    public function save_goal() {
        $this->guard(); global $wpdb;
        $id=$this->id('id'); $jalali=$this->clean('target_jalali_date');
        $data=['user_id'=>get_current_user_id(), 'title'=>$this->clean('title'), 'target_amount'=>$this->money('target_amount'), 'currency'=>$this->clean('currency','toman'), 'target_jalali_date'=>$jalali, 'target_gregorian_date'=>$jalali?$this->jalali_to_gregorian_date($jalali):null, 'note'=>$this->textarea('note'), 'status'=>$this->clean('status','active'), 'updated_at'=>current_time('mysql')];
        if($id) $wpdb->update($this->tables['goals'],$data,['id'=>$id]); else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['goals'],$data); }
        $this->redirect('assets');
    }
    public function delete_goal() { $this->guard(); global $wpdb; $id=$this->id('id'); $this->archive_item_before_delete('goals',$id,'title'); $wpdb->delete($this->tables['goals'], ['id'=>$id]); $this->redirect('assets'); }

    private function view_goals() {
        global $wpdb; $curr=$this->currencies();
        echo '<section class="hpa-card hpa-goals-section"><h2>هدف‌های مالی دارایی‌ها</h2><p class="hpa-muted">برای خرید، پس‌انداز یا نگهداری دارایی‌ها هدف تعریف کن و دارایی‌ها را به آن وصل کن.</p>';
        $this->form_open('hpa_save_goal');
        echo '<div class="hpa-form-grid"><label>عنوان هدف<input name="title" required placeholder="مثلاً پس‌انداز طلا / سفر / صندوق اضطراری"></label><label>مبلغ هدف<input name="target_amount" inputmode="decimal"></label><label>واحد پول<select name="currency">';
        foreach($curr as $k=>$v) echo '<option value="'.esc_attr($k).'">'.esc_html($v).'</option>';
        echo '</select></label><label>تاریخ هدف<input name="target_jalali_date" class="hpa-jdate" placeholder="1404/12/29"></label><label>وضعیت<select name="status"><option value="active">فعال</option><option value="done">تکمیل‌شده</option></select></label><label class="hpa-col-full">یادداشت<textarea name="note"></textarea></label></div>';
        $this->form_close('ثبت هدف مالی');
        $goals=$this->get_goals(false);
        echo '<div class="hpa-goal-grid">';
        foreach($goals as $g){
            $asset_sum=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['assets']} WHERE goal_id=%d", $g->id));
            $cur=0; foreach($asset_sum as $a){ $cur += $this->asset_valuation($a)['current_total']; }
            $target=$this->amount_to_toman($g->target_amount,$g->currency); $pct=$target>0?min(100,round($cur*100/$target)):0;
            echo '<article class="hpa-goal-card"><strong>🎯 '.esc_html($g->title).'</strong><small>پیشرفت: '.esc_html($pct).'%</small><div class="hpa-progress"><span style="width:'.esc_attr($pct).'%"></span></div><em>'.esc_html($this->fmt_money($cur,'toman')).' از '.esc_html($this->fmt_money($target,'toman')).'</em>'.$this->delete_button('hpa_delete_goal',$g->id,'assets').'</article>';
        }
        if(!$goals) echo '<p class="hpa-muted">هنوز هدف مالی ثبت نشده است.</p>';
        echo '</div></section>';
    }

    private function view_accounts() {
        global $wpdb;
        $curr = $this->currencies(); $types=$this->account_types(); $balances=$this->calculate_balances();
        $edit_id = isset($_GET['hpa_edit_account']) ? absint($_GET['hpa_edit_account']) : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['accounts']} WHERE id=%d", $edit_id)) : null;
        $is_edit = (bool)$edit;
        // فرم — هنگام ویرایش باز، هنگام ثبت جدید بسته (collapsible)
        $form_open_attr = $is_edit ? ' open' : '';
        echo '<details class="hpa-card hpa-account-form-details'.($is_edit?' hpa-editing':'').'"'.$form_open_attr.'><summary class="hpa-account-form-summary">'.($is_edit?'✏️ ویرایش حساب':'➕ ثبت حساب جدید').'</summary>';
        $this->form_open('hpa_save_account');
        if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($edit->id).'">';
        $name = $is_edit ? $edit->name : ''; $person = $is_edit ? ($edit->person_key ?: 'hamidreza') : 'hamidreza'; $type = $is_edit ? $edit->type : 'cash'; $currency = $is_edit ? $edit->currency : 'toman';
        echo '<div class="hpa-form-grid"><label>نام حساب<input name="name" required placeholder="مثلاً کارت ملت / کیف پول" value="'.esc_attr($name).'"></label><label>شخص'.$this->person_select('person_key',$person).'</label><label>نوع حساب<select name="type">';
        foreach($types as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($type,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label><label>واحد پول<select name="currency">';
        foreach(['toman','rial','usd','eur'] as $k) echo '<option value="'.esc_attr($k).'" '.selected($currency,$k,false).'>'.esc_html($curr[$k]).'</option>';
        echo '</select></label><label>موجودی اولیه<input name="opening_balance" inputmode="decimal" value="'.esc_attr($is_edit?$edit->opening_balance:'').'"></label><label>نام بانک<input name="bank_name" value="'.esc_attr($is_edit?$edit->bank_name:'').'"></label><label>شماره حساب<input name="account_number" value="'.esc_attr($is_edit?$edit->account_number:'').'"></label><label>شماره کارت<input name="card_number" value="'.esc_attr($is_edit?$edit->card_number:'').'"></label><label>شبا<input name="iban" value="'.esc_attr($is_edit?$edit->iban:'').'"></label><label class="hpa-col-full hpa-icon-picker-field">نماد حساب (لوگوی بانک یا اموجی)'.$this->account_icon_picker($is_edit?($edit->icon?:'💳'):'💳').'</label><label>رنگ<input type="color" name="color" value="'.esc_attr($is_edit?($edit->color?:'#ede9fe'):'#ede9fe').'"></label><label>فعال باشد؟ <span class="hpa-checkline"><input type="checkbox" name="is_active" value="1" '.checked($is_edit?(int)$edit->is_active:1,1,false).'> بله</span></label><label class="hpa-col-full">توضیح<textarea name="note">'.esc_textarea($is_edit?$edit->note:'').'</textarea></label></div>';
        $this->form_close($is_edit?'ذخیره تغییرات حساب':'ثبت حساب');
        if ($is_edit) echo '<a class="hpa-btn hpa-btn-ghost hpa-cancel-edit" href="'.esc_url(remove_query_arg('hpa_edit_account')).'">انصراف از ویرایش</a>';
        echo '</details>';
        // کارت‌های حساب
        echo '<section class="hpa-card"><div class="hpa-section-head"><div><h2>حساب‌های من</h2></div></div>';
        $rows=$this->get_accounts();
        echo '<div class="hpa-account-card-grid">';
        if (!$rows) echo '<p class="hpa-muted">هنوز حسابی ثبت نشده است.</p>';
        foreach($rows as $r) {
            $edit_url = esc_url(add_query_arg(['hpa_tab'=>'accounts','hpa_edit_account'=>$r->id]));
            $bal = (float)($balances[$r->id] ?? $r->opening_balance);
            $bal_fmt = esc_html($this->fmt_money($bal, $r->currency));
            $bg = $r->color ?: '#ede9fe';
            echo '<details class="hpa-account-card"><summary style="background:'.esc_attr($bg).'">';
            echo '<span class="hpa-account-card-icon">'.$this->account_icon_html($r->icon).'</span>';
            echo '<div class="hpa-account-card-info"><strong>'.esc_html($r->name).'</strong><small>'.esc_html($this->account_type_label($r).' · '.$this->person_label($r->person_key ?? 'hamidreza')).'</small></div>';
            echo '<span class="hpa-account-card-balance">'.$bal_fmt.'</span></summary>';
            echo '<div class="hpa-account-card-body">';
            echo '<div class="hpa-account-card-details">';
            echo '<div><span>ارز</span><strong>'.esc_html($curr[$r->currency]??$r->currency).'</strong></div>';
            echo '<div><span>موجودی اولیه</span><strong>'.esc_html($this->fmt_money($r->opening_balance,$r->currency)).'</strong></div>';
            if ($r->bank_name) echo '<div><span>بانک</span><strong>'.esc_html($r->bank_name).'</strong></div>';
            if ($r->card_number) echo '<div><span>کارت</span><strong>'.esc_html($r->card_number).'</strong></div>';
            if ($r->iban) echo '<div><span>شبا</span><strong>'.esc_html($r->iban).'</strong></div>';
            echo '</div>';
            // فرم تطبیق
            echo '<div class="hpa-account-card-reconcile"><form class="hpa-inline-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="hpa_reconcile_account"><input type="hidden" name="account_id" value="'.esc_attr($r->id).'">'.wp_nonce_field(self::NONCE,'hpa_nonce',true,false).'<input name="actual_balance" inputmode="decimal" placeholder="موجودی واقعی برای تطبیق"><button class="hpa-btn hpa-btn-small" type="submit">⚖️ تطبیق</button></form></div>';
            echo '<div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.$this->delete_button('hpa_delete_account',$r->id,'accounts').'</div>';
            echo '</div></details>';
        }
        echo '</div></section>';
        $this->accounts_accounting_reports();
    }


    private function accounts_accounting_reports() {
        global $wpdb;
        $settings = get_option(self::OPTION, []);
        $show_inactive = !empty($settings['show_inactive_accounts']);
        $accounts = $show_inactive ? $wpdb->get_results("SELECT * FROM {$this->tables['accounts']} ORDER BY is_active DESC, id DESC") : $this->get_accounts();
        $range = $this->current_jalali_month_gregorian_range();
        echo '<section class="hpa-card hpa-accounting-books"><div class="hpa-section-head"><div><h2>دفترهای حسابداری شخصی</h2><p class="hpa-muted">دفتر روزنامه، دفتر کل و صورت‌حساب‌ها اینجا آمده‌اند تا داشبورد و تراکنش‌ها شلوغ نشوند.</p></div></div>';
        echo '<details class="hpa-book-block hpa-journal-collapsed" open><summary>دفتر روزنامه شخصی</summary>';
        // در موبایل کارت، در دسکتاپ جدول
        echo '<div class="hpa-journal-desktop"><div class="hpa-table-wrap"><table class="hpa-table"><thead><tr><th>تاریخ</th><th>رویداد</th><th>حساب/شخص</th><th>مبلغ</th><th>توضیح</th></tr></thead><tbody>';
        $journal=[];
        $tx=$wpdb->get_results("SELECT t.*, a.name account_name FROM {$this->tables['transactions']} t LEFT JOIN {$this->tables['accounts']} a ON a.id=t.account_id WHERE t.status!='cancelled' ORDER BY t.gregorian_date DESC, t.id DESC LIMIT 80");
        foreach($tx as $r) $journal[]=['g'=>$r->gregorian_date,'j'=>$r->jalali_date,'e'=>$this->transaction_types()[$r->type]??$r->type,'a'=>$r->account_name ?: $this->person_label($r->person_key),'m'=>$this->fmt_money($r->amount,$r->currency),'d'=>$r->description ?: $r->transaction_place];
        $as=$wpdb->get_results("SELECT * FROM {$this->tables['assets']} ORDER BY gregorian_date DESC, id DESC LIMIT 30");
        foreach($as as $r) $journal[]=['g'=>$r->gregorian_date,'j'=>$r->jalali_date,'e'=>'ثبت دارایی','a'=>$this->person_label($r->person_key),'m'=>$this->fmt_money($r->purchase_price,$r->currency),'d'=>$r->title];
        $db=$wpdb->get_results("SELECT * FROM {$this->tables['debts']} ORDER BY gregorian_date DESC, id DESC LIMIT 30");
        foreach($db as $r) $journal[]=['g'=>$r->gregorian_date,'j'=>$r->jalali_date,'e'=>'ثبت بدهی','a'=>$r->person_name,'m'=>$this->fmt_money($r->amount,$r->currency),'d'=>$r->note];
        usort($journal, function($a,$b){ return strcmp($b['g'], $a['g']); });
        $journal=array_slice($journal,0,80);
        if(!$journal) echo '<tr><td colspan="5" class="hpa-muted">رویدادی ثبت نشده است.</td></tr>';
        foreach($journal as $ji=>$r) echo '<tr'.($ji>=5?' class="hpa-journal-extra"':'').'><td>'.esc_html($r['j']).'</td><td>'.esc_html($r['e']).'</td><td>'.esc_html($r['a']).'</td><td>'.esc_html($r['m']).'</td><td>'.esc_html(wp_trim_words($r['d'],10)).'</td></tr>';
        echo '</tbody></table></div></div>';
        // نسخه موبایل — کارتی
        echo '<div class="hpa-journal-mobile">';
        if(!$journal) echo '<p class="hpa-muted">رویدادی ثبت نشده است.</p>';
        foreach($journal as $ji=>$r) echo '<div class="hpa-journal-mobile-card'.($ji>=5?' hpa-journal-extra':'').'"><div class="hpa-journal-mc-top"><span class="hpa-journal-mc-date">'.esc_html($r['j']).'</span><span class="hpa-journal-mc-type">'.esc_html($r['e']).'</span></div><div class="hpa-journal-mc-mid"><strong>'.esc_html($r['a']).'</strong><em>'.esc_html($r['m']).'</em></div>'.($r['d']?'<p class="hpa-journal-mc-desc">'.esc_html(wp_trim_words($r['d'],8)).'</p>':'').'</div>';
        echo '</div>'.(count($journal)>5?'<button type="button" class="hpa-btn hpa-journal-more">نمایش همهٔ رویدادها ('.esc_html(number_format_i18n(count($journal))).')</button>':'').'</details>';
        echo '<div class="hpa-two hpa-account-reports-grid"><section class="hpa-card hpa-subcard"><h3>دفتر کل حساب‌ها</h3>';
        foreach($accounts as $a){
            $bal=(float)$a->opening_balance;
            echo '<details class="hpa-ledger-card"><summary><b>'.$this->account_icon_html($a->icon,'hpa-bank-logo-sm').' '.esc_html($a->name).'</b><small>'.esc_html($a->is_active?'فعال':'بسته‌شده').'</small></summary><div class="hpa-ledger-lines">';
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE account_id=%d AND status!='cancelled' ORDER BY gregorian_date ASC, id ASC LIMIT 120", $a->id));
            if(!$rows) echo '<p class="hpa-muted">گردشی ثبت نشده است.</p>';
            foreach($rows as $r){ $bal=$this->apply_transaction_to_balance($bal,$a->currency,$r); echo '<div class="hpa-list-row"><b>'.esc_html($r->jalali_date.' · '.($this->transaction_types()[$r->type]??$r->type)).'</b><span>'.esc_html($this->fmt_money($r->amount,$r->currency)).'</span><em>مانده: '.esc_html($this->fmt_money($bal,$a->currency)).'</em></div>'; }
            echo '</div></details>';
        }
        echo '</section><section class="hpa-card hpa-subcard"><h3>صورت‌حساب ماهانه هر حساب</h3>';
        foreach($accounts as $a){
            $start_bal=(float)$a->opening_balance; $before=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE account_id=%d AND status!='cancelled' AND gregorian_date < %s ORDER BY gregorian_date ASC, id ASC", $a->id,$range[0])); foreach($before as $r) $start_bal=$this->apply_transaction_to_balance($start_bal,$a->currency,$r);
            $month=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE account_id=%d AND status!='cancelled' AND gregorian_date BETWEEN %s AND %s ORDER BY gregorian_date ASC,id ASC", $a->id,$range[0],$range[1]));
            $in=0; $out=0; $end=$start_bal; foreach($month as $r){ $old=$end; $end=$this->apply_transaction_to_balance($end,$a->currency,$r); $delta=$end-$old; if($delta>=0)$in+=$delta; else $out+=abs($delta); }
            echo '<div class="hpa-list-row"><b>'.esc_html($a->name).'<small>مانده اول/پایان ماه</small></b><span>'.esc_html($this->fmt_money($start_bal,$a->currency)).' → '.esc_html($this->fmt_money($end,$a->currency)).'</span><em>ورودی: '.esc_html($this->fmt_money($in,$a->currency)).'<br>خروجی: '.esc_html($this->fmt_money($out,$a->currency)).'</em></div>';
        }
        if ($show_inactive) {
            echo '</section></div><section class="hpa-card hpa-subcard"><h3>حساب‌های بسته‌شده</h3>';
            $closed=array_filter((array)$accounts, function($a){ return !(int)$a->is_active; });
            if(!$closed) echo '<p class="hpa-muted">حساب بسته‌شده‌ای وجود ندارد.</p>';
            foreach($closed as $a){
                $url=wp_nonce_url(admin_url('admin-post.php?action=hpa_reopen_account&id='.(int)$a->id), self::NONCE, 'hpa_nonce');
                echo '<div class="hpa-list-row"><b>'.$this->account_icon_html($a->icon,'hpa-bank-logo-sm').' '.esc_html($a->name).'</b><span>'.esc_html($this->person_label($a->person_key)).'</span><a class="hpa-btn hpa-btn-small hpa-btn-ghost" href="'.esc_url($url).'">فعال‌سازی دوباره</a></div>';
            }
            echo '</section></section>';
        } else {
            echo '</section></div></section>';
        }
    }

    private function view_categories() {
        global $wpdb;
        $edit_id = isset($_GET['hpa_edit_category']) ? absint($_GET['hpa_edit_category']) : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['categories']} WHERE id=%d", $edit_id)) : null;
        $is_edit = (bool)$edit;
        echo '<section class="hpa-card '.($is_edit?'hpa-editing':'').'"><h2>'.($is_edit?'ویرایش موضوع تراکنش':'افزودن موضوع تراکنش').'</h2>'; $this->form_open('hpa_save_category');
        if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($edit->id).'">';
        $name=$is_edit?$edit->name:''; $type=$is_edit?$edit->type:'expense'; $icon=$is_edit?($edit->icon?:'📌'):'📌'; $color=$is_edit?($edit->color?:'#e0e7ff'):'#e0e7ff'; $essential=$is_edit?((int)($edit->is_essential ?? 1)):1;
        echo '<div class="hpa-form-grid"><label>نام موضوع<input name="name" required placeholder="مثلاً خرید لوازم آموزشی" value="'.esc_attr($name).'"></label><label>نوع<select name="type"><option value="expense" '.selected($type,'expense',false).'>هزینه</option><option value="income" '.selected($type,'income',false).'>درآمد</option></select></label><label>آیکن/اموجی<input name="icon" value="'.esc_attr($icon).'"></label><label>رنگ فلت<input type="color" name="color" value="'.esc_attr($color).'"></label><label class="hpa-col-full"><span class="hpa-checkline"><input type="checkbox" name="is_essential" value="1" '.checked($essential,1,false).'> هزینه ضروری محسوب شود</span><small class="hpa-help">برای گزارش هزینه‌های ضروری/غیرضروری استفاده می‌شود.</small></label></div>';
        $this->form_close($is_edit?'ذخیره تغییرات موضوع':'ثبت موضوع');
        if ($is_edit) echo '<a class="hpa-btn hpa-btn-ghost hpa-cancel-edit" href="'.esc_url(remove_query_arg('hpa_edit_category')).'">انصراف از ویرایش</a>';
        echo '</section><section class="hpa-card"><h2>موضوعات</h2><div class="hpa-category-list">';
        $type_labels = ['expense'=>'هزینه','income'=>'درآمد'];
        foreach($this->get_categories() as $c) {
            $edit_url = esc_url(add_query_arg(['hpa_tab'=>'categories','hpa_edit_category'=>$c->id]));
            echo '<article class="hpa-category-item"><span class="hpa-category-icon" style="background:'.esc_attr($c->color).'">'.esc_html($c->icon ?: '📌').'</span><div class="hpa-category-text"><strong>'.esc_html($c->name).'</strong><small class="hpa-category-meta">'.esc_html($type_labels[$c->type] ?? $c->type).($c->is_default?' | پیش‌فرض':'').((int)($c->is_essential ?? 1)?' | ضروری':' | غیرضروری').'</small></div><div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.($c->is_default?'':$this->delete_button('hpa_delete_category',$c->id,'categories')).'</div></article>';
        }
        echo '</div></section>';
    }

    private function view_transactions() {
        global $wpdb;
        $accounts=$this->get_accounts(); $categories=$this->get_categories(); $curr=$this->currencies(); $types=$this->transaction_types();
        $edit_id = isset($_GET['hpa_edit_transaction']) ? absint($_GET['hpa_edit_transaction']) : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE id=%d", $edit_id)) : null;
        $is_edit = (bool)$edit;
        $dup = get_transient('hpa_duplicate_warning_'.get_current_user_id());
        if ($dup) { delete_transient('hpa_duplicate_warning_'.get_current_user_id()); echo '<section class="hpa-card hpa-alert hpa-alert-warning"><strong>هشدار تراکنش تکراری</strong><p>تراکنشی با همین مبلغ، حساب، تاریخ، نوع و توضیح قبلاً ثبت شده است. برای جلوگیری از ثبت اشتباه، عملیات ذخیره انجام نشد.</p><small>شناسه تراکنش مشابه: '.esc_html($dup).'</small></section>'; }
        echo '<section class="hpa-card hpa-transaction-form-card '.($is_edit?'hpa-editing':'hpa-creating').'"><h2>'.($is_edit?'ویرایش تراکنش':'ثبت تراکنش').'</h2>'; $this->form_open('hpa_save_transaction', true);
        if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($edit->id).'">';
        $pay_installment_id = isset($_GET['hpa_pay_loan']) ? absint($_GET['hpa_pay_loan']) : 0;
        $pay_installment = $pay_installment_id ? $this->get_installment($pay_installment_id) : null;
        $pay_check_id = isset($_GET['hpa_pay_check']) ? absint($_GET['hpa_pay_check']) : 0;
        $pay_check = $pay_check_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['checks']} WHERE id=%d", $pay_check_id)) : null;
        $etype=$is_edit?$edit->type:($pay_installment?'loan_installment':($pay_check?'check_settlement':'expense')); $eacc=$is_edit?(int)$edit->account_id:0; $eto=$is_edit?(int)$edit->to_account_id:0; $ecat=$is_edit?(int)$edit->category_id:0; $ecur=$is_edit?$edit->currency:($pay_installment?($pay_installment->currency?:'toman'):($pay_check?($pay_check->currency?:'toman'):'toman')); $estatus=$is_edit?$edit->status:'done'; $eperson=$is_edit?($edit->person_key?:'hamidreza'):'hamidreza';
        $efrom=$is_edit?($edit->from_person_key ?: $eperson):'hamidreza';
        $eto_person=$is_edit?($edit->to_person_key ?: 'samira'):'samira';
        $eloan=$is_edit?(int)($edit->source_loan_id ?? 0):($pay_installment?(int)$pay_installment->loan_id:0);
        $einstall=$is_edit?(int)($edit->loan_installment_id ?? 0):$pay_installment_id;
        $edebt=$is_edit?(int)($edit->debt_id ?? 0):0;
        $erecv=$is_edit?(int)($edit->receivable_id ?? 0):0;
        $echeck=$is_edit?(int)($edit->check_id ?? 0):($pay_check?(int)$pay_check->id:0);
        $easset=$is_edit?(int)($edit->asset_id ?? 0):0;
        $erecurring=$is_edit?(int)($edit->recurring_id ?? 0):0;
        $erecurring_due=$is_edit?($edit->recurring_due_jalali_date ?? ''):'';
        $loan_checked = ($eloan || $einstall || $etype === 'loan_installment');
        $preset_amount=$is_edit?$edit->amount:($pay_installment?(float)$pay_installment->amount:($pay_check?((float)$pay_check->amount_each * max(1,(int)$pay_check->check_count)):''));
        $preset_desc=$is_edit?$edit->description:($pay_installment?'پرداخت قسط وام '.$pay_installment->loan_title.' در تاریخ '.$pay_installment->due_jalali_date:($pay_check?'تسویه چک '.$pay_check->title.' در تاریخ '.$pay_check->first_due_jalali_date:''));
        echo '<div class="hpa-form-grid">';
        echo '<label class="hpa-person-normal-field">شخص'. $this->person_select('person_key',$eperson) .'</label>';
        echo '<label>نوع تراکنش<select name="type">'; foreach($types as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($etype,$k,false).'>'.esc_html($v).'</option>'; echo '</select></label>';
        echo '<label>حساب مرتبط<select name="account_id">'; foreach($accounts as $a) echo '<option value="'.esc_attr($a->id).'" '.selected($eacc,$a->id,false).'>'.esc_html($this->account_icon_text($a->icon).' '.$a->name).'</option>'; echo '</select></label>';
        echo '<label class="hpa-transfer-account-field">حساب مقصد در انتقال<select name="to_account_id"><option value="0">ندارد</option>'; foreach($accounts as $a) echo '<option value="'.esc_attr($a->id).'" '.selected($eto,$a->id,false).'>'.esc_html($this->account_icon_text($a->icon).' '.$a->name).'</option>'; echo '</select></label>';
        echo '<label class="hpa-person-transfer-field">مبدأ پول'.$this->person_select('from_person_key',$efrom).'</label>';
        echo '<label class="hpa-person-transfer-field">مقصد پول'.$this->person_select('to_person_key',$eto_person).'</label>';
        echo '<label class="hpa-category-field">موضوع<select name="category_id" class="hpa-category-by-type"><option value="0" data-cat-type="all">بدون موضوع</option>'; foreach($categories as $c) echo '<option data-cat-type="'.esc_attr($c->type).'" value="'.esc_attr($c->id).'" '.selected($ecat,$c->id,false).'>'.esc_html($c->icon.' '.$c->name).'</option>'; echo '</select></label>';
        echo '<label class="hpa-col-full hpa-split-toggle-field"><span class="hpa-checkline"><input type="checkbox" name="hpa_split_categories" value="1"> تقسیم مبلغ بین چند دسته‌بندی</span><small class="hpa-help">اگر فعال شود، مبلغ دسته‌های دوم و سوم هم باید وارد شوند؛ جمع آن‌ها باید با مبلغ کل تراکنش یکی باشد.</small></label>';
        echo '<label class="hpa-split-field">موضوع دوم<select name="split_category_id_2" class="hpa-category-by-type"><option value="0" data-cat-type="all">انتخاب موضوع دوم</option>'; foreach($categories as $c) echo '<option data-cat-type="'.esc_attr($c->type).'" value="'.esc_attr($c->id).'">'.esc_html($c->icon.' '.$c->name).'</option>'; echo '</select></label><label class="hpa-split-field">مبلغ موضوع دوم<input name="split_amount_2" inputmode="decimal"></label>';
        echo '<label class="hpa-split-field">موضوع سوم<select name="split_category_id_3" class="hpa-category-by-type"><option value="0" data-cat-type="all">انتخاب موضوع سوم</option>'; foreach($categories as $c) echo '<option data-cat-type="'.esc_attr($c->type).'" value="'.esc_attr($c->id).'">'.esc_html($c->icon.' '.$c->name).'</option>'; echo '</select></label><label class="hpa-split-field">مبلغ موضوع سوم<input name="split_amount_3" inputmode="decimal"></label>';
        echo '<label>مبلغ<input name="amount" required inputmode="decimal" value="'.esc_attr($preset_amount).'"></label><label class="hpa-transfer-fee-field">کارمزد انتقال<input name="fee_amount" inputmode="decimal" placeholder="اختیاری" value="'.esc_attr($is_edit?($edit->fee_amount ?? 0):'').'"><small class="hpa-help">در انتقال بین حساب‌ها یا اشخاص از حساب مبدأ کم می‌شود.</small></label>';
        echo '<label>واحد پول<select name="currency">'; foreach($curr as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($ecur,$k,false).'>'.esc_html($v).'</option>'; echo '</select></label>';
        echo '<label>تاریخ شمسی<input name="jalali_date" class="hpa-jdate" required value="'.esc_attr($is_edit?$edit->jalali_date:$this->today_jalali()).'" placeholder="1403/01/15"></label>';
        echo '<label>محل تراکنش<input name="transaction_place" placeholder="مثلاً افق کوروش" value="'.esc_attr($is_edit?($edit->transaction_place ?? ''):'').'"></label>';
        echo '<label>وضعیت<select name="status"><option value="done" '.selected($estatus,'done',false).'>انجام‌شده</option><option value="open" '.selected($estatus,'open',false).'>باز</option><option value="cancelled" '.selected($estatus,'cancelled',false).'>لغوشده</option></select></label>';
        $ehide = $is_edit ? (int)( isset($edit->hide_amount) ? $edit->hide_amount : 0 ) : 0;
        echo '<label class="hpa-col-full"><span class="hpa-checkline"><input type="checkbox" name="hide_amount" value="1" '.checked($ehide, 1, false).'> <strong>🔒 پنهان‌کردن مبلغ</strong></span><small class="hpa-help">اگر فعال شود، مبلغ این تراکنش در داشبورد و لیست تراکنش‌ها پنهان می‌شود. مبلغ واقعی در دفتر حساب و تمام محاسبات دست‌نخورده باقی می‌ماند.</small></label>';
        echo '<label class="hpa-col-full hpa-loan-toggle-field"><span class="hpa-checkline"><input type="checkbox" name="hpa_is_loan_related" value="1" '.checked($loan_checked, true, false).'> تراکنش وام/قسط است یا از محل وام انجام شده؟</span></label>';
        echo '<label class="hpa-loan-related-field hpa-source-loan-field">وام مرتبط'.$this->loan_select('source_loan_id',$eloan).'<small class="hpa-help">اگر تراکنش یا خرید از محل وام خاصی بوده، اینجا مشخص کن؛ اصل وام به‌عنوان درآمد/دارایی حساب نمی‌شود.</small></label>';
        echo '<label class="hpa-loan-related-field hpa-installment-field">قسط مرتبط'.$this->installment_select('loan_installment_id',$eloan,$einstall).'</label>';
        echo '<label class="hpa-debt-settlement-field">بدهی مرتبط'.$this->debt_select('debt_id',$edebt).'<small class="hpa-help">با ثبت مبلغ تراکنش، بدهی کامل یا بخشی تسویه می‌شود.</small></label>';
        echo '<label class="hpa-receivable-settlement-field">طلب مرتبط'.$this->receivable_select('receivable_id',$erecv).'<small class="hpa-help">با ثبت مبلغ تراکنش، طلب کامل یا بخشی وصول می‌شود.</small></label>';
        echo '<label class="hpa-check-settlement-field">چک مرتبط'.$this->check_select('check_id',$echeck).'<small class="hpa-help">با ثبت تراکنش تسویه چک، وضعیت چک پرداخت‌شده می‌شود و دیگر در چک‌های آینده نمایش داده نمی‌شود.</small></label>';
        echo '<label class="hpa-asset-link-field">دارایی مرتبط'.$this->asset_select('asset_id',$easset).'<small class="hpa-help">برای خرید/فروش دارایی، تراکنش را به دارایی ثبت‌شده وصل کن.</small></label><label class="hpa-asset-sell-field">مقدار فروخته‌شده<input name="asset_quantity" inputmode="decimal" value="'.esc_attr($is_edit?($edit->asset_quantity ?? ''):'').'"><small class="hpa-help">برای فروش جزئی دارایی و گزارش سود/زیان تحقق‌یافته.</small></label>'; 
        echo '<input type="hidden" name="recurring_due_recurring_id" value="'.esc_attr($erecurring).'">';
        echo '<label class="hpa-recurring-debt-field">بدهی تکرارشونده'.$this->recurring_select('recurring_id',$erecurring).'</label>';
        echo '<label class="hpa-recurring-debt-field">تاریخ سررسید بدهی تکرارشونده'.$this->recurring_due_select('recurring_due_jalali_date',$erecurring,$erecurring_due).'<small class="hpa-help">اگر زودتر پرداخت می‌کنی، باز هم تاریخ سررسید همان بدهی را انتخاب کن.</small></label>';
        $existing_items = ($is_edit) ? $wpdb->get_results($wpdb->prepare("SELECT name, amount FROM {$this->tables['transaction_items']} WHERE transaction_id=%d ORDER BY id", (int)$edit->id)) : [];
        $items_json = wp_json_encode(array_map(function($r){ return ['name'=>$r->name, 'amount'=>(float)$r->amount]; }, (array)$existing_items), JSON_UNESCAPED_UNICODE);
        echo '<label class="hpa-tags-field">برچسب‌ها<input class="hpa-tags-input" name="tags" placeholder="برچسب را بنویس و Enter بزن" value="'.esc_attr($is_edit?$edit->tags:'').'"><small class="hpa-help">با هر Enter یک برچسب اضافه می‌شود؛ می‌توانی چند برچسب ثبت کنی.</small></label>';
        echo '<label class="hpa-col-full hpa-items-field">اقلام خرید (اختیاری)<div class="hpa-items-editor" data-items="'.esc_attr($items_json).'"></div><input type="hidden" name="hpa_items" value="'.esc_attr($items_json).'"><small class="hpa-help">نام هر قلم و قیمتش را وارد کن و Enter بزن. مستقل از مبلغ کل است و در گزارش «خرج به تفکیک قلم» جمع می‌شود.</small></label>';
        echo '<label>رسید خرید/پرداخت<input type="file" name="receipt[]" accept="image/*,application/pdf" multiple></label>';
        echo '<label class="hpa-col-full">توضیح<textarea name="description">'.esc_textarea($preset_desc).' </textarea></label></div>';
        $this->form_close($is_edit?'ذخیره تغییرات تراکنش':'ثبت تراکنش');
        if ($is_edit) echo '<a class="hpa-btn hpa-btn-ghost hpa-cancel-edit" href="'.esc_url(remove_query_arg('hpa_edit_transaction')).'">انصراف از ویرایش</a>';
        echo '</section><section class="hpa-card"><h2>تراکنش‌ها</h2>'; $this->transactions_filter_ui(); $this->transactions_table(50); echo '</section>';
    }

    private function transactions_filter_ui() {
        global $wpdb;
        $q = sanitize_text_field(wp_unslash($_GET['hpa_q'] ?? ''));
        $tag = sanitize_text_field(wp_unslash($_GET['hpa_tag'] ?? ''));
        $cat = absint($_GET['hpa_category'] ?? 0);
        echo '<form class="hpa-filter-bar" method="get"><input type="hidden" name="hpa_tab" value="transactions">';
        echo '<input name="hpa_q" value="'.esc_attr($q).'" placeholder="جستجوی توضیح، برچسب، مبلغ...">';
        echo '<select name="hpa_category"><option value="0">همه دسته‌ها</option>';
        foreach($this->get_categories() as $c) echo '<option value="'.esc_attr($c->id).'" '.selected($cat,(int)$c->id,false).'>'.esc_html(($c->icon ?: '🏷️').' '.$c->name).'</option>';
        echo '</select>';
        echo '<input name="hpa_tag" value="'.esc_attr($tag).'" placeholder="برچسب">';
        echo '<input name="hpa_from" class="hpa-jdate" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET['hpa_from'] ?? ''))).'" placeholder="از تاریخ">';
        echo '<input name="hpa_to" class="hpa-jdate" value="'.esc_attr(sanitize_text_field(wp_unslash($_GET['hpa_to'] ?? ''))).'" placeholder="تا تاریخ">';
        echo '<button class="hpa-btn hpa-btn-primary" type="submit">فیلتر</button><a class="hpa-btn hpa-btn-ghost" href="'.esc_url(add_query_arg('hpa_tab','transactions', remove_query_arg(['hpa_q','hpa_tag','hpa_category','hpa_from','hpa_to']))).'">پاک‌کردن</a></form>';
    }

    private function transactions_table($limit=20) {
        global $wpdb; $limit=absint($limit);
        $where = ["1=1"];
        $params = [];
        if (!empty($_GET['hpa_category'])) { $where[] = 't.category_id=%d'; $params[] = absint($_GET['hpa_category']); }
        if (!empty($_GET['hpa_tag'])) { $where[] = 't.tags LIKE %s'; $params[] = '%' . $wpdb->esc_like(sanitize_text_field(wp_unslash($_GET['hpa_tag']))) . '%'; }
        if (!empty($_GET['hpa_q'])) { $q = '%' . $wpdb->esc_like(sanitize_text_field(wp_unslash($_GET['hpa_q']))) . '%'; $where[] = '(t.description LIKE %s OR t.tags LIKE %s OR CAST(t.amount AS CHAR) LIKE %s)'; array_push($params, $q, $q, $q); }
        if (!empty($_GET['hpa_from'])) { $g = $this->jalali_to_gregorian_date(sanitize_text_field(wp_unslash($_GET['hpa_from']))); if ($g) { $where[]='t.gregorian_date >= %s'; $params[]=$g; } }
        if (!empty($_GET['hpa_to'])) { $g = $this->jalali_to_gregorian_date(sanitize_text_field(wp_unslash($_GET['hpa_to']))); if ($g) { $where[]='t.gregorian_date <= %s'; $params[]=$g; } }
        $sql = "SELECT t.*, a.name account_name, c.id cat_id, c.name cat_name, c.icon cat_icon, c.color cat_color FROM {$this->tables['transactions']} t LEFT JOIN {$this->tables['accounts']} a ON a.id=t.account_id LEFT JOIN {$this->tables['categories']} c ON c.id=t.category_id WHERE ".implode(' AND ', $where)." ORDER BY t.gregorian_date DESC, t.id DESC LIMIT $limit";
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
        $types=$this->transaction_types();
        echo '<div id="hpa-transactions-list" class="hpa-transaction-card-list hpa-list-card-view">';
        if (!$rows) echo '<p class="hpa-muted">هنوز تراکنشی ثبت نشده است.</p>';
        foreach($rows as $r) {
            $flow=$this->transaction_flow_class($r);
            $money_class=$flow==='in'?'hpa-positive':($flow==='out'?'hpa-negative':'hpa-neutral');
            $flow_icon=$flow==='in'?'↗':($flow==='out'?'↘':'↔');
            $edit_url = esc_url(add_query_arg(['hpa_tab'=>'transactions','hpa_edit_transaction'=>$r->id]));
            $tags_html = '';
            foreach(array_filter(array_map('trim', explode(',', str_replace('#','',(string)$r->tags)))) as $tg) $tags_html .= $this->clickable_tag($tg).' ';
            if (!$tags_html && !empty($r->tags)) foreach(array_filter(preg_split('/\s+/', str_replace('#','',$r->tags))) as $tg) $tags_html .= $this->clickable_tag($tg).' ';
            $bal_after = $this->account_balance_after_transaction($r);
            $balance_line = $bal_after ? '<p><strong>مانده حساب بعد از تراکنش:</strong> '.esc_html($this->fmt_money($bal_after['balance'],$bal_after['currency'])).'</p>' : '';
            $hpa_hide2 = !empty($r->hide_amount);
            $hpa_amt_html2 = $hpa_hide2 ? '<span class="hpa-amount-hidden" aria-hidden="true">***</span>' : '<b class="'.esc_attr($money_class).'">'.esc_html($this->fmt_money($r->amount,$r->currency)).'</b>';
            echo '<details class="hpa-recent-tx-card hpa-tx-list-card hpa-flow-'.esc_attr($flow).( $hpa_hide2 ? ' hpa-tx-hidden' : '' ).'"><summary><span class="hpa-flow-mark">'.esc_html($flow_icon).'</span><span class="hpa-recent-main">'.$hpa_amt_html2.'<small>'.esc_html($r->jalali_date).' · '.esc_html($types[$r->type]??$r->type).' · '.esc_html($this->person_label($r->person_key ?? 'hamidreza')).'</small></span><span class="hpa-recent-cat" style="background:'.esc_attr($r->cat_color ?: '#eef2ff').'">'.$this->clickable_category((int)$r->cat_id, $r->cat_name?:'بدون موضوع', $r->cat_icon?:'📌').'</span></summary><div class="hpa-recent-details"><p><strong>حساب:</strong> '.esc_html($r->account_name ?: '—').'</p>'.$balance_line.'<p><strong>محل تراکنش:</strong> '.esc_html(($r->transaction_place ?? '') ?: '—').'</p><p><strong>توضیح:</strong> '.esc_html($r->description ?: '—').'</p><p><strong>برچسب‌ها:</strong> '.($tags_html ?: '<span class="hpa-muted">ندارد</span>').'</p><div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.$this->delete_button('hpa_delete_transaction',$r->id,'transactions').'</div></div></details>';
        }
        echo '</div>';
    }

    private function view_debt_like($table_key,$tab,$title,$action,$person_label) {
        global $wpdb;
        $curr=$this->currencies(); $statuses=$this->status_labels();
        $edit_key = $tab === 'debt' ? 'hpa_edit_debt' : 'hpa_edit_receivable';
        $edit_id = isset($_GET[$edit_key]) ? absint($_GET[$edit_key]) : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables[$table_key]} WHERE id=%d", $edit_id)) : null;
        $is_edit = (bool)$edit;
        echo '<section class="hpa-card '.($is_edit?'hpa-editing':'').'"><h2>'.($is_edit?'ویرایش '.$title:'ثبت '.$title).'</h2>'; $this->form_open($action, true);
        if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($edit->id).'">';
        $currency=$is_edit?($edit->currency?:'toman'):'toman'; $status=$is_edit?($edit->status?:'open'):'open';
        echo '<div class="hpa-form-grid">'
            .'<label>'.$person_label.'<input name="person_name" required value="'.esc_attr($is_edit?$edit->person_name:'').'"></label>'
            .'<label>شماره تماس<input name="phone" value="'.esc_attr($is_edit?$edit->phone:'').'"></label>'
            .'<label>مبلغ کل<input name="amount" required inputmode="decimal" value="'.esc_attr($is_edit?$edit->amount:'').'"></label>'
            .'<label>مبلغ پرداخت‌شده<input name="paid_amount" inputmode="decimal" value="'.esc_attr($is_edit?($edit->paid_amount ?? 0):0).'"><small class="hpa-help">برای پرداخت جزئی بدهی/طلب استفاده می‌شود.</small></label>'
            .($tab==='debt' ? '<label>واریز به حساب'.$this->account_select('account_id',$is_edit?(int)($edit->account_id ?? 0):0).'<small class="hpa-help">اگر حساب انتخاب شود، یک تراکنش «قرض» خودکار ثبت و موجودی حساب زیاد می‌شود؛ اما این مبلغ جزو درآمد ماه حساب نمی‌شود.</small></label>' : '')
            .'<label>واحد پول<select name="currency">';
        foreach($curr as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($currency,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label>'
            .'<label>تاریخ ثبت شمسی<input name="jalali_date" class="hpa-jdate" required value="'.esc_attr($is_edit?($edit->jalali_date?:$this->today_jalali()):$this->today_jalali()).'" placeholder="1403/01/15"></label>'
            .'<label>موعد پرداخت شمسی<input name="due_jalali_date" class="hpa-jdate" value="'.esc_attr($is_edit?$edit->due_jalali_date:'').'" placeholder="1403/02/15"></label>'
            .'<label>وضعیت<select name="status">';
        foreach($statuses as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($status,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label><label>رسید/سند<input type="file" name="receipt[]" accept="image/*,application/pdf" multiple></label>'
            .'<label class="hpa-col-full">توضیح<textarea name="note">'.esc_textarea($is_edit?$edit->note:'').'</textarea></label></div>';
        $this->form_close($is_edit?'ذخیره تغییرات':'ثبت');
        if ($is_edit) echo '<a class="hpa-btn hpa-btn-ghost hpa-cancel-edit" href="'.esc_url(remove_query_arg($edit_key)).'">انصراف از ویرایش</a>';
        echo '</section><section class="hpa-card"><h2>'.$title.'</h2>';
        $rows=$wpdb->get_results("SELECT * FROM {$this->tables[$table_key]} ORDER BY COALESCE(due_gregorian_date, gregorian_date) ASC, id DESC LIMIT 100");
        echo '<div class="hpa-table-wrap"><table class="hpa-table"><thead><tr><th>شخص</th><th>مبلغ کل</th><th>پرداخت‌شده</th><th>باقی‌مانده</th><th>تاریخ</th><th>موعد</th><th>وضعیت</th><th>توضیح</th><th>عملیات</th></tr></thead><tbody>';
        foreach($rows as $r) { $remaining=max(0,(float)$r->amount-(float)($r->paid_amount ?? 0)); $is_paid = ($r->status === 'paid' || $remaining <= 0.0001); $warn = ($r->due_gregorian_date && strtotime($r->due_gregorian_date) <= strtotime('+7 days') && !$is_paid) ? ' hpa-warn-row' : ''; $paid_class = $is_paid ? ' hpa-debt-paid-row' : ''; $edit_url=esc_url(add_query_arg(['hpa_tab'=>$tab,$edit_key=>$r->id])); echo '<tr class="'.esc_attr(trim($warn.$paid_class)).'"'.($is_paid?' data-paid="1"':'').'><td>'.esc_html($r->person_name).'</td><td>'.esc_html($this->fmt_money($r->amount,$r->currency)).'</td><td>'.esc_html($this->fmt_money($r->paid_amount ?? 0,$r->currency)).'</td><td>'.esc_html($this->fmt_money($remaining,$r->currency)).'</td><td>'.esc_html($r->jalali_date).'</td><td>'.esc_html($r->due_jalali_date).'</td><td>'.esc_html($statuses[$r->status]??$r->status).'</td><td>'.esc_html(wp_trim_words($r->note,10)).'</td><td><div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.$this->delete_button($tab==='debt'?'hpa_delete_debt':'hpa_delete_receivable',$r->id,$tab).'</div></td></tr>'; }
        if (!$rows) echo '<tr><td colspan="9" class="hpa-muted">موردی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div></section>';
    }

    private function asset_amount_label($asset) {
        $unit = trim((string)($asset->unit ?: ''));
        if (($asset->asset_group ?? '') === 'crypto' && $unit === '') $unit = strtoupper((string)($asset->model ?: 'واحد'));
        if (in_array($asset->asset_group, ['gold','silver'], true)) {
            $u = $unit ?: 'گرم';
            return trim(number_format_i18n((float)$asset->weight, 4) . ' ' . $u);
        }
        $base = ((float)$asset->quantity > 0) ? (float)$asset->quantity : (float)$asset->weight;
        return trim(number_format_i18n($base, 8) . ' ' . $unit);
    }

    private function asset_unit_price_label($asset) {
        $unit = trim((string)($asset->unit ?: ''));
        if (($asset->asset_group ?? '') === 'crypto' && $unit === '') $unit = strtoupper((string)($asset->model ?: 'واحد'));
        if (in_array($asset->asset_group, ['gold','silver'], true)) $unit = $unit ?: 'گرم';
        if ($unit === '') $unit = 'واحد';
        $base = in_array($asset->asset_group, ['gold','silver'], true) ? (float)$asset->weight : (((float)$asset->quantity > 0) ? (float)$asset->quantity : (float)$asset->weight);
        $price = isset($asset->unit_price) && (float)$asset->unit_price > 0 ? (float)$asset->unit_price : ($base > 0 ? ((float)$asset->purchase_price / $base) : 0);
        if ($price <= 0) return '—';
        return $this->fmt_money($price, $asset->currency) . ' / ' . $unit;
    }

    private function view_assets() {
        global $wpdb;
        $groups=$this->asset_groups(); $curr=$this->currencies();
        $edit_id = isset($_GET['hpa_edit_asset']) ? absint($_GET['hpa_edit_asset']) : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['assets']} WHERE id=%d", $edit_id)) : null;
        $is_edit=(bool)$edit;
echo '<section class="hpa-card hpa-assets-list-section"><h2>دارایی‌ها</h2>';
        $rows=$wpdb->get_results("SELECT * FROM {$this->tables['assets']} ORDER BY gregorian_date DESC, id DESC LIMIT 100");
        echo '<div class="hpa-asset-card-list hpa-list-card-view">';
        if (!$rows) echo '<p class="hpa-muted">دارایی ثبت نشده است.</p>';
        foreach($rows as $r) {
            $v=$this->asset_valuation($r);
            $edit_url=esc_url(add_query_arg(['hpa_tab'=>'assets','hpa_edit_asset'=>$r->id]));
            $group_label = $groups[$r->asset_group] ?? $r->asset_group;
            echo '<details class="hpa-asset-card hpa-recent-tx-card"><summary><span class="hpa-asset-card-icon">'.esc_html($this->asset_group_icon($r->asset_group)).'</span><span class="hpa-recent-main"><b>'.esc_html($r->title).'</b><small>'.esc_html($group_label).' · '.esc_html($this->asset_amount_label($r)).'</small></span><strong class="hpa-asset-card-value">'.esc_html($this->fmt_money($v['current_total'],'toman')).'</strong></summary><div class="hpa-recent-details"><p><strong>شخص:</strong> '.esc_html($this->person_label($r->person_key ?? 'hamidreza')).'</p><p><strong>مدل/عیار:</strong> '.esc_html(trim($r->model.' '.$r->purity) ?: '—').'</p><p><strong>قیمت خرید کل:</strong> '.esc_html($this->fmt_money($v['purchase_total'],'toman')).'</p><p><strong>نرخ خرید واحد:</strong> '.esc_html($this->asset_unit_price_label($r)).'</p><p><strong>ارزش فعلی:</strong> '.esc_html($this->fmt_money($v['current_total'],'toman')).' '.($v['has_market']?'<small class="hpa-market-rate-note">نرخ TGJU: '.esc_html($this->fmt_money($v['current_unit'],'toman')).'</small>':'<small class="hpa-market-rate-note">بدون نرخ بازار؛ برابر خرید</small>').'</p><p><strong>وضعیت:</strong> '.$this->asset_status_html($v).'</p><p><strong>محل خرید:</strong> '.esc_html($r->purchase_place ?: '—').'</p><p><strong>تأمین مالی:</strong> '.esc_html($this->asset_funding_label($r)).'</p><div class="hpa-row-actions"><a class="hpa-edit" href="'.$edit_url.'">ویرایش</a>'.$this->delete_button('hpa_delete_asset',$r->id,'assets').'</div></div></details>';
        }
        echo '</div></section>';
        $this->view_goals();
                echo '<section class="hpa-card hpa-asset-form-card '.($is_edit?'hpa-editing':'hpa-creating').'"><h2>'.($is_edit?'ویرایش دارایی':'ثبت دارایی').'</h2>'; $this->form_open('hpa_save_asset', true);
        if ($is_edit) echo '<input type="hidden" name="id" value="'.esc_attr($edit->id).'">';
        $eg=$is_edit?$edit->asset_group:'gold'; $ecur=$is_edit?$edit->currency:'toman'; $eperson=$is_edit?($edit->person_key?:'hamidreza'):'hamidreza'; $eloan=$is_edit?(int)($edit->source_loan_id ?? 0):0; $egoal=$is_edit?(int)($edit->goal_id ?? 0):0; $efunding=$is_edit?($edit->funding_source ?? 'personal'):'personal'; $emodel=$is_edit?(string)$edit->model:''; $crypto_items=$this->crypto_rate_items();
        echo '<div class="hpa-form-grid"><label>عنوان دارایی<input name="title" required placeholder="مثلاً سکه / بیت‌کوین / انگشتر طلا" value="'.esc_attr($is_edit?$edit->title:'').'"></label><label>شخص'.$this->person_select('person_key',$eperson).'</label><label>گروه دارایی<select name="asset_group">';
        foreach($groups as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($eg,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label><label class="hpa-asset-model-text">مدل/نوع<input name="model" placeholder="مثلاً زربد" value="'.esc_attr($emodel).'"></label><label class="hpa-asset-model-crypto">نوع کریپتو<select name="model_crypto">';
        foreach($crypto_items as $ck=>$ci) { $emodel_l = strtolower($emodel); $label_l = strtolower((string)$ci[0]); $is_crypto_selected = ($emodel_l === strtolower($ck) || $emodel_l === $label_l || strpos($emodel_l, strtolower($ck)) !== false || ($label_l !== '' && strpos($emodel_l, $label_l) !== false)); echo '<option value="'.esc_attr($ck).'" '.selected($is_crypto_selected,true,false).'>'.esc_html($ci[2].' '.$ci[0]).'</option>'; }
        echo '</select><small class="hpa-help">برای کریپتو، نوع دارایی از نرخ‌های TGJU همین افزونه انتخاب می‌شود.</small></label><label class="hpa-asset-purity-field">عیار/خلوص<input name="purity" placeholder="18 عیار / 24 عیار / 999" value="'.esc_attr($is_edit?$edit->purity:'').'"></label><label class="hpa-asset-weight-field">وزن<input name="weight" inputmode="decimal" placeholder="گرم" value="'.esc_attr($is_edit?$edit->weight:'').'"></label><label class="hpa-asset-quantity-field">تعداد/مقدار<input name="quantity" inputmode="decimal" value="'.esc_attr($is_edit?$edit->quantity:'').'"></label><label class="hpa-asset-unit-field">واحد<input name="unit" placeholder="گرم، عدد، BTC" value="'.esc_attr($is_edit?$edit->unit:'').'"></label><label>قیمت خرید کل<input name="purchase_price" inputmode="decimal" value="'.esc_attr($is_edit?$edit->purchase_price:'').'"><small class="hpa-help hpa-unit-price-preview">قیمت واحد بعد از وارد کردن مقدار محاسبه می‌شود.</small></label><label>واحد پول<select name="currency">';
        foreach($curr as $k=>$v) echo '<option value="'.esc_attr($k).'" '.selected($ecur,$k,false).'>'.esc_html($v).'</option>';
        echo '</select></label><label>تاریخ خرید شمسی<input name="jalali_date" class="hpa-jdate" required value="'.esc_attr($is_edit?$edit->jalali_date:$this->today_jalali()).'" placeholder="1403/01/15"></label><label>محل خرید<input name="purchase_place" value="'.esc_attr($is_edit?$edit->purchase_place:'').'"></label><label>وام تأمین‌کننده'.$this->loan_select('source_loan_id',$eloan).'<small class="hpa-help">اگر دارایی با وام خریداری شده، وام را انتخاب کن؛ اصل وام جداگانه دارایی/درآمد حساب نمی‌شود.</small></label><label>هدف مالی'.$this->goal_select('goal_id',$egoal).'</label><label>منبع تأمین<select name="funding_source"><option value="personal" '.selected($efunding,'personal',false).'>پول شخصی</option><option value="loan" '.selected($efunding,'loan',false).'>از محل وام</option><option value="check" '.selected($efunding,'check',false).'>از محل چک</option><option value="debt" '.selected($efunding,'debt',false).'>از محل بدهی</option></select><small class="hpa-help">در کارت دارایی نشان می‌دهد دارایی پشتوانه بدهی/چک/وام دارد یا نه.</small></label><label>رسید خرید<input type="file" name="receipt[]" accept="image/*,application/pdf" multiple></label><label>فعال باشد؟ <span class="hpa-checkline"><input type="checkbox" name="is_active" value="1" '.checked($is_edit?(int)$edit->is_active:1,1,false).'> بله</span></label><label class="hpa-col-full">توضیح<textarea name="note">'.esc_textarea($is_edit?$edit->note:'').'</textarea></label></div>';
        $this->form_close($is_edit?'ذخیره تغییرات دارایی':'ثبت دارایی');
        if ($is_edit) echo '<a class="hpa-btn hpa-btn-ghost hpa-cancel-edit" href="'.esc_url(remove_query_arg('hpa_edit_asset')).'">انصراف از ویرایش</a>';
        echo '</section>';
    }

    private function report_financial_overview_text() {
        global $wpdb;
        $range=$this->current_jalali_month_gregorian_range();
        $income=$this->transaction_sum_toman('income', $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $expense=$this->transaction_sum_toman($this->expense_types(), $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $cashflow=$income-$expense; $assets=$this->asset_summary_totals();
        $debts=$this->table_sum_toman('debts','amount',"status!='paid'")+$this->loan_remaining_total_toman()+$this->check_open_total_toman();
        $ratio=$income>0?round($expense*100/$income):0;
        echo '<section class="hpa-card hpa-analysis-card"><h2>خلاصه تحلیلی سلامت مالی</h2>';
        echo '<p>در ماه شمسی جاری، ورودی ثبت‌شده برابر <strong>'.esc_html($this->fmt_money($income,'toman')).'</strong> و خروجی ثبت‌شده برابر <strong>'.esc_html($this->fmt_money($expense,'toman')).'</strong> است. جریان نقدی ماه '.($cashflow>=0?'مثبت':'منفی').' و معادل <strong class="'.($cashflow>=0?'hpa-positive':'hpa-negative').'">'.esc_html(($cashflow>=0?'+':'-').$this->fmt_money(abs($cashflow),'toman')).'</strong> است.</p>';
        echo '<p>نسبت هزینه به درآمد ماه جاری حدود <strong>'.esc_html($ratio).'%</strong> است. از نگاه اقتصاد شخصی، هرچه این نسبت پایین‌تر باشد توان پس‌انداز، سرمایه‌گذاری و پوشش تعهدات آینده بهتر است. اگر این نسبت بالای ۸۰٪ باشد، بهتر است هزینه‌های غیرضروری و تعهدات ماه بعد دوباره بررسی شوند.</p>';
        echo '<p>ارزش فعلی دارایی‌های ثبت‌شده <strong>'.esc_html($this->fmt_money($assets['current'],'toman')).'</strong> است و سود/زیان روی کاغذ آن <strong class="'.($assets['profit']>=0?'hpa-positive':'hpa-negative').'">'.esc_html(($assets['profit']>=0?'+':'-').$this->fmt_money(abs($assets['profit']),'toman')).'</strong> محاسبه شده. مجموع بدهی‌ها، اقساط مانده و چک‌های باز حدود <strong>'.esc_html($this->fmt_money($debts,'toman')).'</strong> است.</p>';
        echo '</section>';
    }


    private function report_accounting_health_ratios() {
        global $wpdb;
        $range=$this->current_jalali_month_gregorian_range();
        $income=$this->transaction_sum_toman('income', $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $expense=$this->transaction_sum_toman($this->expense_types(), $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $net_savings=$income-$expense;
        $assets=$this->asset_summary_totals();
        $debt_total=$this->table_sum_toman('debts','amount',"status!='paid'")+$this->loan_remaining_total_toman()+$this->check_open_total_toman();
        $debt_asset_ratio=$assets['current']>0?round($debt_total*100/$assets['current']):0;
        $installments=$this->transaction_sum_toman(['loan_installment','recurring_debt'], $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $install_income_ratio=$income>0?round($installments*100/$income):0;
        $next_min=$this->next_month_minimum_liquidity();
        echo '<section class="hpa-grid hpa-kpis hpa-accounting-ratios"><article class="hpa-kpi"><span>💾</span><small>خالص پس‌انداز ماه</small><strong class="'.($net_savings>=0?'hpa-positive':'hpa-negative').'">'.esc_html(($net_savings>=0?'+':'-').$this->fmt_money(abs($net_savings),'toman')).'</strong></article><article class="hpa-kpi"><span>⚖️</span><small>نسبت بدهی به دارایی</small><strong>'.esc_html($debt_asset_ratio).'%</strong></article><article class="hpa-kpi"><span>🏦</span><small>نسبت اقساط به درآمد</small><strong>'.esc_html($install_income_ratio).'%</strong></article><article class="hpa-kpi"><span>🧭</span><small>حداقل نقدینگی ماه آینده</small><strong>'.esc_html($this->fmt_money($next_min,'toman')).'</strong></article></section>';
    }

    private function next_month_minimum_liquidity() {
        global $wpdb;
        $today=$this->today_jalali(); [$jy,$jm]=array_map('intval', explode('/', $today)); $jm++; if($jm>12){$jm=1;$jy++;}
        $last=$jm<=6?31:($jm<=11?30:29); $start=$this->jalali_to_gregorian_date(sprintf('%04d/%02d/01',$jy,$jm)); $end=$this->jalali_to_gregorian_date(sprintf('%04d/%02d/%02d',$jy,$jm,$last));
        $sum=0;
        $loans=$wpdb->get_results($wpdb->prepare("SELECT amount,currency FROM {$this->tables['loan_installments']} WHERE status!='paid' AND due_gregorian_date BETWEEN %s AND %s", $start,$end)); foreach($loans as $r)$sum+=$this->amount_to_toman($r->amount,$r->currency);
        $checks=$wpdb->get_results($wpdb->prepare("SELECT (amount_each*check_count) amount,currency FROM {$this->tables['checks']} WHERE status!='paid' AND first_due_gregorian_date BETWEEN %s AND %s", $start,$end)); foreach($checks as $r)$sum+=$this->amount_to_toman($r->amount,$r->currency);
        $rec=$wpdb->get_results("SELECT amount,currency FROM {$this->tables['recurring']} WHERE COALESCE(status,'active') NOT IN ('inactive','archived','paid','closed')"); foreach($rec as $r)$sum+=$this->amount_to_toman($r->amount,$r->currency);
        return $sum;
    }

    private function report_money_routes() {
        global $wpdb;
        $range=$this->current_jalali_month_gregorian_range();
        $out_types=$this->expense_types();
        $in_types=['income'];
        $out=[]; foreach($out_types as $t){$out[$t]=$this->transaction_sum_toman($t,$wpdb->prepare('gregorian_date BETWEEN %s AND %s',$range[0],$range[1]));}
        $in=[]; foreach($in_types as $t){$in[$t]=$this->transaction_sum_toman($t,$wpdb->prepare('gregorian_date BETWEEN %s AND %s',$range[0],$range[1]));}
        $labels=$this->transaction_types();
        echo '<section class="hpa-two"><div class="hpa-card"><h2>پول کجا رفت؟</h2>';
        foreach($out as $k=>$v) if($v>0) echo '<div class="hpa-list-row"><b>'.esc_html($labels[$k]??$k).'</b><em class="hpa-negative">'.esc_html($this->fmt_money($v,'toman')).'</em></div>';
        if(!array_filter($out)) echo '<p class="hpa-muted">خروجی قابل گزارشی در ماه جاری نیست.</p>';
        echo '</div><div class="hpa-card"><h2>پول از کجا آمد؟</h2>';
        foreach($in as $k=>$v) if($v>0) echo '<div class="hpa-list-row"><b>'.esc_html($labels[$k]??$k).'</b><em class="hpa-positive">'.esc_html($this->fmt_money($v,'toman')).'</em></div>';
        if(!array_filter($in)) echo '<p class="hpa-muted">ورودی قابل گزارشی در ماه جاری نیست.</p>';
        echo '</div></section>';
    }

    private function report_financing_summary() {
        global $wpdb; $range=$this->current_jalali_month_gregorian_range();
        $labels=$this->transaction_types();
        $where=$wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]);
        $in_total=0; $out_total=0; $in_html=''; $out_html='';
        foreach($this->financing_in_types() as $t){ $v=$this->transaction_sum_toman($t, $where); if($v>0){ $in_total+=$v; $in_html.='<div class="hpa-list-row"><b>'.esc_html($labels[$t]??$t).'</b><em class="hpa-positive">'.esc_html($this->fmt_money($v,'toman')).'</em></div>'; } }
        foreach($this->financing_out_types() as $t){ $v=$this->transaction_sum_toman($t, $where); if($v>0){ $out_total+=$v; $out_html.='<div class="hpa-list-row"><b>'.esc_html($labels[$t]??$t).'</b><em class="hpa-negative">'.esc_html($this->fmt_money($v,'toman')).'</em></div>'; } }
        echo '<section class="hpa-card hpa-financing-card"><div class="hpa-section-head"><div><h2>جابه‌جایی پول و بازپرداخت‌ها (ماه جاری)</h2><p class="hpa-muted">اینها درآمد یا هزینه نیستند؛ فقط جابه‌جایی پول‌اند (گرفتن/پس‌دادن قرض و وام، خرید/فروش دارایی، وصول طلب) و در «درآمد/هزینهٔ ماه» شمرده نمی‌شوند. روی موجودی حساب اثر می‌گذارند ولی روی «ارزش خالص دارایی» نه.</p></div></div><div class="hpa-two">';
        echo '<div class="hpa-card hpa-subcard"><h3>ورودی پول (تأمین مالی)</h3>'.($in_html ?: '<p class="hpa-muted">موردی در این ماه نیست.</p>').'<div class="hpa-list-row"><b>جمع ورودی</b><em>'.esc_html($this->fmt_money($in_total,'toman')).'</em></div></div>';
        echo '<div class="hpa-card hpa-subcard"><h3>خروجی پول (بازپرداخت/خرید دارایی)</h3>'.($out_html ?: '<p class="hpa-muted">موردی در این ماه نیست.</p>').'<div class="hpa-list-row"><b>جمع خروجی</b><em>'.esc_html($this->fmt_money($out_total,'toman')).'</em></div></div>';
        echo '</div></section>';
    }

    private function report_item_spending() {
        global $wpdb; $range=$this->current_jalali_month_gregorian_range();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT name, amount, currency FROM {$this->tables['transaction_items']} WHERE gregorian_date BETWEEN %s AND %s", $range[0], $range[1]));
        $map=[]; foreach((array)$rows as $r){ $k=trim((string)$r->name); if($k==='') continue; $map[$k]=($map[$k]??0)+$this->amount_to_toman($r->amount,$r->currency); }
        arsort($map); $total=array_sum($map);
        echo '<section class="hpa-card hpa-item-spending"><div class="hpa-section-head"><div><h2>خرج به تفکیک قلم (ماه جاری)</h2><p class="hpa-muted">جمع هزینهٔ هر قلمی که هنگام ثبت تراکنش با قیمت جدا وارد کرده‌ای — مستقل از مبلغ کل.</p></div></div>';
        if(!$map) echo '<p class="hpa-muted">هنوز قلمی با قیمت ثبت نشده است. هنگام ثبت تراکنش، در بخش «اقلام خرید» نام و قیمت هر قلم را وارد کن.</p>';
        else {
            echo '<div class="hpa-table-wrap"><table class="hpa-table"><thead><tr><th>قلم</th><th>جمع در ماه</th><th>سهم</th></tr></thead><tbody>';
            foreach($map as $name=>$t){ $pct=$total>0?round($t*100/$total):0; echo '<tr><td>'.esc_html($name).'</td><td>'.esc_html($this->fmt_money($t,'toman')).'</td><td>'.esc_html(number_format_i18n($pct,0)).'%</td></tr>'; }
            echo '<tr><td><strong>جمع کل اقلام</strong></td><td><strong>'.esc_html($this->fmt_money($total,'toman')).'</strong></td><td>—</td></tr>';
            echo '</tbody></table></div>';
        }
        echo '</section>';
    }

    private function report_essential_expenses() {
        global $wpdb; $range=$this->current_jalali_month_gregorian_range();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT c.is_essential, t.amount, t.currency FROM {$this->tables['transactions']} t LEFT JOIN {$this->tables['categories']} c ON c.id=t.category_id WHERE t.status!='cancelled' AND t.type IN ('expense','recurring_debt') AND t.gregorian_date BETWEEN %s AND %s", $range[0],$range[1]));
        $ess=0; $non=0; foreach($rows as $r){ if((int)($r->is_essential ?? 1)) $ess+=$this->amount_to_toman($r->amount,$r->currency); else $non+=$this->amount_to_toman($r->amount,$r->currency); }
        echo '<section class="hpa-card"><h2>هزینه‌های ضروری و غیرضروری ماه</h2><div class="hpa-metric-row"><span>ضروری</span><strong>'.esc_html($this->fmt_money($ess,'toman')).'</strong><span>غیرضروری</span><strong>'.esc_html($this->fmt_money($non,'toman')).'</strong></div><p class="hpa-muted">ضروری/غیرضروری بودن از تنظیمات موضوعات تراکنش خوانده می‌شود.</p></section>';
    }

    private function report_person_transfers_shared() {
        global $wpdb; $range=$this->current_jalali_month_gregorian_range();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT from_person_key,to_person_key,amount,currency FROM {$this->tables['transactions']} WHERE type='person_transfer' AND status!='cancelled' AND gregorian_date BETWEEN %s AND %s", $range[0],$range[1]));
        $net=['hamidreza_to_samira'=>0,'samira_to_hamidreza'=>0]; foreach($rows as $r){$v=$this->amount_to_toman($r->amount,$r->currency); if($r->from_person_key==='hamidreza'&&$r->to_person_key==='samira')$net['hamidreza_to_samira']+=$v; if($r->from_person_key==='samira'&&$r->to_person_key==='hamidreza')$net['samira_to_hamidreza']+=$v;}
        $shared=$this->transaction_sum_toman(['expense','loan_installment','recurring_debt','check_settlement','asset_buy'], $wpdb->prepare("person_key=%s AND gregorian_date BETWEEN %s AND %s", 'joint', $range[0],$range[1]));
        echo '<section class="hpa-two"><div class="hpa-card"><h2>گزارش انتقال بین اشخاص</h2><div class="hpa-list-row"><b>'.esc_html($this->person_label('hamidreza')).' ← '.esc_html($this->person_label('samira')).'</b><em>'.esc_html($this->fmt_money($net['hamidreza_to_samira'],'toman')).'</em></div><div class="hpa-list-row"><b>'.esc_html($this->person_label('samira')).' ← '.esc_html($this->person_label('hamidreza')).'</b><em>'.esc_html($this->fmt_money($net['samira_to_hamidreza'],'toman')).'</em></div></div><div class="hpa-card"><h2>خرج‌های مشترک ماه</h2><div class="hpa-list-row"><b>جمع هزینه‌های مشترک</b><em>'.esc_html($this->fmt_money($shared,'toman')).'</em></div></div></section>';
    }

    private function report_places_largest_balance() {
        global $wpdb; $range=$this->current_jalali_month_gregorian_range();
        echo '<section class="hpa-three"><div class="hpa-card"><h2>بیشترین محل‌های خرج</h2>';
        $places=$wpdb->get_results($wpdb->prepare("SELECT transaction_place, SUM(amount) s, currency FROM {$this->tables['transactions']} WHERE transaction_place<>'' AND type IN ('expense','recurring_debt') AND status!='cancelled' AND gregorian_date BETWEEN %s AND %s GROUP BY transaction_place ORDER BY s DESC LIMIT 8",$range[0],$range[1]));
        foreach($places as $r) echo '<div class="hpa-list-row"><b>'.esc_html($r->transaction_place).'</b><em>'.esc_html($this->fmt_money($r->s,$r->currency)).'</em></div>'; if(!$places) echo '<p class="hpa-muted">محل خرج ثبت نشده است.</p>';
        echo '</div><div class="hpa-card"><h2>بزرگ‌ترین تراکنش‌های ماه</h2>';
        $big=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE status!='cancelled' AND gregorian_date BETWEEN %s AND %s ORDER BY amount DESC LIMIT 10",$range[0],$range[1]));
        foreach($big as $r) echo '<div class="hpa-list-row"><b>'.esc_html($r->jalali_date.' · '.($this->transaction_types()[$r->type]??$r->type)).'</b><em>'.esc_html($this->fmt_money($r->amount,$r->currency)).'</em></div>'; if(!$big) echo '<p class="hpa-muted">تراکنشی ثبت نشده است.</p>';
        echo '</div><div class="hpa-card"><h2>روند مانده حساب‌ها</h2>'.$this->account_balance_trend_svg().'</div></section>';
    }

    private function account_balance_trend_svg() {
        global $wpdb; $accounts=$this->get_accounts(); if(!$accounts) return '<p class="hpa-muted">حسابی ثبت نشده است.</p>';
        $out='<div class="hpa-mini-trends">'; $months=$this->last_jalali_month_ranges(6);
        foreach($accounts as $a){ $vals=[]; foreach($months as $m){ $in=$this->transaction_sum_toman($this->cash_in_types(), $wpdb->prepare('account_id=%d AND gregorian_date<=%s',$a->id,$m['end'])); $outg=$this->transaction_sum_toman($this->cash_out_types(), $wpdb->prepare('account_id=%d AND gregorian_date<=%s',$a->id,$m['end'])); $vals[]=$this->amount_to_toman($a->opening_balance,$a->currency)+$in-$outg; } $max=max($vals)?:1; $bars=''; foreach($vals as $v){$bars.='<span style="height:'.esc_attr(max(6,round($v*80/$max))).'px"></span>';} $out.='<div class="hpa-trend-row"><b>'.esc_html($a->name).'</b><div class="hpa-spark">'.$bars.'</div></div>'; }
        return $out.'</div>';
    }

    private function last_jalali_month_ranges($n=6) {
        $ranges=[]; $today=$this->today_jalali(); [$jy,$jm]=array_map('intval', explode('/', $today));
        for($i=$n-1;$i>=0;$i--){ $m=$jm-$i; $y=$jy; while($m<=0){$m+=12;$y--;} $start=sprintf('%04d/%02d/01',$y,$m); $last=$m<=6?31:($m<=11?30:29); $end=sprintf('%04d/%02d/%02d',$y,$m,$last); $ranges[]=['label'=>$this->jalali_month_name($m),'start'=>$this->jalali_to_gregorian_date($start),'end'=>$this->jalali_to_gregorian_date($end)]; }
        return $ranges;
    }
    private function jalali_month_name($m){$names=[1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند']; return $names[(int)$m]??$m;}

    private function future_obligation_items($limit=20) {
        global $wpdb;
        $limit = max(1, absint($limit));
        $items=[];
        $loans=$wpdb->get_results("SELECT i.due_jalali_date d, i.due_gregorian_date gd, i.amount, i.currency, l.title, l.lender FROM {$this->tables['loan_installments']} i LEFT JOIN {$this->tables['loans']} l ON l.id=i.loan_id WHERE i.status!='paid' ORDER BY i.due_gregorian_date ASC LIMIT ".$limit);
        foreach($loans as $r){ $items[]=['title'=>'قسط: '.($r->title ?: 'وام'), 'date'=>$r->d, 'gdate'=>$r->gd, 'amount'=>$r->amount, 'currency'=>$r->currency, 'icon'=>'🏦', 'detail'=>'وام‌دهنده: '.($r->lender ?: '—'), 'type'=>'installment', 'is_paid'=>false]; }
        $today=date('Y-m-d'); $to=date('Y-m-d', strtotime('+30 days'));
        $checks=$wpdb->get_results($wpdb->prepare("SELECT first_due_jalali_date d, first_due_gregorian_date gd, (amount_each*check_count) amount, currency, title, used_for, check_count FROM {$this->tables['checks']} WHERE status!='paid' AND first_due_gregorian_date BETWEEN %s AND %s ORDER BY first_due_gregorian_date ASC LIMIT {$limit}", $today, $to));
        foreach($checks as $r){ $items[]=['title'=>'چک: '.($r->title ?: 'چک'), 'date'=>$r->d, 'gdate'=>$r->gd, 'amount'=>$r->amount, 'currency'=>$r->currency, 'icon'=>'🧾', 'detail'=>'تعداد: '.(int)$r->check_count.' · مصرف: '.($r->used_for ?: '—'), 'type'=>'check', 'is_paid'=>false]; }

        $recurring_items = [];
        $rec=$wpdb->get_results("SELECT r.id, COALESCE(r.next_jalali_date,r.start_jalali_date) d, COALESCE(r.next_gregorian_date,r.start_gregorian_date) gd, r.amount, r.currency, r.title, r.interval_type, c.name AS category_name FROM {$this->tables['recurring']} r LEFT JOIN {$this->tables['categories']} c ON c.id=r.category_id WHERE COALESCE(r.status,'active') NOT IN ('inactive','archived','paid','closed') AND r.amount>0 ORDER BY COALESCE(r.next_gregorian_date,r.start_gregorian_date,'9999-12-31') ASC, r.id DESC LIMIT ".$limit);
        foreach($rec as $r){
            $key=(int)$r->id.'|'.($r->gd ?: $r->d);
            $is_paid=(bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['transactions']} WHERE recurring_id=%d AND type='recurring_debt' AND status='done' AND recurring_due_gregorian_date=%s LIMIT 1", (int)$r->id, (string)$r->gd));
            $recurring_items[$key]=['title'=>'تکرارشونده: '.($r->title ?: 'پرداخت تکرارشونده'), 'date'=>$r->d ?: 'بدون تاریخ', 'gdate'=>$r->gd ?: '9999-12-31', 'amount'=>$r->amount, 'currency'=>$r->currency, 'icon'=>'🔁', 'detail'=>'دسته: '.($r->category_name ?: '—').' · دوره: '.($r->interval_type ?: '—'), 'type'=>'recurring', 'is_paid'=>$is_paid, 'recurring_id'=>(int)$r->id];
        }

        // پرداخت‌های ثبت‌شده‌ی نزدیک را نیز نگه می‌داریم تا همان سررسید با خط‌خوردگی و برچسب پرداخت‌شده دیده شود.
        $paid_from=date('Y-m-d', strtotime('-31 days'));
        $paid_to=date('Y-m-d', strtotime('+365 days'));
        $paid_rec=$wpdb->get_results($wpdb->prepare(
            "SELECT t.id transaction_id,t.recurring_id,t.recurring_due_jalali_date d,t.recurring_due_gregorian_date gd,t.amount,t.currency,r.title,r.interval_type,c.name category_name FROM {$this->tables['transactions']} t INNER JOIN {$this->tables['recurring']} r ON r.id=t.recurring_id LEFT JOIN {$this->tables['categories']} c ON c.id=r.category_id WHERE t.type='recurring_debt' AND t.status='done' AND t.recurring_id>0 AND t.recurring_due_gregorian_date BETWEEN %s AND %s ORDER BY t.recurring_due_gregorian_date ASC,t.id DESC LIMIT {$limit}",
            $paid_from, $paid_to
        ));
        foreach((array)$paid_rec as $r){
            $key=(int)$r->recurring_id.'|'.($r->gd ?: $r->d);
            $recurring_items[$key]=['title'=>'تکرارشونده: '.($r->title ?: 'پرداخت تکرارشونده'), 'date'=>$r->d ?: 'بدون تاریخ', 'gdate'=>$r->gd ?: '9999-12-31', 'amount'=>$r->amount, 'currency'=>$r->currency, 'icon'=>'🔁', 'detail'=>'دسته: '.($r->category_name ?: '—').' · دوره: '.($r->interval_type ?: '—'), 'type'=>'recurring', 'is_paid'=>true, 'recurring_id'=>(int)$r->recurring_id, 'transaction_id'=>(int)$r->transaction_id];
        }
        foreach($recurring_items as $it) $items[]=$it;

        usort($items, function($a,$b){
            $cmp=strcmp((string)($a['gdate'] ?: '9999-99-99'), (string)($b['gdate'] ?: '9999-99-99'));
            if ($cmp !== 0) return $cmp;
            return ((int)!empty($a['is_paid'])) <=> ((int)!empty($b['is_paid']));
        });
        return array_slice($items, 0, $limit);
    }

    private function obligation_card_html($it, $extra_class='') {
        $title = preg_replace('/\s+/u', ' ', trim((string)($it['title'] ?? '')));
        $is_paid = !empty($it['is_paid']);
        $classes = trim('hpa-obligation-card '.$extra_class.($is_paid ? ' hpa-obligation-paid' : ''));
        $paid_label = $is_paid ? '<small class="hpa-obligation-status">✓ پرداخت‌شده</small>' : '';
        return '<details class="'.esc_attr($classes).'"'.($is_paid ? ' data-paid="1"' : '').'><summary><span>'.esc_html($it['icon']).'</span><span class="hpa-obligation-title-wrap"><b>'.esc_html($title).'</b><small>'.esc_html($it['date'] ?: 'بدون تاریخ').'</small>'.$paid_label.'</span><strong>'.esc_html($this->fmt_money($it['amount'],$it['currency'])).'</strong></summary><div class="hpa-obligation-detail"><p>'.esc_html($it['detail']).'</p><p><strong>نوع تعهد:</strong> '.esc_html($it['type']).'</p>'.($is_paid?'<p class="hpa-obligation-paid-note"><strong>وضعیت:</strong> پرداخت‌شده</p>':'').'</div></details>';
    }

    private function report_future_obligations() {
        $items=$this->future_obligation_items(60);
        $visible_default = 6;
        echo '<section id="hpa-future-obligations" class="hpa-card hpa-future-obligations"><div class="hpa-section-head"><div><h2>تعهدات آینده</h2><p class="hpa-muted">اقساط، چک‌ها، بدهی‌های باز و پرداخت‌های تکرارشونده آینده.</p></div></div><div class="hpa-obligation-cards">';
        $i=0;
        foreach($items as $it){ $i++; echo $this->obligation_card_html($it, $i>$visible_default?'hpa-lazy-more-item':''); }
        if(!$items) echo '<p class="hpa-muted">تعهد آینده‌ای ثبت نشده است.</p>';
        echo '</div>';
        if(count($items)>$visible_default) echo '<button type="button" class="hpa-btn hpa-btn-ghost hpa-show-more-cards">نمایش بیشتر</button>';
        echo '</section>';
    }

    private function dashboard_future_obligations_preview() {
        $items=array_slice($this->future_obligation_items(12),0,4);
        echo '<div class="hpa-card hpa-dashboard-obligations"><div class="hpa-section-head"><div><h3>تعهدات آینده</h3><p class="hpa-muted">سه مورد نزدیک‌تر</p></div></div><div class="hpa-dashboard-obligation-list">';
        $count=0;
        foreach($items as $it){ $count++; if($count<=3) echo $this->obligation_card_html($it, 'hpa-dashboard-obligation-card'); }
        if(!$items) echo '<p class="hpa-muted">تعهد آینده‌ای ثبت نشده است.</p>';
        $url=esc_url(add_query_arg('hpa_tab','debt').'#hpa-future-obligations');
        echo '<a class="hpa-obligation-more-peek" href="'.$url.'">مشاهده همه تعهدات آینده</a>';
        echo '</div></div>';
    }

    private function report_next_month_obligations() {
        global $wpdb; $ranges=$this->last_jalali_month_ranges(2); $next=$ranges[1]??null; if(!$next)return;
        $loan=$this->rows_sum_toman($wpdb->get_results($wpdb->prepare("SELECT amount,currency FROM {$this->tables['loan_installments']} WHERE status!='paid' AND due_gregorian_date BETWEEN %s AND %s",$next['start'],$next['end'])));
        $check=$this->rows_sum_toman($wpdb->get_results($wpdb->prepare("SELECT (amount_each*check_count) amount,currency FROM {$this->tables['checks']} WHERE status!='paid' AND first_due_gregorian_date BETWEEN %s AND %s",$next['start'],$next['end'])));
        $rec=$this->rows_sum_toman($wpdb->get_results($wpdb->prepare("SELECT amount,currency FROM {$this->tables['recurring']} WHERE status='active' AND next_gregorian_date BETWEEN %s AND %s",$next['start'],$next['end'])));
        echo '<section class="hpa-card"><h2>فشار تعهدات ماه آینده</h2><div class="hpa-metric-row"><span>قسط</span><strong>'.esc_html($this->fmt_money($loan,'toman')).'</strong><span>چک</span><strong>'.esc_html($this->fmt_money($check,'toman')).'</strong><span>تکرارشونده</span><strong>'.esc_html($this->fmt_money($rec,'toman')).'</strong><span>جمع</span><strong>'.esc_html($this->fmt_money($loan+$check+$rec,'toman')).'</strong></div></section>';
    }

    private function report_debt_backed_assets() {
        global $wpdb; $rows=$wpdb->get_results("SELECT * FROM {$this->tables['assets']} WHERE COALESCE(funding_source,'personal')!='personal' OR source_loan_id>0 ORDER BY id DESC LIMIT 50");
        echo '<section class="hpa-card"><h2>دارایی‌های بدهی‌دار</h2><div class="hpa-asset-card-list">';
        foreach($rows as $r){$v=$this->asset_valuation($r); echo '<article class="hpa-asset-card"><span class="hpa-asset-card-icon">'.esc_html($this->asset_group_icon($r->asset_group)).'</span><b>'.esc_html($r->title).'</b><small>'.esc_html($this->asset_funding_label($r)).'</small><strong>'.esc_html($this->fmt_money($v['current_total'],'toman')).'</strong></article>';}
        if(!$rows) echo '<p class="hpa-muted">دارایی بدهی‌دار ثبت نشده است.</p>'; echo '</div></section>';
    }

    private function report_month_comparison() {
        $ranges=$this->last_jalali_month_ranges(2); if(count($ranges)<2)return; global $wpdb; $cur=$ranges[1]; $prev=$ranges[0];
        $ci=$this->transaction_sum_toman('income',$wpdb->prepare('gregorian_date BETWEEN %s AND %s',$cur['start'],$cur['end'])); $pi=$this->transaction_sum_toman('income',$wpdb->prepare('gregorian_date BETWEEN %s AND %s',$prev['start'],$prev['end']));
        $ce=$this->transaction_sum_toman($this->expense_types(),$wpdb->prepare('gregorian_date BETWEEN %s AND %s',$cur['start'],$cur['end'])); $pe=$this->transaction_sum_toman($this->expense_types(),$wpdb->prepare('gregorian_date BETWEEN %s AND %s',$prev['start'],$prev['end']));
        $pct=function($a,$b){return $b>0?round(($a-$b)*100/$b):0;};
        echo '<section class="hpa-card"><h2>مقایسه ماه جاری با ماه قبل</h2><div class="hpa-metric-row"><span>تغییر درآمد</span><strong class="'.($ci>=$pi?'hpa-positive':'hpa-negative').'">'.esc_html($pct($ci,$pi)).'%</strong><span>تغییر هزینه</span><strong class="'.($ce<=$pe?'hpa-positive':'hpa-negative').'">'.esc_html($pct($ce,$pe)).'%</strong><span>پس‌انداز خالص</span><strong>'.esc_html($this->fmt_money($ci-$ce,'toman')).'</strong></div></section>';
    }

    private function report_networth_affecting() {
        global $wpdb; $rows=$wpdb->get_results("SELECT * FROM {$this->tables['transactions']} WHERE status!='cancelled' AND type NOT IN ('transfer','person_transfer') ORDER BY gregorian_date DESC,id DESC LIMIT 12");
        echo '<section class="hpa-card"><h2>تراکنش‌های اثرگذار روی دارایی خالص</h2>'; foreach($rows as $r) echo '<div class="hpa-list-row"><b>'.esc_html($r->jalali_date.' · '.($this->transaction_types()[$r->type]??$r->type)).'</b><em>'.esc_html($this->fmt_money($r->amount,$r->currency)).'</em></div>'; if(!$rows) echo '<p class="hpa-muted">موردی وجود ندارد.</p>'; echo '</section>';
    }

    private function report_asset_realized_unrealized() {
        global $wpdb; $sells=$wpdb->get_results("SELECT t.*, a.unit_price, a.currency asset_currency, a.title FROM {$this->tables['transactions']} t LEFT JOIN {$this->tables['assets']} a ON a.id=t.asset_id WHERE t.type='asset_sell' AND t.status!='cancelled' AND t.asset_id>0");
        $real=0; foreach($sells as $r){$qty=(float)($r->asset_quantity ?: 0); $cost=$qty>0?(float)$r->unit_price*$qty:0; $real += $this->amount_to_toman($r->amount,$r->currency)-$this->amount_to_toman($cost,$r->asset_currency ?: $r->currency);}
        $unreal=$this->asset_summary_totals()['profit'];
        echo '<section class="hpa-card"><h2>سود/زیان تحقق‌یافته و تحقق‌نیافته</h2><div class="hpa-metric-row"><span>تحقق‌یافته از فروش دارایی</span><strong class="'.($real>=0?'hpa-positive':'hpa-negative').'">'.esc_html(($real>=0?'+':'-').$this->fmt_money(abs($real),'toman')).'</strong><span>تحقق‌نیافته روی دارایی‌های موجود</span><strong class="'.($unreal>=0?'hpa-positive':'hpa-negative').'">'.esc_html(($unreal>=0?'+':'-').$this->fmt_money(abs($unreal),'toman')).'</strong></div></section>';
    }

    private function view_reports() {
        global $wpdb;
        $this->report_financial_overview_text();
        $balances=$this->calculate_balances();
        $income=$this->transaction_sum_toman('income');
        $expense=$this->transaction_sum_toman($this->expense_types());
        $asset_summary=$this->asset_summary_totals();
        $assets_total=$asset_summary['current'];
        $debts_total=$this->table_sum_toman('debts', 'amount', "status!='paid'") + $this->loan_remaining_total_toman() + $this->check_open_total_toman();
        $recv_total=$this->table_sum_toman('receivables', 'amount', "status!='paid'");
        echo '<section class="hpa-grid hpa-kpis hpa-report-kpis">';
        $this->kpi('کل درآمد ثبت‌شده',$this->fmt_money_html($income,'toman'),'📈');
        $this->kpi('کل هزینه ثبت‌شده',$this->fmt_money_html($expense,'toman'),'📉');
        $this->kpi('ارزش فعلی دارایی‌ها',$this->fmt_money_html($assets_total,'toman'), $asset_summary['profit'] >= 0 ? '<span class="hpa-trend-icon hpa-trend-up">↗</span>' : '<span class="hpa-trend-icon hpa-trend-down">↘</span>');
        $this->kpi('طلب باز',$this->fmt_money_html($recv_total,'toman'),'🤝');
        $this->kpi('بدهی باز',$this->fmt_money_html($debts_total,'toman'),'⚠️');
        $this->kpi('مانده حساب‌ها',$this->fmt_money($this->total_balances_toman($balances),'toman'),'💳');
        echo '</section>';
        $this->report_month_comparison();
        $this->report_accounting_health_ratios();
        $this->report_money_routes();
        $this->report_essential_expenses();
        $this->report_item_spending();
        $this->report_financing_summary();
        $this->report_person_transfers_shared();
        echo '<section class="hpa-two"><div class="hpa-card"><h2>نمودار هزینه‌ها بر اساس موضوع</h2>'.$this->expense_chart(true).'</div><div class="hpa-card"><h2>درآمد و هزینه ۶ ماه اخیر</h2>'.$this->monthly_svg_chart().'</div></section>';
        echo '<section class="hpa-two"><div class="hpa-card"><h2>گزارش حساب‌ها</h2>';
        $accounts=$this->get_accounts();
        if(!$accounts) echo '<p class="hpa-muted">حسابی ثبت نشده است.</p>';
        foreach($accounts as $a) echo '<div class="hpa-list-row"><span class="hpa-badge" style="background:'.esc_attr($a->color).'">'.$this->account_icon_html($a->icon).'</span><b>'.esc_html($a->name).'<small class="hpa-inline-person">'.esc_html($this->account_type_label($a).' · '.$this->person_label($a->person_key ?? 'hamidreza')).'</small></b><em>'.esc_html($this->fmt_money($balances[$a->id]??0,$a->currency)).'</em></div>';
        echo '</div><div class="hpa-card"><h2>گزارش بر اساس شخص</h2>';
        foreach($this->persons() as $key=>$label){
            $pin=$this->transaction_sum_toman('income', $wpdb->prepare('person_key=%s', $key));
            $pex=$this->transaction_sum_toman($this->expense_types(), $wpdb->prepare('person_key=%s', $key));
            $pas_summary=$this->asset_summary_totals($wpdb->prepare('person_key=%s', $key));
            $pas=$pas_summary['current'];
            echo '<div class="hpa-list-row"><span class="hpa-person-pill">'.esc_html($label).'</span><b>درآمد: '.esc_html($this->fmt_money($pin,'toman')).'<br>هزینه: '.esc_html($this->fmt_money($pex,'toman')).'</b><em>دارایی فعلی: '.esc_html($this->fmt_money($pas,'toman')).'</em></div>';
        }
        echo '</div></section>';
        $this->report_asset_profit_by_group();
        $this->report_asset_realized_unrealized();
        $this->report_places_largest_balance();
        $this->report_networth_affecting();
        $this->report_cashflow_and_calendar();
        echo '<section class="hpa-card"><div class="hpa-section-head"><div><h2>خروجی PDF و بکاپ</h2><p class="hpa-muted">برای PDF از چاپ مرورگر استفاده کن و مقصد را Save as PDF بگذار. بکاپ JSON شامل داده‌های افزونه است.</p></div><button type="button" class="hpa-btn hpa-btn-primary" onclick="window.print()">خروجی PDF گزارش</button></div>';
        echo '<div class="hpa-row-actions hpa-backup-actions"><a class="hpa-btn hpa-btn-ghost" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=hpa_export_backup'), self::NONCE, 'hpa_nonce')).'">دانلود بکاپ کامل</a>';
        $this->form_open('hpa_import_backup', true);
        echo '<input type="file" name="hpa_backup" accept="application/json" required><button class="hpa-btn hpa-btn-primary" type="submit">بازیابی بکاپ</button></form></div></section>';
        echo '<section class="hpa-card"><h2>آخرین تراکنش‌ها برای کنترل گزارش</h2>';
        $this->transactions_table(12);
        echo '</section>';
    }


    private function report_asset_profit_by_group() {
        global $wpdb;
        $groups=$this->asset_groups();
        $rows=$wpdb->get_results("SELECT * FROM {$this->tables['assets']} WHERE COALESCE(is_active,1)=1 ORDER BY asset_group ASC");
        $data=[];
        foreach($rows as $a){ $v=$this->asset_valuation($a); $g=$a->asset_group; if(!isset($data[$g])) $data[$g]=['purchase'=>0,'current'=>0]; $data[$g]['purchase'] += $v['purchase_total']; $data[$g]['current'] += $v['current_total']; }
        echo '<section class="hpa-card"><h2>سود و زیان دارایی‌ها به تفکیک نوع</h2><div class="hpa-table-wrap"><table class="hpa-table"><thead><tr><th>نوع دارایی</th><th>ارزش خرید</th><th>ارزش فعلی</th><th>سود/زیان</th></tr></thead><tbody>';
        if(!$data) echo '<tr><td colspan="4" class="hpa-muted">دارایی فعالی ثبت نشده است.</td></tr>';
        foreach($data as $g=>$d){ $profit=$d['current']-$d['purchase']; $cls=$profit>=0?'hpa-positive':'hpa-negative'; echo '<tr><td>'.esc_html($groups[$g] ?? $g).'</td><td>'.esc_html($this->fmt_money($d['purchase'],'toman')).'</td><td>'.esc_html($this->fmt_money($d['current'],'toman')).'</td><td class="'.$cls.'">'.esc_html(($profit>=0?'+':'-').$this->fmt_money(abs($profit),'toman')).'</td></tr>'; }
        echo '</tbody></table></div></section>';
    }

    private function report_cashflow_and_calendar() {
        global $wpdb;
        $range=$this->current_jalali_month_gregorian_range();
        $income=$this->transaction_sum_toman('income', $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $expense=$this->transaction_sum_toman($this->expense_types(), $wpdb->prepare('gregorian_date BETWEEN %s AND %s', $range[0], $range[1]));
        $net=$income-$expense;
        echo '<section class="hpa-two"><div class="hpa-card"><h2>گزارش جریان نقدی ماه شمسی</h2><div class="hpa-list-row"><b>ورودی ماه</b><em class="hpa-positive">'.esc_html($this->fmt_money($income,'toman')).'</em></div><div class="hpa-list-row"><b>خروجی ماه</b><em class="hpa-negative">'.esc_html($this->fmt_money($expense,'toman')).'</em></div><div class="hpa-list-row"><b>خالص جریان نقدی</b><em class="'.($net>=0?'hpa-positive':'hpa-negative').'">'.esc_html(($net>=0?'+':'-').$this->fmt_money(abs($net),'toman')).'</em></div></div>';
        $events=[];
        $tx=$wpdb->get_results($wpdb->prepare("SELECT jalali_date, COUNT(*) c, SUM(amount) s, currency FROM {$this->tables['transactions']} WHERE gregorian_date BETWEEN %s AND %s GROUP BY jalali_date ORDER BY gregorian_date ASC LIMIT 45", $range[0], $range[1]));
        foreach($tx as $r) $events[]='<span class="hpa-calendar-chip">'.esc_html($r->jalali_date).' — '.(int)$r->c.' تراکنش</span>';
        $checks=$wpdb->get_results($wpdb->prepare("SELECT first_due_jalali_date, title FROM {$this->tables['checks']} WHERE status!='paid' AND first_due_gregorian_date BETWEEN %s AND %s ORDER BY first_due_gregorian_date ASC LIMIT 20", $range[0], $range[1]));
        foreach($checks as $r) $events[]='<span class="hpa-calendar-chip hpa-calendar-warn">'.esc_html($r->first_due_jalali_date).' — چک: '.esc_html($r->title).'</span>';
        $loans=$wpdb->get_results($wpdb->prepare("SELECT i.due_jalali_date, l.title FROM {$this->tables['loan_installments']} i LEFT JOIN {$this->tables['loans']} l ON l.id=i.loan_id WHERE i.status!='paid' AND i.due_gregorian_date BETWEEN %s AND %s ORDER BY i.due_gregorian_date ASC LIMIT 20", $range[0], $range[1]));
        foreach($loans as $r) $events[]='<span class="hpa-calendar-chip hpa-calendar-warn">'.esc_html($r->due_jalali_date).' — قسط: '.esc_html($r->title).'</span>';
        echo '<div class="hpa-card"><h2>تقویم مالی ماه شمسی</h2><div class="hpa-calendar-list">'.($events?implode('', $events):'<p class="hpa-muted">رویدادی برای این ماه ثبت نشده است.</p>').'</div></div></section>';
    }

    private function rate_items() {
        return [
            'usd' => ['دلار آمریکا','currency','💵'], 'eur' => ['یورو','currency','💶'],
            'gold18' => ['طلای ۱۸ عیار','metal','🥇'], 'gold24' => ['طلای ۲۴ عیار','metal','🟡'],
            'silver' => ['نقره','metal','🥈'], 'btc' => ['Bitcoin','crypto','₿'],
            'eth' => ['Ethereum','crypto','◆'], 'usdt' => ['Tether','crypto','₮'],
            'bnb' => ['BNB','crypto','🟨'], 'sol' => ['Solana','crypto','◎'],
            'xrp' => ['XRP','crypto','✕'], 'doge' => ['Dogecoin','crypto','Ð'],
        ];
    }

    private function crypto_rate_items() {
        $out = [];
        foreach ($this->rate_items() as $key => $item) {
            if (($item[1] ?? '') === 'crypto') $out[$key] = $item;
        }
        return $out;
    }

    private function view_rates() {
        global $wpdb;
        $items = $this->rate_items();
        echo '<section class="hpa-card hpa-mobile-settings-hub"><h2>تنظیمات موبایل</h2><p class="hpa-muted">دسترسی سریع به بخش‌هایی که در منوی موبایل مخفی شده‌اند.</p><div class="hpa-settings-grid"><a href="'.esc_url(add_query_arg('hpa_tab','accounts')).'">💳 حساب‌ها</a><a href="'.esc_url(add_query_arg('hpa_tab','categories')).'">🏷️ موضوعات</a><a href="'.esc_url(add_query_arg('hpa_tab','debt')).'">📉 بدهی/وام/چک</a><a href="'.esc_url(add_query_arg('hpa_tab','receivable')).'">📈 طلب‌ها</a></div></section>';
        echo '<section class="hpa-card"><div class="hpa-section-head"><div><h2>نرخ‌ها و تنظیمات مالی</h2><p class="hpa-muted">نرخ‌ها با موتور داخلی TGJU همین افزونه دریافت و در جدول نرخ‌ها کش می‌شوند؛ دیگر نیازی به نصب افزونه جداگانه TGJU نیست.</p></div>';
        echo '<a class="hpa-btn hpa-btn-ghost" href="'.esc_url(wp_nonce_url(admin_url('admin-post.php?action=hpa_fetch_rates'), self::NONCE, 'hpa_nonce')).'">آپدیت نرخ‌ها از TGJU</a></div>';
        $this->form_open('hpa_save_rate');
        echo '<div class="hpa-form-grid"><label>عنوان نرخ<select name="rate_key">';
        foreach($items as $k=>$v) echo '<option value="'.esc_attr($k).'">'.esc_html($v[2].' '.$v[0]).'</option>';
        echo '</select></label><label>قیمت به تومان<input name="price" required inputmode="decimal"></label><label>تاریخ شمسی<input name="jalali_date" class="hpa-jdate" required value="'.esc_attr($this->today_jalali()).'" placeholder="1403/01/15"></label><label>منبع/توضیح کوتاه<input name="source" placeholder="دستی / بازار / صرافی"></label><label class="hpa-col-full">یادداشت<textarea name="note"></textarea></label></div>';
        $this->form_close('ثبت نرخ دستی');
        echo '</section><section class="hpa-card"><h2>آخرین نرخ‌های ثبت‌شده</h2>';
        $rows = $wpdb->get_results("SELECT * FROM {$this->tables['rates']} ORDER BY FIELD(type,'currency','metal','crypto'), title ASC");
        echo '<div class="hpa-rate-grid">';
        foreach($rows as $r){ $icon = $items[$r->rate_key][2] ?? '💱'; echo '<article class="hpa-rate-card"><span>'.esc_html($icon).'</span><small>'.esc_html($r->title).'</small><strong>'.esc_html($this->fmt_money($r->price, 'toman')).'</strong><em>'.esc_html(($r->is_manual?'دستی':'TGJU').' | '.($r->jalali_date ?: '')).'</em>'.$this->delete_button('hpa_delete_rate',$r->id,'rates').'</article>'; }
        if (!$rows) echo '<p class="hpa-muted">هنوز نرخی ثبت نشده است.</p>';
        echo '</div></section>';
    }

    public function save_rate() {
        $this->guard(); global $wpdb;
        $items = $this->rate_items(); $key = sanitize_key($this->clean('rate_key'));
        if (!isset($items[$key])) $key = 'usd';
        $jalali = $this->clean('jalali_date');
        $data = [
            'rate_key'=>$key, 'title'=>$items[$key][0], 'type'=>$items[$key][1], 'price'=>$this->money('price'), 'unit'=>'toman',
            'source'=>$this->clean('source','دستی'), 'jalali_date'=>$jalali, 'gregorian_date'=>$this->jalali_to_gregorian_date($jalali),
            'note'=>$this->textarea('note'), 'is_manual'=>1, 'updated_at'=>current_time('mysql')
        ];
        $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['rates']} WHERE rate_key=%s", $key));
        if ($exists) $wpdb->update($this->tables['rates'], $data, ['id'=>$exists]);
        else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['rates'], $data); }
        $this->redirect('rates');
    }

    public function delete_rate() { $this->guard(); global $wpdb; $id=$this->id('id'); $this->archive_item_before_delete('rates',$id,'title'); $wpdb->delete($this->tables['rates'], ['id'=>$id]); $this->redirect('rates'); }
    public function manual_fetch_rates() { $this->guard(); $this->fetch_rates_from_tgju(true); $this->redirect('rates'); }

    public function fetch_rates_from_tgju($force=false) {
        $settings = get_option(self::OPTION, []);
        if (!$force && empty($settings['auto_rate_update'])) return false;

        $targets = $this->tgju_rate_targets();
        $fetched = $this->tgju_fetch_rates($targets);
        if (empty($fetched) || !is_array($fetched)) return false;
        $fetched = $this->tgju_apply_usdt_dollar_fallback($fetched);

        global $wpdb;
        $items = $this->rate_items();
        $today_g = date('Y-m-d');
        $today_j = $this->gregorian_to_jalali_date($today_g);
        $saved = 0;

        foreach ($fetched as $key => $row) {
            if (!isset($items[$key])) continue;
            $raw_price = isset($row['price']) ? (float)$row['price'] : 0;
            $price = $this->normalize_tgju_price_to_toman($key, $raw_price, $fetched);
            if ($price <= 0 || !$this->tgju_price_is_valid_for_key($key, $price)) continue;
            $data = [
                'rate_key'=>$key,
                'title'=>$items[$key][0],
                'type'=>$items[$key][1],
                'price'=>$price,
                'unit'=>'toman',
                'source'=>sanitize_text_field($row['source'] ?? 'TGJU'),
                'jalali_date'=>$today_j,
                'gregorian_date'=>$today_g,
                'note'=>'به‌روزرسانی خودکار از TGJU',
                'is_manual'=>0,
                'updated_at'=>current_time('mysql')
            ];
            $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->tables['rates']} WHERE rate_key=%s", $key));
            if ($exists) $wpdb->update($this->tables['rates'], $data, ['id'=>$exists]);
            else { $data['created_at']=current_time('mysql'); $wpdb->insert($this->tables['rates'], $data); }
            $saved++;
        }
        return $saved > 0;
    }

    private function tgju_apply_usdt_dollar_fallback($fetched) {
        if (!is_array($fetched)) return $fetched;

        $usd_raw = !empty($fetched['usd']['price']) ? (float)$fetched['usd']['price'] : 0;
        if ($usd_raw <= 0) $usd_raw = (float)$this->latest_rate_price('usd');
        if ($usd_raw <= 0) return $fetched;

        $usd_toman = $this->normalize_tgju_price_to_toman('usd', $usd_raw, []);
        if ($usd_toman <= 0 || !$this->tgju_price_is_valid_for_key('usd', $usd_toman)) return $fetched;

        $use_fallback = empty($fetched['usdt']['price']);
        if (!$use_fallback) {
            $usdt_toman = $this->normalize_tgju_price_to_toman('usdt', (float)$fetched['usdt']['price'], $fetched);
            if ($usdt_toman <= 0 || !$this->tgju_price_is_valid_for_key('usdt', $usdt_toman)) {
                $use_fallback = true;
            } else {
                $diff = abs($usdt_toman - $usd_toman) / max(1, $usd_toman);
                if ($diff > 0.15) $use_fallback = true;
            }
        }

        if ($use_fallback) {
            $fetched['usdt'] = [
                'price' => $usd_raw,
                'source' => !empty($fetched['usd']['source']) ? $fetched['usd']['source'].' / USDT fallback' : 'TGJU Dollar fallback for USDT',
            ];
        }
        return $fetched;
    }

    private function tgju_rate_targets() {
        /*
         * TGJU WordPress plugin itself renders prices in the browser through the official
         * widget script. For server-side caching we first try the official profile/API
         * pages with strict extraction rules. The old loose parser could pick numbers
         * like 5 from change-percent/version fields; this version validates every price
         * before saving it.
         */
        return [
            'usd'    => ['title'=>'دلار آمریکا', 'ids'=>[], 'slugs'=>['price_dollar_rl','price_dollar_azad','price_dollar'], 'queries'=>['دلار','دلار آزاد','dollar'] ],
            'eur'    => ['title'=>'یورو', 'ids'=>[], 'slugs'=>['price_eur','price_eur_rl'], 'queries'=>['یورو','euro','eur'] ],
            'gold18' => ['title'=>'طلای ۱۸ عیار', 'ids'=>[], 'slugs'=>['geram18','geram18_ayar','gold_18'], 'queries'=>['طلای 18 عیار','گرم طلای ۱۸','geram18'] ],
            'gold24' => ['title'=>'طلای ۲۴ عیار', 'ids'=>[], 'slugs'=>['geram24','gold_24'], 'queries'=>['طلای 24 عیار','گرم طلای ۲۴','geram24'] ],
            'silver' => ['title'=>'نقره', 'ids'=>[], 'slugs'=>['silver','ons_silver','silver_gram'], 'queries'=>['نقره','silver'] ],
            'btc'    => ['title'=>'Bitcoin', 'ids'=>['398096'], 'slugs'=>['crypto-bitcoin','bitcoin'], 'queries'=>['bitcoin','بیت کوین'] ],
            'eth'    => ['title'=>'Ethereum', 'ids'=>['398097'], 'slugs'=>['crypto-ethereum','ethereum'], 'queries'=>['ethereum','اتریوم'] ],
            'usdt'   => ['title'=>'Tether', 'ids'=>[], 'slugs'=>['crypto-tether','tether','usdt'], 'queries'=>['tether','usdt','تتر'] ],
            'bnb'    => ['title'=>'BNB', 'ids'=>['398115'], 'slugs'=>['crypto-binancecoin','crypto-binance-coin','binancecoin','bnb'], 'queries'=>['bnb','binance coin','بایننس'] ],
            'sol'    => ['title'=>'Solana', 'ids'=>['535605'], 'slugs'=>['crypto-solana','solana'], 'queries'=>['solana','سولانا'] ],
            'xrp'    => ['title'=>'XRP', 'ids'=>[], 'slugs'=>['crypto-ripple','ripple','xrp'], 'queries'=>['xrp','ripple','ریپل'] ],
            'doge'   => ['title'=>'Dogecoin', 'ids'=>[], 'slugs'=>['crypto-dogecoin','dogecoin'], 'queries'=>['dogecoin','doge','دوج'] ],
        ];
    }

    private function tgju_fetch_rates($targets) {
        $rates = [];

        // 1) Try widget/API ids where known. The returned JS/JSON is parsed conservatively.
        $ids = [];
        foreach ($targets as $target) {
            if (!empty($target['ids'])) $ids = array_merge($ids, $target['ids']);
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids) {
            $by_id = $this->tgju_fetch_by_widget_ids($ids);
            foreach ($targets as $key => $target) {
                foreach (($target['ids'] ?? []) as $id) {
                    if (isset($by_id[$id]) && (float)$by_id[$id] > 0) {
                        $rates[$key] = ['price'=>(float)$by_id[$id], 'source'=>'TGJU Widget/API'];
                        break;
                    }
                }
            }
        }

        // 2) Try profile pages. Strict patterns prefer the actual last-trade/price field.
        foreach ($targets as $key => $target) {
            if (isset($rates[$key])) continue;
            foreach (($target['slugs'] ?? []) as $slug) {
                $price = $this->tgju_fetch_profile_price($slug, $key);
                if ($price > 0) {
                    $rates[$key] = ['price'=>$price, 'source'=>'TGJU Profile: '.$slug];
                    break;
                }
            }
        }

        // 3) Try finder endpoint, then profile with the discovered key/id when available.
        foreach ($targets as $key => $target) {
            if (isset($rates[$key])) continue;
            foreach (($target['queries'] ?? []) as $q) {
                $found = $this->tgju_find_market_item($q);
                if (!empty($found['price'])) {
                    $rates[$key] = ['price'=>(float)$found['price'], 'source'=>'TGJU Finder: '.$q];
                    break;
                }
                if (!empty($found['slug'])) {
                    $price = $this->tgju_fetch_profile_price($found['slug'], $key);
                    if ($price > 0) {
                        $rates[$key] = ['price'=>$price, 'source'=>'TGJU Finder/Profile: '.$q];
                        break;
                    }
                }
                if (!empty($found['id'])) {
                    $by_id = $this->tgju_fetch_by_widget_ids([(string)$found['id']]);
                    if (!empty($by_id[(string)$found['id']])) {
                        $rates[$key] = ['price'=>(float)$by_id[(string)$found['id']], 'source'=>'TGJU Finder/Widget: '.$q];
                        break;
                    }
                }
            }
        }

        return $rates;
    }

    private function tgju_fetch_by_widget_ids($ids) {
        $out = [];
        $ids_csv = implode(',', array_map('sanitize_text_field', $ids));
        $urls = [
            'https://api.tgju.org/v1/market/indicator/summary-table-data?lang=fa&ids=' . rawurlencode($ids_csv),
            'https://api.tgju.org/v1/market/indicator/summary-table-data?lang=fa&items=' . rawurlencode($ids_csv),
            'https://api.accessban.com/v1/market/indicator/summary-table-data?lang=fa&ids=' . rawurlencode($ids_csv),
            'https://api.accessban.com/v1/widget/v2?type=market-data&items=' . rawurlencode($ids_csv) . '&columns=dot,diff,low,high,time&token=webservice',
            'https://api.tgju.org/v1/widget/v2?type=market-data&items=' . rawurlencode($ids_csv) . '&columns=dot,diff,low,high,time&token=webservice',
        ];
        foreach ($urls as $url) {
            $body = $this->remote_get_body($url);
            if (!$body) continue;
            $parsed = $this->extract_prices_from_json_or_text($body, $ids);
            foreach ($parsed as $id => $price) {
                if ($price > 0) $out[(string)$id] = (float)$price;
            }
            if (count($out) >= count($ids)) break;
        }
        return $out;
    }

    private function tgju_find_market_item($query) {
        $query = sanitize_text_field($query);
        if ($query === '') return [];
        $urls = [
            'https://api.tgju.org/v1/market/finder/list?search=' . rawurlencode($query),
            'https://api.tgju.org/v1/market/finder/list?q=' . rawurlencode($query),
            'https://api.accessban.com/v1/market/finder/list?search=' . rawurlencode($query),
        ];
        foreach ($urls as $url) {
            $body = $this->remote_get_body($url);
            if (!$body) continue;
            $json = json_decode($body, true);
            if (!is_array($json)) continue;
            $items = [];
            $walker = function($node) use (&$walker, &$items) {
                if (!is_array($node)) return;
                $has_identity = false;
                foreach (['id','key','slug','symbol','market_row','code'] as $k) {
                    if (isset($node[$k]) && is_scalar($node[$k])) { $has_identity = true; break; }
                }
                if ($has_identity) $items[] = $node;
                foreach ($node as $child) if (is_array($child)) $walker($child);
            };
            $walker($json);
            foreach ($items as $item) {
                $slug = '';
                foreach (['slug','key','symbol','market_row','code','name'] as $k) {
                    if (!empty($item[$k]) && is_scalar($item[$k])) { $slug = sanitize_title((string)$item[$k]); break; }
                }
                $id = '';
                foreach (['id','item_id','indicator_id','market_id'] as $k) {
                    if (!empty($item[$k]) && is_scalar($item[$k])) { $id = (string)$item[$k]; break; }
                }
                $price = 0;
                foreach (['price','last','value','p','amount','close','PDrCotVal','p_dr_cot_val'] as $pk) {
                    if (isset($item[$pk]) && is_scalar($item[$pk])) { $price = $this->parse_market_number((string)$item[$pk]); if ($price > 0) break; }
                }
                if ($slug || $id || $price > 0) return ['slug'=>$slug, 'id'=>$id, 'price'=>$price];
            }
        }
        return [];
    }

    private function tgju_fetch_profile_price($slug, $key='') {
        $slug = sanitize_title($slug);
        if (!$slug) return 0;
        $urls = [
            'https://www.tgju.org/profile/' . rawurlencode($slug),
            'https://api.tgju.org/v1/market/indicator/summary-table-data?lang=fa&symbols=' . rawurlencode($slug),
            'https://english.tgju.org/profile/' . rawurlencode($slug),
            'https://www.tgju.org/markets/' . rawurlencode($slug),
        ];
        foreach ($urls as $url) {
            $body = $this->remote_get_body($url);
            if (!$body) continue;
            $price = $this->extract_first_market_price($body, $key);
            if ($price > 0) return $price;
        }
        return 0;
    }

    private function remote_get_body($url) {
        $res = wp_remote_get($url, [
            'timeout'=>18,
            'redirection'=>3,
            'headers'=>[
                'Accept'=>'application/json,text/html;q=0.9,*/*;q=0.8',
                'User-Agent'=>'Mozilla/5.0 WordPress HamidPersonalAccounting/' . self::VERSION,
                'X-Client-Name'=>'main_site',
                'X-Client-Version'=>'1',
                'X-Client-SubSystem'=>'main_site',
                'Referer'=>home_url('/'),
            ],
        ]);
        if (is_wp_error($res)) return '';
        $code = (int)wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) return '';
        return (string)wp_remote_retrieve_body($res);
    }

    private function extract_prices_from_json_or_text($body, $ids=[]) {
        $out = [];
        $json = json_decode($body, true);
        if (is_array($json)) {
            $flat = $this->flatten_market_json($json);
            foreach ($ids as $id) {
                if (isset($flat[(string)$id]) && (float)$flat[(string)$id] > 0) $out[(string)$id] = (float)$flat[(string)$id];
            }
        }
        if (!$out && $ids) {
            foreach ($ids as $id) {
                $idq = preg_quote((string)$id, '/');
                $patterns = [
                    '/[\{,]\s*["\']?(?:id|item|item_id|indicator_id)["\']?\s*[:=]\s*["\']?'.$idq.'["\']?[\s\S]{0,900}?["\']?(?:PDrCotVal|p_dr_cot_val|last_price|last|price|value)["\']?\s*[:=]\s*["\']?([0-9۰-۹٬,\.]+)/iu',
                    '/["\']?'.$idq.'["\']?[\s\S]{0,700}?(?:class|data-col|id)=["\'][^"\']*(?:price|last|value|PDrCotVal)[^"\']*["\'][^>]*>\s*([0-9۰-۹٬,\.]+)/iu',
                ];
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $body, $m)) {
                        $price = $this->parse_market_number($m[1]);
                        if ($price > 0) { $out[(string)$id] = $price; break; }
                    }
                }
            }
        }
        return $out;
    }

    private function flatten_market_json($data) {
        $out = [];
        $walk = function($node) use (&$walk, &$out) {
            if (!is_array($node)) return;
            $id = '';
            foreach (['id','item','item_id','indicator_id','symbol_id','market_id'] as $k) {
                if (isset($node[$k]) && is_scalar($node[$k])) { $id = (string)$node[$k]; break; }
            }
            if ($id !== '') {
                foreach (['PDrCotVal','p_dr_cot_val','last_price','last','price','value','amount','close','p'] as $pk) {
                    if (isset($node[$pk]) && is_scalar($node[$pk])) {
                        $price = $this->parse_market_number((string)$node[$pk]);
                        if ($price > 0) { $out[$id] = $price; break; }
                    }
                }
            }
            foreach ($node as $child) if (is_array($child)) $walk($child);
        };
        $walk($data);
        return $out;
    }

    private function extract_first_market_price($body, $key='') {
        $json = json_decode($body, true);
        $candidates = [];
        if (is_array($json)) {
            $flat = $this->flatten_market_json($json);
            foreach ($flat as $price) if ($price > 0) $candidates[] = $price;
            $this->collect_json_price_candidates($json, $candidates);
        }

        // Prefer TGJU actual last-trade columns. Avoid loose "diff", "low", "high" and percent fields.
        $patterns = [
            '/data-col=["\'][^"\']*(?:PDrCotVal|p_dr_cot_val|last_trade|last|price)[^"\']*["\'][^>]*>\s*([0-9۰-۹٬,\.]+)\s*</iu',
            '/(?:id|class)=["\'][^"\']*(?:PDrCotVal|last-price|last_price|info-price|market-price|price)[^"\']*["\'][^>]*>\s*([0-9۰-۹٬,\.]+)\s*</iu',
            '/["\'](?:PDrCotVal|p_dr_cot_val|last_price|last|price)["\']\s*:\s*["\']?([0-9۰-۹٬,\.]+)/iu',
            '/(?:data-price|data-last|data-value)=["\']([0-9۰-۹٬,\.]+)["\']/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $body, $matches)) {
                foreach ($matches[1] as $raw) {
                    $price = $this->parse_market_number($raw);
                    if ($price > 0) $candidates[] = $price;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($this->tgju_raw_candidate_is_plausible($key, (float)$candidate)) return (float)$candidate;
        }
        return 0;
    }

    private function collect_json_price_candidates($node, &$candidates) {
        if (!is_array($node)) return;
        foreach (['PDrCotVal','p_dr_cot_val','last_price','last','price','value','close'] as $pk) {
            if (isset($node[$pk]) && is_scalar($node[$pk])) {
                $price = $this->parse_market_number((string)$node[$pk]);
                if ($price > 0) $candidates[] = $price;
            }
        }
        foreach ($node as $child) if (is_array($child)) $this->collect_json_price_candidates($child, $candidates);
    }

    private function tgju_raw_candidate_is_plausible($key, $raw) {
        if ($raw <= 0) return false;
        // Reject tiny numbers that are usually diff/percent/row/version values.
        $min = [
            'usd'=>10000, 'eur'=>10000, 'usdt'=>10000,
            'gold18'=>100000, 'gold24'=>100000, 'silver'=>500,
            'btc'=>1000, 'eth'=>100, 'bnb'=>10, 'sol'=>1, 'xrp'=>0.01, 'doge'=>0.001,
        ];
        if (isset($min[$key]) && $raw < $min[$key]) return false;
        return true;
    }

    private function normalize_tgju_price_to_toman($key, $raw, $all_raw=[]) {
        $raw = (float)$raw;
        if ($raw <= 0) return 0;

        // TGJU Iran market profiles are commonly in Rial. Store in Toman.
        if (in_array($key, ['usd','eur','usdt'], true)) {
            if ($raw >= 300000) $raw = $raw / 10;
        } elseif (in_array($key, ['gold18','gold24'], true)) {
            if ($raw >= 20000000) $raw = $raw / 10;
        } elseif ($key === 'silver') {
            if ($raw >= 500000) $raw = $raw / 10;
        } elseif (in_array($key, ['btc','eth','bnb','sol','xrp','doge'], true)) {
            $usdt = 0;
            if (!empty($all_raw['usdt']['price'])) $usdt = $this->normalize_tgju_price_to_toman('usdt', (float)$all_raw['usdt']['price'], []);
            elseif (!empty($all_raw['usd']['price'])) $usdt = $this->normalize_tgju_price_to_toman('usd', (float)$all_raw['usd']['price'], []);

            // If crypto is returned as USD quote, convert it to Toman using USDT/USD rate.
            if ($usdt > 0 && $raw < 1000000) {
                $raw = $raw * $usdt;
            } elseif ($raw >= 1000000000) {
                // If it looks like an IRR quote, convert to Toman.
                $raw = $raw / 10;
            }
        }
        return round($raw, 2);
    }

    private function tgju_price_is_valid_for_key($key, $price_toman) {
        $price_toman = (float)$price_toman;
        $ranges = [
            'usd'=>[10000, 3000000], 'eur'=>[10000, 4000000], 'usdt'=>[10000, 3000000],
            'gold18'=>[100000, 200000000], 'gold24'=>[100000, 250000000], 'silver'=>[500, 50000000],
            'btc'=>[10000000, 100000000000], 'eth'=>[1000000, 10000000000], 'bnb'=>[100000, 5000000000],
            'sol'=>[10000, 2000000000], 'xrp'=>[100, 500000000], 'doge'=>[10, 100000000],
        ];
        if (!isset($ranges[$key])) return $price_toman > 0;
        return $price_toman >= $ranges[$key][0] && $price_toman <= $ranges[$key][1];
    }

    private function parse_market_number($value) {
        $value = html_entity_decode((string)$value, ENT_QUOTES, 'UTF-8');
        $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩','٬','،'];
        $en = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9',',',','];
        $value = str_replace($fa, $en, $value);
        $value = preg_replace('/[^0-9.,]/', '', $value);
        if ($value === '') return 0;
        if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
            // Treat comma as thousands separator in Persian market data.
            $value = str_replace(',', '', $value);
        } else {
            $value = str_replace(',', '', $value);
        }
        if (substr_count($value, '.') > 1) $value = str_replace('.', '', $value);
        return (float)$value;
    }

    private function calculate_balances() {
        // کش در حافظه برای جلوگیری از محاسبه مجدد در یک request
        static $cached_balances = null;
        if ( $cached_balances !== null ) return $cached_balances;
        global $wpdb; $balances=[]; $currencies=[];
        $account_persons=[];
        foreach($this->get_accounts() as $a) { $balances[$a->id]=(float)$a->opening_balance; $currencies[$a->id]=$a->currency; $account_persons[$a->id]=$a->person_key ?? 'hamidreza'; }
        $rows=$wpdb->get_results("SELECT account_id,to_account_id,type,amount,fee_amount,currency,person_key,from_person_key,to_person_key FROM {$this->tables['transactions']} WHERE status!='cancelled'");
        foreach($rows as $r){
            if (!isset($balances[$r->account_id])) $balances[$r->account_id]=0;
            $source_currency = $currencies[$r->account_id] ?? ($r->currency ?: 'toman');
            $source_amount = $this->convert_currency($r->amount, $r->currency ?: $source_currency, $source_currency);
            $fee_amount = $this->convert_currency(isset($r->fee_amount) ? $r->fee_amount : 0, $r->currency ?: $source_currency, $source_currency);
            if ($r->type==='income' || $r->type==='asset_sell' || $r->type==='receivable_settlement' || $r->type==='debt_incur') {
                $balances[$r->account_id] += $source_amount;
            } elseif ($r->type==='transfer') {
                $balances[$r->account_id] -= ($source_amount + $fee_amount);
                if($r->to_account_id){
                    if(!isset($balances[$r->to_account_id])) $balances[$r->to_account_id]=0;
                    $dest_currency = $currencies[$r->to_account_id] ?? ($r->currency ?: 'toman');
                    $balances[$r->to_account_id] += $this->convert_currency($r->amount, $r->currency ?: $source_currency, $dest_currency);
                }
            } elseif ($r->type==='person_transfer') {
                // انتقال بین اشخاص درآمد/هزینه نیست. فقط موجودی حساب مرتبط را بر اساس مبدأ/مقصد حمیدرضا کم یا زیاد می‌کند.
                $from_person = $r->from_person_key ?: ($r->person_key ?: 'hamidreza');
                $to_person = $r->to_person_key ?: 'samira';
                if ($from_person === 'hamidreza' && $to_person !== 'hamidreza') {
                    $balances[$r->account_id] -= ($source_amount + $fee_amount);
                } elseif ($to_person === 'hamidreza' && $from_person !== 'hamidreza') {
                    $balances[$r->account_id] += $source_amount;
                    if ($fee_amount > 0) $balances[$r->account_id] -= $fee_amount;
                }
            } else {
                $balances[$r->account_id] -= $source_amount;
            }
        }
        $cached_balances = $balances;
        return $balances;
    }


    private function apply_transaction_to_balance($balance, $currency, $tx) {
        $source_amount = $this->convert_currency($tx->amount ?? 0, $tx->currency ?: $currency, $currency);
        $fee_amount = $this->convert_currency($tx->fee_amount ?? 0, $tx->currency ?: $currency, $currency);
        $type = $tx->type ?? '';
        if (in_array($type, ['income','asset_sell','receivable_settlement','debt_incur'], true)) return $balance + $source_amount;
        if ($type === 'transfer') return $balance - $source_amount - $fee_amount;
        if ($type === 'person_transfer') {
            $from = $tx->from_person_key ?: ($tx->person_key ?: 'hamidreza');
            $to = $tx->to_person_key ?: 'samira';
            if ($from === 'hamidreza' && $to !== 'hamidreza') return $balance - $source_amount - $fee_amount;
            if ($to === 'hamidreza' && $from !== 'hamidreza') return $balance + $source_amount - $fee_amount;
            return $balance;
        }
        return $balance - $source_amount;
    }

    private function account_balance_after_transaction($tx) {
        global $wpdb;
        if (empty($tx->account_id)) return null;
        $acc = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tables['accounts']} WHERE id=%d", (int)$tx->account_id));
        if (!$acc) return null;
        $balance = (float)$acc->opening_balance;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->tables['transactions']} WHERE status!='cancelled' AND account_id=%d AND (gregorian_date < %s OR (gregorian_date=%s AND id<=%d)) ORDER BY gregorian_date ASC, id ASC", (int)$tx->account_id, $tx->gregorian_date, $tx->gregorian_date, (int)$tx->id));
        foreach($rows as $r) $balance = $this->apply_transaction_to_balance($balance, $acc->currency, $r);
        return ['account'=>$acc->name, 'balance'=>$balance, 'currency'=>$acc->currency];
    }

    private function expense_chart($legend=false, $current_month_only=false, $percent_list=false) {
        global $wpdb;
        $where = "t.type IN ('expense','recurring_debt') AND t.status!='cancelled'";
        if ($current_month_only) {
            $range = $this->current_jalali_month_gregorian_range();
            $where .= $wpdb->prepare(' AND t.gregorian_date BETWEEN %s AND %s', $range[0], $range[1]);
        }
        // hide_amount هم لود می‌شود تا بتوانیم در کارت‌های دسته‌بندی رعایت کنیم
        $rows=$wpdb->get_results("SELECT t.amount, t.currency, c.name, c.icon, c.color, t.category_id, t.hide_amount FROM {$this->tables['transactions']} t LEFT JOIN {$this->tables['categories']} c ON c.id=t.category_id WHERE $where ORDER BY t.gregorian_date DESC LIMIT 500");
        if (!$rows) return '<p class="hpa-muted">برای ساخت نمودار، چند هزینه ثبت کن.</p>';
        $groups=[];
        foreach($rows as $r){
            $key = (string)($r->category_id ?: '0');
            if (!isset($groups[$key])) $groups[$key] = ['name'=>$r->name ?: 'بدون موضوع','icon'=>$r->icon ?: '📌','color'=>$r->color ?: '#e0e7ff','total'=>0,'hidden_count'=>0,'visible_total'=>0];
            $toman = $this->amount_to_toman($r->amount, $r->currency);
            $groups[$key]['total'] += $toman;
            if (!empty($r->hide_amount)) {
                $groups[$key]['hidden_count']++;
            } else {
                $groups[$key]['visible_total'] += $toman;
            }
        }
        usort($groups, function($a,$b){ return $b['total'] <=> $a['total']; });
        $all_sum = array_sum(array_map(function($r){ return (float)$r['total']; }, $groups));
        $chart_groups = array_slice($groups, 0, 8);
        $list_groups = array_slice($groups, 0, 5);
        $chart_sum = array_sum(array_map(function($r){ return (float)$r['total']; }, $chart_groups));
        $bars=''; $leg=''; $shares='';
        foreach($chart_groups as $r){
            $chart_pct = $chart_sum ? ((float)$r['total']/$chart_sum)*100 : 0;
            $w = $chart_sum ? max(4, round($chart_pct)) : 0;
            $bars .= '<div class="hpa-bar" style="width:'.$w.'%;background:'.esc_attr($r['color']).'" title="'.esc_attr($r['name']).'"></div>';
            $leg.='<div class="hpa-list-row"><span class="hpa-badge" style="background:'.esc_attr($r['color']).'">'.esc_html($r['icon']).'</span><b>'.esc_html($r['name']).'</b><em>'.esc_html($this->fmt_money($r['total'],'toman')).'</em></div>';
        }
        foreach($list_groups as $r){
            $pct = $all_sum ? ((float)$r['total']/$all_sum)*100 : 0;
            // ساخت رشته مبلغ: اگر پنهان داشت: visible+***
            if ($r['hidden_count'] > 0 && $r['visible_total'] > 0) {
                $total_str = esc_html($this->fmt_money($r['visible_total'],'toman')).' + <span class="hpa-cat-hidden-star">***</span>';
            } elseif ($r['hidden_count'] > 0) {
                $total_str = '<span class="hpa-cat-hidden-star">***</span>';
            } else {
                $total_str = esc_html($this->fmt_money($r['total'],'toman'));
            }
            $shares.='<details class="hpa-expense-share-row" style="background:'.esc_attr($r['color']).'"><summary><b><span>'.esc_html($r['icon']).'</span>'.esc_html($r['name']).'</b><em>'.esc_html(number_format_i18n(round($pct,1),1)).'%</em></summary><div class="hpa-expense-share-detail"><small>'.$total_str.'</small></div></details>';
        }
        return '<div class="hpa-chart-stack">'.$bars.'</div>'.($percent_list?'<div class="hpa-expense-share-list">'.$shares.'</div>':'').($legend?$leg:'');
    }
    private function monthly_svg_chart() {
        global $wpdb; $data=[];
        $months = [1=>'فروردین',2=>'اردیبهشت',3=>'خرداد',4=>'تیر',5=>'مرداد',6=>'شهریور',7=>'مهر',8=>'آبان',9=>'آذر',10=>'دی',11=>'بهمن',12=>'اسفند'];
        $today_parts = explode('/', $this->today_jalali());
        $jy = (int)($today_parts[0] ?? 1403); $jm = (int)($today_parts[1] ?? 1);
        for($i=5;$i>=0;$i--){
            $m = $jm - $i; $y = $jy;
            while($m <= 0){ $m += 12; $y--; }
            $last = ($m <= 6) ? 31 : (($m <= 11) ? 30 : 29);
            $jstart = sprintf('%04d/%02d/01', $y, $m);
            $jend = sprintf('%04d/%02d/%02d', $y, $m, $last);
            $start = $this->jalali_to_gregorian_date($jstart); $end = $this->jalali_to_gregorian_date($jend);
            $inc_rows=$wpdb->get_results($wpdb->prepare("SELECT amount,currency FROM {$this->tables['transactions']} WHERE type='income' AND status!='cancelled' AND gregorian_date BETWEEN %s AND %s",$start,$end));
            $exp_rows=$wpdb->get_results($wpdb->prepare("SELECT amount,currency FROM {$this->tables['transactions']} WHERE type IN ('expense','recurring_debt') AND status!='cancelled' AND gregorian_date BETWEEN %s AND %s",$start,$end));
            $data[]=[$months[$m] ?? (string)$m,$this->rows_sum_toman($inc_rows),$this->rows_sum_toman($exp_rows)];
        }
        $max=max(1, ...array_map(function($d){ return max($d[1],$d[2]); },$data));
        $svg='<svg class="hpa-svg" viewBox="0 0 720 330" role="img" aria-label="درآمد و هزینه شش ماه اخیر">';
        $svg.='<line x1="76" y1="250" x2="690" y2="250" class="hpa-svg-axis"/><line x1="76" y1="42" x2="76" y2="250" class="hpa-svg-axis hpa-svg-axis-y"/>';
        for($g=0;$g<=4;$g++){ $y=250-($g*52); $val=round(($max/4)*$g); $svg.='<line x1="76" y1="'.$y.'" x2="690" y2="'.$y.'" class="hpa-svg-grid"/><text x="12" y="'.($y+5).'" class="hpa-svg-label hpa-svg-y-label">'.esc_html(number_format_i18n($val/1000000,1)).' م</text>'; }
        $x=104;
        foreach($data as $d){
            $hi=round(($d[1]/$max)*190); $he=round(($d[2]/$max)*190);
            $svg.='<rect x="'.$x.'" y="'.(250-$hi).'" width="24" height="'.$hi.'" rx="6" class="hpa-svg-income"/>';
            $svg.='<rect x="'.($x+32).'" y="'.(250-$he).'" width="24" height="'.$he.'" rx="6" class="hpa-svg-expense"/>';
            $svg.='<text x="'.($x-6).'" y="287" class="hpa-svg-label hpa-svg-month">'.esc_html($d[0]).'</text>';
            $x+=98;
        }
        return $svg.'<text x="76" y="24" class="hpa-svg-label">سبز: درآمد | بنفش: هزینه — محور عمودی: میلیون تومان</text></svg>';
    }

    private function delete_button($action,$id,$tab) {
        $url = wp_nonce_url(admin_url('admin-post.php?action='.$action.'&id='.$id), self::NONCE, 'hpa_nonce');
        return '<a class="hpa-delete" onclick="return confirm(\'حذف شود؟\')" href="'.esc_url($url).'">حذف</a>';
    }



    public function register_app_api() {
        add_filter('rest_pre_serve_request', [$this, 'app_cors_headers'], 15, 1);
        register_rest_route('hpa/v1', '/login', [
            'methods' => 'POST',
            'callback' => [$this, 'api_login'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route('hpa/v1', '/pull', [
            'methods' => 'GET',
            'callback' => [$this, 'api_pull'],
            'permission_callback' => [$this, 'api_permission'],
        ]);
        register_rest_route('hpa/v1', '/push', [
            'methods' => 'POST',
            'callback' => [$this, 'api_push'],
            'permission_callback' => [$this, 'api_permission'],
        ]);
        register_rest_route('hpa/v1', '/sync', [
            'methods' => 'POST',
            'callback' => [$this, 'api_sync'],
            'permission_callback' => [$this, 'api_permission'],
        ]);
        register_rest_route('hpa/v1', '/ping', [
            'methods' => 'GET',
            'callback' => function(){ return ['ok'=>true,'version'=>self::VERSION,'time'=>current_time('mysql')]; },
            'permission_callback' => '__return_true',
        ]);
    }

    private function app_sync_enabled() {
        $settings = get_option(self::OPTION, []);
        return !empty($settings['app_sync_enabled']);
    }

    // اجازهٔ فراخوانی API از نرم‌افزار دسکتاپ (مبدأ محلی) — فقط برای مسیرهای hpa/v1.
    public function app_cors_headers($served) {
        $route = isset($GLOBALS['wp']->query_vars['rest_route']) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';
        if (strpos($route, '/hpa/v1/') === false) return $served;
        $origin = get_http_origin();
        if ($origin) {
            header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            header('Vary: Origin', false);
        } else {
            header('Access-Control-Allow-Origin: *');
        }
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Credentials: true');
        return $served;
    }

    public function api_login(WP_REST_Request $request) {
        if (!$this->app_sync_enabled()) return new WP_Error('hpa_api_disabled', 'اتصال اپ در تنظیمات افزونه فعال نیست.', ['status'=>403]);
        $username = sanitize_user((string)$request->get_param('username'));
        $password = (string)$request->get_param('password');
        $user = wp_authenticate($username, $password);
        if (is_wp_error($user)) return new WP_Error('hpa_login_failed', 'نام کاربری یا رمز عبور سایت درست نیست.', ['status'=>401]);
        if ( strtolower( trim( $user->user_email ) ) !== strtolower( self::AUTHORIZED_EMAIL ) ) return new WP_Error('hpa_forbidden', 'دسترسی مجاز نیست.', array('status'=>403));
        $token = wp_generate_password(48, false, false);
        // Store a plain SHA-256 hash of the token (deterministic — not affected by the
        // WP 6.8 bcrypt migration), and keep a list so the desktop app AND the phone can
        // each stay connected instead of overwriting one another's single token.
        $hash = hash('sha256', $token);
        $tokens = get_user_meta($user->ID, '_hpa_app_tokens', true);
        if (!is_array($tokens)) $tokens = [];
        $tokens[] = $hash;
        $tokens = array_slice(array_values(array_unique($tokens)), -6); // keep the 6 most recent devices
        update_user_meta($user->ID, '_hpa_app_tokens', $tokens);
        update_user_meta($user->ID, '_hpa_app_token_created', current_time('mysql'));
        return [
            'ok'=>true,
            'token'=>$token,
            'user'=>['id'=>(int)$user->ID,'display_name'=>$user->display_name,'email'=>$user->user_email],
            'base_url'=>rest_url('hpa/v1/'),
            'plugin_version'=>self::VERSION,
            'server_time'=>current_time('mysql'),
        ];
    }

    public function api_permission(WP_REST_Request $request) {
        if (!$this->app_sync_enabled()) return new WP_Error('hpa_api_disabled', 'اتصال اپ در تنظیمات افزونه فعال نیست.', ['status'=>403]);
        $auth = (string)$request->get_header('authorization');
        if (!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) $auth = (string)$_SERVER['HTTP_AUTHORIZATION'];
        if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        $token = '';
        if (preg_match('/Bearer\s+(.+)/i', $auth, $m)) $token = trim($m[1]);
        if ($token === '') $token = trim((string)$request->get_param('hpa_app_token')); // fallback if the server strips Authorization
        if ($token === '') return new WP_Error('hpa_no_token', 'توکن اپ ارسال نشده است.', ['status'=>401]);
        $hash = hash('sha256', $token);
        // migrate legacy single-hash meta so already-connected devices don't break on update
        $users = get_users(['meta_key'=>'_hpa_app_tokens','number'=>50,'fields'=>['ID']]);
        if (empty($users)) $users = get_users(['meta_key'=>'_hpa_app_token_hash','number'=>50,'fields'=>['ID']]);
        foreach($users as $u) {
            $tokens = get_user_meta($u->ID, '_hpa_app_tokens', true);
            $ok = is_array($tokens) && in_array($hash, $tokens, true);
            if (!$ok) { // legacy fallback (tokens issued before this version)
                $legacy = (string)get_user_meta($u->ID, '_hpa_app_token_hash', true);
                $ok = $legacy && wp_check_password($token, $legacy, $u->ID);
            }
            if ($ok) {
                $perm_user = get_userdata($u->ID);
                if ( !$perm_user || strtolower(trim($perm_user->user_email)) !== strtolower(self::AUTHORIZED_EMAIL) ) {
                    return new WP_Error('hpa_forbidden', 'دسترسی مجاز نیست.', array('status'=>403));
                }
                wp_set_current_user($u->ID);
                return true;
            }
        }
        return new WP_Error('hpa_bad_token', 'توکن اپ معتبر نیست.', ['status'=>401]);
    }

    private function api_sync_tables() {
        return ['accounts','categories','transactions','transaction_items','transaction_splits','debts','receivables','assets','rates','loans','loan_installments','checks','recurring','attachments','goals','deleted_items'];
    }

    private function api_rows_for_table($key) {
        global $wpdb;
        if (empty($this->tables[$key])) return [];
        $table = $this->tables[$key];
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if (!$exists) return [];
        return $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A) ?: [];
    }

    public function api_pull(WP_REST_Request $request) {
        $out = ['ok'=>true,'version'=>self::VERSION,'server_time'=>current_time('mysql'),'tables'=>[]];
        foreach($this->api_sync_tables() as $key) $out['tables'][$key] = $this->api_rows_for_table($key);
        return $out;
    }

    public function api_push(WP_REST_Request $request) {
        global $wpdb;
        $payload = $request->get_json_params();
        if (!is_array($payload)) $payload = [];
        $tables = isset($payload['tables']) && is_array($payload['tables']) ? $payload['tables'] : [];
        $changed = [];
        foreach($this->api_sync_tables() as $key) {
            if (empty($tables[$key]) || !is_array($tables[$key]) || empty($this->tables[$key])) continue;
            $table = $this->tables[$key];
            $changed[$key] = 0;
            foreach($tables[$key] as $row) {
                if (!is_array($row)) continue;
                $clean = [];
                foreach($row as $col=>$val) {
                    $col = sanitize_key((string)$col);
                    if ($col === '') continue;
                    $clean[$col] = is_scalar($val) || is_null($val) ? wp_kses_post((string)$val) : wp_json_encode($val, JSON_UNESCAPED_UNICODE);
                }
                if (!$clean) continue;
                if (!empty($clean['id'])) {
                    $id = absint($clean['id']);
                    $exists = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM `$table` WHERE id=%d", $id));
                    if ($exists) { $wpdb->update($table, $clean, ['id'=>$id]); $changed[$key]++; }
                    else { $wpdb->insert($table, $clean); $changed[$key]++; }
                } else {
                    unset($clean['id']);
                    $wpdb->insert($table, $clean); $changed[$key]++;
                }
            }
        }
        return ['ok'=>true,'changed'=>$changed,'server_time'=>current_time('mysql')];
    }

    public function api_sync(WP_REST_Request $request) {
        $push = $this->api_push($request);
        if (is_wp_error($push)) return $push;
        $pull = $this->api_pull($request);
        if (is_array($pull)) $pull['push_result'] = $push;
        return $pull;
    }

    private function current_jalali_month_gregorian_range() {
        $today = $this->today_jalali();
        $parts = explode('/', $today);
        $jy = isset($parts[0]) ? (int)$parts[0] : 1403;
        $jm = isset($parts[1]) ? (int)$parts[1] : 1;
        $last = ($jm <= 6) ? 31 : (($jm <= 11) ? 30 : 29);
        $start = sprintf('%04d/%02d/01', $jy, $jm);
        $end = sprintf('%04d/%02d/%02d', $jy, $jm, $last);
        return [$this->jalali_to_gregorian_date($start), $this->jalali_to_gregorian_date($end)];
    }

    private function gregorian_to_jalali_date($gdate) {
        $ts = strtotime($gdate ?: 'now');
        [$jy,$jm,$jd] = $this->gregorian_to_jalali((int)date('Y',$ts),(int)date('m',$ts),(int)date('d',$ts));
        return sprintf('%04d/%02d/%02d',$jy,$jm,$jd);
    }
    private function gregorian_to_jalali($gy,$gm,$gd) {
        $g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334];
        $gy2=($gm>2)?($gy+1):$gy;
        $days=355666+(365*$gy)+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$g_d_m[$gm-1];
        $jy=-1595+(33*intdiv($days,12053)); $days%=12053;
        $jy+=4*intdiv($days,1461); $days%=1461;
        if($days>365){ $jy+=intdiv($days-1,365); $days=($days-1)%365; }
        if($days<186){ $jm=1+intdiv($days,31); $jd=1+($days%31); }
        else { $jm=7+intdiv($days-186,30); $jd=1+(($days-186)%30); }
        return [$jy,$jm,$jd];
    }
    private function jalali_to_gregorian_date($jalali) {
        $jalali = preg_replace('/[^0-9\/\-]/','',$jalali);
        $parts = preg_split('/[\/\-]/',$jalali);
        if (count($parts) < 3) return date('Y-m-d');
        [$jy,$jm,$jd] = array_map('intval',$parts);
        [$gy,$gm,$gd] = $this->jalali_to_gregorian($jy,$jm,$jd);
        return sprintf('%04d-%02d-%02d',$gy,$gm,$gd);
    }
    private function jalali_to_gregorian($jy,$jm,$jd) {
        $jy += 1595; $days = -355668 + (365*$jy) + intdiv($jy,33)*8 + intdiv(($jy%33+3),4) + $jd + (($jm<7)?(($jm-1)*31):((($jm-7)*30)+186));
        $gy = 400 * intdiv($days,146097); $days %= 146097;
        if ($days > 36524) { $gy += 100 * intdiv(--$days,36524); $days %= 36524; if ($days >= 365) $days++; }
        $gy += 4 * intdiv($days,1461); $days %= 1461;
        if ($days > 365) { $gy += intdiv($days-1,365); $days = ($days-1)%365; }
        $gd = $days + 1; $sal_a = [0,31,(($gy%4==0 && $gy%100!=0)||($gy%400==0))?29:28,31,30,31,30,31,31,30,31,30,31];
        for($gm=1; $gm<=12 && $gd>$sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
        return [$gy,$gm,$gd];
    }
}

Hamid_Personal_Accounting::instance();
register_activation_hook(__FILE__, ['Hamid_Personal_Accounting','activate']);
register_deactivation_hook(__FILE__, ['Hamid_Personal_Accounting','deactivate']);
