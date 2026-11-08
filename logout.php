<?php
require_once __DIR__ . "/auth.php";
auth_logout();
auth_redirect("login.php");
