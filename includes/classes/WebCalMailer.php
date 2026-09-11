<?php
/**
 * Class to over load PHPMailer class to utilize
 * WebCalendar's translation function.
 *
 * PHPMailer's homepage http://phpmailer.sourceforge.net/
 *
 * @author Ray Jones <rjones@umces.edu>
 * @copyright Craig Knudsen, <cknudsen@cknudsen.com>, http://k5n.us/webcalendar
 * @license https://gnu.org/licenses/old-licenses/gpl-2.0.html GNU GPL
 *
 * @package WebCalendar\Mailer
 */
$inc_path = ( defined( '__WC_INCLUDEDIR' ) ? __WC_INCLUDEDIR : 'includes' );

if( file_exists( $inc_path . '/xcal.php' ) )
  require_once "$inc_path/xcal.php"; // Used for ics attachments.

require_once 'phpmailer/Exception.php';
require_once 'phpmailer/PHPMailer.php';
require_once 'phpmailer/SMTP.php';

use phpmailer\PHPMailer;

class WebCalMailer {
  private $mail;

  // iCal METHOD used for the calendar part of event emails (e.g. an
  // Outlook meeting invitation with Exchange AutoAccept needs 'REQUEST').
  public $icalMethod = 'REQUEST';

  /**
   * Constructor
   */
  function __construct() {
    global $EMAIL_MAILER, $mailerError, $SMTP_AUTH,
    $SMTP_HOST, $SMTP_PORT, $SMTP_PASSWORD, $SMTP_USERNAME;

    $this->mail = new PHPMailer\PHPMailer(false);
    $mailerError = '';
    $this->mail->CharSet = translate( 'charset' );

    if ( $EMAIL_MAILER == 'smtp' ) {
      $this->mail->isSMTP();
      $this->mail->Host = $SMTP_HOST;
      $this->mail->Port = $SMTP_PORT;
      $this->mail->SMTPAuth = ( $SMTP_AUTH == 'Y' );
      $this->mail->SMTPSecure = ( isset($SMTP_STARTTLS)
        && $SMTP_STARTTLS == 'Y' ) ? 'tls' : '';
      $this->mail->SMTPDebug = 0;
      $this->mail->Username = $SMTP_USERNAME;
      $this->mail->Password = $SMTP_PASSWORD;
    } elseif ( $EMAIL_MAILER == 'sendmail' ) {
      $this->mail->isSendmail();
    } else {
      $this->mail->isMail();
    }
    // TODO: Support OAuth so we can use Gmail when 2FA is enabled.
  }

  /**
   * Build email from single via single class call.
   * Return true if mail was successfully sent.
   */
  function WC_Send($from_name, $to_email,
    $to_name, $subject, $msg, $html = 'N', $from_email = '', $id = '' ) {

    // Always use the default sender address configured in the SMTP
    // settings as the From address so the mail server accepts the
    // connection (many servers refuse senders that do not match the
    // authenticated SMTP user). Falls back to the passed in from_email
    // (or a safe placeholder) only if no valid default is configured.
    global $EMAIL_FALLBACK_FROM;
    $sender = '';
    if (!empty($EMAIL_FALLBACK_FROM) &&
        PHPMailer\PHPMailer::validateAddress($EMAIL_FALLBACK_FROM))
      $sender = $EMAIL_FALLBACK_FROM;
    if (empty($sender) && !empty($from_email) &&
        PHPMailer\PHPMailer::validateAddress($from_email))
      $sender = $from_email;
    if (empty($sender))
      $sender = 'noreply@' . (empty($_SERVER['HTTP_HOST'])
        ? 'localhost' : $_SERVER['HTTP_HOST']);

    $this->mail->SetFrom ( $sender, $from_name );

    $this->mail->IsHTML( $html == 'Y' );
    $this->mail->AddAddress( $to_email, unhtmlentities( $to_name, true ) );
    $this->WCSubject( $subject );
    $this->Body( $msg );

    if( ! empty( $id ) )
      $this->IcsAttach( $id );

    $ret = true;
    if ( ! $this->mail->Send() ) {
      # TODO: log this...
      #echo "Mail Error:\n" . $this->mail->ErrorInfo . "\n";
      #print_r ( $this->mail );
      $ret = false;
    }
    $this->ClearAll();
    return $ret;
  }

  /**
   * Replace the default language handler to use WebCalendar's function.
   */
  function Lang( $key ) {
    return translate( $key );
  }

  /**
   * Replace the default error handler so we can add our own trailer.
   */
  function SetError( $msg ) {
    global $mailerError;

    $this->error_count++;
    // $this->ErrorInfo = $msg;
    // die_miserable_death( $msg );
    $mailerError .= $msg . '<br>';
  }

  /**
   * Strip slashes from subject and pass thru unhtmlentities.
   */
  function WCSubject( $subject ) {
    $this->mail->Subject = unhtmlentities( generate_application_name( false ) . ' '
       . translate( 'Notification' ) . ': ' . stripslashes( $subject ) );
  }

  /**
   * Clean up msg as needed.
   */
  function Body( $msg ) {
    $msg = stripslashes( $msg );
    $this->mail->Body = ( $this->mail->ContentType == 'text/html'
      ? nl2br( $msg ) : unhtmlentities( $msg ) );
  }

  /**
   * Send ics file Attachment.
   * The calendar is embedded as a text/calendar MIME part with the
   * configured METHOD (default REQUEST) instead of a plain .ics file
   * attachment, so calendar clients (Outlook/Exchange) can process the
   * event directly (and Exchange AutoAccept can add it automatically).
   */
  function IcsAttach( $id ) {
    if( function_exists( 'export_ical' ) ) {
      $this->mail->Ical = export_ical( $id, true, $this->icalMethod );
      // PHPMailer only includes the text/calendar part when the message
      // is multipart/alternative, i.e. when AltBody is set in addition
      // to Body. Ensure that is the case (a trailing newline keeps the
      // two bodies distinct).
      if ( empty ( $this->mail->AltBody ) )
        $this->mail->AltBody = $this->mail->Body . "\n";
    }
  }

  /**
   * New function to clear ALL attributes.
   */
  function ClearAll() {
    $this->mail->ClearAddresses();
    $this->mail->ClearAllRecipients();
    $this->mail->ClearAttachments();
    $this->mail->ClearCustomHeaders();
    $this->mail->AltBody = '';
    $this->mail->Ical = '';
  }

  /**
   * Locate common error function here.
   */
  function MailError( $mailerError, $error ) {
    print_header();
    echo ( ! empty( $mailerError ) ? '
    <h2>' . translate( 'Email' ) . ' ' . translate( 'Error' ) . '</h2>
    <blockquote>' . $mailerError
       . ( empty( $error ) ? translate( 'Changes successfully saved' ) : '' )
       . '</blockquote>'
      : print_error( $error ) )
     . print_trailer();
  }
}
/*
 The following comments will be picked up by update_translation.pl
 so translators will find them.
 translate( 'authenticate' ) translate( 'connect_host' )
 translate( 'data_not_accepted' ) translate( 'encoding' )
 translate( 'execute' ) translate( 'file_access' ) translate( 'file_open' )
 translate( 'from_failed' ) translate( 'instantiate' )
 translate( 'mailer_not_supported' ) translate( 'provide_address' )
 translate( 'recipients_failed' );
*/

?>
