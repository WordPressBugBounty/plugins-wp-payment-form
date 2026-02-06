<?php

namespace WPPayForm\Database\Migrations;

class MigrateHelper
{
    public static function runForceSQL($sql, $tableName)
    {
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        return true;
    }

    public static function runSQL($sql, $tableName)
	{
		global $wpdb;

		// Use $wpdb->prepare to safely insert $tableName
		$table_check = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $tableName ) );

		if ( $table_check != $tableName ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );
			return true;
		}

		return false;
	}
}
