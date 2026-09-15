<?php

// Mandatory password change page. Reached after first login or after a
// password reset when the FORCE_PASSWORD_CHANGE flag is set.

require_once 'includes/init.php';

if (empty($login) || $login == '__public__') {
  do_redirect('login.php');
  exit;
}

// This page is only for a required password change. A user who is not
// forced to change their password has nothing to do here.
if (!user_must_change_password($login)) {
  do_redirect('index.php');
  exit;
}

$appStr = generate_application_name();

$error = '';

if (!empty($_POST['password1'])) {
  $password1 = getPostValue('password1');
  $password2 = getPostValue('password2');

  if (empty($password1)) {
    $error = translate('You have not entered a password.');
  } else if ($password1 != $password2) {
    $error = translate('The passwords were not identical.');
  } else {
    if (user_update_user_password($login, $password1)) {
      user_force_password_change($login, false);
      activity_log(
        0,
        $login,
        $login,
        LOG_USER_UPDATE,
        translate('Password changed')
      );
      do_redirect('index.php');
      exit;
    } else {
      $error = translate('Database error');
    }
  }
}

print_header([], '', '');

?>

<h3><?php echo htmlentities($appStr); ?></h3>
<h4><?php etranslate('You must change your password'); ?></h4>

<?php if (!empty($error)) { ?>
<div class="alert alert-warning" role="alert">
  <?php echo $error; ?>
</div>
<?php } ?>

<form id="change-password-form" class="form" action="change_password.php" method="post">
  <?php echo csrf_form_key(); ?>
  <div class="form-group">
    <label for="password1" class="text-info"><?php etranslate('New password'); ?>:</label><br>
    <input type="password" name="password1" id="password1" class="form-control">
  </div>
  <div class="form-group">
    <label for="password2" class="text-info"><?php etranslate('Confirm password'); ?>:</label><br>
    <input type="password" name="password2" id="password2" class="form-control">
  </div>
  <div class="form-group text-center">
    <button class="btn btn-primary" type="submit"><?php etranslate('Change password'); ?></button>
  </div>
</form>

<?php echo print_trailer(); ?>
