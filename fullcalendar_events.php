<?php
/**
 * JSON feed for FullCalendar (v6).
 *
 * Outputs events in FullCalendar's EventInput format for the requested
 * date range (params "start" and "end", ISO 8601 or Ymd).
 *
 * Fields: id, title, start, end, allDay, color (category color),
 * url (view_entry.php?id=...).
 *
 * Modeled on the "get" action of events_ajax.php.
 */
require_once 'includes/translate.php';
require_once 'includes/classes/WebCalendar.php';
require_once 'includes/classes/Event.php';
require_once 'includes/classes/RptEvent.php';

$WebCalendar = new WebCalendar( __FILE__ );

require_once 'includes/config.php';
require_once 'includes/dbi4php.php';
require_once 'includes/formvars.php';
require_once 'includes/functions.php';

$WebCalendar->initializeFirstPhase();

require_once "includes/$user_inc";
require_once 'includes/access.php';
require_once 'includes/ajax.php';
require_once 'includes/validate.php';

$WebCalendar->initializeSecondPhase();

load_global_settings();
load_user_preferences();
$WebCalendar->setLanguage();
$GLOBALS['WebCalendar'] = $WebCalendar;

load_user_layers();

$readonly = ( getValue ( 'readonly' ) == '1' );

$user = getValue ( 'user', '[A-Za-z0-9_\.=@,\-]*', true );
if ( empty ( $user ) )
  $user = $login;
// Check access to the other user's calendar.
if ( $user != $login && ! access_user_calendar ( 'view', $user, $login ) ) {
  ajax_send_error ( translate ( 'Not authorized' ) );
  exit;
}

// Drag & drop handling (FullCalendar eventDrop/eventResize). The client
// posts a new start (and optional end) for an existing event id.
$action = getValue ( 'action', '[A-Za-z0-9_]*', true );

if ( $action == 'move' ) {
  // A read-only calendar is not editable, even via drag & drop.
  if ( $readonly ) {
    ajax_send_error ( translate ( 'Not authorized' ) );
    exit;
  }

  $cal_id = getIntValue ( 'id' );
  $newstart = getValue ( 'start' );
  $newend = getValue ( 'end' );

  if ( empty ( $cal_id ) || empty ( $newstart ) ) {
    ajax_send_error ( translate ( 'Invalid parameters' ) );
    exit;
  }

  // Load the event to learn its creator and type.
  $res = dbi_execute ( 'SELECT cal_create_by, cal_type, cal_time
    FROM webcal_entry WHERE cal_id = ?', [$cal_id] );
  if ( ! $res ) {
    ajax_send_error ( translate ( 'Database error' ) . ': ' . dbi_error () );
    exit;
  }
  $row = dbi_fetch_row ( $res );
  dbi_free_result ( $res );

  if ( empty ( $row ) ) {
    ajax_send_error ( translate ( 'Event not found' ) );
    exit;
  }

  $create_by = $row[0];
  $cal_type = $row[1];

  // Repeated events cannot be moved (they are managed via edit_entry.php).
  if ( $cal_type == 'M' || $cal_type == 'N' ) {
    ajax_send_error ( translate ( 'Repeated events may not be moved' ) );
    exit;
  }

  // Check edit access on the event creator's calendar.
  if ( ! access_user_calendar ( 'edit', $create_by, $login ) ) {
    ajax_send_error ( translate ( 'Not authorized' ) );
    exit;
  }

  // Use the same representation as the feed so the round-trip stays intact:
  // cal_time is read back as-is (mktime + date, no timezone conversion).
  if ( strpos ( $newstart, 'T' ) === false ) {
    // All-day move: just the new date.
    $newdate = str_replace ( '-', '', $newstart );
    if ( strlen ( $newdate ) < 8 ) {
      ajax_send_error ( translate ( 'Invalid parameters' ) );
      exit;
    }
    $newdate = substr ( $newdate, 0, 8 );
    $newtime = '-1';
    $newduration = 1440;
  } else {
    $newstart = substr ( preg_replace ( '/[^0-9]/', '', $newstart ), 0, 14 );
    $newend = substr ( preg_replace ( '/[^0-9]/', '', $newend ), 0, 14 );
    if ( strlen ( $newstart ) < 14 || strlen ( $newend ) < 14 ) {
      ajax_send_error ( translate ( 'Invalid parameters' ) );
      exit;
    }
    $newdate = substr ( $newstart, 0, 8 );
    $newtime = substr ( $newstart, 8, 6 );
    $newduration = ( int ) ( ( ( int ) substr ( $newend, 8, 6 ) == 235959
        ? ( ( strtotime ( substr ( $newend, 0, 8 ) ) + 86400 - strtotime ( $newstart ) ) ) / 60
        : ( strtotime ( $newend ) - strtotime ( $newstart ) ) / 60 ) );
    if ( $newduration < 0 )
      $newduration = 0;
  }

  if ( ! dbi_execute ( 'UPDATE webcal_entry SET cal_date = ?, cal_time = ?,
    cal_duration = ?, cal_mod_date = ?, cal_mod_time = ? WHERE cal_id = ?',
    [$newdate, $newtime, $newduration, gmdate ( 'Ymd' ), gmdate ( 'His' ),
      $cal_id] ) ) {
    ajax_send_error ( translate ( 'Database error' ) . ': ' . dbi_error () );
    exit;
  }

  ajax_send_success ();
  exit;
}

$get_unapproved = true;

// FullCalendar passes "start" and "end" as ISO 8601 strings
// (e.g. 2024-01-01 or 2024-01-01T00:00:00) or as Ymd.
$start = getValue ( 'start' );
$end = getValue ( 'end' );
$startstr = str_replace ( [ '-', ':', 'T', ' ' ], '', $start );
if ( strlen ( $startstr ) >= 8 )
  $startTime = mktime ( 0, 0, 0,
    ( int ) substr ( $startstr, 4, 2 ),
    ( int ) substr ( $startstr, 6, 2 ),
    ( int ) substr ( $startstr, 0, 4 ) );
else
  $startTime = time () - 7 * 86400;
$endstr = str_replace ( [ '-', ':', 'T', ' ' ], '', $end );
if ( strlen ( $endstr ) >= 8 )
  $endTime = mktime ( 0, 0, 0,
    ( int ) substr ( $endstr, 4, 2 ),
    ( int ) substr ( $endstr, 6, 2 ),
    ( int ) substr ( $endstr, 0, 4 ) );
else
  $endTime = $startTime + 7 * 86400;
if ( $endTime <= $startTime )
  $endTime = $startTime + 7 * 86400;

load_user_categories();

// Pre-load all events for quicker access.
$repeated_events = read_repeated_events ( $user, $startTime, $endTime );
$events = read_events ( $user, $startTime, $endTime );

$result = [];
foreach ( $events as $E ) {
  $cat = $E->getCategory ();
  $color = ( isset ( $categories[$cat]['cat_color'] )
    && $categories[$cat]['cat_color'] != '#000000' )
    ? $categories[$cat]['cat_color'] : '';
  $startT = $E->getDate () . 'T' . substr ( $E->getTime (), 0, 2 )
    . ':' . substr ( $E->getTime (), 2, 2 )
    . ':' . substr ( $E->getTime (), 4, 2 );
  $item = [
    'id'    => ( string ) $E->getID (),
    'title' => $E->getName (),
    'start' => $E->isTimed () ? $startT : $E->getDate (),
    'allDay'=> ! $E->isTimed (),
  ];
  if ( $color )
    $item['color'] = $color;
  if ( $E->getDuration () > 0 && $E->isTimed () ) {
    $s = mktime ( ( int ) substr ( $E->getTime (), 0, 2 ),
      ( int ) substr ( $E->getTime (), 2, 2 ),
      ( int ) substr ( $E->getTime (), 4, 2 ),
      ( int ) substr ( $E->getDate (), 4, 2 ),
      ( int ) substr ( $E->getDate (), 6, 2 ),
      ( int ) substr ( $E->getDate (), 0, 4 ) );
    $e = $s + $E->getDuration () * 60;
    $item['end'] = date ( 'Y-m-d\TH:i:s', $e );
  }
  $item['url'] = 'view_entry.php?id=' . $E->getID ();
  $result[] = $item;
}
foreach ( $repeated_events as $E ) {
  $cat = $E->getCategory ();
  $color = ( isset ( $categories[$cat]['cat_color'] )
    && $categories[$cat]['cat_color'] != '#000000' )
    ? $categories[$cat]['cat_color'] : '';
  $startT = $E->getDate () . 'T' . substr ( $E->getTime (), 0, 2 )
    . ':' . substr ( $E->getTime (), 2, 2 )
    . ':' . substr ( $E->getTime (), 4, 2 );
  $item = [
    'id'    => ( string ) $E->getID (),
    'title' => $E->getName (),
    'start' => $E->isTimed () ? $startT : $E->getDate (),
    'allDay'=> ! $E->isTimed (),
  ];
  if ( $color )
    $item['color'] = $color;
  if ( $E->getDuration () > 0 && $E->isTimed () ) {
    $s = mktime ( ( int ) substr ( $E->getTime (), 0, 2 ),
      ( int ) substr ( $E->getTime (), 2, 2 ),
      ( int ) substr ( $E->getTime (), 4, 2 ),
      ( int ) substr ( $E->getDate (), 4, 2 ),
      ( int ) substr ( $E->getDate (), 6, 2 ),
      ( int ) substr ( $E->getDate (), 0, 4 ) );
    $e = $s + $E->getDuration () * 60;
    $item['end'] = date ( 'Y-m-d\TH:i:s', $e );
  }
  $item['url'] = 'view_entry.php?id=' . $E->getID ();
  $result[] = $item;
}

$sendPlainText = ( getValue ( 'format' ) == 'text'
  || getValue ( 'format' ) == 'plain' );
ajax_send_objects ( [ 'events' => $result ], $sendPlainText );
