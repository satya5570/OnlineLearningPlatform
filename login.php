<?php

require_once 'vendor/autoload.php';
include 'config.php';
include 'db.php';
session_start();

// init configuration
$clientID = $cid;
$clientSecret =$csc;
$redirectUri = 'http://localhost/OnlineLearningPlatform/dashboard.php';

// create Client Request to access Google API
$client = new Google_Client();
$client->setClientId($clientID);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);
$client->addScope("email");
$client->addScope("profile");



$error_message = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    $password = $_POST['password'];

    // Fetch the user data based on email
    $stmt = $conn->prepare("SELECT user_id, password, role, name FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($user_id, $hashed_password, $role, $name);
        $stmt->fetch();

        if (password_verify($password, $hashed_password)) {
            // Set session variables
            $_SESSION['user_id'] = $user_id;
            $_SESSION['role'] = $role;  // Store user role
            $_SESSION['user_name'] = $name;

            // Redirect based on user role
            if ($role == 'creator') {
                header("Location: create_course.php");  // Redirect to course creator's dashboard
            } else {
                header("Location: dashboard.php");  // Redirect to learner's dashboard
            }
            exit;
        } else {
            $error_message = "Invalid password.";
        }
    } else {
        $error_message = "No user found with this email.";
    }

    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="CSS/login.css">
    <script>
    function validateForm() {
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        const errorContainer = document.getElementById('error_message');

        errorContainer.textContent = '';

        if (email.trim() === '') {
            errorContainer.textContent = 'Email is required.';
            return false;
        }

        if (password.trim() === '') {
            errorContainer.textContent = 'Password is required.';
            return false;
        }

        return true;
    }
    </script>
</head>

<body>
    <div class="container">
        <h2>Login</h2>
        <form action="login.php" method="POST" onsubmit="return validateForm()">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" name="email" id="email" placeholder="Enter your email" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" name="password" id="password" placeholder="Enter your password" required>
            </div>
            <button type="submit">Login</button>
        </form>
        <div id="error_message" style="color: red; font-size: 0.9em;">
            <?php if (!empty($error_message)): ?>
            <?php echo $error_message; ?>
            <?php endif; ?>
        </div>
        <div style="text-align:center; margin-top: 10px;">


            <?php echo "<a href='" . $client->createAuthUrl() . "'>Google Login</a>"; ?>
        </div>

    </div>
</body>

</html>