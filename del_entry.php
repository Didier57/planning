<?php
require_once 'includes/init.php';
require_once 'includes/classes/WebCalMailer.php';
$mail = new WebCalMailer;

$can_edit = $my_event = false;
$other_user = '';

// First, check to see if this user should be able to delete this event.
if ( $id > 0 ) {
  // Then see who has access to edit this entry.
  $can_edit = ( $is_admin || $readonly != 'Y' );

  // If assistant is doing this, then we need to switch login to user in the SQL.
  $query_params = [];
  $query_params[] = $id;
  $sql = 'SELECT we.cal_id, we.cal_type FROM webcal_entry we,
    webcal_entry_user weu WHERE we.cal_id = weu.cal_id AND we.cal_id = ? ';
  if ( ! $is_admin ) {
    $sql .= ' AND ( we.cal_create_by = ? OR weu.cal_login = ? )';
    $sqlparm = ( $is_assistant ? $user : $login );
    $query_params[] = $sqlparm;
    $query_params[] = $sqlparm;
  }
  $res = dbi_execute ( $sql, $query_params );
  if ( $res ) {
    $row = dbi_fetch_row ( $res );
    if ( $row && $row[0] > 0 )
      $can_edit = true;

    $activity_type = $row[1];
    dbi_free_result ( $res );
  }
}
if ( strpos ( 'EM', $activity_type ) !== false ) {
  $log_delete = LOG_DELETE;
  $log_reject = LOG_REJECT;
} else {
  $log_delete = LOG_DELETE_T;
  $log_reject = LOG_REJECT_T;
}
// See who owns the event. Owner should be able to delete.
$res = dbi_execute ( 'SELECT cal_create_by
  FROM webcal_entry
  WHERE cal_id = ?', [$id] );
if ( $res ) {
  $row = dbi_fetch_row ( $res );
  $owner = $row[0];
  dbi_free_result ( $res );

  if ( $owner == $login || $is_assistant && $user == $owner || $is_nonuser_admin )
    $can_edit = $my_event = true;

  // Check UAC.
  if ( access_is_enabled() && ! $is_admin )
    $can_edit = access_user_calendar ( 'edit', $owner );
}

// If the user is the event creator or their assistant
// allow them to delete the event from another user's calendar.
// It's essentially the same thing as editing the event and removing the
// user from the participants list.
if ( $my_event && ! empty ( $user ) && $user != $login && ! $is_assistant )
  $other_user = $user;

if ( $readonly == 'Y' )
  $can_edit = false;

// If User Access Control is enabled, check to see if the current
// user is allowed to delete events from the other user's calendar.
if ( ! $can_edit && access_is_enabled() && ! empty ( $user ) &&
    access_user_calendar ( 'edit', $user ) )
  $can_edit = true;

if ( ! $can_edit )
  $error = print_not_auth();

// Is this a repeating event?
$event_repeats = false;
$res = dbi_execute ( 'SELECT COUNT( cal_id ) FROM webcal_entry_repeats
  WHERE cal_id = ?', [$id] );
if ( $res ) {
  $row = dbi_fetch_row ( $res );
  if ( $row[0] > 0 )
    $event_repeats = true;

  dbi_free_result ( $res );
}
$override_repeat = false;
if ( ! empty ( $date ) && $event_repeats && ! empty ( $override ) )
  $override_repeat = true;

if ( $id > 0 && empty ( $error ) ) {
  if ( ! empty ( $date ) )
    $thisdate = $date;
  else {
    $res = dbi_execute ( 'SELECT cal_date
  FROM webcal_entry
  WHERE cal_id = ?', [$id] );
    if ( $res ) {
      // date format is 19991231
      $row = dbi_fetch_row ( $res );
      $thisdate = $row[0];
    }
  }

  // Only allow delete of webcal_entry & webcal_entry_repeats
  // if owner or admin, not participant.
  // If a user was specified, then only delete that user (not here) even if we
  // are the owner or an admin.
  if ( ( $is_admin || $my_event ) && ! $other_user ) {
    // Email participants that the event was deleted.
    // First, get list of participants (with status Approved or Waiting on approval).
    $res = dbi_execute ( 'SELECT cal_login FROM webcal_entry_user
  WHERE cal_id = ?
    AND cal_status IN ( \'A\', \'W\' )', [$id] );
    $partlogin = [];
    if ( $res ) {
      while ( $row = dbi_fetch_row ( $res ) ) {
        $partlogin[] = $row[0];
      }
      dbi_free_result ( $res );
    }
    error_log( 'del_entry: id=' . $id . ' branch=owner, participants='
      . count( $partlogin ) . ', SEND_EMAIL=' . $SEND_EMAIL );
    // Get event name.
    $res = dbi_execute ( 'SELECT cal_name, cal_date, cal_time FROM webcal_entry
  WHERE cal_id = ?', [$id] );
    if ( $res ) {
      $row = dbi_fetch_row ( $res );
      $name = $row[0];
      $fmtdate = $row[1];
      $time = sprintf ( "%06d", $row[2] );
      dbi_free_result ( $res );
    }

    // Also get external participants (raw email addresses, stored in
    // webcal_entry_ext_user). They are removed from the database below,
    // so collect them now in order to notify them after the deletion.
    $ext_names = $ext_emails = [];
    $res = dbi_execute ( 'SELECT cal_fullname, cal_email FROM webcal_entry_ext_user
  WHERE cal_id = ?', [$id] );
    if ( $res ) {
      while ( $row = dbi_fetch_row ( $res ) ) {
        $ext_names[] = $row[0];
        $ext_emails[] = ( empty ( $row[1] ) ? '' : $row[1] );
      }
      dbi_free_result ( $res );
    }
    error_log( 'del_entry: id=' . $id . ' externals=' . count ( $ext_names ) );

    $eventstart = date_to_epoch ( $fmtdate . $time );
    $TIME_FORMAT = 24;

    // Instead of deleting from the database...
    // mark it as deleted by setting the status for each participant to "D"
    // (instead of "A"/Accepted, "W"/Waiting-on-approval or "R"/Rejected).
    if ( $override_repeat ) {
      dbi_execute ( 'INSERT INTO webcal_entry_repeats_not
        ( cal_id, cal_date, cal_exdate ) VALUES ( ?, ?, ? )',
        [$id, $date, 1] );
      // Should we log this to the activity log???
    } else {
      // If it's a repeating event, delete any event exceptions that were entered.
      if ( $event_repeats ) {
        $res = dbi_execute ( 'SELECT cal_id
  FROM webcal_entry
  WHERE cal_group_id = ?', [$id] );
        if ( $res ) {
          $ex_events = [];
          while ( $row = dbi_fetch_row ( $res ) ) {
            $ex_events[] = $row[0];
          }
          dbi_free_result ( $res );
          for ( $i = 0, $cnt = count ( $ex_events ); $i < $cnt; $i++ ) {
            $res = dbi_execute ( 'SELECT cal_login FROM webcal_entry_user WHERE cal_id = ?', [$ex_events[$i]] );
            if ( $res ) {
              $delusers = [];
              while ( $row = dbi_fetch_row ( $res ) ) {
                $delusers[] = $row[0];
              }
              dbi_free_result ( $res );
              for ( $j = 0, $cnt = count ( $delusers ); $j < $cnt; $j++ ) {
                // Log the deletion.
                activity_log ( $ex_events[$i], $login, $delusers[$j],
                  $log_delete, '' );
                dbi_execute ( 'UPDATE webcal_entry_user SET cal_status = ? WHERE cal_id = ? ' .
                  ' AND cal_login = ?', ['D', $ex_events[$i], $delusers[$j]] );
              }
            }
          }
        }
      }

      // Now, mark event as deleted for all users.
      dbi_execute ( 'UPDATE webcal_entry_user SET cal_status = \'D\' WHERE cal_id = ?', [$id] );

      // Delete External users for this event
      dbi_execute ( 'DELETE FROM webcal_entry_ext_user WHERE cal_id = ?', [$id] );
    }

    // Email participants that the event was deleted.
    // Same logic as edit_entry_handler.php: the creator gets a copy of
    // their own event's emails when EMAIL_EVENT_CREATE is 'Y'.
    $send_own = get_pref_setting ( $login, 'EMAIL_EVENT_CREATE' );

    for ( $i = 0, $cnt = count ( $partlogin ); $i < $cnt; $i++ ) {
      // Log the deletion.
      activity_log ( $id, $login, $partlogin[$i], $log_delete, '' );
      // Check UAC. Default to allowed when access control is disabled
      // (mirrors the email handling in edit_entry_handler.php).
      $can_email = true;
      if ( access_is_enabled() )
        $can_email = access_user_calendar ( 'email', $partlogin[$i], $login );

      // Don't email the logged in user, unless they asked for a copy
      // of their own event notifications.
      if ( $can_email && ( $partlogin[$i] != $login || $send_own == 'Y' ) ) {
        set_env ( 'TZ', get_pref_setting ( $partlogin[$i], 'TIMEZONE' ) );
        $user_language = get_pref_setting ( $partlogin[$i], 'LANGUAGE' );
        user_load_variables ( $partlogin[$i], 'temp' );
        if ( ! $is_nonuser_admin &&
          boss_must_be_notified ( $login, $partlogin[$i] ) && !
            empty ( $tempemail ) && $SEND_EMAIL != 'N' ) {
          reset_language ( empty ( $user_language ) || $user_language == 'none'
            ? $LANGUAGE : $user_language );
          // Use WebCalMailer class. Embed the event as a text/calendar
          // request (same method as creation) marked CANCELLED so that
          // Outlook/Exchange remove the appointment automatically. For a
          // single deleted occurrence of a repeating event, keep the plain
          // text email (no calendar) since the event is not fully cancelled.
          $mail->WC_Send ( $login_fullname, $tempemail, $tempfullname, $name,
            str_replace ( 'XXX', $tempfullname, translate ( 'Hello, XXX.' ) )
             . ".\n\n" . str_replace ( 'XXX', $login_fullname,
              translate ( 'XXX has canceled an appointment.' ) ) . "\n"
             . str_replace ( 'XXX', $name, translate ( 'Subject XXX' ) ) . "\"\n"
             . str_replace ( 'XXX', date_to_str ( $thisdate ),
              translate ( 'Date XXX' ) ) . "\n"
             . ( ! empty ( $eventtime ) && $eventtime != '-1'
              ? str_replace ( 'XXX', display_time ( '', 2, $eventstart,
                  get_pref_setting ( $partlogin[$i], 'TIME_FORMAT' ) ),
                translate ( 'Time XXX' ) ) : '' ) . "\n\n",
            // Apply user's GMT offset and display their TZID.
            get_pref_setting ( $partlogin[$i], 'EMAIL_HTML' ), $login_email,
            ( $override_repeat ? '' : $id ) );
          error_log( 'del_entry: send to ' . $partlogin[$i]
            . ' err=' . $mail->ErrorInfo() );
        } else {
          error_log( 'del_entry: skipped ' . $partlogin[$i]
            . ' note=no_email_or_nonuser_or_boss_or_send_email_off' );
        }
      } else {
        error_log( 'del_entry: not emailing ' . ( isset($partlogin[$i]) ? $partlogin[$i] : '?' )
          . ' can_email=' . ( $can_email ? 'yes' : 'no' )
          . ' is_self=' . ( $partlogin[$i] == $login ? 'yes' : 'no' ) );
      }
    }

    // Notify external participants as well. They are invited with the same
    // METHOD:REQUEST mechanism, so send a CANCELLED request so that
    // Outlook/Exchange remove the appointment automatically. For a single
    // deleted occurrence of a repeating event, keep the plain text email.
    if ( $EXTERNAL_NOTIFICATIONS == 'Y' && $SEND_EMAIL != 'N' ) {
      for ( $i = 0, $cnt = count ( $ext_names ); $i < $cnt; $i++ ) {
        if ( ! empty ( $ext_names[$i] ) && ! empty ( $ext_emails[$i] ) ) {
          $mail->WC_Send ( $login_fullname, $ext_emails[$i], $ext_names[$i],
            $name,
            str_replace ( 'XXX', $ext_names[$i], translate ( 'Hello, XXX.' ) )
             . ".\n\n" . str_replace ( 'XXX', $login_fullname,
              translate ( 'XXX has canceled an appointment.' ) ) . "\n"
             . str_replace ( 'XXX', $name, translate ( 'Subject XXX' ) ) . "\"\n"
             . str_replace ( 'XXX', date_to_str ( $thisdate ),
              translate ( 'Date XXX' ) ) . "\n"
             . ( ! empty ( $eventtime ) && $eventtime != '-1'
              ? str_replace ( 'XXX', display_time ( '', 2, $eventstart,
                  $TIME_FORMAT ), translate ( 'Time XXX' ) ) : '' ) . "\n\n",
            'N', $login_email, ( $override_repeat ? '' : $id ) );
          error_log( 'del_entry: ext send to ' . $ext_emails[$i]
            . ' err=' . $mail->ErrorInfo() );
        } else {
          error_log( 'del_entry: ext skipped '
            . ( empty ( $ext_names[$i] ) ? 'no-name' : $ext_names[$i] )
            . ' has_email=' . ( empty ( $ext_emails[$i] ) ? 'no' : 'yes' ) );
        }
      }
    }
  } else {
    // Not the owner of the event, but participant or noncal_admin.
    // Just  set the status to 'D' instead of deleting.
    $del_user = ( ! empty ( $other_user ) ? $other_user : $login );
    if ( ! empty ( $user ) && $user != $login ) {
      if ( $is_admin || $my_event || ( $can_edit && $is_assistant ) ||
          ( access_is_enabled() &&
            access_user_calendar ( 'edit', $user ) ) ) {
        $del_user = $user;
      } else
        // Error: user cannot delete from other user's calendar.
        $error = print_not_auth();
    }
    if ( empty ( $error ) ) {
      if ( $override_repeat ) {
        dbi_execute ( 'INSERT INTO webcal_entry_repeats_not
          ( cal_id, cal_date, cal_exdate ) VALUES ( ?, ?, ? )',
          [$id, $date, 1] );
        // Should we log this to the activity log???
      } else {
        dbi_execute ( 'UPDATE webcal_entry_user SET cal_status = ?
  WHERE cal_id = ?
    AND cal_login = ?', ['D', $id, $del_user] );
        activity_log ( $id, $login, $login, $log_reject, '' );

        // Notify the user whose calendar the event was removed from,
        // unless that user is the one performing the deletion.
        if ( $del_user != $login ) {
          error_log( 'del_entry: branch=single-user, removing for ' . $del_user );
          $res = dbi_execute ( 'SELECT cal_name, cal_date, cal_time
            FROM webcal_entry WHERE cal_id = ?', [$id] );
          if ( $res ) {
            $drow = dbi_fetch_row ( $res );
            $dname = $drow[0];
            $ddate = $drow[1];
            dbi_free_result ( $res );
            set_env ( 'TZ', get_pref_setting ( $del_user, 'TIMEZONE' ) );
            $user_language = get_pref_setting ( $del_user, 'LANGUAGE' );
            user_load_variables ( $del_user, 'temp' );
            if ( ! empty ( $tempemail ) &&
              boss_must_be_notified ( $login, $del_user ) &&
                $SEND_EMAIL != 'N' ) {
              reset_language ( empty ( $user_language ) ||
                $user_language == 'none' ? $LANGUAGE : $user_language );
              // Plain text notification: for a single-user removal we
              // cannot build a correct CANCELLED request (the event still
              // exists for other participants), so no calendar part here.
              $mail->WC_Send ( $login_fullname, $tempemail, $tempfullname,
                $dname,
                str_replace ( 'XXX', $tempfullname,
                  translate ( 'Hello, XXX.' ) ) . ".\n\n"
                 . str_replace ( 'XXX', $login_fullname,
                  translate ( 'XXX has canceled an appointment.' ) ) . "\n"
                 . str_replace ( 'XXX', $dname,
                  translate ( 'Subject XXX' ) ) . "\"\n"
                 . str_replace ( 'XXX', date_to_str ( $ddate ),
                  translate ( 'Date XXX' ) ) . "\n\n",
                get_pref_setting ( $del_user, 'EMAIL_HTML' ), $login_email );
              activity_log ( $id, $login, $del_user, $log_delete, '' );
            }
          }
        }
      }
    }
  }
}

$ret = getValue ( 'ret' );
$return_view = get_last_view();

if ( ! empty ( $ret ) ) {
  if ( $ret == 'listall' )
    $url = 'list_unapproved.php';
  else
  if ( $ret == 'list' )
    $url = 'list_unapproved.php' . ( empty ( $user ) ? '' : '?user=' . $user );
} else
if ( ! empty ( $return_view ) )
  do_redirect ( $return_view );
else
  $url = get_preferred_view ( '', empty ( $user ) ? '' : 'user=' . $user );

// Return to login TIMEZONE.
set_env ( 'TZ', $TIMEZONE );
if ( empty ( $error ) && empty ( $mailerError ) ) {
  do_redirect ( $url );
  exit;
}
// Process errors.
$mail->MailError ( $mailerError, $error );

?>
