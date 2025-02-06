<?php

require('../admin/inc/db_config.php'); // Database configuration
require('../admin/inc/essentials.php'); // Essentials file for utility functions
require("../inc/sendgrid/sendgrid-php.php"); // SendGrid email library

date_default_timezone_set("Asia/Colombo");

// Function to send verification or password reset emails
function send_mail($uemail, $token, $type) {
    if ($type == "email_confirmation") {
        $page = 'email_confirm.php';
        $subject = "Account Verification Link";
        $content = "confirm your email";
    } else {
        $page = 'index.php';
        $subject = "Account Reset Link";
        $content = "reset your account";
    }

    $email = new \SendGrid\Mail\Mail(); 
    $email->setFrom(SENDGRID_EMAIL, SENDGRID_NAME);
    $email->setSubject($subject);
    $email->addTo($uemail);
    $email->addContent("text/html", "
        Click the link to $content: <br>
        <a href='" . SITE_URL . "$page?$type&email=$uemail&token=$token" . "'>
            CLICK ME
        </a>
    ");

    $sendgrid = new \SendGrid(SENDGRID_API_KEY);

    try {
        $sendgrid->send($email);
        return 1;
    } catch (Exception $e) {
        return 0;
    }
}

// Registration process
if (isset($_POST['register'])) {
    $data = filteration($_POST);

    // Debugging: Print the POST data and file info (to inspect what data is being received)
    echo '<pre>';
    print_r($data);
    print_r($_FILES);  // Check the profile image data
    echo '</pre>';
    exit; // Exit to see the printed data for debugging

    // Match password and confirm password fields
    if ($data['pass'] != $data['cpass']) {
        echo 'pass_mismatch';  // If passwords do not match, output this message
        exit;
    }

    // Check if the user already exists in the database
    $u_exist = select("SELECT * FROM `user_cred` WHERE `email` = ? OR `phonenum` = ? LIMIT 1", [$data['email'], $data['phonenum']], "ss");

    if (mysqli_num_rows($u_exist) != 0) {
        $u_exist_fetch = mysqli_fetch_assoc($u_exist);
        echo ($u_exist_fetch['email'] == $data['email']) ? 'email_already' : 'phone_already';
        exit;
    }

    // Upload user image to the server
    $img = uploadUserImage($_FILES['profile']);
    echo $img;  // Debugging: Check the return value of the image upload
    if ($img == 'inv_img') {
        echo 'inv_img';  // Invalid image type
        exit;
    } else if ($img == 'upd_failed') {
        echo 'upd_failed';  // Image upload failed
        exit;
    }

    // Hash the password using bcrypt
    $enc_pass = password_hash($data['pass'], PASSWORD_BCRYPT);

    // Prepare the insert query to store user details
    $query = "INSERT INTO `user_cred`(`name`, `email`, `address`, `phonenum`, `pincode`, `dob`, `profile`, `password`, `is_verified`) 
              VALUES (?,?,?,?,?,?,?,?,?)";
    $values = [$data['name'], $data['email'], $data['address'], $data['phonenum'], $data['pincode'], $data['dob'], $img, $enc_pass, '1'];

    // Debugging: Print values before inserting into the database to inspect if they are correct
    echo '<pre>';
    print_r($values);
    echo '</pre>';
    exit;  // Exit here to see the values being passed to the query

    // Execute the insert query
    if (insert($query, $values, 'sssssssss')) {
        echo 1;  // Success: Data inserted successfully
    } else {
        echo 'Insert failed: ' . mysqli_error($con);  // Output MySQL error if insert fails
    }
}

// Login process
if (isset($_POST['login'])) {
    $data = filteration($_POST);

    // Check if the user exists in the database
    $u_exist = select("SELECT * FROM `user_cred` WHERE `email`=? OR `phonenum`=? LIMIT 1", [$data['email_mob'], $data['email_mob']], "ss");

    if (mysqli_num_rows($u_exist) == 0) {
        echo 'inv_email_mob';  // User does not exist
    } else {
        $u_fetch = mysqli_fetch_assoc($u_exist);
        if ($u_fetch['is_verified'] == 0) {
            echo 'not_verified';  // User is not verified
        } else if ($u_fetch['status'] == 0) {
            echo 'inactive';  // User account is inactive
        } else {
            if (!password_verify($data['pass'], $u_fetch['password'])) {
                echo 'invalid_pass';  // Invalid password
            } else {
                session_start();
                $_SESSION['login'] = true;
                $_SESSION['uId'] = $u_fetch['id'];
                $_SESSION['uName'] = $u_fetch['name'];
                $_SESSION['uPic'] = $u_fetch['profile'];
                $_SESSION['uPhone'] = $u_fetch['phonenum'];
                echo 1;  // Success: Logged in
            }
        }
    }
}

// Forgot Password process
if (isset($_POST['forgot_pass'])) {
    $data = filteration($_POST);

    $u_exist = select("SELECT * FROM `user_cred` WHERE `email`=? LIMIT 1", [$data['email']], "s");

    if (mysqli_num_rows($u_exist) == 0) {
        echo 'inv_email';  // Invalid email
    } else {
        $u_fetch = mysqli_fetch_assoc($u_exist);
        if ($u_fetch['is_verified'] == 0) {
            echo 'not_verified';  // User not verified
        } else if ($u_fetch['status'] == 0) {
            echo 'inactive';  // User account is inactive
        } else {
            // Send reset link to email
            $token = bin2hex(random_bytes(16));

            if (!send_mail($data['email'], $token, 'account_recovery')) {
                echo 'mail_failed';  // Failed to send email
            } else {
                $date = date("Y-m-d");

                $query = mysqli_query($con, "UPDATE `user_cred` SET `token`='$token', `t_expire`='$date' WHERE `id`='$u_fetch[id]'");

                if ($query) {
                    echo 1;  // Success: Reset link sent
                } else {
                    echo 'upd_failed';  // Update failed
                }
            }
        }
    }
}

// Recover User process after password reset
if (isset($_POST['recover_user'])) {
    $data = filteration($_POST);

    $enc_pass = password_hash($data['pass'], PASSWORD_BCRYPT);

    $query = "UPDATE `user_cred` SET `password`=?, `token`=?, `t_expire`=? WHERE `email`=? AND `token`=?";
    $values = [$enc_pass, null, null, $data['email'], $data['token']];

    if (update($query, $values, 'sssss')) {
        echo 1;  // Success: Password reset
    } else {
        echo 'failed';  // Password reset failed
    }
}

?>
