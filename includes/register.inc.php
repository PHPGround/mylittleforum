<?php
if(!defined('IN_INDEX'))
 {
  header('Location: ../index.php');
  exit;
 }

$smarty->configLoad($settings['language_file'], 'emails');
$lang = $smarty->getConfigVars();

// remove not activated user accounts:
@mysqli_query($connid, "DELETE FROM ".$db_settings['userdata_table']." WHERE registered < (NOW() - INTERVAL 24 HOUR) AND activate_code != '' AND logins=0");

if(empty($_SESSION[$settings['session_prefix'].'user_id']) && $settings['captcha_register']>0)
 {
  require('modules/captcha/captcha.php');
  $captcha = new Captcha();
 }

if(isset($_REQUEST['action'])) $action = $_REQUEST['action'];
else $action = 'main';

if(isset($_POST['register_submit'])) $action = 'register_submitted';
if(isset($_GET['key'])) $action = 'activate';

switch($action)
 {
  case 'main':
   if($settings['register_mode']<2)
    {
     if($settings['terms_of_use_agreement']==1) $smarty->assign("terms_of_use_agreement",true);
     $smarty->assign('subnav_location','subnav_register');
     $smarty->assign('subtemplate','register.inc.tpl');
     $template = 'main.tpl';
    }
   else
    {
     $smarty->assign('lang_section','register');
     $smarty->assign('message','register_only_by_admin');
     $smarty->assign('subnav_location','subnav_register');
     $smarty->assign('subtemplate','info.inc.tpl');
     $template = 'main.tpl';
    }
  break;
  case 'register_submitted':
   if($settings['register_mode']>1) die('No authorisation!');
   else
    {
     $new_user_name = trim($_POST['new_user_name']);
     $new_user_email = trim($_POST['new_user_email']);
     $reg_pw = $_POST['reg_pw'];
     $reg_pw_conf = $_POST['reg_pw_conf'];
     if(isset($_POST['terms_of_use_agree']) && $_POST['terms_of_use_agree']==1) $terms_of_use_agree=1; else $terms_of_use_agree=0;

     // form complete?
     if($new_user_name=='' || $new_user_email=='' || $reg_pw=='' || $reg_pw_conf=='') $errors[] = 'error_form_uncomplete';

     if(empty($errors))
      {
       // password too short?
       if(my_strlen($reg_pw, $lang['charset']) < $settings['min_pw_length']) $errors[] = 'error_password_too_short';
       // password and repeatet Password equal?
       if($reg_pw != $reg_pw_conf) $errors[] = 'error_pw_conf_wrong';
       // name too long?
       if(my_strlen($new_user_name, $lang['charset']) > $settings['username_maxlength']) $errors[] = 'error_name_too_long';
       // e-mail address too long?
       if(my_strlen($new_user_email, $lang['charset']) > $settings['email_maxlength']) $errors[] = 'error_email_too_long';

       // word in username too long?
       $too_long_word = too_long_word($new_user_name,$settings['name_word_maxlength']);
       if($too_long_word) $errors[] = 'error_word_too_long';

       // look if name already exists:
       $name_result = mysqli_query($connid, "SELECT user_name FROM ".$db_settings['userdata_table']." WHERE lower(user_name) = '".mysqli_real_escape_string($connid, my_strtolower($new_user_name, $lang['charset']))."'") or raise_error('database_error',mysqli_error($connid));
       if(mysqli_num_rows($name_result)>0) $errors[] = 'user_name_already_exists';
       mysqli_free_result($name_result);

       // look, if e-mail already exists:
       $email_result = mysqli_query($connid, "SELECT user_email FROM ".$db_settings['userdata_table']." WHERE lower(user_email) = '".mysqli_real_escape_string($connid, my_strtolower($new_user_email, $lang['charset']))."'") or raise_error('database_error',mysqli_error($connid));
       if(mysqli_num_rows($email_result)>0) $errors[] = 'error_email_alr_exists';
       mysqli_free_result($email_result);

       // e-mail correct?
       if(!is_valid_email($new_user_email)) $errors[] = 'error_email_wrong';

       if($settings['terms_of_use_agreement']==1 && $terms_of_use_agree!=1) $errors[] = 'terms_of_use_error_register';

       if(contains_special_characters($new_user_name)) $errors[] = 'error_username_invalid_chars';
      }

     // check for not accepted words:
     $checkstring = my_strtolower($new_user_name.' '.$new_user_email, $lang['charset']);
     $not_accepted_words = get_not_accepted_words($checkstring);
     if($settings['stop_forum_spam']==1) $infamous_email = isInfamousEmail($new_user_email);
     else $infamous_email = false;
     if($not_accepted_words!=false || $infamous_email) $errors[] = 'error_reg_not_accepted_word';

     // CAPTCHA check:
     if(empty($errors) && empty($_SESSION[$settings['session_prefix'].'user_id']) && $settings['captcha_register']>0)
      {
       if($settings['captcha_register']==2)
        {
         if(empty($_SESSION['captcha_session']) || empty($_POST['captcha_code']) || $captcha->check_captcha($_SESSION['captcha_session'],$_POST['captcha_code'])!=true) $errors[] = 'captcha_check_failed';
        }
       else
        {
         if(empty($_SESSION['captcha_session']) || empty($_POST['captcha_code']) || $captcha->check_math_captcha($_SESSION['captcha_session'][2],$_POST['captcha_code'])!=true) $errors[] = 'captcha_check_failed';
        }
       unset($_SESSION['captcha_session']);
      }

     // save user if no errors:
     if(empty($errors))
      {
       $pw_hash = generate_pw_hash($reg_pw);
       $activate_code = random_string(20);
       $activate_code_hash = generate_pw_hash($activate_code);
       if($settings['register_mode']==1) $user_lock = 1;
       else $user_lock = 0;
       @mysqli_query($connid, "INSERT INTO ".$db_settings['userdata_table']." (user_type, user_name, user_real_name, user_pw, user_email, user_hp, user_location, signature, profile, email_contact, last_login, last_logout, user_ip, registered, user_view, fold_threads, user_lock, auto_login_code, pwf_code, activate_code, entries_read) VALUES (0,'".mysqli_real_escape_string($connid, $new_user_name)."','','".mysqli_real_escape_string($connid, $pw_hash)."','".mysqli_real_escape_string($connid, $new_user_email)."','','','','',".$settings['default_email_contact'].",NULL,NOW(),'".mysqli_real_escape_string($connid, $_SERVER["REMOTE_ADDR"])."',NOW(),".intval($settings['default_view']).", ".intval($settings['fold_threads']).", ".$user_lock.", '', '', '".mysqli_real_escape_string($connid, $activate_code_hash)."', '')") or raise_error('database_error',mysqli_error($connid));

       // get new user ID:
       $new_user_id_result = mysqli_query($connid, "SELECT user_id FROM ".$db_settings['userdata_table']." WHERE user_name = '".mysqli_real_escape_string($connid, $new_user_name)."' LIMIT 1");
       if (!$new_user_id_result) raise_error('database_error',mysqli_error($connid));
       $field = mysqli_fetch_array($new_user_id_result);
       $new_user_id = $field['user_id'];
       mysqli_free_result($new_user_id_result);

       // send e-mail with activation key to new user:
       $lang['new_user_email_txt'] = str_replace("[name]", $new_user_name, $lang['new_user_email_txt']);
       $lang['new_user_email_txt'] = str_replace("[activate_link]", $settings['forum_address']."index.php?mode=register&id=".$new_user_id."&key=".$activate_code, $lang['new_user_email_txt']);

       if(my_mail($new_user_email, $lang['new_user_email_sj'], $lang['new_user_email_txt'])) $smarty->assign('message','registered');
       else $smarty->assign('message','registered_send_error');

       $smarty->assign('lang_section','register');
       $smarty->assign('var',htmlspecialchars($new_user_email));
       $smarty->assign('subnav_location','subnav_register');
       $smarty->assign('subtemplate','info.inc.tpl');
       $template = 'main.tpl';
      }
     else
      {
       $smarty->assign('errors',$errors);
       if(isset($too_long_word)) $smarty->assign('word',$too_long_word);
       $smarty->assign('subnav_location','subnav_register');
       $smarty->assign('subtemplate','register.inc.tpl');
       $smarty->assign('new_user_name',htmlspecialchars($new_user_name));
       $smarty->assign('new_user_email',htmlspecialchars($new_user_email));
       if($settings['terms_of_use_agreement']==1) $smarty->assign("terms_of_use_agreement",true);
       $template = 'main.tpl';
      }
    }
  break;
  case 'activate':
   if(isset($_GET['id'])) $id = intval($_GET['id']); else $error = TRUE;
   if(isset($_GET['key'])) $key = trim($_GET['key']); else $error = TRUE;
   if(empty($error))
    {
     if($id==0) $error = TRUE;
     if($key=='') $error = TRUE;
    }
   if(empty($error))
    {
     $result = mysqli_query($connid, "SELECT user_name, user_email, logins, activate_code FROM ".$db_settings['userdata_table']." WHERE user_id = ".intval($id)." LIMIT 1") or raise_error('database_error',mysqli_error($connid));
     if(mysqli_num_rows($result) != 1) $errors[] = true;
     $data = mysqli_fetch_array($result);
     mysqli_free_result($result);
    }
   if(empty($error))
    {
     if(trim($data['activate_code']) == '') $error = true;
    }
   if(empty($error))
    {
     if(is_pw_correct($key,$data['activate_code']))
      {
       @mysqli_query($connid, "UPDATE ".$db_settings['userdata_table']." SET activate_code = '' WHERE user_id=".intval($id)) or raise_error('database_error',mysqli_error($connid));

       // E-mail notification to mods and admins:
       if($data['logins']==0) // if != 0 user has changed his e-mail address
        {
         if($settings['register_mode']==1) $new_user_notif_txt = $lang['new_user_notif_txt_locked'];
         else $new_user_notif_txt = $lang['new_user_notif_txt'];
         $new_user_notif_txt = str_replace("[name]", $data['user_name'], $new_user_notif_txt);
         $new_user_notif_txt = str_replace("[email]", $data['user_email'], $new_user_notif_txt);
         $new_user_notif_txt = str_replace("[user_link]", $settings['forum_address']."index.php?mode=user&show_user=".$id, $new_user_notif_txt);

         // who gets a notification?
         $admin_result = @mysqli_query($connid, "SELECT user_name, user_email FROM ".$db_settings['userdata_table']." WHERE user_type>0 AND new_user_notification=1");
         if(!$admin_result) raise_error('database_error',mysqli_error($connid));
         while($admin_array = mysqli_fetch_array($admin_result))
          {
           $ind_reg_emailbody = str_replace("[recipient]", $admin_array['user_name'], $new_user_notif_txt);
           $admin_mailto = my_mb_encode_mimeheader($admin_array['user_name'], CHARSET, "Q")." <".$admin_array['user_email'].">";
           my_mail($admin_mailto, $lang['new_user_notif_sj'], $ind_reg_emailbody);
          }
        }
       if($settings['register_mode']==1) header("Location: index.php?mode=login&login_message=account_activated_but_locked");
       else header("Location: index.php?mode=login&login_message=account_activated");
       exit;
      }
     else $error = true;
    }
   if(isset($error))
    {
     $smarty->assign('lang_section','register');
     $smarty->assign('message','activation_failed');
     $smarty->assign('subnav_location','subnav_register');
     $smarty->assign('subtemplate','info.inc.tpl');
     $template = 'main.tpl';
    }
  break;
 }

// CAPTCHA:
if(empty($_SESSION[$settings['session_prefix'].'user_id']) && $settings['captcha_register']>0)
 {
  if($settings['captcha_register']==2)
   {
    $_SESSION['captcha_session'] = $captcha->generate_code();
   }
  else
   {
    $_SESSION['captcha_session'] = $captcha->generate_math_captcha();
    $captcha_tpl['number_1'] = $_SESSION['captcha_session'][0];
    $captcha_tpl['number_2'] = $_SESSION['captcha_session'][1];
   }
  $captcha_tpl['session_name'] = session_name();
  $captcha_tpl['session_id'] = session_id();
  $captcha_tpl['type'] = $settings['captcha_register'];
  $smarty->assign('captcha',$captcha_tpl);
 }
?>
