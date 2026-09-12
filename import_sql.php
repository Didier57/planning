<?php
/**
 * Description:
 *  Import a WebCalendar database dump (an .sql file exported by Navicat or
 *  similar) as an administrator.  Reference data in the tables present in the
 *  dump is purged first, then re-inserted from the file.  The database schema
 *  itself is never modified: DDL statements in the dump are ignored and only
 *  INSERT / SET statements are executed.
 *
 *  webcal_config is deliberately excluded: its current settings (version,
 *  timezone, mailer, ...) are kept untouched.
 *
 * Security:
 *  User must be an admin user.  File upload requires the CSRF form key.
 */
require_once 'includes/init.php';

if ( ! $is_admin )
  die_miserable_death ( print_not_auth() );

set_time_limit ( 0 );

define ( 'WCB_EXCLUDED', 'webcal_config' );
define ( 'WCB_BATCH', 500 );

/** Convert a Windows-1252/Latin-1 byte string to UTF-8 without the
 *  mbstring or iconv extensions.  Already-valid UTF-8 is returned
 *  unchanged (input never re-encoded twice). */
function wcb_to_utf8 ( $text ) {
  static $map = [ 0x80 => "\u{20AC}", 0x82 => "\u{201A}",
    0x83 => "\u{0192}", 0x84 => "\u{201E}", 0x85 => "\u{2026}",
    0x86 => "\u{2020}", 0x87 => "\u{2021}", 0x88 => "\u{02C6}",
    0x89 => "\u{2030}", 0x8A => "\u{0160}", 0x8B => "\u{2039}",
    0x8C => "\u{0152}", 0x8E => "\u{017D}", 0x91 => "\u{2018}",
    0x92 => "\u{2019}", 0x93 => "\u{201C}", 0x94 => "\u{201D}",
    0x95 => "\u{2022}", 0x96 => "\u{2013}", 0x97 => "\u{2014}",
    0x98 => "\u{02DC}", 0x99 => "\u{2122}", 0x9A => "\u{0161}",
    0x9B => "\u{203A}", 0x9C => "\u{0153}", 0x9E => "\u{017E}",
    0x9F => "\u{0178}" ];
  if ( @preg_match ( '//u', $text ) )
    return $text;
  return preg_replace_callback ( '/[\x80-\xFF]/', function ( $m ) use ( $map ) {
    $o = ord ( $m[0] );
    if ( isset ( $map[$o] ) )
      return $map[$o];
    return chr ( 0xC0 | ( $o >> 6 ) ) . chr ( 0x80 | ( $o & 0x3F ) );
  }, $text );
}

/** Split a SQL script into individual statements.  Comments and unused
 *  statements (DROP/CREATE/LOCK/UNLOCK) are skipped at execution time. */
function wcb_split_sql ( $sql ) {
  $out = [];
  $len = strlen ( $sql );
  $i = 0;
  $buf = '';
  while ( $i < $len ) {
    $ch = $sql[$i];
    if ( $ch == '-' && substr ( $sql, $i, 2 ) == '--' ) {
      $nl = strpos ( $sql, "\n", $i );
      $i = ( $nl === false ) ? $len : $nl;
      continue;
    }
    if ( $ch == '#' ) {
      $nl = strpos ( $sql, "\n", $i );
      $i = ( $nl === false ) ? $len : $nl;
      continue;
    }
    if ( $ch == '/' && substr ( $sql, $i, 2 ) == '/*' ) {
      $end = strpos ( $sql, '*/', $i + 2 );
      $i = ( $end === false ) ? $len : $end + 2;
      continue;
    }
    if ( $ch == "'" || $ch == '"' || $ch == '`' ) {
      $q = $ch;
      $buf .= $ch;
      $i++;
      while ( $i < $len ) {
        $c = $sql[$i];
        if ( $c == '\\' && $i + 1 < $len ) {
          $buf .= $c . $sql[$i + 1];
          $i += 2;
          continue;
        }
        $buf .= $c;
        $i++;
        if ( $c == $q )
          break;
      }
      continue;
    }
    if ( $ch == ';' ) {
      $s = trim ( $buf );
      if ( $s != '' )
        $out[] = $s;
      $buf = '';
      $i++;
      continue;
    }
    $buf .= $ch;
    $i++;
  }
  $s = trim ( $buf );
  if ( $s != '' )
    $out[] = $s;
  return $out;
}

/** Find the position right after the top-level VALUES keyword, or false. */
function wcb_values_pos ( $stmt ) {
  $len = strlen ( $stmt );
  $i = 0;
  $depth = 0;
  while ( $i < $len ) {
    $ch = $stmt[$i];
    if ( $ch == "'" || $ch == '"' || $ch == '`' ) {
      $q = $ch;
      $i++;
      while ( $i < $len ) {
        $c = $stmt[$i];
        if ( $c == '\\' && $i + 1 < $len ) {
          $i += 2;
          continue;
        }
        $i++;
        if ( $c == $q )
          break;
      }
      continue;
    }
    if ( $ch == '(' )
      $depth++;
    elseif ( $ch == ')' )
      $depth--;
    elseif ( $depth == 0 && substr ( strtoupper ( $stmt ), $i, 6 )
        == 'VALUES' && ( $i + 6 == $len
          || ! preg_match ( '/[A-Z0-9_]/', $stmt[$i + 6] ) ) )
      return $i + 6;
    $i++;
  }
  return false;
}

/** Extract top-level parenthesised groups (one group per row tuple). */
function wcb_extract_groups ( $sql ) {
  $groups = [];
  $len = strlen ( $sql );
  $i = 0;
  while ( $i < $len ) {
    if ( $sql[$i] != '(' ) {
      $i++;
      continue;
    }
    $j = $i;
    $depth = 0;
    while ( $j < $len ) {
      $c = $sql[$j];
      if ( $c == "'" || $c == '"' || $c == '`' ) {
        $q = $c;
        $j++;
        while ( $j < $len ) {
          $cc = $sql[$j];
          if ( $cc == '\\' && $j + 1 < $len ) {
            $j += 2;
            continue;
          }
          $j++;
          if ( $cc == $q )
            break;
        }
        continue;
      }
      if ( $c == '(' )
        $depth++;
      elseif ( $c == ')' ) {
        $depth--;
        if ( $depth == 0 )
          break;
      }
      $j++;
    }
    $groups[] = substr ( $sql, $i + 1, $j - $i - 1 );
    $i = $j + 1; // outer loop skips the rest until the next '('
  }
  return $groups;
}

/** Split a row tuple's inner text into individual values (verbatim). */
function wcb_split_values ( $inner ) {
  $values = [];
  $len = strlen ( $inner );
  $cur = '';
  $i = 0;
  while ( $i < $len ) {
    $ch = $inner[$i];
    if ( $ch == "'" || $ch == '"' || $ch == '`' ) {
      $q = $ch;
      $cur .= $ch;
      $i++;
      while ( $i < $len ) {
        $c = $inner[$i];
        if ( $c == '\\' && $i + 1 < $len ) {
          $cur .= $c . $inner[$i + 1];
          $i += 2;
          continue;
        }
        $cur .= $c;
        $i++;
        if ( $c == $q )
          break;
      }
      continue;
    }
    if ( $ch == ',' ) {
      $values[] = trim ( $cur );
      $cur = '';
      $i++;
      continue;
    }
    $cur .= $ch;
    $i++;
  }
  $values[] = trim ( $cur );
  return $values;
}

/** Ordered dump columns of a CREATE TABLE statement, or null. */
function wcb_create_columns ( $stmt ) {
  if ( ! preg_match ( '/^CREATE\s+TABLE\s+`?([a-z0-9_]+)`?\s*\(/i',
    $stmt, $tm ) )
    return null;
  if ( ! preg_match_all ( '/^\s*`([^`]+)`\s+/m', $stmt, $cm ) )
    return null;
  $cols = [];
  foreach ( $cm[1] as $c ) {
    if ( preg_match ( '/^(PRIMARY|UNIQUE|KEY|INDEX|CONSTRAINT|FULLTEXT|'
      . 'SPATIAL)\b/i', $c ) )
      continue;
    $cols[] = $c;
  }
  return [ $tm[1], $cols ];
}

/** Ordered live columns of a table via SHOW COLUMNS, or null. */
function wcb_live_columns ( $table ) {
  $res = dbi_execute ( 'SHOW COLUMNS FROM `' . $table . '`', [], false, false );
  $cols = [];
  if ( $res ) {
    while ( $row = dbi_fetch_row ( $res ) )
      $cols[] = $row[0];
    dbi_free_result ( $res );
  }
  return ( count ( $cols ) > 0 ) ? $cols : null;
}

$doImport = ( getPostValue ( 'import' ) == '1' );

print_header();
echo '<h2>' . translate ( 'Import SQL' ) . '</h2>';

if ( $doImport ) {
  $results = [];
  $errors = [];

  if ( empty ( $_FILES['sqlfile'] )
      || $_FILES['sqlfile']['error'] != UPLOAD_ERR_OK
      || ! is_uploaded_file ( $_FILES['sqlfile']['tmp_name'] ) ) {
    $errMsg = translate ( 'No valid file was uploaded.' );
    $upErr = empty ( $_FILES['sqlfile'] ) ? -1 : $_FILES['sqlfile']['error'];
    if ( $upErr != UPLOAD_ERR_OK ) {
      $names = [ 1 => 'UPLOAD_ERR_INI_SIZE',
        2 => 'UPLOAD_ERR_FORM_SIZE',
        3 => 'UPLOAD_ERR_PARTIAL',
        4 => 'UPLOAD_ERR_NO_FILE',
        6 => 'UPLOAD_ERR_NO_TMP_DIR',
        7 => 'UPLOAD_ERR_CANT_WRITE',
        8 => 'UPLOAD_ERR_EXTENSION' ];
      $errMsg .= (! isset ( $names[$upErr] ) ? '' : ' (' . $names[$upErr] . ')');
      if ( $upErr == UPLOAD_ERR_INI_SIZE && ini_get ( 'upload_max_filesize' ) ) {
        $errMsg .= '. ' . translate ( 'Server upload limit is' ) . ' '
          . ini_get ( 'upload_max_filesize' );
      }
    }
    $errors[] = $errMsg;
  } elseif ( getPostValue ( 'confirm' ) != '1' ) {
    $errors[] = translate ( 'The wipe confirmation checkbox must be ticked.' );
  }

  if ( count ( $errors ) == 0 ) {
    $contents = @file_get_contents ( $_FILES['sqlfile']['tmp_name'] );
    if ( $contents === false || strlen ( $contents ) == 0 ) {
      $errors[] = translate ( 'The uploaded file could not be read.' );
    } else {
      $contents = preg_replace ( '/^\xEF\xBB\xBF/', '', $contents );
      $contents = wcb_to_utf8 ( $contents );
    }
  }

  if ( count ( $errors ) == 0 ) {
    $statements = wcb_split_sql ( $contents );
    unset ( $contents );
    $tables = [];
    $rows = [];
    foreach ( $statements as $stmt ) {
      if ( preg_match ( '/^SET\s/i', $stmt ) ) {
        dbi_execute ( $stmt, [], false, false );
        continue;
      }
      if ( preg_match ( '/^CREATE\s+TABLE\s/i', $stmt ) ) {
        $t = wcb_create_columns ( $stmt );
        if ( $t !== null && $t[0] != WCB_EXCLUDED )
          $tables[$t[0]] = $t;
        continue;
      }
      if ( preg_match ( '/^INSERT\s/i', $stmt ) ) {
        if ( ! preg_match ( '/^INSERT\s+INTO\s+`?([a-z0-9_]+)`?\s*/i',
          $stmt, $im ) )
          continue;
        $table = $im[1];
        if ( $table == WCB_EXCLUDED )
          continue;
        $pos = wcb_values_pos ( $stmt );
        if ( $pos !== false ) {
          $groups = wcb_extract_groups ( substr ( $stmt, $pos ) );
          foreach ( $groups as $g )
            $rows[$table][] = wcb_split_values ( $g );
        }
        continue;
      }
    }

    dbi_execute ( 'SET FOREIGN_KEY_CHECKS = 0', [], false, false );

    foreach ( array_keys ( $tables ) as $table ) {
      $oldCols = $tables[$table][1];
      $newCols = wcb_live_columns ( $table );
      $skip = '';

      if ( $newCols === null ) {
        $skip = translate ( 'Table does not exist in the target database.' );
      } elseif ( $oldCols === null || count ( $oldCols ) == 0 ) {
        $skip = translate ( 'Could not determine the dump column order.' );
      } else {
        $dumpPos = array_flip ( $oldCols );
        $missing = [];
        foreach ( $oldCols as $c ) {
          if ( ! in_array ( $c, $newCols ) )
            $missing[] = $c;
        }
        if ( count ( $missing ) > 0 )
          $skip = translate ( 'Columns not present in the target table: '
            ) . implode ( ', ', $missing );

        if ( $skip == '' ) {
          dbi_execute ( 'DELETE FROM `' . $table . '`', [], false, false );

          $colList = implode ( ', ',
            array_map ( function ( $c ) { return '`' . $c . '`'; }, $newCols ) );
          $rowsets = [];
          $n = 0;
          foreach ( ( $rows[$table] ?? [] ) as $vals ) {
            if ( count ( $vals ) != count ( $oldCols ) ) {
              $errors[] = sprintf ( translate ( 'Table %s: a row has %d '
                . 'values instead of %d.' ), $table, count ( $vals ),
                count ( $oldCols ) );
              continue;
            }
            $outVals = [];
            foreach ( $newCols as $c ) {
              $oidx = isset ( $dumpPos[$c] ) ? $dumpPos[$c] : null;
              $outVals[] = ( $oidx !== null ) ? $vals[$oidx] : 'null';
            }
            $rowsets[] = '(' . implode ( ', ', $outVals ) . ')';
            $n++;
            if ( count ( $rowsets ) >= WCB_BATCH ) {
              $sql = 'INSERT INTO `' . $table . '` (' . $colList
                . ') VALUES ' . implode ( ', ', $rowsets );
              $res = dbi_execute ( $sql, [], false, false );
              if ( ! $res )
                $errors[] = sprintf ( translate ( 'Table %s: %s' ), $table,
                  dbi_error() );
              $rowsets = [];
            }
          }
          if ( count ( $rowsets ) > 0 ) {
            $sql = 'INSERT INTO `' . $table . '` (' . $colList
              . ') VALUES ' . implode ( ', ', $rowsets );
            $res = dbi_execute ( $sql, [], false, false );
            if ( ! $res )
              $errors[] = sprintf ( translate ( 'Table %s: %s' ), $table,
                dbi_error() );
          }

          $count = 0;
          $r2 = dbi_execute ( 'SELECT COUNT(*) FROM `' . $table . '`' );
          if ( $r2 ) {
            $row = dbi_fetch_row ( $r2 );
            $count = intval ( $row[0] );
            dbi_free_result ( $r2 );
          }
          $results[$table] = $count . ' / ' . $n;
        }
      }
      if ( $skip != '' )
        $results[$table] = '0 / 0 (' . $skip . ')';
    }

    dbi_execute ( 'SET FOREIGN_KEY_CHECKS = 1', [], false, false );
  }

  if ( count ( $errors ) > 0 ) {
    echo '<p class="alert">' . nl2br ( htmlentities ( implode ( "\n",
      $errors ) ) ) . '</p>';
  } else {
    echo '<table class="report" summary="import result">
      <tr><th>' . translate ( 'Table' ) . '</th><th>'
      . translate ( 'Rows' ) . '</th></tr>';
    foreach ( $results as $table => $info )
      echo '<tr><td>' . htmlentities ( $table ) . '</td><td>'
        . htmlentities ( $info ) . '</td></tr>';
    echo '</table>';
    if ( count ( $errors ) > 0 )
      echo '<p class="alert">' . nl2br ( htmlentities ( implode ( "\n",
        $errors ) ) ) . '</p>';
  }
  echo '<p><a href="import_sql.php">' . translate ( 'Back' ) . '</a></p>'
    . print_trailer();
  exit;
}

echo '
<p>' . translate ( 'Upload a WebCalendar database dump (.sql) to replace the current data. All existing records in the tables present in the dump will be deleted first, then re-inserted from the file. The database schema itself is not modified.' ) . '</p>
<p>' . translate ( 'The "webcal_config" settings table is never touched.' ) . '</p>
<div class="report-alert">' . translate ( 'Warning: existing calendar data will be wiped.' ) . '</div>
<form action="import_sql.php" method="post" enctype="multipart/form-data">
  ' . csrf_form_key() . '
  <input type="hidden" name="import" value="1">
  <p><label>' . translate ( 'SQL file' ) . ': <input type="file" name="sqlfile"></label></p>
  <p><label><input type="checkbox" name="confirm" value="1"> ' . translate ( 'I understand that existing records will be wiped.' ) . '</label></p>
  <input type="submit" value="' . translate ( 'Import' ) . '">
</form>'
  . print_trailer();