<?php

namespace WPPayForm\Database\Migrations;

class SubmissionsTable
{
    public static function migrate()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        $table_name = $wpdb->prefix . 'wpf_submissions';

        $sql = "CREATE TABLE $table_name (
			id int(11) NOT NULL AUTO_INCREMENT,
			form_id int(11) NOT NULL,
			user_id int(11) DEFAULT NULL,
			customer_id varchar(255),
			customer_name varchar(255),
			customer_email varchar(255),
			form_data_raw longtext,
			form_data_formatted longtext,
			currency varchar(255),
			payment_status varchar(255),
			submission_hash varchar (255),
			payment_total int(11),
			payment_mode varchar(255),
			payment_method varchar(255),
			status varchar(255),
			ip_address varchar (45),
			browser varchar(45),
			device varchar(45),
			city varchar(45),
			country varchar(45),
			created_at timestamp NULL,
			updated_at timestamp NULL,
			PRIMARY  KEY  (id),
			KEY idx_form_id (form_id),
			KEY idx_customer_email (customer_email),
			KEY idx_payment_status (payment_status),
			KEY idx_status (status),
			KEY idx_created_at (created_at),
			KEY idx_submission_hash (submission_hash),
			KEY idx_form_payment_status (form_id, payment_status)
		) $charset_collate;";

        dbDelta($sql);
    }
}
