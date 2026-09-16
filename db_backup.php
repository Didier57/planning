<?php

/**
 * Database Backup / Restore (administrators only).
 *
 *  - "Backup"  streams a full SQL dump of all webcal_* tables as a download.
 *  - "Restore" posts to import_sql.php which performs the actual restore.
 */

require_once 'includes/init.php';

if ( ! $is_admin )
  die_miserable_death ( print_not_auth() );

/* Escape a value for a single-quoted MySQL string literal.  dbi_escape_string()
 * is NOT used here because it calls stripslashes() first, which would corrupt
 * legitimate backslashes in event text. */
function wcbk_escape ( $value ) {
  return str_replace (
    ["\\", "\0", "'", "\"", "\n", "\r", "\x1a"],
    ["\\\\", "\\0", "\\'", "\\\"", "\\n", "\\r", "\\Z"], $value );
}

function wcbk_connection_charset () {
  if ( ! empty ( $GLOBALS['db_connection_info']['connection'] )
      && method_exists ( $GLOBALS['db_connection_info']['connection'],
        'character_set_name' ) )
    return $GLOBALS['db_connection_info']['connection']->character_set_name ();
  return 'utf8';
}

/* Fallback list of tables (in case SHOW TABLES is unavailable). */
function wcbk_table_list () {
  $tables = [];
  $res = dbi_execute ( 'SHOW TABLES', [], false, false );
  if ( $res ) {
    while ( $row = dbi_fetch_row ( $res ) ) {
      if ( preg_match ( '/^webcal_/i', $row[0] ) )
        $tables[] = $row[0];
    }
    dbi_free_result ( $res );
  }
  if ( empty ( $tables ) ) {
    $tables = [
      'webcal_user', 'webcal_entry', 'webcal_entry_categories',
      'webcal_entry_repeats', 'webcal_entry_repeats_not', 'webcal_entry_user',
      'webcal_entry_ext_user', 'webcal_user_pref', 'webcal_user_layers',
      'webcal_site_extras', 'webcal_reminders', 'webcal_group',
      'webcal_group_user', 'webcal_view', 'webcal_view_user', 'webcal_config',
      'webcal_entry_log', 'webcal_categories', 'webcal_asst',
      'webcal_nonuser_cals', 'webcal_import', 'webcal_import_data',
      'webcal_report', 'webcal_report_template', 'webcal_access_user',
      'webcal_access_function', 'webcal_user_template', 'webcal_blob',
      'webcal_timezones'
    ];
  }
  sort ( $tables );
  return $tables;
}

/* ------------------------------------------------------------------ */
/* Backup: stream a full SQL dump as a download.                       */
/* ------------------------------------------------------------------ */
if ( getPostValue ( 'backup' ) == '1' ) {
  set_time_limit ( 0 );

  while ( ob_get_level () )
    ob_end_clean ();

  $tables = wcbk_table_list ();
  $charset = wcbk_connection_charset ();
  $host = empty ( $GLOBALS['db_host'] ) ? 'localhost' : $GLOBALS['db_host'];
  $host = preg_replace ( '/[^A-Za-z0-9._-]/', '_', $host );
  $filename = 'webcalendar-backup-' . $host . '-' . gmdate ( 'Ymd_His' ) . '.sql';

  header ( 'Content-Type: application/sql; charset=UTF-8' );
  header ( 'Content-Disposition: attachment; filename="' . $filename . '"' );
  header ( 'Pragma: private' );
  header ( 'Cache-control: private, must-revalidate' );

  echo "-- WebCalendar database backup\n"
    . '-- Generated: ' . gmdate ( 'Y-m-d H:i:s' ) . " UTC\n"
    . '-- Database: ' . $GLOBALS['db_database'] . "\n"
    . '-- Tables: ' . count ( $tables ) . "\n\n"
    . 'SET NAMES ' . $charset . ";\n"
    . "SET FOREIGN_KEY_CHECKS = 0;\n\n";
  flush ();

  foreach ( $tables as $table ) {
    $res = dbi_execute ( 'SHOW CREATE TABLE `' . $table . '`', [], false, false );
    if ( ! $res )
      continue;
    $row = dbi_fetch_row ( $res );
    $create = isset ( $row[1] ) ? $row[1] : '';
    dbi_free_result ( $res );
    if ( $create == '' )
      continue;

    $cols = [];
    $bin = [];
    $res = dbi_execute ( 'SHOW COLUMNS FROM `' . $table . '`', [], false, false );
    if ( $res ) {
      while ( $row = dbi_fetch_row ( $res ) ) {
        $cols[] = $row[0];
        $type = strtolower ( isset ( $row[1] ) ? $row[1] : '' );
        if ( strpos ( $type, 'blob' ) !== false
            || strpos ( $type, 'binary' ) !== false )
          $bin[$row[0]] = true;
      }
      dbi_free_result ( $res );
    }
    if ( empty ( $cols ) )
      continue;

    echo "--\n-- Table structure for table `$table`\n--\n"
      . "DROP TABLE IF EXISTS `$table`;\n"
      . $create . ";\n\n";

    $colList = '`' . implode ( '`, `', $cols ) . '`';
    $res = dbi_execute ( 'SELECT ' . $colList . ' FROM `' . $table . '`',
      [], false, false );
    if ( ! $res )
      continue;

    $batch = [];
    $started = false;
    while ( $row = dbi_fetch_row ( $res ) ) {
      $vals = [];
      for ( $i = 0, $n = count ( $cols ); $i < $n; $i++ ) {
        $v = $row[$i];
        if ( $v === null )
          $vals[] = 'NULL';
        elseif ( ! empty ( $bin[$cols[$i]] ) )
          $vals[] = '0x' . bin2hex ( $v );
        else
          $vals[] = "'" . wcbk_escape ( $v ) . "'";
      }
      $batch[] = '(' . implode ( ', ', $vals ) . ')';
      if ( count ( $batch ) >= 100 ) {
        if ( ! $started ) {
          echo "--\n-- Dumping data for table `$table`\n--\n";
          $started = true;
        }
        echo 'INSERT INTO `' . $table . '` (' . $colList . ") VALUES\n"
          . implode ( ",\n", $batch ) . ";\n";
        $batch = [];
        flush ();
      }
    }
    if ( ! empty ( $batch ) ) {
      if ( ! $started ) {
        echo "--\n-- Dumping data for table `$table`\n--\n";
        $started = true;
      }
      echo 'INSERT INTO `' . $table . '` (' . $colList . ") VALUES\n"
        . implode ( ",\n", $batch ) . ";\n";
    }
    dbi_free_result ( $res );
    echo "\n";
    flush ();
  }

  echo "SET FOREIGN_KEY_CHECKS = 1;\n";
  flush ();
  exit;
}

/* ------------------------------------------------------------------ */
/* Landing page: Backup + Restore sections.                            */
/* ------------------------------------------------------------------ */
print_header ();

echo '<h2>' . translate ( 'Database Backup / Restore' ) . '</h2>';

echo '<h3>' . translate ( 'Backup' ) . '</h3>
  <p>' . translate ( 'Download a full backup of the database as a .sql file.' )
  . '</p>
  <form action="db_backup.php" method="post">
    ' . csrf_form_key () . '
    <input type="hidden" name="backup" value="1">
    <input type="submit" value="' . translate ( 'Download backup' ) . '">
  </form>';

echo '<h3>' . translate ( 'Restore' ) . '</h3>
  <p>' . translate ( 'Upload a .sql backup file to restore the database. Existing calendar data will be wiped. The "webcal_config" settings table is never touched.' )
  . '</p>
  <form action="import_sql.php" method="post" enctype="multipart/form-data">
    ' . csrf_form_key () . '
    <input type="hidden" name="import" value="1">
    <p><label>' . translate ( 'SQL file' )
  . ': <input type="file" name="sqlfile"></label></p>
    <p><label><input type="checkbox" name="confirm" value="1"> '
  . translate ( 'I understand that existing records will be wiped.' )
  . '</label></p>
    <input type="submit" value="' . translate ( 'Restore' ) . '">
  </form>';

echo print_trailer ();

?>
