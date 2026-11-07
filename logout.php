<?php
require_once __DIR__ . '/auth.php';
auth_destroy_session();
auth_redirect('login.php');
