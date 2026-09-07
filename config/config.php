<?php
declare(strict_types=1);

const APP_NAME = 'Question Paper System';
const APP_ENV = 'development';
const BASE_URL = '/Question-Paper-System-new';

date_default_timezone_set('Asia/Kolkata');

const SESSION_NAME = 'QPS_SESSION';
const SESSION_TIMEOUT = 1800; // 30 minutes

const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'question_paper_system-new';
const DB_USER = 'root';
const DB_PASS = '';

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_SECONDS = 900; // 15 minutes
const LOGIN_LOCK_SECONDS = 900;   // 15 minutes

if (APP_ENV === 'production') {
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}
