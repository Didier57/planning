<?php

// Forgot password page: request a new password to be emailed to you.

unset ( $_SESSION['webcal_login'] );
unset ( $_SESSION['webcalendar_session'] );

require_once 'includes/translate.php';
require_once 'includes/classes/WebCalendar.php';

$WebCalendar = new WebCalendar( __FILE__ );

require_once 'includes/config.php';
require_once 'includes/dbi4php.php';
require_once 'includes/formvars.php';
require_once 'includes/functions.php';

$WebCalendar->initializeFirstPhase();

require_once "includes/$user_inc";
require_once 'includes/access.php';
require_once 'includes/gradient.php';

$WebCalendar->initializeSecondPhase();

load_global_settings();
load_user_preferences('guest');

$WebCalendar->setLanguage();

require_once 'includes/classes/WebCalMailer.php';

$appStr = generate_application_name();

$error = '';
$message = '';
$sent = false;

if (!empty($_POST['login'])) {
  $input = trim($_POST['login']);

  if ($input == '') {
    $error = translate('Please enter your username or email address.');
  } else {
    // Find the matching account by login or email (case-insensitive).
    // $input is compared against both; the account must be a real user.
    $target = '';
    $target_email = '';
    $target_name = '';
    foreach (user_get_users() as $u) {
      if ($u['cal_login'] == '__public__')
        continue;
      $match_login = (strcasecmp($u['cal_login'], $input) == 0);
      $match_email = (strcasecmp($u['cal_email'], $input) == 0);
      if ($match_login || $match_email) {
        $target = $u['cal_login'];
        $target_email = $u['cal_email'];
        $target_name = trim($u['cal_firstname'] . ' ' . $u['cal_lastname']);
        break;
      }
    }

    // Anti-enumeration: always show the same generic message whether or not
    // the account exists, so an attacker cannot probe for valid usernames.
    if ($target != '' && !empty($target_email) && $SEND_EMAIL != 'N') {
      $new_pass = user_generate_password();
      if (user_update_user_password($target, $new_pass)) {
        user_force_password_change($target, true);

        $mail = new WebCalMailer;
        $htmlmail = (empty($EMAIL_HTML) || $EMAIL_HTML != 'Y' ? 'N' : 'Y');
        $tempName = strlen($target_name) ? $target_name : $target;
        $msg = str_replace(
          ', XXX.',
          ', ' . $tempName . '.',
          translate('Hello, XXX.')
        ) . "\n\n"
          . str_replace(
            'XXX',
            $appStr,
            translate('A password reset was requested for your XXX account.')
          )
          . "\n\n"
          . str_replace('XXX', $target, translate('Your username is XXX.'))
          . "\n\n"
          . str_replace('XXX', $new_pass, translate('Your new password is XXX.'))
          . "\n\n"
          . str_replace(
            'XXX',
            $appStr,
            translate('Please visit XXX to log in and change your password.')
          )
          . "\n\n" . getServerUrl() . 'login.php'
          . "\n\n"
          . translate('You must change your password after logging in.')
          . "\n\n" . translate('If you received this email in error') . "\n\n";

        $name = $appStr . ' ' . translate('Password Reset');
        $mail->WC_Send(
          translate('Administrator', true),
          $target_email,
          $target_name,
          $name,
          $msg,
          $htmlmail,
          $EMAIL_FALLBACK_FROM
        );
      }
    }

    $sent = true;
  }
}

echo send_doctype($appStr);

echo $ASSETS;

// Print custom header (since we do not call print_header function).
if (!empty($CUSTOM_SCRIPT) && $CUSTOM_SCRIPT == 'Y') {
  load_template($login, 'S');
}
?>
</head>
<body id="forgot-password">
<div class="container">
<div id="login-container">
<div class="row justify-content-center">
  <div class="col-12 col-sm-8 col-md-6 col-lg-4">
  <form id="forgot-form" class="form" action="forgot_password.php" method="post">
    <div class="text-center">
      <h3><?php echo htmlentities($appStr); ?></h3>
      <h4><?php etranslate('Forgot your password?'); ?></h4>
    </div>
  <?php if (!empty($error)) { ?>
    <div class="alert alert-warning" role="alert">
      <?php echo $error; ?>
    </div>
  <?php } ?>
  <?php if ($sent) { ?>
    <div class="alert alert-info" role="alert">
      <?php etranslate('If an account matches the information you provided, an email with a new password has been sent.'); ?>
    </div>
    <div class="form-group text-center">
      <a href="login.php"><?php etranslate('Return to Login screen'); ?></a>
    </div>
  <?php } else { ?>
    <div class="form-group">
      <label for="login" class="text-info"><?php etranslate('Username or email address'); ?>:</label><br>
      <input type="text" name="login" id="login" class="form-control">
    </div>
    <div class="form-group text-center">
      <button class="btn btn-primary" type="submit"><?php etranslate('Reset my password'); ?></button>
    </div>
    <div class="form-group text-center">
      <a href="login.php"><?php etranslate('Return to Login screen'); ?></a>
    </div>
  <?php } ?>
  </form>
  </div>
</div>
</div>
</div>

<br>

<?php
echo '<div id="webcalendarVersion"><a href="' . $PROGRAM_URL . '" target="_blank" id="programname">'
    . $PROGRAM_NAME . '</a></div>';

// Print custom trailer (since we do not call print_trailer function).
if (!empty($CUSTOM_TRAILER) && $CUSTOM_TRAILER == 'Y') {
  echo load_template($login, 'T');
}
?>
</body>
</html>
