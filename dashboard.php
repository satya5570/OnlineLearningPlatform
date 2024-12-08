<?php
session_start();
require_once 'vendor/autoload.php';

// Include database connection
include 'db.php';
include 'config.php';

// init configuration
$clientID = $cid;
$clientSecret = $csc;
$redirectUri = 'http://localhost/OnlineLearningPlatform/dashboard.php';

// create Client Request to access Google API
$client = new Google_Client();
$client->setClientId($clientID);
$client->setClientSecret($clientSecret);
$client->setRedirectUri($redirectUri);
$client->addScope("email");
$client->addScope("profile");


// Initialize user information
$user_id = null;
$name = "";
$userinfo = [];

// Check if logged in through Google or normally
if (isset($_GET['code'])) {
    try {
        $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
        
        if (isset($token['access_token'])) {
            $client->setAccessToken($token['access_token']);

            // Get profile info
            $google_oauth = new Google_Service_Oauth2($client);
            $google_account_info = $google_oauth->userinfo->get();
            $userinfo = [
                'email' => $google_account_info['email'],
                'first_name' => $google_account_info['givenName'],
                'last_name' => $google_account_info['familyName'],
                'gender' => $google_account_info['gender'],
                'full_name' => $google_account_info['name'],
                'picture' => $google_account_info['picture'],
                'verifiedEmail' => $google_account_info['verifiedEmail'],
                'token' => $google_account_info['id'],
            ];

            // Checking if user exists in the database
            $sql = "SELECT * FROM users WHERE email = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $userinfo['email']);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                // User exists
                $userinfo = $result->fetch_assoc();
                $token = $userinfo['token'];
                $_SESSION['user_id'] = $userinfo['user_id']; // Set user_id in session
            } else {
                // User does not exist, create a new user
                $sql = "INSERT INTO users (name, email, social_login, verified_email, token) 
                        VALUES (?, ?, 'google', ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssis", $userinfo['full_name'], $userinfo['email'], $userinfo['verifiedEmail'], $userinfo['token']);
                $result = $stmt->execute();
                if ($result) {
                    $token = $userinfo['token'];
                    $_SESSION['user_id'] = $conn->insert_id; // Set user_id in session
                } else {
                    // Handle user creation failure gracefully without showing errors to the user
                    header("Location: error.php"); // Redirect to a generic error page
                    exit;
                }
            }
            $stmt->close();

            // Save user data into session
            $_SESSION['user_token'] = $token;

            // Retrieve the user's name using the session token
            $sql = "SELECT name FROM users WHERE token = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $_SESSION['user_token']);
            $stmt->execute();
            $stmt->bind_result($name);
            $stmt->fetch();
            $stmt->close();
        } else {
            // Redirect to login page if access token is not available
            header("Location: login.php");
            exit;
        }
    } catch (Exception $e) {
        // Log the error and redirect to a generic error page
        error_log("Error fetching access token: " . $e->getMessage());
        header("Location: error.php");
        exit;
    }
} elseif (isset($_SESSION['user_id'])) {
    // Normal login handling
    $user_id = $_SESSION['user_id'];

    // Fetch user name from database
    $stmt = $conn->prepare("SELECT name FROM users WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($name);
    $stmt->fetch();
    $stmt->close();
} else {
    // Redirect to login if no valid session exists
    header("Location: login.php");
    exit;
}

// Handle enrollment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['course_id'])) {
    $course_id = $_POST['course_id'];

    // Check if the learner is already enrolled
    $check_stmt = $conn->prepare("SELECT * FROM enrollments WHERE learner_id = ? AND course_id = ?");
    $check_stmt->bind_param("ii", $user_id, $course_id);
    $check_stmt->execute();
    $check_stmt->store_result();

    if ($check_stmt->num_rows == 0) {
        // Enroll the learner in the course
        $enroll_stmt = $conn->prepare("INSERT INTO enrollments (learner_id, course_id) VALUES (?, ?)");
        $enroll_stmt->bind_param("ii", $user_id, $course_id);
        $enroll_stmt->execute();
        $enroll_stmt->close();
    }

    $check_stmt->close();
}

// Fetch all available courses
$courses_stmt = $conn->prepare("SELECT course_id, title, description, category FROM courses");
$courses_stmt->execute();
$courses_stmt->bind_result($course_id, $course_title, $course_description, $course_category);

$courses = [];
while ($courses_stmt->fetch()) {
    $courses[] = [
        'course_id' => $course_id,
        'title' => $course_title,
        'description' => $course_description,
        'category' => $course_category
    ];
}
$courses_stmt->close();

// Fetch enrolled courses
$enrolled_stmt = $conn->prepare("SELECT c.course_id, c.title, c.description FROM enrollments e 
    JOIN courses c ON e.course_id = c.course_id WHERE e.learner_id = ?");
$enrolled_stmt->bind_param("i", $user_id);
$enrolled_stmt->execute();
$enrolled_stmt->bind_result($enrolled_course_id, $enrolled_course_title, $enrolled_course_description);

$enrolled_courses = [];
while ($enrolled_stmt->fetch()) {
    $enrolled_courses[] = [
        'course_id' => $enrolled_course_id,
        'title' => $enrolled_course_title,
        'description' => $enrolled_course_description
    ];
}
$enrolled_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learner Dashboard</title>
    <link rel="stylesheet" href="CSS/dashboard.css">
</head>

<body>
    <header>
        <h1>Learner Dashboard</h1>
        <div class="nav">
            <p>Logged in as: <strong><?php echo htmlspecialchars($name); ?></strong></p>
            <a href="completed_courses_list.php" style="margin-right: 10px;">Completed Courses</a>
            <a href="changepassword.php" style="margin-right: 10px;">Change Password</a>
            <a href="logout.php">Logout</a>
        </div>
    </header>

    <!-- Available Courses Section -->
    <div class="section">
        <h2>Available Courses</h2>
        <div class="card-container">
            <?php foreach ($courses as $course): ?>
            <!-- Check if the user is already enrolled in the course -->
            <?php 
            $is_enrolled = false;
            foreach ($enrolled_courses as $enrolled_course) {
                if ($enrolled_course['course_id'] == $course['course_id']) {
                    $is_enrolled = true;
                    break;
                }
            }
            ?>
            <a href="course.php?course_id=<?php echo htmlspecialchars($course['course_id']); ?>"
                style="text-decoration: none;">
                <div class="card">
                    <h3><?php echo htmlspecialchars($course['title']); ?></h3>
                    <p><?php echo htmlspecialchars($course['description']); ?></p>
                    <p><strong>Category:</strong> <?php echo htmlspecialchars($course['category']); ?></p>
                    <?php if (!$is_enrolled): ?>
                    <form method="POST" action="dashboard.php">
                        <input type="hidden" name="course_id" value="<?php echo $course['course_id']; ?>">
                        <button type="submit">Enroll</button>
                    </form>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Enrolled Courses Section -->
    <div class="section">
        <h2>Enrolled Courses</h2>
        <div class="card-container">
            <?php if (!empty($enrolled_courses)): ?>
            <?php foreach ($enrolled_courses as $course): ?>
            <a href="course.php?course_id=<?php echo htmlspecialchars($course['course_id']); ?>"
                style="text-decoration: none;">
                <div class="card">
                    <h3><?php echo htmlspecialchars($course['title']); ?></h3>
                    <p><?php echo htmlspecialchars($course['description']); ?></p>
                </div>
            </a>
            <?php endforeach; ?>
            <?php else: ?>
            <p>No enrolled courses yet.</p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>