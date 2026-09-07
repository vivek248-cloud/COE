<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';


/*
|--------------------------------------------------------------------------
| Already Logged In
|--------------------------------------------------------------------------
*/

if (is_logged_in()) {

    header(
        'Location: ' .
        dashboard_url_for_role(current_role())
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| Variables
|--------------------------------------------------------------------------
*/

$error = '';

$pageTitle = 'Teaching Staff Login';


/*
|--------------------------------------------------------------------------
| Login Processing
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();


    $username = trim(
        (string) ($_POST['username'] ?? '')
    );

    $password = (string) (
        $_POST['password'] ?? ''
    );


    /*
    |--------------------------------------------------------------------------
    | Required Fields
    |--------------------------------------------------------------------------
    */

    if (
        $username === '' ||
        $password === ''
    ) {

        $error =
            'Username and password are required.';

    } else {


        /*
        |--------------------------------------------------------------------------
        | Find User
        |--------------------------------------------------------------------------
        */

        $stmt = db()->prepare(
            'SELECT
                id,
                username,
                password_hash,
                role,
                is_active
             FROM users
             WHERE username = :username
             LIMIT 1'
        );


        $stmt->execute([
            ':username' => $username
        ]);


        $user = $stmt->fetch();


        /*
        |--------------------------------------------------------------------------
        | Validate Credentials
        |--------------------------------------------------------------------------
        */

        if (
            !$user ||
            (int) $user['is_active'] !== 1 ||
            !password_verify(
                $password,
                $user['password_hash']
            )
        ) {

            audit_log(
                $user
                    ? (int) $user['id']
                    : null,

                'LOGIN_FAILED',

                'Teaching Staff login failed for username: ' .
                $username
            );


            $error =
                'Invalid username or password.';


        } elseif (
            $user['role'] !== 'TEACHING_STAFF'
        ) {


            /*
            |--------------------------------------------------------------------------
            | Wrong Role
            |--------------------------------------------------------------------------
            */

            audit_log(
                (int) $user['id'],

                'LOGIN_ROLE_DENIED',

                'Non-teaching account attempted Teaching Staff login.'
            );


            $error =
                'This account is not authorized for the Teaching Staff portal.';


        } else {


            /*
            |--------------------------------------------------------------------------
            | Successful Login
            |--------------------------------------------------------------------------
            */

            login_user($user);


            audit_log(
                (int) $user['id'],

                'LOGIN_SUCCESS',

                'Teaching Staff login successful.'
            );


            header(
                'Location: ' .
                BASE_URL .
                '/teaching/dashboard.php'
            );

            exit;
        }
    }
}

?>


<!DOCTYPE html>

<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= e($pageTitle) ?> - <?= e(APP_NAME) ?>
    </title>


    <link
        rel="stylesheet"
        href="<?= BASE_URL ?>/assets/css/layout.css"
    >

    <style>

        /*
        ========================================================
        LOGIN PAGE
        ========================================================
        */

        .qps-login-page {

            min-height: 100vh;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 30px 20px;

            background:
                linear-gradient(
                    135deg,
                    #f8fafc 0%,
                    #eef4ff 100%
                );

        }


        .qps-login-card {

            width: 100%;

            max-width: 430px;

            padding: 34px;

            background: #ffffff;

            border: 1px solid #e2e8f0;

            border-radius: 16px;

            box-shadow:
                0 20px 50px
                rgba(15, 23, 42, .10);

        }


        .qps-login-brand {

            display: flex;

            align-items: center;

            gap: 12px;

            margin-bottom: 28px;

        }


        .qps-login-brand-icon {

            width: 46px;

            height: 46px;

            display: flex;

            align-items: center;

            justify-content: center;

            border-radius: 12px;

            background: #2563eb;

            color: #ffffff;

            font-size: 14px;

            font-weight: 800;

        }


        .qps-login-brand-text {

            display: flex;

            flex-direction: column;

        }


        .qps-login-brand-title {

            color: #172033;

            font-size: 16px;

            font-weight: 750;

        }


        .qps-login-brand-subtitle {

            margin-top: 2px;

            color: #64748b;

            font-size: 11px;

        }


        .qps-login-heading {

            margin-bottom: 24px;

        }


        .qps-login-heading h1 {

            margin: 0;

            color: #172033;

            font-size: 25px;

            font-weight: 750;

            letter-spacing: -.4px;

        }


        .qps-login-heading p {

            margin: 7px 0 0;

            color: #64748b;

            font-size: 13px;

        }


        .qps-login-form-group {

            margin-bottom: 18px;

        }


        .qps-login-form-group label {

            display: block;

            margin-bottom: 7px;

            color: #334155;

            font-size: 13px;

            font-weight: 650;

        }


        .qps-login-form-group input {

            width: 100%;

            height: 44px;

            padding: 0 13px;

            border: 1px solid #cbd5e1;

            border-radius: 9px;

            outline: none;

            background: #ffffff;

            color: #172033;

            font-family: inherit;

            font-size: 14px;

            transition:
                border-color .18s ease,
                box-shadow .18s ease;

        }


        .qps-login-form-group input:focus {

            border-color: #2563eb;

            box-shadow:
                0 0 0 3px
                rgba(37, 99, 235, .12);

        }


        .qps-login-button {

            width: 100%;

            height: 44px;

            margin-top: 5px;

            border: 0;

            border-radius: 9px;

            background: #2563eb;

            color: #ffffff;

            font-family: inherit;

            font-size: 14px;

            font-weight: 700;

            cursor: pointer;

            transition:
                background .18s ease,
                transform .18s ease;

        }


        .qps-login-button:hover {

            background: #1d4ed8;

            transform: translateY(-1px);

        }


        .qps-login-error {

            margin-bottom: 20px;

            padding: 11px 13px;

            border: 1px solid #fecaca;

            border-radius: 9px;

            background: #fef2f2;

            color: #b91c1c;

            font-size: 13px;

        }


        .qps-login-switch {

            margin-top: 23px;

            padding-top: 20px;

            border-top: 1px solid #e2e8f0;

            text-align: center;

            color: #64748b;

            font-size: 12px;

        }


        .qps-login-switch a {

            color: #2563eb;

            font-weight: 700;

            text-decoration: none;

        }


        .qps-login-switch a:hover {

            text-decoration: underline;

        }


        .qps-login-footer {

            margin-top: 25px;

            text-align: center;

            color: #94a3b8;

            font-size: 10px;

        }


        @media (max-width: 500px) {

            .qps-login-page {

                padding: 20px 14px;

            }


            .qps-login-card {

                padding: 25px 20px;

                border-radius: 13px;

            }

        }

    </style>

</head>


<body>


<div class="qps-login-page">


    <div class="qps-login-card">


        <!-- ==================================================
             BRAND
        =================================================== -->

        <div class="qps-login-brand">

            <div class="qps-login-brand-icon">
                QP
            </div>

            <div class="qps-login-brand-text">

                <span class="qps-login-brand-title">
                    Question Paper System
                </span>

                <span class="qps-login-brand-subtitle">
                    Examination Management
                </span>

            </div>

        </div>


        <!-- ==================================================
             HEADING
        =================================================== -->

        <div class="qps-login-heading">

            <h1>
                Teaching Staff Login
            </h1>

            <p>
                Sign in using your assigned User ID and password.
            </p>

        </div>


        <!-- ==================================================
             ERROR
        =================================================== -->

        <?php if ($error !== ''): ?>

            <div class="qps-login-error">

                <?= e($error) ?>

            </div>

        <?php endif; ?>


        <!-- ==================================================
             LOGIN FORM
        =================================================== -->

        <form
            method="post"
            autocomplete="off"
        >

            <?= csrf_field() ?>


            <div class="qps-login-form-group">

                <label for="username">
                    User ID
                </label>

                <input
                    id="username"
                    name="username"
                    type="text"
                    maxlength="100"
                    required
                    autocomplete="username"
                    value="<?= e($_POST['username'] ?? '') ?>"
                >

            </div>


            <div class="qps-login-form-group">

                <label for="password">
                    Password
                </label>

                <input
                    id="password"
                    name="password"
                    type="password"
                    required
                    autocomplete="current-password"
                >

            </div>


            <button
                type="submit"
                class="qps-login-button"
            >
                Login
            </button>

        </form>


        <!-- ==================================================
             PORTAL SWITCH
        =================================================== -->

        <div class="qps-login-switch">

            COE Staff?

            <a
                href="<?= BASE_URL ?>/coe/login.php"
            >
                Open COE Login
            </a>

        </div>


        <div class="qps-login-footer">

            © <?= date('Y') ?>
            <?= e(APP_NAME) ?>

        </div>


    </div>

</div>


</body>

</html>